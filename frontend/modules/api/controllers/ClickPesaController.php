<?php

declare(strict_types=1);

namespace frontend\modules\api\controllers;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use common\models\ClickPesaSetting;
use common\services\ClickPesaService;
use Mpdf\Mpdf;
use Yii;
use yii\base\InvalidConfigException;
use yii\filters\ContentNegotiator;
use yii\filters\VerbFilter;
use yii\helpers\Html;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\UnauthorizedHttpException;

/**
 * ClickPesa JSON API endpoints.
 *
 * Routes:
 * - POST /api/clickpesa/control-number
 * - GET  /api/clickpesa/control-number/<id>/invoice
 * - POST /api/clickpesa/webhook
 * - GET  /api/clickpesa/payment-status/<reference>
 * - GET  /api/clickpesa/payout-status/<reference>
 * - POST /api/clickpesa/retry-payout/<id>
 * Legacy aliases kept for wallet UI compatibility.
 */
class ClickPesaController extends Controller
{
    /** JSON API — no CSRF (session disabled in api module). */
    public $enableCsrfValidation = false;

    private ClickPesaService $clickPesaService;

    public function __construct($id, $module, ClickPesaService $clickPesaService, $config = [])
    {
        $this->clickPesaService = $clickPesaService;
        parent::__construct($id, $module, $config);
    }

    public function behaviors(): array
    {
        return [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => [
                    'application/json' => Response::FORMAT_JSON,
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'webhook' => ['GET', 'POST'],
                    'control-number' => ['POST'],
                    'control-number-invoice' => ['GET'],
                    'create-control-number' => ['POST'],
                    'payment-status' => ['GET', 'POST'],
                    'payout-status' => ['GET'],
                    'retry-payout' => ['POST'],
                    'payout' => ['POST'],
                    'payments' => ['GET'],
                    'payment-details' => ['GET'],
                    'delete' => ['POST', 'DELETE'],
                    'account-balance' => ['GET'],
                    'account-statement' => ['GET'],
                    'sync-transactions' => ['POST'],
                    'auto-payout-settings' => ['GET', 'POST'],
                    'control-numbers' => ['GET'],
                    'payouts' => ['GET'],
                ],
            ],
        ];
    }

    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if ($action->id === 'webhook') {
            $this->enableCsrfValidation = false;
        }

        return parent::beforeAction($action);
    }

    public function actionWebhook(): array
    {
        if (Yii::$app->request->isGet) {
            Yii::$app->response->statusCode = 200;

            return [
                'success' => true,
                'message' => 'ClickPesa webhook endpoint is live. ClickPesa must call this URL with POST.',
                'endpoint' => '/api/clickpesa/webhook',
                'methods' => ['GET (health)', 'POST (webhook)'],
            ];
        }

        try {
            $raw = (string) Yii::$app->request->getRawBody();
            $payload = $this->decodeJson($raw);
            $signature = Yii::$app->request->headers->get('X-ClickPesa-Signature')
                ?? Yii::$app->request->headers->get('X-Checksum');
            $token = Yii::$app->request->headers->get('X-ClickPesa-Token')
                ?? Yii::$app->request->headers->get('Authorization');

            $result = $this->clickPesaService->processWebhook($payload, $signature, $token, $raw);
            Yii::$app->response->statusCode = 200;

            return $result;
        } catch (\Throwable $e) {
            Yii::error('ClickPesa webhook error: ' . $e->getMessage(), 'clickpesa');

            if ($e instanceof UnauthorizedHttpException) {
                Yii::$app->response->statusCode = 401;
            } elseif ($e instanceof BadRequestHttpException) {
                Yii::$app->response->statusCode = 400;
            } else {
                Yii::$app->response->statusCode = 500;
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * POST /api/clickpesa/control-number
     */
    public function actionControlNumber(): array
    {
        return $this->handle(function () {
            $this->requireInternalAuth();
            $userId = Yii::$app->user->isGuest ? null : (int) Yii::$app->user->id;

            return $this->clickPesaService->createControlNumber($this->getJsonBody(), $userId);
        });
    }

    public function actionControlNumberInvoice(int $id): Response
    {
        $this->requireDashboardOrAdminAuth();
        $invoice = $this->clickPesaService->getInvoiceData($id);
        $html = $this->buildInvoiceHtml($invoice);
        $tempDir = Yii::getAlias('@runtime/mpdf');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $pdf = new Mpdf([
            'tempDir' => $tempDir,
            'format' => 'A4',
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);
        $pdf->WriteHTML($html);

        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/pdf');
        $disposition = Yii::$app->request->get('download') ? 'attachment' : 'inline';
        $response->headers->set(
            'Content-Disposition',
            $disposition . '; filename="invoice-' . preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $invoice['invoiceNumber']) . '.pdf"'
        );
        $response->content = $pdf->Output('', 'S');

        return $response;
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private function buildInvoiceHtml(array $invoice): string
    {
        $businessName = (string) (Yii::$app->params['businessName'] ?? Yii::$app->name ?: 'Getway');
        $businessAddress = (string) (Yii::$app->params['businessAddress'] ?? 'Dar es Salaam, Tanzania');
        $businessEmail = (string) (Yii::$app->params['businessEmail'] ?? 'support@getway.app');
        $businessPhone = (string) (Yii::$app->params['businessPhone'] ?? '');
        $logoPath = Yii::getAlias('@frontend/web/images/favicon.png');
        $logoHtml = is_file($logoPath)
            ? '<img src="' . str_replace('\\', '/', $logoPath) . '" style="height:52px" alt="Logo">'
            : '<div style="font-size:16px;font-weight:bold;color:#4b5563">' . Html::encode($businessName) . '</div>';

        $qrHtml = '';
        $qrPayload = trim((string) ($invoice['controlNumber'] ?? ''));
        if ($qrPayload !== '') {
            $qrDataUri = $this->buildInvoiceQrDataUri($qrPayload);
            if ($qrDataUri !== '') {
                $qrHtml = '<img src="' . $qrDataUri . '" style="width:110px;height:110px" alt="QR Code">';
            }
        }

        $amount = number_format((float) ($invoice['amount'] ?? 0), 2);
        $currency = Html::encode((string) ($invoice['currency'] ?? 'TZS'));
        $customerName = Html::encode((string) ($invoice['customerName'] ?? 'Customer'));
        $customerPhone = Html::encode((string) ($invoice['customerPhone'] ?? '—'));
        $orderId = Html::encode((string) ($invoice['orderId'] ?? '—'));
        $invoiceNumber = Html::encode((string) ($invoice['invoiceNumber'] ?? ''));
        $createdAt = Html::encode((string) ($invoice['createdAt'] ?? date('Y-m-d H:i:s')));
        $controlNumber = Html::encode((string) ($invoice['controlNumber'] ?? '—'));
        $billReference = Html::encode((string) ($invoice['billReference'] ?? '—'));
        $description = Html::encode((string) ($invoice['description'] ?? 'Payment'));
        $paymentMode = Html::encode((string) ($invoice['paymentMode'] ?? 'EXACT'));
        $status = Html::encode((string) ($invoice['status'] ?? 'PENDING'));

        return '
            <style>
                body { font-family: DejaVu Sans, sans-serif; color: #333; font-size: 11px; }
                .inv-title { font-size: 34px; color: #8ec5e8; font-weight: bold; margin: 0 0 8px 0; letter-spacing: 1px; }
                .inv-muted { color: #666; line-height: 1.5; }
                .inv-box-title { background: #f3f4f6; padding: 8px 10px; font-weight: bold; border: 1px solid #e5e7eb; }
                .inv-box-body { padding: 10px; border: 1px solid #e5e7eb; border-top: 0; min-height: 72px; }
                .inv-table { width: 100%; border-collapse: collapse; margin-top: 18px; }
                .inv-table th { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 8px; text-align: left; font-size: 10px; }
                .inv-table td { border: 1px solid #e5e7eb; padding: 8px; vertical-align: top; }
                .inv-total-label { text-align: right; padding: 6px 10px; }
                .inv-total-value { text-align: right; padding: 6px 10px; font-weight: bold; }
                .inv-grand { font-size: 14px; color: #111; }
                .inv-footer { margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 12px; color: #666; }
            </style>
            <table width="100%" style="margin-bottom:16px">
                <tr>
                    <td width="58%" valign="top">
                        <div class="inv-title">INVOICE</div>
                        <div class="inv-muted">
                            <strong>' . Html::encode($businessName) . '</strong><br>
                            ' . Html::encode($businessAddress) . '<br>
                            ' . ($businessEmail !== '' ? Html::encode($businessEmail) . '<br>' : '') . '
                            ' . ($businessPhone !== '' ? Html::encode($businessPhone) : '') . '
                        </div>
                    </td>
                    <td width="42%" valign="top" style="text-align:right">
                        ' . $logoHtml . '
                        <div style="margin-top:10px;text-align:right">' . $qrHtml . '</div>
                    </td>
                </tr>
            </table>
            <table width="100%" style="margin-bottom:8px">
                <tr>
                    <td width="33%" valign="top">
                        <div class="inv-box-title">Billing Address:</div>
                        <div class="inv-box-body">
                            ' . $customerName . '<br>
                            Phone: ' . $customerPhone . '
                        </div>
                    </td>
                    <td width="33%" valign="top">
                        <div class="inv-box-title">Payment Details:</div>
                        <div class="inv-box-body">
                            Control No: <strong>' . $controlNumber . '</strong><br>
                            Reference: ' . $billReference . '<br>
                            Status: ' . $status . '
                        </div>
                    </td>
                    <td width="34%" valign="top">
                        <table width="100%" style="font-size:10px">
                            <tr><td><strong>Invoice Date:</strong></td><td style="text-align:right">' . $createdAt . '</td></tr>
                            <tr><td><strong>Invoice No.:</strong></td><td style="text-align:right">' . $invoiceNumber . '</td></tr>
                            <tr><td><strong>Order No.:</strong></td><td style="text-align:right">' . $orderId . '</td></tr>
                            <tr><td><strong>Payment Mode:</strong></td><td style="text-align:right">' . $paymentMode . '</td></tr>
                        </table>
                    </td>
                </tr>
            </table>
            <table class="inv-table">
                <thead>
                    <tr>
                        <th width="10%">#</th>
                        <th width="40%">Product / Description</th>
                        <th width="10%">Qty</th>
                        <th width="15%">Price</th>
                        <th width="15%">Total</th>
                        <th width="10%">Tax</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>
                            <strong>' . $description . '</strong><br>
                            <span class="inv-muted">ClickPesa BillPay control number payment</span>
                        </td>
                        <td>1</td>
                        <td>' . $currency . ' ' . $amount . '</td>
                        <td>' . $currency . ' ' . $amount . '</td>
                        <td>' . $currency . ' 0.00</td>
                    </tr>
                </tbody>
            </table>
            <table width="100%" style="margin-top:12px">
                <tr>
                    <td width="60%"></td>
                    <td width="40%">
                        <table width="100%">
                            <tr><td class="inv-total-label">Subtotal:</td><td class="inv-total-value">' . $currency . ' ' . $amount . '</td></tr>
                            <tr><td class="inv-total-label">Tax:</td><td class="inv-total-value">' . $currency . ' 0.00</td></tr>
                            <tr><td class="inv-total-label inv-grand">Total:</td><td class="inv-total-value inv-grand">' . $currency . ' ' . $amount . '</td></tr>
                        </table>
                    </td>
                </tr>
            </table>
            <div class="inv-footer">
                <strong>Payment method:</strong> ClickPesa BillPay<br>
                Scan the QR code or use control number <strong>' . $controlNumber . '</strong> at any supported channel.<br>
                Thank you for your business.
            </div>
        ';
    }

    private function buildInvoiceQrDataUri(string $payload): string
    {
        try {
            $options = new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'outputBase64' => true,
                'scale' => 8,
                'margin' => 1,
            ]);

            return (string) (new QRCode($options))->render($payload);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @deprecated Use actionControlNumber */
    public function actionCreateControlNumber(): array
    {
        return $this->actionControlNumber();
    }

    public function actionPaymentStatus(?string $reference = null): array
    {
        return $this->handle(function () use ($reference) {
            if ($reference === null || $reference === '') {
                $body = Yii::$app->request->isPost ? $this->getJsonBody() : [];
                $reference = (string) ($body['orderReference'] ?? $body['order_reference'] ?? Yii::$app->request->get('reference', ''));
            }
            $refresh = Yii::$app->request->get('refresh', '1') !== '0';

            return $this->clickPesaService->getPaymentStatus($reference, $refresh);
        });
    }

    public function actionPayoutStatus(?string $reference = null): array
    {
        return $this->handle(function () use ($reference) {
            $this->requireInternalAuth();
            if ($reference === null || $reference === '') {
                $reference = (string) Yii::$app->request->get('reference', '');
            }

            return $this->clickPesaService->getPayoutStatus($reference, true);
        });
    }

    public function actionRetryPayout(?int $id = null): array
    {
        return $this->handle(function () use ($id) {
            $this->requireAdminAuth();
            if ($id === null) {
                $body = $this->getJsonBody();
                $id = (int) ($body['id'] ?? 0);
            }

            return $this->clickPesaService->retryPayout($id, true);
        });
    }

    public function actionPayout(): array
    {
        return $this->handle(function () {
            $this->requireAdminAuth();

            return $this->clickPesaService->createPayout($this->getJsonBody());
        });
    }

    public function actionPayments(): array
    {
        return $this->handle(fn() => $this->clickPesaService->listWalletPayments());
    }

    public function actionPaymentDetails(): array
    {
        return $this->handle(function () {
            $type = (string) Yii::$app->request->get('type', 'success');

            return $this->clickPesaService->listWalletPaymentDetails($type);
        });
    }

    public function actionDelete(): array
    {
        return $this->handle(function () {
            $body = $this->getJsonBody();
            $orderReference = (string) ($body['orderReference'] ?? $body['order_reference'] ?? '');

            return $this->clickPesaService->deleteWalletPayment($orderReference);
        });
    }

    public function actionAccountBalance(): array
    {
        return $this->handle(function () {
            $this->requireDashboardOrAdminAuth();
            return $this->clickPesaService->getAccountBalance();
        });
    }

    public function actionAccountStatement(): array
    {
        return $this->handle(function () {
            $this->requireDashboardOrAdminAuth();
            return $this->clickPesaService->getAccountStatement([
                'startDate' => Yii::$app->request->get('startDate'),
                'endDate' => Yii::$app->request->get('endDate'),
                'currency' => Yii::$app->request->get('currency'),
            ]);
        });
    }

    public function actionSyncTransactions(): array
    {
        return $this->handle(function () {
            $this->requireDashboardOrAdminAuth();
            return $this->clickPesaService->syncAccountStatementTransactions([
                'startDate' => Yii::$app->request->getBodyParam('startDate', Yii::$app->request->get('startDate')),
                'endDate' => Yii::$app->request->getBodyParam('endDate', Yii::$app->request->get('endDate')),
                'currency' => Yii::$app->request->getBodyParam('currency', Yii::$app->request->get('currency')),
            ]);
        });
    }

    public function actionAutoPayoutSettings(): array
    {
        return $this->handle(function () {
            $this->requireDashboardOrAdminAuth();
            if (Yii::$app->request->isGet) {
                return $this->clickPesaService->getAutoPayoutSettings();
            }

            $this->requireSuperAdminPermission();
            $this->confirmSensitiveEnableRequest();

            return $this->clickPesaService->updateAutoPayoutSettings(
                $this->getJsonBody(),
                Yii::$app->user->isGuest ? null : (int) Yii::$app->user->id,
                Yii::$app->request->userIP
            );
        });
    }

    public function actionControlNumbers(): array
    {
        return $this->handle(function () {
            $this->requireDashboardOrAdminAuth();
            return $this->clickPesaService->listControlNumbers((int) Yii::$app->request->get('limit', 100));
        });
    }

    public function actionPayouts(): array
    {
        return $this->handle(function () {
            $this->requireDashboardOrAdminAuth();
            return $this->clickPesaService->listPayouts((int) Yii::$app->request->get('limit', 100));
        });
    }

    /**
     * @param callable(): array $callback
     */
    private function handle(callable $callback): array
    {
        try {
            $result = $callback();
            Yii::$app->response->statusCode = 200;

            return $result;
        } catch (BadRequestHttpException $e) {
            Yii::$app->response->statusCode = 400;
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (UnauthorizedHttpException $e) {
            Yii::$app->response->statusCode = 401;
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (ForbiddenHttpException $e) {
            Yii::$app->response->statusCode = 403;
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\yii\web\NotFoundHttpException $e) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\yii\web\ConflictHttpException $e) {
            Yii::$app->response->statusCode = 409;
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\yii\web\TooManyRequestsHttpException $e) {
            Yii::$app->response->statusCode = 429;
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (InvalidConfigException $e) {
            Yii::$app->response->statusCode = 500;
            Yii::error($e->getMessage(), 'clickpesa');
            return ['success' => false, 'message' => 'ClickPesa is not configured.'];
        } catch (\yii\db\Exception $e) {
            Yii::$app->response->statusCode = 503;
            Yii::error($e->getMessage(), 'clickpesa');
            return [
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'causeFile' => 'common/config/main-local.php',
            ];
        } catch (\Throwable $e) {
            Yii::$app->response->statusCode = 500;
            Yii::error($e->getMessage(), 'clickpesa');
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Authenticated Yii user OR internal API token (never expose in JS — server-side only).
     */
    private function requireInternalAuth(): void
    {
        if (!Yii::$app->user->isGuest) {
            return;
        }

        $expected = (string) (Yii::$app->params['clickpesa']['internalApiToken']
            ?? getenv('CLICKPESA_INTERNAL_API_TOKEN')
            ?: '');
        if ($expected === '') {
            // Dev convenience: allow when no token configured (wallet local use).
            // Production must set CLICKPESA_INTERNAL_API_TOKEN.
            if (YII_ENV_PROD) {
                throw new UnauthorizedHttpException('Authentication required.');
            }
            return;
        }

        $auth = (string) (Yii::$app->request->headers->get('Authorization') ?? '');
        $token = stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : $auth;
        $headerToken = (string) (Yii::$app->request->headers->get('X-Internal-Token') ?? '');

        if (!hash_equals($expected, $token) && !hash_equals($expected, $headerToken)) {
            throw new UnauthorizedHttpException('Authentication required.');
        }
    }

    private function requireAdminAuth(): void
    {
        if (Yii::$app->user->isGuest) {
            throw new UnauthorizedHttpException('Admin authentication required.');
        }
    }

    private function requireDashboardOrAdminAuth(): void
    {
        if (!Yii::$app->user->isGuest) {
            return;
        }

        $sessionUser = $this->readStandaloneAuthUser();
        if (is_array($sessionUser) && in_array(strtolower((string) ($sessionUser['role'] ?? '')), ['admin', 'super-admin', 'super_admin'], true)) {
            return;
        }

        $this->requireInternalAuth();
    }

    private function requireSuperAdminPermission(): void
    {
        if (!Yii::$app->user->isGuest && Yii::$app->user->can('manageClickpesaPayoutSettings')) {
            return;
        }

        $sessionUser = $this->readStandaloneAuthUser();
        $role = strtolower((string) ($sessionUser['role'] ?? ''));
        if (in_array($role, ['admin', 'super-admin', 'super_admin'], true)) {
            return;
        }

        throw new ForbiddenHttpException('Only a super admin may change automatic payout settings.');
    }

    private function confirmSensitiveEnableRequest(): void
    {
        $body = $this->getJsonBody();
        $settings = ClickPesaSetting::current();
        $enablingLive = !empty($body['enabled']) && strtoupper((string) ($body['mode'] ?? $settings->mode)) === ClickPesaSetting::MODE_LIVE_AUTO;
        if (!$enablingLive && !empty($body['enabled']) && !(bool) $settings->auto_payout_enabled) {
            $enablingLive = true;
        }
        if (!$enablingLive) {
            return;
        }

        $password = (string) ($body['currentAdminPassword'] ?? $body['adminPassword'] ?? '');
        $otp = (string) ($body['otp'] ?? '');
        $reauth = (bool) ($body['reauthenticated'] ?? false);
        if ($password === '' && $otp === '' && !$reauth) {
            throw new ForbiddenHttpException('Password confirmation, OTP or re-authentication is required before enabling LIVE automatic payout.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStandaloneAuthUser(): ?array
    {
        $authInit = Yii::getAlias('@frontend/web/auth-init.php');
        if (!is_file($authInit)) {
            return null;
        }

        require_once $authInit;
        if (function_exists('gwAuthStartSession')) {
            gwAuthStartSession();
        }

        $user = $_SESSION['gw_auth_user'] ?? null;
        return is_array($user) ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonBody(): array
    {
        $raw = Yii::$app->request->getRawBody();
        if ($raw !== null && $raw !== '') {
            return $this->decodeJson($raw);
        }

        $bodyParams = Yii::$app->request->getBodyParams();
        if (is_array($bodyParams) && $bodyParams !== []) {
            return $bodyParams;
        }

        throw new BadRequestHttpException('Invalid or empty JSON body.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new BadRequestHttpException('Invalid or empty JSON body.');
        }

        return $decoded;
    }
}
