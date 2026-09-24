<?php

declare(strict_types=1);

/**
 * Meseji SMS adapter for monitoring search alerts.
 * Credentials only from .env — never returned to the browser.
 */

require_once dirname(__DIR__) . '/env-load.php';

/**
 * @return array{
 *   enabled:bool,
 *   recipient:string,
 *   provider:string,
 *   apiUrl:string,
 *   apiKey:string,
 *   senderId:string,
 *   message:string
 * }
 */
function monSmsConfig(): array
{
    gwLoadEnv();
    $enabledRaw = strtolower(trim((string) (getenv('SMS_ENABLED') ?: 'false')));
    return [
        'enabled' => in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true),
        'recipient' => trim((string) (getenv('SMS_RECIPIENT') ?: '+255622045972')),
        'provider' => trim((string) (getenv('SMS_PROVIDER') ?: 'meseji')),
        'apiUrl' => trim((string) (getenv('SMS_API_URL') ?: 'https://meseji.co.tz/api/v1/sms/send')),
        'apiKey' => trim((string) (getenv('SMS_API_KEY') ?: '')),
        'senderId' => trim((string) (getenv('SMS_SENDER_ID') ?: '')),
        'message' => trim((string) (getenv('SMS_MESSAGE') ?: 'System inatumika. Ingia kwenye system kuona zaidi.')),
    ];
}

/**
 * Ensure SMS settings row exists (admin toggles / mode).
 */
function monEnsureSmsSettings(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS monitoring_sms_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            recipient VARCHAR(30) NOT NULL DEFAULT '+255622045972',
            mode ENUM('per_search','summary_5','summary_10') NOT NULL DEFAULT 'per_search',
            last_summary_sent_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $stmt = $pdo->query('SELECT id FROM monitoring_sms_settings WHERE id = 1 LIMIT 1');
    if (!$stmt->fetch()) {
        $cfg = monSmsConfig();
        $ins = $pdo->prepare(
            'INSERT INTO monitoring_sms_settings (id, enabled, recipient, mode, updated_at) VALUES (1, ?, ?, ?, ?)'
        );
        $ins->execute([
            $cfg['enabled'] ? 1 : 0,
            $cfg['recipient'] !== '' ? $cfg['recipient'] : '+255622045972',
            'per_search',
            gmdate('Y-m-d H:i:s'),
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function monSmsSettings(PDO $pdo): array
{
    monEnsureSmsSettings($pdo);
    $row = $pdo->query('SELECT * FROM monitoring_sms_settings WHERE id = 1 LIMIT 1')->fetch();
    return is_array($row) ? $row : [
        'enabled' => 1,
        'recipient' => '+255622045972',
        'mode' => 'per_search',
        'last_summary_sent_at' => null,
    ];
}

function monNormalizePhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0') && strlen($digits) === 10) {
        $digits = '255' . substr($digits, 1);
    }
    if (str_starts_with($digits, '255') && strlen($digits) === 12) {
        return '+' . $digits;
    }
    if ($phone !== '' && str_starts_with($phone, '+')) {
        return $phone;
    }
    return $digits !== '' ? '+' . $digits : $phone;
}

/**
 * Send SMS via meseji.co.tz. Returns provider result; never throws to caller for business flow.
 *
 * @return array{ok:bool, message_id:?string, status:string, response:?string, error:?string}
 */
function monSmsSendMeseji(string $recipient, string $message): array
{
    $cfg = monSmsConfig();
    if ($cfg['apiKey'] === '' || $cfg['apiUrl'] === '') {
        return [
            'ok' => false,
            'message_id' => null,
            'status' => 'config_missing',
            'response' => null,
            'error' => 'SMS API not configured.',
        ];
    }

    $to = monNormalizePhone($recipient);
    $digits = ltrim($to, '+');
    $sender = $cfg['senderId'] !== '' ? $cfg['senderId'] : 'MESEJI';
    $payload = [
        'sender_id' => $sender,
        'message' => $message,
        'contacts' => $digits,
    ];

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ch = curl_init($cfg['apiUrl']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-api-key: ' . $cfg['apiKey'],
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (!is_string($raw)) {
        return [
            'ok' => false,
            'message_id' => null,
            'status' => 'failed',
            'response' => null,
            'error' => $err !== '' ? $err : 'Empty SMS response',
        ];
    }

    $json = json_decode($raw, true);
    $messageId = null;
    if (is_array($json)) {
        $messageId = $json['message_id']
            ?? $json['messageId']
            ?? ($json['data']['messageIds'][0] ?? null)
            ?? ($json['data']['batchId'] ?? null)
            ?? ($json['id'] ?? null);
        if (is_array($messageId)) {
            $messageId = null;
        }
        $messageId = $messageId !== null ? (string) $messageId : null;
    }

    $ok = $code >= 200 && $code < 300;
    if (is_array($json) && array_key_exists('success', $json)) {
        $ok = $ok && !empty($json['success']);
    }
    if (is_array($json) && array_key_exists('status', $json) && is_bool($json['status'])) {
        $ok = $ok && $json['status'] === true;
    }

    return [
        'ok' => $ok,
        'message_id' => $messageId,
        'status' => $ok ? 'sent' : 'failed',
        'response' => substr($raw, 0, 4000),
        'error' => $ok ? null : ($err !== '' ? $err : ('HTTP ' . $code)),
    ];
}

/**
 * Queue/send alert for a newly stored search. Duplicate remote_search_id never sends twice.
 */
function monSmsAlertForSearch(PDO $pdo, string $deviceId, int $remoteSearchId): void
{
    try {
        monEnsureSmsSettings($pdo);
        $settings = monSmsSettings($pdo);
        $cfg = monSmsConfig();
        $recipient = monNormalizePhone(
            trim((string) ($settings['recipient'] ?? '')) !== ''
                ? (string) $settings['recipient']
                : $cfg['recipient']
        );
        $message = $cfg['message'] !== ''
            ? $cfg['message']
            : 'System inatumika. Ingia kwenye system kuona zaidi.';
        $now = gmdate('Y-m-d H:i:s');

        // Unique insert — duplicate sync cannot create a second SMS row.
        $ins = $pdo->prepare(
            'INSERT INTO sms_alert_logs
            (device_id, remote_search_id, recipient, message, delivery_status, created_at)
            VALUES (?, ?, ?, ?, ?, ?)'
        );
        try {
            $ins->execute([$deviceId, $remoteSearchId, $recipient, $message, 'pending', $now]);
        } catch (Throwable $e) {
            // Duplicate unique key → already alerted for this search
            return;
        }
        $logId = (int) $pdo->lastInsertId();

        $enabled = !empty($settings['enabled']) && $cfg['enabled'];
        if (!$enabled) {
            $upd = $pdo->prepare(
                'UPDATE sms_alert_logs SET delivery_status = ?, error_message = ?, sent_at = ? WHERE id = ?'
            );
            $upd->execute(['disabled', 'SMS alerts disabled.', $now, $logId]);
            return;
        }

        $mode = (string) ($settings['mode'] ?? 'per_search');
        if ($mode === 'summary_5' || $mode === 'summary_10') {
            $minutes = $mode === 'summary_5' ? 5 : 10;
            $last = $settings['last_summary_sent_at'] ?? null;
            $lastTs = $last ? strtotime((string) $last) : false;
            if ($lastTs !== false && (time() - $lastTs) < ($minutes * 60)) {
                $upd = $pdo->prepare(
                    'UPDATE sms_alert_logs SET delivery_status = ?, error_message = ?, sent_at = ? WHERE id = ?'
                );
                $upd->execute([
                    'skipped_cooldown',
                    "Summary cooldown {$minutes}m active.",
                    $now,
                    $logId,
                ]);
                return;
            }
        }

        $result = monSmsSendMeseji($recipient, $message);
        $upd = $pdo->prepare(
            'UPDATE sms_alert_logs
             SET provider_message_id = ?, delivery_status = ?, provider_response = ?, error_message = ?, sent_at = ?
             WHERE id = ?'
        );
        $upd->execute([
            $result['message_id'],
            $result['status'],
            $result['response'],
            $result['error'],
            $now,
            $logId,
        ]);

        if ($result['ok'] && ($mode === 'summary_5' || $mode === 'summary_10')) {
            $pdo->prepare('UPDATE monitoring_sms_settings SET last_summary_sent_at = ?, updated_at = ? WHERE id = 1')
                ->execute([$now, $now]);
        }
    } catch (Throwable $e) {
        error_log('Monitoring SMS alert failed: ' . $e->getMessage());
    }
}
