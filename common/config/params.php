<?php

declare(strict_types=1);

return [
    'adminEmail' => 'admin@example.com',
    'supportEmail' => 'support@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
    'user.passwordResetTokenExpire' => 3600,
    'user.passwordMinLength' => 8,

    /**
     * ClickPesa API credentials.
     * Override in params-local.php or via environment variables.
     * Never put real keys in frontend JS or commit them to public repos.
     */
    'clickpesa' => [
        'baseUrl' => getenv('CLICKPESA_API_BASE_URL') ?: 'https://api.clickpesa.com/third-parties',
        'clientId' => getenv('CLICKPESA_CLIENT_ID') ?: '',
        'apiKey' => getenv('CLICKPESA_API_KEY') ?: '',
        'checksumKey' => getenv('CLICKPESA_CHECKSUM_KEY') ?: '',
        'webhookToken' => getenv('CLICKPESA_WEBHOOK_TOKEN') ?: '',
        'encryptionKey' => getenv('CLICKPESA_ENCRYPTION_KEY') ?: '',
        'internalApiToken' => getenv('CLICKPESA_INTERNAL_API_TOKEN') ?: '',
        'currency' => 'TZS',
        // Auto payout defaults (DB settings override at runtime). Keep disabled until tests pass.
        'autoPayoutEnabled' => filter_var(getenv('CLICKPESA_AUTO_PAYOUT_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'autoPayoutPhone' => getenv('CLICKPESA_AUTO_PAYOUT_PHONE') ?: '255715296092',
        'autoPayoutPercentage' => (float) (getenv('CLICKPESA_AUTO_PAYOUT_PERCENTAGE') ?: 100),
        'autoPayoutMinimum' => (float) (getenv('CLICKPESA_AUTO_PAYOUT_MINIMUM_AMOUNT') ?: 1000),
        'autoPayoutDelay' => (int) (getenv('CLICKPESA_AUTO_PAYOUT_DELAY_SECONDS') ?: 60),
    ],
];
