<?php

declare(strict_types=1);

/**
 * Load project-root .env into getenv() for standalone frontend PHP pages.
 */
function gwLoadEnv(bool $forceUltamsg = false): void
{
    static $loaded = false;
    if ($loaded && !$forceUltamsg) {
        return;
    }

    $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $refresh = $forceUltamsg && str_starts_with($name, 'ULTAMSG_');
        if (getenv($name) !== false && !$refresh) {
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

    $loaded = true;
}

/**
 * @return array{instanceId:string,token:string,apiUrl:string}
 */
function gwUltamsgConfig(): array
{
    gwLoadEnv(true);
    $instanceId = trim((string) (getenv('ULTAMSG_INSTANCE_ID') ?: ''));
    $token = trim((string) (getenv('ULTAMSG_TOKEN') ?: ''));
    $apiUrl = rtrim(trim((string) (getenv('ULTAMSG_API_URL') ?: '')), '/');

    if ($apiUrl === '' && $instanceId !== '') {
        $apiUrl = 'https://api.ultramsg.com/' . $instanceId;
    }

    return [
        'instanceId' => $instanceId,
        'token' => $token,
        'apiUrl' => $apiUrl,
    ];
}
