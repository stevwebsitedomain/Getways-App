<?php

declare(strict_types=1);

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'dev');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../common/config/bootstrap.php';
require __DIR__ . '/../console/config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../common/config/main.php',
    require __DIR__ . '/../common/config/main-local.php',
    require __DIR__ . '/../console/config/main.php',
    require __DIR__ . '/../console/config/main-local.php'
);

new yii\console\Application($config);

$s = common\models\ClickPesaSetting::current();
echo 'auto_payout_enabled=' . (int) $s->auto_payout_enabled . PHP_EOL;
echo 'masked=' . $s->getMaskedDestination() . PHP_EOL;
echo 'phone=' . $s->getDestinationPhone() . PHP_EOL;
echo 'manual_approval=' . (int) $s->require_manual_approval . PHP_EOL;
