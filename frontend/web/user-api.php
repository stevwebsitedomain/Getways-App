<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();
require_once __DIR__ . '/invoice-share.php';

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

/**
 * @return \yii\web\Application
 */
function userYiiApp(): \yii\web\Application
{
    static $app = null;
    if ($app instanceof \yii\web\Application) {
        return $app;
    }

    defined('YII_DEBUG') or define('YII_DEBUG', true);
    defined('YII_ENV') or define('YII_ENV', 'dev');

    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    require_once dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
    require_once dirname(__DIR__, 2) . '/common/config/bootstrap.php';
    require_once dirname(__DIR__) . '/config/bootstrap.php';

    $config = yii\helpers\ArrayHelper::merge(
        require dirname(__DIR__, 2) . '/common/config/main.php',
        require dirname(__DIR__, 2) . '/common/config/main-local.php',
        require dirname(__DIR__) . '/config/main.php',
        require dirname(__DIR__) . '/config/main-local.php'
    );

    if (isset($config['components']['session'])) {
        $config['components']['session']['autoStart'] = false;
    }
    if (isset($config['components']['user'])) {
        $config['components']['user']['enableSession'] = false;
        $config['components']['user']['enableAutoLogin'] = false;
    }

    $app = new yii\web\Application($config);

    return $app;
}

/** @return \common\services\ClickPesaService */
function userClickPesa(): \common\services\ClickPesaService
{
    return Yii::$container->get(\common\services\ClickPesaService::class);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function userNormalizeInvoiceRow(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 && preg_match('/[?&]id=(\d+)/', (string) ($row['invoiceUrl'] ?? ''), $matches) === 1) {
        $id = (int) $matches[1];
    }
    if ($id > 0) {
        $row['invoiceUrl'] = invoiceShareUrl($id, 'user');
        $row['receiptShareUrl'] = invoiceFullShareUrl($id, 'user');
    }

    return $row;
}

/**
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function userNormalizeControlNumberResult(array $result): array
{
    if (!empty($result['id'])) {
        $id = (int) $result['id'];
        $result['invoiceUrl'] = invoiceShareUrl($id, 'user');
        $result['receiptShareUrl'] = invoiceFullShareUrl($id, 'user');
    }

    return $result;
}

/**
 * @param array<string,mixed> $body
 * @return array<string,mixed>
 */
function userRemoteControlNumberBody(array $body): array
{
    unset($body['customerName'], $body['customerPhone'], $body['phone']);

    return $body;
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

    $items = array_map('userNormalizeInvoiceRow', $items);

    userJson(200, ['ok' => true, 'success' => true, 'items' => $items, 'source' => 'render-user-proxy']);
}

if ($action === 'create-control-number' && $method === 'POST') {
    $body = readJsonBody();
    $customerName = (string) ($user['fullName'] ?? 'Customer');
    $customerPhone = !empty($user['phone']) ? (string) $user['phone'] : '';
    $userId = isset($user['id']) && $user['id'] !== '' ? (int) $user['id'] : null;

    try {
        userYiiApp();
        $result = userClickPesa()->createControlNumber([
            'order_id' => $body['order_id'] ?? $body['orderId'] ?? '',
            'amount' => $body['amount'] ?? 0,
            'description' => $body['description'] ?? '',
            'payment_mode' => $body['payment_mode'] ?? 'EXACT',
            'customerName' => $customerName,
            'phone' => $customerPhone,
        ], $userId);
        userJson(200, ['ok' => true, 'success' => true, 'source' => 'local-clickpesa'] + userNormalizeControlNumberResult($result));
    } catch (yii\web\HttpException $e) {
        userJson($e->statusCode, ['ok' => false, 'success' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        $remote = userFetchRemote('create-control-number', 'POST', userRemoteControlNumberBody($body));
        if ($remote === null) {
            userJson(503, ['ok' => false, 'success' => false, 'message' => $e->getMessage() ?: 'Could not create control number.']);
        }
        if (($remote['success'] ?? true) === false) {
            userJson((int) ($remote['remoteStatus'] ?? 502), $remote);
        }

        userJson(200, ['ok' => true, 'success' => true, 'source' => 'render-user-proxy'] + userNormalizeControlNumberResult($remote));
    }
}

userJson(400, ['ok' => false, 'success' => false, 'message' => 'Unknown action.']);
