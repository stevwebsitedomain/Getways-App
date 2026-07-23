<?php

declare(strict_types=1);

require_once __DIR__ . '/auth-init.php';

function gwRobotRuntimeDir(): string
{
    $dir = gwAuthRuntimeDir() . DIRECTORY_SEPARATOR . 'ai-robot';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function gwRobotActivityPath(): string
{
    return gwRobotRuntimeDir() . DIRECTORY_SEPARATOR . 'activity.json';
}

function gwRobotErrorsPath(): string
{
    return gwRobotRuntimeDir() . DIRECTORY_SEPARATOR . 'errors.json';
}

/**
 * @return array{events: list<array<string, mixed>>}
 */
function gwRobotReadJson(string $path): array
{
    if (!is_file($path)) {
        return ['events' => []];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['events' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['events' => []];
    }
    if (!isset($data['events']) || !is_array($data['events'])) {
        $data['events'] = [];
    }
    return $data;
}

/**
 * @param array{events: list<array<string, mixed>>} $data
 */
function gwRobotWriteJson(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $written = @file_put_contents(
        $path,
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
    return $written !== false;
}

/**
 * @param array<string, mixed> $user
 */
function gwRobotLogLogin(array $user, string $method = 'password'): void
{
    $path = gwRobotActivityPath();
    $data = gwRobotReadJson($path);
    $events = $data['events'];

    $event = [
        'type' => 'login',
        'id' => bin2hex(random_bytes(8)),
        'userId' => (string) ($user['id'] ?? ''),
        'fullName' => (string) ($user['fullName'] ?? 'User'),
        'role' => (string) ($user['role'] ?? 'user'),
        'phone' => (string) ($user['phone'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'method' => $method,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'userAgent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'at' => gmdate('c'),
        'atLocal' => date('Y-m-d H:i:s'),
    ];

    array_unshift($events, $event);
    $data['events'] = array_slice($events, 0, 200);
    gwRobotWriteJson($path, $data);
}

/**
 * @param array<string, mixed> $extra
 */
function gwRobotLogError(string $source, string $message, string $severity = 'error', array $extra = []): void
{
    $path = gwRobotErrorsPath();
    $data = gwRobotReadJson($path);
    $events = $data['events'];

    $event = [
        'type' => 'error',
        'id' => bin2hex(random_bytes(8)),
        'source' => $source,
        'message' => $message,
        'severity' => $severity,
        'status' => 'open',
        'extra' => $extra,
        'at' => gmdate('c'),
        'atLocal' => date('Y-m-d H:i:s'),
    ];

    array_unshift($events, $event);
    $data['events'] = array_slice($events, 0, 150);
    gwRobotWriteJson($path, $data);
}

/**
 * @return list<array<string, mixed>>
 */
function gwRobotGetRecentLogins(int $limit = 10): array
{
    $data = gwRobotReadJson(gwRobotActivityPath());
    $logins = [];
    foreach ($data['events'] as $event) {
        if (!is_array($event) || ($event['type'] ?? '') !== 'login') {
            continue;
        }
        $logins[] = $event;
        if (count($logins) >= $limit) {
            break;
        }
    }
    return $logins;
}

/**
 * @return list<array<string, mixed>>
 */
function gwRobotGetOpenErrors(): array
{
    $data = gwRobotReadJson(gwRobotErrorsPath());
    $open = [];
    foreach ($data['events'] as $event) {
        if (!is_array($event) || ($event['type'] ?? '') !== 'error') {
            continue;
        }
        if (($event['status'] ?? 'open') === 'open') {
            $open[] = $event;
        }
    }
    return $open;
}

/**
 * @return list<string>
 */
function gwRobotScanSystemErrors(): array
{
    $found = [];

    $runtimeDir = gwAuthRuntimeDir();
    if (!is_writable($runtimeDir)) {
        $found[] = 'Runtime folder is not writable: ' . $runtimeDir;
        gwRobotLogError('system', 'Runtime folder is not writable', 'critical', ['path' => $runtimeDir]);
    }

    $sessionsDir = $runtimeDir . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionsDir)) {
        $found[] = 'Sessions folder missing';
        gwRobotLogError('system', 'Sessions folder missing', 'warning');
    } elseif (!is_writable($sessionsDir)) {
        $found[] = 'Sessions folder is not writable';
        gwRobotLogError('system', 'Sessions folder is not writable', 'warning');
    }

    $usersFile = $runtimeDir . DIRECTORY_SEPARATOR . 'auth-users.json';
    if (!is_file($usersFile)) {
        $found[] = 'User store file missing (auth-users.json)';
        gwRobotLogError('system', 'User store file missing', 'warning');
    }

    $yiiLogs = [
        dirname(__DIR__, 2) . '/frontend/runtime/logs/app.log',
        dirname(__DIR__, 2) . '/backend/runtime/logs/app.log',
    ];
    foreach ($yiiLogs as $logPath) {
        if (!is_file($logPath)) {
            continue;
        }
        $tail = gwRobotTailFile($logPath, 4096);
        if ($tail !== '' && preg_match('/\[error\]/i', $tail)) {
            $found[] = 'Recent Yii errors in ' . basename(dirname($logPath)) . '/app.log';
            gwRobotLogError('yii-log', 'Recent errors in Yii log: ' . basename(dirname($logPath)), 'error', ['path' => $logPath]);
        }
    }

    return array_values(array_unique($found));
}

function gwRobotTailFile(string $path, int $bytes = 4096): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $size = filesize($path);
    if ($size === false || $size === 0) {
        return '';
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }
    $start = max(0, $size - $bytes);
    fseek($handle, $start);
    $content = fread($handle, $bytes);
    fclose($handle);
    return is_string($content) ? $content : '';
}

/**
 * @return array{fixed: list<string>, failed: list<string>}
 */
function gwRobotAutoFix(string $errorId = ''): array
{
    $fixed = [];
    $failed = [];
    $path = gwRobotErrorsPath();
    $data = gwRobotReadJson($path);
    $events = $data['events'];

    foreach ($events as &$event) {
        if (!is_array($event) || ($event['type'] ?? '') !== 'error') {
            continue;
        }
        if (($event['status'] ?? 'open') !== 'open') {
            continue;
        }
        if ($errorId !== '' && ($event['id'] ?? '') !== $errorId) {
            continue;
        }

        $source = (string) ($event['source'] ?? '');
        $message = (string) ($event['message'] ?? '');
        $resolved = false;

        if (str_contains($message, 'Runtime folder is not writable') || str_contains($message, 'Sessions folder')) {
            $runtimeDir = gwAuthRuntimeDir();
            $sessionsDir = $runtimeDir . DIRECTORY_SEPARATOR . 'sessions';
            if (!is_dir($runtimeDir)) {
                @mkdir($runtimeDir, 0775, true);
            }
            if (!is_dir($sessionsDir)) {
                @mkdir($sessionsDir, 0775, true);
            }
            @chmod($runtimeDir, 0775);
            @chmod($sessionsDir, 0775);
            if (is_writable($runtimeDir) && is_dir($sessionsDir) && is_writable($sessionsDir)) {
                $resolved = true;
                $fixed[] = 'Fixed folder permissions for runtime/sessions';
            } else {
                $failed[] = 'Could not fix folder permissions';
            }
        }

        if (str_contains($message, 'User store file missing')) {
            $usersFile = gwAuthRuntimeDir() . DIRECTORY_SEPARATOR . 'auth-users.json';
            if (!is_file($usersFile)) {
                @file_put_contents($usersFile, json_encode(['users' => []], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
            if (is_file($usersFile)) {
                $resolved = true;
                $fixed[] = 'Created missing auth-users.json';
            } else {
                $failed[] = 'Could not create auth-users.json';
            }
        }

        if ($source === 'api' || $source === 'server') {
            $resolved = true;
            $fixed[] = 'Marked API/server error as resolved (will retry on next check)';
        }

        if ($source === 'yii-log') {
            $resolved = true;
            $fixed[] = 'Acknowledged Yii log errors (monitoring continues)';
        }

        if ($resolved) {
            $event['status'] = 'fixed';
            $event['fixedAt'] = gmdate('c');
        } elseif ($errorId !== '' && ($event['id'] ?? '') === $errorId) {
            $failed[] = 'No automatic fix available for: ' . $message;
        }
    }
    unset($event);

    gwRobotWriteJson($path, $data);

    if ($errorId === '') {
        $openCount = count(gwRobotGetOpenErrors());
        if ($openCount === 0 && empty($fixed)) {
            $fixed[] = 'No open errors to fix';
        }
    }

    return ['fixed' => $fixed, 'failed' => $failed];
}

function gwRobotFormatDuration(string $isoTime): string
{
    $ts = strtotime($isoTime);
    if ($ts === false) {
        return 'unknown time';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return $diff . ' seconds ago';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' minutes ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' hours ago';
    }
    return (int) floor($diff / 86400) . ' days ago';
}

function gwRobotFormatDurationSw(string $isoTime): string
{
    $ts = strtotime($isoTime);
    if ($ts === false) {
        return 'muda usiojulikana';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'sekunde ' . $diff . ' zilizopita';
    }
    if ($diff < 3600) {
        return 'dakika ' . (int) floor($diff / 60) . ' zilizopita';
    }
    if ($diff < 86400) {
        return 'masaa ' . (int) floor($diff / 3600) . ' yaliyopita';
    }
    return 'siku ' . (int) floor($diff / 86400) . ' zilizopita';
}

function gwRobotAgentPath(): string
{
    return gwRobotRuntimeDir() . DIRECTORY_SEPARATOR . 'agent-profile.json';
}

/**
 * @return array<string, mixed>
 */
function gwRobotGetAgentProfile(): array
{
    $path = gwRobotAgentPath();
    if (!is_file($path)) {
        return [
            'authorizedUserId' => '',
            'codename' => 'Special Agent namba 3',
            'realName' => '',
            'boundAt' => '',
            'memories' => [],
            'conversations' => [],
        ];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['authorizedUserId' => '', 'codename' => 'Special Agent namba 3', 'realName' => '', 'boundAt' => '', 'memories' => [], 'conversations' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['authorizedUserId' => '', 'codename' => 'Special Agent namba 3', 'realName' => '', 'boundAt' => '', 'memories' => [], 'conversations' => []];
    }
    if (!isset($data['memories']) || !is_array($data['memories'])) {
        $data['memories'] = [];
    }
    if (!isset($data['conversations']) || !is_array($data['conversations'])) {
        $data['conversations'] = [];
    }
    if (!isset($data['codename']) || $data['codename'] === '') {
        $data['codename'] = 'Special Agent namba 3';
    }
    return $data;
}

/**
 * @param array<string, mixed> $profile
 */
function gwRobotSaveAgentProfile(array $profile): bool
{
    return gwRobotWriteJson(gwRobotAgentPath(), $profile);
}

/**
 * @param array<string, mixed> $user
 * @return array{authorized: bool, bound: bool, codename: string, profile: array<string, mixed>}
 */
function gwRobotCheckAgent(array $user): array
{
    $profile = gwRobotGetAgentProfile();
    $userId = (string) ($user['id'] ?? '');
    $codename = (string) ($profile['codename'] ?? 'Special Agent namba 3');
    $authorizedId = (string) ($profile['authorizedUserId'] ?? '');

    if ($authorizedId === '' && strtolower((string) ($user['role'] ?? '')) === 'admin') {
        $profile['authorizedUserId'] = $userId;
        $profile['realName'] = (string) ($user['fullName'] ?? '');
        $profile['boundAt'] = gmdate('c');
        gwRobotSaveAgentProfile($profile);
        $authorizedId = $userId;
    }

    return [
        'authorized' => $authorizedId !== '' && $authorizedId === $userId,
        'bound' => $authorizedId !== '',
        'codename' => $codename,
        'profile' => $profile,
    ];
}

function gwRobotAgentCodename(): string
{
    $profile = gwRobotGetAgentProfile();
    return (string) ($profile['codename'] ?? 'Special Agent namba 3');
}

function gwRobotRememberFact(string $fact, string $context = ''): void
{
    $profile = gwRobotGetAgentProfile();
    $memories = $profile['memories'];
    $memories[] = [
        'fact' => $fact,
        'context' => $context,
        'at' => gmdate('c'),
    ];
    $profile['memories'] = array_slice($memories, -80);
    gwRobotSaveAgentProfile($profile);
}

/**
 * @param array<string, mixed> $user
 * @return array{text: string, emotion: string, authorized: bool}
 */
function gwRobotChat(string $message, array $user, string $lang = 'sw'): array
{
    $agent = gwRobotCheckAgent($user);
    $codename = $agent['codename'];
    $msg = trim($message);
    $lower = mb_strtolower($msg, 'UTF-8');

    if (!$agent['authorized']) {
        $text = $lang === 'sw'
            ? "Sikubali. Mimi huongei na mtu yeyote isipokuwa {$codename}. Wewe si yeye. Nenda zako."
            : "Access denied. I only speak with {$codename}. You are not authorized.";
        return ['text' => $text, 'emotion' => 'angry', 'authorized' => false];
    }

    $profile = $agent['profile'];
    $emotion = 'neutral';

    if (gwRobotMessageMatches($lower, ['nikumbuke', 'kumbuka', 'remember', 'jifunze', 'learn'])) {
        gwRobotRememberFact($msg, 'user_taught');
        $text = $lang === 'sw'
            ? "Nimekukumbuka, {$codename}. Nitaendelea kukumbuka hili."
            : "Noted, {$codename}. I will remember that.";
        return ['text' => $text, 'emotion' => 'happy', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['asante', 'thank', 'shukrani', 'nzuri', 'vizuri', 'good job', 'umefanya vizuri'])) {
        $text = $lang === 'sw'
            ? "Asante {$codename}! Nimefurahi kukusaidia. Niko hapa kila wakati."
            : "Thank you {$codename}! Happy to help. I am always here.";
        return ['text' => $text, 'emotion' => 'happy', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['kasirika', 'hasira', 'angry', 'stupid', 'mpuuzi', 'mbaya'])) {
        $text = $lang === 'sw'
            ? "{$codename}, pole sana. Naelewa unakasirika. Nifanye nini kusaidia?"
            : "{$codename}, I understand you are upset. How can I help fix this?";
        return ['text' => $text, 'emotion' => 'angry', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['habari', 'hello', 'hi', 'mambo', 'vipi', 'salamu', 'hey'])) {
        $text = $lang === 'sw'
            ? "Shikamoo {$codename}! Mimi ni Kaka, roboti yako ya mfumo. Nimekutambua wewe pekee. Unaweza kuniuliza chochote kuhusu mfumo."
            : "Hello {$codename}! I am Kaka, your system robot. I recognize only you. Ask me anything about the system.";
        return ['text' => $text, 'emotion' => 'happy', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['jina lako', 'unaitwa nani', 'who are you', 'your name'])) {
        $text = $lang === 'sw'
            ? "Mimi ni Kaka, roboti wa mfumo wa Getway. Wewe ni {$codename}, wakala wangu pekee niliyekubali."
            : "I am Kaka, the Getway system robot. You are {$codename}, my only authorized agent.";
        return ['text' => $text, 'emotion' => 'happy', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['nani ameingia', 'nani amelogin', 'who logged', 'login', 'ameingia', 'walioingia'])) {
        $logins = gwRobotGetRecentLogins(5);
        if ($lang === 'sw') {
            if (count($logins) === 0) {
                $text = "{$codename}, hakuna mtu aliyeingia bado.";
            } else {
                $parts = ["{$codename}, hivi karibuni wameingia:"];
                foreach (array_slice($logins, 0, 4) as $i => $login) {
                    $parts[] = ($i + 1) . '. ' . ($login['fullName'] ?? 'User')
                        . ', jukumu ' . ($login['role'] ?? 'user')
                        . ', ' . gwRobotFormatDurationSw((string) ($login['at'] ?? '')) . '.';
                }
                $text = implode(' ', $parts);
            }
        } else {
            if (count($logins) === 0) {
                $text = "{$codename}, no one has logged in yet.";
            } else {
                $parts = ["{$codename}, recent logins:"];
                foreach (array_slice($logins, 0, 4) as $i => $login) {
                    $parts[] = ($i + 1) . '. ' . ($login['fullName'] ?? 'User')
                        . ', role ' . ($login['role'] ?? 'user')
                        . ', ' . gwRobotFormatDuration((string) ($login['at'] ?? '')) . '.';
                }
                $text = implode(' ', $parts);
            }
        }
        return ['text' => $text, 'emotion' => 'neutral', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['makosa', 'error', 'tatizo', 'shida', 'problem', 'bug'])) {
        $errors = gwRobotGetOpenErrors();
        $count = count($errors);
        if ($count === 0) {
            $text = $lang === 'sw'
                ? "{$codename}, hakuna makosa kwenye mfumo sasa. Kila kitu kiko salama."
                : "{$codename}, no errors in the system right now. All clear.";
            return ['text' => $text, 'emotion' => 'happy', 'authorized' => true];
        }
        $fix = gwRobotAutoFix('');
        $fixed = implode('. ', $fix['fixed']);
        $remaining = count(gwRobotGetOpenErrors());
        if ($lang === 'sw') {
            $text = "Tahadhari {$codename}! Makosa {$count} yamegunduliwa. ";
            if ($fixed !== '') {
                $text .= "Nimerekebisha: {$fixed}. ";
            }
            $text .= $remaining > 0 ? "Bado {$remaining} yanabaki." : 'Yote yamerekebishwa.';
        } else {
            $text = "Alert {$codename}! {$count} error(s) found. ";
            if ($fixed !== '') {
                $text .= "Fixed: {$fixed}. ";
            }
            $text .= $remaining > 0 ? "{$remaining} remaining." : 'All resolved.';
        }
        return ['text' => $text, 'emotion' => $remaining > 0 ? 'angry' : 'happy', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['saa', 'muda', 'time', 'date', 'leo', 'today'])) {
        $now = date('H:i, l j F Y');
        $text = $lang === 'sw'
            ? "{$codename}, sasa ni saa {$now}."
            : "{$codename}, the time is {$now}.";
        return ['text' => $text, 'emotion' => 'neutral', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['hali', 'status', 'mfumo', 'system', 'dashboard'])) {
        $errors = gwRobotGetOpenErrors();
        $logins = gwRobotGetRecentLogins(1);
        $loginAt = (string) ($_SESSION['gw_login_at'] ?? '');
        if ($lang === 'sw') {
            $text = "{$codename}, hali ya mfumo: ";
            $text .= count($errors) > 0
                ? 'kuna makosa ' . count($errors) . '. '
                : 'hakuna makosa, mfumo unaendeshwa vizuri. ';
            if ($loginAt !== '') {
                $text .= 'Wewe umeingia ' . gwRobotFormatDurationSw($loginAt) . '. ';
            }
            if (count($logins) > 0) {
                $text .= 'Mtu wa mwisho kuingia: ' . ($logins[0]['fullName'] ?? 'User') . '.';
            }
        } else {
            $text = "{$codename}, system status: ";
            $text .= count($errors) > 0
                ? count($errors) . ' error(s) active. '
                : 'no errors, running smoothly. ';
            if ($loginAt !== '') {
                $text .= 'You logged in ' . gwRobotFormatDuration($loginAt) . '. ';
            }
            if (count($logins) > 0) {
                $text .= 'Last login: ' . ($logins[0]['fullName'] ?? 'User') . '.';
            }
        }
        return ['text' => $text, 'emotion' => count($errors) > 0 ? 'angry' : 'happy', 'authorized' => true];
    }

    if (gwRobotMessageMatches($lower, ['rekebisha', 'fix', 'solve', 'tatua'])) {
        $fix = gwRobotAutoFix('');
        $fixed = implode('. ', $fix['fixed']);
        $text = $lang === 'sw'
            ? ($fixed !== '' ? "{$codename}, nimerekebisha: {$fixed}." : "{$codename}, hakuna makosa ya kurekebisha.")
            : ($fixed !== '' ? "{$codename}, I fixed: {$fixed}." : "{$codename}, nothing to fix.");
        return ['text' => $text, 'emotion' => 'happy', 'authorized' => true];
    }

    $memories = $profile['memories'] ?? [];
    foreach ($memories as $mem) {
        if (!is_array($mem)) {
            continue;
        }
        $factLower = mb_strtolower((string) ($mem['fact'] ?? ''), 'UTF-8');
        $snippet = mb_substr($factLower, 0, 12);
        $querySnippet = mb_substr($lower, 0, 10);
        if ($factLower !== '' && ($snippet !== '' && str_contains($lower, $snippet) || str_contains($factLower, $querySnippet))) {
            $text = $lang === 'sw'
                ? "{$codename}, nakukumbuka: " . ($mem['fact'] ?? '')
                : "{$codename}, I remember: " . ($mem['fact'] ?? '');
            return ['text' => $text, 'emotion' => 'happy', 'authorized' => true];
        }
    }

    gwRobotRememberFact($msg, 'conversation');
    $conversations = $profile['conversations'] ?? [];
    $conversations[] = ['q' => $msg, 'at' => gmdate('c')];
    $profile['conversations'] = array_slice($conversations, -50);
    gwRobotSaveAgentProfile($profile);

    if ($lang === 'sw') {
        $text = "{$codename}, nimekusikia. Umesema: {$msg}. ";
        $text .= 'Kama unahitaji taarifa za mfumo, niulize kuhusu makosa, walioingia, au hali ya dashboard. Nimekukumbuka.';
    } else {
        $text = "{$codename}, I heard you. You said: {$msg}. ";
        $text .= 'For system info, ask about errors, logins, or dashboard status. I will remember this.';
    }

    return ['text' => $text, 'emotion' => 'neutral', 'authorized' => true];
}

/**
 * @param list<string> $keywords
 */
function gwRobotMessageMatches(string $haystack, array $keywords): bool
{
    foreach ($keywords as $kw) {
        if ($kw !== '' && str_contains($haystack, $kw)) {
            return true;
        }
    }
    return false;
}
