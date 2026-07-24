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

const API_VERSION = '1.7.0';
const DEFAULT_BASE_URL = 'https://temed.ru';
const DEFAULT_LIST_LIMIT = 500;
const MAX_LIST_LIMIT = 1000;

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function sendJson(array $payload, int $statusCode = 200): void
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

function sendSuccess($data, array $meta = [], int $statusCode = 200): void
{
    $payload = [
        'success' => true,
        'api_version' => API_VERSION,
        'data' => $data,
    ];

    if ($meta !== []) {
        $payload['meta'] = $meta;
    }

    sendJson($payload, $statusCode);
}

function temedSeoSendError(string $message, int $statusCode, array $details = []): void
{
    $payload = [
        'success' => false,
        'api_version' => API_VERSION,
        'error' => $message,
    ];

    if (isset($details['error_code'])) {
        $payload['error_code'] = (string)$details['error_code'];
        unset($details['error_code']);
    }

    if ($details !== []) {
        $payload['details'] = $details;
    }

    sendJson($payload, $statusCode);
}

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

function getAuthorizationHeader(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function requireGetMethod(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method !== 'GET') {
        header('Allow: GET');
        temedSeoSendError('Метод не поддерживается', 405, ['allowed_methods' => ['GET']]);
    }
}

function authenticateWithToken(array $config, string $configKey): void
{
    $expectedToken = trim((string)($config[$configKey] ?? ''));
    if ($configKey === 'read_token' && $expectedToken === '' && isset($config['bearer_token'])) {
        error_log('[TEMED SEO API] Deprecated bearer_token config key is used; migrate to read_token.');
        $expectedToken = trim((string)$config['bearer_token']);
    }
    if ($expectedToken === '' || $expectedToken === 'CHANGE_ME') {
        temedSeoSendError('API_NOT_CONFIGURED', 503, ['error_code' => 'API_NOT_CONFIGURED']);
    }
    $authorization = getAuthorizationHeader();

    if (!hash_equals('Bearer ' . $expectedToken, $authorization)) {
        temedSeoSendError('Unauthorized', 401);
    }
}

function authenticate(array $config): void
{
    authenticateWithToken($config, 'read_token');
}

function getConfiguredIblockId(array $config, string $key): int
{
    $iblockId = (int)($config['iblocks'][$key] ?? 0);

    if ($iblockId <= 0) {
        temedSeoSendError(
            'В config.php не указан инфоблок',
            500,
            ['config_key' => $key]
        );
    }

    return $iblockId;
}

function getBaseUrl(array $config): string
{
    $baseUrl = trim((string)($config['base_url'] ?? DEFAULT_BASE_URL));

    return rtrim($baseUrl !== '' ? $baseUrl : DEFAULT_BASE_URL, '/');
}

function buildAbsoluteUrl(?string $url, array $config): string
{
    $url = trim((string)$url);

    if ($url === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }

    return getBaseUrl($config) . '/' . ltrim($url, '/');
}

function getStringParam(string $name, string $default = ''): string
{
    $value = $_GET[$name] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return trim((string)$value);
}

function getIntParam(string $name, int $default = 0): int
{
    $value = $_GET[$name] ?? $default;

    if (is_array($value)) {
        return $default;
    }

    return (int)$value;
}

function getBoolParam(string $name, bool $default = false): bool
{
    if (!array_key_exists($name, $_GET)) {
        return $default;
    }

    $value = strtolower(getStringParam($name));

    return in_array($value, ['1', 'true', 'yes', 'y', 'on'], true);
}

function getLimit(): int
{
    $limit = getIntParam('limit', DEFAULT_LIST_LIMIT);

    if ($limit <= 0) {
        $limit = DEFAULT_LIST_LIMIT;
    }

    return min($limit, MAX_LIST_LIMIT);
}

function getOffset(): int
{
    return max(0, getIntParam('offset', 0));
}

function normalizeActiveFilter(string $value): ?string
{
    $value = strtoupper(trim($value));

    if ($value === 'Y' || $value === 'N') {
        return $value;
    }

    return null;
}

function cleanPlainText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('~<br\s*/?>~iu', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = preg_replace('~[\x{00A0}\s]+~u', ' ', $text) ?? $text;

    return trim($text);
}

function makeSummary(string $previewText, string $detailText, int $length = 500): string
{
    $source = cleanPlainText($previewText);

    if ($source === '') {
        $source = cleanPlainText($detailText);
    }

    if ($source === '') {
        return '';
    }

    if (mb_strlen($source, 'UTF-8') <= $length) {
        return $source;
    }

    $summary = mb_substr($source, 0, $length, 'UTF-8');
    $lastSpace = mb_strrpos($summary, ' ', 0, 'UTF-8');

    if ($lastSpace !== false && $lastSpace > (int)($length * 0.7)) {
        $summary = mb_substr($summary, 0, $lastSpace, 'UTF-8');
    }

    return rtrim($summary, " \t\n\r\0\x0B,.;:-") . '…';
}

function isEmptyPropertyValue($value): bool
{
    return $value === null || $value === '' || $value === false || $value === [];
}

function arrayValueAt($value, int $index)
{
    if (!is_array($value)) {
        return $index === 0 ? $value : null;
    }

    $values = array_values($value);

    return $values[$index] ?? null;
}

function getSensitivePatterns(): array
{
    return [
        'SECRET',
        'TOKEN',
        'PASSWORD',
        'PASSWD',
        'PRIVATE_KEY',
        'PUBLIC_KEY',
        'API_KEY',
        'WEBHOOK',
        'AUTH',
        'LOGIN',
        'TILDA_SECRET',
    ];
}

function isSensitiveProperty(
    string $code,
    string $name
): bool {
    $haystack = mb_strtoupper(
        $code . ' ' . $name,
        'UTF-8'
    );

    foreach (getSensitivePatterns() as $pattern) {
        $pattern = mb_strtoupper(
            $pattern,
            'UTF-8'
        );

        $regexp =
            '~(^|[^A-Z0-9])'
            . preg_quote($pattern, '~')
            . '([^A-Z0-9]|$)~u';

        if (preg_match($regexp, $haystack)) {
            return true;
        }
    }

    return false;
}

function getAllowedDoctorPropertyCodes(): array
{
    return [
        'SHORT_NAME',
        'POSITION',
        'CLINIC',
        'PHOTO',
        'GENDER',
        'EDUCATION',
        'EXPERIENCE',
        'DIAG',
        'METODS',
        'METODS_NEW',
        'TREATS_NEW',
        'PRICE',
        'CONSULT_ONLINE',
        'ONLINE_LINK',
        'DOCTOR_GENITIVE_NAME',
        'SPECIAL1_LP',
        'SPECIAL2_LP',
        'SPECIAL3_LP',
        'CITY_LP',
        'EXPERIENCE_LP',
        'PHOTO_LP',
        'PHOTO2_LP',
    ];
}


function getLinkedElement(int $elementId, array $config): array
{
    static $cache = [];

    if ($elementId <= 0) {
        return [
            'element_id' => 0,
            'name' => '',
            'code' => '',
            'url' => '',
            'absolute_url' => '',
        ];
    }

    if (isset($cache[$elementId])) {
        return $cache[$elementId];
    }

    $result = CIBlockElement::GetList(
        [],
        ['ID' => $elementId],
        false,
        false,
        ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'DETAIL_PAGE_URL', 'ACTIVE']
    );

    $element = $result->GetNext();

    $cache[$elementId] = [
        'element_id' => $elementId,
        'name' => $element ? (string)$element['NAME'] : '',
        'code' => $element ? (string)$element['CODE'] : '',
        'active' => $element ? (string)$element['ACTIVE'] : '',
        'url' => $element ? (string)($element['DETAIL_PAGE_URL'] ?? '') : '',
        'absolute_url' => $element
            ? buildAbsoluteUrl((string)($element['DETAIL_PAGE_URL'] ?? ''), $config)
            : '',
    ];

    return $cache[$elementId];
}

function getLinkedSection(int $sectionId, array $config): array
{
    static $cache = [];

    if ($sectionId <= 0) {
        return [
            'section_id' => 0,
            'name' => '',
            'code' => '',
            'url' => '',
            'absolute_url' => '',
        ];
    }

    if (isset($cache[$sectionId])) {
        return $cache[$sectionId];
    }

    $result = CIBlockSection::GetList(
        [],
        ['ID' => $sectionId],
        false,
        ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'SECTION_PAGE_URL', 'ACTIVE']
    );

    $section = $result->GetNext();

    $cache[$sectionId] = [
        'section_id' => $sectionId,
        'name' => $section ? (string)$section['NAME'] : '',
        'code' => $section ? (string)$section['CODE'] : '',
        'active' => $section ? (string)$section['ACTIVE'] : '',
        'url' => $section ? (string)($section['SECTION_PAGE_URL'] ?? '') : '',
        'absolute_url' => $section
            ? buildAbsoluteUrl((string)($section['SECTION_PAGE_URL'] ?? ''), $config)
            : '',
    ];

    return $cache[$sectionId];
}

function formatPropertyValues(array $property, array $config): array
{
    $rawValue = $property['VALUE'] ?? null;

    if (isEmptyPropertyValue($rawValue)) {
        return [];
    }

    $isMultiple = (string)($property['MULTIPLE'] ?? 'N') === 'Y';
    $rawValues = $isMultiple && is_array($rawValue)
        ? array_values($rawValue)
        : [$rawValue];

    $formatted = [];

    foreach ($rawValues as $index => $value) {
        if (isEmptyPropertyValue($value)) {
            continue;
        }

        $type = (string)($property['PROPERTY_TYPE'] ?? '');
        $formattedValue = $value;

        if ($type === 'F' && is_numeric($value)) {
            $relativeUrl = (string)CFile::GetPath((int)$value);
            $formattedValue = [
                'file_id' => (int)$value,
                'url' => $relativeUrl,
                'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
            ];
        } elseif ($type === 'E' && is_numeric($value)) {
            $formattedValue = getLinkedElement((int)$value, $config);
        } elseif ($type === 'G' && is_numeric($value)) {
            $formattedValue = getLinkedSection((int)$value, $config);
        } elseif ($type === 'L') {
            $formattedValue = [
                'enum_id' => arrayValueAt($property['VALUE_ENUM_ID'] ?? null, $index),
                'value' => (string)arrayValueAt(
                    $property['VALUE_ENUM'] ?? $value,
                    $index
                ),
                'xml_id' => (string)arrayValueAt(
                    $property['VALUE_XML_ID'] ?? '',
                    $index
                ),
            ];
        } elseif (is_array($value) && array_key_exists('TEXT', $value)) {
            $formattedValue = [
                'text' => (string)($value['TEXT'] ?? ''),
                'type' => (string)($value['TYPE'] ?? ''),
            ];
        } elseif (is_scalar($value)) {
            $formattedValue = (string)$value;
        }

        $description = arrayValueAt($property['DESCRIPTION'] ?? '', $index);

        $formatted[] = [
            'value' => $formattedValue,
            'description' => is_scalar($description) ? (string)$description : '',
        ];
    }

    return $formatted;
}

function filterProperties(
    array $properties,
    array $config,
    ?array $allowedCodes = null
): array {
    $result = [];

    foreach ($properties as $property) {
        $code = trim((string)($property['CODE'] ?? ''));
        $name = trim((string)($property['NAME'] ?? ''));

        if ($code === '') {
            continue;
        }

        if ($allowedCodes !== null && !in_array($code, $allowedCodes, true)) {
            continue;
        }

        if (isSensitiveProperty($code, $name)) {
            continue;
        }

        $values = formatPropertyValues($property, $config);

        if ($values === []) {
            continue;
        }

        $result[$code] = [
            'id' => (int)($property['ID'] ?? 0),
            'name' => $name,
            'code' => $code,
            'type' => (string)($property['PROPERTY_TYPE'] ?? ''),
            'user_type' => (string)($property['USER_TYPE'] ?? ''),
            'multiple' => (string)($property['MULTIPLE'] ?? 'N'),
            'values' => $values,
        ];
    }

    return $result;
}

function getSectionData(int $sectionId, array $config): ?array
{
    if ($sectionId <= 0) {
        return null;
    }

    $section = getLinkedSection($sectionId, $config);

    return [
        'id' => $section['section_id'],
        'name' => $section['name'],
        'code' => $section['code'],
        'active' => $section['active'],
        'url' => $section['url'],
        'absolute_url' => $section['absolute_url'],
    ];
}

function getSectionsFromIblock(int $iblockId, array $config): array
{
    $items = [];

    $result = CIBlockSection::GetList(
        ['LEFT_MARGIN' => 'ASC'],
        ['IBLOCK_ID' => $iblockId],
        false,
        [
            'ID',
            'IBLOCK_ID',
            'IBLOCK_SECTION_ID',
            'NAME',
            'CODE',
            'ACTIVE',
            'DEPTH_LEVEL',
            'SECTION_PAGE_URL',
            'SORT',
        ]
    );

    while ($section = $result->GetNext()) {
        $url = (string)($section['SECTION_PAGE_URL'] ?? '');

        $items[] = [
            'id' => (int)$section['ID'],
            'iblock_id' => (int)$section['IBLOCK_ID'],
            'parent_id' => (int)($section['IBLOCK_SECTION_ID'] ?? 0),
            'name' => (string)$section['NAME'],
            'code' => (string)$section['CODE'],
            'active' => (string)$section['ACTIVE'],
            'depth_level' => (int)$section['DEPTH_LEVEL'],
            'sort' => (int)$section['SORT'],
            'url' => $url,
            'absolute_url' => buildAbsoluteUrl($url, $config),
        ];
    }

    return $items;
}

function getPropertyDefinitions(
    int $iblockId,
    ?array $allowedCodes = null,
    bool $includeSensitive = false
): array {
    $items = [];

    $result = CIBlockProperty::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => $iblockId]
    );

    while ($property = $result->Fetch()) {
        $code = trim((string)($property['CODE'] ?? ''));
        $name = trim((string)($property['NAME'] ?? ''));

        if ($code === '') {
            continue;
        }

        if ($allowedCodes !== null && !in_array($code, $allowedCodes, true)) {
            continue;
        }

        if (!$includeSensitive && isSensitiveProperty($code, $name)) {
            continue;
        }

        $items[] = [
            'id' => (int)$property['ID'],
            'name' => $name,
            'code' => $code,
            'type' => (string)$property['PROPERTY_TYPE'],
            'user_type' => (string)($property['USER_TYPE'] ?? ''),
            'multiple' => (string)$property['MULTIPLE'],
            'required' => (string)$property['IS_REQUIRED'],
            'active' => (string)$property['ACTIVE'],
            'sort' => (int)$property['SORT'],
            'linked_iblock_id' => !empty($property['LINK_IBLOCK_ID'])
                ? (int)$property['LINK_IBLOCK_ID']
                : null,
        ];
    }

    return $items;
}

function getPropertyEnumValues(int $iblockId, string $propertyCode): array
{
    $propertyResult = CIBlockProperty::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
    );

    $property = $propertyResult->Fetch();

    if (!$property || (string)$property['PROPERTY_TYPE'] !== 'L') {
        return [];
    }

    $items = [];
    $enumResult = CIBlockPropertyEnum::GetList(
        ['SORT' => 'ASC', 'VALUE' => 'ASC'],
        ['PROPERTY_ID' => (int)$property['ID']]
    );

    while ($enum = $enumResult->Fetch()) {
        $items[] = [
            'id' => (int)$enum['ID'],
            'value' => (string)$enum['VALUE'],
            'xml_id' => (string)$enum['XML_ID'],
            'sort' => (int)$enum['SORT'],
            'default' => (string)$enum['DEF'],
        ];
    }

    return $items;
}


function temedSeoGetIblockSites(int $iblockId): array
{
    $sites = [];
    $result = CIBlock::GetSite($iblockId);

    while ($site = $result->Fetch()) {
        $siteId = trim((string)($site['LID'] ?? ''));

        if ($siteId !== '') {
            $sites[] = $siteId;
        }
    }

    return $sites;
}

function temedSeoGetIblockTypeName(string $typeId): string
{
    static $cache = [];

    if ($typeId === '') {
        return '';
    }

    if (array_key_exists($typeId, $cache)) {
        return $cache[$typeId];
    }

    $languageId = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
    $type = CIBlockType::GetByIDLang($typeId, $languageId);

    $cache[$typeId] = is_array($type) ? (string)($type['NAME'] ?? '') : '';

    return $cache[$typeId];
}

function temedSeoGetAllIblocks(array $config): array
{
    $items = [];
    $result = CIBlock::GetList(
        ['IBLOCK_TYPE_ID' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
        []
    );

    while ($iblock = $result->GetNext()) {
        $iblockId = (int)($iblock['ID'] ?? 0);
        $typeId = (string)($iblock['IBLOCK_TYPE_ID'] ?? '');

        $items[] = [
            'id' => $iblockId,
            'name' => (string)($iblock['NAME'] ?? ''),
            'code' => (string)($iblock['CODE'] ?? ''),
            'type_id' => $typeId,
            'type_name' => temedSeoGetIblockTypeName($typeId),
            'active' => (string)($iblock['ACTIVE'] ?? ''),
            'sort' => (int)($iblock['SORT'] ?? 0),
            'sites' => $iblockId > 0 ? temedSeoGetIblockSites($iblockId) : [],
            'list_page_url' => (string)($iblock['LIST_PAGE_URL'] ?? ''),
            'section_page_url' => (string)($iblock['SECTION_PAGE_URL'] ?? ''),
            'detail_page_url' => (string)($iblock['DETAIL_PAGE_URL'] ?? ''),
            'created_at' => (string)($iblock['DATE_CREATE'] ?? ''),
            'updated_at' => (string)($iblock['TIMESTAMP_X'] ?? ''),
        ];
    }

    return $items;
}

function temedSeoStringifyPropertyDefaultValue($value): string
{
    if ($value === null || is_array($value) || is_object($value)) {
        return '';
    }

    return (string)$value;
}

function temedSeoNormalizePropertySettings($settings)
{
    if (is_array($settings) || is_object($settings)) {
        return $settings;
    }

    if (!is_string($settings) || trim($settings) === '') {
        return [];
    }

    $serialized = trim($settings);
    $unserialized = @unserialize($serialized, ['allowed_classes' => false]);

    if ($unserialized !== false || $serialized === 'b:0;') {
        return is_array($unserialized) || is_object($unserialized)
            ? $unserialized
            : $serialized;
    }

    return $serialized;
}

function temedSeoGetPropertyEnumDefinitions(int $propertyId): array
{
    $items = [];
    $result = CIBlockPropertyEnum::GetList(
        ['SORT' => 'ASC', 'VALUE' => 'ASC', 'ID' => 'ASC'],
        ['PROPERTY_ID' => $propertyId]
    );

    while ($enum = $result->Fetch()) {
        $items[] = [
            'id' => (int)($enum['ID'] ?? 0),
            'property_id' => (int)($enum['PROPERTY_ID'] ?? $propertyId),
            'value' => (string)($enum['VALUE'] ?? ''),
            'xml_id' => (string)($enum['XML_ID'] ?? ''),
            'sort' => (int)($enum['SORT'] ?? 0),
            'default' => (string)($enum['DEF'] ?? 'N'),
        ];
    }

    return $items;
}

function temedSeoGetIblockPropertyDefinitions(int $iblockId): array
{
    $items = [];
    $result = CIBlockProperty::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
        ['IBLOCK_ID' => $iblockId]
    );

    while ($property = $result->Fetch()) {
        $code = trim((string)($property['CODE'] ?? ''));
        $name = trim((string)($property['NAME'] ?? ''));

        if (isSensitiveProperty($code, $name)) {
            continue;
        }

        $propertyId = (int)($property['ID'] ?? 0);
        $type = (string)($property['PROPERTY_TYPE'] ?? '');

        $items[] = [
            'id' => $propertyId,
            'iblock_id' => (int)($property['IBLOCK_ID'] ?? $iblockId),
            'name' => $name,
            'code' => $code,
            'type' => $type,
            'user_type' => (string)($property['USER_TYPE'] ?? ''),
            'multiple' => (string)($property['MULTIPLE'] ?? 'N'),
            'required' => (string)($property['IS_REQUIRED'] ?? 'N'),
            'active' => (string)($property['ACTIVE'] ?? 'Y'),
            'sort' => (int)($property['SORT'] ?? 0),
            'linked_iblock_id' => !empty($property['LINK_IBLOCK_ID'])
                ? (int)$property['LINK_IBLOCK_ID']
                : null,
            'default_value' => temedSeoStringifyPropertyDefaultValue($property['DEFAULT_VALUE'] ?? ''),
            'with_description' => (string)($property['WITH_DESCRIPTION'] ?? 'N'),
            'searchable' => (string)($property['SEARCHABLE'] ?? 'N'),
            'filtrable' => (string)($property['FILTRABLE'] ?? 'N'),
            'xml_id' => (string)($property['XML_ID'] ?? ''),
            'list_type' => (string)($property['LIST_TYPE'] ?? ''),
            'row_count' => (int)($property['ROW_COUNT'] ?? 0),
            'col_count' => (int)($property['COL_COUNT'] ?? 0),
            'settings' => temedSeoNormalizePropertySettings($property['USER_TYPE_SETTINGS'] ?? []),
            'enum_values' => $type === 'L' && $propertyId > 0
                ? temedSeoGetPropertyEnumDefinitions($propertyId)
                : [],
        ];
    }

    return $items;
}

function temedSeoRequireExistingIblock(int $iblockId): void
{
    $result = CIBlock::GetByID($iblockId);

    if (!$result->Fetch()) {
        temedSeoSendError(
            'Инфоблок не найден',
            404,
            ['iblock_id' => $iblockId]
        );
    }
}

function getDoctorList(array $config): array
{
    $iblockId = getConfiguredIblockId($config, 'doctors');
    $activeFilter = normalizeActiveFilter(getStringParam('active', 'Y'));
    $query = getStringParam('q');
    $limit = getLimit();
    $offset = getOffset();

    $filter = ['IBLOCK_ID' => $iblockId];

    if ($activeFilter !== null) {
        $filter['ACTIVE'] = $activeFilter;
    }

    if ($query !== '') {
        $filter[] = [
            'LOGIC' => 'OR',
            ['%NAME' => $query],
            ['%CODE' => $query],
        ];
    }

    $items = [];
    $result = CIBlockElement::GetList(
        ['NAME' => 'ASC', 'ID' => 'ASC'],
        $filter,
        false,
        ['nPageSize' => $limit, 'iNumPage' => (int)floor($offset / $limit) + 1],
        [
            'ID',
            'IBLOCK_ID',
            'NAME',
            'CODE',
            'ACTIVE',
            'DETAIL_PAGE_URL',
            'PREVIEW_TEXT',
            'DETAIL_TEXT',
            'DATE_CREATE',
            'TIMESTAMP_X',
        ]
    );

    while ($element = $result->GetNextElement()) {
        $fields = $element->GetFields();
        $properties = filterProperties(
            $element->GetProperties(),
            $config,
            getAllowedDoctorPropertyCodes()
        );
        $url = (string)($fields['DETAIL_PAGE_URL'] ?? '');
        $previewText = (string)($fields['PREVIEW_TEXT'] ?? '');
        $detailText = (string)($fields['DETAIL_TEXT'] ?? '');

        $items[] = [
            'id' => (int)$fields['ID'],
            'iblock_id' => (int)$fields['IBLOCK_ID'],
            'name' => (string)$fields['NAME'],
            'code' => (string)$fields['CODE'],
            'active' => (string)$fields['ACTIVE'],
            'url' => $url,
            'absolute_url' => buildAbsoluteUrl($url, $config),
            'summary' => makeSummary($previewText, $detailText, 350),
            'created_at' => (string)($fields['DATE_CREATE'] ?? ''),
            'updated_at' => (string)($fields['TIMESTAMP_X'] ?? ''),
            'properties' => $properties,
        ];
    }

    return $items;
}

function getDoctorDetail(array $config, int $doctorId): array
{
    $iblockId = getConfiguredIblockId($config, 'doctors');

    if ($doctorId <= 0) {
        temedSeoSendError('Не передан корректный параметр id', 400);
    }

    $result = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'ID' => $doctorId],
        false,
        false,
        [
            'ID',
            'IBLOCK_ID',
            'NAME',
            'CODE',
            'ACTIVE',
            'DETAIL_PAGE_URL',
            'PREVIEW_TEXT',
            'PREVIEW_TEXT_TYPE',
            'DETAIL_TEXT',
            'DETAIL_TEXT_TYPE',
            'PREVIEW_PICTURE',
            'DETAIL_PICTURE',
            'DATE_CREATE',
            'TIMESTAMP_X',
        ]
    );

    $element = $result->GetNextElement();

    if (!$element) {
        temedSeoSendError('Врач не найден', 404);
    }

    $fields = $element->GetFields();
    $url = (string)($fields['DETAIL_PAGE_URL'] ?? '');

    $previewPicture = null;
    if (!empty($fields['PREVIEW_PICTURE'])) {
        $relativeUrl = (string)CFile::GetPath((int)$fields['PREVIEW_PICTURE']);
        $previewPicture = [
            'file_id' => (int)$fields['PREVIEW_PICTURE'],
            'url' => $relativeUrl,
            'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
        ];
    }

    $detailPicture = null;
    if (!empty($fields['DETAIL_PICTURE'])) {
        $relativeUrl = (string)CFile::GetPath((int)$fields['DETAIL_PICTURE']);
        $detailPicture = [
            'file_id' => (int)$fields['DETAIL_PICTURE'],
            'url' => $relativeUrl,
            'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
        ];
    }

    return [
        'id' => (int)$fields['ID'],
        'iblock_id' => (int)$fields['IBLOCK_ID'],
        'name' => (string)$fields['NAME'],
        'code' => (string)$fields['CODE'],
        'active' => (string)$fields['ACTIVE'],
        'url' => $url,
        'absolute_url' => buildAbsoluteUrl($url, $config),
        'preview_text' => (string)($fields['PREVIEW_TEXT'] ?? ''),
        'preview_text_type' => (string)($fields['PREVIEW_TEXT_TYPE'] ?? ''),
        'detail_text' => (string)($fields['DETAIL_TEXT'] ?? ''),
        'detail_text_type' => (string)($fields['DETAIL_TEXT_TYPE'] ?? ''),
        'summary' => makeSummary(
            (string)($fields['PREVIEW_TEXT'] ?? ''),
            (string)($fields['DETAIL_TEXT'] ?? ''),
            500
        ),
        'preview_picture' => $previewPicture,
        'detail_picture' => $detailPicture,
        'created_at' => (string)($fields['DATE_CREATE'] ?? ''),
        'updated_at' => (string)($fields['TIMESTAMP_X'] ?? ''),
        'properties' => filterProperties(
            $element->GetProperties(),
            $config,
            getAllowedDoctorPropertyCodes()
        ),
    ];
}

function resolveArticleSource(array $config, string $source): array
{
    if ($source === 'new') {
        return [[
            'source' => 'new',
            'iblock_id' => getConfiguredIblockId($config, 'articles'),
        ]];
    }

    if ($source === 'legacy') {
        return [[
            'source' => 'legacy',
            'iblock_id' => getConfiguredIblockId($config, 'legacy_articles'),
        ]];
    }

    if ($source === 'all') {
        return [
            [
                'source' => 'new',
                'iblock_id' => getConfiguredIblockId($config, 'articles'),
            ],
            [
                'source' => 'legacy',
                'iblock_id' => getConfiguredIblockId($config, 'legacy_articles'),
            ],
        ];
    }

    temedSeoSendError(
        'Некорректный параметр source',
        400,
        ['allowed_sources' => ['new', 'legacy', 'all']]
    );

    return [];
}

function getArticleListFromIblock(
    int $iblockId,
    string $source,
    array $config
): array {
    $activeFilter = normalizeActiveFilter(getStringParam('active', ''));
    $query = getStringParam('q');
    $sectionId = getIntParam('section_id', 0);

    $filter = ['IBLOCK_ID' => $iblockId];

    if ($activeFilter !== null) {
        $filter['ACTIVE'] = $activeFilter;
    }

    if ($sectionId > 0) {
        $filter['SECTION_ID'] = $sectionId;
        $filter['INCLUDE_SUBSECTIONS'] = 'Y';
    }

    if ($query !== '') {
        $filter[] = [
            'LOGIC' => 'OR',
            ['%NAME' => $query],
            ['%CODE' => $query],
            ['%PREVIEW_TEXT' => $query],
            ['%DETAIL_TEXT' => $query],
        ];
    }

    $items = [];
    $result = CIBlockElement::GetList(
        ['TIMESTAMP_X' => 'DESC', 'ID' => 'DESC'],
        $filter,
        false,
        false,
        [
            'ID',
            'IBLOCK_ID',
            'IBLOCK_SECTION_ID',
            'NAME',
            'CODE',
            'ACTIVE',
            'DETAIL_PAGE_URL',
            'PREVIEW_TEXT',
            'PREVIEW_TEXT_TYPE',
            'DETAIL_TEXT',
            'DETAIL_TEXT_TYPE',
            'PREVIEW_PICTURE',
            'DATE_CREATE',
            'TIMESTAMP_X',
        ]
    );

    while ($article = $result->GetNext()) {
        $url = (string)($article['DETAIL_PAGE_URL'] ?? '');
        $previewText = (string)(
            $article['~PREVIEW_TEXT']
            ?? $article['PREVIEW_TEXT']
            ?? ''
        );
        $detailText = (string)(
            $article['~DETAIL_TEXT']
            ?? $article['DETAIL_TEXT']
            ?? ''
        );

        $previewPicture = null;
        if (!empty($article['PREVIEW_PICTURE'])) {
            $relativeUrl = (string)CFile::GetPath((int)$article['PREVIEW_PICTURE']);
            $previewPicture = [
                'file_id' => (int)$article['PREVIEW_PICTURE'],
                'url' => $relativeUrl,
                'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
            ];
        }

        $items[] = [
            'id' => (int)$article['ID'],
            'iblock_id' => (int)$article['IBLOCK_ID'],
            'source' => $source,
            'name' => (string)$article['NAME'],
            'code' => (string)$article['CODE'],
            'active' => (string)$article['ACTIVE'],
            'url' => $url,
            'absolute_url' => buildAbsoluteUrl($url, $config),
            'section' => getSectionData(
                (int)($article['IBLOCK_SECTION_ID'] ?? 0),
                $config
            ),
            'preview_text' => $previewText,
            'preview_text_type' => (string)($article['PREVIEW_TEXT_TYPE'] ?? ''),
            'summary' => makeSummary($previewText, $detailText, 500),
            'preview_picture' => $previewPicture,
            'created_at' => (string)($article['DATE_CREATE'] ?? ''),
            'updated_at' => (string)($article['TIMESTAMP_X'] ?? ''),
        ];
    }

    return $items;
}

function getArticleList(array $config): array
{
    $source = getStringParam('source', 'all');
    $sources = resolveArticleSource($config, $source);
    $items = [];

    foreach ($sources as $sourceInfo) {
        $items = array_merge(
            $items,
            getArticleListFromIblock(
                (int)$sourceInfo['iblock_id'],
                (string)$sourceInfo['source'],
                $config
            )
        );
    }

    usort(
        $items,
        static function (array $a, array $b): int {
            return strcmp((string)$b['updated_at'], (string)$a['updated_at']);
        }
    );

    $offset = getOffset();
    $limit = getLimit();

    return [
        'items' => array_slice($items, $offset, $limit),
        'total' => count($items),
        'offset' => $offset,
        'limit' => $limit,
        'source' => $source,
    ];
}

function findArticleSourceById(array $config, int $articleId, string $requestedSource): array
{
    $sources = resolveArticleSource($config, $requestedSource);

    foreach ($sources as $sourceInfo) {
        $result = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => (int)$sourceInfo['iblock_id'],
                'ID' => $articleId,
            ],
            false,
            false,
            ['ID']
        );

        if ($result->Fetch()) {
            return $sourceInfo;
        }
    }

    temedSeoSendError('Статья не найдена', 404);

    return [];
}

function getArticleDetail(array $config, int $articleId, string $source): array
{
    if ($articleId <= 0) {
        temedSeoSendError('Не передан корректный параметр id', 400);
    }

    $sourceInfo = findArticleSourceById($config, $articleId, $source);
    $iblockId = (int)$sourceInfo['iblock_id'];
    $resolvedSource = (string)$sourceInfo['source'];

    $result = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'ID' => $articleId],
        false,
        false,
        [
            'ID',
            'IBLOCK_ID',
            'IBLOCK_SECTION_ID',
            'NAME',
            'CODE',
            'XML_ID',
            'ACTIVE',
            'SORT',
            'DETAIL_PAGE_URL',
            'PREVIEW_TEXT',
            'PREVIEW_TEXT_TYPE',
            'DETAIL_TEXT',
            'DETAIL_TEXT_TYPE',
            'PREVIEW_PICTURE',
            'DETAIL_PICTURE',
            'DATE_CREATE',
            'TIMESTAMP_X',
            'ACTIVE_FROM',
            'ACTIVE_TO',
        ]
    );

    $element = $result->GetNextElement();

    if (!$element) {
        temedSeoSendError('Статья не найдена', 404);
    }

    $fields = $element->GetFields();
    $url = (string)($fields['DETAIL_PAGE_URL'] ?? '');

    $previewPicture = null;
    if (!empty($fields['PREVIEW_PICTURE'])) {
        $relativeUrl = (string)CFile::GetPath((int)$fields['PREVIEW_PICTURE']);
        $previewPicture = [
            'file_id' => (int)$fields['PREVIEW_PICTURE'],
            'url' => $relativeUrl,
            'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
        ];
    }

    $detailPicture = null;
    if (!empty($fields['DETAIL_PICTURE'])) {
        $relativeUrl = (string)CFile::GetPath((int)$fields['DETAIL_PICTURE']);
        $detailPicture = [
            'file_id' => (int)$fields['DETAIL_PICTURE'],
            'url' => $relativeUrl,
            'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
        ];
    }

    $allowedCodes = null;

    $previewText = (string)($fields['PREVIEW_TEXT'] ?? '');
    $detailText = (string)($fields['DETAIL_TEXT'] ?? '');

    return [
        'id' => (int)$fields['ID'],
        'iblock_id' => (int)$fields['IBLOCK_ID'],
        'source' => $resolvedSource,
        'name' => (string)$fields['NAME'],
        'code' => (string)$fields['CODE'],
        'xml_id' => (string)($fields['XML_ID'] ?? ''),
        'active' => (string)$fields['ACTIVE'],
        'sort' => (int)($fields['SORT'] ?? 500),
        'url' => $url,
        'absolute_url' => buildAbsoluteUrl($url, $config),
        'section' => getSectionData(
            (int)($fields['IBLOCK_SECTION_ID'] ?? 0),
            $config
        ),
        'preview_text' => $previewText,
        'preview_text_type' => (string)($fields['PREVIEW_TEXT_TYPE'] ?? ''),
        'detail_text' => $detailText,
        'detail_text_type' => (string)($fields['DETAIL_TEXT_TYPE'] ?? ''),
        // Обратная совместимость: HTML → блоки article_content v2 для редактора.
        'article_content' => (new HtmlToBlocksParser())->parse($detailText),
        'summary' => makeSummary($previewText, $detailText, 500),
        'preview_picture' => $previewPicture,
        'detail_picture' => $detailPicture,
        'active_from' => (string)($fields['ACTIVE_FROM'] ?? ''),
        'active_to' => (string)($fields['ACTIVE_TO'] ?? ''),
        'created_at' => (string)($fields['DATE_CREATE'] ?? ''),
        'updated_at' => (string)($fields['TIMESTAMP_X'] ?? ''),
        'properties' => filterProperties(
            $element->GetProperties(),
            $config,
            $allowedCodes
        ),
    ];
}

function getGenericIblockList(
    array $config,
    string $configKey,
    bool $includeProperties = true
): array {
    $iblockId = getConfiguredIblockId($config, $configKey);
    $activeFilter = normalizeActiveFilter(getStringParam('active', 'Y'));
    $query = getStringParam('q');
    $sectionId = getIntParam('section_id', 0);
    $limit = getLimit();
    $offset = getOffset();

    $filter = ['IBLOCK_ID' => $iblockId];

    if ($activeFilter !== null) {
        $filter['ACTIVE'] = $activeFilter;
    }

    if ($sectionId > 0) {
        $filter['SECTION_ID'] = $sectionId;
        $filter['INCLUDE_SUBSECTIONS'] = 'Y';
    }

    if ($query !== '') {
        $filter[] = [
            'LOGIC' => 'OR',
            ['%NAME' => $query],
            ['%CODE' => $query],
            ['%PREVIEW_TEXT' => $query],
            ['%DETAIL_TEXT' => $query],
        ];
    }

    $allItems = [];
    $result = CIBlockElement::GetList(
        ['NAME' => 'ASC', 'ID' => 'ASC'],
        $filter,
        false,
        false,
        [
            'ID',
            'IBLOCK_ID',
            'IBLOCK_SECTION_ID',
            'NAME',
            'CODE',
            'XML_ID',
            'ACTIVE',
            'SORT',
            'DETAIL_PAGE_URL',
            'PREVIEW_TEXT',
            'DETAIL_TEXT',
            'PREVIEW_PICTURE',
            'DATE_CREATE',
            'TIMESTAMP_X',
        ]
    );

    while ($element = $result->GetNextElement()) {
        $fields = $element->GetFields();
        $url = (string)($fields['DETAIL_PAGE_URL'] ?? '');
        $previewText = (string)($fields['PREVIEW_TEXT'] ?? '');
        $detailText = (string)($fields['DETAIL_TEXT'] ?? '');

        $previewPicture = null;
        if (!empty($fields['PREVIEW_PICTURE'])) {
            $relativeUrl = (string)CFile::GetPath((int)$fields['PREVIEW_PICTURE']);
            $previewPicture = [
                'file_id' => (int)$fields['PREVIEW_PICTURE'],
                'url' => $relativeUrl,
                'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
            ];
        }

        $item = [
            'id' => (int)$fields['ID'],
            'iblock_id' => (int)$fields['IBLOCK_ID'],
            'section' => getSectionData(
                (int)($fields['IBLOCK_SECTION_ID'] ?? 0),
                $config
            ),
            'name' => (string)$fields['NAME'],
            'code' => (string)$fields['CODE'],
            'xml_id' => (string)($fields['XML_ID'] ?? ''),
            'active' => (string)$fields['ACTIVE'],
            'sort' => (int)($fields['SORT'] ?? 500),
            'url' => $url,
            'absolute_url' => buildAbsoluteUrl($url, $config),
            'summary' => makeSummary($previewText, $detailText, 400),
            'preview_picture' => $previewPicture,
            'created_at' => (string)($fields['DATE_CREATE'] ?? ''),
            'updated_at' => (string)($fields['TIMESTAMP_X'] ?? ''),
        ];

        if ($includeProperties) {
            $item['properties'] = filterProperties(
                $element->GetProperties(),
                $config,
                null
            );
        }

        $allItems[] = $item;
    }

    return [
        'items' => array_slice($allItems, $offset, $limit),
        'total' => count($allItems),
        'offset' => $offset,
        'limit' => $limit,
        'iblock_id' => $iblockId,
    ];
}

function getGenericIblockDetail(
    array $config,
    string $configKey,
    int $elementId
): array {
    if ($elementId <= 0) {
        temedSeoSendError('Не передан корректный параметр id', 400);
    }

    $iblockId = getConfiguredIblockId($config, $configKey);

    $result = CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => $iblockId, 'ID' => $elementId],
        false,
        false,
        [
            'ID',
            'IBLOCK_ID',
            'IBLOCK_SECTION_ID',
            'NAME',
            'CODE',
            'XML_ID',
            'ACTIVE',
            'SORT',
            'DETAIL_PAGE_URL',
            'PREVIEW_TEXT',
            'PREVIEW_TEXT_TYPE',
            'DETAIL_TEXT',
            'DETAIL_TEXT_TYPE',
            'PREVIEW_PICTURE',
            'DETAIL_PICTURE',
            'DATE_CREATE',
            'TIMESTAMP_X',
        ]
    );

    $element = $result->GetNextElement();

    if (!$element) {
        temedSeoSendError('Элемент не найден', 404);
    }

    $fields = $element->GetFields();
    $url = (string)($fields['DETAIL_PAGE_URL'] ?? '');
    $previewText = (string)($fields['PREVIEW_TEXT'] ?? '');
    $detailText = (string)($fields['DETAIL_TEXT'] ?? '');

    $previewPicture = null;
    if (!empty($fields['PREVIEW_PICTURE'])) {
        $relativeUrl = (string)CFile::GetPath((int)$fields['PREVIEW_PICTURE']);
        $previewPicture = [
            'file_id' => (int)$fields['PREVIEW_PICTURE'],
            'url' => $relativeUrl,
            'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
        ];
    }

    $detailPicture = null;
    if (!empty($fields['DETAIL_PICTURE'])) {
        $relativeUrl = (string)CFile::GetPath((int)$fields['DETAIL_PICTURE']);
        $detailPicture = [
            'file_id' => (int)$fields['DETAIL_PICTURE'],
            'url' => $relativeUrl,
            'absolute_url' => buildAbsoluteUrl($relativeUrl, $config),
        ];
    }

    return [
        'id' => (int)$fields['ID'],
        'iblock_id' => (int)$fields['IBLOCK_ID'],
        'section' => getSectionData(
            (int)($fields['IBLOCK_SECTION_ID'] ?? 0),
            $config
        ),
        'name' => (string)$fields['NAME'],
        'code' => (string)$fields['CODE'],
        'xml_id' => (string)($fields['XML_ID'] ?? ''),
        'active' => (string)$fields['ACTIVE'],
        'sort' => (int)($fields['SORT'] ?? 500),
        'url' => $url,
        'absolute_url' => buildAbsoluteUrl($url, $config),
        'preview_text' => $previewText,
        'preview_text_type' => (string)($fields['PREVIEW_TEXT_TYPE'] ?? ''),
        'detail_text' => $detailText,
        'detail_text_type' => (string)($fields['DETAIL_TEXT_TYPE'] ?? ''),
        'summary' => makeSummary($previewText, $detailText, 500),
        'preview_picture' => $previewPicture,
        'detail_picture' => $detailPicture,
        'created_at' => (string)($fields['DATE_CREATE'] ?? ''),
        'updated_at' => (string)($fields['TIMESTAMP_X'] ?? ''),
        'properties' => filterProperties(
            $element->GetProperties(),
            $config,
            null
        ),
    ];
}

function getDictionaries(array $config): array
{
    $newArticlesIblockId = getConfiguredIblockId($config, 'articles');
    $legacyArticlesIblockId = getConfiguredIblockId($config, 'legacy_articles');
    $doctorsIblockId = getConfiguredIblockId($config, 'doctors');
    $clinicsIblockId = getConfiguredIblockId($config, 'clinics');
    $pricesIblockId = getConfiguredIblockId($config, 'prices');

    return [
        'article_types' => getPropertyEnumValues($newArticlesIblockId, 'ARTICLE_TYPE'),
        'search_intents' => getPropertyEnumValues($newArticlesIblockId, 'SEARCH_INTENT'),
        'regions' => getPropertyEnumValues($newArticlesIblockId, 'REGION'),
        'article_templates' => getPropertyEnumValues($newArticlesIblockId, 'ARTICLE_TEMPLATE'),
        'article_sections' => [
            'new' => getSectionsFromIblock($newArticlesIblockId, $config),
            'legacy' => getSectionsFromIblock($legacyArticlesIblockId, $config),
        ],
        'property_definitions' => [
            'new_articles' => getPropertyDefinitions($newArticlesIblockId),
            'legacy_articles' => getPropertyDefinitions($legacyArticlesIblockId),
            'doctors' => getPropertyDefinitions(
                $doctorsIblockId,
                getAllowedDoctorPropertyCodes()
            ),
            'clinics' => getPropertyDefinitions($clinicsIblockId),
            'prices' => getPropertyDefinitions($pricesIblockId),
        ],
        'html_blocks' => $config['html_blocks'] ?? [
            'short_answer',
            'expert_opinion',
            'important',
            'info',
            'clinical_case',
            'checklist',
            'table',
            'quote',
            'sources',
            'related_articles',
            'service_card',
            'cta',
        ],
        'forms' => is_array($config['forms'] ?? null)
            ? $config['forms']
            : [],
        // Библиотека смысловых блоков article_content v2 для карточек
        // «Добавить блок» в редакторе.
        'content_blocks' => ArticleContent::catalog(),
    ];
}


function temedSeoLoadArticleStructures(): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    $filePath = __DIR__ . '/data/article_structures.json';

    if (!is_file($filePath) || !is_readable($filePath)) {
        temedSeoSendError(
            'Файл конфигурации структур статей недоступен',
            500
        );
    }

    $json = file_get_contents($filePath);

    if ($json === false || trim($json) === '') {
        temedSeoSendError(
            'Файл конфигурации структур статей пуст или не читается',
            500
        );
    }

    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        error_log(
            '[TEMED SEO API] Ошибка JSON article_structures.json: '
            . json_last_error_msg()
        );

        temedSeoSendError(
            'Некорректный JSON конфигурации структур статей',
            500
        );
    }

    if (
        !array_key_exists('configs', $data)
        || !is_array($data['configs'])
    ) {
        temedSeoSendError(
            'В конфигурации структур отсутствует массив configs',
            500
        );
    }

    $requiredFields = ['id', 'version', 'intent', 'name'];
    $seenIds = [];

    foreach ($data['configs'] as $index => $structure) {
        if (!is_array($structure)) {
            temedSeoSendError(
                'Некорректная структура в конфигурации',
                500,
                ['config_index' => $index]
            );
        }

        foreach ($requiredFields as $field) {
            if (
                !array_key_exists($field, $structure)
                || trim((string)$structure[$field]) === ''
            ) {
                temedSeoSendError(
                    'В структуре отсутствует обязательное поле',
                    500,
                    [
                        'config_index' => $index,
                        'field' => $field,
                    ]
                );
            }
        }

        $structureId = trim((string)$structure['id']);

        if (isset($seenIds[$structureId])) {
            temedSeoSendError(
                'В конфигурации обнаружен повторяющийся id структуры',
                500,
                ['structure_id' => $structureId]
            );
        }

        $seenIds[$structureId] = true;
    }

    $cache = $data;

    return $cache;
}

function getCapabilities(): array
{
    return [
        'read_only' => false,
        'actions' => [
            'ping',
            'capabilities',
            'bootstrap',
            'iblocks',
            'iblock_properties',
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
            'internal_uniqueness',
            'cannibalization_check',
            'linking_candidates',
            'create_or_update_draft',
        ],
        'article_sources' => ['new', 'legacy', 'all'],
        'list_parameters' => [
            'q',
            'active',
            'section_id',
            'limit',
            'offset',
        ],
        'methods' => [
            'GET' => ['ping','capabilities','bootstrap','iblocks','iblock_properties','doctors','doctor','doctor_properties','articles','article','article_properties','article_sections','article_structures','clinics','clinic','prices','price','services','service','dictionaries','system_manifest'],
            'POST' => ['internal_uniqueness', 'cannibalization_check', 'linking_candidates', 'create_or_update_draft'],
        ],
        'write_actions' => [
            'create_or_update_draft' => [
                'auth' => 'write_token',
                'target_iblock' => 'articles',
                'active' => 'N',
                'allowed_property_codes' => ArticleDraftWriter::allowedPropertyCodes(),
            ],
        ],
        'security' => [
            'authentication' => 'Bearer token',
            'read_token' => 'read actions',
            'write_token' => 'write actions',
            'sensitive_property_filtering' => true,
            'doctor_property_allowlist' => true,
            'write_actions' => true,
        ],
    ];
}

function getBootstrap(array $config): array
{
    return [
        'capabilities' => getCapabilities(),
        'dictionaries' => getDictionaries($config),
        'doctors' => getDoctorList($config),
        'articles' => getArticleList($config),
        'clinics' => getGenericIblockList($config, 'clinics', true),
        'prices' => getGenericIblockList($config, 'prices', true),
    ];
}

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

    case 'article_structures':
        $structures = temedSeoLoadArticleStructures();
        sendSuccess(
            $structures,
            ['count' => count($structures['configs'])]
        );
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
