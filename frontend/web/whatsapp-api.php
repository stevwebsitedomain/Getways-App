<?php

declare(strict_types=1);

/**
 * Ultramsg WhatsApp API proxy (admin).
 *
 * GET  ?action=status
 * GET  ?action=messages&status=all|sent|queue|unsent|invalid|expired&page=1&limit=50
 * GET  ?action=webhook-events
 * POST ?action=send  JSON { to, body, priority? }
 * POST ?action=delete JSON { id }
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
 * @param array<string, scalar|null> $fields
 * @return array{http:int,body:string,json:?array,error:string}
 */
function waUltamsgPost(string $url, array $fields): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['http' => 0, 'body' => 'curl_init failed', 'json' => null, 'error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERAGENT => 'Getways-App-WhatsApp/1.0',
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['http' => $http, 'body' => $err !== '' ? $err : 'request failed', 'json' => null, 'error' => $err];
    }

    $json = json_decode((string) $body, true);

    return [
        'http' => $http,
        'body' => (string) $body,
        'json' => is_array($json) ? $json : null,
        'error' => $err,
    ];
}

/**
 * @return array{http:int,body:string,json:?array,error:string}
 */
function waUltamsgGet(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['http' => 0, 'body' => 'curl_init failed', 'json' => null, 'error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Getways-App-WhatsApp/1.0',
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['http' => $http, 'body' => $err !== '' ? $err : 'request failed', 'json' => null, 'error' => $err];
    }

    $json = json_decode((string) $body, true);

    return [
        'http' => $http,
        'body' => (string) $body,
        'json' => is_array($json) ? $json : null,
        'error' => $err,
    ];
}

function waNormalizeMessages(?array $json): array
{
    if ($json === null) {
        return [];
    }
    if (isset($json['messages']) && is_array($json['messages'])) {
        return array_values($json['messages']);
    }
    if (array_is_list($json)) {
        return $json;
    }
    if (isset($json['data']) && is_array($json['data'])) {
        return array_values($json['data']);
    }

    return [];
}

function waProviderErrorMessage(array $res): string
{
    $json = $res['json'] ?? null;
    if (is_array($json)) {
        foreach (['error', 'message', 'msg'] as $key) {
            if (!empty($json[$key]) && is_scalar($json[$key])) {
                return trim((string) $json[$key]);
            }
        }
    }
    if (!empty($res['error'])) {
        return (string) $res['error'];
    }
    $body = trim((string) ($res['body'] ?? ''));
    if ($body !== '' && strlen($body) < 240) {
        return $body;
    }
    if ((int) ($res['http'] ?? 0) === 0) {
        return 'Server could not reach Ultramsg (network/SSL).';
    }

    return 'Could not load messages from Ultramsg.';
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
        'senderName' => $config['senderName'],
        'webhookUrl' => $config['webhookUrl'],
        'data' => $res['json'] ?? $res['body'],
    ]);
}

if ($action === 'messages') {
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    $allowed = ['all', 'sent', 'queue', 'unsent', 'invalid', 'expired'];
    if (!in_array($status, $allowed, true)) {
        $status = 'all';
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $sort = strtolower(trim((string) ($_GET['sort'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';

    $query = [
        'token' => $config['token'],
        'page' => $page,
        'limit' => $limit,
        'status' => $status,
        'sort' => $sort,
    ];
    $url = $config['apiUrl'] . '/messages?' . http_build_query($query);
    $res = waUltamsgGet($url);

    // Retry without sort if first call fails (some Ultramsg responses are picky).
    if (!(($res['http'] >= 200 && $res['http'] < 300) || isset($res['json']['messages']))) {
        unset($query['sort']);
        $url = $config['apiUrl'] . '/messages?' . http_build_query($query);
        $res = waUltamsgGet($url);
    }

    $messages = waNormalizeMessages($res['json']);
    $hasMessagesKey = is_array($res['json']) && array_key_exists('messages', $res['json']);
    $ok = ($res['http'] >= 200 && $res['http'] < 300 && !isset($res['json']['error'])) || ($hasMessagesKey && !isset($res['json']['error']));
    $detail = $ok ? null : waProviderErrorMessage($res);

    waJson($ok ? 200 : 502, [
        'ok' => $ok,
        'message' => $ok ? 'Messages loaded.' : ($detail ?: 'Could not load messages from Ultramsg.'),
        'http' => $res['http'],
        'status' => $status,
        'page' => $page,
        'limit' => $limit,
        'count' => count($messages),
        'messages' => $ok ? $messages : [],
        'instanceId' => $config['instanceId'],
        'raw' => $ok ? null : ($res['json'] ?? $res['body']),
    ]);
}

if ($action === 'webhook-events') {
    $path = gwWhatsappWebhookLogPath();
    $events = [];
    if (is_file($path) && is_readable($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($lines, -80);
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
    }
    waJson(200, [
        'ok' => true,
        'message' => 'Webhook events loaded.',
        'webhookUrl' => $config['webhookUrl'],
        'count' => count($events),
        'events' => $events,
    ]);
}

if ($action === 'delete' && $method === 'POST') {
    $input = waReadJsonBody();
    $id = trim((string) ($input['id'] ?? $input['msgId'] ?? ''));
    if ($id === '') {
        waJson(422, ['ok' => false, 'message' => 'Message id is required.']);
    }

    // Best-effort Ultramsg delete; UI also hides locally.
    $url = $config['apiUrl'] . '/messages/delete';
    $res = waUltamsgPost($url, [
        'token' => $config['token'],
        'msgId' => $id,
        'id' => $id,
    ]);
    $ok = $res['http'] >= 200 && $res['http'] < 300
        && !(is_array($res['json']) && isset($res['json']['error']));

    waJson(200, [
        'ok' => true,
        'message' => $ok ? 'Message deleted.' : 'Removed locally. Provider delete may be unsupported.',
        'providerOk' => $ok,
        'http' => $res['http'],
        'data' => $res['json'] ?? $res['body'],
    ]);
}

if ($action !== 'send' || $method !== 'POST') {
    waJson(400, [
        'ok' => false,
        'message' => 'Use POST action=send|delete, or GET action=status|messages|webhook-events.',
    ]);
}

$input = waReadJsonBody();
$to = preg_replace('/\D+/', '', (string) ($input['to'] ?? '')) ?? '';
$body = trim((string) ($input['body'] ?? ''));
$priority = trim((string) ($input['priority'] ?? '10'));
if ($priority === '') {
    $priority = '10';
}

if ($to === '' || strlen($to) < 9) {
    waJson(422, ['ok' => false, 'message' => 'Enter a valid phone number (international digits, e.g. 2557XXXXXXXX).']);
}
if ($body === '') {
    waJson(422, ['ok' => false, 'message' => 'Message body is required.']);
}

$sender = $config['senderName'];
// Brand the outgoing text so recipients clearly see Digital Matrix Technology.
if ($sender !== '' && !str_starts_with($body, '*' . $sender . '*') && !str_starts_with($body, $sender)) {
    $body = '*' . $sender . "*\n\n" . $body;
}

$url = $config['apiUrl'] . '/messages/chat';
$res = waUltamsgPost($url, [
    'token' => $config['token'],
    'to' => $to,
    'body' => $body,
    'priority' => $priority,
    'referenceId' => 'getway-manual',
]);

$payload = $res['json'];

if (is_array($payload) && isset($payload['error'])) {
    waJson(502, [
        'ok' => false,
        'message' => (string) $payload['error'],
        'http' => $res['http'],
        'senderName' => $sender,
        'data' => $payload,
    ]);
}

if ($res['http'] >= 200 && $res['http'] < 300) {
    waJson(200, [
        'ok' => true,
        'message' => 'Message submitted to Ultramsg.',
        'to' => $to,
        'senderName' => $sender,
        'bodySent' => $body,
        'http' => $res['http'],
        'data' => $payload ?? $res['body'],
    ]);
}

waJson(502, [
    'ok' => false,
    'message' => 'Ultramsg request failed.',
    'http' => $res['http'],
    'senderName' => $sender,
    'data' => $payload ?? $res['body'],
]);
