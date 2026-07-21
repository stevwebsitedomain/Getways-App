<?php

declare(strict_types=1);

namespace frontend\controllers;

use Yii;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\Response;

class ApiController extends Controller
{
    public $enableCsrfValidation = false;

    public function behaviors(): array
    {
        return [
            'verbs' => [
                'class' => VerbFilter::class,
                'actions' => [
                    'search' => ['GET'],
                    'party-two' => ['GET'],
                    'tis-proxy' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                ],
            ],
        ];
    }

    public function actionSearch(string $q = ''): Response
    {
        $query = trim($q);
        Yii::$app->response->format = Response::FORMAT_JSON;

        if ($query === '') {
            Yii::$app->response->statusCode = 400;
            return Yii::$app->response->data = [
                'error' => 'Missing query. Use ?q=...',
            ];
        }

        $url = 'https://api.duckduckgo.com/?format=json&no_html=1&skip_disambig=1&q=' . rawurlencode($query);
        [$ok, $payload, $error] = $this->fetchJson($url);

        if (!$ok) {
            Yii::$app->response->statusCode = 502;
            return Yii::$app->response->data = [
                'error' => 'Search upstream is unreachable.',
                'details' => $error,
            ];
        }

        $results = $this->duckDuckGoToRows($payload);
        return Yii::$app->response->data = [
            'query' => $query,
            'data' => $results,
            'source' => 'duckduckgo',
        ];
    }

    public function actionPartyTwo(string $story = ''): Response
    {
        // Keep compatibility with frontend/web/script.js which calls /api/party-two?story=...
        return $this->actionSearch($story);
    }

    public function actionTisProxy(string $path = ''): Response
    {
        $request = Yii::$app->request;
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;

        if ($request->isOptions) {
            $response->statusCode = 204;
            return $response;
        }

        $upstreamBase = rtrim(trim((string) (Yii::$app->params['tisApiUpstream'] ?? 'http://127.0.0.1:5000')), '/');
        $targetUrl = $upstreamBase . ($path !== '' ? '/' . ltrim($path, '/') : '');
        $queryString = (string) $request->queryString;
        if ($queryString !== '') {
            $targetUrl .= '?' . $queryString;
        }

        $ch = curl_init($targetUrl);
        if ($ch === false) {
            $response->statusCode = 500;
            $response->content = 'Failed to initialize proxy request.';
            return $response;
        }

        $forwardHeaders = [];
        foreach ($request->headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, ['host', 'connection', 'content-length', 'accept-encoding', 'x-forwarded-for'], true)) {
                continue;
            }
            $line = $name . ': ' . (is_array($value) ? implode(', ', $value) : $value);
            $forwardHeaders[] = $line;
        }
        if (str_contains($upstreamBase, 'ngrok-free.app')) {
            $forwardHeaders[] = 'ngrok-skip-browser-warning: true';
        }

        $rawBody = $request->getRawBody();
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $forwardHeaders,
        ]);
        if ($rawBody !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            curl_close($ch);

            // If ngrok is down, retry local Node (history / payments still work on localhost)
            $localFallback = 'http://127.0.0.1:5000';
            if ($upstreamBase !== $localFallback && str_contains($upstreamBase, 'ngrok')) {
                $fallbackUrl = $localFallback . ($path !== '' ? '/' . ltrim($path, '/') : '');
                if ($queryString !== '') {
                    $fallbackUrl .= '?' . $queryString;
                }
                $ch2 = curl_init($fallbackUrl);
                if ($ch2 !== false) {
                    $localHeaders = array_values(array_filter(
                        $forwardHeaders,
                        static fn(string $h): bool => stripos($h, 'ngrok-skip-browser-warning') !== 0
                    ));
                    curl_setopt_array($ch2, [
                        CURLOPT_CUSTOMREQUEST => $request->method,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HEADER => true,
                        CURLOPT_FOLLOWLOCATION => false,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_TIMEOUT => 60,
                        CURLOPT_HTTPHEADER => $localHeaders,
                    ]);
                    if ($rawBody !== '') {
                        curl_setopt($ch2, CURLOPT_POSTFIELDS, $rawBody);
                    }
                    $result = curl_exec($ch2);
                    if ($result !== false) {
                        $ch = $ch2;
                    } else {
                        curl_close($ch2);
                    }
                }
            }

            if ($result === false) {
                $response->statusCode = 502;
                $response->content = json_encode([
                    'message' => 'Upstream API unreachable.',
                    'details' => $error,
                ], JSON_UNESCAPED_SLASHES);
                $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
                return $response;
            }
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($result, 0, $headerSize);
        $body = substr($result, $headerSize);
        $response->statusCode = $statusCode > 0 ? $statusCode : 200;

        $headerBlocks = preg_split("/\r\n\r\n|\n\n|\r\r/", (string) $rawHeaders);
        $lastHeaderBlock = trim((string) end($headerBlocks));
        $lines = preg_split("/\r\n|\n|\r/", $lastHeaderBlock) ?: [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);
            $lower = strtolower($name);
            if (in_array($lower, ['transfer-encoding', 'connection', 'keep-alive'], true)) {
                continue;
            }
            if (in_array($lower, ['content-type', 'cache-control', 'pragma', 'expires', 'x-accel-buffering'], true)) {
                $response->headers->set($name, $value);
            }
        }

        $response->content = $body;
        return $response;
    }

    /**
     * @return array{0: bool, 1: array<string,mixed>, 2: string}
     */
    private function fetchJson(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [false, [], 'Failed to initialize cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Getway-System/1.0',
            ],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [false, [], $error ?: 'Unknown cURL error'];
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            return [false, [], 'Upstream HTTP ' . $statusCode];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [false, [], 'Invalid JSON from upstream'];
        }

        return [true, $decoded, ''];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int, array<string,string>>
     */
    private function duckDuckGoToRows(array $payload): array
    {
        $rows = [];

        $pushRow = static function (string $text, string $url) use (&$rows): void {
            if ($text === '' || $url === '') {
                return;
            }
            $host = parse_url($url, PHP_URL_HOST);
            $rows[] = [
                'title' => substr($text, 0, 140),
                'snippet' => $text,
                'url' => $url,
                'domain' => is_string($host) ? $host : '',
            ];
        };

        $abstractText = trim((string) ($payload['AbstractText'] ?? ''));
        $abstractUrl = trim((string) ($payload['AbstractURL'] ?? ''));
        if ($abstractText !== '' && $abstractUrl !== '') {
            $pushRow($abstractText, $abstractUrl);
        }

        $collectTopics = function (array $topics) use (&$collectTopics, $pushRow): void {
            foreach ($topics as $topic) {
                if (!is_array($topic)) {
                    continue;
                }
                if (isset($topic['Topics']) && is_array($topic['Topics'])) {
                    $collectTopics($topic['Topics']);
                    continue;
                }
                $text = trim((string) ($topic['Text'] ?? ''));
                $url = trim((string) ($topic['FirstURL'] ?? ''));
                $pushRow($text, $url);
            }
        };

        $related = $payload['RelatedTopics'] ?? [];
        if (is_array($related)) {
            $collectTopics($related);
        }

        if (count($rows) === 0) {
            $rows[] = [
                'title' => 'No results found',
                'snippet' => 'No matching results were returned by the search upstream.',
                'url' => '',
                'domain' => '',
            ];
        }

        return array_slice($rows, 0, 10);
    }
}
