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
 * GET  ?action=test-emails
 * POST ?action=test-emails JSON { emails: string[]|string }  (import / replace test list)
 * POST ?action=send-test-emails JSON { emails?: string[] }   (send to imported or provided)
 * GET  ?action=email-log
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

function crmTestEmailsPath(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'crm-test-emails.json';
}

function crmEmailLogPath(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'runtime';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . DIRECTORY_SEPARATOR . 'crm-email-log.json';
}

/**
 * @return list<string>
 */
function crmParseEmailList(mixed $raw): array
{
    $chunks = [];
    if (is_array($raw)) {
        foreach ($raw as $item) {
            $chunks[] = (string) $item;
        }
    } else {
        $chunks[] = (string) $raw;
    }
    $text = implode("\n", $chunks);
    $text = str_replace([';', "\r", "\t", '|'], [',', "\n", ' ', ','], $text);
    $parts = preg_split('/[\s,;]+/', $text) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = strtolower(trim((string) $part));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = true;
        }
    }
    return array_keys($emails);
}

/**
 * @return list<string>
 */
function crmLoadTestEmails(): array
{
    $path = crmTestEmailsPath();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    return crmParseEmailList($data['emails'] ?? $data);
}

/**
 * @param list<string> $emails
 */
function crmSaveTestEmails(array $emails): bool
{
    $clean = crmParseEmailList($emails);
    $payload = [
        'emails' => $clean,
        'updatedAt' => gmdate('c'),
        'count' => count($clean),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }
    return @file_put_contents(crmTestEmailsPath(), $json, LOCK_EX) !== false;
}

/**
 * @return list<array<string, mixed>>
 */
function crmLoadEmailLog(int $limit = 80): array
{
    $path = crmEmailLogPath();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
        return [];
    }
    $entries = array_values($data['entries']);
    usort($entries, static function ($a, $b) {
        return strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
    });
    return array_slice($entries, 0, max(1, $limit));
}

/**
 * @param array<string, mixed> $entry
 */
function crmAppendEmailLog(array $entry): void
{
    $path = crmEmailLogPath();
    $data = ['entries' => []];
    if (is_file($path)) {
        $raw = file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
                $data['entries'] = $decoded['entries'];
            }
        }
    }
    $data['entries'][] = $entry;
    if (count($data['entries']) > 300) {
        $data['entries'] = array_slice($data['entries'], -300);
    }
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (is_string($json)) {
        @file_put_contents($path, $json, LOCK_EX);
    }
}

function crmDefaultEmailTemplate(): array
{
    return [
        'subject' => 'Career Opportunity | Full-Stack Developer | System Delivery',
        'headerTitle' => 'WELCOME TO CAREER OPPORTUNITIES:',
        'headerSubtitle' => 'Everything You Need to Get Started.',
        'bodyTitle' => 'JOB APPLICATION',
        'greeting' => 'Hello {{name}},',
        'body' => "I hope this message finds you well. My name is **Steven Makarious**, a full-stack developer specializing in designing and delivering reliable digital systems for organizations that need clarity, speed, and long-term stability.\n\n"
            . "I build and support production systems across government, education, and private enterprise. Below is a brief summary of selected work:\n\n"
            . "• **TRA / revenue data systems** — platforms for collecting, processing, and pulling operational data with accuracy and secure workflows\n"
            . "• **School management systems** — student records, academic operations, and administration tools for education institutions\n"
            . "• **CRM platforms** — lead tracking, client communication, and organized follow-up workflows\n"
            . "• **Product management systems** — catalogs, operations, and tools that help teams manage products and business processes end to end\n\n"
            . "My delivery style covers the full cycle: requirements, architecture, secure backend services, modern interfaces, integrations, and continuous improvement. I focus on systems that are practical to run, easy for teams to adopt, and strong enough for real operational load.\n\n"
            . "If your organization is hiring — or exploring a developer who can own system delivery end to end — I would welcome a short conversation.",
        'signOff' => 'Best regards,',
        'signName' => 'Steven Makarious',
        'signRole' => 'Software Developer',
        'footerLine' => 'Want to see more of my work? Click the button below to explore additional projects and case studies.',
        'ctaText' => 'View more projects',
        'ctaUrl' => 'https://makarious.legitconsult.co.tz/',
        'contactPhones' => '+255 715 296 092 | +255 622 045 972',
        'contactEmail' => 'stevenabalwambo@gmail.com',
        'contactWebsite' => 'https://makarious.legitconsult.co.tz/',
        'contactOrg' => 'Digital Matrix Technology · Tanzania',
        'fromEmail' => 'stevenabalwambo@gmail.com',
        'fromName' => 'Steven Makarious',
        'updatedAt' => null,
    ];
}

function crmLoadEmailTemplate(): array
{
    $defaults = crmDefaultEmailTemplate();
    $path = crmEmailTemplatePath();
    $data = [];
    if (is_file($path)) {
        $raw = file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }
    $out = $defaults;
    foreach ($defaults as $key => $value) {
        if ($key === 'updatedAt') {
            continue;
        }
        if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
            $out[$key] = trim($data[$key]);
        }
    }
    $out['fromEmail'] = 'stevenabalwambo@gmail.com';
    // Prefer portfolio CTA over legacy mailto when saved template still has old link
    $cta = strtolower(trim((string) ($out['ctaUrl'] ?? '')));
    if ($cta === '' || str_starts_with($cta, 'mailto:')) {
        $out['ctaUrl'] = (string) $defaults['ctaUrl'];
    }
    // Prefer pipe separator in subject instead of em/en dashes
    $out['subject'] = str_replace(['—', '–', '---', ' -- '], [' | ', ' | ', ' | ', ' | '], (string) $out['subject']);
    // Migrate legacy name in saved templates
    $out['body'] = str_replace('Steven Abalwambo', 'Steven Makarious', (string) $out['body']);
    $out['signName'] = str_replace('Steven Abalwambo', 'Steven Makarious', (string) $out['signName']);
    if (trim((string) $out['signName']) === '' || strcasecmp(trim((string) $out['signName']), 'Steven Abalwambo') === 0) {
        $out['signName'] = 'Steven Makarious';
    }
    // Replace legacy generic application body with expert developer profile
    $legacyMarkers = [
        'I am hardworking, reliable, and eager to contribute my skills',
        'express my strong interest in available job opportunities within your organization',
    ];
    foreach ($legacyMarkers as $marker) {
        if (stripos((string) $out['body'], $marker) !== false) {
            $out['subject'] = (string) $defaults['subject'];
            $out['body'] = (string) $defaults['body'];
            $out['signRole'] = (string) $defaults['signRole'];
            $out['signName'] = (string) $defaults['signName'];
            break;
        }
    }
    // Prefer portfolio CTA copy that invites viewing more projects
    $legacyCta = [
        'Get in touch',
        'A practical partnership to grow your team with the right talent.',
    ];
    if (in_array(trim((string) $out['ctaText']), $legacyCta, true) || trim((string) $out['ctaText']) === '') {
        $out['ctaText'] = (string) $defaults['ctaText'];
    }
    if (in_array(trim((string) $out['footerLine']), $legacyCta, true) || trim((string) $out['footerLine']) === '') {
        $out['footerLine'] = (string) $defaults['footerLine'];
    }
    $out['updatedAt'] = $data['updatedAt'] ?? null;
    return $out;
}

function crmSaveEmailTemplate(array $template): bool
{
    $defaults = crmDefaultEmailTemplate();
    $path = crmEmailTemplatePath();
    $payload = [
        'subject' => trim((string) ($template['subject'] ?? $defaults['subject'])),
        'headerTitle' => trim((string) ($template['headerTitle'] ?? $defaults['headerTitle'])),
        'headerSubtitle' => trim((string) ($template['headerSubtitle'] ?? $defaults['headerSubtitle'])),
        'bodyTitle' => trim((string) ($template['bodyTitle'] ?? $defaults['bodyTitle'])),
        'greeting' => trim((string) ($template['greeting'] ?? $defaults['greeting'])),
        'body' => trim((string) ($template['body'] ?? $defaults['body'])),
        'signOff' => trim((string) ($template['signOff'] ?? $defaults['signOff'])),
        'signName' => trim((string) ($template['signName'] ?? $defaults['signName'])),
        'signRole' => trim((string) ($template['signRole'] ?? $defaults['signRole'])),
        'footerLine' => trim((string) ($template['footerLine'] ?? $defaults['footerLine'])),
        'ctaText' => trim((string) ($template['ctaText'] ?? $defaults['ctaText'])),
        'ctaUrl' => trim((string) ($template['ctaUrl'] ?? $defaults['ctaUrl'])),
        'contactPhones' => trim((string) ($template['contactPhones'] ?? $defaults['contactPhones'])),
        'contactEmail' => trim((string) ($template['contactEmail'] ?? $defaults['contactEmail'])),
        'contactWebsite' => trim((string) ($template['contactWebsite'] ?? $defaults['contactWebsite'])),
        'contactOrg' => trim((string) ($template['contactOrg'] ?? $defaults['contactOrg'])),
        'fromEmail' => 'stevenabalwambo@gmail.com',
        'fromName' => trim((string) ($template['fromName'] ?? $defaults['fromName'])) ?: 'Steven Makarious',
        'updatedAt' => gmdate('c'),
    ];
    if ($payload['ctaUrl'] === '' || str_starts_with(strtolower($payload['ctaUrl']), 'mailto:')) {
        $payload['ctaUrl'] = (string) $defaults['ctaUrl'];
    }
    $payload['subject'] = str_replace(['—', '–', '---'], [' | ', ' | ', ' | '], $payload['subject']);
    $payload['body'] = str_replace('Steven Abalwambo', 'Steven Makarious', $payload['body']);
    $payload['signName'] = str_replace('Steven Abalwambo', 'Steven Makarious', $payload['signName']);
    if ($payload['signName'] === '') {
        $payload['signName'] = 'Steven Makarious';
    }
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
    $platform = trim((string) ($lead['platform'] ?? ''));
    $platformLabel = $platform !== '' ? ucfirst($platform) : 'CRM';
    $address = trim((string) ($lead['address'] ?? ''));
    if ($address === '') {
        $address = 'Tanzania';
    }
    $map = [
        '{{name}}' => $name,
        '{{title}}' => trim((string) ($lead['title'] ?? $name)),
        '{{username}}' => trim((string) ($lead['username'] ?? '')),
        '{{platform}}' => $platformLabel,
        '{{email}}' => trim((string) ($lead['email'] ?? '')),
        '{{phone}}' => trim((string) ($lead['phone'] ?? '')),
        '{{website}}' => trim((string) ($lead['website'] ?? '')),
        '{{address}}' => $address,
        '{{location}}' => $address,
        '{{company}}' => $name,
        '{{focus}}' => $platformLabel,
    ];
    return strtr($text, $map);
}

function crmEmailEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function crmEmailInlineFormat(string $text): string
{
    $escaped = crmEmailEscape($text);
    return (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
}

/**
 * Absolute public base URL for email assets (images must load for recipients).
 */
function crmEmailPublicBaseUrl(): string
{
    $candidates = [
        trim((string) (getenv('CRM_PUBLIC_BASE_URL') ?: '')),
        trim((string) (getenv('BASE_URL') ?: '')),
        trim((string) (getenv('APP_URL') ?: '')),
    ];
    foreach ($candidates as $base) {
        if ($base === '') {
            continue;
        }
        $base = rtrim($base, '/');
        if (preg_match('#^https?://#i', $base)) {
            return $base;
        }
    }
    return 'https://makarious.legitconsult.co.tz';
}

/**
 * Absolute image URL for CRM email (local path or full https URL).
 */
function crmEmailImageUrl(string $pathOrUrl): string
{
    $value = trim($pathOrUrl);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    return crmEmailPublicBaseUrl() . '/' . ltrim(str_replace('\\', '/', $value), '/');
}

/**
 * Compact Events + News block for the middle of the CRM email.
 */
function crmBuildEmailEventsSection(string $font): string
{
    $base = crmEmailPublicBaseUrl();
    $portrait = crmEmailImageUrl('images/crm/steven-makarious.jpg');
    // Public stock images (Unsplash) — always reachable in recipient inboxes
    $schoolImg = 'https://images.unsplash.com/photo-1588072432836-e10032774350?auto=format&fit=crop&w=240&h=160&q=80';
    $officeImg = 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=240&h=160&q=80';

    $events = [
        [
            'img' => $portrait,
            'title' => 'THE DIGITAL MATRIX TECHNOLOGY CLIENT SYSTEMS WORKSHOP 2026',
            'when' => 'Sep 22, 2026 09:00 - Sep 23, 2026 16:00',
            'where' => 'Dar es Salaam, Tanzania',
            'desc' => 'Briefings on school portals, TRA data systems, CRM and office platforms.',
        ],
        [
            'img' => $schoolImg,
            'title' => 'SCHOOL SYSTEMS DELIVERY BRIEFING',
            'when' => 'Oct 05, 2026 10:00 - 14:00',
            'where' => 'Dar es Salaam, Tanzania',
            'desc' => 'Student records, academic operations and administration tools.',
        ],
        [
            'img' => $officeImg,
            'title' => 'CRM & PRODUCT MANAGEMENT SESSION',
            'when' => 'Oct 18, 2026 09:30 - 13:00',
            'where' => 'Julius Nyerere International Convention Centre',
            'desc' => 'Lead tracking, product catalogs and end-to-end business workflows.',
        ],
    ];

    $news = [
        [
            'day' => '08',
            'meta' => 'Tue Sep',
            'title' => 'DIGITAL MATRIX TECHNOLOGY EXPANDS FULL-STACK DELIVERY',
        ],
        [
            'day' => '12',
            'meta' => 'Fri Sep',
            'title' => 'NEW PROJECTS: TRA DATA, SCHOOL SYSTEMS, CRM & PRODUCT TOOLS',
        ],
        [
            'day' => '15',
            'meta' => 'Mon Sep',
            'title' => 'SUPPORT VIA WHATSAPP · +255 715 296 092',
        ],
        [
            'day' => '20',
            'meta' => 'Sat Sep',
            'title' => 'PORTFOLIO UPDATES AT MAKARIOUS.LEGITCONSULT.CO.TZ',
        ],
    ];

    $imgStyle = 'display:block;width:72px;height:56px;object-fit:cover;border:0;';
    $eventRows = '';
    foreach ($events as $ev) {
        $eventRows .= '<tr><td style="padding:0 0 14px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td width="78" valign="top" style="padding-right:10px;">'
            . '<img src="' . crmEmailEscape((string) $ev['img']) . '" width="72" height="56" alt="" style="' . $imgStyle . '" />'
            . '</td>'
            . '<td valign="top" style="' . $font . '">'
            . '<div style="font-size:11px;font-weight:800;letter-spacing:0.02em;color:#1e293b;text-transform:uppercase;line-height:1.35;margin:0 0 4px;">'
            . crmEmailEscape((string) $ev['title']) . '</div>'
            . '<div style="font-size:10px;color:#64748b;line-height:1.45;margin:0 0 2px;">'
            . crmEmailEscape((string) $ev['when']) . '</div>'
            . '<div style="font-size:10px;color:#64748b;line-height:1.45;margin:0 0 4px;">'
            . crmEmailEscape((string) $ev['where']) . '</div>'
            . '<div style="font-size:11px;color:#475569;line-height:1.45;">'
            . crmEmailEscape((string) $ev['desc']) . '</div>'
            . '</td></tr></table></td></tr>';
    }

    $newsRows = '';
    foreach ($news as $i => $item) {
        $border = $i === 0 ? '' : 'border-top:1px solid #e2e8f0;';
        $newsRows .= '<tr><td style="padding:10px 0;' . $border . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td width="42" valign="top" style="padding-right:8px;' . $font . 'text-align:left;">'
            . '<div style="font-size:18px;font-weight:800;color:#0f172a;line-height:1;">' . crmEmailEscape((string) $item['day']) . '</div>'
            . '<div style="font-size:9px;color:#64748b;line-height:1.2;margin-top:2px;">' . crmEmailEscape((string) $item['meta']) . '</div>'
            . '</td>'
            . '<td valign="middle" style="' . $font . 'font-size:11px;font-weight:700;color:#1e293b;text-transform:uppercase;line-height:1.35;">'
            . crmEmailEscape((string) $item['title'])
            . '</td></tr></table></td></tr>';
    }

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 20px;border-top:1px solid #e2e8f0;">'
        . '<tr><td style="padding:18px 0 8px;">'
        . '<div style="' . $font . 'font-size:11px;font-weight:800;letter-spacing:0.1em;text-transform:uppercase;color:#4F378B;margin:0 0 12px;">Highlights</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        // Events column
        . '<td width="54%" valign="top" style="padding-right:12px;">'
        . '<div style="' . $font . 'font-size:13px;font-weight:800;letter-spacing:0.06em;text-transform:uppercase;color:#1e293b;margin:0 0 12px;">Events</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $eventRows . '</table>'
        . '</td>'
        // News column
        . '<td width="46%" valign="top" style="padding-left:12px;border-left:1px solid #e2e8f0;">'
        . '<div style="' . $font . 'font-size:13px;font-weight:800;letter-spacing:0.06em;text-transform:uppercase;color:#1e293b;margin:0 0 8px;">News</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $newsRows . '</table>'
        . '</td>'
        . '</tr></table>'
        . '<div style="margin-top:6px;' . $font . 'font-size:10px;color:#94a3b8;">More at <a href="' . crmEmailEscape($base) . '" style="color:#4F378B;text-decoration:none;">' . crmEmailEscape(preg_replace('#^https?://#i', '', $base) ?: $base) . '</a></div>'
        . '</td></tr></table>';
}

/**
 * Convert template body text into HTML paragraphs + bullet lists.
 */
function crmEmailFormatBodyHtml(string $bodyRaw): string
{
    $paragraphs = preg_split("/\n{2,}/", trim($bodyRaw)) ?: [];
    $bodyHtml = '';
    $pStyle = 'margin:0 0 16px;font-size:15px;line-height:1.7;color:#334155;font-family:Georgia,\'Times New Roman\',serif;';
    $ulStyle = 'margin:4px 0 18px;padding:0 0 0 4px;list-style:none;';
    $liStyle = 'margin:0 0 10px;padding:0 0 0 18px;position:relative;font-size:15px;line-height:1.65;color:#334155;font-family:Georgia,\'Times New Roman\',serif;';
    $dotStyle = 'position:absolute;left:0;top:0.55em;width:7px;height:7px;border-radius:50%;background:#4F378B;';

    foreach ($paragraphs as $para) {
        $para = trim((string) $para);
        if ($para === '') {
            continue;
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $para)), static function ($line) {
            return $line !== '';
        }));
        if ($lines === []) {
            continue;
        }

        $bulletCount = 0;
        foreach ($lines as $line) {
            if (preg_match('/^(?:•|\-|\*)\s+/u', $line)) {
                $bulletCount++;
            }
        }

        if ($bulletCount > 0 && $bulletCount === count($lines)) {
            $bodyHtml .= '<ul style="' . $ulStyle . '">';
            foreach ($lines as $line) {
                $item = (string) preg_replace('/^(?:•|\-|\*)\s+/u', '', $line);
                $bodyHtml .= '<li style="' . $liStyle . '"><span style="' . $dotStyle . '"></span>'
                    . crmEmailInlineFormat($item)
                    . '</li>';
            }
            $bodyHtml .= '</ul>';
            continue;
        }

        $bodyHtml .= '<p style="' . $pStyle . '">'
            . crmEmailInlineFormat(implode('<br>', $lines))
            . '</p>';
    }

    return $bodyHtml;
}

/**
 * Build HTML email card matching the branded template design.
 *
 * @param array<string, mixed> $template
 * @param array<string, mixed> $lead
 */
function crmBuildEmailHtml(array $template, array $lead): string
{
    $defaults = crmDefaultEmailTemplate();
    $headerTitle = crmEmailEscape(crmRenderEmailPlaceholders((string) ($template['headerTitle'] ?? ''), $lead));
    $headerSubtitle = crmEmailEscape(crmRenderEmailPlaceholders((string) ($template['headerSubtitle'] ?? ''), $lead));
    $bodyTitle = crmEmailEscape(crmRenderEmailPlaceholders((string) ($template['bodyTitle'] ?? ''), $lead));
    $greeting = crmEmailInlineFormat(crmRenderEmailPlaceholders((string) ($template['greeting'] ?? ''), $lead));
    $bodyRaw = crmRenderEmailPlaceholders((string) ($template['body'] ?? ''), $lead);
    $signOff = crmEmailEscape(crmRenderEmailPlaceholders((string) ($template['signOff'] ?? 'Best regards,'), $lead));
    $signName = crmEmailEscape(crmRenderEmailPlaceholders((string) ($template['signName'] ?? ''), $lead));
    $signRole = crmEmailEscape(crmRenderEmailPlaceholders((string) ($template['signRole'] ?? ''), $lead));
    $footerLine = crmEmailEscape(crmRenderEmailPlaceholders((string) ($template['footerLine'] ?? ''), $lead));
    $ctaText = crmEmailEscape(crmRenderEmailPlaceholders((string) ($template['ctaText'] ?? 'Get in touch'), $lead));
    $ctaUrl = trim(crmRenderEmailPlaceholders((string) ($template['ctaUrl'] ?? $defaults['ctaUrl']), $lead));
    if ($ctaUrl === '' || str_starts_with(strtolower($ctaUrl), 'mailto:')) {
        $ctaUrl = (string) $defaults['ctaUrl'];
    }
    $ctaUrlEsc = crmEmailEscape($ctaUrl);

    $contactPhones = crmEmailEscape(trim((string) ($template['contactPhones'] ?? $defaults['contactPhones'])));
    $contactEmail = crmEmailEscape(trim((string) ($template['contactEmail'] ?? $defaults['contactEmail'])));
    $contactWebsite = trim((string) ($template['contactWebsite'] ?? $defaults['contactWebsite']));
    $contactWebsiteEsc = crmEmailEscape($contactWebsite);
    $contactOrg = crmEmailEscape(trim((string) ($template['contactOrg'] ?? $defaults['contactOrg'])));

    $focus = crmEmailEscape(crmRenderEmailPlaceholders('{{platform}}', $lead));
    $location = crmEmailEscape(crmRenderEmailPlaceholders('{{location}}', $lead));

    $bodyHtml = crmEmailFormatBodyHtml($bodyRaw);

    $font = "font-family:Arial,Helvetica,sans-serif;";

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $bodyTitle . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef2f7;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:28px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#ffffff;border-radius:0;overflow:hidden;box-shadow:0 12px 32px rgba(15,23,42,0.10);border:1px solid #e2e8f0;">'

        // Header — solid color edge to edge (no fade)
        . '<tr><td style="background:#4F378B;padding:0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        . '<tr><td style="padding:30px 28px 26px;text-align:center;background:#4F378B;">'
        . '<div style="' . $font . 'font-size:11px;font-weight:700;letter-spacing:0.14em;color:rgba(255,255,255,0.85);text-transform:uppercase;margin:0 0 10px;">Digital Matrix Technology</div>'
        . '<div style="' . $font . 'font-size:20px;font-weight:800;letter-spacing:0.03em;color:#ffffff;text-transform:uppercase;line-height:1.3;">'
        . $headerTitle . '</div>'
        . '<div style="margin-top:10px;' . $font . 'font-size:14px;font-weight:500;color:rgba(255,255,255,0.92);">'
        . $headerSubtitle . '</div>'
        . '</td></tr></table></td></tr>'

        // Body
        . '<tr><td style="padding:30px 30px 10px;background:#ffffff;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">'
        . '<tr><td style="width:4px;background:#4F378B;"></td>'
        . '<td style="padding-left:12px;' . $font . 'font-size:17px;font-weight:800;color:#0f172a;text-transform:uppercase;letter-spacing:0.04em;">'
        . $bodyTitle . '</td></tr></table>'
        . '<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#0f172a;' . $font . '">'
        . $greeting . '</p>'
        . $bodyHtml

        // Events + News (middle of email)
        . crmBuildEmailEventsSection($font)

        // Signature
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0 8px;">'
        . '<tr><td style="border-top:1px solid #e2e8f0;padding-top:18px;' . $font . 'font-size:15px;line-height:1.55;color:#334155;">'
        . $signOff . '<br>'
        . '<strong style="color:#0f172a;font-size:16px;">' . $signName . '</strong><br>'
        . '<span style="color:#64748b;font-size:13px;">' . $signRole . '</span>'
        . '</td></tr></table>'

        // Contact card
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0 8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0;">'
        . '<tr><td style="padding:14px 16px;' . $font . '">'
        . '<div style="font-size:11px;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;color:#4F378B;margin:0 0 8px;">Contact information</div>'
        . '<div style="font-size:14px;line-height:1.7;color:#0f172a;">'
        . '<strong>Phone:</strong> ' . $contactPhones . '<br>'
        . '<strong>Email:</strong> <a href="mailto:' . $contactEmail . '" style="color:#4F378B;text-decoration:none;">' . $contactEmail . '</a><br>'
        . '<strong>Website:</strong> <a href="' . $contactWebsiteEsc . '" style="color:#4F378B;text-decoration:none;">' . $contactWebsiteEsc . '</a><br>'
        . '<span style="color:#64748b;font-size:13px;">' . $contactOrg . '</span>'
        . '</div></td></tr></table>'
        . '</td></tr>'

        // Focus | Location
        . '<tr><td style="padding:8px 30px 16px;background:#ffffff;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;border-radius:0;border:1px solid #e2e8f0;">'
        . '<tr><td style="padding:12px 16px;' . $font . 'font-size:13px;color:#334155;">'
        . '<strong style="color:#0f172a;">Focus:</strong> ' . $focus
        . ' <span style="color:#94a3b8;padding:0 8px;">|</span> '
        . '<strong style="color:#0f172a;">Location:</strong> ' . $location
        . '</td></tr></table></td></tr>'

        // CTA
        . '<tr><td style="padding:6px 30px 30px;background:#ffffff;text-align:center;">'
        . '<p style="margin:0 0 18px;' . $font . 'font-size:13px;line-height:1.55;color:#64748b;">'
        . $footerLine . '</p>'
        . '<a href="' . $ctaUrlEsc . '" style="display:inline-block;background:#4F378B;color:#ffffff;text-decoration:none;' . $font . 'font-size:14px;font-weight:700;padding:13px 32px;border-radius:0;box-shadow:0 8px 18px rgba(79,55,139,0.28);">'
        . $ctaText . '</a>'
        . '<div style="margin-top:14px;' . $font . 'font-size:11px;color:#94a3b8;">'
        . crmEmailEscape(str_replace(['https://', 'http://'], '', $ctaUrl))
        . '</div>'
        . '</td></tr>'

        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

function crmMailConfig(): array
{
    return [
        'host' => trim((string) (getenv('CRM_SMTP_HOST') ?: 'smtp.gmail.com')),
        'port' => (int) (getenv('CRM_SMTP_PORT') ?: 587),
        'user' => trim((string) (getenv('CRM_SMTP_USER') ?: getenv('CRM_MAIL_FROM') ?: 'stevenabalwambo@gmail.com')),
        'pass' => trim((string) (getenv('CRM_SMTP_PASS') ?: getenv('CRM_SMTP_PASSWORD') ?: '')),
        'fromEmail' => trim((string) (getenv('CRM_MAIL_FROM') ?: 'stevenabalwambo@gmail.com')),
        'fromName' => trim((string) (getenv('CRM_MAIL_FROM_NAME') ?: 'Steven Makarious')),
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
 * Send one email via SMTP (Gmail-compatible STARTTLS) as multipart HTML + plain text.
 */
function crmSendSmtpMail(string $to, string $subject, string $bodyText, string $bodyHtml = ''): void
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

        $boundary = 'gw_crm_' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $from),
            'To: <' . $to . '>',
            'Reply-To: <' . $from . '>',
            'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Getways-CRM',
        ];

        if ($bodyHtml === '') {
            $bodyHtml = '<pre style="font-family:Arial,Helvetica,sans-serif;white-space:pre-wrap;">'
                . crmEmailEscape($bodyText) . '</pre>';
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($bodyText))
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($bodyHtml))
            . '--' . $boundary . "--\r\n.";

        fwrite($socket, $message . "\r\n");
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
        crmJson(500, ['ok' => false, 'message' => 'Could not start search request.']);
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
        crmJson(502, ['ok' => false, 'message' => $err !== '' ? $err : 'Empty response from search service.']);
    }

    $decoded = json_decode($body, true);
    if ($http >= 400) {
        $message = 'Search failed.';
        if (is_array($decoded)) {
            $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? $message);
        }
        $message = preg_replace('/\bApify\b/i', 'search service', $message) ?: $message;
        crmJson($http >= 500 ? 502 : $http, ['ok' => false, 'message' => $message, 'httpStatus' => $http]);
    }

    if (!is_array($decoded)) {
        crmJson(502, ['ok' => false, 'message' => 'Search service returned invalid data.']);
    }

    if ($decoded !== [] && array_keys($decoded) !== range(0, count($decoded) - 1)) {
        $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'Search failed.');
        $message = preg_replace('/\bApify\b/i', 'search service', $message) ?: $message;
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
    if ($subject === '' || mb_strlen($subject) < 3) {
        crmJson(422, ['ok' => false, 'message' => 'Subject is required.']);
    }
    if ($body === '' || mb_strlen($body) < 10) {
        crmJson(422, ['ok' => false, 'message' => 'Message body is required.']);
    }
    $template = [
        'subject' => $subject,
        'headerTitle' => trim((string) ($input['headerTitle'] ?? '')),
        'headerSubtitle' => trim((string) ($input['headerSubtitle'] ?? '')),
        'bodyTitle' => trim((string) ($input['bodyTitle'] ?? '')),
        'greeting' => trim((string) ($input['greeting'] ?? '')),
        'body' => $body,
        'signOff' => trim((string) ($input['signOff'] ?? '')),
        'signName' => trim((string) ($input['signName'] ?? '')),
        'signRole' => trim((string) ($input['signRole'] ?? '')),
        'footerLine' => trim((string) ($input['footerLine'] ?? '')),
        'ctaText' => trim((string) ($input['ctaText'] ?? '')),
        'ctaUrl' => trim((string) ($input['ctaUrl'] ?? '')),
        'fromName' => trim((string) ($input['fromName'] ?? 'Steven Makarious')),
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
    $seenEmails = [];
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
        $unique = [];
        foreach ($emails as $email) {
            if (isset($seenEmails[$email])) {
                continue;
            }
            $seenEmails[$email] = true;
            $unique[] = $email;
        }
        if ($unique === []) {
            continue;
        }
        $targets[] = ['lead' => $lead, 'emails' => $unique, 'source' => 'lead'];
    }

    // Extra emails from Edit email popup — merged with online lead emails.
    $extraEmails = crmLoadTestEmails();
    $extraOnly = [];
    foreach ($extraEmails as $email) {
        if (isset($seenEmails[$email])) {
            continue;
        }
        $seenEmails[$email] = true;
        $extraOnly[] = $email;
    }
    if ($extraOnly !== []) {
        $targets[] = [
            'lead' => [
                'name' => 'Extra contact',
                'title' => 'Extra contact',
                'username' => '',
                'platform' => 'extra',
                'email' => $extraOnly[0],
                'address' => 'Tanzania',
            ],
            'emails' => $extraOnly,
            'source' => 'extra',
        ];
    }

    if ($targets === []) {
        crmJson(422, ['ok' => false, 'message' => 'No emails to send. Save leads with emails or add emails via Edit email.']);
    }

    $sent = 0;
    $failed = 0;
    $errors = [];
    $results = [];
    foreach ($targets as $row) {
        $lead = $row['lead'];
        $source = (string) ($row['source'] ?? 'lead');
        $leadName = trim((string) ($lead['name'] ?? $lead['title'] ?? $lead['username'] ?? 'Lead'));
        $subject = crmRenderEmailPlaceholders((string) $template['subject'], $lead);
        $bodyText = crmRenderEmailPlaceholders(
            trim((string) ($template['greeting'] ?? '')) . "\n\n" . (string) $template['body'] . "\n\n"
            . trim((string) ($template['signOff'] ?? 'Best regards,')) . "\n"
            . trim((string) ($template['signName'] ?? 'Steven Makarious')) . "\n"
            . trim((string) ($template['signRole'] ?? '')),
            $lead
        );
        $bodyHtml = crmBuildEmailHtml($template, $lead);
        foreach ($row['emails'] as $email) {
            $entry = [
                'id' => 'elog_' . bin2hex(random_bytes(6)),
                'at' => gmdate('c'),
                'to' => $email,
                'subject' => $subject,
                'source' => $source,
                'leadName' => $source === 'extra' ? 'Extra email' : $leadName,
                'platform' => (string) ($lead['platform'] ?? ''),
                'status' => 'sent',
                'error' => '',
            ];
            try {
                crmSendSmtpMail($email, $subject, $bodyText, $bodyHtml);
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                $entry['status'] = 'failed';
                $entry['error'] = $e->getMessage();
                if (count($errors) < 8) {
                    $errors[] = $email . ': ' . $e->getMessage();
                }
            }
            crmAppendEmailLog($entry);
            $results[] = [
                'to' => $email,
                'status' => $entry['status'],
                'error' => $entry['error'],
                'leadName' => $entry['leadName'],
            ];
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
        'results' => $results,
        'fromEmail' => crmMailConfig()['fromEmail'],
        'extraCount' => count($extraOnly),
    ]);
}

if ($action === 'test-emails' && $method === 'GET') {
    $emails = crmLoadTestEmails();
    crmJson(200, [
        'ok' => true,
        'emails' => $emails,
        'count' => count($emails),
        'mailReady' => crmMailConfig()['pass'] !== '',
        'fromEmail' => crmMailConfig()['fromEmail'],
    ]);
}

if ($method === 'POST' && $action === 'test-emails') {
    $input = crmReadJsonBody();
    $clear = !empty($input['clear']);
    $emails = crmParseEmailList($input['emails'] ?? $input['text'] ?? '');
    if ($emails === [] && !$clear) {
        crmJson(422, ['ok' => false, 'message' => 'Paste at least one valid email address.']);
    }
    if (count($emails) > 100) {
        crmJson(422, ['ok' => false, 'message' => 'Import up to 100 emails at a time.']);
    }
    if (!crmSaveTestEmails($emails)) {
        crmJson(500, ['ok' => false, 'message' => 'Could not save emails.']);
    }
    crmJson(200, [
        'ok' => true,
        'message' => $emails === []
            ? 'Extra email list cleared.'
            : 'Saved ' . count($emails) . ' extra email(s).',
        'emails' => $emails,
        'count' => count($emails),
    ]);
}

if ($method === 'POST' && $action === 'send-test-emails') {
    $input = crmReadJsonBody();
    $emails = crmParseEmailList($input['emails'] ?? []);
    if ($emails === []) {
        $emails = crmLoadTestEmails();
    }
    if ($emails === []) {
        crmJson(422, ['ok' => false, 'message' => 'Import test emails first, then send.']);
    }
    if (count($emails) > 30) {
        crmJson(422, ['ok' => false, 'message' => 'Send test to at most 30 emails at once.']);
    }

    $template = crmLoadEmailTemplate();
    $fakeLead = [
        'name' => 'Test Company',
        'title' => 'Test Company',
        'username' => 'test',
        'platform' => 'crm-test',
        'email' => $emails[0],
        'address' => 'Dar es Salaam, Tanzania',
    ];
    $subject = crmRenderEmailPlaceholders((string) $template['subject'], $fakeLead);
    $bodyText = crmRenderEmailPlaceholders(
        trim((string) ($template['greeting'] ?? '')) . "\n\n" . (string) $template['body'] . "\n\n"
        . trim((string) ($template['signOff'] ?? 'Best regards,')) . "\n"
        . trim((string) ($template['signName'] ?? 'Steven Makarious')) . "\n"
        . trim((string) ($template['signRole'] ?? '')),
        $fakeLead
    );
    $bodyHtml = crmBuildEmailHtml($template, $fakeLead);

    $sent = 0;
    $failed = 0;
    $errors = [];
    $results = [];
    foreach ($emails as $email) {
        $entry = [
            'id' => 'elog_' . bin2hex(random_bytes(6)),
            'at' => gmdate('c'),
            'to' => $email,
            'subject' => $subject,
            'source' => 'test',
            'leadName' => 'Test import',
            'platform' => 'test',
            'status' => 'sent',
            'error' => '',
        ];
        try {
            crmSendSmtpMail($email, $subject, $bodyText, $bodyHtml);
            $sent++;
        } catch (Throwable $e) {
            $failed++;
            $entry['status'] = 'failed';
            $entry['error'] = $e->getMessage();
            if (count($errors) < 8) {
                $errors[] = $email . ': ' . $e->getMessage();
            }
        }
        crmAppendEmailLog($entry);
        $results[] = [
            'to' => $email,
            'status' => $entry['status'],
            'error' => $entry['error'],
            'leadName' => 'Test import',
        ];
    }

    crmJson($sent > 0 ? 200 : 502, [
        'ok' => $sent > 0,
        'message' => $sent > 0
            ? "Test sent {$sent} email(s)" . ($failed > 0 ? ", {$failed} failed." : '. Check inboxes (and spam).')
            : 'Could not send test emails.',
        'sent' => $sent,
        'failed' => $failed,
        'errors' => $errors,
        'results' => $results,
        'fromEmail' => crmMailConfig()['fromEmail'],
    ]);
}

if ($action === 'email-log' && $method === 'GET') {
    $entries = crmLoadEmailLog(100);
    crmJson(200, [
        'ok' => true,
        'count' => count($entries),
        'items' => $entries,
        'mailReady' => crmMailConfig()['pass'] !== '',
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
