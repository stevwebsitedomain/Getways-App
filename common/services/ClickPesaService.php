<?php

declare(strict_types=1);

namespace common\services;

use common\models\ClickPesaPayout;
use common\models\ClickPesaSetting;
use common\models\ClickPesaSettingAudit;
use common\models\ClickPesaSyncLog;
use common\models\ClickPesaTransaction;
use common\models\ClickPesaWebhookEvent;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\db\Exception as DbException;
use yii\httpclient\Client; // lowercase namespace — NOT Yii\HttpClient\Client
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\TooManyRequestsHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * ClickPesa API integration: token, BillPay control numbers, webhooks, safe auto-payout.
 */
class ClickPesaService extends Component
{
    private const LOG_CATEGORY = 'clickpesa';
    private const TOKEN_CACHE_KEY = 'clickpesa.access_token.v1';
    private const RATE_LIMIT_KEY = 'clickpesa.payout.rate.';

    /**
     * @return array{
     *   baseUrl:string,clientId:string,apiKey:string,checksumKey:string,
     *   webhookToken:string,currency:string,autoPayoutEnabled:bool,
     *   autoPayoutPhone:string,autoPayoutPercentage:float,autoPayoutMinimum:float,
     *   autoPayoutDelay:int
     * }
     */
    public function getConfig(): array
    {
        $params = Yii::$app->params['clickpesa'] ?? [];
        $base = (string) ($params['baseUrl'] ?? getenv('CLICKPESA_API_BASE_URL') ?: 'https://api.clickpesa.com/third-parties');
        $base = rtrim($base, '/');
        if (!str_ends_with($base, 'third-parties') && !str_contains($base, '/third-parties')) {
            $base = rtrim($base, '/') . '/third-parties';
        }

        return [
            'baseUrl' => $base . '/',
            'clientId' => (string) ($params['clientId'] ?? getenv('CLICKPESA_CLIENT_ID') ?: ''),
            'apiKey' => (string) ($params['apiKey'] ?? getenv('CLICKPESA_API_KEY') ?: ''),
            'checksumKey' => (string) ($params['checksumKey'] ?? getenv('CLICKPESA_CHECKSUM_KEY') ?: ''),
            'webhookToken' => (string) ($params['webhookToken'] ?? getenv('CLICKPESA_WEBHOOK_TOKEN') ?: ''),
            'currency' => (string) ($params['currency'] ?? 'TZS'),
            'autoPayoutEnabled' => filter_var(
                $params['autoPayoutEnabled'] ?? getenv('CLICKPESA_AUTO_PAYOUT_ENABLED') ?: false,
                FILTER_VALIDATE_BOOLEAN
            ),
            'autoPayoutPhone' => (string) ($params['autoPayoutPhone'] ?? getenv('CLICKPESA_AUTO_PAYOUT_PHONE') ?: ClickPesaSetting::DEFAULT_PHONE),
            'autoPayoutPercentage' => (float) ($params['autoPayoutPercentage'] ?? getenv('CLICKPESA_AUTO_PAYOUT_PERCENTAGE') ?: 100),
            'autoPayoutMinimum' => (float) ($params['autoPayoutMinimum'] ?? getenv('CLICKPESA_AUTO_PAYOUT_MINIMUM_AMOUNT') ?: 1000),
            'autoPayoutDelay' => (int) ($params['autoPayoutDelay'] ?? getenv('CLICKPESA_AUTO_PAYOUT_DELAY_SECONDS') ?: 60),
        ];
    }

    /**
     * Generate / cache ClickPesa bearer token (~1 hour).
     */
    public function generateToken(bool $forceRefresh = false): string
    {
        if (!$forceRefresh) {
            $cached = Yii::$app->cache->get(self::TOKEN_CACHE_KEY);
            if (is_array($cached) && !empty($cached['token']) && (int) ($cached['expiresAt'] ?? 0) > time() + 60) {
                return (string) $cached['token'];
            }
        }

        $config = $this->getConfig();
        if ($config['clientId'] === '' || $config['apiKey'] === '') {
            throw new InvalidConfigException('ClickPesa clientId and apiKey must be configured.');
        }

        if (!class_exists(Client::class, true)) {
            throw new ServerErrorHttpException(
                'Class yii\\httpclient\\Client not found. Run: composer require yiisoft/yii2-httpclient && composer dump-autoload'
            );
        }

        $client = new Client([
            'baseUrl' => $config['baseUrl'],
            'requestConfig' => ['format' => Client::FORMAT_JSON],
            'responseConfig' => ['format' => Client::FORMAT_JSON],
        ]);

        $response = $client->createRequest()
            ->setMethod('POST')
            ->setUrl('generate-token')
            ->setHeaders([
                'client-id' => $config['clientId'],
                'api-key' => $config['apiKey'],
                'Content-Type' => 'application/json',
            ])
            ->setContent('{}')
            ->send();

        if (!$response->isOk) {
            $this->log('error', 'Token generation failed', ['status' => $response->statusCode]);
            throw new UnauthorizedHttpException('Failed to generate ClickPesa access token.');
        }

        $data = is_array($response->data) ? $response->data : [];
        $token = $this->extractValue($data, ['token', 'accessToken', 'access_token', 'data.token', 'data.accessToken']);
        if (!$token) {
            throw new ServerErrorHttpException('ClickPesa token missing in response.');
        }

        $token = preg_replace('/^Bearer\s+/i', '', (string) $token) ?: (string) $token;
        $expiresIn = (int) ($this->extractValue($data, ['expiresIn', 'expires_in', 'data.expiresIn']) ?: 3600);
        // Cache slightly less than one hour
        $ttl = max(60, min($expiresIn - 120, 3300));

        Yii::$app->cache->set(self::TOKEN_CACHE_KEY, [
            'token' => $token,
            'expiresAt' => time() + $ttl,
        ], $ttl);

        $this->log('info', 'ClickPesa access token refreshed');

        return $token;
    }

    public function getAccountBalance(): array
    {
        $response = $this->request('GET', 'account/balance');
        $balance = $this->normalizeAmount($this->extractValue($response, [
            'balance',
            'availableBalance',
            'accountBalance',
            'data.balance',
            'data.availableBalance',
        ]));
        $currency = (string) ($this->extractValue($response, [
            'currency',
            'data.currency',
            'account.currency',
        ]) ?: $this->getConfig()['currency']);

        return [
            'success' => true,
            'currency' => strtoupper($currency),
            'balance' => $balance,
            'lastUpdated' => date('c'),
        ];
    }

    public function getAccountStatement(array $filters = []): array
    {
        $query = array_filter([
            'startDate' => $this->normalizeDateParam($filters['startDate'] ?? null),
            'endDate' => $this->normalizeDateParam($filters['endDate'] ?? null),
            'currency' => strtoupper((string) ($filters['currency'] ?? $this->getConfig()['currency'])),
        ], static fn($value): bool => $value !== null && $value !== '');

        $path = 'account/statement';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        $response = $this->request('GET', $path);
        $normalized = $this->normalizeStatementResponse($response, $query['currency'] ?? $this->getConfig()['currency']);
        $matched = $this->buildStatementAnalytics($normalized['transactions']);

        return array_merge($normalized, [
            'analytics' => $matched,
            'filters' => $query,
        ]);
    }

    public function syncAccountStatementTransactions(array $filters = []): array
    {
        $statement = $this->getAccountStatement($filters);
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($statement['transactions'] as $row) {
            $result = $this->upsertStatementTransaction($row);
            if ($result === 'inserted') {
                $inserted++;
            } elseif ($result === 'updated') {
                $updated++;
            } else {
                $skipped++;
            }
        }

        $settings = ClickPesaSetting::current();
        $settings->last_synced_at = time();
        $settings->save(false, ['last_synced_at', 'updated_at']);

        $this->recordSyncLog(
            'account_statement',
            'SUCCESS',
            $inserted,
            $updated,
            $skipped,
            'ClickPesa account statement synchronized.',
            [
                'filters' => $statement['filters'] ?? [],
                'transactionCount' => count($statement['transactions']),
            ]
        );

        return [
            'success' => true,
            'message' => 'ClickPesa transactions synchronized successfully.',
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'lastSyncedAt' => date('c', (int) $settings->last_synced_at),
            'transactions' => $statement['transactions'],
            'analytics' => $statement['analytics'],
        ];
    }

    public function listControlNumbers(int $limit = 100): array
    {
        $this->processPendingPayouts(10);
        $settings = ClickPesaSetting::current();
        $models = ClickPesaTransaction::find()
            ->where(['or', ['channel' => 'billpay'], ['not', ['control_number' => null]]])
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $items = [];
        foreach ($models as $tx) {
            $payout = ClickPesaPayout::findOne(['payment_id' => $tx->id]);
            $withdraw = $this->resolveWithdrawInfo($tx, $payout, $settings);
            $items[] = [
                'id' => $tx->id,
                'orderId' => $tx->order_id,
                'customerName' => $tx->customer_name,
                'controlNumber' => $tx->control_number,
                'hasControlNumber' => trim((string) ($tx->control_number ?? '')) !== '',
                'reference' => $tx->order_reference,
                'amount' => (float) ($tx->expected_amount ?: $tx->amount),
                'receivedAmount' => $tx->received_amount !== null ? (float) $tx->received_amount : null,
                'status' => $tx->payment_status,
                'description' => $tx->description,
                'createdAt' => $tx->created_at ? date('c', (int) $tx->created_at) : null,
                'withdrawStatus' => $withdraw['withdrawStatus'],
                'canWithdraw' => $withdraw['canWithdraw'],
                'canResend' => strtoupper((string) $tx->payment_status) === ClickPesaTransaction::STATUS_PENDING,
                'invoiceUrl' => 'admin-invoice.php?id=' . $tx->id,
            ];
        }

        return [
            'success' => true,
            'payoutSettings' => [
                'enabled' => (bool) $settings->auto_payout_enabled,
                'mode' => $settings->mode ?: ClickPesaSetting::MODE_TEST,
                'manualApprovalRequired' => (bool) $settings->require_manual_approval,
            ],
            'items' => $items,
        ];
    }

    /**
     * Refresh a pending payment from ClickPesa and re-share control number details.
     *
     * @return array<string, mixed>
     */
    public function resendPaymentReminder(int $paymentId): array
    {
        $tx = ClickPesaTransaction::findOne($paymentId);
        if ($tx === null) {
            throw new NotFoundHttpException('Payment not found.');
        }
        if (strtoupper((string) $tx->payment_status) !== ClickPesaTransaction::STATUS_PENDING) {
            throw new BadRequestHttpException('Only pending payments can be resent.');
        }

        $wasPaid = $tx->isPaymentSuccessful();
        $this->getPaymentStatus((string) $tx->order_reference, true);
        $tx->refresh();

        if (!$wasPaid && $tx->isPaymentSuccessful()) {
            $received = $this->extractValue(
                json_decode((string) ($tx->raw_payload ?: '{}'), true) ?: [],
                ['receivedAmount', 'collectedAmount', 'amount', 'data.amount']
            );
            if ($received !== null && (float) $received > 0) {
                $tx->received_amount = (float) $received;
            } elseif ($tx->received_amount === null) {
                $tx->received_amount = (float) ($tx->expected_amount ?: $tx->amount);
            }
            $tx->paid_at = time();
            $tx->save(false);

            if ($this->maybeQueueAutomaticPayout($tx)) {
                $this->processQueuedPayoutForPayment($tx);
            }
        }

        return [
            'success' => true,
            'message' => 'Payment status refreshed. Share the control number with the customer again if needed.',
            'paymentStatus' => $tx->payment_status,
            'controlNumber' => $tx->control_number,
            'orderReference' => $tx->order_reference,
        ];
    }

    public function listPayouts(int $limit = 100): array
    {
        $models = ClickPesaPayout::find()
            ->orderBy(['id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return [
            'success' => true,
            'items' => array_map(static fn(ClickPesaPayout $payout): array => [
                'id' => $payout->id,
                'payoutReference' => $payout->payout_reference,
                'destinationMasked' => $payout->destination_masked,
                'amount' => (float) $payout->amount,
                'fee' => $payout->fee !== null ? (float) $payout->fee : null,
                'status' => $payout->payout_status,
                'provider' => $payout->provider,
                'lastError' => $payout->last_error,
                'createdAt' => $payout->created_at ? date('c', (int) $payout->created_at) : null,
                'updatedAt' => $payout->updated_at ? date('c', (int) $payout->updated_at) : null,
                'retryable' => $payout->canRetry(),
            ], $models),
        ];
    }

    public function getAutoPayoutSettings(): array
    {
        $settings = ClickPesaSetting::current();

        return [
            'success' => true,
            'enabled' => (bool) $settings->auto_payout_enabled,
            'mode' => $settings->mode ?: ClickPesaSetting::MODE_TEST,
            'destinationType' => $settings->destination_type,
            'maskedDestination' => $settings->getMaskedDestination(),
            'mobileProvider' => $settings->mobile_provider,
            'minimumAmount' => (float) $settings->minimum_amount,
            'dailyLimit' => (float) $settings->daily_limit,
            'payoutPercentage' => (float) $settings->payout_percentage,
            'delaySeconds' => (int) $settings->delay_seconds,
            'manualApprovalRequired' => (bool) $settings->require_manual_approval,
            'lastSyncedAt' => $settings->last_synced_at ? date('c', (int) $settings->last_synced_at) : null,
            'warning' => $settings->mode === ClickPesaSetting::MODE_TEST
                ? 'TEST MODE — auto payout is off. Turn Auto payout ON and enter admin password to activate.'
                : (($settings->auto_payout_enabled && $settings->mode === ClickPesaSetting::MODE_MANUAL_APPROVAL)
                    ? 'Manual approval mode — payouts need approval before sending.'
                    : null),
        ];
    }

    public function updateAutoPayoutSettings(array $data, ?int $actorId = null, ?string $actorIp = null): array
    {
        $settings = ClickPesaSetting::current();
        $before = $this->getAutoPayoutSettings();
        $mode = strtoupper(trim((string) ($data['mode'] ?? $settings->mode ?: ClickPesaSetting::MODE_TEST)));
        $enabled = array_key_exists('enabled', $data)
            ? (bool) $data['enabled']
            : (bool) $settings->auto_payout_enabled;

        if (!in_array($mode, [
            ClickPesaSetting::MODE_TEST,
            ClickPesaSetting::MODE_MANUAL_APPROVAL,
            ClickPesaSetting::MODE_LIVE_AUTO,
        ], true)) {
            throw new BadRequestHttpException('mode must be TEST, MANUAL_APPROVAL or LIVE_AUTO.');
        }

        $settings->destination_type = strtoupper((string) ($data['destinationType'] ?? $settings->destination_type ?: ClickPesaSetting::DESTINATION_MOBILE));
        if (!in_array($settings->destination_type, [ClickPesaSetting::DESTINATION_MOBILE, ClickPesaSetting::DESTINATION_BANK], true)) {
            throw new BadRequestHttpException('destinationType must be MOBILE_MONEY or BANK.');
        }

        $settings->mode = $mode;
        $settings->auto_payout_enabled = $enabled ? 1 : 0;
        $settings->mobile_provider = trim((string) ($data['mobileProvider'] ?? $settings->mobile_provider));
        $settings->payout_percentage = (float) ($data['payoutPercentage'] ?? $settings->payout_percentage);
        $settings->minimum_amount = (float) ($data['minimumAmount'] ?? $settings->minimum_amount);
        $settings->daily_limit = (float) ($data['dailyLimit'] ?? $settings->daily_limit);
        $settings->delay_seconds = (int) ($data['delaySeconds'] ?? $settings->delay_seconds);
        $settings->require_manual_approval = array_key_exists('manualApprovalRequired', $data)
            ? ((bool) $data['manualApprovalRequired'] ? 1 : 0)
            : (int) $settings->require_manual_approval;

        if (!empty($data['mobileMoneyNumber'])) {
            $settings->setDestinationPhone((string) $data['mobileMoneyNumber']);
        } elseif ($enabled && !$settings->getDestinationPhone()) {
            $settings->setDestinationPhone(ClickPesaSetting::DEFAULT_PHONE);
        }

        $settings->bank_name = trim((string) ($data['bankName'] ?? $settings->bank_name));
        $settings->bank_account_name = trim((string) ($data['bankAccountName'] ?? $settings->bank_account_name));
        $settings->bank_bic_swift = trim((string) ($data['bankBicSwift'] ?? $settings->bank_bic_swift));
        if (!empty($data['bankAccountNumber'])) {
            $settings->bank_account_number_enc = ClickPesaSetting::encryptValue((string) $data['bankAccountNumber']);
        }

        if ($settings->destination_type === ClickPesaSetting::DESTINATION_MOBILE && !$settings->getDestinationPhone()) {
            throw new BadRequestHttpException('Configure a valid payout destination before enabling automatic payout.');
        }

        if ($enabled && $mode === ClickPesaSetting::MODE_LIVE_AUTO && !$settings->getDestinationPhone() && $settings->destination_type === ClickPesaSetting::DESTINATION_MOBILE) {
            throw new BadRequestHttpException('Configure a valid payout destination before enabling automatic payout.');
        }

        if ($mode === ClickPesaSetting::MODE_TEST) {
            $settings->auto_payout_enabled = 0;
        } elseif ($enabled) {
            $settings->auto_payout_enabled = 1;
        }
        if ($mode === ClickPesaSetting::MODE_MANUAL_APPROVAL) {
            $settings->require_manual_approval = 1;
        }

        if (!$settings->save()) {
            throw new ServerErrorHttpException('Failed to save payout settings.');
        }

        ClickPesaSettingAudit::log('settings_updated', [
            'before' => $before,
            'after' => $this->getAutoPayoutSettings(),
        ], $actorId, $actorIp);

        return $this->getAutoPayoutSettings();
    }

    public function getInvoiceData(int $id): array
    {
        $tx = ClickPesaTransaction::findOne($id);
        if ($tx === null) {
            throw new NotFoundHttpException('Control number not found.');
        }

        $controlNumber = trim((string) ($tx->control_number ?? ''));
        $createdAt = $tx->created_at ? date('Y-m-d H:i:s', (int) $tx->created_at) : date('Y-m-d H:i:s');
        $paidAt = $tx->paid_at ? date('Y-m-d H:i:s', (int) $tx->paid_at) : null;

        return [
            'id' => $tx->id,
            'invoiceNumber' => 'INV-CP-' . str_pad((string) $tx->id, 6, '0', STR_PAD_LEFT),
            'orderId' => $tx->order_id ?: '—',
            'customerName' => $tx->customer_name ?: 'Customer',
            'customerPhone' => $tx->phone ? ClickPesaSetting::maskPhone((string) $tx->phone) : null,
            'customerPhoneFormatted' => $tx->phone ? ClickPesaSetting::maskPhone((string) $tx->phone) : '—',
            'customerEmail' => null,
            'controlNumber' => $controlNumber !== '' ? $controlNumber : '—',
            'hasControlNumber' => $controlNumber !== '',
            'billReference' => $tx->order_reference ?: '—',
            'amount' => (float) ($tx->expected_amount ?: $tx->amount),
            'currency' => $tx->currency ?: $this->getConfig()['currency'],
            'paymentMode' => $tx->payment_mode ?: ClickPesaTransaction::MODE_EXACT,
            'description' => $tx->description ?: 'ClickPesa payment',
            'status' => $tx->payment_status,
            'channel' => $tx->channel ?: 'billpay',
            'createdAt' => $createdAt,
            'createdAtFormatted' => $createdAt,
            'paidAt' => $paidAt,
            'paidAtFormatted' => $paidAt ?: '—',
            'qrReference' => $tx->order_reference ?: '',
        ];
    }

    /**
     * POST /billpay/create-order-control-number
     *
     * @param array<string, mixed> $data
     * @throws BadRequestHttpException
     * @throws ConflictHttpException
     * @throws ForbiddenHttpException
     * @throws ServerErrorHttpException
     */
    public function createControlNumber(array $data, ?int $userId = null): array
    {
        $orderId = trim((string) ($data['order_id'] ?? $data['orderId'] ?? ''));
        $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        $description = trim((string) ($data['description'] ?? $data['billDescription'] ?? ''));
        $paymentMode = strtoupper(trim((string) ($data['payment_mode'] ?? $data['paymentMode'] ?? $data['billPaymentMode'] ?? ClickPesaTransaction::MODE_EXACT)));

        if ($amount <= 0) {
            throw new BadRequestHttpException('amount must be a positive decimal value.');
        }
        if ($description === '') {
            $description = $orderId !== ''
                ? 'Payment for order ' . $orderId
                : 'Payment';
        }
        if (!in_array($paymentMode, [ClickPesaTransaction::MODE_EXACT, ClickPesaTransaction::MODE_PARTIAL_OVER], true)) {
            throw new BadRequestHttpException('payment_mode must be EXACT or ALLOW_PARTIAL_AND_OVER_PAYMENT.');
        }

        if ($orderId !== '') {
            $this->assertOrderAccess($orderId, $userId);

            $active = ClickPesaTransaction::find()
                ->where(['order_id' => $orderId])
                ->andWhere(['payment_status' => ClickPesaTransaction::STATUS_PENDING])
                ->andWhere(['not', ['control_number' => null]])
                ->one();

            $forceNew = !empty($data['force_new']) || !empty($data['forceNew']);
            if ($active !== null && !$forceNew) {
                return [
                    'success' => true,
                    'id' => $active->id,
                    'controlNumber' => $active->control_number,
                    'reference' => $active->order_reference,
                    'amount' => (float) ($active->expected_amount ?: $active->amount),
                    'status' => $active->payment_status,
                    'existing' => true,
                    'invoiceUrl' => '/api/clickpesa/control-number/' . $active->id . '/invoice',
                ];
            }
        }

        $billReference = $this->normalizeBillReference(
            trim((string) ($data['orderReference'] ?? $data['billReference'] ?? ''))
        );
        if ($billReference === '' && $orderId !== '') {
            $billReference = $this->normalizeBillReference($orderId);
        }
        if ($billReference === '') {
            $billReference = $this->generateBillReference($orderId !== '' ? $orderId : 'GEN');
        }

        if ($billReference === '') {
            throw new BadRequestHttpException(
                'Bill reference must contain letters and numbers only (no spaces or symbols like - or _).'
            );
        }

        // Retry unique reference on collision
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (ClickPesaTransaction::findOne(['order_reference' => $billReference]) === null) {
                break;
            }
            $billReference = $this->generateBillReference($orderId !== '' ? $orderId : 'GEN');
        }

        if (ClickPesaTransaction::findOne(['order_reference' => $billReference]) !== null) {
            throw new ConflictHttpException('Could not allocate a unique bill reference.');
        }

        $customerName = !empty($data['customerName']) ? (string) $data['customerName'] : null;
        $customerPhone = !empty($data['phone']) ? $this->normalizePhone((string) $data['phone']) : null;

        // ClickPesa order control numbers reject customerName/customerPhone in the API body.
        $payload = [
            'billDescription' => $description,
            'billPaymentMode' => $paymentMode,
            'billAmount' => $amount,
            'billReference' => $billReference,
        ];

        $this->log('info', 'Creating BillPay control number', [
            'billReference' => $billReference,
            'amount' => $amount,
            'orderId' => $orderId,
        ]);

        $response = $this->request('POST', 'billpay/create-order-control-number', $payload);
        $controlNumber = $this->extractBillPayNumber($response, $billReference);
        if ($controlNumber === null) {
            $this->log('error', 'BillPay response missing numeric control number', [
                'billReference' => $billReference,
                'responseKeys' => array_keys($response),
            ]);
            throw new ServerErrorHttpException(
                'ClickPesa did not return a BillPay control number. Try a different Order ID or check API credentials.'
            );
        }

        $tx = new ClickPesaTransaction([
            'order_id' => $orderId !== '' ? $orderId : null,
            'user_id' => $userId,
            'order_reference' => $billReference,
            'control_number' => $controlNumber !== null ? (string) $controlNumber : null,
            'amount' => $amount,
            'expected_amount' => $amount,
            'currency' => $this->getConfig()['currency'],
            'payment_mode' => $paymentMode,
            'phone' => $customerPhone,
            'customer_name' => $customerName,
            'description' => $description,
            'payment_status' => ClickPesaTransaction::STATUS_PENDING,
            'transaction_type' => ClickPesaTransaction::TYPE_COLLECTION,
            'channel' => 'billpay',
            'raw_request' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'raw_payload' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        if (!$tx->save()) {
            $this->log('error', 'Failed to save control-number transaction', ['errors' => $tx->errors]);
            throw new ServerErrorHttpException('Failed to persist transaction.');
        }

        return [
            'success' => true,
            'id' => $tx->id,
            'controlNumber' => $tx->control_number,
            'reference' => $tx->order_reference,
            'amount' => (float) $tx->expected_amount,
            'status' => ClickPesaTransaction::STATUS_PENDING,
            'orderReference' => $tx->order_reference,
            'paymentStatus' => $tx->payment_status,
            'invoiceUrl' => '/api/clickpesa/control-number/' . $tx->id . '/invoice',
            'transaction' => $tx->toApiArray(),
        ];
    }

    /**
     * @return array{totalSales:float,failedSales:float,pendingTransactions:int,count:int,payments:list<array<string,mixed>>}
     */
    public function listWalletPayments(): array
    {
        $payments = $this->loadWalletRows();
        $totalSales = 0.0;
        $failedSales = 0.0;
        $pending = 0;

        foreach ($payments as $payment) {
            $status = strtoupper((string) ($payment['status'] ?? ''));
            $amount = (float) ($payment['amount'] ?? 0);
            if (in_array($status, ['SUCCESS', 'PAID', 'SUCCESSFUL', 'COMPLETED'], true)) {
                $totalSales += $amount;
            } elseif (in_array($status, ['FAILED', 'FAILURE'], true)) {
                $failedSales += $amount;
            } elseif ($status === 'PENDING') {
                $pending++;
            }
        }

        return [
            'totalSales' => $totalSales,
            'failedSales' => $failedSales,
            'pendingTransactions' => $pending,
            'count' => count($payments),
            'payments' => $payments,
        ];
    }

    /**
     * Admin dashboard analytics from local DB (same records as user wallet).
     * Does not call ClickPesa account-statement API.
     *
     * @return array{
     *   success:bool,
     *   source:string,
     *   filters:array<string,string|null>,
     *   analytics:array<string,mixed>,
     *   payments:list<array<string,mixed>>
     * }
     */
    public function getDashboardAnalytics(array $filters = []): array
    {
        $period = strtolower(trim((string) ($filters['period'] ?? 'all')));
        $startDate = $this->normalizeDateParam($filters['startDate'] ?? null);
        $endDate = $this->normalizeDateParam($filters['endDate'] ?? null);

        if ($startDate === null && $endDate === null) {
            if ($period === 'month') {
                $startDate = date('Y-m-01');
                $endDate = date('Y-m-d');
            } elseif ($period === '30d') {
                $startDate = date('Y-m-d', strtotime('-29 days'));
                $endDate = date('Y-m-d');
            } elseif ($period === '90d') {
                $startDate = date('Y-m-d', strtotime('-89 days'));
                $endDate = date('Y-m-d');
            }
        }

        $query = ClickPesaTransaction::find()
            ->where(['transaction_type' => ClickPesaTransaction::TYPE_COLLECTION])
            ->orderBy(['updated_at' => SORT_DESC])
            ->limit(1000);

        if ($startDate !== null) {
            $query->andWhere(['>=', 'created_at', strtotime($startDate . ' 00:00:00')]);
        }
        if ($endDate !== null) {
            $query->andWhere(['<=', 'created_at', strtotime($endDate . ' 23:59:59')]);
        }

        /** @var ClickPesaTransaction[] $models */
        $models = $query->all();

        $moneyIn = 0.0;
        $failedSales = 0.0;
        $success = 0;
        $pending = 0;
        $failed = 0;
        $recentCollections = [];
        $payments = [];
        $firstTransactionAt = null;
        $lastTransactionAt = null;

        foreach ($models as $tx) {
            $amount = (float) ($tx->received_amount ?: $tx->expected_amount ?: $tx->amount);
            $status = strtoupper((string) $tx->payment_status);
            $createdAt = $tx->created_at ? date('c', (int) $tx->created_at) : null;

            if ($createdAt !== null) {
                if ($firstTransactionAt === null || $createdAt < $firstTransactionAt) {
                    $firstTransactionAt = $createdAt;
                }
                if ($lastTransactionAt === null || $createdAt > $lastTransactionAt) {
                    $lastTransactionAt = $createdAt;
                }
            }

            if ($tx->isPaymentSuccessful()) {
                $moneyIn += $amount;
                $success++;
            } elseif ($status === ClickPesaTransaction::STATUS_FAILED) {
                $failedSales += $amount;
                $failed++;
            } else {
                $pending++;
            }

            $payments[] = [
                'orderReference' => $tx->order_reference,
                'amount' => $amount,
                'status' => $tx->isPaymentSuccessful() ? ClickPesaTransaction::STATUS_SUCCESS : $tx->payment_status,
                'phone' => $tx->phone ?: '',
                'controlNumber' => $tx->control_number,
                'createdAt' => $createdAt,
            ];

            $recentCollections[] = [
                'id' => $tx->id,
                'orderId' => $tx->order_id,
                'orderReference' => $tx->order_reference,
                'controlNumber' => $tx->control_number,
                'amount' => $amount,
                'status' => $tx->payment_status,
                'createdAt' => $createdAt,
            ];
        }

        return [
            'success' => true,
            'source' => 'database',
            'period' => $period,
            'filters' => array_filter([
                'period' => $period,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ]),
            'analytics' => [
                'moneyIn' => round($moneyIn, 2),
                'failedSales' => round($failedSales, 2),
                'success' => $success,
                'pending' => $pending,
                'failed' => $failed,
                'recordCount' => count($models),
                'periodLabel' => $this->buildAnalyticsPeriodLabel($startDate, $endDate, $period),
                'firstTransactionAt' => $firstTransactionAt,
                'lastTransactionAt' => $lastTransactionAt,
                'trendDays' => $this->buildAnalyticsTrendDays($payments, 14),
                'recentCollections' => array_slice($recentCollections, 0, 15),
            ],
            'payments' => $payments,
        ];
    }

    /**
     * @return array{success:bool,message:string,orderReference:string}
     */
    public function deleteWalletPayment(string $orderReference): array
    {
        $orderReference = strtoupper(preg_replace('/[^A-Z0-9\-]/', '', $orderReference) ?: '');
        if ($orderReference === '') {
            throw new BadRequestHttpException('orderReference is required.');
        }

        $tx = ClickPesaTransaction::findOne(['order_reference' => $orderReference]);
        if ($tx === null) {
            throw new NotFoundHttpException('Payment not found.');
        }

        if (!$tx->delete()) {
            throw new \RuntimeException('Could not delete payment.');
        }

        return [
            'success' => true,
            'message' => 'Payment deleted.',
            'orderReference' => $orderReference,
        ];
    }

    /**
     * @return array{type:string,count:int,rows:list<array<string,mixed>>}
     */
    public function listWalletPaymentDetails(string $type): array
    {
        $type = strtolower(trim($type));
        $payments = $this->loadWalletRows();

        if ($type === 'success' || $type === 'failed') {
            $wanted = $type === 'success' ? ['SUCCESS', 'PAID'] : ['FAILED'];
            $rows = array_values(array_filter(
                $payments,
                static fn(array $p): bool => in_array(strtoupper((string) ($p['status'] ?? '')), $wanted, true)
            ));

            return ['type' => $type, 'count' => count($rows), 'rows' => $rows];
        }

        if ($type === 'pending' || $type === 'unpaid') {
            $rows = array_values(array_filter(
                $payments,
                static fn(array $p): bool => strtoupper((string) ($p['status'] ?? '')) === 'PENDING'
            ));

            return ['type' => 'pending', 'count' => count($rows), 'rows' => $rows];
        }

        throw new BadRequestHttpException('Invalid type. Use success, failed, or pending.');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadWalletRows(): array
    {
        /** @var ClickPesaTransaction[] $models */
        $models = ClickPesaTransaction::find()
            ->orderBy(['updated_at' => SORT_DESC])
            ->limit(300)
            ->all();

        $rows = [];
        foreach ($models as $tx) {
            $rows[] = [
                'id' => $tx->order_reference,
                'orderReference' => $tx->order_reference,
                'amount' => (float) ($tx->received_amount ?: $tx->amount),
                'status' => $tx->payment_status === ClickPesaTransaction::STATUS_PAID
                    ? ClickPesaTransaction::STATUS_SUCCESS
                    : $tx->payment_status,
                'phone' => $tx->phone ?: '',
                'channel' => $tx->channel ?: '',
                'controlNumber' => $tx->control_number,
                'createdAt' => $tx->created_at ? date('c', (int) $tx->created_at) : null,
            ];
        }

        return $rows;
    }

    /**
     * @throws NotFoundHttpException
     * @throws BadRequestHttpException
     */
    public function getPaymentStatus(string $orderReference, bool $refreshFromApi = true): array
    {
        $orderReference = trim($orderReference);
        if ($orderReference === '') {
            throw new BadRequestHttpException('orderReference is required.');
        }

        $tx = ClickPesaTransaction::find()
            ->where(['order_reference' => $orderReference])
            ->orWhere(['control_number' => $orderReference])
            ->one();
        $remote = null;

        if ($refreshFromApi) {
            try {
                $remote = $this->request('GET', 'payments/' . rawurlencode($orderReference));
                if (is_array($remote) && array_is_list($remote) && isset($remote[0]) && is_array($remote[0])) {
                    $remote = $remote[0];
                }
                $mappedStatus = $this->mapPaymentStatus(is_array($remote) ? $remote : []);
                if ($tx === null) {
                    $tx = new ClickPesaTransaction([
                        'order_reference' => $orderReference,
                        'amount' => (float) ($this->extractValue(is_array($remote) ? $remote : [], ['amount', 'collectedAmount', 'billAmount', 'data.amount']) ?: 0),
                        'currency' => (string) ($this->extractValue(is_array($remote) ? $remote : [], ['currency', 'data.currency']) ?: $this->getConfig()['currency']),
                        'payment_status' => $mappedStatus,
                        'transaction_type' => ClickPesaTransaction::TYPE_COLLECTION,
                        'channel' => (string) ($this->extractValue(is_array($remote) ? $remote : [], ['channel', 'paymentMethod', 'data.channel']) ?: 'api'),
                        'raw_payload' => json_encode($remote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ]);
                    $tx->save(false);
                } elseif ($mappedStatus !== $tx->payment_status && !$tx->isPaymentSuccessful()) {
                    $tx->payment_status = $mappedStatus;
                    $tx->raw_payload = json_encode($remote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $tx->save(false, ['payment_status', 'raw_payload', 'updated_at']);
                }
            } catch (\Throwable $e) {
                $this->log('warning', 'Remote payment status lookup failed', [
                    'orderReference' => $orderReference,
                    'error' => $e->getMessage(),
                ]);
                if ($tx === null) {
                    throw new NotFoundHttpException('Transaction not found.');
                }
            }
        }

        if ($tx === null) {
            throw new NotFoundHttpException('Transaction not found.');
        }

        return [
            'success' => true,
            'orderReference' => $tx->order_reference,
            'paymentStatus' => $tx->payment_status,
            'payoutStatus' => $tx->payout_status,
            'controlNumber' => $tx->control_number,
            'transaction' => $tx->toApiArray(),
            'clickpesa' => $remote,
        ];
    }

    /**
     * Query official payout status — do not trust create HTTP 200 alone.
     */
    public function getPayoutStatus(string $orderReference, bool $refreshFromApi = true): array
    {
        $orderReference = trim($orderReference);
        if ($orderReference === '') {
            throw new BadRequestHttpException('payout reference is required.');
        }

        $payout = ClickPesaPayout::find()
            ->where(['payout_reference' => $orderReference])
            ->one();

        $remote = null;
        if ($refreshFromApi) {
            try {
                $remote = $this->request('GET', 'payouts/' . rawurlencode($orderReference));
                if ($payout !== null) {
                    $mapped = $this->mapPayoutStatus(is_array($remote) ? $remote : []);
                    if ($mapped !== $payout->payout_status) {
                        $payout->payout_status = $mapped;
                        $payout->raw_response = json_encode($remote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if ($payout->isFinal()) {
                            $payout->processed_at = time();
                        }
                        $payout->save(false);
                        $this->syncLegacyPayoutFields($payout);
                    }
                }
            } catch (\Throwable $e) {
                $this->log('warning', 'Remote payout status lookup failed', [
                    'reference' => $orderReference,
                    'error' => $e->getMessage(),
                ]);
                if ($payout === null) {
                    throw new NotFoundHttpException('Payout not found.');
                }
            }
        }

        if ($payout === null) {
            throw new NotFoundHttpException('Payout not found.');
        }

        return [
            'success' => true,
            'payout' => $payout->toAdminArray(),
            'clickpesa' => $remote,
        ];
    }

    /**
     * Manual / legacy payout endpoint (admin / authenticated).
     *
     * @param array{orderReference?:string,amount?:float|int|string,phone?:string,beneficiaryName?:string,description?:string} $data
     */
    public function createPayout(array $data): array
    {
        $this->assertPayoutRateLimit();

        $orderReference = trim((string) ($data['orderReference'] ?? ''));
        if ($orderReference === '') {
            throw new BadRequestHttpException('orderReference is required.');
        }

        $tx = ClickPesaTransaction::findOne(['order_reference' => $orderReference]);
        if ($tx === null) {
            $this->getPaymentStatus($orderReference, true);
            $tx = ClickPesaTransaction::findOne(['order_reference' => $orderReference]);
        }
        if ($tx === null) {
            throw new NotFoundHttpException('Payment transaction not found.');
        }

        $this->getPaymentStatus($orderReference, true);
        $tx->refresh();

        if (!$tx->isPaymentSuccessful()) {
            throw new BadRequestHttpException(
                'Payment must be SUCCESS/PAID before payout. Current status: ' . $tx->payment_status
            );
        }

        $settings = ClickPesaSetting::current();
        $phone = trim((string) ($data['phone'] ?? $settings->getDestinationPhone() ?? ''));
        if ($phone === '') {
            throw new BadRequestHttpException('Destination phone is not configured.');
        }

        $amount = isset($data['amount']) ? (float) $data['amount'] : $this->calculatePayoutAmount($tx, $settings);
        $payout = $this->queueOrCreatePayout($tx, $amount, $phone, false);

        if ($payout->payout_status === ClickPesaPayout::STATUS_AWAITING_APPROVAL) {
            return [
                'success' => true,
                'message' => 'Payout awaiting manual approval.',
                'payout' => $payout->toAdminArray(),
            ];
        }

        $payout = $this->processPayout($payout, $phone);

        return [
            'success' => true,
            'orderReference' => $tx->order_reference,
            'payoutReference' => $payout->payout_reference,
            'payoutStatus' => $payout->payout_status,
            'paymentStatus' => $tx->payment_status,
            'payout' => $payout->toAdminArray(),
            'transaction' => $tx->toApiArray(),
        ];
    }

    /**
     * Process inbound webhook: validate, store event, update payment, queue payout async.
     *
     * @throws UnauthorizedHttpException
     * @throws BadRequestHttpException
     * @throws DbException
     */
    public function processWebhook(array $payload, ?string $signatureHeader = null, ?string $tokenHeader = null, string $rawBody = ''): array
    {
        $signatureValid = false;
        try {
            $this->validateWebhookSecurity($payload, $signatureHeader, $tokenHeader);
            $signatureValid = true;
        } catch (UnauthorizedHttpException $e) {
            $event = $this->storeWebhookEvent($payload, $rawBody, false, ClickPesaWebhookEvent::STATUS_REJECTED);
            $this->log('warning', 'Webhook rejected', ['eventId' => $event->id]);
            throw $e;
        }

        $eventHash = ClickPesaWebhookEvent::hashPayload($rawBody, $payload);
        $existing = ClickPesaWebhookEvent::findOne(['event_hash' => $eventHash]);
        if ($existing !== null) {
            $existing->processing_status = ClickPesaWebhookEvent::STATUS_DUPLICATE;
            $existing->save(false, ['processing_status']);

            return [
                'success' => true,
                'message' => 'Duplicate webhook ignored',
                'duplicate' => true,
            ];
        }

        $event = $this->storeWebhookEvent($payload, $rawBody, $signatureValid, ClickPesaWebhookEvent::STATUS_RECEIVED);

        $eventType = strtoupper((string) ($this->extractValue($payload, [
            'event',
            'eventType',
            'type',
            'status',
            'data.event',
        ]) ?: 'WEBHOOK'));

        $normalizedType = $this->normalizeEventType($eventType, $payload);

        try {
            $result = match (true) {
                $this->eventMatches($normalizedType, ['PAYMENT RECEIVED', 'PAYMENT_RECEIVED', 'RECEIVED', 'SUCCESS', 'PAID', 'SETTLED'])
                    && !$this->isPayoutEvent($payload, $eventType)
                    => $this->handlePaymentReceived($payload),
                $this->eventMatches($normalizedType, ['PAYMENT FAILED', 'PAYMENT_FAILED', 'FAILED', 'FAILURE'])
                    && !$this->isPayoutEvent($payload, $eventType)
                    => $this->handlePaymentFailed($payload),
                $this->eventMatches($normalizedType, ['PAYOUT INITIATED', 'PAYOUT_INITIATED', 'INITIATED'])
                    => $this->handlePayoutStatusUpdate($payload, ClickPesaPayout::STATUS_PENDING),
                $this->eventMatches($normalizedType, ['PAYOUT SUCCESS', 'PAYOUT_SUCCESS', 'SUCCESS'])
                    && $this->isPayoutEvent($payload, $eventType)
                    => $this->handlePayoutStatusUpdate($payload, ClickPesaPayout::STATUS_SUCCESS),
                $this->eventMatches($normalizedType, ['PAYOUT FAILED', 'PAYOUT_FAILED'])
                    => $this->handlePayoutStatusUpdate($payload, ClickPesaPayout::STATUS_FAILED),
                $this->eventMatches($normalizedType, ['PAYOUT REFUNDED', 'PAYOUT_REFUNDED', 'REFUNDED'])
                    => $this->handlePayoutStatusUpdate($payload, ClickPesaPayout::STATUS_REFUNDED),
                $this->eventMatches($normalizedType, ['PAYOUT REVERSED', 'PAYOUT_REVERSED', 'REVERSED'])
                    => $this->handlePayoutStatusUpdate($payload, ClickPesaPayout::STATUS_REVERSED),
                default => $this->handleGenericWebhook($payload, $normalizedType),
            };

            $event->processing_status = ClickPesaWebhookEvent::STATUS_PROCESSED;
            $event->processed_at = time();
            $event->event_type = $normalizedType;
            $event->save(false);

            return array_merge(['success' => true, 'eventType' => $normalizedType], $result);
        } catch (\Throwable $e) {
            $event->processing_status = ClickPesaWebhookEvent::STATUS_FAILED;
            $event->save(false);
            throw $e;
        }
    }

    /**
     * @throws UnauthorizedHttpException
     */
    public function validateWebhookSecurity(array $payload, ?string $signatureHeader = null, ?string $tokenHeader = null): void
    {
        $config = $this->getConfig();
        $checksumKey = $config['checksumKey'];
        $webhookToken = $config['webhookToken'];

        if ($webhookToken !== '') {
            $provided = $tokenHeader
                ?? ($_SERVER['HTTP_X_CLICKPESA_TOKEN'] ?? null)
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
            if (is_string($provided) && stripos($provided, 'Bearer ') === 0) {
                $provided = trim(substr($provided, 7));
            }
            if (!is_string($provided) || !hash_equals($webhookToken, $provided)) {
                $this->log('warning', 'Webhook token validation failed');
                throw new UnauthorizedHttpException('Invalid webhook token.');
            }
        }

        if ($checksumKey === '') {
            if ($webhookToken === '') {
                $this->log('warning', 'Webhook accepted without checksumKey/webhookToken — configure for production.');
            }
            return;
        }

        $providedChecksum = $signatureHeader
            ?? (isset($payload['checksum']) ? (string) $payload['checksum'] : null)
            ?? ($_SERVER['HTTP_X_CLICKPESA_SIGNATURE'] ?? null)
            ?? ($_SERVER['HTTP_X_CHECKSUM'] ?? null);

        if (!is_string($providedChecksum) || $providedChecksum === '') {
            throw new UnauthorizedHttpException('Missing webhook signature.');
        }

        $payloadForValidation = $payload;
        unset($payloadForValidation['checksum'], $payloadForValidation['checksumMethod']);

        $computed = $this->createPayloadChecksum($checksumKey, $payloadForValidation);
        if (!hash_equals($computed, strtolower($providedChecksum)) && !hash_equals($computed, $providedChecksum)) {
            $this->log('warning', 'Webhook checksum mismatch', ['expectedPrefix' => substr($computed, 0, 8)]);
            throw new UnauthorizedHttpException('Invalid webhook signature.');
        }
    }

    /**
     * ClickPesa canonical HMAC-SHA256 checksum.
     * @see https://docs.clickpesa.com/home/checksum
     *
     * @param mixed $payload
     */
    public function createPayloadChecksum(string $checksumKey, $payload): string
    {
        $canonical = $this->canonicalize($payload);
        $payloadString = json_encode($canonical, JSON_UNESCAPED_SLASHES);
        if ($payloadString === false) {
            throw new ServerErrorHttpException('Failed to encode payload for checksum.');
        }

        return hash_hmac('sha256', $payloadString, $checksumKey);
    }

    /**
     * Process queued / due automatic payouts (console job).
     *
     * @return array{processed:int,skipped:int,errors:int}
     */
    public function processPendingPayouts(int $limit = 20): array
    {
        $now = time();
        /** @var ClickPesaPayout[] $rows */
        $rows = ClickPesaPayout::find()
            ->where(['payout_status' => [ClickPesaPayout::STATUS_QUEUED, ClickPesaPayout::STATUS_FAILED]])
            ->andWhere([
                'or',
                ['next_retry_at' => null],
                ['<=', 'next_retry_at', $now],
            ])
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit)
            ->all();

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        $settings = ClickPesaSetting::current();
        if (!(bool) $settings->auto_payout_enabled && empty($rows)) {
            return ['processed' => 0, 'skipped' => 0, 'errors' => 0];
        }

        foreach ($rows as $payout) {
            if ($payout->payout_status === ClickPesaPayout::STATUS_FAILED && !$payout->canRetry()) {
                $skipped++;
                continue;
            }
            if ((bool) $settings->require_manual_approval && $payout->payout_status === ClickPesaPayout::STATUS_QUEUED) {
                $payout->payout_status = ClickPesaPayout::STATUS_AWAITING_APPROVAL;
                $payout->save(false);
                $skipped++;
                continue;
            }

            $phone = $settings->getDestinationPhone();
            if ($phone === null || $phone === '') {
                $skipped++;
                continue;
            }

            try {
                $this->processPayout($payout, $phone);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->log('error', 'Pending payout processing failed', [
                    'payoutId' => $payout->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('processed', 'skipped', 'errors');
    }

    public function retryPayout(int $payoutId, bool $adminApproved = false): array
    {
        $this->assertPayoutRateLimit();

        $payout = ClickPesaPayout::findOne($payoutId);
        if ($payout === null) {
            throw new NotFoundHttpException('Payout not found.');
        }

        if ($payout->isFinal()) {
            throw new ConflictHttpException('Payout already finalized.');
        }

        if ($payout->payout_status === ClickPesaPayout::STATUS_AWAITING_APPROVAL && !$adminApproved) {
            throw new ForbiddenHttpException('Manual approval required.');
        }

        if ($payout->payout_status === ClickPesaPayout::STATUS_FAILED && !$payout->canRetry() && !$adminApproved) {
            throw new BadRequestHttpException('Payout is not retryable: ' . (string) $payout->last_error);
        }

        $settings = ClickPesaSetting::current();
        $phone = $settings->getDestinationPhone();
        if ($phone === null || $phone === '') {
            throw new BadRequestHttpException('Destination phone is not configured.');
        }

        $payout->retry_count = (int) $payout->retry_count + 1;
        $payout->payout_status = ClickPesaPayout::STATUS_QUEUED;
        $payout->last_error = null;
        $payout->save(false);

        $payout = $this->processPayout($payout, $phone);

        return ['success' => true, 'payout' => $payout->toAdminArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handlePaymentReceived(array $payload): array
    {
        $tx = $this->findTransactionFromPayload($payload);
        if ($tx === null) {
            throw new BadRequestHttpException('No matching internal transaction for webhook reference.');
        }

        $receivedAmount = (float) ($this->extractValue($payload, [
            'amount',
            'collectedAmount',
            'billAmount',
            'paidAmount',
            'data.amount',
            'data.collectedAmount',
        ]) ?: 0);

        $currency = (string) ($this->extractValue($payload, ['currency', 'collectedCurrency', 'data.currency']) ?: $tx->currency);
        $expected = (float) ($tx->expected_amount ?: $tx->amount);

        if ($currency !== '' && strtoupper($currency) !== strtoupper((string) $tx->currency)) {
            throw new BadRequestHttpException('Currency mismatch.');
        }

        $mode = $tx->payment_mode ?: ClickPesaTransaction::MODE_EXACT;
        if ($mode === ClickPesaTransaction::MODE_EXACT && $receivedAmount > 0 && abs($receivedAmount - $expected) > 0.009) {
            $this->log('warning', 'Payment amount mismatch', [
                'reference' => $tx->order_reference,
                'expected' => $expected,
                'received' => $receivedAmount,
            ]);
            throw new BadRequestHttpException('Payment amount mismatch.');
        }

        $alreadyPaid = $tx->isPaymentSuccessful();

        $transaction = Yii::$app->db->beginTransaction();
        try {
            if (!$alreadyPaid) {
                $tx->payment_status = ClickPesaTransaction::STATUS_PAID;
                $tx->received_amount = $receivedAmount > 0 ? $receivedAmount : $expected;
                $tx->paid_at = time();
                $tx->event_type = 'PAYMENT RECEIVED';
                $tx->raw_payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $cpId = $this->extractValue($payload, ['id', 'transactionId', 'paymentId', 'data.id']);
                if ($cpId) {
                    $tx->clickpesa_transaction_id = (string) $cpId;
                }
                $tx->save(false);

                $this->markRelatedOrderPaid($tx);
                $this->updateInventoryOnce($tx);
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $payoutQueued = false;
        if (!$alreadyPaid) {
            $payoutQueued = $this->maybeQueueAutomaticPayout($tx);
            if ($payoutQueued) {
                $this->processQueuedPayoutForPayment($tx);
            }
        }

        return [
            'message' => $alreadyPaid ? 'Payment already marked paid' : 'Payment marked paid',
            'orderReference' => $tx->order_reference,
            'paymentStatus' => $tx->payment_status,
            'payoutQueued' => $payoutQueued,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handlePaymentFailed(array $payload): array
    {
        $tx = $this->findTransactionFromPayload($payload);
        if ($tx === null) {
            throw new BadRequestHttpException('No matching internal transaction for webhook reference.');
        }

        if (!$tx->isPaymentSuccessful()) {
            $tx->payment_status = ClickPesaTransaction::STATUS_FAILED;
            $tx->event_type = 'PAYMENT FAILED';
            $tx->raw_payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $tx->save(false);
        }

        return [
            'message' => 'Payment marked failed',
            'orderReference' => $tx->order_reference,
            'paymentStatus' => $tx->payment_status,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handlePayoutStatusUpdate(array $payload, string $status): array
    {
        $ref = (string) ($this->extractValue($payload, [
            'orderReference',
            'payoutReference',
            'reference',
            'data.orderReference',
        ]) ?: '');

        $payout = $ref !== ''
            ? ClickPesaPayout::findOne(['payout_reference' => $ref])
            : null;

        if ($payout === null) {
            return ['message' => 'Payout event recorded without local match', 'reference' => $ref];
        }

        $payout->payout_status = $status;
        $payout->raw_response = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (in_array($status, [ClickPesaPayout::STATUS_SUCCESS, ClickPesaPayout::STATUS_FAILED, ClickPesaPayout::STATUS_REFUNDED, ClickPesaPayout::STATUS_REVERSED], true)) {
            $payout->processed_at = time();
        }
        $fee = $this->extractValue($payload, ['fee', 'charges', 'data.fee']);
        if ($fee !== null) {
            $payout->fee = (float) $fee;
        }
        $provider = $this->extractValue($payload, ['provider', 'channel', 'data.provider']);
        if ($provider) {
            $payout->provider = (string) $provider;
        }
        $payout->save(false);
        $this->syncLegacyPayoutFields($payout);

        return ['message' => 'Payout status updated', 'payout' => $payout->toAdminArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleGenericWebhook(array $payload, string $eventType): array
    {
        $mapped = $this->mapPaymentStatus($payload);
        $isPayout = $this->isPayoutEvent($payload, $eventType);
        if ($isPayout) {
            return $this->handlePayoutStatusUpdate($payload, $this->mapPayoutStatus($payload));
        }

        $tx = $this->findTransactionFromPayload($payload);
        if ($tx === null) {
            return ['message' => 'Webhook stored', 'eventType' => $eventType];
        }

        if ($mapped === ClickPesaTransaction::STATUS_SUCCESS || $mapped === ClickPesaTransaction::STATUS_PAID) {
            return $this->handlePaymentReceived($payload);
        }
        if ($mapped === ClickPesaTransaction::STATUS_FAILED) {
            return $this->handlePaymentFailed($payload);
        }

        $tx->event_type = $eventType;
        $tx->raw_payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tx->save(false);

        return ['message' => 'Webhook applied', 'orderReference' => $tx->order_reference];
    }

    private function maybeQueueAutomaticPayout(ClickPesaTransaction $tx): bool
    {
        $settings = ClickPesaSetting::current();
        if (
            !(bool) $settings->auto_payout_enabled
            || ($settings->mode ?: ClickPesaSetting::MODE_TEST) === ClickPesaSetting::MODE_TEST
        ) {
            $this->log('info', 'Auto payout disabled (test mode)', ['paymentId' => $tx->id]);
            return false;
        }

        if (ClickPesaPayout::findOne(['payment_id' => $tx->id]) !== null) {
            return false;
        }

        $phone = $settings->getDestinationPhone() ?: $this->getConfig()['autoPayoutPhone'];
        if ($phone === null || $phone === '') {
            $this->log('warning', 'Auto payout skipped — no destination');
            return false;
        }

        $amount = $this->calculatePayoutAmount($tx, $settings);
        $mode = strtoupper((string) ($settings->mode ?: ClickPesaSetting::MODE_TEST));
        $minimum = (float) $settings->minimum_amount;
        if ($amount < $minimum) {
            if ($mode !== ClickPesaSetting::MODE_LIVE_AUTO || $amount <= 0) {
                $this->log('info', 'Auto payout below minimum', ['amount' => $amount, 'minimum' => $minimum]);
                return false;
            }
        }

        if (!$this->withinDailyLimit($settings, $amount)) {
            $this->log('warning', 'Auto payout blocked by daily limit');
            return false;
        }

        $delay = max(0, (int) $settings->delay_seconds);
        $payout = $this->queueOrCreatePayout($tx, $amount, $phone, true);
        $payout->next_retry_at = time() + $delay;
        if ((bool) $settings->require_manual_approval) {
            $payout->payout_status = ClickPesaPayout::STATUS_AWAITING_APPROVAL;
        }
        $payout->save(false);

        return true;
    }

    private function queueOrCreatePayout(
        ClickPesaTransaction $tx,
        float $amount,
        string $phone,
        bool $fromAuto
    ): ClickPesaPayout {
        $existing = ClickPesaPayout::findOne(['payment_id' => $tx->id]);
        if ($existing !== null) {
            if ($existing->isFinal() || in_array($existing->payout_status, [
                ClickPesaPayout::STATUS_PENDING,
                ClickPesaPayout::STATUS_PROCESSING,
                ClickPesaPayout::STATUS_QUEUED,
                ClickPesaPayout::STATUS_AWAITING_APPROVAL,
            ], true)) {
                throw new ConflictHttpException('Payout already exists for this payment.');
            }

            return $existing;
        }

        $ref = 'TIS-PAYOUT-' . $tx->id . '-' . time() . random_int(10, 99);
        $payout = new ClickPesaPayout([
            'payment_id' => $tx->id,
            'payout_reference' => $ref,
            'destination_type' => ClickPesaSetting::DESTINATION_MOBILE,
            'destination_masked' => ClickPesaSetting::maskPhone($this->normalizePhone($phone)),
            'amount' => $amount,
            'currency' => $tx->currency ?: $this->getConfig()['currency'],
            'payout_status' => ClickPesaPayout::STATUS_QUEUED,
            'retry_count' => 0,
        ]);

        if (!$payout->save()) {
            throw new ServerErrorHttpException('Failed to create payout record.');
        }

        $this->log('info', $fromAuto ? 'Auto payout queued' : 'Payout queued', [
            'paymentId' => $tx->id,
            'payoutReference' => $ref,
            'amount' => $amount,
        ]);

        return $payout;
    }

    private function processPayout(ClickPesaPayout $payout, string $phone): ClickPesaPayout
    {
        $normalizedPhone = $this->normalizePhone($phone);
        $payload = [
            'amount' => (float) $payout->amount,
            'phoneNumber' => $normalizedPhone,
            'currency' => $payout->currency ?: 'TZS',
            'orderReference' => $payout->payout_reference,
        ];

        $payout->payout_status = ClickPesaPayout::STATUS_PROCESSING;
        $payout->raw_request = json_encode($this->redactPayoutPayload($payload), JSON_UNESCAPED_SLASHES);
        $payout->save(false);

        try {
            // Preview first
            $preview = $this->request('POST', 'payouts/preview-mobile-money-payout', $payload);
            $payout->payout_status = ClickPesaPayout::STATUS_PREVIEWED;
            $payout->raw_response = json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $fee = $this->extractValue($preview, ['fee', 'charges', 'data.fee']);
            if ($fee !== null) {
                $payout->fee = (float) $fee;
            }
            $payout->save(false);

            // Create only after preview succeeds
            $response = $this->request('POST', 'payouts/create-mobile-money-payout', $payload);
            $mapped = $this->mapPayoutStatus($response);
            // HTTP 200 on create ≠ SUCCESS — keep PENDING unless API says otherwise
            if ($mapped === ClickPesaPayout::STATUS_SUCCESS) {
                $mapped = ClickPesaPayout::STATUS_PENDING;
            }
            $payout->payout_status = $mapped === ClickPesaPayout::STATUS_FAILED
                ? ClickPesaPayout::STATUS_FAILED
                : ClickPesaPayout::STATUS_PENDING;
            $payout->raw_response = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $provider = $this->extractValue($response, ['provider', 'channel', 'data.provider']);
            if ($provider) {
                $payout->provider = (string) $provider;
            }
            $payout->save(false);
            $this->syncLegacyPayoutFields($payout);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $payout->payout_status = ClickPesaPayout::STATUS_FAILED;
            $payout->last_error = $message;
            $payout->retry_count = (int) $payout->retry_count;
            if ($payout->isRetryableError() && (int) $payout->retry_count < ClickPesaPayout::MAX_RETRIES) {
                $backoff = (int) (60 * (2 ** (int) $payout->retry_count));
                $payout->next_retry_at = time() + $backoff;
            }
            $payout->save(false);
            $this->syncLegacyPayoutFields($payout);
            throw $e;
        }

        return $payout;
    }

    private function syncLegacyPayoutFields(ClickPesaPayout $payout): void
    {
        $tx = ClickPesaTransaction::findOne($payout->payment_id);
        if ($tx === null) {
            return;
        }
        $tx->payout_reference = $payout->payout_reference;
        $tx->payout_amount = $payout->amount;
        $tx->payout_status = $payout->payout_status;
        $tx->payout_phone = $payout->destination_masked;
        $tx->payout_payload = $payout->raw_response;
        $tx->save(false, ['payout_reference', 'payout_amount', 'payout_status', 'payout_phone', 'payout_payload', 'updated_at']);
    }

    private function calculatePayoutAmount(ClickPesaTransaction $tx, ClickPesaSetting $settings): float
    {
        $base = (float) ($tx->received_amount ?: $tx->expected_amount ?: $tx->amount);
        $pct = (float) $settings->payout_percentage;
        if ($pct <= 0) {
            $pct = 100;
        }

        return round($base * ($pct / 100), 2);
    }

    private function withinDailyLimit(ClickPesaSetting $settings, float $amount): bool
    {
        $limit = (float) $settings->daily_limit;
        if ($limit <= 0) {
            return true;
        }

        $start = strtotime('today');
        $sum = (float) ClickPesaPayout::find()
            ->where(['>=', 'created_at', $start])
            ->andWhere(['not in', 'payout_status', [ClickPesaPayout::STATUS_FAILED, ClickPesaPayout::STATUS_REVERSED]])
            ->sum('amount');

        return ($sum + $amount) <= $limit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function findTransactionFromPayload(array $payload): ?ClickPesaTransaction
    {
        $ref = (string) ($this->extractValue($payload, [
            'billReference',
            'orderReference',
            'order_reference',
            'reference',
            'data.billReference',
            'data.orderReference',
            'payload.orderReference',
        ]) ?: '');

        $control = (string) ($this->extractValue($payload, [
            'billPayNumber',
            'controlNumber',
            'control_number',
            'data.billPayNumber',
            'data.controlNumber',
        ]) ?: '');

        if ($ref !== '') {
            $tx = ClickPesaTransaction::findOne(['order_reference' => $ref]);
            if ($tx !== null) {
                return $tx;
            }
        }
        if ($control !== '') {
            return ClickPesaTransaction::findOne(['control_number' => $control]);
        }

        return null;
    }

    private function markRelatedOrderPaid(ClickPesaTransaction $tx): void
    {
        if ($tx->order_id === null || $tx->order_id === '') {
            // Try matching orders.orderReference
            try {
                Yii::$app->db->createCommand()
                    ->update('orders', ['status' => 'PAID'], ['orderReference' => $tx->order_reference])
                    ->execute();
            } catch (\Throwable) {
                // orders table may not exist in all environments
            }
            return;
        }

        try {
            Yii::$app->db->createCommand()
                ->update('orders', ['status' => 'PAID'], [
                    'or',
                    ['id' => $tx->order_id],
                    ['orderReference' => $tx->order_id],
                    ['orderReference' => $tx->order_reference],
                ])
                ->execute();
        } catch (\Throwable $e) {
            $this->log('warning', 'Could not update related order', ['error' => $e->getMessage()]);
        }
    }

    private function updateInventoryOnce(ClickPesaTransaction $tx): void
    {
        if ((int) $tx->inventory_updated === 1) {
            return;
        }

        try {
            $orderId = null;
            if ($tx->order_id !== null && ctype_digit((string) $tx->order_id)) {
                $orderId = (int) $tx->order_id;
            } else {
                $row = Yii::$app->db->createCommand(
                    'SELECT id FROM orders WHERE orderReference = :ref LIMIT 1',
                    [':ref' => $tx->order_reference]
                )->queryOne();
                if ($row) {
                    $orderId = (int) $row['id'];
                }
            }

            if ($orderId) {
                $items = Yii::$app->db->createCommand(
                    'SELECT sku, quantity FROM order_items WHERE orderId = :oid',
                    [':oid' => $orderId]
                )->queryAll();

                foreach ($items as $item) {
                    Yii::$app->db->createCommand(
                        'UPDATE inventory_items SET stock = GREATEST(0, stock - :qty) WHERE sku = :sku',
                        [':qty' => (int) $item['quantity'], ':sku' => $item['sku']]
                    )->execute();
                }
            }

            $tx->inventory_updated = 1;
            $tx->save(false, ['inventory_updated', 'updated_at']);
        } catch (\Throwable $e) {
            $this->log('warning', 'Inventory update skipped', ['error' => $e->getMessage()]);
        }
    }

    private function resolveWithdrawInfo(
        ClickPesaTransaction $tx,
        ?ClickPesaPayout $payout,
        ClickPesaSetting $settings
    ): array {
        if (!$tx->isPaymentSuccessful()) {
            return ['withdrawStatus' => '—', 'canWithdraw' => false];
        }

        $enabled = (bool) $settings->auto_payout_enabled;
        $mode = strtoupper((string) ($settings->mode ?: ClickPesaSetting::MODE_TEST));
        if (!$enabled || $mode === ClickPesaSetting::MODE_TEST) {
            return ['withdrawStatus' => 'Payout off', 'canWithdraw' => false];
        }

        $payoutStatus = strtoupper((string) ($payout->payout_status ?? $tx->payout_status ?? ''));
        if (in_array($payoutStatus, [ClickPesaPayout::STATUS_SUCCESS, 'COMPLETED', 'SETTLED'], true)) {
            return ['withdrawStatus' => 'Withdrawn', 'canWithdraw' => false];
        }
        if (in_array($payoutStatus, [
            ClickPesaPayout::STATUS_QUEUED,
            ClickPesaPayout::STATUS_AWAITING_APPROVAL,
            ClickPesaPayout::STATUS_PROCESSING,
            ClickPesaPayout::STATUS_PENDING,
            'PREVIEWED',
        ], true)) {
            return ['withdrawStatus' => 'Processing…', 'canWithdraw' => false];
        }
        if ($payoutStatus === ClickPesaPayout::STATUS_FAILED) {
            return ['withdrawStatus' => 'Failed', 'canWithdraw' => $mode === ClickPesaSetting::MODE_MANUAL_APPROVAL];
        }
        if ($mode === ClickPesaSetting::MODE_LIVE_AUTO) {
            return ['withdrawStatus' => $payoutStatus !== '' ? $payoutStatus : 'Auto queued', 'canWithdraw' => false];
        }

        return ['withdrawStatus' => 'Not withdrawn', 'canWithdraw' => true];
    }

    private function processQueuedPayoutForPayment(ClickPesaTransaction $tx): void
    {
        $settings = ClickPesaSetting::current();
        if (($settings->mode ?: ClickPesaSetting::MODE_TEST) !== ClickPesaSetting::MODE_LIVE_AUTO) {
            return;
        }
        if ((bool) $settings->require_manual_approval) {
            return;
        }

        $payout = ClickPesaPayout::findOne(['payment_id' => $tx->id]);
        if ($payout === null || $payout->isFinal()) {
            return;
        }

        $phone = $settings->getDestinationPhone() ?: $this->getConfig()['autoPayoutPhone'];
        if ($phone === null || trim($phone) === '') {
            return;
        }

        try {
            $this->processPayout($payout, $phone);
        } catch (\Throwable $e) {
            $this->log('error', 'Immediate auto payout failed', [
                'paymentId' => $tx->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function assertOrderAccess(string $orderId, ?int $userId): void
    {
        // Soft check: if orders table has the row, ensure it exists. Full RBAC can be layered later.
        try {
            $exists = Yii::$app->db->createCommand(
                'SELECT id FROM orders WHERE id = :id OR orderReference = :ref LIMIT 1',
                [':id' => $orderId, ':ref' => $orderId]
            )->queryScalar();
            if ($exists === false) {
                // Allow creating control numbers for external order ids not yet in DB
                return;
            }
        } catch (\Throwable) {
            return;
        }

        unset($userId);
    }

    private function assertPayoutRateLimit(): void
    {
        $ip = Yii::$app->request->userIP ?? 'cli';
        $key = self::RATE_LIMIT_KEY . $ip;
        $count = (int) Yii::$app->cache->get($key);
        if ($count >= 10) {
            throw new TooManyRequestsHttpException('Payout rate limit exceeded. Try again later.');
        }
        Yii::$app->cache->set($key, $count + 1, 60);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeWebhookEvent(array $payload, string $rawBody, bool $signatureValid, string $status): ClickPesaWebhookEvent
    {
        $ref = (string) ($this->extractValue($payload, [
            'billReference', 'orderReference', 'reference', 'data.orderReference',
        ]) ?: '');

        $event = new ClickPesaWebhookEvent([
            'event_hash' => ClickPesaWebhookEvent::hashPayload($rawBody, $payload),
            'event_type' => (string) ($this->extractValue($payload, ['event', 'eventType', 'type']) ?: null),
            'reference' => $ref !== '' ? $ref : null,
            'signature_valid' => $signatureValid ? 1 : 0,
            'payload' => $rawBody !== '' ? $rawBody : json_encode($payload, JSON_UNESCAPED_SLASHES),
            'processing_status' => $status,
            'created_at' => time(),
        ]);

        // Unique hash may race — ignore duplicate insert
        try {
            $event->save(false);
        } catch (\Throwable) {
            $existing = ClickPesaWebhookEvent::findOne(['event_hash' => $event->event_hash]);
            if ($existing !== null) {
                return $existing;
            }
            throw new ServerErrorHttpException('Failed to store webhook event.');
        }

        return $event;
    }

    /**
     * ClickPesa BillPay returns billPayNumber (often identical to billReference).
     *
     * @param array<string, mixed> $response
     */
    private function extractBillPayNumber(array $response, string $billReference): ?string
    {
        $paths = [
            'billPayNumber',
            'data.billPayNumber',
            'data.billPay.billPayNumber',
            'billPay.billPayNumber',
            'controlNumber',
            'data.controlNumber',
            'control_number',
            'data.control_number',
        ];

        foreach ($paths as $path) {
            $value = $this->extractValue($response, [$path]);
            if ($value === null || $value === '') {
                continue;
            }

            $normalized = strtoupper(trim((string) $value));
            if (!preg_match('/^[A-Z0-9]+$/', $normalized)) {
                continue;
            }
            if (strlen($normalized) < 3 || strlen($normalized) > 20) {
                continue;
            }

            return $normalized;
        }

        return null;
    }

    private function normalizeBillReference(string $value): string
    {
        $safe = strtoupper(preg_replace('/[^A-Z0-9]/', '', $value) ?: '');

        return substr($safe, 0, 20);
    }

    private function generateBillReference(string $orderId): string
    {
        $safe = strtoupper(preg_replace('/[^A-Z0-9]/', '', $orderId) ?: 'ORD');
        $safe = substr($safe, 0, 8);
        $suffix = substr((string) time(), -6) . (string) random_int(10, 99);

        return substr($safe . $suffix, 0, 20);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, bool $retryOnUnauthorized = true): array
    {
        $config = $this->getConfig();
        $token = $this->generateToken();

        if (!class_exists(Client::class, true)) {
            throw new ServerErrorHttpException(
                'Class yii\\httpclient\\Client not found. Run: composer require yiisoft/yii2-httpclient && composer dump-autoload'
            );
        }

        $client = new Client([
            'baseUrl' => $config['baseUrl'],
            'requestConfig' => ['format' => Client::FORMAT_JSON],
            'responseConfig' => ['format' => Client::FORMAT_JSON],
        ]);

        $request = $client->createRequest()
            ->setMethod($method)
            ->setUrl(ltrim($path, '/'))
            ->setHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);

        if ($body !== null) {
            if ($config['checksumKey'] !== '') {
                $bodyForChecksum = $body;
                unset($bodyForChecksum['checksum'], $bodyForChecksum['checksumMethod']);
                $body['checksum'] = $this->createPayloadChecksum($config['checksumKey'], $bodyForChecksum);
                $body['checksumMethod'] = 'canonical';
            }
            $request->setData($body);
        }

        $response = $request->send();
        $data = is_array($response->data) ? $response->data : [];

        $this->log('info', 'ClickPesa API response', [
            'method' => $method,
            'path' => $path,
            'status' => $response->statusCode,
            'clickpesaCode' => $this->extractValue($data, ['code', 'responseCode', 'statusCode', 'data.code']),
        ]);

        if (!$response->isOk) {
            $message = (string) ($this->extractValue($data, ['message', 'error', 'error.message']) ?: 'ClickPesa request failed');
            // Never log raw body (may contain sensitive fields)
            $this->log('error', 'ClickPesa API error', [
                'path' => $path,
                'status' => $response->statusCode,
                'message' => $message,
            ]);

            $code = (int) $response->statusCode;
            if ($code === 401) {
                Yii::$app->cache->delete(self::TOKEN_CACHE_KEY);
                if ($retryOnUnauthorized) {
                    return $this->request($method, $path, $body, false);
                }
                throw new UnauthorizedHttpException($message);
            }
            if ($code === 409) {
                throw new ConflictHttpException($message);
            }
            if ($code >= 400 && $code < 500) {
                throw new BadRequestHttpException($message);
            }

            throw new ServerErrorHttpException($message);
        }

        return $data;
    }

    /**
     * @param mixed $obj
     * @return mixed
     */
    private function canonicalize($obj)
    {
        if ($obj === null || !is_array($obj)) {
            return $obj;
        }

        if (array_is_list($obj)) {
            return array_map([$this, 'canonicalize'], $obj);
        }

        ksort($obj);
        $result = [];
        foreach ($obj as $key => $value) {
            $result[$key] = $this->canonicalize($value);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapPaymentStatus(array $payload): string
    {
        $raw = strtoupper((string) ($this->extractValue($payload, [
            'status',
            'paymentStatus',
            'transactionStatus',
            'data.status',
            'payload.status',
            'state',
        ]) ?: 'PENDING'));

        if (in_array($raw, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'PAID', 'SETTLED', 'OK', 'PAYMENT RECEIVED', 'PAYMENT_RECEIVED'], true)) {
            return ClickPesaTransaction::STATUS_PAID;
        }
        if (in_array($raw, ['FAILED', 'FAILURE', 'DECLINED', 'CANCELLED', 'CANCELED', 'ERROR', 'PAYMENT FAILED'], true)) {
            return ClickPesaTransaction::STATUS_FAILED;
        }
        if (in_array($raw, ['REFUNDED', 'REFUND'], true)) {
            return ClickPesaTransaction::STATUS_REFUNDED;
        }

        return ClickPesaTransaction::STATUS_PENDING;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mapPayoutStatus(array $payload): string
    {
        $raw = strtoupper((string) ($this->extractValue($payload, [
            'status',
            'payoutStatus',
            'data.status',
            'state',
        ]) ?: 'PENDING'));

        return match (true) {
            in_array($raw, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'PAID', 'SETTLED'], true) => ClickPesaPayout::STATUS_SUCCESS,
            in_array($raw, ['FAILED', 'FAILURE', 'DECLINED', 'ERROR'], true) => ClickPesaPayout::STATUS_FAILED,
            in_array($raw, ['REFUNDED', 'REFUND'], true) => ClickPesaPayout::STATUS_REFUNDED,
            in_array($raw, ['REVERSED', 'REVERSE'], true) => ClickPesaPayout::STATUS_REVERSED,
            in_array($raw, ['PROCESSING', 'INITIATED'], true) => ClickPesaPayout::STATUS_PROCESSING,
            default => ClickPesaPayout::STATUS_PENDING,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isPayoutEvent(array $payload, string $eventType): bool
    {
        $haystack = strtolower($eventType . ' ' . json_encode($payload));

        return str_contains($haystack, 'payout')
            || str_contains($haystack, 'disburs')
            || str_contains($haystack, 'transfer');
    }

    private function normalizeEventType(string $eventType, array $payload): string
    {
        $type = strtoupper(str_replace(['-', '.'], '_', trim($eventType)));
        if ($type === '' || $type === 'WEBHOOK') {
            $status = strtoupper((string) ($this->extractValue($payload, ['status', 'data.status']) ?: ''));
            if ($this->isPayoutEvent($payload, $status)) {
                return 'PAYOUT_' . $status;
            }
            if ($status !== '') {
                return 'PAYMENT_' . $status;
            }
        }

        return $type;
    }

    /**
     * @param string[] $candidates
     */
    private function eventMatches(string $normalized, array $candidates): bool
    {
        $n = strtoupper(str_replace(' ', '_', $normalized));
        foreach ($candidates as $c) {
            $c = strtoupper(str_replace(' ', '_', $c));
            if ($n === $c || str_ends_with($n, '_' . $c) || str_contains($n, $c)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redactPayoutPayload(array $payload): array
    {
        if (isset($payload['phoneNumber'])) {
            $payload['phoneNumber'] = ClickPesaSetting::maskPhone((string) $payload['phoneNumber']);
        }
        unset($payload['checksum']);

        return $payload;
    }

    public function normalizePhone(string $phone): string
    {
        return ClickPesaSetting::normalizePhoneStatic($phone);
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $paths
     * @return mixed|null
     */
    private function extractValue(array $data, array $paths)
    {
        foreach ($paths as $path) {
            $parts = explode('.', $path);
            $cursor = $data;
            $found = true;
            foreach ($parts as $part) {
                if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                    $found = false;
                    break;
                }
                $cursor = $cursor[$part];
            }
            if ($found && $cursor !== null && $cursor !== '') {
                return $cursor;
            }
        }

        return null;
    }

    private function normalizeDateParam(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            throw new BadRequestHttpException('Invalid date format. Use YYYY-MM-DD.');
        }

        return date('Y-m-d', $ts);
    }

    /**
     * @param list<array<string,mixed>> $payments
     * @return list<array{label:string,count:int,date:string}>
     */
    private function buildAnalyticsTrendDays(array $payments, int $numDays = 14): array
    {
        $numDays = max(1, min($numDays, 90));
        $start = strtotime('today midnight -' . ($numDays - 1) . ' days');
        $days = [];
        for ($i = 0; $i < $numDays; $i++) {
            $ts = strtotime('+' . $i . ' days', $start);
            $days[] = [
                'label' => date('j M', $ts),
                'count' => 0,
                'date' => date('Y-m-d', $ts),
            ];
        }

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }
            $ts = strtotime((string) ($payment['createdAt'] ?? ''));
            if ($ts === false) {
                continue;
            }
            $dayStart = strtotime('midnight', $ts);
            $diff = (int) round(($dayStart - $start) / 86400);
            if ($diff >= 0 && $diff < $numDays) {
                $days[$diff]['count']++;
            }
        }

        return $days;
    }

    private function buildAnalyticsPeriodLabel(?string $startDate, ?string $endDate, string $period): string
    {
        if ($startDate === null && $endDate === null) {
            return 'All time';
        }
        if ($startDate !== null && $endDate !== null) {
            return $startDate === $endDate ? $startDate : ($startDate . ' → ' . $endDate);
        }

        return match ($period) {
            'month' => 'This month',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
            default => 'All time',
        };
    }

    private function normalizeAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    private function normalizeStatementResponse(array $response, string $defaultCurrency): array
    {
        $transactions = $this->extractValue($response, ['transactions', 'statement', 'data.transactions', 'data.statement']);
        if (!is_array($transactions)) {
            $transactions = [];
        }

        $normalizedTransactions = [];
        foreach ($transactions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalizedTransactions[] = $this->normalizeStatementTransaction($row, $defaultCurrency);
        }

        return [
            'success' => true,
            'currency' => strtoupper((string) ($this->extractValue($response, ['currency', 'data.currency']) ?: $defaultCurrency)),
            'accountDetails' => [
                'openingBalance' => $this->normalizeAmount($this->extractValue($response, ['openingBalance', 'data.openingBalance', 'accountDetails.openingBalance'])),
                'closingBalance' => $this->normalizeAmount($this->extractValue($response, ['closingBalance', 'data.closingBalance', 'accountDetails.closingBalance'])),
                'totalCredits' => $this->normalizeAmount($this->extractValue($response, ['totalCredits', 'data.totalCredits', 'accountDetails.totalCredits'])),
                'totalDebits' => $this->normalizeAmount($this->extractValue($response, ['totalDebits', 'data.totalDebits', 'accountDetails.totalDebits'])),
            ],
            'transactions' => $normalizedTransactions,
        ];
    }

    private function normalizeStatementTransaction(array $row, string $defaultCurrency): array
    {
        $id = (string) ($this->extractValue($row, ['id', 'transactionId', 'clickpesaTransactionId']) ?: '');
        $reference = (string) ($this->extractValue($row, ['orderReference', 'billReference', 'reference']) ?: '');
        $controlNumber = (string) ($this->extractValue($row, ['controlNumber', 'billPayNumber']) ?: '');
        $amount = $this->normalizeAmount($this->extractValue($row, ['amount', 'credit', 'debit']));
        $balance = $this->normalizeAmount($this->extractValue($row, ['balance', 'runningBalance']));
        $date = (string) ($this->extractValue($row, ['date', 'transactionDate', 'createdAt']) ?: '');
        $description = (string) ($this->extractValue($row, ['description', 'narration', 'remarks']) ?: 'ClickPesa statement transaction');

        return [
            'id' => $id !== '' ? $id : sha1(json_encode($row, JSON_UNESCAPED_SLASHES)),
            'date' => $date,
            'description' => $description,
            'amount' => $amount,
            'balance' => $balance,
            'currency' => strtoupper((string) ($this->extractValue($row, ['currency']) ?: $defaultCurrency)),
            'orderReference' => $reference,
            'billReference' => $reference,
            'controlNumber' => $controlNumber !== '' ? $controlNumber : null,
            'status' => strtoupper((string) ($this->extractValue($row, ['status', 'paymentStatus']) ?: 'POSTED')),
        ];
    }

    private function buildStatementAnalytics(array $transactions): array
    {
        $moneyIn = 0.0;
        $success = 0;
        $pending = 0;
        $failed = 0;
        $recentCollections = [];

        foreach ($transactions as $row) {
            if (!is_array($row)) {
                continue;
            }

            $reference = (string) ($row['orderReference'] ?? $row['billReference'] ?? '');
            $controlNumber = (string) ($row['controlNumber'] ?? '');
            $matched = null;
            if ($reference !== '') {
                $matched = ClickPesaTransaction::findOne(['order_reference' => $reference]);
            }
            if ($matched === null && $controlNumber !== '') {
                $matched = ClickPesaTransaction::findOne(['control_number' => $controlNumber]);
            }

            if ($matched === null) {
                continue;
            }

            $amount = (float) ($matched->received_amount ?: $matched->expected_amount ?: $matched->amount);
            if ($matched->isPaymentSuccessful()) {
                $moneyIn += $amount;
                $success++;
            } elseif ($matched->payment_status === ClickPesaTransaction::STATUS_FAILED) {
                $failed++;
            } else {
                $pending++;
            }

            $recentCollections[] = [
                'id' => $matched->id,
                'orderId' => $matched->order_id,
                'orderReference' => $matched->order_reference,
                'controlNumber' => $matched->control_number,
                'amount' => $amount,
                'status' => $matched->payment_status,
                'createdAt' => $row['date'] ?? null,
            ];
        }

        return [
            'moneyIn' => round($moneyIn, 2),
            'success' => $success,
            'pending' => $pending,
            'failed' => $failed,
            'recentCollections' => array_slice($recentCollections, 0, 10),
        ];
    }

    private function upsertStatementTransaction(array $row): string
    {
        $reference = trim((string) ($row['orderReference'] ?? $row['billReference'] ?? ''));
        $controlNumber = trim((string) ($row['controlNumber'] ?? ''));
        $cpId = trim((string) ($row['id'] ?? ''));

        $tx = null;
        if ($cpId !== '') {
            $tx = ClickPesaTransaction::findOne(['clickpesa_transaction_id' => $cpId]);
        }
        if ($tx === null && $reference !== '') {
            $tx = ClickPesaTransaction::findOne(['order_reference' => $reference]);
        }
        if ($tx === null && $controlNumber !== '') {
            $tx = ClickPesaTransaction::findOne(['control_number' => $controlNumber]);
        }

        $isNew = $tx === null;
        if ($tx === null) {
            if ($reference === '' && $cpId === '') {
                return 'skipped';
            }

            $tx = new ClickPesaTransaction([
                'order_reference' => $reference !== '' ? $reference : ('STATEMENT-' . substr($cpId, 0, 20)),
                'transaction_type' => ClickPesaTransaction::TYPE_COLLECTION,
                'payment_status' => ClickPesaTransaction::STATUS_PENDING,
                'currency' => (string) ($row['currency'] ?? $this->getConfig()['currency']),
            ]);
        }

        $tx->clickpesa_transaction_id = $cpId !== '' ? $cpId : $tx->clickpesa_transaction_id;
        $tx->control_number = $controlNumber !== '' ? $controlNumber : $tx->control_number;
        $tx->description = (string) ($row['description'] ?? $tx->description);
        $tx->currency = (string) ($row['currency'] ?? $tx->currency ?: $this->getConfig()['currency']);
        $tx->amount = $this->normalizeAmount($row['amount'] ?? $tx->amount);
        $tx->expected_amount = $tx->expected_amount ?? $tx->amount;
        $tx->received_amount = $this->normalizeAmount($row['amount'] ?? $tx->received_amount);
        $tx->statement_balance = $this->normalizeAmount($row['balance'] ?? null);
        $tx->statement_date = (string) ($row['date'] ?? $tx->statement_date);
        $tx->sync_source = 'account_statement';
        $tx->channel = $tx->channel ?: 'statement';
        $tx->last_synced_at = time();
        $tx->raw_payload = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!$tx->save()) {
            $this->log('warning', 'Statement transaction save skipped', ['reference' => $tx->order_reference]);
            return 'skipped';
        }

        return $isNew ? 'inserted' : 'updated';
    }

    private function recordSyncLog(
        string $type,
        string $status,
        int $inserted,
        int $updated,
        int $skipped,
        string $message,
        array $metadata = []
    ): void {
        $log = new ClickPesaSyncLog([
            'sync_type' => $type,
            'status' => $status,
            'records_inserted' => $inserted,
            'records_updated' => $updated,
            'records_skipped' => $skipped,
            'message' => $message,
            'metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'synced_at' => time(),
        ]);
        $log->save(false);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        // Never log secrets
        unset(
            $context['apiKey'],
            $context['clientId'],
            $context['checksumKey'],
            $context['token'],
            $context['phone'],
            $context['phoneNumber']
        );

        $text = $message;
        if ($context !== []) {
            $text .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        Yii::getLogger()->log($text, match (strtolower($level)) {
            'error' => \yii\log\Logger::LEVEL_ERROR,
            'warning' => \yii\log\Logger::LEVEL_WARNING,
            default => \yii\log\Logger::LEVEL_INFO,
        }, self::LOG_CATEGORY);
    }
}
