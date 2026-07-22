<?php

return [
    'container' => [
        'singletons' => [
            \yii\mail\MailerInterface::class => [
                'class' => \yii\symfonymailer\Mailer::class,
                'viewPath' => '@common/mail',
            ],
        ],
    ],
    'components' => [
        'db' => [
            'class' => \yii\db\Connection::class,
            'dsn' => 'mysql:host=sdb-71.hosting.stackcp.net;port=3306;dbname=Getway-app-35303539c325',
            'username' => 'admin-48da',
            'password' => 'Getway2026',
            'charset' => 'utf8mb4',
        ],
        'mailer' => \yii\mail\MailerInterface::class,
    ],
];
