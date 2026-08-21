<?php

declare(strict_types=1);

/**
 * Server-side WhatsApp schedule (file queue) + Ultramsg send helper.
 * Used by whatsapp-api.php and whatsapp-cron.php so timed messages send
 * without the admin browser staying open.
 */

require_once __DIR__ . '/env-load.php';

function gwWhatsappSchedulePath(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'whatsapp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . DIRECTORY_SEPARATOR . 'schedule.json';
}

function gwWhatsappCronSecret(): string
{
    gwLoadEnv(true);
    $explicit = trim((string) (getenv('WA_CRON_SECRET') ?: ''));
    if ($explicit !== '') {
        return $explicit;
    }
    $token = trim((string) (getenv('ULTAMSG_TOKEN') ?: ''));

    return $token !== '' ? hash('sha256', 'gw-wa-cron|' . $token) : '';
}

/**
 * @return list<array{id:string,to:string,body:string,priority:string,at:int,createdAt:int}>
 */
function gwWhatsappReadSchedule(): array
{
    $path = gwWhatsappSchedulePath();
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
    $out = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $to = preg_replace('/\D+/', '', (string) ($row['to'] ?? '')) ?? '';
        $body = trim((string) ($row['body'] ?? ''));
        $at = (int) ($row['at'] ?? 0);
        if ($to === '' || strlen($to) < 9 || $body === '' || $at <= 0) {
            continue;
        }
        $out[] = [
            'id' => (string) ($row['id'] ?? ('wa_' . $to . '_' . $at)),
            'to' => $to,
            'body' => $body,
            'priority' => (string) ($row['priority'] ?? '10'),
            'at' => $at,
            'createdAt' => (int) ($row['createdAt'] ?? $at),
        ];
    }

    return $out;
}

/**
 * @param list<array{id?:string,to:string,body:string,priority?:string,at:int,createdAt?:int}> $items
 */
function gwWhatsappWriteSchedule(array $items): bool
{
    $path = gwWhatsappSchedulePath();
    $payload = json_encode(
        ['updatedAt' => time() * 1000, 'items' => array_values($items)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    if ($payload === false) {
        return false;
    }

    $fp = fopen($path, 'c+');
    if ($fp === false) {
        return false;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return false;
        }
        ftruncate($fp, 0);
        rewind($fp);
        $ok = fwrite($fp, $payload) !== false;
        fflush($fp);
        flock($fp, LOCK_UN);

        return $ok;
    } finally {
        fclose($fp);
    }
}

/**
 * Upsert by phone: one pending message per destination.
 *
 * @param list<array{to:string,body:string,priority?:string,at:int}> $incoming
 * @return list<array{id:string,to:string,body:string,priority:string,at:int,createdAt:int}>
 */
function gwWhatsappUpsertSchedule(array $incoming): array
{
    $now = (int) round(microtime(true) * 1000);
    $items = gwWhatsappReadSchedule();
    $byPhone = [];
    foreach ($items as $row) {
        $byPhone[$row['to']] = $row;
    }

    foreach ($incoming as $row) {
        if (!is_array($row)) {
            continue;
        }
        $to = preg_replace('/\D+/', '', (string) ($row['to'] ?? '')) ?? '';
        $body = trim((string) ($row['body'] ?? ''));
        $at = (int) ($row['at'] ?? 0);
        if ($to === '' || strlen($to) < 9 || $body === '' || $at <= 0) {
            continue;
        }
        $priority = trim((string) ($row['priority'] ?? '10'));
        if ($priority === '') {
            $priority = '10';
        }
        $byPhone[$to] = [
            'id' => 'wa_' . $to . '_' . $at . '_' . substr(md5($body), 0, 6),
            'to' => $to,
            'body' => $body,
            'priority' => $priority,
            'at' => $at,
            'createdAt' => $now,
        ];
    }

    $merged = array_values($byPhone);
    usort($merged, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
    gwWhatsappWriteSchedule($merged);

    return $merged;
}

/**
 * @return array{http:int,body:string,json:?array,error:string}
 */
function gwWhatsappUltamsgPost(string $url, array $fields): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['http' => 0, 'body' => 'curl_init failed', 'json' => null, 'error' => 'curl_init failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_USERAGENT => 'Getways-App-WhatsApp/1.0',
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['http' => $http, 'body' => $err !== '' ? $err : 'request failed', 'json' => null, 'error' => $err];
    }

    $json = json_decode((string) $body, true);

    return [
        'http' => $http,
        'body' => (string) $body,
        'json' => is_array($json) ? $json : null,
        'error' => $err,
    ];
}

/**
 * @return array{ok:bool,message:string,to:string,http:int,data:mixed}
 */
function gwWhatsappSendChat(string $to, string $body, string $priority = '10'): array
{
    $config = gwUltamsgConfig();
    if ($config['apiUrl'] === '' || $config['token'] === '') {
        return [
            'ok' => false,
            'message' => 'Ultamsg not configured.',
            'to' => $to,
            'http' => 0,
            'data' => null,
        ];
    }

    $to = preg_replace('/\D+/', '', $to) ?? '';
    $body = trim($body);
    $priority = trim($priority) !== '' ? trim($priority) : '10';
    if ($to === '' || strlen($to) < 9) {
        return ['ok' => false, 'message' => 'Invalid phone.', 'to' => $to, 'http' => 0, 'data' => null];
    }
    if ($body === '') {
        return ['ok' => false, 'message' => 'Empty body.', 'to' => $to, 'http' => 0, 'data' => null];
    }

    $sender = $config['senderName'];
    if ($sender !== '' && !str_starts_with($body, '*' . $sender . '*') && !str_starts_with($body, $sender)) {
        $body = '*' . $sender . "*\n\n" . $body;
    }

    $url = $config['apiUrl'] . '/messages/chat';
    $res = gwWhatsappUltamsgPost($url, [
        'token' => $config['token'],
        'to' => $to,
        'body' => $body,
        'priority' => $priority,
        'referenceId' => 'getway-scheduled',
    ]);

    $payload = $res['json'];
    if (is_array($payload) && isset($payload['error'])) {
        return [
            'ok' => false,
            'message' => (string) $payload['error'],
            'to' => $to,
            'http' => $res['http'],
            'data' => $payload,
        ];
    }

    if ($res['http'] >= 200 && $res['http'] < 300) {
        return [
            'ok' => true,
            'message' => 'Message submitted to Ultramsg.',
            'to' => $to,
            'http' => $res['http'],
            'data' => $payload ?? $res['body'],
        ];
    }

    return [
        'ok' => false,
        'message' => 'Ultramsg request failed.',
        'to' => $to,
        'http' => $res['http'],
        'data' => $payload ?? $res['body'],
    ];
}

/**
 * Send all due scheduled messages. Retries failed items after 60s.
 *
 * @return array{sent:int,failed:int,pending:int,results:list<array{to:string,ok:bool,message:string}>}
 */
function gwWhatsappProcessDueSchedules(int $limit = 25): array
{
    $now = (int) round(microtime(true) * 1000);
    $items = gwWhatsappReadSchedule();
    $keep = [];
    $results = [];
    $sent = 0;
    $failed = 0;
    $processed = 0;

    foreach ($items as $item) {
        if ($processed >= $limit) {
            $keep[] = $item;
            continue;
        }
        if ((int) $item['at'] > $now + 250) {
            $keep[] = $item;
            continue;
        }

        $processed++;
        $res = gwWhatsappSendChat($item['to'], $item['body'], $item['priority'] ?? '10');
        $results[] = [
            'to' => $item['to'],
            'ok' => $res['ok'],
            'message' => $res['message'],
        ];
        if ($res['ok']) {
            $sent++;
        } else {
            $failed++;
            $keep[] = array_merge($item, ['at' => $now + 60000]);
        }
    }

    gwWhatsappWriteSchedule($keep);

    return [
        'sent' => $sent,
        'failed' => $failed,
        'pending' => count($keep),
        'results' => $results,
    ];
}
