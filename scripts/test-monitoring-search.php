<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/frontend/web/env-load.php';
require_once $root . '/frontend/web/monitoring-api/_lib.php';
require_once $root . '/frontend/web/monitoring-api/mon-sms.php';

gwLoadEnv();
$pdo = monPdo();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'TABLES ' . implode(',', $tables) . PHP_EOL;

$key = $argv[2] ?? '';
$device = 'BOSS-PC-001';
$base = rtrim($argv[1] ?? 'http://localhost/Getways-App/frontend/web', '/');
if ($key === '') {
    fwrite(STDERR, "Usage: php scripts/test-monitoring-search.php [baseUrl] <apiKey>\n");
    exit(1);
}

function postJson(string $url, string $key, string $device, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'X-Device-ID: ' . $device,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 40,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'raw' => is_string($raw) ? $raw : $err];
}

$localId = 880001;
$first = postJson($base . '/monitoring-api/receive-search.php', $key, $device, [
    'device_id' => $device,
    'local_search_id' => $localId,
    'username' => 'masaki',
    'search_term' => 'monitoring test',
    'status' => 'completed',
    'results_count' => 1,
    'searched_at' => gmdate('Y-m-d H:i:s'),
    'filters' => ['city' => 'Dar'],
    'results' => [[
        'local_result_id' => 1,
        'title' => 'Sample',
        'full_name' => 'Test User',
        'phone' => '255700000000',
    ]],
]);
echo "SEARCH1 {$first['code']} {$first['raw']}\n";

$second = postJson($base . '/monitoring-api/receive-search.php', $key, $device, [
    'device_id' => $device,
    'local_search_id' => $localId,
    'username' => 'masaki',
    'search_term' => 'monitoring test',
    'status' => 'completed',
    'results_count' => 1,
    'searched_at' => gmdate('Y-m-d H:i:s'),
]);
echo "SEARCH2 {$second['code']} {$second['raw']}\n";

$dl = postJson($base . '/monitoring-api/receive-download.php', $key, $device, [
    'device_id' => $device,
    'local_download_id' => 770001,
    'username' => 'masaki',
    'file_name' => 'results.csv',
    'file_type' => 'csv',
    'file_size' => 120,
    'rows_count' => 1,
    'search_id' => $localId,
    'downloaded_at' => gmdate('Y-m-d H:i:s'),
    'records' => [['name' => 'Test User']],
]);
echo "DOWNLOAD {$dl['code']} {$dl['raw']}\n";

$check = postJson($base . '/monitoring-api/check-device.php', $key, $device, [
    'device_id' => $device,
    'control_version_applied' => 1,
]);
echo "CHECK {$check['code']} {$check['raw']}\n";

$stmt = $pdo->prepare('SELECT COUNT(*) FROM sms_alert_logs WHERE device_id = ? AND remote_search_id = (SELECT id FROM remote_searches WHERE device_id = ? AND local_search_id = ?)');
$stmt->execute([$device, $device, $localId]);
echo 'SMS_ROWS ' . (int) $stmt->fetchColumn() . PHP_EOL;
