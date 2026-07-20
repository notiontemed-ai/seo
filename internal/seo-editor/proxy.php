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

function proxySendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    exit;
}

function proxyApiRequest(
    string $apiUrl,
    string $apiToken,
    array $query
): array {
    $requestUrl = $apiUrl
        . (str_contains($apiUrl, '?') ? '&' : '?')
        . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $curl = curl_init($requestUrl);

    if ($curl === false) {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'Не удалось инициализировать запрос к API',
        ];
    }

    curl_setopt_array(
        $curl,
        [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiToken,
                'Accept: application/json',
            ],
        ]
    );

    $responseBody = curl_exec($curl);
    $responseCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);

    curl_close($curl);

    if ($responseBody === false) {
        return [
            'ok' => false,
            'status' => 502,
            'error' => 'Не удалось выполнить запрос к TEMED SEO API',
            'details' => $curlError,
        ];
    }

    $decoded = json_decode((string)$responseBody, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [
            'ok' => false,
            'status' => 502,
            'error' => 'TEMED SEO API вернул некорректный JSON',
            'upstream_status' => $responseCode,
        ];
    }

    return [
        'ok' => true,
        'status' => $responseCode > 0 ? $responseCode : 502,
        'payload' => $decoded,
    ];
}

function proxyRequireSuccess(array $result, string $label): array
{
    if (empty($result['ok'])) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Не удалось загрузить справочник: ' . $label,
                'details' => $result,
            ],
            (int)($result['status'] ?? 502)
        );
    }

    $payload = $result['payload'] ?? [];

    if (
        !is_array($payload)
        || empty($payload['success'])
    ) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'API вернул ошибку при загрузке справочника: ' . $label,
                'upstream' => $payload,
            ],
            (int)($result['status'] ?? 502)
        );
    }

    return $payload;
}

function proxyExtractItems(array $payload): array
{
    $data = $payload['data'] ?? [];

    if (isset($data['items']) && is_array($data['items'])) {
        return $data['items'];
    }

    if (is_array($data) && array_is_list($data)) {
        return $data;
    }

    return [];
}

function proxyNormalizeCollection(array $payload): array
{
    $data = $payload['data'] ?? [];

    if (isset($data['items']) && is_array($data['items'])) {
        return $data;
    }

    if (is_array($data) && array_is_list($data)) {
        return [
            'items' => $data,
            'count' => count($data),
        ];
    }

    if (is_array($data)) {
        return $data;
    }

    return [
        'items' => [],
        'count' => 0,
    ];
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

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
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
$n8nUrl = trim((string)($config['n8n_base_url'] ?? ''));
$n8nSecret = trim((string)($config['n8n_secret'] ?? ''));

if ($apiUrl === '' || $apiToken === '') {
    proxySendJson(
        [
            'success' => false,
            'error' => 'В config.php не настроены TEMED SEO API URL или token',
        ],
        500
    );
}

if (!function_exists('curl_init')) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'На сервере недоступно расширение cURL',
        ],
        500
    );
}


if ($requestMethod === 'POST') {
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        proxySendJson(['success' => false, 'error' => 'Пустое тело запроса'], 400);
    }

    $requestPayload = json_decode($rawBody, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($requestPayload)) {
        proxySendJson(['success' => false, 'error' => 'Тело запроса должно быть корректным JSON'], 400);
    }

    if (($requestPayload['action'] ?? '') === 'check_internal_uniqueness') {
        $article = is_array($requestPayload['article'] ?? null) ? $requestPayload['article'] : [];
        $exclude = [];
        $source = (string)($requestPayload['existing_article_source'] ?? $requestPayload['exclude']['source'] ?? '');
        $id = (int)($requestPayload['existing_article_id'] ?? $requestPayload['exclude']['element_id'] ?? 0);
        if (in_array($source, ['new', 'legacy'], true) && $id > 0) {
            $exclude = ['source' => $source, 'element_id' => $id];
        }
        $encodedApiPayload = json_encode(['action' => 'internal_uniqueness', 'article' => $article, 'exclude' => $exclude, 'options' => $requestPayload['options'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $curl = curl_init($apiUrl);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $encodedApiPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiToken, 'Content-Type: application/json', 'Accept: application/json']]);
        $body = curl_exec($curl);
        $code = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $err = curl_error($curl);
        curl_close($curl);
        if ($body === false) proxySendJson(['success'=>false,'error'=>'Не удалось выполнить внутреннюю проверку','details'=>$err], 502);
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) proxySendJson(['success'=>false,'error'=>'TEMED SEO API вернул некорректный JSON'], 502);
        proxySendJson($decoded, $code > 0 ? $code : 200);
    }
    if ($n8nUrl === '' || $n8nSecret === '') {
        proxySendJson(
            [
                'success' => false,
                'error' => 'В config.php не настроены n8n_base_url или n8n_secret',
            ],
            500
        );
    }

    $n8nAllowedActions = [
        'health',
        'research_sources',
        'generate_outline',
        'generate_article',
        'extract_med_questions',
        'apply_med_answers',
        'validate_article',
        'revise_article',
        'approve_outline',
        'assistant_chat',
        'start_external_uniqueness',
        'get_external_uniqueness',
        'assistant_refresh_knowledge',
        'create_bitrix_draft',
    ];

    $n8nAction = isset($requestPayload['action'])
        && !is_array($requestPayload['action'])
        ? trim((string)$requestPayload['action'])
        : '';

    if (
        $n8nAction === ''
        || !in_array($n8nAction, $n8nAllowedActions, true)
    ) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Недопустимое действие n8n',
                'allowed_actions' => $n8nAllowedActions,
            ],
            400
        );
    }

    if ($n8nAction === 'check_internal_uniqueness') {
        // Reserved for explicit server-side TEMED SEO API route.
    }

    $sizeLimits = [
        'assistant_chat' => ['message' => 10000, 'generated_outline' => 100000],
        'start_external_uniqueness' => ['text' => 150000],
    ];
    foreach (($sizeLimits[$n8nAction] ?? []) as $field => $limit) {
        $value = $requestPayload[$field] ?? ($requestPayload['data'][$field] ?? '');
        if (is_string($value) && mb_strlen($value, 'UTF-8') > $limit) {
            proxySendJson(['success' => false, 'error' => 'Payload too large', 'field' => $field], 413);
        }
    }
    if (isset($requestPayload['conversation']) && is_array($requestPayload['conversation']) && count($requestPayload['conversation']) > 20) {
        proxySendJson(['success' => false, 'error' => 'Слишком длинная история ассистента'], 422);
    }

    unset($requestPayload['secret']);

    if ($n8nAction === 'assistant_chat') {
        $warnings = [];
        $manifestResult = proxyApiRequest($apiUrl, $apiToken, ['action' => 'system_manifest']);
        $structuresResult = proxyApiRequest($apiUrl, $apiToken, ['action' => 'article_structures']);
        $dictResult = proxyApiRequest($apiUrl, $apiToken, ['action' => 'dictionaries']);
        if (empty($manifestResult['ok']) || (($manifestResult['payload']['success'] ?? false) !== true)) {
            $warnings[] = 'LIVE_SYSTEM_MANIFEST_UNAVAILABLE';
        }
        $dictData = $dictResult['payload']['data'] ?? [];
        $summary = [];
        foreach (is_array($dictData) ? $dictData : [] as $key => $value) {
            $summary[$key] = is_array($value) ? count($value) : gettype($value);
        }
        $requestPayload['system_context'] = [
            'manifest' => $manifestResult['payload']['data'] ?? null,
            'structures' => $structuresResult['payload']['data'] ?? null,
            'dictionaries_summary' => $summary,
            'warnings' => $warnings,
        ];
    }

    $requestPayload['action'] = $n8nAction;
    $requestPayload['request_id'] = isset($requestPayload['request_id'])
        ? (string)$requestPayload['request_id']
        : 'web-' . bin2hex(random_bytes(8));

    $encodedPayload = json_encode(
        $requestPayload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    if ($encodedPayload === false) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Не удалось сформировать запрос для n8n',
            ],
            500
        );
    }

    $curl = curl_init($n8nUrl);

    if ($curl === false) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Не удалось инициализировать запрос к n8n',
            ],
            500
        );
    }

    curl_setopt_array(
        $curl,
        [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => [
                'assistant_chat' => 180,
                'start_external_uniqueness' => 30,
                'get_external_uniqueness' => 30,
            ][$n8nAction] ?? 120,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-TEMED-SEO-SECRET: ' . $n8nSecret,
            ],
        ]
    );

    $n8nResponseBody = curl_exec($curl);
    $n8nResponseCode = (int)curl_getinfo(
        $curl,
        CURLINFO_RESPONSE_CODE
    );
    $n8nCurlError = curl_error($curl);

    curl_close($curl);

    if ($n8nResponseBody === false) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'Не удалось выполнить запрос к n8n',
                'details' => $n8nCurlError,
            ],
            502
        );
    }

    $n8nDecoded = json_decode((string)$n8nResponseBody, true);

    if (
        json_last_error() !== JSON_ERROR_NONE
        || !is_array($n8nDecoded)
    ) {
        proxySendJson(
            [
                'success' => false,
                'error' => 'n8n вернул некорректный JSON',
                'upstream_status' => $n8nResponseCode,
                'raw_preview' => mb_substr(
                    (string)$n8nResponseBody,
                    0,
                    2000
                ),
            ],
            502
        );
    }

    http_response_code(
        $n8nResponseCode > 0
            ? $n8nResponseCode
            : 502
    );

    echo json_encode(
        $n8nDecoded,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    );

    exit;
}

$allowedActions = [
    'ping',
    'bootstrap',
    'capabilities',
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
    'system_manifest',
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

$action = isset($_GET['action']) && !is_array($_GET['action'])
    ? trim((string)$_GET['action'])
    : '';

if ($action === '' || !in_array($action, $allowedActions, true)) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'Недопустимое действие',
            'allowed_actions' => $allowedActions,
        ],
        400
    );
}

/*
 * bootstrap — агрегированный read-only ответ для интерфейса.
 * Сам TEMED SEO API такого action не обязан иметь: proxy собирает его
 * из нескольких существующих методов и не раскрывает Bearer-токен браузеру.
 */
if ($action === 'bootstrap') {
    $dictionariesPayload = proxyRequireSuccess(
        proxyApiRequest(
            $apiUrl,
            $apiToken,
            [
                'action' => 'dictionaries',
            ]
        ),
        'dictionaries'
    );

    $sectionsPayload = proxyRequireSuccess(
        proxyApiRequest(
            $apiUrl,
            $apiToken,
            [
                'action' => 'article_sections',
            ]
        ),
        'article_sections'
    );

    $doctorsPayload = proxyRequireSuccess(
        proxyApiRequest(
            $apiUrl,
            $apiToken,
            [
                'action' => 'doctors',
                'active' => 'Y',
                'limit' => 500,
                'offset' => 0,
            ]
        ),
        'doctors'
    );

    $newArticlesPayload = proxyRequireSuccess(
        proxyApiRequest(
            $apiUrl,
            $apiToken,
            [
                'action' => 'articles',
                'source' => 'new',
                'limit' => 500,
                'offset' => 0,
            ]
        ),
        'articles:new'
    );

    $legacyArticlesPayload = proxyRequireSuccess(
        proxyApiRequest(
            $apiUrl,
            $apiToken,
            [
                'action' => 'articles',
                'source' => 'legacy',
                'limit' => 500,
                'offset' => 0,
            ]
        ),
        'articles:legacy'
    );

    $clinicsPayload = proxyRequireSuccess(
        proxyApiRequest(
            $apiUrl,
            $apiToken,
            [
                'action' => 'clinics',
                'active' => 'Y',
                'limit' => 500,
                'offset' => 0,
            ]
        ),
        'clinics'
    );

    $pricesPayload = proxyRequireSuccess(
        proxyApiRequest(
            $apiUrl,
            $apiToken,
            [
                'action' => 'prices',
                'active' => 'Y',
                'limit' => 1000,
                'offset' => 0,
            ]
        ),
        'prices'
    );

    $dictionaries = $dictionariesPayload['data'] ?? [];

    if (!is_array($dictionaries)) {
        $dictionaries = [];
    }

    $dictionaries['article_sections'] = $sectionsPayload['data'] ?? [];

    $newArticles = proxyExtractItems($newArticlesPayload);
    $legacyArticles = proxyExtractItems($legacyArticlesPayload);

    foreach ($newArticles as &$article) {
        if (is_array($article) && empty($article['source'])) {
            $article['source'] = 'new';
        }
    }
    unset($article);

    foreach ($legacyArticles as &$article) {
        if (is_array($article) && empty($article['source'])) {
            $article['source'] = 'legacy';
        }
    }
    unset($article);

    $doctors = proxyExtractItems($doctorsPayload);
    $clinics = proxyNormalizeCollection($clinicsPayload);
    $prices = proxyNormalizeCollection($pricesPayload);

    proxySendJson(
        [
            'success' => true,
            'api_version' => $dictionariesPayload['api_version'] ?? null,
            'data' => [
                'dictionaries' => $dictionaries,
                'doctors' => $doctors,
                'articles' => [
                    'items' => array_values(
                        array_merge(
                            $newArticles,
                            $legacyArticles
                        )
                    ),
                    'count' => count($newArticles) + count($legacyArticles),
                    'new_count' => count($newArticles),
                    'legacy_count' => count($legacyArticles),
                ],
                'clinics' => $clinics,
                'prices' => $prices,
            ],
            'meta' => [
                'counts' => [
                    'doctors' => count($doctors),
                    'articles_new' => count($newArticles),
                    'articles_legacy' => count($legacyArticles),
                    'clinics' => isset($clinics['items']) && is_array($clinics['items'])
                        ? count($clinics['items'])
                        : 0,
                    'prices' => isset($prices['items']) && is_array($prices['items'])
                        ? count($prices['items'])
                        : 0,
                ],
            ],
        ]
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

$result = proxyApiRequest(
    $apiUrl,
    $apiToken,
    $query
);

if (empty($result['ok'])) {
    proxySendJson(
        [
            'success' => false,
            'error' => $result['error'] ?? 'Ошибка TEMED SEO API',
            'details' => $result['details'] ?? null,
            'upstream_status' => $result['upstream_status'] ?? null,
        ],
        (int)($result['status'] ?? 502)
    );
}

http_response_code((int)($result['status'] ?? 502));

echo json_encode(
    $result['payload'],
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_PRETTY_PRINT
);
