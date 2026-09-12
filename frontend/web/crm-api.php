<?php

declare(strict_types=1);

/**
 * Admin CRM API — Facebook / Instagram search via Apify + saved leads.
 *
 * POST ?action=search  JSON { query, location?, limit?, platform? }
 * GET  ?action=image&u=URL   (proxied profile / media images)
 * GET  ?action=saved
 * GET  ?action=regions
 * GET  ?action=email-template
 * POST ?action=email-template JSON { subject, body }
 * POST ?action=send-emails JSON { platform?, ids? }
 */

require_once __DIR__ . '/auth-init.php';
require_once __DIR__ . '/env-load.php';
gwLoadEnv();
gwAuthStartSession();

function crmJson(int $code, array $payload): never
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = $_SESSION['gw_auth_user'] ?? null;
if (!is_array($user) || strtolower((string) ($user['role'] ?? '')) !== 'admin') {
    crmJson(401, ['ok' => false, 'message' => 'Admin login required.']);
}

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'search')));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function crmReadJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

function crmApifyToken(): string
{
    return trim((string) (getenv('APIFY_TOKEN') ?: getenv('APIFY_API_TOKEN') ?: ''));
}

function crmStorePath(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'crm-leads.json';
}

function crmLoadLeads(): array
{
    $path = crmStorePath();
    if (!is_file($path)) {
        return ['leads' => []];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return ['leads' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['leads']) || !is_array($data['leads'])) {
        return ['leads' => []];
    }
    return $data;
}

function crmSaveLeads(array $data): bool
{
    $path = crmStorePath();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function crmEmailTemplatePath(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'crm-email-template.json';
}

function crmDefaultEmailTemplate(): array
{
    return [
        'subject' => 'Job application / Application for opportunities at {{name}}',
        'body' => "Dear Hiring Team at {{name}},\n\n"
            . "I hope this email finds you well. My name is Steven Abalwambo, and I am writing to express my strong interest in available job opportunities within your organization.\n\n"
            . "I am hardworking, reliable, and eager to contribute my skills while growing with a professional team. I would be grateful for the chance to discuss how I can support your goals, whether through a current opening or future opportunities.\n\n"
            . "Please feel free to contact me if there is a suitable role, or if you would like to schedule a short conversation.\n\n"
            . "Kind regards,\n"
            . "Steven Abalwambo\n"
            . "Email: stevenabalwambo@gmail.com\n"
            . "Phone: +255 XXX XXX XXX",
        'fromEmail' => 'stevenabalwambo@gmail.com',
        'fromName' => 'Steven Abalwambo',
        'updatedAt' => null,
    ];
}

function crmLoadEmailTemplate(): array
{
    $defaults = crmDefaultEmailTemplate();
    $path = crmEmailTemplatePath();
    if (!is_file($path)) {
        return $defaults;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $defaults;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $defaults;
    }
    return [
        'subject' => trim((string) ($data['subject'] ?? $defaults['subject'])) ?: $defaults['subject'],
        'body' => trim((string) ($data['body'] ?? $defaults['body'])) ?: $defaults['body'],
        'fromEmail' => trim((string) ($data['fromEmail'] ?? $defaults['fromEmail'])) ?: $defaults['fromEmail'],
        'fromName' => trim((string) ($data['fromName'] ?? $defaults['fromName'])) ?: $defaults['fromName'],
        'updatedAt' => $data['updatedAt'] ?? null,
    ];
}

function crmSaveEmailTemplate(array $template): bool
{
    $path = crmEmailTemplatePath();
    $payload = [
        'subject' => trim((string) ($template['subject'] ?? '')),
        'body' => trim((string) ($template['body'] ?? '')),
        'fromEmail' => 'stevenabalwambo@gmail.com',
        'fromName' => trim((string) ($template['fromName'] ?? 'Steven Abalwambo')) ?: 'Steven Abalwambo',
        'updatedAt' => gmdate('c'),
    ];
    if ($payload['subject'] === '' || $payload['body'] === '') {
        return false;
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * @param array<string, mixed> $lead
 */
function crmRenderEmailPlaceholders(string $text, array $lead): string
{
    $name = trim((string) ($lead['name'] ?? $lead['title'] ?? 'your company'));
    $map = [
        '{{name}}' => $name,
        '{{title}}' => trim((string) ($lead['title'] ?? $name)),
        '{{username}}' => trim((string) ($lead['username'] ?? '')),
        '{{platform}}' => trim((string) ($lead['platform'] ?? '')),
        '{{email}}' => trim((string) ($lead['email'] ?? '')),
        '{{phone}}' => trim((string) ($lead['phone'] ?? '')),
        '{{website}}' => trim((string) ($lead['website'] ?? '')),
        '{{address}}' => trim((string) ($lead['address'] ?? '')),
        '{{company}}' => $name,
    ];
    return strtr($text, $map);
}

function crmMailConfig(): array
{
    return [
        'host' => trim((string) (getenv('CRM_SMTP_HOST') ?: 'smtp.gmail.com')),
        'port' => (int) (getenv('CRM_SMTP_PORT') ?: 587),
        'user' => trim((string) (getenv('CRM_SMTP_USER') ?: getenv('CRM_MAIL_FROM') ?: 'stevenabalwambo@gmail.com')),
        'pass' => trim((string) (getenv('CRM_SMTP_PASS') ?: getenv('CRM_SMTP_PASSWORD') ?: '')),
        'fromEmail' => trim((string) (getenv('CRM_MAIL_FROM') ?: 'stevenabalwambo@gmail.com')),
        'fromName' => trim((string) (getenv('CRM_MAIL_FROM_NAME') ?: 'Steven Abalwambo')),
        'secure' => strtolower(trim((string) (getenv('CRM_SMTP_SECURE') ?: 'tls'))),
    ];
}

function crmSmtpExpect($socket, string $expectPrefix): string
{
    $data = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    if ($data === '' || !str_starts_with(trim($data), $expectPrefix)) {
        throw new RuntimeException('SMTP unexpected response: ' . trim($data !== '' ? $data : '(empty)'));
    }
    return $data;
}

function crmSmtpCommand($socket, string $command, string $expectPrefix): string
{
    fwrite($socket, $command . "\r\n");
    return crmSmtpExpect($socket, $expectPrefix);
}

/**
 * Send one email via SMTP (Gmail-compatible STARTTLS).
 */
function crmSendSmtpMail(string $to, string $subject, string $bodyText): void
{
    $cfg = crmMailConfig();
    if ($cfg['pass'] === '') {
        throw new RuntimeException('CRM_SMTP_PASS is not configured in .env (use a Gmail App Password).');
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid recipient email.');
    }

    $host = $cfg['host'];
    $port = $cfg['port'] > 0 ? $cfg['port'] : 587;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $host . ':' . $port,
        $errno,
        $errstr,
        25,
        STREAM_CLIENT_CONNECT
    );
    if ($socket === false) {
        throw new RuntimeException('Could not connect to SMTP: ' . $errstr);
    }
    stream_set_timeout($socket, 30);

    try {
        crmSmtpExpect($socket, '220');
        crmSmtpCommand($socket, 'EHLO localhost', '250');
        if ($cfg['secure'] === 'tls' || $port === 587) {
            crmSmtpCommand($socket, 'STARTTLS', '220');
            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($crypto !== true) {
                throw new RuntimeException('SMTP STARTTLS failed.');
            }
            crmSmtpCommand($socket, 'EHLO localhost', '250');
        }
        crmSmtpCommand($socket, 'AUTH LOGIN', '334');
        crmSmtpCommand($socket, base64_encode($cfg['user']), '334');
        crmSmtpCommand($socket, base64_encode($cfg['pass']), '235');

        $from = $cfg['fromEmail'];
        $fromName = $cfg['fromName'];
        crmSmtpCommand($socket, 'MAIL FROM:<' . $from . '>', '250');
        crmSmtpCommand($socket, 'RCPT TO:<' . $to . '>', '250');
        crmSmtpCommand($socket, 'DATA', '354');

        $headers = [
            'Date: ' . date('r'),
            'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $from),
            'To: <' . $to . '>',
            'Reply-To: <' . $from . '>',
            'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: Getways-CRM',
        ];
        $encodedBody = chunk_split(base64_encode($bodyText));
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody . "\r\n.";
        fwrite($socket, $data . "\r\n");
        crmSmtpExpect($socket, '250');
        fwrite($socket, "QUIT\r\n");
    } finally {
        fclose($socket);
    }
}

/**
 * @return list<string>
 */
function crmLeadEmails(array $lead): array
{
    $emails = [];
    if (!empty($lead['emails']) && is_array($lead['emails'])) {
        foreach ($lead['emails'] as $email) {
            $email = trim((string) $email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = strtolower($email);
            }
        }
    }
    $single = trim((string) ($lead['email'] ?? ''));
    if ($single !== '' && filter_var($single, FILTER_VALIDATE_EMAIL)) {
        $emails[] = strtolower($single);
    }
    return array_values(array_unique($emails));
}

/**
 * @return list<string>
 */
function crmTanzaniaRegions(): array
{
    return [
        'Arusha',
        'Dar es Salaam',
        'Dodoma',
        'Geita',
        'Iringa',
        'Kagera',
        'Katavi',
        'Kigoma',
        'Kilimanjaro',
        'Lindi',
        'Manyara',
        'Mara',
        'Mbeya',
        'Morogoro',
        'Mtwara',
        'Mwanza',
        'Njombe',
        'Pwani',
        'Rukwa',
        'Ruvuma',
        'Shinyanga',
        'Simiyu',
        'Singida',
        'Songwe',
        'Tabora',
        'Tanga',
        'Kaskazini Unguja',
        'Kusini Unguja',
        'Mjini Magharibi',
        'Kaskazini Pemba',
        'Kusini Pemba',
    ];
}

/**
 * @param list<string>|string|null $value
 */
function crmAsList($value): array
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }
        return array_values(array_unique($out));
    }
    if (is_string($value) || is_numeric($value)) {
        $text = trim((string) $value);
        return $text !== '' ? [$text] : [];
    }
    return [];
}

function crmIsHttpUrl(string $url): bool
{
    return (bool) preg_match('#^https?://#i', $url);
}

/**
 * @param mixed $value
 */
function crmExtractUrl($value): string
{
    if (is_string($value) && crmIsHttpUrl(trim($value))) {
        return trim($value);
    }
    if (!is_array($value)) {
        return '';
    }
    foreach (['uri', 'url', 'src', 'href', 'thumbnail', 'display_url', 'profile_pic_url'] as $key) {
        if (!empty($value[$key]) && is_string($value[$key]) && crmIsHttpUrl(trim($value[$key]))) {
            return trim($value[$key]);
        }
    }
    return '';
}

function crmProxyImageUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || !crmIsHttpUrl($url)) {
        return '';
    }
    return 'crm-api.php?action=image&u=' . rawurlencode($url);
}

function crmFacebookPictureCandidates(array $item): array
{
    $pageId = trim((string) ($item['pageId'] ?? $item['facebookId'] ?? ''));
    $pageName = trim((string) ($item['pageName'] ?? $item['username'] ?? ''));
    if ($pageName === '' && !empty($item['pageUrl'])) {
        if (preg_match('#facebook\.com/([^/?#]+)#i', (string) $item['pageUrl'], $m)) {
            $pageName = rawurldecode($m[1]);
        }
    }
    if ($pageName !== '' && in_array(strtolower($pageName), ['profile.php', 'pages', 'people'], true)) {
        $pageName = '';
    }

    $urls = [];
    // Prefer vanity page name — numeric IDs often resolve to Facebook's blank silhouette.
    if ($pageName !== '') {
        $urls[] = 'https://graph.facebook.com/' . rawurlencode($pageName) . '/picture?type=large';
        $urls[] = 'https://graph.facebook.com/' . rawurlencode($pageName) . '/picture?width=320&height=320';
        $urls[] = 'https://www.facebook.com/' . rawurlencode($pageName) . '/picture?type=large';
    }
    if ($pageId !== '' && preg_match('/^\d+$/', $pageId)) {
        $urls[] = 'https://graph.facebook.com/' . rawurlencode($pageId) . '/picture?type=large';
        $urls[] = 'https://graph.facebook.com/' . rawurlencode($pageId) . '/picture?width=320&height=320';
    }
    $website = trim((string) ($item['website'] ?? ''));
    if ($website !== '') {
        $host = preg_replace('#^https?://#i', '', $website);
        $host = preg_replace('#^www\.#i', '', (string) $host);
        $host = trim(explode('/', (string) $host)[0] ?? '');
        if ($host !== '' && str_contains($host, '.')) {
            $urls[] = 'https://logo.clearbit.com/' . rawurlencode($host);
            $urls[] = 'https://www.google.com/s2/favicons?sz=128&domain_url=' . rawurlencode('https://' . $host);
        }
    }
    return array_values(array_unique($urls));
}

function crmPickImage(array $item, string $platform = 'facebook'): string
{
    $candidates = [
        $item['profilePicUrlHD'] ?? null,
        $item['profilePicUrl'] ?? null,
        $item['profile_pic_url_hd'] ?? null,
        $item['profile_pic_url'] ?? null,
        $item['profilePicture'] ?? null,
        $item['profilePic'] ?? null,
        $item['profile_photo'] ?? null,
        $item['photo'] ?? null,
        $item['image'] ?? null,
        $item['displayUrl'] ?? null,
        $item['display_url'] ?? null,
        $item['thumbnailUrl'] ?? null,
        $item['thumbnail_src'] ?? null,
        $item['coverPhoto'] ?? null,
        $item['cover'] ?? null,
        $item['thumbnail'] ?? null,
        $item['picture'] ?? null,
        $item['ownerProfilePicUrl'] ?? null,
    ];
    if (!empty($item['owner']) && is_array($item['owner'])) {
        $candidates[] = $item['owner']['profile_pic_url'] ?? null;
        $candidates[] = $item['owner']['profilePicUrl'] ?? null;
    }
    foreach ($candidates as $url) {
        $resolved = crmExtractUrl($url);
        if ($resolved !== '') {
            return $resolved;
        }
    }

    if ($platform === 'facebook') {
        $fb = crmFacebookPictureCandidates($item);
        if ($fb !== []) {
            return $fb[0];
        }
    }

    return '';
}

/**
 * @return list<string>
 */
function crmPickThumbnails(array $item, string $platform = 'facebook'): array
{
    $thumbs = [];
    $bags = [
        $item['photos'] ?? null,
        $item['images'] ?? null,
        $item['media'] ?? null,
        $item['gallery'] ?? null,
        $item['thumbnails'] ?? null,
        $item['latestPosts'] ?? null,
        $item['topPosts'] ?? null,
        $item['relatedProfiles'] ?? null,
    ];
    foreach ($bags as $bag) {
        if (!is_array($bag)) {
            continue;
        }
        foreach ($bag as $entry) {
            $url = '';
            if (is_string($entry)) {
                $url = crmExtractUrl($entry);
            } elseif (is_array($entry)) {
                $url = crmExtractUrl($entry['displayUrl'] ?? $entry['display_url'] ?? $entry['thumbnail'] ?? $entry['url'] ?? $entry['src'] ?? $entry);
                if ($url === '') {
                    $url = crmPickImage($entry, $platform);
                }
            }
            if ($url !== '') {
                $thumbs[] = $url;
            }
            if (count($thumbs) >= 8) {
                break 2;
            }
        }
    }

    $cover = crmPickImage($item, $platform);
    if ($cover !== '') {
        array_unshift($thumbs, $cover);
    }
    if ($platform === 'facebook') {
        foreach (crmFacebookPictureCandidates($item) as $fbUrl) {
            $thumbs[] = $fbUrl;
        }
    }

    return array_values(array_unique(array_filter($thumbs)));
}

/**
 * @return list<string>
 */
function crmWrapImageList(array $urls): array
{
    $out = [];
    foreach ($urls as $url) {
        $proxied = crmProxyImageUrl((string) $url);
        if ($proxied !== '') {
            $out[] = $proxied;
        }
    }
    return array_values(array_unique($out));
}

function crmNormalizeFacebookItem(array $item): array
{
    $info = $item['info'] ?? [];
    if (is_string($info)) {
        $info = [$info];
    }
    if (!is_array($info)) {
        $info = [];
    }
    $about = '';
    if (!empty($item['about_me']['text']) && is_string($item['about_me']['text'])) {
        $about = trim($item['about_me']['text']);
    } elseif (!empty($item['about']) && is_string($item['about'])) {
        $about = trim($item['about']);
    } elseif (!empty($item['description']) && is_string($item['description'])) {
        $about = trim($item['description']);
    } else {
        $about = trim(implode(' ', array_map('strval', $info)));
    }

    $emails = crmAsList($item['email'] ?? ($item['emails'] ?? []));
    $phones = crmAsList($item['phone'] ?? ($item['phones'] ?? []));
    $categories = crmAsList($item['categories'] ?? []);
    $pageId = trim((string) ($item['pageId'] ?? $item['facebookId'] ?? ''));
    $pageUrl = trim((string) ($item['pageUrl'] ?? $item['facebookUrl'] ?? ''));
    $title = trim((string) ($item['title'] ?? $item['pageName'] ?? 'Facebook page'));
    $name = trim((string) ($item['pageName'] ?? ''));
    if ($name === '' && $title !== '') {
        $name = preg_replace('/\s*\|\s*.*$/', '', $title) ?: $title;
    }

    $profilePicture = crmPickImage($item, 'facebook');
    $thumbnails = crmPickThumbnails($item, 'facebook');
    $imageCandidates = array_values(array_unique(array_filter(array_merge(
        $profilePicture !== '' ? [$profilePicture] : [],
        crmFacebookPictureCandidates($item),
        $thumbnails
    ))));

    return [
        'id' => $pageId !== '' ? 'fb_' . $pageId : 'fb_' . md5($pageUrl !== '' ? $pageUrl : $title),
        'platform' => 'facebook',
        'name' => $name !== '' ? $name : $title,
        'title' => $title,
        'username' => trim((string) ($item['pageName'] ?? '')),
        'pageUrl' => $pageUrl,
        'facebookUrl' => trim((string) ($item['facebookUrl'] ?? $pageUrl)),
        'instagramUrl' => '',
        'pageId' => $pageId,
        'phone' => $phones[0] ?? '',
        'phones' => $phones,
        'email' => $emails[0] ?? '',
        'emails' => $emails,
        'website' => trim((string) ($item['website'] ?? '')),
        'address' => trim((string) ($item['address'] ?? '')),
        'description' => $about,
        'categories' => $categories,
        'likes' => isset($item['likes']) ? (int) $item['likes'] : null,
        'followers' => isset($item['followers']) ? (int) $item['followers'] : null,
        'rating' => trim((string) ($item['rating'] ?? '')),
        'ratingOverall' => $item['ratingOverall'] ?? null,
        'ratingCount' => isset($item['ratingCount']) ? (int) $item['ratingCount'] : null,
        'creationDate' => trim((string) ($item['creation_date'] ?? $item['creationDate'] ?? '')),
        'adStatus' => trim((string) ($item['ad_status'] ?? $item['adStatus'] ?? '')),
        'messenger' => trim((string) ($item['messenger'] ?? '')),
        'priceRange' => trim((string) ($item['priceRange'] ?? '')),
        'profilePicture' => crmProxyImageUrl($profilePicture !== '' ? $profilePicture : ($imageCandidates[0] ?? '')),
        'profilePictureRaw' => $profilePicture !== '' ? $profilePicture : ($imageCandidates[0] ?? ''),
        'imageCandidates' => crmWrapImageList($imageCandidates),
        'thumbnails' => crmWrapImageList($thumbnails),
    ];
}

function crmNormalizeInstagramItem(array $item): array
{
    $username = trim((string) ($item['username'] ?? $item['ownerUsername'] ?? ''));
    if ($username === '' && !empty($item['owner']['username'])) {
        $username = trim((string) $item['owner']['username']);
    }
    $fullName = trim((string) ($item['fullName'] ?? $item['full_name'] ?? $item['name'] ?? ''));
    $id = trim((string) ($item['id'] ?? $item['pk'] ?? ''));
    $url = trim((string) ($item['url'] ?? $item['inputUrl'] ?? ''));
    if ($url === '' && $username !== '') {
        $url = 'https://www.instagram.com/' . rawurlencode($username) . '/';
    }

    $emails = crmAsList($item['email'] ?? ($item['emails'] ?? ($item['contactPhoneNumber'] ?? [])));
    $phones = crmAsList($item['phone'] ?? ($item['phones'] ?? ($item['contactPhoneNumber'] ?? [])));
    if ($phones === [] && !empty($item['businessPhoneNumber'])) {
        $phones = crmAsList($item['businessPhoneNumber']);
    }
    if ($emails === [] && !empty($item['businessEmail'])) {
        $emails = crmAsList($item['businessEmail']);
    }

    $website = trim((string) ($item['externalUrl'] ?? $item['website'] ?? ''));
    if ($website === '' && !empty($item['externalUrls'][0]['url'])) {
        $website = trim((string) $item['externalUrls'][0]['url']);
    }

    $bio = trim((string) ($item['biography'] ?? $item['bio'] ?? $item['description'] ?? ''));
    $categories = crmAsList($item['businessCategoryName'] ?? ($item['category'] ?? ($item['categories'] ?? [])));
    $addressParts = array_filter([
        trim((string) ($item['address'] ?? '')),
        trim((string) ($item['cityName'] ?? $item['city'] ?? '')),
        trim((string) ($item['addressStreet'] ?? '')),
    ]);
    $address = trim(implode(', ', $addressParts));
    if ($address === '' && !empty($item['locationName'])) {
        $address = trim((string) $item['locationName']);
    }

    $profilePicture = crmPickImage($item, 'instagram');
    $thumbnails = crmPickThumbnails($item, 'instagram');
    $name = $fullName !== '' ? $fullName : ($username !== '' ? '@' . $username : 'Instagram profile');

    return [
        'id' => $id !== '' ? 'ig_' . $id : 'ig_' . md5($url !== '' ? $url : $username),
        'platform' => 'instagram',
        'name' => $name,
        'title' => $name,
        'username' => $username,
        'pageUrl' => $url,
        'facebookUrl' => '',
        'instagramUrl' => $url,
        'pageId' => $id,
        'phone' => $phones[0] ?? '',
        'phones' => $phones,
        'email' => $emails[0] ?? '',
        'emails' => $emails,
        'website' => $website,
        'address' => $address,
        'description' => $bio,
        'categories' => $categories,
        'likes' => isset($item['likesCount']) ? (int) $item['likesCount'] : (isset($item['likes']) ? (int) $item['likes'] : null),
        'followers' => isset($item['followersCount']) ? (int) $item['followersCount'] : (isset($item['followers']) ? (int) $item['followers'] : null),
        'rating' => '',
        'ratingOverall' => null,
        'ratingCount' => null,
        'creationDate' => '',
        'adStatus' => '',
        'messenger' => '',
        'priceRange' => '',
        'postsCount' => isset($item['postsCount']) ? (int) $item['postsCount'] : null,
        'isBusiness' => !empty($item['isBusinessAccount']) || !empty($item['isBusiness']),
        'verified' => !empty($item['verified']) || !empty($item['isVerified']),
        'profilePicture' => crmProxyImageUrl($profilePicture),
        'profilePictureRaw' => $profilePicture,
        'imageCandidates' => crmWrapImageList(array_values(array_filter(array_merge(
            $profilePicture !== '' ? [$profilePicture] : [],
            $thumbnails
        )))),
        'thumbnails' => crmWrapImageList($thumbnails),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function crmApifyRun(string $actorId, array $payload): array
{
    $token = crmApifyToken();
    if ($token === '') {
        crmJson(503, ['ok' => false, 'message' => 'APIFY_TOKEN is not configured in .env']);
    }

    $url = 'https://api.apify.com/v2/acts/' . rawurlencode($actorId) . '/run-sync-get-dataset-items'
        . '?token=' . rawurlencode($token)
        . '&format=json'
        . '&clean=1';

    $ch = curl_init($url);
    if ($ch === false) {
        crmJson(500, ['ok' => false, 'message' => 'Could not start Apify request.']);
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 240,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_USERAGENT => 'Getways-App-CRM/1.1',
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $body === '') {
        crmJson(502, ['ok' => false, 'message' => $err !== '' ? $err : 'Empty response from Apify.']);
    }

    $decoded = json_decode($body, true);
    if ($http >= 400) {
        $message = 'Apify search failed.';
        if (is_array($decoded)) {
            $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $message);
        }
        crmJson($http >= 500 ? 502 : $http, ['ok' => false, 'message' => $message, 'httpStatus' => $http]);
    }

    if (!is_array($decoded)) {
        crmJson(502, ['ok' => false, 'message' => 'Apify returned invalid JSON.']);
    }

    if ($decoded !== [] && array_keys($decoded) !== range(0, count($decoded) - 1)) {
        $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'Apify search failed.');
        crmJson(502, ['ok' => false, 'message' => $message]);
    }

    $items = [];
    foreach ($decoded as $row) {
        if (is_array($row)) {
            $items[] = $row;
        }
    }
    return $items;
}

/**
 * @return list<array<string, mixed>>
 */
function crmRunFacebookSearch(string $query, string $location, int $limit): array
{
    $payload = [
        'categories' => [$query],
        'resultsLimit' => $limit,
        'locations' => [],
    ];
    if ($location !== '') {
        $payload['locations'] = [$location . ', Tanzania'];
    }

    $raw = crmApifyRun('apify~facebook-search-scraper', $payload);
    $items = [];
    foreach ($raw as $row) {
        $items[] = crmNormalizeFacebookItem($row);
    }
    return $items;
}

/**
 * @return list<array<string, mixed>>
 */
function crmRunInstagramSearch(string $query, string $location, int $limit): array
{
    $search = $query;
    if ($location !== '') {
        $search = trim($query . ' ' . $location . ' Tanzania');
    }

    $payload = [
        'search' => $search,
        'searchType' => 'user',
        'searchLimit' => $limit,
        'resultsType' => 'details',
        'resultsLimit' => 1,
    ];

    $raw = crmApifyRun('apify~instagram-scraper', $payload);
    $items = [];
    $seen = [];
    foreach ($raw as $row) {
        $normalized = crmNormalizeInstagramItem($row);
        $key = (string) ($normalized['id'] ?? '');
        if ($key !== '' && isset($seen[$key])) {
            continue;
        }
        if ($key !== '') {
            $seen[$key] = true;
        }
        // Prefer profile-like rows (skip pure posts when username missing and it's a post)
        if (($normalized['username'] ?? '') === '' && ($normalized['name'] ?? '') === 'Instagram profile') {
            continue;
        }
        $items[] = $normalized;
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
}

function crmNormalizeLinkedinItem(array $item): array
{
    $jobId = trim((string) ($item['id'] ?? ''));
    $title = trim((string) ($item['title'] ?? 'LinkedIn job'));
    $company = trim((string) ($item['companyName'] ?? ''));
    $link = trim((string) ($item['link'] ?? ''));
    $companyUrl = trim((string) ($item['companyLinkedinUrl'] ?? ''));
    $posterUrl = trim((string) ($item['jobPosterProfileUrl'] ?? ''));
    $pageUrl = $link !== '' ? $link : ($companyUrl !== '' ? $companyUrl : $posterUrl);

    $salary = $item['salaryInfo'] ?? [];
    if (is_array($salary)) {
        $salaryText = trim(implode(' – ', array_map('strval', $salary)));
    } else {
        $salaryText = trim((string) $salary);
    }

    $categories = array_values(array_filter([
        trim((string) ($item['employmentType'] ?? '')),
        trim((string) ($item['seniorityLevel'] ?? '')),
        trim((string) ($item['jobFunction'] ?? '')),
        trim((string) ($item['industries'] ?? '')),
    ]));

    $benefits = $item['benefits'] ?? [];
    if (!is_array($benefits)) {
        $benefits = $benefits !== '' && $benefits !== null ? [(string) $benefits] : [];
    }

    $description = trim((string) ($item['descriptionText'] ?? $item['companyDescription'] ?? ''));
    if ($description === '' && !empty($item['descriptionHtml']) && is_string($item['descriptionHtml'])) {
        $description = trim(html_entity_decode(strip_tags($item['descriptionHtml']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $profilePicture = crmExtractUrl($item['companyLogo'] ?? null);
    if ($profilePicture === '') {
        $profilePicture = crmExtractUrl($item['jobPosterPhoto'] ?? null);
    }
    $thumbs = array_values(array_filter([
        crmExtractUrl($item['companyLogo'] ?? null),
        crmExtractUrl($item['jobPosterPhoto'] ?? null),
    ]));

    $name = $company !== '' ? $company : ($item['jobPosterName'] ?? $title);
    $followers = isset($item['companyEmployeesCount']) ? (int) $item['companyEmployeesCount'] : null;

    return [
        'id' => $jobId !== '' ? 'li_' . $jobId : 'li_' . md5($pageUrl !== '' ? $pageUrl : $title . $company),
        'platform' => 'linkedin',
        'name' => (string) $name,
        'title' => $title,
        'username' => trim((string) ($item['jobPosterName'] ?? '')),
        'pageUrl' => $pageUrl,
        'facebookUrl' => '',
        'instagramUrl' => '',
        'linkedinUrl' => $pageUrl,
        'companyUrl' => $companyUrl,
        'pageId' => $jobId,
        'phone' => '',
        'phones' => [],
        'email' => '',
        'emails' => [],
        'website' => trim((string) ($item['companyWebsite'] ?? '')),
        'address' => trim((string) ($item['location'] ?? '')),
        'description' => $description,
        'categories' => $categories,
        'likes' => null,
        'followers' => $followers,
        'rating' => '',
        'ratingOverall' => null,
        'ratingCount' => null,
        'creationDate' => trim((string) ($item['postedAt'] ?? '')),
        'adStatus' => '',
        'messenger' => '',
        'priceRange' => $salaryText,
        'employmentType' => trim((string) ($item['employmentType'] ?? '')),
        'seniorityLevel' => trim((string) ($item['seniorityLevel'] ?? '')),
        'jobFunction' => trim((string) ($item['jobFunction'] ?? '')),
        'industries' => trim((string) ($item['industries'] ?? '')),
        'applicantsCount' => trim((string) ($item['applicantsCount'] ?? '')),
        'jobPosterName' => trim((string) ($item['jobPosterName'] ?? '')),
        'jobPosterTitle' => trim((string) ($item['jobPosterTitle'] ?? '')),
        'jobPosterPhoto' => crmProxyImageUrl(crmExtractUrl($item['jobPosterPhoto'] ?? null)),
        'benefits' => array_values(array_map('strval', $benefits)),
        'companyDescription' => trim((string) ($item['companyDescription'] ?? '')),
        'profilePicture' => crmProxyImageUrl($profilePicture),
        'profilePictureRaw' => $profilePicture,
        'imageCandidates' => crmWrapImageList($thumbs),
        'thumbnails' => crmWrapImageList($thumbs),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function crmRunLinkedinSearch(string $query, string $location, int $limit): array
{
    $payload = [
        'keywords' => $query,
        'datePosted' => 'anyTime',
        'companyIds' => [],
        'under10Applicants' => false,
        'autoConvertToAiSearch' => true,
        'scrapeCompany' => true,
        'limitPerSource' => $limit,
        'splitByLocation' => false,
    ];
    if ($location !== '') {
        $payload['location'] = $location . ', Tanzania';
    } else {
        $payload['location'] = 'Tanzania';
    }

    $raw = crmApifyRun('curious_coder~linkedin-jobs-scraper', $payload);
    $items = [];
    foreach ($raw as $row) {
        $items[] = crmNormalizeLinkedinItem($row);
        if (count($items) >= $limit) {
            break;
        }
    }
    return $items;
}

function crmProxyImage(): never
{
    $url = trim((string) ($_GET['u'] ?? ''));
    if ($url === '' || !crmIsHttpUrl($url)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid image URL';
        exit;
    }

    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    $allowed = false;
    $needles = [
        'facebook.com',
        'fbcdn.net',
        'fbsbx.com',
        'instagram.com',
        'cdninstagram.com',
        'linkedin.com',
        'licdn.com',
        'clearbit.com',
        'gstatic.com',
        'google.com',
        'unavatar.io',
        'googleusercontent.com',
        'ui-avatars.com',
    ];
    foreach ($needles as $needle) {
        if ($host === $needle || str_ends_with($host, '.' . $needle)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Host not allowed';
        exit;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        http_response_code(502);
        exit;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GetwaysCRM/1.1)',
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $body === '' || $http >= 400) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'Image unavailable';
        exit;
    }

    // Tiny broken/empty payloads should fail so the UI can try the next candidate.
    if (strlen($body) < 80) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Image unavailable';
        exit;
    }

    if ($ctype === '' || stripos($ctype, 'text/') === 0 || stripos($ctype, 'application/json') === 0) {
        $ctype = 'image/jpeg';
    }
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=86400');
    echo $body;
    exit;
}

if ($action === 'image' && $method === 'GET') {
    crmProxyImage();
}

if ($action === 'regions' && $method === 'GET') {
    crmJson(200, ['ok' => true, 'regions' => crmTanzaniaRegions()]);
}

if ($action === 'saved' && $method === 'GET') {
    $data = crmLoadLeads();
    $leads = array_values($data['leads']);
    usort($leads, static function ($a, $b) {
        return strcmp((string) ($b['savedAt'] ?? ''), (string) ($a['savedAt'] ?? ''));
    });
    crmJson(200, ['ok' => true, 'count' => count($leads), 'items' => $leads]);
}

if ($action === 'email-template' && $method === 'GET') {
    $template = crmLoadEmailTemplate();
    $cfg = crmMailConfig();
    crmJson(200, [
        'ok' => true,
        'template' => $template,
        'mailReady' => $cfg['pass'] !== '',
        'fromEmail' => $cfg['fromEmail'],
        'placeholders' => ['{{name}}', '{{title}}', '{{company}}', '{{platform}}', '{{email}}', '{{phone}}', '{{website}}', '{{address}}', '{{username}}'],
    ]);
}

if ($method === 'POST' && $action === 'email-template') {
    $input = crmReadJsonBody();
    $subject = trim((string) ($input['subject'] ?? ''));
    $body = trim((string) ($input['body'] ?? ''));
    $fromName = trim((string) ($input['fromName'] ?? 'Steven Abalwambo'));
    if ($subject === '' || mb_strlen($subject) < 3) {
        crmJson(422, ['ok' => false, 'message' => 'Subject is required.']);
    }
    if ($body === '' || mb_strlen($body) < 10) {
        crmJson(422, ['ok' => false, 'message' => 'Message body is required.']);
    }
    $template = [
        'subject' => $subject,
        'body' => $body,
        'fromName' => $fromName !== '' ? $fromName : 'Steven Abalwambo',
        'fromEmail' => 'stevenabalwambo@gmail.com',
    ];
    if (!crmSaveEmailTemplate($template)) {
        crmJson(500, ['ok' => false, 'message' => 'Could not save email template.']);
    }
    crmJson(200, ['ok' => true, 'message' => 'Email message saved.', 'template' => crmLoadEmailTemplate()]);
}

if ($method === 'POST' && $action === 'send-emails') {
    $input = crmReadJsonBody();
    $platform = strtolower(trim((string) ($input['platform'] ?? 'all')));
    $ids = $input['ids'] ?? null;
    $idList = [];
    if (is_array($ids)) {
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $idList[$id] = true;
            }
        }
    }

    $template = crmLoadEmailTemplate();
    if (!empty($input['subject'])) {
        $template['subject'] = trim((string) $input['subject']);
    }
    if (!empty($input['body'])) {
        $template['body'] = trim((string) $input['body']);
    }

    $data = crmLoadLeads();
    $targets = [];
    foreach ($data['leads'] as $lead) {
        if (!is_array($lead)) {
            continue;
        }
        if ($idList !== [] && !isset($idList[(string) ($lead['id'] ?? '')])) {
            continue;
        }
        if ($platform !== '' && $platform !== 'all' && strtolower((string) ($lead['platform'] ?? '')) !== $platform) {
            continue;
        }
        $emails = crmLeadEmails($lead);
        if ($emails === []) {
            continue;
        }
        $targets[] = ['lead' => $lead, 'emails' => $emails];
    }

    if ($targets === []) {
        crmJson(422, ['ok' => false, 'message' => 'No saved leads with email addresses matched your filter.']);
    }

    $sent = 0;
    $failed = 0;
    $errors = [];
    foreach ($targets as $row) {
        $lead = $row['lead'];
        $subject = crmRenderEmailPlaceholders((string) $template['subject'], $lead);
        $body = crmRenderEmailPlaceholders((string) $template['body'], $lead);
        foreach ($row['emails'] as $email) {
            try {
                crmSendSmtpMail($email, $subject, $body);
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                if (count($errors) < 8) {
                    $errors[] = $email . ': ' . $e->getMessage();
                }
            }
        }
    }

    crmJson($sent > 0 ? 200 : 502, [
        'ok' => $sent > 0,
        'message' => $sent > 0
            ? "Sent {$sent} email(s)" . ($failed > 0 ? ", {$failed} failed." : '.')
            : 'Could not send emails.',
        'sent' => $sent,
        'failed' => $failed,
        'errors' => $errors,
        'fromEmail' => crmMailConfig()['fromEmail'],
    ]);
}

if ($method === 'POST' && $action === 'save') {
    $input = crmReadJsonBody();
    $item = $input['item'] ?? $input;
    if (!is_array($item)) {
        crmJson(422, ['ok' => false, 'message' => 'Invalid lead payload.']);
    }
    $id = trim((string) ($item['id'] ?? ''));
    if ($id === '') {
        $id = 'lead_' . md5(json_encode($item) ?: uniqid('crm', true));
        $item['id'] = $id;
    }
    $item['savedAt'] = gmdate('c');
    $item['savedBy'] = (string) ($user['id'] ?? $user['email'] ?? 'admin');

    $data = crmLoadLeads();
    $found = false;
    foreach ($data['leads'] as $i => $lead) {
        if (!is_array($lead)) {
            continue;
        }
        if ((string) ($lead['id'] ?? '') === $id) {
            $data['leads'][$i] = $item;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $data['leads'][] = $item;
    }
    if (!crmSaveLeads($data)) {
        crmJson(500, ['ok' => false, 'message' => 'Could not save lead.']);
    }
    crmJson(200, ['ok' => true, 'message' => $found ? 'Lead updated.' : 'Lead saved.', 'item' => $item]);
}

if ($method === 'POST' && $action === 'save-many') {
    $input = crmReadJsonBody();
    $items = $input['items'] ?? [];
    if (!is_array($items) || $items === []) {
        crmJson(422, ['ok' => false, 'message' => 'No leads to save.']);
    }
    $data = crmLoadLeads();
    $saved = 0;
    $updated = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            $id = 'lead_' . md5(json_encode($item) ?: uniqid('crm', true));
            $item['id'] = $id;
        }
        $item['savedAt'] = gmdate('c');
        $item['savedBy'] = (string) ($user['id'] ?? $user['email'] ?? 'admin');
        $found = false;
        foreach ($data['leads'] as $i => $lead) {
            if (!is_array($lead)) {
                continue;
            }
            if ((string) ($lead['id'] ?? '') === $id) {
                $data['leads'][$i] = $item;
                $found = true;
                $updated++;
                break;
            }
        }
        if (!$found) {
            $data['leads'][] = $item;
            $saved++;
        }
    }
    if (!crmSaveLeads($data)) {
        crmJson(500, ['ok' => false, 'message' => 'Could not save leads.']);
    }
    crmJson(200, [
        'ok' => true,
        'message' => 'Saved ' . ($saved + $updated) . ' lead(s).',
        'saved' => $saved,
        'updated' => $updated,
        'count' => count($data['leads']),
    ]);
}

if ($method === 'POST' && $action === 'delete') {
    $input = crmReadJsonBody();
    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        crmJson(422, ['ok' => false, 'message' => 'Lead id required.']);
    }
    $data = crmLoadLeads();
    $before = count($data['leads']);
    $data['leads'] = array_values(array_filter($data['leads'], static function ($lead) use ($id) {
        return !is_array($lead) || (string) ($lead['id'] ?? '') !== $id;
    }));
    if (count($data['leads']) === $before) {
        crmJson(404, ['ok' => false, 'message' => 'Lead not found.']);
    }
    if (!crmSaveLeads($data)) {
        crmJson(500, ['ok' => false, 'message' => 'Could not delete lead.']);
    }
    crmJson(200, ['ok' => true, 'message' => 'Lead deleted.']);
}

if ($method === 'POST' && $action === 'search') {
    $input = crmReadJsonBody();
    $query = trim((string) ($input['query'] ?? $input['q'] ?? ''));
    $location = trim((string) ($input['location'] ?? ''));
    $platform = strtolower(trim((string) ($input['platform'] ?? 'facebook')));
    if (!in_array($platform, ['facebook', 'instagram', 'linkedin'], true)) {
        $platform = 'facebook';
    }
    $limit = (int) ($input['limit'] ?? 20);
    if ($limit < 1) {
        $limit = 10;
    }
    if ($limit > 50) {
        $limit = 50;
    }
    if ($query === '' || mb_strlen($query) < 2) {
        crmJson(422, ['ok' => false, 'message' => 'Enter a search term (at least 2 characters).']);
    }

    if ($platform === 'instagram') {
        $items = crmRunInstagramSearch($query, $location, $limit);
    } elseif ($platform === 'linkedin') {
        $items = crmRunLinkedinSearch($query, $location, $limit);
    } else {
        $items = crmRunFacebookSearch($query, $location, $limit);
    }

    crmJson(200, [
        'ok' => true,
        'platform' => $platform,
        'query' => $query,
        'location' => $location,
        'count' => count($items),
        'items' => $items,
    ]);
}

crmJson(404, ['ok' => false, 'message' => 'Unknown CRM action.']);
