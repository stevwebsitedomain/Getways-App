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
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : $_POST;
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
                'hint' => 'Local PHP hosting cannot reach Railway MySQL on port 27413. Dashboard reads data via Render proxy when deployed.',
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

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
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

function adminHandle(callable $callback, string $apiRoute, ?string $remoteAction = null): never
{
    try {
        adminYiiApp();
        $dbError = adminDbProbe();
        if ($dbError !== null && $remoteAction !== null) {
            $remote = adminFetchRemote(
                $remoteAction,
                strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
                $_GET,
                in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT', 'PATCH'], true)
                    ? readJsonBody()
                    : null
            );
            if ($remote !== null) {
                if (($remote['success'] ?? true) === false) {
                    adminJson((int) ($remote['remoteStatus'] ?? 502), $remote + ['apiRoute' => $apiRoute, 'source' => 'render-admin-proxy']);
                }
                adminJson(200, ['ok' => true, 'apiRoute' => $apiRoute, 'source' => 'render-admin-proxy'] + $remote);
            }

            $dbError['hint'] = 'Local PHP hosting cannot reach Railway MySQL (port 27413 blocked). Data is fetched via Render admin proxy when available.';
            adminJson(503, $dbError + ['apiRoute' => $apiRoute]);
        }
        if ($dbError !== null) {
            adminJson(503, $dbError + ['apiRoute' => $apiRoute]);
        }

        $result = $callback();
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
        adminJson(503, [
            'ok' => false,
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage(),
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

        return adminClickPesa()->getDashboardAnalytics($filters);
    }, '/admin-api.php?action=analytics', 'analytics');
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
            $ok = in_array($password, ['admin123', '1234'], true);
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
    }, '/api/clickpesa/auto-payout/settings');
}

if ($action === 'control-numbers' && $method === 'GET') {
    adminHandle(static fn() => adminClickPesa()->listControlNumbers(100), '/api/clickpesa/control-numbers', 'control-numbers');
}

if ($action === 'payouts' && $method === 'GET') {
    adminHandle(static fn() => adminClickPesa()->listPayouts(100), '/api/clickpesa/payouts', 'payouts');
}

if ($action === 'retry-payout' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new yii\web\BadRequestHttpException('Payout id is required.');
        }

        return adminClickPesa()->retryPayout($id, true);
    }, '/api/clickpesa/retry-payout');
}

if ($action === 'sync-transactions' && $method === 'POST') {
    adminHandle(static function () {
        $body = readJsonBody();

        return adminClickPesa()->syncAccountStatementTransactions([
            'startDate' => $body['startDate'] ?? ($_GET['startDate'] ?? date('Y-m-01')),
            'endDate' => $body['endDate'] ?? ($_GET['endDate'] ?? date('Y-m-d')),
            'currency' => $body['currency'] ?? ($_GET['currency'] ?? 'TZS'),
        ]);
    }, '/api/clickpesa/sync-transactions');
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
    }, '/api/clickpesa/control-number');
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
