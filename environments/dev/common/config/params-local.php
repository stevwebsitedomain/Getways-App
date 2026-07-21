<?php

declare(strict_types=1);

/**
 * Example local params — copy values from ClickPesa dashboard.
 */
return [
    'clickpesa' => [
        'baseUrl' => getenv('CLICKPESA_API_BASE_URL') ?: 'https://api.clickpesa.com/third-parties',
        'clientId' => getenv('CLICKPESA_CLIENT_ID') ?: '',
        'apiKey' => getenv('CLICKPESA_API_KEY') ?: '',
        'checksumKey' => getenv('CLICKPESA_CHECKSUM_KEY') ?: '',
        'webhookToken' => getenv('CLICKPESA_WEBHOOK_TOKEN') ?: '',
        'encryptionKey' => getenv('CLICKPESA_ENCRYPTION_KEY') ?: '',
        'internalApiToken' => getenv('CLICKPESA_INTERNAL_API_TOKEN') ?: '',
        'currency' => 'TZS',
        'autoPayoutEnabled' => filter_var(getenv('CLICKPESA_AUTO_PAYOUT_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'autoPayoutPhone' => getenv('CLICKPESA_AUTO_PAYOUT_PHONE') ?: '255715296092',
        'autoPayoutPercentage' => (float) (getenv('CLICKPESA_AUTO_PAYOUT_PERCENTAGE') ?: 100),
        'autoPayoutMinimum' => (float) (getenv('CLICKPESA_AUTO_PAYOUT_MINIMUM_AMOUNT') ?: 1000),
        'autoPayoutDelay' => (int) (getenv('CLICKPESA_AUTO_PAYOUT_DELAY_SECONDS') ?: 60),
    ],
];
