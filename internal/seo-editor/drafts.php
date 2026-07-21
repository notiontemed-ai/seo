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

const DRAFT_ACTIONS = [
    'health', 'get_dictionaries', 'list_drafts', 'get_draft', 'create_draft',
    'save_draft_version', 'list_versions', 'get_version', 'restore_version',
    'delete_draft', 'restore_draft', 'purge_draft',
];
const MAX_REQUEST_BYTES = 6_000_000;

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $code, string $message, int $status, array $details = []): void
{
    json_response(['success' => false, 'error' => $code, 'message' => $message, 'details' => $details], $status);
}

if (empty($_SESSION['seo_editor_authenticated'])) {
    fail('UNAUTHORIZED', 'Сессия редактора завершена.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('METHOD_NOT_ALLOWED', 'Черновики принимают только POST-запросы.', 405);
}
$length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > MAX_REQUEST_BYTES) {
    fail('REQUEST_TOO_LARGE', 'Запрос черновика превышает допустимый размер.', 413);
}
$raw = file_get_contents('php://input') ?: '';
$request = json_decode($raw, true);
if (!is_array($request)) {
    fail('INVALID_JSON', 'Некорректный JSON в запросе.', 400);
}
$action = (string)($request['action'] ?? '');
if (!in_array($action, DRAFT_ACTIONS, true)) {
    fail('UNKNOWN_ACTION', 'Действие черновиков не разрешено.', 422);
}
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    fail('CONFIG_MISSING', 'Файл config.php не настроен.', 500);
}
$config = require $configFile;
$webAppUrl = trim((string)($config['drafts_web_app_url'] ?? ''));
$secret = (string)($config['drafts_shared_secret'] ?? '');
$userName = trim((string)($config['editor_user_name'] ?? 'TEMED SEO Editor')) ?: 'TEMED SEO Editor';
if ($webAppUrl === '' || $secret === '') {
    fail('DRAFTS_NOT_CONFIGURED', 'Apps Script для черновиков не настроен.', 500);
}
$outgoing = [
    'secret' => $secret,
    'action' => $action,
    'user' => $userName,
    'data' => is_array($request['data'] ?? null) ? $request['data'] : [],
];
$ch = curl_init($webAppUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode($outgoing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 30,
]);
$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($responseBody === false) {
    error_log('seo drafts proxy curl_error action=' . $action . ' error=' . $curlError);
    fail('UPSTREAM_UNAVAILABLE', 'Сервис черновиков временно недоступен.', 502);
}
$payload = json_decode($responseBody, true);
if (!is_array($payload)) {
    error_log('seo drafts proxy non_json action=' . $action . ' http=' . $httpCode . ' bytes=' . strlen($responseBody));
    fail('UPSTREAM_BAD_JSON', 'Сервис черновиков вернул некорректный ответ.', 502);
}
if ($httpCode < 200 || $httpCode >= 300 || ($payload['success'] ?? false) !== true) {
    $code = (string)($payload['error'] ?? 'UPSTREAM_ERROR');
    $message = (string)($payload['message'] ?? 'Ошибка сервиса черновиков.');
    $status = $code === 'VERSION_CONFLICT' ? 409 : ($httpCode >= 400 && $httpCode < 600 ? $httpCode : 502);
    unset($payload['stack'], $payload['secret']);
    fail($code, $message, $status, is_array($payload['details'] ?? null) ? $payload['details'] : []);
}
unset($payload['secret']);
json_response(['success' => true, 'data' => is_array($payload['data'] ?? null) ? $payload['data'] : []]);
