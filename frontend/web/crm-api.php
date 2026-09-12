<?php

declare(strict_types=1);

/**
 * Admin CRM API — Facebook search via Apify.
 *
 * POST ?action=search JSON { query, location?, limit? }
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth-init.php';
require_once __DIR__ . '/env-load.php';
gwLoadEnv();
gwAuthStartSession();

function crmJson(int $code, array $payload): never
{
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

function crmPickImage(array $item): string
{
    $candidates = [
        $item['profilePicture'] ?? null,
        $item['profilePic'] ?? null,
        $item['profile_photo'] ?? null,
        $item['photo'] ?? null,
        $item['image'] ?? null,
        $item['coverPhoto'] ?? null,
        $item['cover'] ?? null,
        $item['thumbnail'] ?? null,
        $item['picture'] ?? null,
    ];
    foreach ($candidates as $url) {
        if (is_string($url) && preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (is_array($url)) {
            foreach (['uri', 'url', 'src'] as $key) {
                if (!empty($url[$key]) && is_string($url[$key]) && preg_match('#^https?://#i', $url[$key])) {
                    return $url[$key];
                }
            }
        }
    }

    $pageId = trim((string) ($item['pageId'] ?? $item['facebookId'] ?? $item['id'] ?? ''));
    if ($pageId !== '' && preg_match('/^\d+$/', $pageId)) {
        return 'https://graph.facebook.com/' . rawurlencode($pageId) . '/picture?type=large';
    }

    return '';
}

/**
 * @return list<string>
 */
function crmPickThumbnails(array $item): array
{
    $thumbs = [];
    $bags = [
        $item['photos'] ?? null,
        $item['images'] ?? null,
        $item['media'] ?? null,
        $item['gallery'] ?? null,
        $item['thumbnails'] ?? null,
    ];
    foreach ($bags as $bag) {
        if (!is_array($bag)) {
            continue;
        }
        foreach ($bag as $entry) {
            if (is_string($entry) && preg_match('#^https?://#i', $entry)) {
                $thumbs[] = $entry;
            } elseif (is_array($entry)) {
                foreach (['uri', 'url', 'src', 'thumbnail'] as $key) {
                    if (!empty($entry[$key]) && is_string($entry[$key]) && preg_match('#^https?://#i', $entry[$key])) {
                        $thumbs[] = $entry[$key];
                        break;
                    }
                }
            }
            if (count($thumbs) >= 8) {
                break 2;
            }
        }
    }
    $cover = crmPickImage($item);
    if ($cover !== '') {
        array_unshift($thumbs, $cover);
    }
    return array_values(array_unique($thumbs));
}

function crmNormalizeItem(array $item): array
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

    return [
        'id' => $pageId !== '' ? $pageId : md5($pageUrl !== '' ? $pageUrl : $title),
        'name' => $name !== '' ? $name : $title,
        'title' => $title,
        'pageUrl' => $pageUrl,
        'facebookUrl' => trim((string) ($item['facebookUrl'] ?? $pageUrl)),
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
        'profilePicture' => crmPickImage($item),
        'thumbnails' => crmPickThumbnails($item),
    ];
}

function crmRunApifySearch(string $query, string $location, int $limit): array
{
    $token = crmApifyToken();
    if ($token === '') {
        crmJson(503, ['ok' => false, 'message' => 'APIFY_TOKEN is not configured in .env']);
    }

    $payload = [
        'categories' => [$query],
        'resultsLimit' => $limit,
    ];
    if ($location !== '') {
        $payload['locations'] = [$location];
    } else {
        $payload['locations'] = [];
    }

    $url = 'https://api.apify.com/v2/acts/apify~facebook-search-scraper/run-sync-get-dataset-items'
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
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_USERAGENT => 'Getways-App-CRM/1.0',
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

    // Dataset items are a list; error payloads are associative.
    if ($decoded !== [] && array_keys($decoded) !== range(0, count($decoded) - 1)) {
        $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'Apify search failed.');
        crmJson(502, ['ok' => false, 'message' => $message]);
    }

    $items = [];
    foreach ($decoded as $row) {
        if (is_array($row)) {
            $items[] = crmNormalizeItem($row);
        }
    }

    return $items;
}

if ($method === 'POST' && $action === 'search') {
    $input = crmReadJsonBody();
    $query = trim((string) ($input['query'] ?? $input['q'] ?? ''));
    $location = trim((string) ($input['location'] ?? ''));
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

    $items = crmRunApifySearch($query, $location, $limit);
    crmJson(200, [
        'ok' => true,
        'query' => $query,
        'location' => $location,
        'count' => count($items),
        'items' => $items,
    ]);
}

crmJson(404, ['ok' => false, 'message' => 'Unknown CRM action.']);
