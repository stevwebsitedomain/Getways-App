<?php
declare(strict_types=1);

/**
 * Restore [gw:{collector}] tags wiped from descriptions, and report wallet totals.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/vendor/yiisoft/yii2/Yii.php';
require $root . '/common/config/bootstrap.php';
$config = yii\helpers\ArrayHelper::merge(
    require $root . '/common/config/main.php',
    require $root . '/common/config/main-local.php',
    require $root . '/console/config/main.php',
    require $root . '/console/config/main-local.php'
);
new yii\console\Application($config);

$updated = 0;
$rows = Yii::$app->db->createCommand(
    "SELECT id, order_reference, collector_user_id, description, payment_status, amount
     FROM clickpesa_transactions
     WHERE collector_user_id IS NOT NULL AND collector_user_id <> ''"
)->queryAll();

foreach ($rows as $row) {
    $collector = trim((string) $row['collector_user_id']);
    $desc = (string) ($row['description'] ?? '');
    $tag = '[gw:' . $collector . ']';
    if ($collector === '' || str_contains($desc, $tag)) {
        continue;
    }
    $next = trim($tag . ' ' . ($desc !== '' ? $desc : 'ClickPesa Payment'));
    Yii::$app->db->createCommand()->update(
        'clickpesa_transactions',
        ['description' => mb_substr($next, 0, 512), 'updated_at' => time()],
        ['id' => (int) $row['id']]
    )->execute();
    $updated++;
    echo "fixed #{$row['id']} {$row['order_reference']} => {$collector}" . PHP_EOL;
}

echo "updated={$updated}" . PHP_EOL;

// Show SUCCESS totals by collector for sanity.
$sums = Yii::$app->db->createCommand(
    "SELECT collector_user_id, COUNT(*) cnt, SUM(amount) total
     FROM clickpesa_transactions
     WHERE payment_status IN ('SUCCESS','PAID')
       AND collector_user_id IS NOT NULL AND collector_user_id <> ''
     GROUP BY collector_user_id"
)->queryAll();
foreach ($sums as $s) {
    echo "collector={$s['collector_user_id']} success_count={$s['cnt']} total={$s['total']}" . PHP_EOL;
}
