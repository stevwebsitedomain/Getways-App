<?php

declare(strict_types=1);

/**
 * Load project-root .env into getenv() for standalone frontend PHP pages.
 */
function gwLoadEnv(bool $forceUltamsg = false): void
{
    static $loaded = false;
    if ($loaded && !$forceUltamsg) {
        return;
    }

    $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $refresh = $forceUltamsg && (str_starts_with($name, 'ULTAMSG_') || $name === 'BASE_URL');
        if (getenv($name) !== false && !$refresh) {
            continue;
        }
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $loaded = true;
}

/**
 * @return array{
 *   instanceId:string,
 *   token:string,
 *   apiUrl:string,
 *   senderName:string,
 *   webhookUrl:string
 * }
 */
function gwUltamsgConfig(): array
{
    gwLoadEnv(true);
    $instanceId = trim((string) (getenv('ULTAMSG_INSTANCE_ID') ?: ''));
    $token = trim((string) (getenv('ULTAMSG_TOKEN') ?: ''));
    $apiUrl = rtrim(trim((string) (getenv('ULTAMSG_API_URL') ?: '')), '/');
    $senderName = trim((string) (getenv('ULTAMSG_SENDER_NAME') ?: 'Digital Matrix Technology'));
    $webhookUrl = trim((string) (getenv('ULTAMSG_WEBHOOK_PUBLIC_URL') ?: ''));

    if ($apiUrl === '' && $instanceId !== '') {
        $apiUrl = 'https://api.ultramsg.com/' . $instanceId;
    }

    if ($webhookUrl === '') {
        // Prefer production PHP host used by ClickPesa webhooks when set.
        $clickpesaHook = trim((string) (getenv('CLICKPESA_WEBHOOK_URL') ?: ''));
        if ($clickpesaHook !== '' && preg_match('#^(https?://[^/]+)#i', $clickpesaHook, $m)) {
            $webhookUrl = $m[1] . '/whatsapp-webhook.php';
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            if ($dir === '/' || $dir === '\\') {
                $dir = '';
            }
            // When called from whatsapp-send.php, dirname is the web root folder.
            $webhookUrl = $scheme . '://' . $host . $dir . '/whatsapp-webhook.php';
        }
    }

    return [
        'instanceId' => $instanceId,
        'token' => $token,
        'apiUrl' => $apiUrl,
        'senderName' => $senderName !== '' ? $senderName : 'Digital Matrix Technology',
        'webhookUrl' => $webhookUrl,
    ];
}

function gwWhatsappWebhookLogPath(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'whatsapp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . DIRECTORY_SEPARATOR . 'webhook-events.jsonl';
}
