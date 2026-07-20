<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/internal/seo-editor/lib/ArticleContentNormalizer.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function send(array $payload, int $status = 200): void { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); exit; }
$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : ['bearer_token' => 'CHANGE_ME'];
$token = trim((string)($config['bearer_token'] ?? ''));
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($token !== '' && $token !== 'CHANGE_ME' && !hash_equals('Bearer ' . $token, $auth)) send(['success'=>false,'error'=>'Unauthorized'], 401);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = $method === 'POST' ? (json_decode((string)file_get_contents('php://input'), true)['action'] ?? '') : ($_GET['action'] ?? '');
$action = is_string($action) ? trim($action) : '';

if ($action === 'system_manifest') {
    send(['success'=>true,'api_version'=>'1.2.0','data'=>[
        'editor_version'=>'1.1.0','deployed_commit'=>'','article_iblock_id'=>81,'legacy_article_iblock_id'=>68,
        'available_actions'=>['ping','dictionaries','article_structures','system_manifest','internal_uniqueness'],
        'article_properties'=>['ARTICLE_STRUCTURE','ARTICLE_STRUCTURE_NAME','ARTICLE_STRUCTURE_VERSION','SEARCH_INTENT','ARTICLE_TYPE','AUTHOR','MEDICAL_REVIEWER','REGION','RELATED_ARTICLES'],
        'structure_versions'=>['1.0.0'], 'deployed_files'=>[]
    ]]);
}

if ($action === 'internal_uniqueness') {
    if ($method !== 'POST') send(['success'=>false,'error'=>'POST required'], 405);
    $payload = json_decode((string)file_get_contents('php://input'), true);
    $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
    $normalized = ArticleContentNormalizer::normalizeForInternal($article);
    $words = $normalized['words'];
    $total = max(0, count($words) - 4);
    send(['success'=>true,'data'=>[
        'status'=>'completed','content_hash'=>$normalized['hash'],'checked_at'=>gmdate('c'),
        'uniqueness_percent'=>100.0,'matched_percent'=>0.0,'matched_shingles'=>0,'total_shingles'=>$total,
        'matches'=>[],'metadata_duplicates'=>['name'=>[],'preview_text'=>[],'short_answer'=>[]],
        'warnings'=>['Корпус Bitrix не подключён в репозитории; production API должен сравнивать инфоблоки 81 и 68 на сервере.']
    ]]);
}

if ($action === 'article_structures') {
    $file = __DIR__ . '/data/article_structures.json';
    send(['success'=>true,'data'=>json_decode((string)file_get_contents($file), true)]);
}
if ($action === 'ping') send(['success'=>true,'data'=>['status'=>'ok']]);
send(['success'=>false,'error'=>'Unknown action','allowed_actions'=>['ping','article_structures','system_manifest','internal_uniqueness']], 400);
