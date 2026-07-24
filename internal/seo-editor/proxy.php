<?php

declare(strict_types=1);

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);

session_name('TEMED_SEO_EDITOR');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function proxyEncode($data): string
{
    return (string)json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );
}

function proxySendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);

    echo proxyEncode($payload);

    exit;
}

/**
 * Выполнить JSON-запрос через cURL.
 *
 * @param string[] $headers
 * @return array{ok:bool,status:int,body:string,error:string,decoded:mixed}
 */
function proxyHttpRequest(
    string $url,
    string $method,
    array $headers,
    ?string $body,
    int $connectTimeout,
    int $timeout
): array {
    $curl = curl_init($url);

    if ($curl === false) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'error' => 'Не удалось инициализировать запрос',
            'decoded' => null,
        ];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($curl, $options);

    $responseBody = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);

    curl_close($curl);

    if ($responseBody === false) {
        return [
            'ok' => false,
            'status' => $status,
            'body' => '',
            'error' => $error,
            'decoded' => null,
        ];
    }

    $decoded = json_decode((string)$responseBody, true);

    return [
        'ok' => true,
        'status' => $status,
        'body' => (string)$responseBody,
        'error' => '',
        'decoded' => json_last_error() === JSON_ERROR_NONE ? $decoded : null,
    ];
}

/**
 * Отдать клиенту ответ upstream-сервиса как есть, с его HTTP-статусом.
 *
 * @param array{ok:bool,status:int,body:string,error:string,decoded:mixed} $result
 */
function proxyRelay(array $result, string $upstreamName): void
{
    if (!$result['ok']) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Не удалось выполнить запрос к ' . $upstreamName,
                'details' => $result['error'],
            ],
            502
        );
    }

    if (!is_array($result['decoded'])) {
        $bodyPreview = mb_substr((string)$result['body'], 0, 300);

        proxySendJson(
            [
                'success' => false,
                'error' => $upstreamName . ' вернул некорректный JSON',
                'upstream_status' => $result['status'],
                'details' => 'HTTP ' . $result['status'] . ': ' . $bodyPreview,
            ],
            502
        );
    }

    http_response_code($result['status'] > 0 ? $result['status'] : 502);

    echo proxyEncode($result['decoded']);

    exit;
}

/**
 * Прочитать read-only action из TEMED SEO API и вернуть его поле data.
 * Используется для обогащения контекста ассистента; ошибки не фатальны.
 *
 * @return mixed
 */
function proxyFetchApiData(string $apiUrl, string $apiToken, string $action)
{
    if ($apiUrl === '' || $apiToken === '') {
        return null;
    }

    $url = $apiUrl
        . (str_contains($apiUrl, '?') ? '&' : '?')
        . http_build_query(['action' => $action], '', '&', PHP_QUERY_RFC3986);

    $result = proxyHttpRequest(
        $url,
        'GET',
        [
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
        ],
        null,
        10,
        30
    );

    if (!$result['ok'] || !is_array($result['decoded'])) {
        return null;
    }

    return $result['decoded']['data'] ?? null;
}

/**
 * Разобрать значение вида "40M" / "8M" / "1G" из php.ini в байты.
 * Пустое значение или "0" трактуется как «без лимита» (0).
 */
function proxyIniBytes(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $unit = strtolower($value[strlen($value) - 1]);
    $number = (int)$value;

    switch ($unit) {
        case 'g':
            $number *= 1024;
            // fallthrough
        case 'm':
            $number *= 1024;
            // fallthrough
        case 'k':
            $number *= 1024;
    }

    return $number > 0 ? $number : 0;
}

/**
 * Дополнить payload assistant_chat живым контекстом из TEMED SEO API:
 * system_manifest, article_structures и справочники (read-only reference data).
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function proxyEnrichAssistantPayload(array $payload, string $apiUrl, string $apiToken): array
{
    $manifest = proxyFetchApiData($apiUrl, $apiToken, 'system_manifest');
    $structures = proxyFetchApiData($apiUrl, $apiToken, 'article_structures');
    $dictionaries = proxyFetchApiData($apiUrl, $apiToken, 'dictionaries');

    $missing = [];

    if ($manifest === null) {
        $missing[] = 'system_manifest';
    }

    if ($structures === null) {
        $missing[] = 'article_structures';
    }

    if ($dictionaries === null) {
        $missing[] = 'dictionaries';
    }

    if ($missing !== []) {
        error_log(
            'TEMED SEO proxy: не удалось обогатить assistant_chat полями: '
            . implode(', ', $missing)
        );
    }

    $payload['system_manifest'] = $manifest;
    $payload['article_structures'] = $structures;
    $payload['dictionaries'] = $dictionaries;

    return $payload;
}

if (empty($_SESSION['seo_editor_authenticated'])) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'Unauthorized',
        ],
        401
    );
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');

    proxySendJson(
        [
            'success' => false,
            'error' => 'Метод не поддерживается',
            'allowed_methods' => ['GET', 'POST'],
        ],
        405
    );
}

$configFile = __DIR__ . '/config.php';

if (!is_file($configFile) || !is_readable($configFile)) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'Не найден config.php',
        ],
        500
    );
}

$config = require $configFile;

$apiUrl = trim((string)($config['temed_seo_api_url'] ?? ''));
$apiToken = trim((string)($config['temed_seo_api_token'] ?? ''));
$apiWriteToken = trim((string)($config['temed_seo_api_write_token'] ?? ''));
$n8nBaseUrl = trim((string)($config['n8n_base_url'] ?? ''));
$n8nSecret = trim((string)($config['n8n_secret'] ?? ''));

if (!function_exists('curl_init')) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'На сервере недоступно расширение cURL',
        ],
        500
    );
}

// GET read-only actions проксируются в TEMED SEO API.
$allowedGetActions = [
    'ping',
    'capabilities',
    'bootstrap',
    'doctors',
    'doctor',
    'doctor_properties',
    'articles',
    'article',
    'article_properties',
    'article_sections',
    'article_structures',
    'clinics',
    'clinic',
    'prices',
    'price',
    'services',
    'service',
    'dictionaries',
];

$allowedParameters = [
    'action',
    'id',
    'source',
    'q',
    'active',
    'section_id',
    'limit',
    'offset',
];

// POST-действия AI/TEXT.RU и черновиков, которые обрабатывает n8n-вебхук
// (см. assets/js/editor.js: callN8n / callDraftApi).
$n8nActions = [
    'research_sources',
    'generate_outline',
    'approve_outline',
    'generate_article',
    'extract_med_questions',
    'apply_med_answers',
    'validate_article',
    'revise_article',
    'start_external_uniqueness',
    'get_external_uniqueness',
    'assistant_chat',
    'draft_create',
    'draft_save_version',
    'draft_get',
    'draft_get_version',
    'draft_restore_version',
    'draft_list',
    'draft_archive',
    'draft_unarchive',
    'draft_purge',
    'transcribe_case',
];

if ($method === 'GET') {
    if ($apiUrl === '' || $apiToken === '') {
        proxySendJson(
            [
                'success' => false,
                'error' => 'В config.php не настроены TEMED SEO API URL или token',
            ],
            500
        );
    }

    $action = isset($_GET['action']) && !is_array($_GET['action'])
        ? trim((string)$_GET['action'])
        : '';

    if ($action === '' || !in_array($action, $allowedGetActions, true)) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Недопустимое действие',
                'allowed_actions' => $allowedGetActions,
            ],
            400
        );
    }

    $query = [];

    foreach ($allowedParameters as $parameter) {
        if (!array_key_exists($parameter, $_GET) || is_array($_GET[$parameter])) {
            continue;
        }

        $query[$parameter] = trim((string)$_GET[$parameter]);
    }

    $query['action'] = $action;

    $requestUrl = $apiUrl
        . (str_contains($apiUrl, '?') ? '&' : '?')
        . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $result = proxyHttpRequest(
        $requestUrl,
        'GET',
        [
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
        ],
        null,
        10,
        60
    );

    proxyRelay($result, 'TEMED SEO API');
}

// method === 'POST'
$rawBody = file_get_contents('php://input');
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxBytes = proxyIniBytes((string)ini_get('post_max_size'));

// Если php://input пуст при ненулевом Content-Length — тело обрезано сервером
// (как правило post_max_size меньше размера запроса). Возвращаем понятную
// ошибку вместо молчаливого обрыва / «некорректного JSON».
if (($rawBody === '' || $rawBody === false) && $contentLength > 0) {
    if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Тело запроса (~' . round($contentLength / 1048576, 1)
                    . ' МБ) превышает серверный лимит post_max_size (~'
                    . round($postMaxBytes / 1048576, 1) . ' МБ). Уменьшите размер '
                    . 'аудиофайла или увеличьте post_max_size на сервере.',
            ],
            413
        );
    }

    proxySendJson(
        [
            'success' => false,
            'error' => 'Пустое тело POST-запроса при Content-Length ' . $contentLength . ' байт.',
        ],
        400
    );
}

$payload = json_decode((string)$rawBody, true);

if (!is_array($payload)) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'Тело запроса должно быть корректным JSON-объектом',
        ],
        400
    );
}

$action = isset($payload['action']) && !is_array($payload['action'])
    ? trim((string)$payload['action'])
    : '';

if ($action === '') {
    proxySendJson(
        [
            'success' => false,
            'error' => 'Не передан action',
        ],
        400
    );
}

// Транскрибация аудио: тело раздувается base64 (25 МБ аудио -> ~34 МБ),
// поэтому допускаем до 40 МБ, но не больше — иначе понятная ошибка.
if ($action === 'transcribe_case') {
    $transcribeMaxBytes = 40 * 1024 * 1024;
    $bodyBytes = $contentLength > 0 ? $contentLength : strlen((string)$rawBody);

    if ($bodyBytes > $transcribeMaxBytes) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Аудиозапрос (~' . round($bodyBytes / 1048576, 1)
                    . ' МБ) превышает лимит 40 МБ.',
            ],
            413
        );
    }
}

// Запись неактивного черновика в Bitrix идёт напрямую в TEMED SEO API
// с серверным write-token (в браузер токен не попадает).
if ($action === 'create_or_update_draft') {
    if ($apiUrl === '') {
        proxySendJson(
            [
                'success' => false,
                'error' => 'В config.php не настроен TEMED SEO API URL',
            ],
            500
        );
    }

    if ($apiWriteToken === '' || $apiWriteToken === 'CHANGE_ME') {
        proxySendJson(
            [
                'success' => false,
                'error' => 'В config.php не настроен temed_seo_api_write_token',
            ],
            500
        );
    }

    $result = proxyHttpRequest(
        $apiUrl,
        'POST',
        [
            'Authorization: Bearer ' . $apiWriteToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        proxyEncode($payload),
        10,
        120
    );

    proxyRelay($result, 'TEMED SEO API');
}

// Каннибализация и внутренняя уникальность считаются нативно в PHP
// (TEMED SEO API POST), n8n эти алгоритмы не реализует.
if ($action === 'cannibalization_check') {
    if ($apiUrl === '' || $apiToken === '') {
        proxySendJson(
            [
                'success' => false,
                'error' => 'В config.php не настроены TEMED SEO API URL или token',
            ],
            500
        );
    }

    $result = proxyHttpRequest(
        $apiUrl,
        'POST',
        [
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        proxyEncode($payload),
        10,
        120
    );

    proxyRelay($result, 'TEMED SEO API');
}

if ($action === 'check_internal_uniqueness') {
    if ($apiUrl === '' || $apiToken === '') {
        proxySendJson(
            [
                'success' => false,
                'error' => 'В config.php не настроены TEMED SEO API URL или token',
            ],
            500
        );
    }

    $payload['action'] = 'internal_uniqueness';

    $result = proxyHttpRequest(
        $apiUrl,
        'POST',
        [
            'Authorization: Bearer ' . $apiToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        proxyEncode($payload),
        10,
        120
    );

    proxyRelay($result, 'TEMED SEO API');
}

// AI/TEXT.RU и черновики проксируются в n8n-вебхук.
if (in_array($action, $n8nActions, true)) {
    if ($n8nBaseUrl === '') {
        proxySendJson(
            [
                'success' => false,
                'error' => 'В config.php не настроен n8n_base_url',
            ],
            500
        );
    }

    if ($action === 'assistant_chat') {
        $payload = proxyEnrichAssistantPayload($payload, $apiUrl, $apiToken);
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    if ($n8nSecret !== '') {
        $headers[] = 'X-TEMED-SEO-SECRET: ' . $n8nSecret;
    } else {
        error_log('TEMED SEO proxy: n8n_secret не задан в config.php.');
    }

    // Транскрибация аудио может идти дольше обычных AI-действий.
    $n8nTimeout = $action === 'transcribe_case' ? 300 : 120;

    $result = proxyHttpRequest(
        $n8nBaseUrl,
        'POST',
        $headers,
        proxyEncode($payload),
        10,
        $n8nTimeout
    );

    proxyRelay($result, 'n8n');
}

proxySendJson(
    [
        'success' => false,
        'error' => 'Недопустимое действие',
        'allowed_actions' => array_merge(['check_internal_uniqueness', 'cannibalization_check', 'create_or_update_draft'], $n8nActions),
    ],
    400
);
