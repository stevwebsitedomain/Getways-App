<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();

function userJson(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $_SESSION['gw_auth_user'] ?? null;
if (!is_array($user)) {
    userJson(401, ['ok' => false, 'success' => false, 'message' => 'Login required.']);
}

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? '')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : $_POST;
}

function userRemoteUpstream(): string
{
    $fromEnv = getenv('ADMIN_DATA_UPSTREAM');
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        return rtrim(trim($fromEnv), '/');
    }

    return 'https://getways-app.onrender.com';
}

/**
 * @return array<string,mixed>|null
 */
function userFetchRemote(string $remoteAction, string $httpMethod = 'GET', ?array $body = null): ?array
{
    $url = userRemoteUpstream() . '/admin/' . rawurlencode($remoteAction);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    $token = getenv('ADMIN_API_TOKEN');
    if (is_string($token) && trim($token) !== '') {
        $headers[] = 'X-Admin-Proxy-Token: ' . trim($token);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($httpMethod),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($body !== null && strtoupper($httpMethod) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return null;
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if ($status >= 400 || ($decoded['success'] ?? true) === false) {
        return [
            'ok' => false,
            'success' => false,
            'message' => (string) ($decoded['message'] ?? 'Remote API failed.'),
            'remoteStatus' => $status,
        ];
    }

    return $decoded;
}

function normalizePhone(string $phone): string
{
    return preg_replace('/[^\d+]/', '', trim($phone)) ?? '';
}

if ($action === 'transactions' && $method === 'GET') {
    $remote = userFetchRemote('control-numbers', 'GET');
    if ($remote === null) {
        userJson(503, ['ok' => false, 'success' => false, 'message' => 'Could not load transactions.']);
    }
    if (($remote['success'] ?? true) === false) {
        userJson((int) ($remote['remoteStatus'] ?? 502), $remote);
    }

    $phone = normalizePhone((string) ($user['phone'] ?? ''));
    $items = is_array($remote['items'] ?? null) ? $remote['items'] : [];
    if ($phone !== '') {
        $items = array_values(array_filter($items, static function ($row) use ($phone, $user) {
            if (!is_array($row)) {
                return false;
            }
            $rowPhone = normalizePhone((string) ($row['customerPhone'] ?? $row['phone'] ?? ''));
            $name = strtolower(trim((string) ($row['customerName'] ?? '')));
            $userName = strtolower(trim((string) ($user['fullName'] ?? '')));
            return ($rowPhone !== '' && $rowPhone === $phone)
                || ($userName !== '' && $name !== '' && $name === $userName);
        }));
    }

    userJson(200, ['ok' => true, 'success' => true, 'items' => $items, 'source' => 'render-user-proxy']);
}

if ($action === 'create-control-number' && $method === 'POST') {
    $body = readJsonBody();
    $body['customerName'] = (string) ($user['fullName'] ?? 'Customer');
    if (!empty($user['phone'])) {
        $body['phone'] = (string) $user['phone'];
    }

    $remote = userFetchRemote('create-control-number', 'POST', $body);
    if ($remote === null) {
        userJson(503, ['ok' => false, 'success' => false, 'message' => 'Could not create control number.']);
    }
    if (($remote['success'] ?? true) === false) {
        userJson((int) ($remote['remoteStatus'] ?? 502), $remote);
    }

    userJson(200, ['ok' => true, 'success' => true] + $remote);
}

userJson(400, ['ok' => false, 'success' => false, 'message' => 'Unknown action.']);
