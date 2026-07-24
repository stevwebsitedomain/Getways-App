<?php

declare(strict_types=1);

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();

if (!isset($_SESSION['gw_auth_user']) || !is_array($_SESSION['gw_auth_user'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Not logged in.']);
    exit;
}

$role = strtolower((string) ($_SESSION['gw_auth_user']['role'] ?? 'user'));
if ($role !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Admin access required.']);
    exit;
}

function camProxyJson(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

function camProxyNormalizeIp(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return '';
    }
    return $ip;
}

function camProxyBuildUrl(string $ip, int $port, string $path, string $user = '', string $pass = ''): string
{
    $path = '/' . ltrim($path, '/');
    $auth = '';
    if ($user !== '') {
        $auth = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
    }
    return "http://{$auth}{$ip}:{$port}{$path}";
}

function camProxyProbeUrl(string $url, int $timeout = 4): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Getway-CIA-Camera/1.0',
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_RANGE => '0-2048',
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 400) {
        return ['ok' => false, 'code' => $code, 'error' => $error];
    }

    $isVideo = str_contains(strtolower($ctype), 'image')
        || str_contains(strtolower($ctype), 'multipart')
        || str_contains(strtolower($ctype), 'mjpeg')
        || str_contains(strtolower($ctype), 'octet-stream');

    return ['ok' => $isVideo || $code === 200, 'code' => $code, 'content_type' => $ctype];
}

function camProxyFetchBinary(string $url, int $timeout = 8): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Getway-CIA-Camera/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 400) {
        return ['ok' => false, 'error' => $error ?: "HTTP {$code}"];
    }

    return ['ok' => true, 'body' => $body, 'content_type' => $ctype ?: 'image/jpeg'];
}

$action = (string) ($_GET['action'] ?? 'probe');

if ($action === 'probe') {
    $ip = camProxyNormalizeIp((string) ($_GET['ip'] ?? ''));
    if ($ip === '') {
        camProxyJson(400, ['ok' => false, 'message' => 'Valid camera IP required.']);
    }

    $user = trim((string) ($_GET['user'] ?? ''));
    $pass = trim((string) ($_GET['pass'] ?? ''));
    $paths = [
        '/video.mjpg',
        '/mjpg/video.mjpg',
        '/cgi-bin/mjpg/video.cgi',
        '/videostream.cgi',
        '/stream',
        '/live',
        '/cam/realmonitor?channel=1&subtype=1',
        '/axis-cgi/mjpg/video.cgi',
        '/ISAPI/Streaming/channels/101/httpPreview',
        '/snapshot.jpg',
        '/jpg/image.jpg',
        '/onvif/snapshot',
    ];
    $ports = [80, 8080, 8000, 88, 5540];

    $found = [];
    foreach ($ports as $port) {
        foreach ($paths as $path) {
            $url = camProxyBuildUrl($ip, $port, $path, $user, $pass);
            $probe = camProxyProbeUrl($url, 3);
            if (!$probe['ok']) {
                continue;
            }
            $label = "Port {$port} · {$path}";
            $found[] = [
                'label' => $label,
                'url' => $url,
                'display_url' => "http://{$ip}:{$port}{$path}",
                'port' => $port,
                'path' => $path,
                'content_type' => $probe['content_type'] ?? '',
            ];
            if (count($found) >= 8) {
                break 2;
            }
        }
    }

    camProxyJson(200, [
        'ok' => true,
        'ip' => $ip,
        'cameras' => $found,
        'message' => count($found) ? 'Camera streams found.' : 'No camera stream found on common paths. Try manual URL.',
    ]);
}

if ($action === 'snapshot') {
    $camera = trim((string) ($_GET['camera'] ?? ''));
    if ($camera === '' || !preg_match('#^https?://#i', $camera)) {
        camProxyJson(400, ['ok' => false, 'message' => 'Invalid camera URL.']);
    }

    $parsed = parse_url($camera);
    $host = strtolower((string) ($parsed['host'] ?? ''));
  if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
        camProxyJson(400, ['ok' => false, 'message' => 'Camera host not allowed.']);
    }

    $fetch = camProxyFetchBinary($camera);
    if (!$fetch['ok']) {
        camProxyJson(502, ['ok' => false, 'message' => 'Could not fetch camera frame.', 'error' => $fetch['error'] ?? '']);
    }

    header('Content-Type: ' . ($fetch['content_type'] ?? 'image/jpeg'));
    header('Cache-Control: no-store');
    echo $fetch['body'];
    exit;
}

camProxyJson(400, ['ok' => false, 'message' => 'Unknown action.']);
