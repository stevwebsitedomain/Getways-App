<?php

/**
 * Local smoke test for monitoring-api endpoints.
 * Usage: php scripts/test-monitoring-api.php [baseUrl] [apiKey]
 */

$base = rtrim($argv[1] ?? 'http://localhost/Getways-App/frontend/web', '/');
$key = $argv[2] ?? '';
$device = 'BOSS-PC-001';

if ($key === '') {
    fwrite(STDERR, "Usage: php scripts/test-monitoring-api.php [baseUrl] <apiKey>\n");
    exit(1);
}

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
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'code' => $code,
        'err' => $err,
        'raw' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

echo "Base: {$base}\n";

$check = monPost("{$base}/monitoring-api/check-device.php", $key, $device, [
    'device_id' => $device,
    'checked_at' => gmdate('c'),
]);
echo "check-device HTTP {$check['code']}: " . ($check['raw'] ?: $check['err']) . "\n";

$logs = monPost("{$base}/monitoring-api/receive-logs.php", $key, $device, [
    'device_id' => $device,
    'sent_at' => gmdate('c'),
    'logs' => [
        [
            'local_record_id' => 900001,
            'user_id' => 1,
            'username' => 'masaki',
            'action' => 'search_performed',
            'description' => 'Search: masaki',
            'endpoint' => '/api/airbnb/jobs',
            'request_method' => 'POST',
            'status' => 'ok',
            'results_count' => 3,
            'ip_address' => '127.0.0.1',
            'metadata' => ['query' => 'masaki'],
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ],
        [
            'local_record_id' => 900002,
            'username' => 'masaki',
            'action' => 'login_success',
            'description' => 'User logged in',
            'status' => 'ok',
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ],
    ],
]);
echo "receive-logs HTTP {$logs['code']}: " . ($logs['raw'] ?: $logs['err']) . "\n";

$dup = monPost("{$base}/monitoring-api/receive-logs.php", $key, $device, [
    'device_id' => $device,
    'logs' => [
        [
            'local_record_id' => 900001,
            'action' => 'search_performed',
            'description' => 'duplicate',
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ],
    ],
]);
echo "receive-logs duplicate HTTP {$dup['code']}: " . ($dup['raw'] ?: $dup['err']) . "\n";
