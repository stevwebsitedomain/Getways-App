<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/ai-robot-lib.php';
gwAuthStartSession();

function robotJson(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function robotCurrentUser(): ?array
{
    if (!isset($_SESSION['gw_auth_user']) || !is_array($_SESSION['gw_auth_user'])) {
        return null;
    }
    return $_SESSION['gw_auth_user'];
}

function robotRequireAuth(): array
{
    $user = robotCurrentUser();
    if ($user === null) {
        robotJson(401, ['ok' => false, 'message' => 'Not logged in.']);
    }
    return $user;
}

function robotReadInput(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'status')));
$input = robotReadInput();
$lang = strtolower(trim((string) ($_GET['lang'] ?? $input['lang'] ?? 'sw')));
if ($lang !== 'en') {
    $lang = 'sw';
}

if ($action === 'report-error' && $method === 'POST') {
    robotRequireAuth();
    $source = trim((string) ($input['source'] ?? 'client'));
    $message = trim((string) ($input['message'] ?? ''));
    if ($message === '') {
        robotJson(422, ['ok' => false, 'message' => 'Message required.']);
    }
    gwRobotLogError($source, $message, (string) ($input['severity'] ?? 'error'), [
        'page' => (string) ($input['page'] ?? ''),
        'url' => (string) ($input['url'] ?? ''),
    ]);
    robotJson(200, ['ok' => true, 'message' => 'Error logged.']);
}

$user = robotRequireAuth();
$role = strtolower((string) ($user['role'] ?? 'user'));
$isAdmin = $role === 'admin';

if (!isset($_SESSION['gw_login_at']) || $_SESSION['gw_login_at'] === '') {
    $_SESSION['gw_login_at'] = gmdate('c');
}

gwRobotScanSystemErrors();

if ($action === 'activity') {
    $limit = min(20, max(1, (int) ($_GET['limit'] ?? 10)));
    $logins = gwRobotGetRecentLogins($limit);
    robotJson(200, [
        'ok' => true,
        'logins' => $logins,
        'count' => count($logins),
    ]);
}

if ($action === 'errors') {
    $open = gwRobotGetOpenErrors();
    robotJson(200, [
        'ok' => true,
        'errors' => $open,
        'count' => count($open),
    ]);
}

if ($action === 'fix' && $method === 'POST') {
    $errorId = trim((string) ($input['errorId'] ?? ''));
    $result = gwRobotAutoFix($errorId);
    robotJson(200, [
        'ok' => true,
        'fixed' => $result['fixed'],
        'failed' => $result['failed'],
        'remaining' => count(gwRobotGetOpenErrors()),
    ]);
}

if ($action === 'speak') {
    $mode = strtolower(trim((string) ($_GET['mode'] ?? $input['mode'] ?? 'overview')));
    $text = robotBuildSpeakText($mode, $user, $isAdmin, $lang);
    robotJson(200, [
        'ok' => true,
        'mode' => $mode,
        'text' => $text,
        'lang' => $lang,
    ]);
}

// Default: status
$logins = gwRobotGetRecentLogins(5);
$errors = gwRobotGetOpenErrors();
$sessionLoginAt = (string) ($_SESSION['gw_login_at'] ?? '');

robotJson(200, [
    'ok' => true,
    'user' => [
        'fullName' => (string) ($user['fullName'] ?? ''),
        'role' => $role,
        'phone' => (string) ($user['phone'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
    ],
    'session' => [
        'loginAt' => $sessionLoginAt,
        'loginAtLocal' => $sessionLoginAt !== '' ? date('Y-m-d H:i:s', strtotime($sessionLoginAt)) : '',
        'duration' => $sessionLoginAt !== '' ? gwRobotFormatDuration($sessionLoginAt) : '',
        'durationSw' => $sessionLoginAt !== '' ? gwRobotFormatDurationSw($sessionLoginAt) : '',
    ],
    'recentLogins' => $logins,
    'openErrors' => $errors,
    'errorCount' => count($errors),
    'systemOk' => count($errors) === 0,
    'isAdmin' => $isAdmin,
    'serverTime' => date('Y-m-d H:i:s'),
]);

function robotBuildSpeakText(string $mode, array $user, bool $isAdmin, string $lang): string
{
    $name = trim((string) ($user['fullName'] ?? 'User'));
    $roleLabel = $isAdmin ? ($lang === 'sw' ? 'msimamizi' : 'admin') : ($lang === 'sw' ? 'mtumiaji' : 'user');
    $loginAt = (string) ($_SESSION['gw_login_at'] ?? '');
    $logins = gwRobotGetRecentLogins(5);
    $errors = gwRobotGetOpenErrors();
    $errorCount = count($errors);

    if ($mode === 'login') {
        if ($lang === 'sw') {
            $parts = ["Habari {$name}. Wewe umeingia kama {$roleLabel}."];
            if ($loginAt !== '') {
                $parts[] = 'Umeingia ' . gwRobotFormatDurationSw($loginAt) . '.';
            }
            if (count($logins) > 0) {
                $parts[] = 'Watu walioingia hivi karibuni:';
                foreach (array_slice($logins, 0, 3) as $i => $login) {
                    $ln = (string) ($login['fullName'] ?? 'User');
                    $lr = (string) ($login['role'] ?? 'user');
                    $when = gwRobotFormatDurationSw((string) ($login['at'] ?? ''));
                    $parts[] = ($i + 1) . ". {$ln}, jukumu {$lr}, {$when}.";
                }
            } else {
                $parts[] = 'Hakuna rekodi za kuingia bado.';
            }
            return implode(' ', $parts);
        }

        $parts = ["Hello {$name}. You are logged in as {$roleLabel}."];
        if ($loginAt !== '') {
            $parts[] = 'You logged in ' . gwRobotFormatDuration($loginAt) . '.';
        }
        if (count($logins) > 0) {
            $parts[] = 'Recent logins:';
            foreach (array_slice($logins, 0, 3) as $i => $login) {
                $ln = (string) ($login['fullName'] ?? 'User');
                $lr = (string) ($login['role'] ?? 'user');
                $when = gwRobotFormatDuration((string) ($login['at'] ?? ''));
                $parts[] = ($i + 1) . ". {$ln}, role {$lr}, {$when}.";
            }
        } else {
            $parts[] = 'No login records yet.';
        }
        return implode(' ', $parts);
    }

    if ($mode === 'error') {
        if ($errorCount === 0) {
            return $lang === 'sw'
                ? "Habari {$name}. Hakuna makosa yaliyogunduliwa kwenye mfumo. Kila kitu kiko sawa."
                : "Hello {$name}. No errors detected in the system. Everything looks good.";
        }

        $fixResult = gwRobotAutoFix('');
        $fixedList = implode('. ', $fixResult['fixed']);
        $remaining = count(gwRobotGetOpenErrors());

        if ($lang === 'sw') {
            $msg = "Tahadhari {$name}! Makosa {$errorCount} yamegunduliwa kwenye mfumo. ";
            if ($fixedList !== '') {
                $msg .= "Nimejaribu kurekebisha: {$fixedList}. ";
            }
            $msg .= $remaining > 0
                ? "Bado kuna makosa {$remaining} yanayofuatiliwa."
                : 'Makosa yote yamerekebishwa.';
            return $msg;
        }

        $msg = "Alert {$name}! {$errorCount} error(s) detected. ";
        if ($fixedList !== '') {
            $msg .= "Auto-fix attempted: {$fixedList}. ";
        }
        $msg .= $remaining > 0
            ? "{$remaining} error(s) still being monitored."
            : 'All errors have been resolved.';
        return $msg;
    }

    if ($mode === 'monitor') {
        if ($lang === 'sw') {
            return "Ufuatiliaji unaendelea. Mfumo unaendeshwa na {$name} kama {$roleLabel}. "
                . ($errorCount > 0 ? "Makosa {$errorCount} yanafuatiliwa." : 'Hakuna makosa.')
                . ' Saa ni ' . date('H:i') . '.';
        }
        return "Monitoring active. System operated by {$name} as {$roleLabel}. "
            . ($errorCount > 0 ? "{$errorCount} error(s) being tracked." : 'No errors.')
            . ' Time is ' . date('H:i') . '.';
    }

    // overview
    if ($lang === 'sw') {
        $msg = "Karibu {$name}. Mimi ni Kaka, msaidizi wako wa mfumo wa Getway. ";
        $msg .= "Wewe ni {$roleLabel}";
        if ($loginAt !== '') {
            $msg .= ', umeingia ' . gwRobotFormatDurationSw($loginAt);
        }
        $msg .= '. ';
        if (count($logins) > 0) {
            $last = $logins[0];
            $msg .= 'Mtu wa mwisho kuingia ni ' . ($last['fullName'] ?? 'User')
                . ' ' . gwRobotFormatDurationSw((string) ($last['at'] ?? '')) . '. ';
        }
        if ($errorCount > 0) {
            $msg .= "Kuna makosa {$errorCount} kwenye mfumo. Nitaendelea kuyarekebisha.";
        } else {
            $msg .= 'Mfumo unaendeshwa vizuri, hakuna makosa.';
        }
        return $msg;
    }

    $msg = "Welcome {$name}. I am Kaka, your Getway system assistant. ";
    $msg .= "You are {$roleLabel}";
    if ($loginAt !== '') {
        $msg .= ', logged in ' . gwRobotFormatDuration($loginAt);
    }
    $msg .= '. ';
    if (count($logins) > 0) {
        $last = $logins[0];
        $msg .= 'Last login was ' . ($last['fullName'] ?? 'User')
            . ' ' . gwRobotFormatDuration((string) ($last['at'] ?? '')) . '. ';
    }
    if ($errorCount > 0) {
        $msg .= "{$errorCount} error(s) found. I will keep fixing them.";
    } else {
        $msg .= 'System is healthy, no errors.';
    }
    return $msg;
}
