<?php

declare(strict_types=1);

return [
    'bootstrap' => [
        \common\bootstrap\EnvBootstrap::class,
        \common\bootstrap\MailerBootstrap::class,
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'vendorPath' => dirname(dirname(__DIR__)) . '/vendor',
    'container' => [
        'singletons' => [
            \common\services\ClickPesaService::class => \common\services\ClickPesaService::class,
            \common\services\ClickPesaPayoutService::class => \common\services\ClickPesaPayoutService::class,
        ],
    ],
    'components' => [
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
    ],
];
