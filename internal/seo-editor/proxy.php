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

if (empty($_SESSION['seo_editor_authenticated'])) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'Unauthorized',
        ],
        401
    );
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');

    proxySendJson(
        [
            'success' => false,
            'error' => 'Метод не поддерживается',
            'allowed_methods' => ['GET'],
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

if ($apiUrl === '' || $apiToken === '') {
    proxySendJson(
        [
            'success' => false,
            'error' => 'В config.php не настроены TEMED SEO API URL или token',
        ],
        500
    );
}

$allowedActions = [
    'ping',
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

if (!function_exists('curl_init')) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'На сервере недоступно расширение cURL',
        ],
        500
    );
}

$curl = curl_init($requestUrl);

if ($curl === false) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'Не удалось инициализировать запрос к API',
        ],
        500
    );
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
    proxySendJson(
        [
            'success' => false,
            'error' => 'Не удалось выполнить запрос к TEMED SEO API',
            'details' => $curlError,
        ],
        502
    );
}

$decoded = json_decode((string)$responseBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    proxySendJson(
        [
            'success' => false,
            'error' => 'TEMED SEO API вернул некорректный JSON',
            'upstream_status' => $responseCode,
        ],
        502
    );
}

http_response_code($responseCode > 0 ? $responseCode : 502);

echo json_encode(
    $decoded,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_PRETTY_PRINT
);
