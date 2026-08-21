<?php

declare(strict_types=1);

/**
 * Resolves ClickPesa env vars with spec names and legacy aliases.
 *
 * @return array<string, mixed>
 */
function clickpesaEnvConfig(): array
{
    $env = static function (string $primary, ?string $fallback = null, string $default = ''): string {
        $value = getenv($primary);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }
        if ($fallback !== null) {
            $legacy = getenv($fallback);
            if ($legacy !== false && $legacy !== '') {
                return (string) $legacy;
            }
        }

        return $default;
    };

    $envBool = static function (string $primary, ?string $fallback = null, bool $default = false): bool {
        $raw = getenv($primary);
        if ($raw === false && $fallback !== null) {
            $raw = getenv($fallback);
        }
        if ($raw === false) {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    };

    $baseUrl = rtrim($env('CLICKPESA_BASE_URL', 'CLICKPESA_API_BASE_URL', 'https://api.clickpesa.com/third-parties'), '/');
    if (!str_ends_with($baseUrl, 'third-parties')) {
        $baseUrl = rtrim($baseUrl, '/') . '/third-parties';
    }

    $checksumEnabled = $envBool('CLICKPESA_CHECKSUM_ENABLED', null, false);
    $checksumSecret = $env('CLICKPESA_CHECKSUM_SECRET', 'CLICKPESA_CHECKSUM_KEY', '');

    return [
        'baseUrl' => $baseUrl,
        'clientId' => $env('CLICKPESA_CLIENT_ID', 'CLIENT_ID', ''),
        'apiKey' => $env('CLICKPESA_API_KEY', 'API_KEY', ''),
        'checksumEnabled' => $checksumEnabled,
        'checksumKey' => $checksumEnabled ? $checksumSecret : $env('CLICKPESA_CHECKSUM_SECRET', 'CLICKPESA_CHECKSUM_KEY', ''),
        'checksumSecret' => $checksumSecret,
        'webhookUrl' => $env('CLICKPESA_WEBHOOK_URL', null, ''),
        'webhookToken' => $env('CLICKPESA_WEBHOOK_TOKEN', null, ''),
        'encryptionKey' => $env('CLICKPESA_ENCRYPTION_KEY', null, ''),
        'internalApiToken' => $env('CLICKPESA_INTERNAL_API_TOKEN', null, ''),
        'currency' => 'TZS',
        'payoutEnabled' => $envBool('CLICKPESA_PAYOUT_ENABLED', 'CLICKPESA_AUTO_PAYOUT_ENABLED', false),
        'payoutTestMode' => $envBool('CLICKPESA_PAYOUT_TEST_MODE', null, true),
        'defaultPayoutPhone' => $env('CLICKPESA_DEFAULT_PAYOUT_PHONE', 'CLICKPESA_AUTO_PAYOUT_PHONE', '255765149991'),
        'httpTimeoutSeconds' => max(5, (int) $env('CLICKPESA_HTTP_TIMEOUT_SECONDS', null, '30')),
        'tokenRefreshBeforeExpirySeconds' => max(60, (int) $env('CLICKPESA_TOKEN_REFRESH_BEFORE_EXPIRY_SECONDS', null, '300')),
        // Legacy keys kept for backward compatibility
        'autoPayoutEnabled' => $envBool('CLICKPESA_PAYOUT_ENABLED', 'CLICKPESA_AUTO_PAYOUT_ENABLED', false),
        'autoPayoutPhone' => $env('CLICKPESA_DEFAULT_PAYOUT_PHONE', 'CLICKPESA_AUTO_PAYOUT_PHONE', '255765149991'),
        'autoPayoutPercentage' => (float) $env('CLICKPESA_AUTO_PAYOUT_PERCENTAGE', null, '100'),
        'autoPayoutMinimum' => (float) $env('CLICKPESA_AUTO_PAYOUT_MINIMUM_AMOUNT', null, '1000'),
        'autoPayoutDelay' => (int) $env('CLICKPESA_AUTO_PAYOUT_DELAY_SECONDS', null, '60'),
    ];
}

return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,

    /**
     * ClickPesa API credentials — loaded from .env via EnvBootstrap or server env.
     * Never put real keys in frontend JS or commit them to public repos.
     */
    'clickpesa' => clickpesaEnvConfig(),
];
