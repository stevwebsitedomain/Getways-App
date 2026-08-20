<?php

declare(strict_types=1);

namespace common\bootstrap;

use yii\base\BootstrapInterface;

/**
 * Loads key=value pairs from project root .env into getenv()/putenv() for PHP.
 * Node already uses dotenv; Yii reads credentials via getenv() in params.php.
 */
final class EnvBootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        $root = dirname(dirname(__DIR__));
        $envFile = $root . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envFile) || !is_readable($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
