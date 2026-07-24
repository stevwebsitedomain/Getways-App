<?php

declare(strict_types=1);

require_once __DIR__ . '/auth-init.php';
gwAuthStartSession();

if (!isset($_SESSION['gw_auth_user']) || !is_array($_SESSION['gw_auth_user'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Not logged in.']);
    exit;
}

$role = strtolower((string) ($_SESSION['gw_auth_user']['role'] ?? 'user'));
if ($role !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => 'Admin access required.']);
    exit;
}

function camProxyJson(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function camProxyNormalizeIp(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return '';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    if (preg_match('/^[a-z0-9.\-]+$/i', $ip)) {
        return $ip;
    }
    return '';
}

function camProxyEnv(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return (string) $v;
    }
    $envPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envPath)) {
        return $default;
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $val] = explode('=', $line, 2);
        if (trim($k) === $key) {
            return trim($val, " \t\"'");
        }
    }
    return $default;
}

function camProxyFfmpegPath(): string
{
    $fromEnv = camProxyEnv('RADAR_FFMPEG_PATH', '');
    if ($fromEnv !== '' && is_file($fromEnv)) {
        return $fromEnv;
    }
    $candidates = [
        'ffmpeg',
        dirname(__DIR__, 2) . '\\ffmpeg\\ffmpeg-master-latest-win64-gpl\\bin\\ffmpeg.exe',
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
        'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe',
    ];
    foreach ($candidates as $bin) {
        if ($bin === 'ffmpeg') {
            $out = shell_exec('where ffmpeg 2>nul');
            if (is_string($out) && trim($out) !== '') {
                return trim(explode("\n", $out)[0]);
            }
            continue;
        }
        if (is_file($bin)) {
            return $bin;
        }
    }
    return '';
}

function camProxyRtspSnapshot(string $rtspUrl, int $timeout = 12): array
{
    $ffmpeg = camProxyFfmpegPath();
    if ($ffmpeg === '') {
        return ['ok' => false, 'error' => 'ffmpeg not installed. Install ffmpeg for RTSP cameras.'];
    }

    $cmd = sprintf(
        '%s -hide_banner -loglevel error -rtsp_transport tcp -y -i %s -frames:v 1 -f image2 pipe:1',
        escapeshellarg($ffmpeg),
        escapeshellarg($rtspUrl)
    );

    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'Could not start ffmpeg.'];
    }

    stream_set_blocking($pipes[1], true);
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    if (!is_string($body) || !camProxyLooksLikeImage($body, 'image/jpeg')) {
        return ['ok' => false, 'error' => 'RTSP frame capture failed. Check URL and credentials.'];
    }

    return ['ok' => true, 'body' => $body, 'content_type' => 'image/jpeg'];
}

function camProxyBuildUrl(string $scheme, string $host, int $port, string $path, string $user = '', string $pass = ''): string
{
    $path = '/' . ltrim($path, '/');
    $auth = '';
    if ($user !== '') {
        $auth = rawurlencode($user) . ':' . rawurlencode($pass) . '@';
    }
    $defaultPort = match ($scheme) {
        'https' => 443,
        'rtsp' => 554,
        default => 80,
    };
    $portPart = ($port > 0 && $port !== $defaultPort) ? ":{$port}" : '';
    return "{$scheme}://{$auth}{$host}{$portPart}{$path}";
}

function camProxyTcpOpen(string $host, int $port, float $timeout = 2.0): bool
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (is_resource($fp)) {
        fclose($fp);
        return true;
    }
    return false;
}

function camProxyLooksLikeImage(string $body, string $ctype): bool
{
    $ctype = strtolower($ctype);
    if (str_contains($ctype, 'image/jpeg') || str_contains($ctype, 'image/jpg') || str_contains($ctype, 'image/png')) {
        return true;
    }
    if (str_contains($ctype, 'multipart') || str_contains($ctype, 'mjpeg') || str_contains($ctype, 'x-mixed-replace')) {
        return true;
    }
    if (strlen($body) >= 3 && str_starts_with($body, "\xFF\xD8\xFF")) {
        return true;
    }
    if (strlen($body) >= 8 && str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
        return true;
    }
    return false;
}

function camProxyRequest(string $url, string $user = '', string $pass = '', int $timeout = 5, bool $headOnly = false): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Getway-CIA-Camera/1.1)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPAUTH => CURLAUTH_ANY,
    ];
    if ($user !== '') {
        $opts[CURLOPT_USERPWD] = $user . ':' . $pass;
    }
    if ($headOnly) {
        $opts[CURLOPT_NOBODY] = true;
        $opts[CURLOPT_HEADER] = true;
    } else {
        $opts[CURLOPT_HEADER] = true;
        $opts[CURLOPT_RANGE] = '0-4095';
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    $body = '';
    if (is_string($raw) && !$headOnly) {
        $headerSize = strpos($raw, "\r\n\r\n");
        $body = $headerSize !== false ? substr($raw, $headerSize + 4) : $raw;
    }

    return [
        'ok' => $raw !== false,
        'code' => $code,
        'content_type' => $ctype,
        'error' => $error,
        'body' => $body,
        'needs_auth' => in_array($code, [401, 403], true),
        'is_image' => camProxyLooksLikeImage($body, $ctype),
    ];
}

function camProxyProbeUrl(string $url, string $user = '', string $pass = '', int $timeout = 4): array
{
    $res = camProxyRequest($url, $user, $pass, $timeout, false);

    if (!$res['ok']) {
        return ['status' => 'error', 'code' => 0, 'error' => $res['error']];
    }

    if ($res['needs_auth'] && !$res['is_image']) {
        return ['status' => 'auth', 'code' => $res['code'], 'content_type' => $res['content_type']];
    }

    if ($res['code'] >= 200 && $res['code'] < 400 && $res['is_image']) {
        return ['status' => 'ok', 'code' => $res['code'], 'content_type' => $res['content_type']];
    }

    if ($res['code'] >= 200 && $res['code'] < 400) {
        return ['status' => 'maybe', 'code' => $res['code'], 'content_type' => $res['content_type']];
    }

    return ['status' => 'fail', 'code' => $res['code'], 'error' => $res['error']];
}

function camProxyFetchBinary(string $url, string $user = '', string $pass = '', int $timeout = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Getway-CIA-Camera/1.1)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPAUTH => CURLAUTH_ANY,
        CURLOPT_USERPWD => $user !== '' ? ($user . ':' . $pass) : '',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 400) {
        return ['ok' => false, 'error' => $error ?: "HTTP {$code}"];
    }

    if (!camProxyLooksLikeImage((string) $body, $ctype)) {
        return ['ok' => false, 'error' => 'Response is not an image stream.'];
    }

    return ['ok' => true, 'body' => $body, 'content_type' => $ctype ?: 'image/jpeg'];
}

function camProxyBrandRtspPaths(string $brand): array
{
    $brand = strtolower(trim($brand));
    if (in_array($brand, ['v380', 'v380hik', 'v380-hik'], true)) {
        return [
            '/live/ch00_0',
            '/live/ch00_1',
            '/live/ch0',
            '/live/ch1',
            '/stream1',
            '/stream2',
            '/11',
            '/12',
            '/1',
            '/user=admin&password=&channel=1&stream=0.sdp',
            '/cam/realmonitor?channel=1&subtype=0',
            '/h264/ch1/main/av_stream',
        ];
    }
    if (in_array($brand, ['hikvision', 'hik'], true)) {
        return [
            '/Streaming/Channels/101',
            '/ISAPI/Streaming/channels/101',
            '/h264/ch1/main/av_stream',
            '/cam/realmonitor?channel=1&subtype=0',
        ];
    }
    return [
        '/stream1',
        '/stream2',
        '/live/ch0',
        '/live/ch00_0',
        '/11',
        '/cam/realmonitor?channel=1&subtype=0',
        '/h264/ch1/main/av_stream',
    ];
}

function camProxyStreamsForIp(string $ip, string $user, string $pass, string $brand, array $openPorts): array
{
    $streams = [];
    $rtspPaths = camProxyBrandRtspPaths($brand);

    if (in_array(554, $openPorts, true)) {
        foreach ($rtspPaths as $path) {
            $url = camProxyBuildUrl('rtsp', $ip, 554, $path, $user, $pass);
            $label = in_array(strtolower($brand), ['v380', 'v380hik', 'v380-hik'], true)
                ? "V380 · {$path}"
                : "RTSP · {$path}";
            $streams[] = [
                'label' => $label,
                'url' => $url,
                'display_url' => "rtsp://{$ip}:554{$path}",
                'is_rtsp' => true,
                'confidence' => 'high',
            ];
            if (count($streams) >= 4) {
                break;
            }
        }
    }

    if (in_array(80, $openPorts, true) || in_array(8080, $openPorts, true)) {
        $httpPort = in_array(80, $openPorts, true) ? 80 : 8080;
        foreach (['/video.mjpg', '/cgi-bin/snapshot.cgi', '/snap.jpg'] as $path) {
            $url = camProxyBuildUrl('http', $ip, $httpPort, $path, $user, $pass);
            $probe = camProxyProbeUrl($url, $user, $pass, 2);
            if (in_array($probe['status'], ['ok', 'maybe', 'auth'], true)) {
                $streams[] = [
                    'label' => "HTTP :{$httpPort} · {$path}",
                    'url' => $url,
                    'display_url' => "http://{$ip}:{$httpPort}{$path}",
                    'is_rtsp' => false,
                    'confidence' => $probe['status'] === 'ok' ? 'high' : 'low',
                ];
                break;
            }
        }
    }

    return $streams;
}

function camProxySubnetFromIp(string $ip): string
{
    if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$/', trim($ip), $m)) {
        return $m[1];
    }
    return '192.168.0';
}

$action = (string) ($_GET['action'] ?? 'probe');

if ($action === 'probe') {
    $ip = camProxyNormalizeIp((string) ($_GET['ip'] ?? ''));
    if ($ip === '') {
        camProxyJson(400, ['ok' => false, 'message' => 'Valid camera IP or hostname required.']);
    }

    $user = trim((string) ($_GET['user'] ?? ''));
    $pass = trim((string) ($_GET['pass'] ?? ''));
    $brand = strtolower(trim((string) ($_GET['brand'] ?? 'v380')));

    $paths = [
        '/video.mjpg',
        '/mjpg/video.mjpg',
        '/cgi-bin/mjpg/video.cgi',
        '/cgi-bin/snapshot.cgi',
        '/cgi-bin/snapshot.cgi?channel=1',
        '/snap.jpg',
        '/snapshot.jpg',
        '/jpg/image.jpg',
        '/image/jpeg.cgi',
        '/tmpfs/auto.jpg',
        '/onvif-http/snapshot?Profile_1',
        '/webcapture.jpg?command=snap&channel=1',
        '/ISAPI/Streaming/channels/101/picture',
        '/ISAPI/Streaming/channels/1/picture',
        '/Streaming/Channels/101/picture',
        '/cgi-bin/api.cgi?cmd=Snap&channel=0',
        '/videostream.cgi',
        '/stream',
        '/live',
        '/cam/realmonitor?channel=1&subtype=0',
        '/cam/realmonitor?channel=1&subtype=1',
        '/axis-cgi/mjpg/video.cgi',
        '/ISAPI/Streaming/channels/101/httpPreview',
        '/onvif/snapshot',
        '/api/v1/stream',
        '/',
    ];

    $ports = [
        ['scheme' => 'http', 'port' => 80],
        ['scheme' => 'http', 'port' => 8080],
        ['scheme' => 'http', 'port' => 8000],
        ['scheme' => 'http', 'port' => 88],
        ['scheme' => 'http', 'port' => 5540],
        ['scheme' => 'http', 'port' => 37777],
        ['scheme' => 'https', 'port' => 443],
        ['scheme' => 'https', 'port' => 8443],
    ];

    $openPorts = [];
    foreach ([80, 443, 8080, 8000, 554, 37777, 34567] as $p) {
        if (camProxyTcpOpen($ip, $p, 1.8)) {
            $openPorts[] = $p;
        }
    }

    $found = [];
    $authHints = [];
    $tried = 0;

    foreach ($ports as $entry) {
        foreach ($paths as $path) {
            $tried++;
            $url = camProxyBuildUrl($entry['scheme'], $ip, $entry['port'], $path, $user, $pass);
            $probe = camProxyProbeUrl($url, $user, $pass, 3);

            if ($probe['status'] === 'auth') {
                $authHints[] = [
                    'label' => "{$entry['scheme']}://{$ip}:{$entry['port']}{$path} (needs login)",
                    'url' => $url,
                    'display_url' => "{$entry['scheme']}://{$ip}:{$entry['port']}{$path}",
                    'needs_auth' => true,
                ];
                continue;
            }

            if ($probe['status'] !== 'ok' && $probe['status'] !== 'maybe') {
                continue;
            }

            $label = strtoupper($entry['scheme']) . " :{$entry['port']} · {$path}";
            if ($probe['status'] === 'maybe') {
                $label .= ' (try connect)';
            }

            $found[] = [
                'label' => $label,
                'url' => $url,
                'display_url' => "{$entry['scheme']}://{$ip}:{$entry['port']}{$path}",
                'port' => $entry['port'],
                'path' => $path,
                'content_type' => $probe['content_type'] ?? '',
                'confidence' => $probe['status'] === 'ok' ? 'high' : 'low',
            ];

            if (count($found) >= 10) {
                break 2;
            }
        }
    }

  if (count($found) === 0 && count($authHints) > 0) {
        $found = array_slice($authHints, 0, 6);
    }

    if (count($found) === 0 && camProxyTcpOpen($ip, 554, 1.5)) {
        $rtspPaths = camProxyBrandRtspPaths($brand);
        foreach ($rtspPaths as $path) {
            $url = camProxyBuildUrl('rtsp', $ip, 554, $path, $user, $pass);
            $found[] = [
                'label' => (in_array($brand, ['v380', 'v380hik', 'v380-hik'], true) ? 'V380 ' : 'RTSP ') . ":554 · {$path}",
                'url' => $url,
                'display_url' => "rtsp://{$ip}:554{$path}",
                'port' => 554,
                'path' => $path,
                'content_type' => 'rtsp',
                'confidence' => 'rtsp',
                'is_rtsp' => true,
            ];
            if (count($found) >= 8) {
                break;
            }
        }
    }

    $message = 'No camera stream found on common paths.';
    $hints = [];

    if (count($openPorts) === 0) {
        $message = 'Cannot reach camera on network. Check IP, power, and same Wi‑Fi/LAN.';
        $hints[] = 'Make sure this PC and camera are on the same network (192.168.0.x).';
        $hints[] = 'Ping the camera: open CMD and run ping ' . $ip;
    } elseif (in_array(554, $openPorts, true) && !in_array(80, $openPorts, true) && !in_array(8080, $openPorts, true)) {
        $message = 'Camera uses RTSP (port 554) — not HTTP. RTSP URLs listed below.';
        $hints[] = 'Install ffmpeg on this PC for RTSP capture, or enable HTTP/MJPEG in camera settings.';
        $hints[] = 'Try: rtsp://' . $ip . ':554/stream1 with your username/password.';
    } elseif (count($authHints) > 0 && $user === '') {
        $message = 'Camera responded but needs username/password.';
        $hints[] = 'Enter camera login (often admin / your camera password) then Search again.';
    } elseif (count($found) === 0) {
        $hints[] = 'Paste manual stream URL from camera manual (MJPEG or snapshot).';
        $hints[] = 'Open ports detected: ' . implode(', ', $openPorts);
    } else {
        $message = 'Camera streams found — click one to connect.';
    }

    camProxyJson(200, [
        'ok' => true,
        'ip' => $ip,
        'cameras' => $found,
        'open_ports' => $openPorts,
        'rtsp_detected' => in_array(554, $openPorts, true),
        'ffmpeg_available' => camProxyFfmpegPath() !== '',
        'tried' => $tried,
        'message' => $message,
        'hints' => $hints,
    ]);
}

if ($action === 'scan') {
    $subnet = (string) ($_GET['subnet'] ?? '192.168.0');
    if (!preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/', $subnet)) {
        $subnet = '192.168.0';
    }
    $start = max(1, min(254, (int) ($_GET['start'] ?? 1)));
    $end = max($start, min(254, (int) ($_GET['end'] ?? ($start + 29))));
    $brand = strtolower(trim((string) ($_GET['brand'] ?? 'v380')));
    $user = trim((string) ($_GET['user'] ?? ''));
    $pass = trim((string) ($_GET['pass'] ?? ''));

    $hosts = [];
    for ($i = $start; $i <= $end; $i++) {
        $ip = "{$subnet}.{$i}";
        $openPorts = [];
        foreach ([554, 80, 8080, 8000] as $port) {
            if (camProxyTcpOpen($ip, $port, 0.12)) {
                $openPorts[] = $port;
            }
        }
        if ($openPorts === []) {
            continue;
        }

        $streams = camProxyStreamsForIp($ip, $user, $pass, $brand, $openPorts);
        if ($streams === []) {
            continue;
        }

        $hosts[] = [
            'ip' => $ip,
            'open_ports' => $openPorts,
            'brand_guess' => in_array(554, $openPorts, true) ? 'V380 / RTSP Camera' : 'IP Camera',
            'cameras' => $streams,
            'best_url' => $streams[0]['url'] ?? null,
        ];
    }

    camProxyJson(200, [
        'ok' => true,
        'subnet' => $subnet,
        'start' => $start,
        'end' => $end,
        'hosts' => $hosts,
        'ffmpeg_available' => camProxyFfmpegPath() !== '',
        'brand' => $brand,
    ]);
}

if ($action === 'snapshot') {
    $camera = trim((string) ($_GET['camera'] ?? ''));
    if ($camera === '' || !preg_match('#^https?://#i', $camera) && !preg_match('#^rtsp://#i', $camera)) {
        camProxyJson(400, ['ok' => false, 'message' => 'Invalid camera URL (http/https/rtsp).']);
    }

    if (preg_match('#^rtsp://#i', $camera)) {
        $parsed = parse_url($camera);
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            camProxyJson(400, ['ok' => false, 'message' => 'Camera host not allowed.']);
        }
        $fetch = camProxyRtspSnapshot($camera);
        if (!$fetch['ok']) {
            camProxyJson(502, ['ok' => false, 'message' => $fetch['error'] ?? 'RTSP capture failed.']);
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store');
        echo $fetch['body'];
        exit;
    }

    if ($camera === '' || !preg_match('#^https?://#i', $camera)) {
        camProxyJson(400, ['ok' => false, 'message' => 'Invalid camera URL.']);
    }

    $parsed = parse_url($camera);
    $host = strtolower((string) ($parsed['host'] ?? ''));
    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
        camProxyJson(400, ['ok' => false, 'message' => 'Camera host not allowed.']);
    }

    $user = trim((string) ($_GET['user'] ?? ''));
    $pass = trim((string) ($_GET['pass'] ?? ''));
    if ($user === '' && isset($parsed['user'])) {
        $user = (string) $parsed['user'];
        $pass = (string) ($parsed['pass'] ?? '');
    }

    $fetch = camProxyFetchBinary($camera, $user, $pass);
    if (!$fetch['ok']) {
        camProxyJson(502, ['ok' => false, 'message' => 'Could not fetch camera frame.', 'error' => $fetch['error'] ?? '']);
    }

    header('Content-Type: ' . ($fetch['content_type'] ?? 'image/jpeg'));
    header('Cache-Control: no-store');
    echo $fetch['body'];
    exit;
}

camProxyJson(400, ['ok' => false, 'message' => 'Unknown action.']);
