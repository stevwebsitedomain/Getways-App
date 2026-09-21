<?php

declare(strict_types=1);

/**
 * Shared helpers for Monitoring API (device ingest + admin).
 */

require_once dirname(__DIR__) . '/env-load.php';

function monJson(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
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
    return false;
}

function monRequireHttps(): void
{
    if (!monIsHttps()) {
        monJson(403, ['success' => false, 'message' => 'HTTPS required.']);
    }
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
    // Apache sometimes exposes Authorization differently
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

function monPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    gwLoadEnv();
    $host = (string) (getenv('DB_HOST') ?: '127.0.0.1');
    $port = (string) (getenv('DB_PORT') ?: '3306');
    $name = (string) (getenv('DB_NAME') ?: '');
    $user = (string) (getenv('DB_USER') ?: '');
    $pass = (string) (getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '');
    if ($name === '' || $user === '') {
        monJson(500, ['success' => false, 'message' => 'Database is not configured.']);
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    monEnsureSchema($pdo);
    return $pdo;
}

function monEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS monitoring_devices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(64) NOT NULL,
            device_uuid VARCHAR(64) NULL,
            api_key_hash VARCHAR(255) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            license_expires_at DATETIME NULL,
            daily_search_limit INT NULL,
            maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
            message TEXT NULL,
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_monitoring_device_id (device_id),
            KEY idx_monitoring_devices_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS monitoring_activity_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(64) NOT NULL,
            local_id BIGINT NULL,
            user_id VARCHAR(64) NULL,
            username VARCHAR(128) NULL,
            action VARCHAR(64) NULL,
            description TEXT NULL,
            endpoint VARCHAR(255) NULL,
            request_method VARCHAR(16) NULL,
            status VARCHAR(32) NULL,
            ip_address VARCHAR(64) NULL,
            metadata_json MEDIUMTEXT NULL,
            created_at_client VARCHAR(64) NULL,
            received_at DATETIME NOT NULL,
            UNIQUE KEY uq_monitoring_logs_device_local (device_id, local_id),
            KEY idx_monitoring_logs_device (device_id),
            KEY idx_monitoring_logs_action (action),
            KEY idx_monitoring_logs_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    monSeedBossDevice($pdo);
}

function monSeedBossDevice(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT id FROM monitoring_devices WHERE device_id = ? LIMIT 1');
    $stmt->execute(['BOSS-PC-001']);
    if ($stmt->fetch()) {
        return;
    }
    $hash = password_hash('qbp385A4STrK6hRattuLqI7NM2peQNVHLACwJ3go', PASSWORD_DEFAULT);
    $now = gmdate('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        'INSERT INTO monitoring_devices
        (device_id, device_uuid, api_key_hash, status, license_expires_at, daily_search_limit, maintenance_mode, message, last_seen_at, created_at, updated_at)
        VALUES (?, NULL, ?, ?, ?, ?, 0, NULL, NULL, ?, ?)'
    );
    $ins->execute([
        'BOSS-PC-001',
        $hash,
        'active',
        '2026-12-31 23:59:59',
        100,
        $now,
        $now,
    ]);
}

/**
 * @return array<string, mixed>
 */
function monFindDevice(PDO $pdo, string $deviceId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM monitoring_devices WHERE device_id = ? LIMIT 1');
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
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
 * Authenticate device request: Bearer key + HMAC signature + timestamp skew.
 *
 * @return array{device: array<string, mixed>, apiKey: string, body: array}
 */
function monAuthenticateDevice(): array
{
    monRequireHttps();

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        monJson(405, ['success' => false, 'message' => 'Method not allowed']);
    }

    $deviceId = monHeader('X-Device-ID');
    $timestamp = monHeader('X-Timestamp');
    $signature = strtolower(monHeader('X-Signature'));
    $apiKey = monExtractBearer();
    $raw = monReadRawBody();
    $body = monReadJsonBody();

    if ($deviceId === '' || $timestamp === '' || $signature === '' || $apiKey === '') {
        monJson(401, ['success' => false, 'message' => 'Missing auth headers.']);
    }
    if (!ctype_digit($timestamp)) {
        monJson(401, ['success' => false, 'message' => 'Invalid timestamp.']);
    }
    $ts = (int) $timestamp;
    if (abs(time() - $ts) > 300) {
        monJson(401, ['success' => false, 'message' => 'Timestamp skew too large.']);
    }

    monCheckRateLimit($apiKey);

    $pdo = monPdo();
    $device = monFindDevice($pdo, $deviceId);
    if ($device === null) {
        monJson(401, ['success' => false, 'message' => 'Unknown device.']);
    }
    if (!password_verify($apiKey, (string) $device['api_key_hash'])) {
        monJson(401, ['success' => false, 'message' => 'Invalid API key.']);
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $apiKey);
    if (!hash_equals($expected, $signature)) {
        monJson(401, ['success' => false, 'message' => 'Invalid signature.']);
    }

    // Body device_id must match header when present
    $bodyDevice = trim((string) ($body['device_id'] ?? ''));
    if ($bodyDevice !== '' && strcasecmp($bodyDevice, $deviceId) !== 0) {
        monJson(400, ['success' => false, 'message' => 'device_id mismatch.']);
    }

    return ['device' => $device, 'apiKey' => $apiKey, 'body' => $body, 'deviceId' => $deviceId, 'pdo' => $pdo];
}

function monTouchDevice(PDO $pdo, string $deviceId, ?string $deviceUuid = null): void
{
    $now = gmdate('Y-m-d H:i:s');
    if ($deviceUuid !== null && $deviceUuid !== '') {
        $stmt = $pdo->prepare('UPDATE monitoring_devices SET last_seen_at = ?, device_uuid = COALESCE(?, device_uuid), updated_at = ? WHERE device_id = ?');
        $stmt->execute([$now, $deviceUuid, $now, $deviceId]);
    } else {
        $stmt = $pdo->prepare('UPDATE monitoring_devices SET last_seen_at = ?, updated_at = ? WHERE device_id = ?');
        $stmt->execute([$now, $now, $deviceId]);
    }
}

function monSanitizeMetadata(mixed $meta): ?string
{
    if ($meta === null) {
        return null;
    }
    if (!is_array($meta)) {
        $meta = ['value' => $meta];
    }
    // Strip likely secrets if accidentally included
    $blocked = ['password', 'token', 'api_key', 'apikey', 'secret', 'authorization'];
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
