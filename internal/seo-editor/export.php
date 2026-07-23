<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/BitrixArticleXmlExporter.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('TEMED_SEO_EDITOR');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Strict']);
session_start();

function jsonError(int $status, string $message, array $details = []): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode(['success'=>false,'message'=>$message,'details'=>$details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['seo_editor_authenticated'])) jsonError(401, 'Unauthorized');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    jsonError(405, 'Метод не поддерживается');
}
$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) jsonError(400, 'Тело запроса должно быть JSON');

$required = ['name','code','detail_html','primary_query','article_structure','article_structure_version','search_intent','article_type','author_id','medical_reviewer_id','section'];
$missing = [];
foreach ($required as $field) {
    if (!array_key_exists($field, $payload) || $payload[$field] === null || (is_string($payload[$field]) && trim($payload[$field]) === '') || (is_array($payload[$field]) && count($payload[$field]) === 0)) $missing[] = $field;
}
if ($missing) jsonError(422, 'Не удалось сформировать XML: заполните обязательные поля.', ['missing_fields'=>$missing]);

$exporter = new BitrixArticleXmlExporter();
try {
    $doc = $exporter->export($payload);
} catch (Throwable $e) {
    jsonError(500, 'Не удалось сформировать XML.', ['reason'=>$e->getMessage()]);
}
$problems = $exporter->validate($doc);
if ($problems) jsonError(500, 'Сформированный XML неполный: отсутствуют обязательные узлы.', ['missing'=>$problems]);
$filename = $exporter->filename($payload);
header('X-TEMED-XML-Filled-Properties: ' . (string)$exporter->filledProperties());
header('X-TEMED-XML-Warnings: ' . rawurlencode(json_encode($exporter->warnings(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $doc->saveXML();
