<?php

declare(strict_types=1);

namespace common\services;

use common\models\ClickPesaPayout;
use common\models\ClickPesaPayoutAuditLog;
use common\models\ClickPesaSetting;
use common\models\ClickPesaTransaction;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\httpclient\Client;
use yii\web\BadRequestHttpException;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * ClickPesa Mobile Money payout — same Autopay/USSD application credentials.
 *
 * Flow (no separate Payout API product / second API key):
 * 1) POST /generate-token  (client-id + api-key headers)
 * 2) POST /payouts/preview-mobile-money-payout  (Bearer token)
 * 3) POST /payouts/create-mobile-money-payout   (Bearer token)
 * 4) GET  /payouts/{orderReference}             (Bearer token)
 *
 * Default recipient: 255715296092
 */
class ClickPesaPayoutService extends Component
{
    private const LOG_CATEGORY = 'clickpesa';
    private const TOKEN_CACHE_KEY = 'clickpesa.payout.access_token.v1';
    private const TOKEN_LOCK_KEY = 'clickpesa.payout.token_refresh_lock';
    public const DEFAULT_RECIPIENT = '255715296092';

    /**
     * Prefer Autopay (USSD) Client ID / API Key — same app that collects via USSD push.
     * Falls back to CLICKPESA_* / CLIENT_ID only if Autopay is not configured.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $params = Yii::$app->params['clickpesa'] ?? [];

        $autopayId = (string) (getenv('AUTOPAY_CLIENT_ID') ?: '');
        $autopayKey = (string) (getenv('AUTOPAY_API_KEY') ?: '');
        if ($autopayId !== '' && $autopayKey !== '') {
            $params['clientId'] = $autopayId;
            $params['apiKey'] = $autopayKey;
            $checksum = (string) (getenv('AUTOPAY_CHECKSUM_KEY') ?: getenv('CHECKSUM_KEY') ?: ($params['checksumKey'] ?? ''));
            if ($checksum !== '') {
                $params['checksumKey'] = $checksum;
                $params['checksumSecret'] = $checksum;
            }
        }

        if (empty($params['defaultPayoutPhone'])) {
            $params['defaultPayoutPhone'] = self::DEFAULT_RECIPIENT;
        }

        return $params;
    }

    public function isTestMode(): bool
    {
        return (bool) ($this->getConfig()['payoutTestMode'] ?? true);
    }

    public function isPayoutEnvEnabled(): bool
    {
        return (bool) ($this->getConfig()['payoutEnabled'] ?? false);
    }

    /**
     * Production must fail safely when credentials are missing and payout is enabled.
     */
    public function assertProductionCredentials(): void
    {
        if (YII_ENV === 'dev') {
            return;
        }

        $config = $this->getConfig();
        if (!$this->isPayoutEnvEnabled()) {
            return;
        }

        if ($config['clientId'] === '' || $config['apiKey'] === '') {
            throw new InvalidConfigException(
                'Autopay/ClickPesa Client ID and API Key are required for Mobile Money payout (same credentials used for generate-token).'
            );
        }
    }

    public function generateToken(bool $forceRefresh = false): string
    {
        $refreshBefore = (int) ($this->getConfig()['tokenRefreshBeforeExpirySeconds'] ?? 300);

        if (!$forceRefresh) {
            $cached = Yii::$app->cache->get(self::TOKEN_CACHE_KEY);
            if (
                is_array($cached)
                && !empty($cached['token'])
                && (int) ($cached['expiresAt'] ?? 0) > time() + $refreshBefore
            ) {
                return (string) $cached['token'];
            }
        }

        $lock = Yii::$app->cache->get(self::TOKEN_LOCK_KEY);
        if ($lock && !$forceRefresh) {
            usleep(200_000);
            $cached = Yii::$app->cache->get(self::TOKEN_CACHE_KEY);
            if (is_array($cached) && !empty($cached['token'])) {
                return (string) $cached['token'];
            }
        }

        Yii::$app->cache->set(self::TOKEN_LOCK_KEY, 1, 30);

        try {
            $config = $this->getConfig();
            if ($config['clientId'] === '' || $config['apiKey'] === '') {
                throw new InvalidConfigException('ClickPesa clientId and apiKey must be configured.');
            }

            $client = $this->httpClient();
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
                $this->log('error', 'Payout token generation failed', ['status' => $response->statusCode]);
                throw new UnauthorizedHttpException('Failed to generate ClickPesa access token.');
            }

            $data = is_array($response->data) ? $response->data : [];
            $token = $this->extractValue($data, ['token', 'accessToken', 'access_token', 'data.token']);
            if (!$token) {
                throw new ServerErrorHttpException('ClickPesa token missing in response.');
            }

            $token = preg_replace('/^Bearer\s+/i', '', (string) $token) ?: (string) $token;
            $expiresIn = (int) ($this->extractValue($data, ['expiresIn', 'expires_in']) ?: 3600);
            $refreshBefore = (int) ($config['tokenRefreshBeforeExpirySeconds'] ?? 300);
            $ttl = max(60, $expiresIn - $refreshBefore);

            Yii::$app->cache->set(self::TOKEN_CACHE_KEY, [
                'token' => $token,
                'expiresAt' => time() + $ttl,
            ], $ttl);

            $this->log('info', 'ClickPesa payout token refreshed');

            return $token;
        } finally {
            Yii::$app->cache->delete(self::TOKEN_LOCK_KEY);
        }
    }

    public function getValidToken(): string
    {
        return $this->generateToken(false);
    }

    public function retrieveBalance(): array
    {
        $response = $this->request('GET', 'account/balance');
        $balance = $this->normalizeAmount($this->extractValue($response, [
            'balance', 'availableBalance', 'accountBalance', 'data.balance', 'data.availableBalance',
        ]));
        $currency = (string) ($this->extractValue($response, ['currency', 'data.currency']) ?: 'TZS');

        return [
            'success' => true,
            'currency' => strtoupper($currency),
            'balance' => $balance,
            'lastUpdated' => date('c'),
        ];
    }

    /**
     * @throws BadRequestHttpException
     */
    public function normalizePhoneNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/[\s\-\(\)\+]/', '', $phoneNumber) ?: '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '255' . substr($digits, 1);
        }
        if (strlen($digits) === 9 && !str_starts_with($digits, '255')) {
            $digits = '255' . $digits;
        }

        if (!preg_match('/^255\d{9}$/', $digits)) {
            throw new BadRequestHttpException(
                'Invalid Tanzanian mobile number. Expected format: 255XXXXXXXXX (12 digits).'
            );
        }

        return $digits;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function validatePayoutRequest(array $request): array
    {
        $amount = isset($request['amount']) ? (float) $request['amount'] : 0.0;
        if ($amount <= 0) {
            throw new BadRequestHttpException('Payout amount must be greater than zero.');
        }

        $phone = $this->normalizePhoneNumber((string) ($request['phoneNumber'] ?? $request['phone'] ?? ''));
        $currency = strtoupper((string) ($request['currency'] ?? 'TZS'));
        if ($currency !== 'TZS') {
            throw new BadRequestHttpException('Only TZS currency is supported.');
        }

        $orderReference = trim((string) ($request['orderReference'] ?? ''));
        if ($orderReference === '') {
            throw new BadRequestHttpException('orderReference is required.');
        }

        return [
            'amount' => round($amount, 2),
            'phoneNumber' => $phone,
            'currency' => $currency,
            'orderReference' => $orderReference,
        ];
    }

    public function generateOrderReference(?ClickPesaTransaction $transaction = null): string
    {
        $prefix = 'PAYOUT' . date('Ymd');
        $suffix = $transaction !== null
            ? (string) $transaction->id
            : (string) random_int(1000, 9999);
        $ref = $prefix . $suffix . random_int(100, 999);

        return preg_replace('/[^A-Za-z0-9]/', '', $ref) ?: ('PAYOUT' . time());
    }

    /**
     * @param mixed $payload
     */
    public function generateChecksum($payload): string
    {
        $config = $this->getConfig();
        $secret = (string) ($config['checksumSecret'] ?? $config['checksumKey'] ?? '');
        if ($secret === '') {
            throw new InvalidConfigException('CLICKPESA_CHECKSUM_SECRET is required when checksum is enabled.');
        }

        return $this->createPayloadChecksum($secret, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function previewPayout(array $payload): array
    {
        $validated = $this->validatePayoutRequest($payload);
        $apiPayload = $this->attachChecksum($validated);

        return $this->request('POST', 'payouts/preview-mobile-money-payout', $apiPayload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPayout(array $payload): array
    {
        $validated = $this->validatePayoutRequest($payload);

        if ($this->isTestMode()) {
            $this->log('info', 'TEST MODE — create payout skipped', [
                'orderReference' => $validated['orderReference'],
                'amount' => $validated['amount'],
                'phone' => ClickPesaSetting::maskPhone($validated['phoneNumber']),
            ]);

            return [
                'success' => true,
                'testMode' => true,
                'status' => ClickPesaPayout::STATUS_PENDING,
                'orderReference' => $validated['orderReference'],
                'message' => 'TEST MODE — no real funds transferred.',
                'amount' => $validated['amount'],
                'phoneNumber' => ClickPesaSetting::maskPhone($validated['phoneNumber']),
            ];
        }

        $this->assertProductionCredentials();
        $apiPayload = $this->attachChecksum($validated);

        return $this->request('POST', 'payouts/create-mobile-money-payout', $apiPayload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayoutStatus(string $orderReference): array
    {
        $orderReference = trim($orderReference);
        if ($orderReference === '') {
            throw new BadRequestHttpException('orderReference is required.');
        }

        $response = $this->request('GET', 'payouts/' . rawurlencode($orderReference));

        // ClickPesa may return an array of payout rows — prefer the matching/latest row.
        if (isset($response[0]) && is_array($response[0])) {
            $best = $response[0];
            foreach ($response as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ref = (string) ($row['orderReference'] ?? '');
                if ($ref === $orderReference) {
                    $best = $row;
                    break;
                }
            }

            return $best;
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getAllPayouts(array $filters = []): array
    {
        $query = array_filter([
            'startDate' => $filters['startDate'] ?? null,
            'endDate' => $filters['endDate'] ?? null,
            'status' => $filters['status'] ?? null,
            'channel' => $filters['channel'] ?? null,
            'currency' => $filters['currency'] ?? null,
            'orderReference' => $filters['orderReference'] ?? null,
            'page' => $filters['page'] ?? null,
            'limit' => $filters['limit'] ?? null,
            'sort' => $filters['sort'] ?? null,
        ], static fn($v): bool => $v !== null && $v !== '');

        $path = 'payouts/all';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $this->request('GET', $path);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyWebhook(array $payload, ?string $signatureOrChecksum): bool
    {
        $config = $this->getConfig();
        if (!($config['checksumEnabled'] ?? false)) {
            $webhookToken = (string) ($config['webhookToken'] ?? '');
            if ($webhookToken === '') {
                return true;
            }

            return is_string($signatureOrChecksum) && hash_equals($webhookToken, $signatureOrChecksum);
        }

        $secret = (string) ($config['checksumSecret'] ?? '');
        if ($secret === '' || !is_string($signatureOrChecksum) || $signatureOrChecksum === '') {
            return false;
        }

        $payloadForValidation = $payload;
        unset($payloadForValidation['checksum'], $payloadForValidation['checksumMethod']);
        $computed = $this->createPayloadChecksum($secret, $payloadForValidation);

        return hash_equals($computed, strtolower($signatureOrChecksum));
    }

    /**
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
     * Manual payout step 1: preview and store pending confirmation token.
     *
     * @return array<string, mixed>
     */
    public function initiateManualPayout(float $amount, ?string $phone = null, ?string $note = null, ?int $adminId = null): array
    {
        $settings = ClickPesaSetting::current();
        if ((bool) ($settings->emergency_stop ?? 0)) {
            throw new ForbiddenHttpException('Emergency stop is active. Payouts are disabled.');
        }

        $normalizedPhone = $this->normalizePhoneNumber(
            $phone ?: $settings->getDestinationPhone() ?: (string) ($this->getConfig()['defaultPayoutPhone'] ?? ClickPesaSetting::DEFAULT_PHONE)
        );

        $this->validateAmountAgainstSettings($amount, $settings);

        $balance = $this->retrieveBalance();
        $available = (float) ($balance['balance'] ?? 0);
        if ($available < $amount) {
            throw new BadRequestHttpException(
                sprintf('Insufficient wallet balance. Required: TZS %s, Available: TZS %s', number_format($amount, 2), number_format($available, 2))
            );
        }

        $orderReference = $this->generateOrderReference();
        $payload = [
            'amount' => $amount,
            'phoneNumber' => $normalizedPhone,
            'currency' => 'TZS',
            'orderReference' => $orderReference,
        ];

        $preview = $this->previewPayout($payload);
        $fee = $this->normalizeAmount($this->extractValue($preview, ['fee', 'charges', 'data.fee']));
        $totalDeduction = $amount + $fee;
        $provider = (string) ($this->extractValue($preview, ['provider', 'channel', 'data.provider', 'accountProvider']) ?: '');
        $recipientName = (string) ($this->extractValue($preview, ['accountName', 'recipientName', 'data.accountName']) ?: '');

        $previewToken = bin2hex(random_bytes(16));

        $payout = new ClickPesaPayout([
            'payment_id' => null,
            'payout_reference' => $orderReference,
            'destination_type' => ClickPesaSetting::DESTINATION_MOBILE,
            'destination_masked' => ClickPesaSetting::maskPhone($normalizedPhone),
            'phone_number' => $normalizedPhone,
            'amount' => $amount,
            'fee' => $fee > 0 ? $fee : null,
            'total_deduction' => $totalDeduction,
            'currency' => 'TZS',
            'provider' => $provider !== '' ? $provider : null,
            'channel' => 'MOBILE MONEY',
            'payout_status' => ClickPesaPayout::STATUS_PREVIEWED,
            'initiated_by' => $adminId !== null ? 'admin:' . $adminId : 'admin',
            'internal_note' => $note,
            'preview_token' => $previewToken,
            'raw_request' => json_encode($this->redactPayload($payload), JSON_UNESCAPED_SLASHES),
            'raw_response' => json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        if (!$payout->save()) {
            throw new ServerErrorHttpException('Failed to save payout preview record.');
        }

        ClickPesaPayoutAuditLog::record(
            $payout,
            'MANUAL_PREVIEW',
            null,
            ClickPesaPayout::STATUS_PREVIEWED,
            ClickPesaPayoutAuditLog::ACTOR_ADMIN,
            $adminId,
            ['amount' => $amount, 'fee' => $fee]
        );

        return [
            'success' => true,
            'previewToken' => $previewToken,
            'orderReference' => $orderReference,
            'recipientPhone' => '+' . $normalizedPhone,
            'recipientName' => $recipientName,
            'provider' => $provider,
            'amount' => $amount,
            'fee' => $fee,
            'totalDeduction' => $totalDeduction,
            'currency' => 'TZS',
            'testMode' => $this->isTestMode(),
            'payoutId' => $payout->id,
        ];
    }

    /**
     * Manual payout step 2: confirm and execute (idempotent).
     *
     * @return array<string, mixed>
     */
    public function confirmManualPayout(string $orderReference, string $previewToken, ?int $adminId = null): array
    {
        $payout = ClickPesaPayout::findOne(['payout_reference' => $orderReference]);
        if ($payout === null) {
            throw new NotFoundHttpException('Payout not found.');
        }

        if ($payout->isFinal()) {
            return ['success' => true, 'message' => 'Payout already finalized.', 'payout' => $payout->toAdminArray()];
        }

        if ($payout->payout_status !== ClickPesaPayout::STATUS_PREVIEWED) {
            throw new ConflictHttpException('Payout is not awaiting confirmation.');
        }

        if (!hash_equals((string) $payout->preview_token, $previewToken)) {
            throw new ForbiddenHttpException('Invalid confirmation token.');
        }

        $phone = (string) ($payout->phone_number ?: '');
        if ($phone === '') {
            throw new BadRequestHttpException('Recipient phone missing on payout record.');
        }

        $payload = [
            'amount' => (float) $payout->amount,
            'phoneNumber' => $phone,
            'currency' => $payout->currency ?: 'TZS',
            'orderReference' => $payout->payout_reference,
        ];

        $payout->payout_status = ClickPesaPayout::STATUS_PROCESSING;
        $payout->approved_by = $adminId;
        $payout->approved_at = time();
        $payout->preview_token = null;
        $payout->save(false);

        ClickPesaPayoutAuditLog::record(
            $payout,
            'MANUAL_CONFIRM',
            ClickPesaPayout::STATUS_PREVIEWED,
            ClickPesaPayout::STATUS_PROCESSING,
            ClickPesaPayoutAuditLog::ACTOR_ADMIN,
            $adminId
        );

        try {
            $response = $this->createPayout($payload);
            $mapped = $this->mapRemoteStatus($response);
            if ($mapped === ClickPesaPayout::STATUS_SUCCESS) {
                $mapped = ClickPesaPayout::STATUS_PENDING;
            }

            $payout->payout_status = $mapped;
            $payout->clickpesa_payout_id = (string) ($this->extractValue($response, ['id', 'payoutId', 'data.id']) ?: '') ?: null;
            $payout->raw_response = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payout->isFinal()) {
                $payout->completed_at = time();
                $payout->processed_at = time();
            }
            $payout->save(false);

            ClickPesaPayoutAuditLog::record(
                $payout,
                'CREATE_SENT',
                ClickPesaPayout::STATUS_PROCESSING,
                $payout->payout_status,
                ClickPesaPayoutAuditLog::ACTOR_ADMIN,
                $adminId,
                ['testMode' => $this->isTestMode()]
            );
        } catch (ConflictHttpException $e) {
            $remote = $this->getPayoutStatus($orderReference);
            $payout->payout_status = $this->mapRemoteStatus($remote);
            $payout->raw_response = json_encode($remote, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $payout->save(false);
        } catch (\Throwable $e) {
            $payout->payout_status = ClickPesaPayout::STATUS_FAILED;
            $payout->failure_reason = $e->getMessage();
            $payout->last_error = $e->getMessage();
            $payout->save(false);
            ClickPesaPayoutAuditLog::record(
                $payout,
                'CREATE_FAILED',
                ClickPesaPayout::STATUS_PROCESSING,
                ClickPesaPayout::STATUS_FAILED,
                ClickPesaPayoutAuditLog::ACTOR_ADMIN,
                $adminId,
                ['error' => $e->getMessage()]
            );
            throw $e;
        }

        return [
            'success' => true,
            'payout' => $payout->toAdminArray(),
            'testMode' => $this->isTestMode(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardSummary(): array
    {
        $settings = ClickPesaSetting::current();
        $todayStart = strtotime('today');

        $successful = (int) ClickPesaPayout::find()->where(['payout_status' => ClickPesaPayout::STATUS_SUCCESS])->count();
        $pending = (int) ClickPesaPayout::find()->where(['payout_status' => [
            ClickPesaPayout::STATUS_AUTHORIZED,
            ClickPesaPayout::STATUS_PENDING,
            ClickPesaPayout::STATUS_PROCESSING,
            ClickPesaPayout::STATUS_PREVIEWED,
            ClickPesaPayout::STATUS_QUEUED,
            ClickPesaPayout::STATUS_AWAITING_APPROVAL,
        ]])->count();
        $failed = (int) ClickPesaPayout::find()->where(['payout_status' => ClickPesaPayout::STATUS_FAILED])->count();
        $refunded = (int) ClickPesaPayout::find()->where(['payout_status' => ClickPesaPayout::STATUS_REFUNDED])->count();
        $reversed = (int) ClickPesaPayout::find()->where(['payout_status' => ClickPesaPayout::STATUS_REVERSED])->count();
        $totalFees = (float) ClickPesaPayout::find()
            ->where(['payout_status' => ClickPesaPayout::STATUS_SUCCESS])
            ->sum('fee');

        $todayTotal = (float) ClickPesaPayout::find()
            ->where(['>=', 'created_at', $todayStart])
            ->andWhere(['not in', 'payout_status', [ClickPesaPayout::STATUS_FAILED, ClickPesaPayout::STATUS_REVERSED]])
            ->sum('amount');

        $config = $this->getConfig();

        return [
            'success' => true,
            'automaticPayoutEnabled' => (bool) $settings->auto_payout_enabled,
            'emergencyStop' => (bool) ($settings->emergency_stop ?? 0),
            'testMode' => $this->isTestMode(),
            'payoutEnvEnabled' => $this->isPayoutEnvEnabled(),
            'credentialsConfigured' => $config['clientId'] !== '' && $config['apiKey'] !== '',
            'defaultRecipient' => '+' . ($settings->getDestinationPhone() ?: $config['defaultPayoutPhone']),
            'counts' => [
                'successful' => $successful,
                'pending' => $pending,
                'failed' => $failed,
                'refunded' => $refunded,
                'reversed' => $reversed,
            ],
            'totalFees' => round($totalFees, 2),
            'todayPayoutTotal' => round($todayTotal, 2),
            'dailyLimit' => (float) $settings->daily_limit,
        ];
    }

    /**
     * Apply approved state transition — final statuses cannot move backwards.
     */
    public function applyStatusTransition(ClickPesaPayout $payout, string $newStatus, string $actorType = ClickPesaPayoutAuditLog::ACTOR_SYSTEM): bool
    {
        $newStatus = strtoupper($newStatus);
        $oldStatus = $payout->payout_status;

        if ($payout->isFinal() && $newStatus !== $oldStatus) {
            $this->log('info', 'Ignored late status change on final payout', [
                'reference' => $payout->payout_reference,
                'from' => $oldStatus,
                'to' => $newStatus,
            ]);

            return false;
        }

        if ($newStatus === $oldStatus) {
            return false;
        }

        $payout->payout_status = $newStatus;
        if ($payout->isFinal()) {
            $payout->completed_at = time();
            $payout->processed_at = time();
        }
        $payout->save(false);

        ClickPesaPayoutAuditLog::record(
            $payout,
            'STATUS_CHANGE',
            $oldStatus,
            $newStatus,
            $actorType
        );

        return true;
    }

    /**
     * @param array<string, mixed> $remote
     */
    public function mapRemoteStatus(array $remote): string
    {
        $raw = strtoupper((string) ($this->extractValue($remote, ['status', 'payoutStatus', 'data.status']) ?: 'PENDING'));

        return match (true) {
            in_array($raw, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'PAID', 'SETTLED'], true) => ClickPesaPayout::STATUS_SUCCESS,
            in_array($raw, ['FAILED', 'FAILURE', 'DECLINED', 'ERROR'], true) => ClickPesaPayout::STATUS_FAILED,
            in_array($raw, ['REFUNDED', 'REFUND'], true) => ClickPesaPayout::STATUS_REFUNDED,
            in_array($raw, ['REVERSED', 'REVERSE'], true) => ClickPesaPayout::STATUS_REVERSED,
            in_array($raw, ['AUTHORIZED', 'AUTHORISED'], true) => ClickPesaPayout::STATUS_AUTHORIZED,
            in_array($raw, ['PROCESSING', 'INITIATED'], true) => ClickPesaPayout::STATUS_PROCESSING,
            default => ClickPesaPayout::STATUS_PENDING,
        };
    }

    private function validateAmountAgainstSettings(float $amount, ClickPesaSetting $settings): void
    {
        $min = (float) $settings->minimum_amount;
        $max = (float) ($settings->maximum_amount ?? 0);
        if ($min > 0 && $amount < $min) {
            throw new BadRequestHttpException(sprintf('Amount below minimum automatic payout (TZS %s).', number_format($min, 2)));
        }
        if ($max > 0 && $amount > $max) {
            throw new BadRequestHttpException(sprintf('Amount exceeds maximum payout limit (TZS %s).', number_format($max, 2)));
        }

        $limit = (float) $settings->daily_limit;
        if ($limit > 0) {
            $todayStart = strtotime('today');
            $sum = (float) ClickPesaPayout::find()
                ->where(['>=', 'created_at', $todayStart])
                ->andWhere(['not in', 'payout_status', [ClickPesaPayout::STATUS_FAILED, ClickPesaPayout::STATUS_REVERSED]])
                ->sum('amount');
            if (($sum + $amount) > $limit) {
                throw new BadRequestHttpException('Daily payout limit would be exceeded.');
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attachChecksum(array $payload): array
    {
        $config = $this->getConfig();
        if (!($config['checksumEnabled'] ?? false)) {
            if (($config['checksumKey'] ?? '') !== '') {
                $body = $payload;
                unset($body['checksum'], $body['checksumMethod']);
                $payload['checksum'] = $this->createPayloadChecksum((string) $config['checksumKey'], $body);
                $payload['checksumMethod'] = 'canonical';
            }

            return $payload;
        }

        $body = $payload;
        unset($body['checksum'], $body['checksumMethod']);
        $payload['checksum'] = $this->generateChecksum($body);
        $payload['checksumMethod'] = 'canonical';

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, bool $retryOnUnauthorized = true): array
    {
        $token = $this->getValidToken();
        $timeout = (int) ($this->getConfig()['httpTimeoutSeconds'] ?? 30);

        $client = $this->httpClient($timeout);
        $request = $client->createRequest()
            ->setMethod($method)
            ->setUrl(ltrim($path, '/'))
            ->setHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);

        if ($body !== null) {
            $request->setData($body);
        }

        $response = $request->send();
        $data = is_array($response->data) ? $response->data : [];

        $this->log('info', 'ClickPesa payout API', [
            'method' => $method,
            'path' => $path,
            'status' => $response->statusCode,
        ]);

        if (!$response->isOk) {
            $message = (string) ($this->extractValue($data, ['message', 'error']) ?: 'ClickPesa payout request failed');
            $code = (int) $response->statusCode;

            if ($code === 401) {
                Yii::$app->cache->delete(self::TOKEN_CACHE_KEY);
                if ($retryOnUnauthorized) {
                    return $this->request($method, $path, $body, false);
                }
                throw new UnauthorizedHttpException($message);
            }
            if ($code === 403) {
                throw new ForbiddenHttpException($message);
            }
            if ($code === 409) {
                throw new ConflictHttpException($message);
            }
            if ($code === 404) {
                throw new NotFoundHttpException($message);
            }
            if ($code >= 400 && $code < 500) {
                throw new BadRequestHttpException($message);
            }

            throw new ServerErrorHttpException($message);
        }

        return $data;
    }

    private function httpClient(int $timeout = 30): Client
    {
        $config = $this->getConfig();
        $base = rtrim((string) ($config['baseUrl'] ?? 'https://api.clickpesa.com/third-parties'), '/') . '/';

        return new Client([
            'baseUrl' => $base,
            'requestConfig' => [
                'format' => Client::FORMAT_JSON,
                'options' => [
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                ],
            ],
            'responseConfig' => ['format' => Client::FORMAT_JSON],
        ]);
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
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload): array
    {
        if (isset($payload['phoneNumber'])) {
            $payload['phoneNumber'] = ClickPesaSetting::maskPhone((string) $payload['phoneNumber']);
        }
        unset($payload['checksum']);

        return $payload;
    }

    private function normalizeAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $paths
     */
    private function extractValue(array $data, array $paths): mixed
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

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        Yii::$level($message . ($context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''), self::LOG_CATEGORY);
    }
}
