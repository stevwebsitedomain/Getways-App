<?php

declare(strict_types=1);

/**
 * Manual WhatsApp send via Ultramsg API.
 * POST JSON: { "to": "2557...", "body": "Hello" }
 * Optional GET action=status — instance status check.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth-init.php';
require_once __DIR__ . '/env-load.php';
gwAuthStartSession();

function waJson(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $_SESSION['gw_auth_user'] ?? null;
if (!is_array($user) || strtolower((string) ($user['role'] ?? '')) !== 'admin') {
    waJson(401, ['ok' => false, 'message' => 'Admin login required.']);
}

$config = gwUltamsgConfig();
if ($config['apiUrl'] === '' || $config['token'] === '') {
    waJson(500, [
        'ok' => false,
        'message' => 'Ultamsg not configured. Set ULTAMSG_INSTANCE_ID, ULTAMSG_TOKEN, and ULTAMSG_API_URL in .env',
    ]);
}

$action = strtolower(trim((string) ($_GET['action'] ?? 'send')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function waReadJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : $_POST;
}

/**
 * @param array<string, string> $fields
 * @return array{http:int,body:string,json:?array}
 */
function waUltamsgPost(string $url, array $fields): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['http' => 0, 'body' => 'curl_init failed', 'json' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['http' => $http, 'body' => $err !== '' ? $err : 'request failed', 'json' => null];
    }

    $json = json_decode((string) $body, true);

    return [
        'http' => $http,
        'body' => (string) $body,
        'json' => is_array($json) ? $json : null,
    ];
}

/**
 * @return array{http:int,body:string,json:?array}
 */
function waUltamsgGet(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['http' => 0, 'body' => 'curl_init failed', 'json' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['http' => $http, 'body' => $err !== '' ? $err : 'request failed', 'json' => null];
    }

    $json = json_decode((string) $body, true);

    return [
        'http' => $http,
        'body' => (string) $body,
        'json' => is_array($json) ? $json : null,
    ];
}

if ($action === 'status') {
    $url = $config['apiUrl'] . '/instance/status?token=' . rawurlencode($config['token']);
    $res = waUltamsgGet($url);
    $ok = $res['http'] >= 200 && $res['http'] < 300;
    waJson($ok ? 200 : 502, [
        'ok' => $ok,
        'message' => $ok ? 'Instance status loaded.' : 'Could not read instance status.',
        'http' => $res['http'],
        'instanceId' => $config['instanceId'],
        'apiUrl' => $config['apiUrl'],
        'data' => $res['json'] ?? $res['body'],
    ]);
}

if ($action !== 'send' || $method !== 'POST') {
    waJson(400, ['ok' => false, 'message' => 'Use POST action=send with to + body, or GET action=status.']);
}

$input = waReadJsonBody();
$to = preg_replace('/\D+/', '', (string) ($input['to'] ?? '')) ?? '';
$body = trim((string) ($input['body'] ?? ''));

if ($to === '' || strlen($to) < 9) {
    waJson(422, ['ok' => false, 'message' => 'Enter a valid phone number (international digits, e.g. 2557XXXXXXXX).']);
}
if ($body === '') {
    waJson(422, ['ok' => false, 'message' => 'Message body is required.']);
}

$url = $config['apiUrl'] . '/messages/chat';
$res = waUltamsgPost($url, [
    'token' => $config['token'],
    'to' => $to,
    'body' => $body,
    'priority' => '10',
    'referenceId' => 'getway-manual',
]);

$payload = $res['json'];
$sent = $res['http'] >= 200 && $res['http'] < 300
    && is_array($payload)
    && (
        (isset($payload['sent']) && (string) $payload['sent'] === 'true')
        || isset($payload['id'])
        || isset($payload['message'])
    );

// Ultramsg often returns { "sent": "true", "message": "ok", "id": 123 }
if (!$sent && is_array($payload) && isset($payload['error'])) {
    waJson(502, [
        'ok' => false,
        'message' => (string) $payload['error'],
        'http' => $res['http'],
        'data' => $payload,
    ]);
}

if ($res['http'] >= 200 && $res['http'] < 300) {
    waJson(200, [
        'ok' => true,
        'message' => 'Message submitted to Ultramsg.',
        'to' => $to,
        'http' => $res['http'],
        'data' => $payload ?? $res['body'],
    ]);
}

waJson(502, [
    'ok' => false,
    'message' => 'Ultramsg request failed.',
    'http' => $res['http'],
    'data' => $payload ?? $res['body'],
]);
