<?php

declare(strict_types=1);

$params = array_merge(
    require __DIR__ . '/../../common/config/params.php',
    require __DIR__ . '/../../common/config/params-local.php',
    require __DIR__ . '/params.php',
    require __DIR__ . '/params-local.php',
);

return [
    'id' => 'app-frontend',
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'frontend\controllers',
    'modules' => [
        'api' => [
            'class' => \frontend\modules\api\Module::class,
        ],
    ],
    // Keep existing /api/tis/* proxy on ApiController without colliding with the api module.
    'controllerMap' => [
        'tis-api' => [
            'class' => \frontend\controllers\ApiController::class,
        ],
    ],
    'components' => [
        'request' => [
            'csrfParam' => '_csrf-frontend',
            'parsers' => [
                'application/json' => \yii\web\JsonParser::class,
            ],
        ],
        'user' => [
            'identityClass' => \common\models\User::class,
            'enableAutoLogin' => true,
            'identityCookie' => ['name' => '_identity-frontend', 'httpOnly' => true],
        ],
        'session' => [
            // this is the name of the session cookie used for login on the frontend
            'name' => 'advanced-frontend',
        ],
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
                [
                    'class' => \yii\log\FileTarget::class,
                    'categories' => ['clickpesa'],
                    'logFile' => '@runtime/logs/clickpesa.log',
                    'levels' => ['error', 'warning', 'info'],
                    'logVars' => [],
                    'maxFileSize' => 10240,
                    'maxLogFiles' => 10,
                ],
            ],
        ],
        'errorHandler' => [
            'errorAction' => 'site/error',
        ],
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                'GET,POST api/clickpesa/webhook' => 'api/click-pesa/webhook',
                'POST api/clickpesa/control-number' => 'api/click-pesa/control-number',
                'GET api/clickpesa/control-number/<id:\d+>/invoice' => 'api/click-pesa/control-number-invoice',
                'POST api/clickpesa/create-control-number' => 'api/click-pesa/create-control-number',
                'GET api/clickpesa/account-balance' => 'api/click-pesa/account-balance',
                'GET api/clickpesa/account-statement' => 'api/click-pesa/account-statement',
                'POST api/clickpesa/sync-transactions' => 'api/click-pesa/sync-transactions',
                'GET,POST api/clickpesa/auto-payout/settings' => 'api/click-pesa/auto-payout-settings',
                'GET api/clickpesa/control-numbers' => 'api/click-pesa/control-numbers',
                'GET api/clickpesa/payouts' => 'api/click-pesa/payouts',
                'GET api/clickpesa/payment-status/<reference:[A-Za-z0-9\-_]+>' => 'api/click-pesa/payment-status',
                'POST api/clickpesa/payment-status' => 'api/click-pesa/payment-status',
                'GET api/clickpesa/payout-status/<reference:[A-Za-z0-9\-_]+>' => 'api/click-pesa/payout-status',
                'POST api/clickpesa/retry-payout/<id:\d+>' => 'api/click-pesa/retry-payout',
                'POST api/clickpesa/payout' => 'api/click-pesa/payout',
                'GET api/clickpesa/payments' => 'api/click-pesa/payments',
                'GET api/clickpesa/payment-details' => 'api/click-pesa/payment-details',
                'POST,DELETE api/clickpesa/delete' => 'api/click-pesa/delete',
                'api/clickpesa/<action>' => 'api/click-pesa/<action>',
                'api/tis' => 'tis-api/tis-proxy',
                'api/tis/<path:.+>' => 'tis-api/tis-proxy',
            ],
        ],
    ],
    'params' => $params,
];
