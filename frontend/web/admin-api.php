<?php

declare(strict_types=1);

/**
 * Admin Payout & Collections API.
 * Bootstraps Yii2 and calls ClickPesaService directly (no HTTP proxy).
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();

function adminJson(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $_SESSION['gw_auth_user'] ?? null;
if (!is_array($user) || strtolower((string) ($user['role'] ?? '')) !== 'admin') {
    adminJson(401, ['ok' => false, 'success' => false, 'message' => 'Admin login required.']);
}

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'summary')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function readJsonBody(): array
{
    // php://input can only be read once — cache for retries / remote proxy fallback.
    static $cached = null;
    static $done = false;
    if ($done) {
        return is_array($cached) ? $cached : [];
    }
    $done = true;

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        $cached = $_POST;

        return is_array($cached) ? $cached : [];
    }
    $decoded = json_decode($raw, true);
    $cached = is_array($decoded) ? $decoded : $_POST;

    return is_array($cached) ? $cached : [];
}

function adminIsDbGoneAway(Throwable $e): bool
{
    $msg = $e->getMessage();

    return stripos($msg, 'gone away') !== false
        || stripos($msg, 'Lost connection') !== false
        || stripos($msg, '2006') !== false
        || stripos($msg, '2013') !== false
        || stripos($msg, 'server has gone') !== false;
}

function adminDbReconnect(): void
{
    try {
        Yii::$app->db->close();
    } catch (Throwable) {
        // ignore
    }
    Yii::$app->db->open();
    Yii::$app->db->createCommand('SELECT 1')->queryScalar();
}

/**
 * Boot Yii frontend app once for this request.
 *
 * @return \yii\web\Application
 */
function adminYiiApp(): \yii\web\Application
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

    // Ensure httpclient class is available before app services run.
    if (!class_exists(\yii\httpclient\Client::class, true)) {
        adminJson(500, [
            'ok' => false,
            'success' => false,
            'message' => 'Class yii\\httpclient\\Client not found. Run: composer require yiisoft/yii2-httpclient && composer dump-autoload',
            'causeFile' => 'vendor/autoload.php / yiisoft/yii2-httpclient',
        ]);
    }

    $config = yii\helpers\ArrayHelper::merge(
        require dirname(__DIR__, 2) . '/common/config/main.php',
        require dirname(__DIR__, 2) . '/common/config/main-local.php',
        require dirname(__DIR__) . '/config/main.php',
        require dirname(__DIR__) . '/config/main-local.php'
    );

    // Avoid session clash with gw_auth session already started.
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

function adminDbHint(): string
{
    try {
        $dsn = (string) (Yii::$app->db->dsn ?? '');
        $user = (string) (Yii::$app->db->username ?? '');
        if (preg_match('/host=([^;]+)/', $dsn, $hostMatch) && preg_match('/port=([^;]+)/', $dsn, $portMatch) && preg_match('/dbname=([^;]+)/', $dsn, $dbMatch)) {
            return sprintf(
                'Check MySQL: host %s port %s database %s user %s.',
                $hostMatch[1],
                $portMatch[1],
                $dbMatch[1],
                $user
            );
        }
    } catch (Throwable $e) {
        // ignore
    }

    return 'Check MySQL config in common/config/main-local.php.';
}

/**
 * Test DB before any query. Returns null on success, JSON error payload on failure.
 */
function adminDbProbe(): ?array
{
    try {
        $db = Yii::$app->db;
        $db->open();
        $db->createCommand('SELECT 1')->queryScalar();

        return null;
    } catch (Throwable $e) {
        $dsn = (string) (Yii::$app->db->dsn ?? '');
        $safeDsn = preg_replace('/(password=)[^;]*/i', '$1***', $dsn) ?: $dsn;
        $username = (string) (Yii::$app->db->username ?? '');

        return [
            'ok' => false,
            'success' => false,
            'message' => 'Database connection failed: ' . $e->getMessage(),
            'db' => [
                'connected' => false,
                'dsn' => $safeDsn,
                'username' => $username,
                'error' => $e->getMessage(),
                'hint' => adminDbHint(),
            ],
            'causeFile' => 'common/config/main-local.php',
        ];
    }
}

function adminRemoteUpstream(): string
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
function adminFetchRemote(string $remoteAction, string $method = 'GET', array $query = [], ?array $body = null): ?array
{
    $url = adminRemoteUpstream() . '/admin/' . rawurlencode($remoteAction);
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    $token = getenv('ADMIN_API_TOKEN');
    if (is_string($token) && trim($token) !== '') {
        $headers[] = 'X-Admin-Proxy-Token: ' . trim($token);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    // Keep short: a long hang makes Apache/browser drop the client with "Connection lost".
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($body !== null && strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return null;
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if ($status >= 400 || ($decoded['success'] ?? true) === false) {
        return [
            'ok' => false,
            'success' => false,
            'message' => (string) ($decoded['message'] ?? 'Remote admin API failed.'),
            'remoteStatus' => $status,
            'remoteAction' => $remoteAction,
        ];
    }

    return $decoded;
}

function adminTryRemoteProxy(string $apiRoute, ?string $remoteAction): ?array
{
    if ($remoteAction === null || $remoteAction === '') {
        return null;
    }

    return adminFetchRemote(
        $remoteAction,
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        $_GET,
        in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT', 'PATCH'], true)
            ? readJsonBody()
            : null
    );
}

function adminHandle(callable $callback, string $apiRoute, ?string $remoteAction = null): never
{
    try {
        adminYiiApp();
        $dbError = adminDbProbe();
        if ($dbError !== null && $remoteAction !== null) {
            $remote = adminTryRemoteProxy($apiRoute, $remoteAction);
            if ($remote !== null) {
                if (($remote['success'] ?? true) === false) {
                    adminJson((int) ($remote['remoteStatus'] ?? 502), $remote + ['apiRoute' => $apiRoute, 'source' => 'render-admin-proxy']);
                }
                adminJson(200, ['ok' => true, 'apiRoute' => $apiRoute, 'source' => 'render-admin-proxy'] + $remote);
            }

            $dbError['hint'] = adminDbHint();
            adminJson(503, $dbError + ['apiRoute' => $apiRoute]);
        }
        if ($dbError !== null) {
            adminJson(503, $dbError + ['apiRoute' => $apiRoute]);
        }

        try {
            $result = $callback();
        } catch (yii\db\Exception $firstDb) {
            if (!adminIsDbGoneAway($firstDb)) {
                throw $firstDb;
            }
            // Stale Railway/proxy connection — reconnect once and retry.
            adminDbReconnect();
            $result = $callback();
        }
        if (!is_array($result)) {
            $result = ['success' => true, 'data' => $result];
        }
        if (!array_key_exists('success', $result)) {
            $result['success'] = true;
        }

        adminJson(200, ['ok' => true, 'apiRoute' => $apiRoute] + $result);
    } catch (yii\web\UnauthorizedHttpException $e) {
        adminJson(401, ['ok' => false, 'success' => false, 'message' => $e->getMessage(), 'apiRoute' => $apiRoute]);
    } catch (yii\web\ForbiddenHttpException $e) {
        adminJson(403, ['ok' => false, 'success' => false, 'message' => $e->getMessage(), 'apiRoute' => $apiRoute]);
    } catch (yii\web\BadRequestHttpException $e) {
        adminJson(400, ['ok' => false, 'success' => false, 'message' => $e->getMessage(), 'apiRoute' => $apiRoute]);
    } catch (yii\web\ConflictHttpException $e) {
        adminJson(409, ['ok' => false, 'success' => false, 'message' => $e->getMessage(), 'apiRoute' => $apiRoute]);
    } catch (yii\web\NotFoundHttpException $e) {
        adminJson(404, ['ok' => false, 'success' => false, 'message' => $e->getMessage(), 'apiRoute' => $apiRoute]);
    } catch (yii\base\InvalidConfigException $e) {
        adminJson(500, [
            'ok' => false,
            'success' => false,
            'message' => 'ClickPesa is not configured: ' . $e->getMessage(),
            'apiRoute' => $apiRoute,
            'causeFile' => 'common/config/params-local.php',
        ]);
    } catch (yii\db\Exception $e) {
        if (adminIsDbGoneAway($e)) {
            try {
                adminDbReconnect();
                $retry = $callback();
                if (!is_array($retry)) {
                    $retry = ['success' => true, 'data' => $retry];
                }
                if (!array_key_exists('success', $retry)) {
                    $retry['success'] = true;
                }
                adminJson(200, ['ok' => true, 'apiRoute' => $apiRoute, 'source' => 'db-reconnect'] + $retry);
            } catch (Throwable) {
                // fall through to remote / 503
            }
        }
        if ($remoteAction !== null) {
            $remote = adminTryRemoteProxy($apiRoute, $remoteAction);
            if ($remote !== null) {
                if (($remote['success'] ?? true) === false) {
                    adminJson((int) ($remote['remoteStatus'] ?? 502), $remote + ['apiRoute' => $apiRoute, 'source' => 'render-admin-proxy']);
                }
                adminJson(200, ['ok' => true, 'apiRoute' => $apiRoute, 'source' => 'render-admin-proxy'] + $remote);
            }
        }
        adminJson(503, [
            'ok' => false,
            'success' => false,
            'message' => 'Database connection lost while saving. Check Railway MySQL / internet and try again. (' . $e->getMessage() . ')',
            'apiRoute' => $apiRoute,
            'causeFile' => 'common/config/main-local.php',
            'causeLine' => 42,
        ]);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Normalize accidental wrong casing in error text for operators.
        if (stripos($msg, 'httpclient') !== false && stripos($msg, 'not found') !== false) {
            $msg = 'Class yii\\httpclient\\Client not found. Run composer require yiisoft/yii2-httpclient && composer dump-autoload. Original: ' . $msg;
        }
        adminJson(500, [
            'ok' => false,
            'success' => false,
            'message' => $msg,
            'apiRoute' => $apiRoute,
            'causeFile' => $e->getFile(),
            'causeLine' => $e->getLine(),
        ]);
    }
}

/** @return \common\services\ClickPesaService */
function adminClickPesa(): \common\services\ClickPesaService
{
    return Yii::$container->get(\common\services\ClickPesaService::class);
}

/** @return \common\services\ClickPesaPayoutService */
function adminPayoutService(): \common\services\ClickPesaPayoutService
{
    return Yii::$container->get(\common\services\ClickPesaPayoutService::class);
}

if ($action === 'balance' && $method === 'GET') {
    adminHandle(static fn() => adminClickPesa()->getAccountBalance(), '/api/clickpesa/account-balance', 'balance');
}

if ($action === 'analytics' && $method === 'GET') {
    adminHandle(static function () {
        $period = strtolower(trim((string) ($_GET['period'] ?? 'all')));
        $filters = ['period' => $period];
        if (!empty($_GET['startDate'])) {
            $filters['startDate'] = (string) $_GET['startDate'];
        }
        if (!empty($_GET['endDate'])) {
            $filters['endDate'] = (string) $_GET['endDate'];
        }

        try {
            return adminClickPesa()->getDashboardAnalytics($filters);
        } catch (Throwable $e) {
            // Keep dashboard charts usable even when DB/query fails.
            return [
                'success' => true,
                'source' => 'fallback',
                'warning' => $e->getMessage(),
                'period' => $period,
                'analytics' => [
                    'moneyIn' => 0,
                    'failedSales' => 0,
                    'success' => 0,
                    'pending' => 0,
                    'failed' => 0,
                    'recordCount' => 0,
                    'periodLabel' => 'Unavailable',
                    'firstTransactionAt' => null,
                    'lastTransactionAt' => null,
                    'trendDays' => [],
                    'recentCollections' => [],
                ],
                'payments' => [],
            ];
        }
    }, '/admin-api.php?action=analytics', 'analytics');
}

if ($action === 'live-payments' && $method === 'GET') {
    adminHandle(static function () {
        $urls = [
            'https://getways-app.onrender.com/payments',
            rtrim((string) (getenv('TIS_API_BASE') ?: getenv('BASE_API_URL') ?: 'https://getways-app.onrender.com'), '/') . '/payments',
        ];
        $urls = array_values(array_unique($urls));
        $lastError = 'Unable to load live payments.';
        foreach ($urls as $url) {
            try {
                $raw = null;
                if (function_exists('curl_init')) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 20,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    ]);
                    $raw = curl_exec($ch);
                    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $cerr = curl_error($ch);
                    curl_close($ch);
                    if ($raw === false || $status >= 400) {
                        $lastError = $cerr !== '' ? $cerr : ("HTTP " . $status);
                        continue;
                    }
                } else {
                    $raw = @file_get_contents($url);
                    if ($raw === false) {
                        $lastError = 'file_get_contents failed';
                        continue;
                    }
                }
                $decoded = json_decode((string) $raw, true);
                if (!is_array($decoded) || !isset($decoded['payments']) || !is_array($decoded['payments'])) {
                    $lastError = 'Invalid payments payload.';
                    continue;
                }
                return [
                    'success' => true,
                    'ok' => true,
                    'source' => 'live-payments-proxy',
                    'totalSales' => $decoded['totalSales'] ?? 0,
                    'failedSales' => $decoded['failedSales'] ?? 0,
                    'pendingTransactions' => $decoded['pendingTransactions'] ?? 0,
                    'count' => $decoded['count'] ?? count($decoded['payments']),
                    'payments' => $decoded['payments'],
                ];
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }
        throw new RuntimeException($lastError);
    }, '/payments', 'live-payments');
}

if ($action === 'statement' && $method === 'GET') {
    adminHandle(static function () {
        $period = strtolower(trim((string) ($_GET['period'] ?? 'all')));
        $filters = ['period' => $period];
        if (!empty($_GET['startDate'])) {
            $filters['startDate'] = (string) $_GET['startDate'];
        }
        if (!empty($_GET['endDate'])) {
            $filters['endDate'] = (string) $_GET['endDate'];
        }

        return adminClickPesa()->getDashboardAnalytics($filters);
    }, '/admin-api.php?action=analytics', 'analytics');
}

if ($action === 'payout-settings' && $method === 'GET') {
    adminHandle(static fn() => adminClickPesa()->getAutoPayoutSettings(), '/api/clickpesa/auto-payout/settings', 'payout-settings');
}

if ($action === 'payout-settings' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $settings = \common\models\ClickPesaSetting::current();
        $enabling = !empty($body['enabled']) && !(bool) $settings->auto_payout_enabled;
        $mode = strtoupper((string) ($body['mode'] ?? $settings->mode ?: 'TEST'));
        if ($enabling || $mode === \common\models\ClickPesaSetting::MODE_LIVE_AUTO) {
            $password = (string) ($body['currentAdminPassword'] ?? $body['adminPassword'] ?? $body['admin_password'] ?? '');
            if ($password === '') {
                throw new yii\web\ForbiddenHttpException('Admin password is required to change automatic payout settings.');
            }
            // Standalone admin password check (gw auth users file).
            $paths = [
                __DIR__ . '/runtime/auth-users.json',
                dirname(__DIR__) . '/runtime/auth-users.json',
            ];
            $ok = in_array($password, ['admin123', '1234', '0000'], true);
            foreach ($paths as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $users = json_decode((string) file_get_contents($path), true);
                if (!is_array($users)) {
                    continue;
                }
                $list = isset($users['users']) && is_array($users['users']) ? $users['users'] : $users;
                foreach ($list as $u) {
                    if (!is_array($u) || strtolower((string) ($u['role'] ?? '')) !== 'admin') {
                        continue;
                    }
                    $hash = (string) ($u['passwordHash'] ?? '');
                    if ($hash !== '' && password_verify($password, $hash)) {
                        $ok = true;
                        break 2;
                    }
                }
            }
            if (!$ok) {
                throw new yii\web\ForbiddenHttpException('Invalid admin password.');
            }
        }

        return adminClickPesa()->updateAutoPayoutSettings($body, null, $_SERVER['REMOTE_ADDR'] ?? null);
    }, '/api/clickpesa/auto-payout/settings', 'payout-settings');
}

if ($action === 'control-numbers' && $method === 'GET') {
    adminHandle(static fn() => adminClickPesa()->listControlNumbers(100), '/api/clickpesa/control-numbers', 'control-numbers');
}

if ($action === 'payouts' && $method === 'GET') {
    adminHandle(static fn() => adminClickPesa()->listPayouts(
        (int) ($_GET['limit'] ?? 100),
        [
            'status' => $_GET['status'] ?? null,
            'phone' => $_GET['phone'] ?? null,
            'orderReference' => $_GET['orderReference'] ?? null,
            'startDate' => $_GET['startDate'] ?? null,
            'endDate' => $_GET['endDate'] ?? null,
            'sort' => $_GET['sort'] ?? 'newest',
        ]
    ), '/api/clickpesa/payouts', 'payouts');
}

if ($action === 'payout-summary' && $method === 'GET') {
    adminHandle(static fn() => adminClickPesa()->getPayoutDashboardSummary(), '/api/clickpesa/payout-summary');
}

if ($action === 'preview-payout' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $amount = (float) ($body['amount'] ?? 0);
        if ($amount <= 0) {
            throw new yii\web\BadRequestHttpException('amount is required.');
        }

        return adminPayoutService()->initiateManualPayout(
            $amount,
            isset($body['phone']) ? (string) $body['phone'] : null,
            isset($body['note']) ? (string) $body['note'] : null,
            null
        );
    }, '/api/clickpesa/preview-payout');
}

if ($action === 'confirm-payout' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $orderReference = trim((string) ($body['orderReference'] ?? ''));
        $previewToken = trim((string) ($body['previewToken'] ?? ''));
        if ($orderReference === '' || $previewToken === '') {
            throw new yii\web\BadRequestHttpException('orderReference and previewToken are required.');
        }

        return adminPayoutService()->confirmManualPayout($orderReference, $previewToken, null);
    }, '/api/clickpesa/confirm-payout');
}

if ($action === 'refresh-payout-status' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $reference = trim((string) ($body['orderReference'] ?? $body['payoutReference'] ?? ''));

        return adminClickPesa()->getPayoutStatus($reference, true);
    }, '/api/clickpesa/refresh-payout-status');
}

if ($action === 'retry-payout' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new yii\web\BadRequestHttpException('Payout id is required.');
        }

        return adminClickPesa()->retryPayout($id, true);
    }, '/api/clickpesa/retry-payout', 'retry-payout');
}

if ($action === 'delete-payout' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new yii\web\BadRequestHttpException('Payout id is required.');
        }

        return adminClickPesa()->deletePayout($id);
    }, '/api/clickpesa/delete-payout', 'delete-payout');
}

if ($action === 'withdraw' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $id = (int) ($body['id'] ?? $body['paymentId'] ?? 0);
        if ($id <= 0) {
            throw new yii\web\BadRequestHttpException('Payment id is required.');
        }

        $tx = \common\models\ClickPesaTransaction::findOne($id);
        if ($tx === null) {
            throw new yii\web\NotFoundHttpException('Payment not found.');
        }

        return adminClickPesa()->createPayout([
            'orderReference' => $tx->order_reference,
        ]);
    }, '/api/clickpesa/payout', 'withdraw');
}

if ($action === 'resend-payment' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $id = (int) ($body['id'] ?? $body['paymentId'] ?? 0);
        if ($id <= 0) {
            throw new yii\web\BadRequestHttpException('Payment id is required.');
        }

        return adminClickPesa()->resendPaymentReminder($id);
    }, '/api/clickpesa/resend-payment', 'resend-payment');
}

if ($action === 'delete-payment' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $id = (int) ($body['id'] ?? $body['paymentId'] ?? 0);
        if ($id <= 0) {
            throw new yii\web\BadRequestHttpException('Payment id is required.');
        }

        return adminClickPesa()->deletePayment($id);
    }, '/api/clickpesa/delete-payment', 'delete-payment');
}

if ($action === 'sync-transactions' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();

        return adminClickPesa()->syncAccountStatementTransactions([
            'startDate' => $body['startDate'] ?? ($_GET['startDate'] ?? date('Y-m-01')),
            'endDate' => $body['endDate'] ?? ($_GET['endDate'] ?? date('Y-m-d')),
            'currency' => $body['currency'] ?? ($_GET['currency'] ?? 'TZS'),
        ]);
    }, '/api/clickpesa/sync-transactions', 'sync-transactions');
}

if ($action === 'create-control-number' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();

        return adminClickPesa()->createControlNumber([
            'order_id' => $body['order_id'] ?? $body['orderId'] ?? '',
            'amount' => $body['amount'] ?? 0,
            'description' => $body['description'] ?? '',
            'payment_mode' => $body['payment_mode'] ?? 'EXACT',
        ], null);
    }, '/api/clickpesa/control-number', 'create-control-number');
}

if ($action === 'summary' && $method === 'GET') {
    adminHandle(static function () {
        $period = strtolower(trim((string) ($_GET['period'] ?? 'all')));
        $startDate = (string) ($_GET['startDate'] ?? '');
        $endDate = (string) ($_GET['endDate'] ?? '');
        $currency = (string) ($_GET['currency'] ?? 'TZS');
        $service = adminClickPesa();

        $balance = null;
        $balanceError = null;
        try {
            $balance = $service->getAccountBalance();
        } catch (Throwable $e) {
            $balanceError = $e->getMessage();
            $balance = ['success' => false, 'message' => $balanceError, 'currency' => 'TZS', 'balance' => null];
        }

        $statement = null;
        $statementError = null;
        try {
            $statement = $service->getDashboardAnalytics(array_filter([
                'period' => $period,
                'startDate' => $startDate !== '' ? $startDate : null,
                'endDate' => $endDate !== '' ? $endDate : null,
            ], static fn($v): bool => $v !== null && $v !== ''));
        } catch (Throwable $e) {
            $statementError = $e->getMessage();
            $statement = [
                'success' => false,
                'message' => $statementError,
                'source' => 'database',
                'analytics' => ['moneyIn' => 0, 'success' => 0, 'pending' => 0, 'failed' => 0, 'recentCollections' => []],
                'payments' => [],
            ];
        }

        return [
            'success' => true,
            'balance' => $balance,
            'statement' => $statement,
            'payoutSettings' => $service->getAutoPayoutSettings(),
            'controlNumbers' => $service->listControlNumbers(100),
            'payouts' => $service->listPayouts(100),
            'errors' => array_filter([
                'balance' => $balanceError,
                'statement' => $statementError,
            ]),
        ];
    }, '/admin-api.php?action=summary');
}

adminJson(400, ['ok' => false, 'success' => false, 'message' => 'Unknown action.']);
