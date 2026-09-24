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

function monDbFailure(Throwable $e): never
{
    error_log('Monitoring: ' . $e->getMessage());
    $driver = 0;
    if ($e instanceof PDOException && is_array($e->errorInfo ?? null) && isset($e->errorInfo[1])) {
        $driver = (int) $e->errorInfo[1];
    }
    $message = 'Database unavailable.';
    if ($driver === 1045) {
        $message = 'MySQL rejected the username or password. On this server the host is localhost, the same one in phpMyAdmin.';
    } elseif ($driver === 1049) {
        $message = 'Database reacrisc_tra_masaki was not found on this MySQL server.';
    } elseif (in_array($driver, [2002, 2003, 2005, 2006], true)) {
        $message = 'Could not reach MySQL. On the website server the host is localhost, not 127.0.0.1.';
    } elseif (in_array($driver, [1044, 1142], true)) {
        $message = 'MySQL login worked, but this user cannot use that database.';
    }
    monJson(500, ['success' => false, 'ok' => false, 'message' => $message]);
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

function monIsPublicHttp(): bool
{
    $httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return $httpHost !== ''
        && !str_contains($httpHost, 'localhost')
        && !str_starts_with($httpHost, '127.0.0.1');
}

function monRuntimeDbPath(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'monitoring-db.php';
}

/**
 * @return array{host:string,port:string,name:string,user:string,pass:string}|null
 */
function monRuntimeDbConfig(): ?array
{
    $path = monRuntimeDbPath();
    if (!is_file($path)) {
        return null;
    }
    $data = include $path;
    if (!is_array($data)) {
        return null;
    }
    $host = trim((string) ($data['host'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $user = trim((string) ($data['user'] ?? ''));
    if ($host === '' || $name === '' || $user === '') {
        return null;
    }
    $port = trim((string) ($data['port'] ?? '3306'));
    return [
        'host' => $host,
        'port' => $port !== '' ? $port : '3306',
        'name' => $name,
        'user' => $user,
        'pass' => (string) ($data['pass'] ?? ''),
    ];
}

/**
 * @param array{host:string,port:string,name:string,user:string,pass:string} $cfg
 */
function monStoreRuntimeDbConfig(array $cfg): bool
{
    $export = var_export([
        'host' => $cfg['host'],
        'port' => $cfg['port'],
        'name' => $cfg['name'],
        'user' => $cfg['user'],
        'pass' => $cfg['pass'],
    ], true);
    $written = file_put_contents(monRuntimeDbPath(), "<?php\nreturn {$export};\n", LOCK_EX);
    return $written !== false;
}

/**
 * @return array{host:string,port:string,name:string,user:string,pass:string}
 */
function monDbConfig(): array
{
    $stored = monRuntimeDbConfig();
    if ($stored !== null) {
        return $stored;
    }

    gwLoadEnv();
    $host = trim((string) (getenv('MONITORING_DB_HOST') ?: ''));
    $port = trim((string) (getenv('MONITORING_DB_PORT') ?: '3306'));
    $name = trim((string) (getenv('MONITORING_DB_NAME') ?: ''));
    $user = trim((string) (getenv('MONITORING_DB_USER') ?: ''));
    $pass = (string) (getenv('MONITORING_DB_PASSWORD') ?: '');

    if ($host === '' && $name !== '' && $user !== '' && monIsPublicHttp()) {
        $host = 'localhost';
    }

    if ($host === '' || $name === '' || $user === '') {
        monJson(500, ['success' => false, 'message' => 'Monitoring database is not configured.']);
    }

    // Production public hosts must not point monitoring at a developer PC via reverse tunnel names.
    $blockedRemoteLocal = ['host.docker.internal', '10.0.2.2'];
    if (monIsPublicHttp() && in_array(strtolower($host), $blockedRemoteLocal, true)) {
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

/**
 * @param array{host:string,port:string,name:string,user:string,pass:string} $cfg
 * @return list<array{host:string,port:string,name:string,user:string,pass:string}>
 */
function monDbCandidates(array $cfg): array
{
    $hosts = [$cfg['host']];
    // Only substitute localhost when the saved host is the developer-PC address.
    // A real hosting hostname such as sdb-84.hosting.stackcp.net must be used as given.
    $configured = strtolower($cfg['host']);
    if (monIsPublicHttp() && $configured === '127.0.0.1') {
        array_unshift($hosts, 'localhost');
    }
    $seen = [];
    $out = [];
    foreach ($hosts as $host) {
        $host = trim($host);
        $key = strtolower($host);
        if ($host === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $copy = $cfg;
        $copy['host'] = $host;
        $out[] = $copy;
    }
    return $out;
}

/**
 * @param array{host:string,port:string,name:string,user:string,pass:string} $cfg
 */
function monOpenPdo(array $cfg): PDO
{
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ];
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $options[constant('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')] = 5;
    }
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

function monPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = monDbConfig();
    $last = null;
    foreach (monDbCandidates($cfg) as $candidate) {
        try {
            $pdo = monOpenPdo($candidate);
            try {
                monEnsureSchema($pdo);
            } catch (Throwable $schemaError) {
                error_log('Monitoring schema: ' . $schemaError->getMessage());
                $ready = $pdo->query("SHOW TABLES LIKE 'monitored_devices'")->fetchColumn();
                if (!$ready) {
                    throw $schemaError;
                }
            }
            return $pdo;
        } catch (Throwable $e) {
            $last = $e;
            $pdo = null;
        }
    }

    monDbFailure($last instanceof Throwable ? $last : new RuntimeException('Monitoring database connection failed.'));
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
            daily_download_limit INT UNSIGNED NULL,
            maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
            maintenance_message TEXT NULL,
            control_mode ENUM('active','read_only','api_blocked','fully_blocked') NOT NULL DEFAULT 'active',
            blocked_message TEXT NULL,
            control_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            sms_alerts_enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_seen_at DATETIME NULL,
            last_ip_address VARCHAR(45) NULL,
            last_sync_at DATETIME NULL,
            last_command_received_at DATETIME NULL,
            last_command_applied_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_monitored_device_id (device_id),
            KEY idx_monitored_devices_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    monEnsureColumn($pdo, 'monitored_devices', 'daily_download_limit', 'INT UNSIGNED NULL AFTER daily_search_limit');
    monEnsureColumn($pdo, 'monitored_devices', 'control_mode', "ENUM('active','read_only','api_blocked','fully_blocked') NOT NULL DEFAULT 'active' AFTER maintenance_message");
    monEnsureColumn($pdo, 'monitored_devices', 'blocked_message', 'TEXT NULL AFTER control_mode');
    monEnsureColumn($pdo, 'monitored_devices', 'control_version', 'BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER blocked_message');
    monEnsureColumn($pdo, 'monitored_devices', 'sms_alerts_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER control_version');
    monEnsureColumn($pdo, 'monitored_devices', 'last_sync_at', 'DATETIME NULL AFTER last_ip_address');
    monEnsureColumn($pdo, 'monitored_devices', 'last_command_received_at', 'DATETIME NULL AFTER last_sync_at');
    monEnsureColumn($pdo, 'monitored_devices', 'last_command_applied_at', 'DATETIME NULL AFTER last_command_received_at');

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
            metadata_json LONGTEXT NULL,
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
            field_name VARCHAR(100) NULL,
            old_status VARCHAR(30) NULL,
            new_status VARCHAR(30) NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            description TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_device (device_id),
            KEY idx_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    monEnsureColumn($pdo, 'device_control_audit', 'field_name', 'VARCHAR(100) NULL AFTER admin_user_id');
    monEnsureColumn($pdo, 'device_control_audit', 'old_value', 'TEXT NULL AFTER new_status');
    monEnsureColumn($pdo, 'device_control_audit', 'new_value', 'TEXT NULL AFTER old_value');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS remote_searches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL,
            local_search_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT NULL,
            username VARCHAR(100) NULL,
            search_term TEXT NOT NULL,
            search_type VARCHAR(100) NULL,
            filters_json LONGTEXT NULL,
            results_count INT UNSIGNED DEFAULT 0,
            status ENUM('started','completed','failed') DEFAULT 'started',
            error_message TEXT NULL,
            searched_at DATETIME NOT NULL,
            received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_remote_search (device_id, local_search_id),
            INDEX idx_device_date (device_id, searched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS remote_search_results (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL,
            local_search_id BIGINT UNSIGNED NOT NULL,
            local_result_id BIGINT UNSIGNED NOT NULL,
            result_type VARCHAR(100) NULL,
            title VARCHAR(255) NULL,
            username VARCHAR(255) NULL,
            full_name VARCHAR(255) NULL,
            phone VARCHAR(100) NULL,
            email VARCHAR(255) NULL,
            location VARCHAR(255) NULL,
            profile_url TEXT NULL,
            website_url TEXT NULL,
            description TEXT NULL,
            result_data_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_remote_result (device_id, local_search_id, local_result_id),
            INDEX idx_search (device_id, local_search_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS remote_downloads (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL,
            local_download_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT NULL,
            username VARCHAR(100) NULL,
            search_id BIGINT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(50) NULL,
            file_size BIGINT NULL,
            rows_count INT UNSIGNED NULL,
            download_type VARCHAR(100) NULL,
            source_description TEXT NULL,
            downloaded_records_json LONGTEXT NULL,
            downloaded_at DATETIME NOT NULL,
            received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_remote_download (device_id, local_download_id),
            INDEX idx_device_download_date (device_id, downloaded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sms_alert_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id VARCHAR(100) NOT NULL,
            remote_search_id BIGINT UNSIGNED NULL,
            recipient VARCHAR(30) NOT NULL,
            message TEXT NOT NULL,
            provider_message_id VARCHAR(255) NULL,
            delivery_status VARCHAR(50) DEFAULT 'pending',
            provider_response TEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            UNIQUE KEY unique_search_sms (device_id, remote_search_id),
            KEY idx_sms_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    require_once __DIR__ . '/mon-sms.php';
    monEnsureSmsSettings($pdo);
    monEnsureBossDevice($pdo);
}

function monEnsureBossDevice(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare('SELECT id FROM monitored_devices WHERE device_id = ? LIMIT 1');
        $stmt->execute(['BOSS-PC-001']);
        if ($stmt->fetchColumn()) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO monitored_devices
            (device_id, device_name, api_key_hash, status, license_expires_at, daily_search_limit, maintenance_mode, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 100, 0, ?, ?)'
        );
        $ins->execute([
            'BOSS-PC-001',
            'Boss Local Computer',
            '$2y$10$kmsN/oj/p5jjZC/8P1ZDCeTXmP8jmF/D.eX8/OoYb8Muy/vsW6wUe',
            'active',
            '2026-12-31 23:59:59',
            $now,
            $now,
        ]);
    } catch (Throwable $e) {
        error_log('Monitoring seed: ' . $e->getMessage());
    }
}

function monEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function monTouchDevice(PDO $pdo, string $deviceId, bool $sync = false): void
{
    $now = gmdate('Y-m-d H:i:s');
    $ip = monClientIp();
    if ($sync) {
        $stmt = $pdo->prepare(
            'UPDATE monitored_devices
             SET last_seen_at = ?, last_ip_address = ?, last_sync_at = ?,
                 last_command_received_at = ?, updated_at = ?
             WHERE device_id = ?'
        );
        $stmt->execute([$now, $ip, $now, $now, $now, $deviceId]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE monitored_devices SET last_seen_at = ?, last_ip_address = ?, updated_at = ? WHERE device_id = ?'
        );
        $stmt->execute([$now, $ip, $now, $deviceId]);
    }
}

function monMarkCommandApplied(PDO $pdo, string $deviceId): void
{
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare(
        'UPDATE monitored_devices SET last_command_applied_at = ?, updated_at = ? WHERE device_id = ?'
    )->execute([$now, $now, $deviceId]);
}

/**
 * @return array{page:int,per_page:int,offset:int}
 */
function monPagination(int $defaultPerPage = 25, int $maxPerPage = 100): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min($maxPerPage, max(1, (int) ($_GET['per_page'] ?? $defaultPerPage)));
    return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
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

    $controlMode = strtolower((string) ($device['control_mode'] ?? 'active'));
    if ($requireWritable && $controlMode === 'fully_blocked') {
        monJson(403, [
            'success' => false,
            'message' => 'Device is fully blocked.',
            'control_mode' => $controlMode,
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
    $device['sms_alerts_enabled'] = !isset($device['sms_alerts_enabled']) || !empty($device['sms_alerts_enabled']);
    $device['control_mode'] = (string) ($device['control_mode'] ?? 'active');
    $device['control_version'] = (int) ($device['control_version'] ?? 1);
    return $device;
}
