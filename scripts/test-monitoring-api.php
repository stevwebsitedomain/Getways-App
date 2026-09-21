<?php
/**
 * Local smoke test for monitoring-api HMAC endpoints.
 * Usage: php scripts/test-monitoring-api.php [baseUrl]
 */
$base = rtrim($argv[1] ?? 'http://localhost/Getways-App/frontend/web', '/');
$key = 'qbp385A4STrK6hRattuLqI7NM2peQNVHLACwJ3go';
$device = 'BOSS-PC-001';

function monPost(string $url, string $key, string $device, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ts = (string) time();
    $sig = hash_hmac('sha256', $ts . '.' . $body, $key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'X-Device-ID: ' . $device,
            'X-Timestamp: ' . $ts,
            'X-Signature: ' . $sig,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'err' => $err, 'raw' => $raw];
}

$check = monPost($base . '/monitoring-api/check-device.php', $key, $device, [
    'device_id' => $device,
    'device_uuid' => 'uuid-here',
    'checked_at' => gmdate('c'),
]);
echo "check-device HTTP {$check['code']}\n{$check['raw']}\n\n";

$recv = monPost($base . '/monitoring-api/receive-logs.php', $key, $device, [
    'device_id' => $device,
    'device_uuid' => 'uuid-here',
    'sent_at' => gmdate('c'),
    'logs' => [[
        'local_id' => 123,
        'user_id' => null,
        'username' => 'masaki',
        'action' => 'search_performed',
        'description' => 'Search: masaki',
        'endpoint' => '/api/airbnb/jobs',
        'request_method' => 'POST',
        'status' => 'ok',
        'ip_address' => '127.0.0.1',
        'device_id' => $device,
        'metadata' => ['query' => 'masaki'],
        'created_at' => '2026-09-21 12:00:00',
    ]],
]);
echo "receive-logs HTTP {$recv['code']}\n{$recv['raw']}\n";
