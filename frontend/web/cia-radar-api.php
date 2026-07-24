<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();

if (!isset($_SESSION['gw_auth_user']) || !is_array($_SESSION['gw_auth_user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not logged in.']);
    exit;
}

function ciaProxyEnv(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return (string) $v;
    }
    $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envPath)) {
        return $default;
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $val] = explode('=', $line, 2);
        if (trim($k) === $key) {
            return trim($val, " \t\"'");
        }
    }
    return $default;
}

$upstream = rtrim(ciaProxyEnv('RADAR_SERVICE_URL', 'http://127.0.0.1:8765'), '/');
$path = (string) ($_GET['path'] ?? '/api/radar/status');
if (!str_starts_with($path, '/')) {
    $path = '/' . $path;
}
if (str_contains($path, '..')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid path.']);
    exit;
}

$url = $upstream . $path;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = file_get_contents('php://input');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_TIMEOUT => 45,
    CURLOPT_CONNECTTIMEOUT => 8,
]);
if ($method !== 'GET' && $body !== false && $body !== '') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$response = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'message' => 'Radar service unavailable. Start radar-service on port 8765.',
        'error' => $error,
    ]);
    exit;
}

http_response_code($code > 0 ? $code : 200);
echo $response;
