<?php

/**
 * Prefer project-root .env; fall back to StackCP host defaults (no password in git).
 */
$envFile = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

$dbHost = getenv('DB_HOST') ?: '';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'reacrisc_getways';
$dbUser = getenv('DB_USER') ?: 'reacrisc_admin';
$dbPass = getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '';

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
            'dsn' => "mysql:host={$dbHost};port={$dbPort};dbname={$dbName}",
            'username' => $dbUser,
            'password' => $dbPass,
            'charset' => 'utf8mb4',
        ],
        'mailer' => \yii\mail\MailerInterface::class,
    ],
];
