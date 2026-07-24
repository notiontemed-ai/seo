<?php

declare(strict_types=1);

// Helpers: URL, параметры запроса, текст, свойства. Вынесено (этап 6).
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
