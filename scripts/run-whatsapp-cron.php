<?php

declare(strict_types=1);

/**
 * CLI / Task Scheduler entry for due WhatsApp schedules.
 *
 * Windows (Task Scheduler every 1 min):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Getways-App\scripts\run-whatsapp-cron.php
 *
 * Linux cron every minute:
 *   * * * * * php /path/to/Getways-App/scripts/run-whatsapp-cron.php >/dev/null 2>&1
 *
 * Or HTTP (host cron):
 *   curl -fsS "https://YOUR-DOMAIN/whatsapp-cron.php?key=YOUR_WA_CRON_SECRET"
 */

$web = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'web';
require_once $web . DIRECTORY_SEPARATOR . 'whatsapp-schedule-lib.php';

$result = gwWhatsappProcessDueSchedules(50);
$line = sprintf(
    "[%s] sent=%d failed=%d pending=%d\n",
    gmdate('c'),
    $result['sent'],
    $result['failed'],
    $result['pending']
);

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, $line);
    foreach ($result['results'] as $row) {
        fwrite(STDOUT, sprintf("  - %s %s (%s)\n", $row['ok'] ? 'OK' : 'FAIL', $row['to'], $row['message']));
    }
    exit($result['failed'] > 0 && $result['sent'] === 0 ? 1 : 0);
}

header('Content-Type: text/plain; charset=UTF-8');
echo $line;
