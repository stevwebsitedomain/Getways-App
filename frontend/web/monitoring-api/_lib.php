<?php

declare(strict_types=1);

/**
 * Shared helpers for Monitoring API (device ingest + admin).
 * Uses MONITORING_DB_* from project-root .env only (never main DB_*).
 */

require_once dirname(__DIR__) . '/env-load.php';

const MON_OFFLINE_AFTER_SECONDS = 600; // 10 minutes

function monJson(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function monSafeError(string $publicMessage, Throwable $e): never
{
    error_log('Monitoring: ' . $e->getMessage());
    monJson(500, ['success' => false, 'ok' => false, 'message' => $publicMessage]);
}

function monIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($fwd === 'https') {
        return true;
    }
    // Allow local XAMPP / CLI testing without TLS.
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === 'localhost' || str_starts_with($host, '127.0.0.1') || str_starts_with($host, 'localhost:')) {
        return true;
    }
    if (PHP_SAPI === 'cli') {
        return true;
    }
    return false;
}

function monRequireHttps(): void
{
    if (!monIsHttps()) {
        monJson(403, ['success' => false, 'message' => 'HTTPS required.']);
    }
}

function monClientIp(): string
{
    $candidates = [
        (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    foreach ($candidates as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        // X-Forwarded-For may be a list
        $ip = trim(explode(',', $raw)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return substr($ip, 0, 45);
        }
    }
    return '0.0.0.0';
}

function monReadRawBody(): string
{
    static $raw = null;
    if ($raw !== null) {
        return $raw;
    }
    $raw = file_get_contents('php://input');
    return is_string($raw) ? $raw : '';
}

function monReadJsonBody(): array
{
    $raw = monReadRawBody();
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function monHeader(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return trim((string) $_SERVER[$key]);
    }
    if (strcasecmp($name, 'Authorization') === 0) {
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return trim((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        }
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $k => $v) {
                if (strcasecmp((string) $k, 'Authorization') === 0) {
                    return trim((string) $v);
                }
            }
        }
    }
    return '';
}

function monExtractBearer(): string
{
    $auth = monHeader('Authorization');
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * @return array{host:string,port:string,name:string,user:string,pass:string}
 */
function monDbConfig(): array
{
    gwLoadEnv();
    $host = trim((string) (getenv('MONITORING_DB_HOST') ?: ''));
    $port = trim((string) (getenv('MONITORING_DB_PORT') ?: '3306'));
    $name = trim((string) (getenv('MONITORING_DB_NAME') ?: ''));
    $user = trim((string) (getenv('MONITORING_DB_USER') ?: ''));
    $pass = (string) (getenv('MONITORING_DB_PASSWORD') ?: '');

    if ($host === '' || $name === '' || $user === '') {
        monJson(500, ['success' => false, 'message' => 'Monitoring database is not configured.']);
    }

    // Production public hosts must not point monitoring at a developer PC via reverse tunnel names.
    $httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isPublicHttp = $httpHost !== ''
        && !str_contains($httpHost, 'localhost')
        && !str_starts_with($httpHost, '127.0.0.1');
    $blockedRemoteLocal = ['host.docker.internal', '10.0.2.2'];
    if ($isPublicHttp && in_array(strtolower($host), $blockedRemoteLocal, true)) {
        monJson(500, ['success' => false, 'message' => 'Invalid monitoring database host.']);
    }

    return [
        'host' => $host,
        'port' => $port !== '' ? $port : '3306',
        'name' => $name,
        'user' => $user,
        'pass' => $pass,
    ];
}

function monPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = monDbConfig();
    try {
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        monEnsureSchema($pdo);
    } catch (Throwable $e) {
        monSafeError('Database unavailable.', $e);
    }

    return $pdo;
}

function monEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS monitored_devices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL,
            device_name VARCHAR(150) NOT NULL,
            api_key_hash VARCHAR(255) NOT NULL,
            status ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
            license_expires_at DATETIME NULL,
            daily_search_limit INT UNSIGNED NULL,
            maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
            maintenance_message TEXT NULL,
            last_seen_at DATETIME NULL,
            last_ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_monitored_device_id (device_id),
            KEY idx_monitored_devices_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS remote_activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL,
            local_record_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT NULL,
            username VARCHAR(100) NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT NULL,
            endpoint VARCHAR(255) NULL,
            request_method VARCHAR(10) NULL,
            status VARCHAR(50) NULL,
            results_count INT NULL,
            ip_address VARCHAR(45) NULL,
            metadata_json JSON NULL,
            occurred_at DATETIME NOT NULL,
            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_device_log (device_id, local_record_id),
            KEY idx_device_date (device_id, occurred_at),
            KEY idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS device_control_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL,
            admin_user_id BIGINT NULL,
            old_status VARCHAR(30) NULL,
            new_status VARCHAR(30) NULL,
            description TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_device (device_id),
            KEY idx_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * @return array<string, mixed>|null
 */
function monFindDevice(PDO $pdo, string $deviceId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM monitored_devices WHERE device_id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function monOfflineAfterSeconds(): int
{
    gwLoadEnv();
    $mins = (int) (getenv('MONITORING_OFFLINE_AFTER_MINUTES') ?: 10);
    if ($mins < 1) {
        $mins = 10;
    }
    return $mins * 60;
}

function monIsOnline(?string $lastSeenAt): bool
{
    if ($lastSeenAt === null || trim($lastSeenAt) === '') {
        return false;
    }
    $ts = strtotime($lastSeenAt . ' UTC');
    if ($ts === false) {
        $ts = strtotime($lastSeenAt);
    }
    if ($ts === false) {
        return false;
    }
    return (time() - $ts) <= monOfflineAfterSeconds();
}

function monPresenceLabel(?string $lastSeenAt): string
{
    return monIsOnline($lastSeenAt) ? 'online' : 'offline';
}

function monRateLimitDir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'monitoring-rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function monCheckRateLimit(string $apiKey, int $maxPerMinute = 60): void
{
    $bucket = gmdate('YmdHi');
    $file = monRateLimitDir() . DIRECTORY_SEPARATOR . hash('sha256', $apiKey . '|' . $bucket) . '.json';
    $count = 0;
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $count = is_array($data) ? (int) ($data['count'] ?? 0) : 0;
    }
    $count++;
    @file_put_contents($file, json_encode(['count' => $count, 'bucket' => $bucket]), LOCK_EX);
    if ($count > $maxPerMinute) {
        monJson(429, ['success' => false, 'message' => 'Rate limit exceeded. Try again shortly.']);
    }
}

/**
 * Authenticate device: Bearer API key + X-Device-ID (+ optional HMAC headers).
 *
 * @return array{device: array<string, mixed>, apiKey: string, body: array, deviceId: string, pdo: PDO}
 */
function monAuthenticateDevice(bool $requireWritable = false): array
{
    monRequireHttps();

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        monJson(405, ['success' => false, 'message' => 'Method not allowed']);
    }

    $deviceId = monHeader('X-Device-ID');
    $apiKey = monExtractBearer();
    $timestamp = monHeader('X-Timestamp');
    $signature = strtolower(monHeader('X-Signature'));
    $raw = monReadRawBody();
    $body = monReadJsonBody();

    if ($deviceId === '' || $apiKey === '') {
        monJson(401, ['success' => false, 'message' => 'Missing auth headers.']);
    }

    // HMAC optional for older clients; when present, must validate.
    if ($timestamp !== '' || $signature !== '') {
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            monJson(401, ['success' => false, 'message' => 'Invalid signature headers.']);
        }
        if (abs(time() - (int) $timestamp) > 300) {
            monJson(401, ['success' => false, 'message' => 'Timestamp skew too large.']);
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $apiKey);
        if (!hash_equals($expected, $signature)) {
            monJson(401, ['success' => false, 'message' => 'Invalid signature.']);
        }
    }

    monCheckRateLimit($apiKey);

    try {
        $pdo = monPdo();
    } catch (Throwable $e) {
        monSafeError('Database unavailable.', $e);
    }

    $device = monFindDevice($pdo, $deviceId);
    if ($device === null) {
        monJson(401, ['success' => false, 'message' => 'Unknown device.']);
    }
    if (!password_verify($apiKey, (string) $device['api_key_hash'])) {
        monJson(401, ['success' => false, 'message' => 'Invalid API key.']);
    }

    $status = strtolower((string) ($device['status'] ?? ''));
    if ($requireWritable && !in_array($status, ['active'], true)) {
        monJson(403, [
            'success' => false,
            'message' => 'Device is not active.',
            'device_status' => $status,
        ]);
    }

    $bodyDevice = trim((string) ($body['device_id'] ?? ''));
    if ($bodyDevice !== '' && strcasecmp($bodyDevice, $deviceId) !== 0) {
        monJson(400, ['success' => false, 'message' => 'device_id mismatch.']);
    }

    return [
        'device' => $device,
        'apiKey' => $apiKey,
        'body' => $body,
        'deviceId' => $deviceId,
        'pdo' => $pdo,
    ];
}

function monTouchDevice(PDO $pdo, string $deviceId): void
{
    $now = gmdate('Y-m-d H:i:s');
    $ip = monClientIp();
    $stmt = $pdo->prepare(
        'UPDATE monitored_devices SET last_seen_at = ?, last_ip_address = ?, updated_at = ? WHERE device_id = ?'
    );
    $stmt->execute([$now, $ip, $now, $deviceId]);
}

function monSanitizeMetadata(mixed $meta): ?string
{
    if ($meta === null) {
        return null;
    }
    if (!is_array($meta)) {
        $meta = ['value' => $meta];
    }
    $blocked = [
        'password', 'passwd', 'token', 'api_key', 'apikey', 'secret', 'authorization',
        'cookie', 'session', 'session_id', 'csrf', 'bearer',
    ];
    $clean = [];
    foreach ($meta as $k => $v) {
        $key = strtolower((string) $k);
        $skip = false;
        foreach ($blocked as $b) {
            if (str_contains($key, $b)) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        $clean[$k] = $v;
    }
    $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : null;
}

function monParseDateTime(?string $value, string $fallback): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return $fallback;
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

function monCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['gw_mon_csrf']) || !is_string($_SESSION['gw_mon_csrf'])) {
        $_SESSION['gw_mon_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['gw_mon_csrf'];
}

function monRequireCsrf(?string $token): void
{
    $expected = monCsrfToken();
    if ($expected === '' || $token === null || $token === '' || !hash_equals($expected, $token)) {
        monJson(403, ['ok' => false, 'success' => false, 'message' => 'Invalid CSRF token.']);
    }
}

function monPublicDeviceRow(array $device): array
{
    unset($device['api_key_hash']);
    $device['presence'] = monPresenceLabel($device['last_seen_at'] ?? null);
    $device['is_online'] = monIsOnline($device['last_seen_at'] ?? null);
    $device['maintenance_mode'] = !empty($device['maintenance_mode']);
    return $device;
}
