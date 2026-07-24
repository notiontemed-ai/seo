<?php

declare(strict_types=1);

// Проверка разнесения index.php по модулям (этап 6): модули загружаются без
// окружения Bitrix, чистые функции работают, capabilities целостны.

const API_VERSION = '1.8.0';
const DEFAULT_BASE_URL = 'https://temed.ru';
const DEFAULT_LIST_LIMIT = 500;
const MAX_LIST_LIMIT = 1000;
const WRITE_ACTIONS = ['create_or_update_draft'];
const MAX_WRITE_BODY_BYTES = 1048576;

$lib = __DIR__ . '/../../local/api/seo/lib/';
require $lib . 'ArticleContent.php';
require $lib . 'ContentReferenceResolver.php';
require $lib . 'HtmlRenderer.php';
require $lib . 'ArticleDraftWriter.php';
require $lib . 'ApiResponse.php';
require $lib . 'ApiSupport.php';
require $lib . 'ApiAuth.php';
require $lib . 'ReadActions.php';

function assertTrue(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

// capabilities целостны
$caps = getCapabilities();
assertTrue(in_array('cannibalization_check', $caps['actions'], true), 'caps has cannibalization_check');
assertTrue(in_array('linking_candidates', $caps['actions'], true), 'caps has linking_candidates');
assertTrue(in_array('create_or_update_draft', $caps['methods']['POST'], true), 'POST has write action');
assertTrue(count($caps['write_actions']['create_or_update_draft']['allowed_property_codes']) === 23, 'allowlist 23 codes');

// support helpers
assertTrue(buildAbsoluteUrl('/x', ['base_url' => 'https://temed.ru']) === 'https://temed.ru/x', 'buildAbsoluteUrl relative');
assertTrue(buildAbsoluteUrl('https://a/b', []) === 'https://a/b', 'buildAbsoluteUrl passthrough');
assertTrue(makeSummary('Привет мир', '', 50) === 'Привет мир', 'makeSummary');
assertTrue(isSensitiveProperty('API_TOKEN', '') === true, 'sensitive detect');
assertTrue(isSensitiveProperty('PRIMARY_QUERY', 'Запрос') === false, 'non-sensitive');
assertTrue(count(getAllowedDoctorPropertyCodes()) > 0, 'doctor codes');

// request params
$_GET = ['limit' => '10', 'active' => 'Y'];
assertTrue(getLimit() === 10, 'getLimit');
assertTrue(normalizeActiveFilter('Y') === 'Y', 'normalizeActiveFilter');
assertTrue(getStringParam('active') === 'Y', 'getStringParam');

fwrite(STDOUT, "API modules split: все проверки пройдены\n");
