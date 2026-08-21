<?php

declare(strict_types=1);

/**
 * Process due WhatsApp schedules without an admin browser session.
 *
 * Secure with WA_CRON_SECRET (or derived from ULTAMSG_TOKEN).
 * Call every 1–2 minutes via host cron / Task Scheduler, e.g.:
 *   curl -fsS "https://YOUR-DOMAIN.com/whatsapp-cron.php?key=YOUR_SECRET"
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/whatsapp-schedule-lib.php';

function waCronJson(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$secret = gwWhatsappCronSecret();
if ($secret === '') {
    waCronJson(500, [
        'ok' => false,
        'message' => 'Cron secret not configured. Set WA_CRON_SECRET or ULTAMSG_TOKEN in .env',
    ]);
}

$provided = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
if ($provided === '') {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^\s*Bearer\s+(.+)$/i', $auth, $m)) {
        $provided = trim($m[1]);
    }
}

if ($provided === '' || !hash_equals($secret, $provided)) {
    waCronJson(401, ['ok' => false, 'message' => 'Unauthorized.']);
}

$limit = min(50, max(1, (int) ($_GET['limit'] ?? 25)));
$result = gwWhatsappProcessDueSchedules($limit);

waCronJson(200, [
    'ok' => true,
    'message' => 'Schedule tick complete.',
    'sent' => $result['sent'],
    'failed' => $result['failed'],
    'pending' => $result['pending'],
    'results' => $result['results'],
    'at' => time(),
]);
