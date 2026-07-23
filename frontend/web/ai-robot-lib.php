<?php

declare(strict_types=1);

require_once __DIR__ . '/auth-init.php';

function gwRobotRuntimeDir(): string
{
    $dir = gwAuthRuntimeDir() . DIRECTORY_SEPARATOR . 'ai-robot';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function gwRobotActivityPath(): string
{
    return gwRobotRuntimeDir() . DIRECTORY_SEPARATOR . 'activity.json';
}

function gwRobotErrorsPath(): string
{
    return gwRobotRuntimeDir() . DIRECTORY_SEPARATOR . 'errors.json';
}

/**
 * @return array{events: list<array<string, mixed>>}
 */
function gwRobotReadJson(string $path): array
{
    if (!is_file($path)) {
        return ['events' => []];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['events' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['events' => []];
    }
    if (!isset($data['events']) || !is_array($data['events'])) {
        $data['events'] = [];
    }
    return $data;
}

/**
 * @param array{events: list<array<string, mixed>>} $data
 */
function gwRobotWriteJson(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $written = @file_put_contents(
        $path,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
    return $written !== false;
}

/**
 * @param array<string, mixed> $user
 */
function gwRobotLogLogin(array $user, string $method = 'password'): void
{
    $path = gwRobotActivityPath();
    $data = gwRobotReadJson($path);
    $events = $data['events'];

    $event = [
        'type' => 'login',
        'id' => bin2hex(random_bytes(8)),
        'userId' => (string) ($user['id'] ?? ''),
        'fullName' => (string) ($user['fullName'] ?? 'User'),
        'role' => (string) ($user['role'] ?? 'user'),
        'phone' => (string) ($user['phone'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'method' => $method,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'userAgent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'at' => gmdate('c'),
        'atLocal' => date('Y-m-d H:i:s'),
    ];

    array_unshift($events, $event);
    $data['events'] = array_slice($events, 0, 200);
    gwRobotWriteJson($path, $data);
}

/**
 * @param array<string, mixed> $extra
 */
function gwRobotLogError(string $source, string $message, string $severity = 'error', array $extra = []): void
{
    $path = gwRobotErrorsPath();
    $data = gwRobotReadJson($path);
    $events = $data['events'];

    $event = [
        'type' => 'error',
        'id' => bin2hex(random_bytes(8)),
        'source' => $source,
        'message' => $message,
        'severity' => $severity,
        'status' => 'open',
        'extra' => $extra,
        'at' => gmdate('c'),
        'atLocal' => date('Y-m-d H:i:s'),
    ];

    array_unshift($events, $event);
    $data['events'] = array_slice($events, 0, 150);
    gwRobotWriteJson($path, $data);
}

/**
 * @return list<array<string, mixed>>
 */
function gwRobotGetRecentLogins(int $limit = 10): array
{
    $data = gwRobotReadJson(gwRobotActivityPath());
    $logins = [];
    foreach ($data['events'] as $event) {
        if (!is_array($event) || ($event['type'] ?? '') !== 'login') {
            continue;
        }
        $logins[] = $event;
        if (count($logins) >= $limit) {
            break;
        }
    }
    return $logins;
}

/**
 * @return list<array<string, mixed>>
 */
function gwRobotGetOpenErrors(): array
{
    $data = gwRobotReadJson(gwRobotErrorsPath());
    $open = [];
    foreach ($data['events'] as $event) {
        if (!is_array($event) || ($event['type'] ?? '') !== 'error') {
            continue;
        }
        if (($event['status'] ?? 'open') === 'open') {
            $open[] = $event;
        }
    }
    return $open;
}

/**
 * @return list<string>
 */
function gwRobotScanSystemErrors(): array
{
    $found = [];

    $runtimeDir = gwAuthRuntimeDir();
    if (!is_writable($runtimeDir)) {
        $found[] = 'Runtime folder is not writable: ' . $runtimeDir;
        gwRobotLogError('system', 'Runtime folder is not writable', 'critical', ['path' => $runtimeDir]);
    }

    $sessionsDir = $runtimeDir . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionsDir)) {
        $found[] = 'Sessions folder missing';
        gwRobotLogError('system', 'Sessions folder missing', 'warning');
    } elseif (!is_writable($sessionsDir)) {
        $found[] = 'Sessions folder is not writable';
        gwRobotLogError('system', 'Sessions folder is not writable', 'warning');
    }

    $usersFile = $runtimeDir . DIRECTORY_SEPARATOR . 'auth-users.json';
    if (!is_file($usersFile)) {
        $found[] = 'User store file missing (auth-users.json)';
        gwRobotLogError('system', 'User store file missing', 'warning');
    }

    $yiiLogs = [
        dirname(__DIR__, 2) . '/frontend/runtime/logs/app.log',
        dirname(__DIR__, 2) . '/backend/runtime/logs/app.log',
    ];
    foreach ($yiiLogs as $logPath) {
        if (!is_file($logPath)) {
            continue;
        }
        $tail = gwRobotTailFile($logPath, 4096);
        if ($tail !== '' && preg_match('/\[error\]/i', $tail)) {
            $found[] = 'Recent Yii errors in ' . basename(dirname($logPath)) . '/app.log';
            gwRobotLogError('yii-log', 'Recent errors in Yii log: ' . basename(dirname($logPath)), 'error', ['path' => $logPath]);
        }
    }

    return array_values(array_unique($found));
}

function gwRobotTailFile(string $path, int $bytes = 4096): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $size = filesize($path);
    if ($size === false || $size === 0) {
        return '';
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }
    $start = max(0, $size - $bytes);
    fseek($handle, $start);
    $content = fread($handle, $bytes);
    fclose($handle);
    return is_string($content) ? $content : '';
}

/**
 * @return array{fixed: list<string>, failed: list<string>}
 */
function gwRobotAutoFix(string $errorId = ''): array
{
    $fixed = [];
    $failed = [];
    $path = gwRobotErrorsPath();
    $data = gwRobotReadJson($path);
    $events = $data['events'];

    foreach ($events as &$event) {
        if (!is_array($event) || ($event['type'] ?? '') !== 'error') {
            continue;
        }
        if (($event['status'] ?? 'open') !== 'open') {
            continue;
        }
        if ($errorId !== '' && ($event['id'] ?? '') !== $errorId) {
            continue;
        }

        $source = (string) ($event['source'] ?? '');
        $message = (string) ($event['message'] ?? '');
        $resolved = false;

        if (str_contains($message, 'Runtime folder is not writable') || str_contains($message, 'Sessions folder')) {
            $runtimeDir = gwAuthRuntimeDir();
            $sessionsDir = $runtimeDir . DIRECTORY_SEPARATOR . 'sessions';
            if (!is_dir($runtimeDir)) {
                @mkdir($runtimeDir, 0775, true);
            }
            if (!is_dir($sessionsDir)) {
                @mkdir($sessionsDir, 0775, true);
            }
            @chmod($runtimeDir, 0775);
            @chmod($sessionsDir, 0775);
            if (is_writable($runtimeDir) && is_dir($sessionsDir) && is_writable($sessionsDir)) {
                $resolved = true;
                $fixed[] = 'Fixed folder permissions for runtime/sessions';
            } else {
                $failed[] = 'Could not fix folder permissions';
            }
        }

        if (str_contains($message, 'User store file missing')) {
            $usersFile = gwAuthRuntimeDir() . DIRECTORY_SEPARATOR . 'auth-users.json';
            if (!is_file($usersFile)) {
                @file_put_contents($usersFile, json_encode(['users' => []], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
            if (is_file($usersFile)) {
                $resolved = true;
                $fixed[] = 'Created missing auth-users.json';
            } else {
                $failed[] = 'Could not create auth-users.json';
            }
        }

        if ($source === 'api' || $source === 'server') {
            $resolved = true;
            $fixed[] = 'Marked API/server error as resolved (will retry on next check)';
        }

        if ($source === 'yii-log') {
            $resolved = true;
            $fixed[] = 'Acknowledged Yii log errors (monitoring continues)';
        }

        if ($resolved) {
            $event['status'] = 'fixed';
            $event['fixedAt'] = gmdate('c');
        } elseif ($errorId !== '' && ($event['id'] ?? '') === $errorId) {
            $failed[] = 'No automatic fix available for: ' . $message;
        }
    }
    unset($event);

    gwRobotWriteJson($path, $data);

    if ($errorId === '') {
        $openCount = count(gwRobotGetOpenErrors());
        if ($openCount === 0 && empty($fixed)) {
            $fixed[] = 'No open errors to fix';
        }
    }

    return ['fixed' => $fixed, 'failed' => $failed];
}

function gwRobotFormatDuration(string $isoTime): string
{
    $ts = strtotime($isoTime);
    if ($ts === false) {
        return 'unknown time';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return $diff . ' seconds ago';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' minutes ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' hours ago';
    }
    return (int) floor($diff / 86400) . ' days ago';
}

function gwRobotFormatDurationSw(string $isoTime): string
{
    $ts = strtotime($isoTime);
    if ($ts === false) {
        return 'muda usiojulikana';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'sekunde ' . $diff . ' zilizopita';
    }
    if ($diff < 3600) {
        return 'dakika ' . (int) floor($diff / 60) . ' zilizopita';
    }
    if ($diff < 86400) {
        return 'masaa ' . (int) floor($diff / 3600) . ' yaliyopita';
    }
    return 'siku ' . (int) floor($diff / 86400) . ' zilizopita';
}
