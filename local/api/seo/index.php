<?php

declare(strict_types=1);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

require_once __DIR__ . '/lib/TextNormalizer.php';
require_once __DIR__ . '/lib/ArticleCorpusRepository.php';
require_once __DIR__ . '/lib/InternalUniquenessService.php';
require_once __DIR__ . '/lib/TextSignals.php';
require_once __DIR__ . '/lib/CorpusCache.php';
require_once __DIR__ . '/lib/CannibalizationService.php';
require_once __DIR__ . '/lib/DonorLinkParser.php';
require_once __DIR__ . '/lib/LinkingService.php';
require_once __DIR__ . '/lib/SystemManifestService.php';
require_once __DIR__ . '/lib/ContentReferenceResolver.php';
require_once __DIR__ . '/lib/ArticleContent.php';
require_once __DIR__ . '/lib/HtmlRenderer.php';
require_once __DIR__ . '/lib/HtmlToBlocksParser.php';
require_once __DIR__ . '/lib/ArticleDraftWriter.php';

const WRITE_ACTIONS = ['create_or_update_draft'];
const MAX_WRITE_BODY_BYTES = 1048576; // 1 МБ

const API_VERSION = '1.8.0';
const DEFAULT_BASE_URL = 'https://temed.ru';
const DEFAULT_LIST_LIMIT = 500;
const MAX_LIST_LIMIT = 1000;

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


// Модули API, разнесённые из монолита index.php (этап 6).
require_once __DIR__ . '/lib/ApiResponse.php';
require_once __DIR__ . '/lib/ApiAuth.php';
require_once __DIR__ . '/lib/ApiSupport.php';
require_once __DIR__ . '/lib/ReadActions.php';

set_exception_handler(
    static function (Throwable $exception): void {
        error_log(
            sprintf(
                '[TEMED SEO API] %s in %s:%d',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            )
        );

        temedSeoSendError('Внутренняя ошибка API', 500);
    }
);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    temedSeoSendError('API_NOT_CONFIGURED', 503, ['error_code' => 'API_NOT_CONFIGURED']);
}
$config = require $configPath;
if (!is_array($config)) {
    temedSeoSendError('API_NOT_CONFIGURED', 503, ['error_code' => 'API_NOT_CONFIGURED']);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$postPayload = [];
if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode((string)$rawBody, true);
    if (!is_array($decoded) && json_last_error() !== JSON_ERROR_NONE) {
        temedSeoSendError('Тело запроса должно быть корректным JSON', 400, ['error_code' => 'INVALID_JSON']);
    }
    $postPayload = is_array($decoded) ? $decoded : [];
}
$action = $method === 'POST' ? trim((string)($postPayload['action'] ?? '')) : getStringParam('action', 'ping');

// Write-действия используют отдельный write-token и лимит тела 1 МБ.
if (in_array($action, WRITE_ACTIONS, true)) {
    if (strlen((string)($rawBody ?? '')) > MAX_WRITE_BODY_BYTES) {
        temedSeoSendError('Тело запроса превышает лимит 1 МБ', 413, ['error_code' => 'PAYLOAD_TOO_LARGE']);
    }
    authenticateWithToken($config, 'write_token');
} else {
    authenticate($config);
}

if (!Loader::includeModule('iblock')) {
    temedSeoSendError('Модуль iblock недоступен', 500);
}

$methods = getCapabilities()['methods'];
if (!isset($methods[$method]) || !in_array($action, $methods[$method], true)) {
    header('Allow: GET, POST');
    temedSeoSendError('Метод не поддерживается для action', 405, ['allowed_methods' => $methods]);
}

switch ($action) {
    case 'ping':
        sendSuccess([
            'message' => 'TEMED SEO API работает',
            'server_time' => date(DATE_ATOM),
        ]);
        break;

    case 'capabilities':
        sendSuccess(getCapabilities());
        break;

    case 'bootstrap':
        sendSuccess(getBootstrap($config));
        break;

    case 'iblocks':
        $items = temedSeoGetAllIblocks($config);
        sendSuccess($items, ['count' => count($items)]);
        break;

    case 'iblock_properties':
        $rawIblockId = getStringParam('iblock_id');
        $iblockId = preg_match('~^[1-9][0-9]*$~', $rawIblockId) === 1
            ? (int)$rawIblockId
            : 0;

        if ($iblockId <= 0) {
            temedSeoSendError('Не передан корректный параметр iblock_id', 400);
        }

        temedSeoRequireExistingIblock($iblockId);

        $items = temedSeoGetIblockPropertyDefinitions($iblockId);
        sendSuccess($items, ['count' => count($items), 'iblock_id' => $iblockId]);
        break;

    case 'doctors':
        $items = getDoctorList($config);
        sendSuccess($items, ['count' => count($items)]);
        break;

    case 'doctor':
        sendSuccess(getDoctorDetail($config, getIntParam('id')));
        break;

    case 'doctor_properties':
        $items = getPropertyDefinitions(
            getConfiguredIblockId($config, 'doctors'),
            getAllowedDoctorPropertyCodes()
        );
        sendSuccess($items, ['count' => count($items)]);
        break;

    case 'articles':
        $result = getArticleList($config);
        sendSuccess($result['items'], [
            'count' => count($result['items']),
            'total' => $result['total'],
            'offset' => $result['offset'],
            'limit' => $result['limit'],
            'source' => $result['source'],
        ]);
        break;

    case 'article':
        sendSuccess(
            getArticleDetail(
                $config,
                getIntParam('id'),
                getStringParam('source', 'all')
            )
        );
        break;

    case 'article_properties':
        $source = getStringParam('source', 'new');

        if ($source === 'new') {
            $iblockId = getConfiguredIblockId($config, 'articles');
            $allowedCodes = null;
        } elseif ($source === 'legacy') {
            $iblockId = getConfiguredIblockId($config, 'legacy_articles');
            $allowedCodes = null;
        } else {
            temedSeoSendError(
                'Некорректный параметр source',
                400,
                ['allowed_sources' => ['new', 'legacy']]
            );
        }

        $items = getPropertyDefinitions($iblockId, $allowedCodes);
        sendSuccess($items, ['count' => count($items), 'source' => $source]);
        break;

    case 'article_sections':
        $source = getStringParam('source', 'new');

        if ($source === 'new') {
            $iblockId = getConfiguredIblockId($config, 'articles');
        } elseif ($source === 'legacy') {
            $iblockId = getConfiguredIblockId($config, 'legacy_articles');
        } else {
            temedSeoSendError(
                'Некорректный параметр source',
                400,
                ['allowed_sources' => ['new', 'legacy']]
            );
        }

        $items = getSectionsFromIblock($iblockId, $config);
        sendSuccess($items, ['count' => count($items), 'source' => $source]);
        break;

    case 'clinics':
        $result = getGenericIblockList($config, 'clinics', true);
        sendSuccess($result['items'], [
            'count' => count($result['items']),
            'total' => $result['total'],
            'offset' => $result['offset'],
            'limit' => $result['limit'],
        ]);
        break;

    case 'clinic':
        sendSuccess(
            getGenericIblockDetail($config, 'clinics', getIntParam('id'))
        );
        break;

    case 'prices':
    case 'services':
        $result = getGenericIblockList($config, 'prices', true);
        sendSuccess($result['items'], [
            'count' => count($result['items']),
            'total' => $result['total'],
            'offset' => $result['offset'],
            'limit' => $result['limit'],
        ]);
        break;

    case 'price':
    case 'service':
        sendSuccess(
            getGenericIblockDetail($config, 'prices', getIntParam('id'))
        );
        break;

    case 'dictionaries':
        sendSuccess(getDictionaries($config));
        break;

    case 'system_manifest':
        sendSuccess((new SystemManifestService($config))->build());
        break;

    case 'internal_uniqueness':
        try {
            sendSuccess((new InternalUniquenessService($config, new ArticleCorpusRepository($config)))->check($postPayload));
        } catch (InvalidArgumentException $exception) {
            temedSeoSendError($exception->getMessage(), 400);
        }
        break;

    case 'cannibalization_check':
        try {
            $forceRefresh = !empty($postPayload['refresh_corpus']);
            $corpus = (new CorpusCache($config, new ArticleCorpusRepository($config)))->getArticles($forceRefresh);
            sendSuccess((new CannibalizationService($config, $corpus))->check($postPayload));
        } catch (InvalidArgumentException $exception) {
            temedSeoSendError($exception->getMessage(), 400);
        }
        break;

    case 'linking_candidates':
        try {
            $forceRefresh = !empty($postPayload['refresh_corpus']);
            $corpus = (new CorpusCache($config, new ArticleCorpusRepository($config)))->getArticles($forceRefresh);
            sendSuccess((new LinkingService($config, $corpus))->check($postPayload));
        } catch (InvalidArgumentException $exception) {
            temedSeoSendError($exception->getMessage(), 400);
        }
        break;

    case 'create_or_update_draft':
        try {
            $resolver = new BitrixContentReferenceResolver($config);
            $writer = new ArticleDraftWriter(
                $config,
                new BitrixArticleWriteGateway(),
                new HtmlRenderer($resolver),
                $resolver
            );
            $result = $writer->write($postPayload);
            sendSuccess(
                $result['data'],
                ['warnings' => $result['warnings']],
                $result['http_status']
            );
        } catch (DraftWriteException $exception) {
            temedSeoSendError(
                $exception->getMessage(),
                $exception->httpStatus,
                ['error_code' => $exception->errorCode] + $exception->details
            );
        } catch (InvalidArgumentException $exception) {
            temedSeoSendError($exception->getMessage(), 400, ['error_code' => 'INVALID_INPUT']);
        }
        break;

    default:
        temedSeoSendError(
            'Unknown action',
            400,
            ['allowed_actions' => getCapabilities()['actions']]
        );
}
