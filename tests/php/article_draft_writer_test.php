<?php

declare(strict_types=1);

require_once __DIR__ . '/../../local/api/seo/lib/ArticleDraftWriter.php';

function assertTrue(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

/**
 * In-memory реализация шлюза для тестов без окружения Bitrix.
 * Записывает все вызовы, чтобы можно было проверить, что именно ушло в Bitrix.
 */
final class FakeWriteGateway implements ArticleWriteGateway
{
    /** @var array<string,array{id:int,active:string,name:string}> code => element */
    public array $elements = [];
    /** @var array<int,int> существующие разделы */
    public array $sections = [432];
    /** @var array<string,array<string,int>> "iblock|code" => enum map */
    public array $enums = [];
    public array $added = [];
    public array $updated = [];
    public array $props = [];
    private int $nextId = 1000;

    public function findElementByCode(int $iblockId, string $code): ?array
    {
        return $this->elements[$code] ?? null;
    }

    public function sectionExists(int $iblockId, int $sectionId): bool
    {
        return in_array($sectionId, $this->sections, true);
    }

    public function getEnumMap(int $iblockId, string $propertyCode): array
    {
        return $this->enums[$iblockId . '|' . $propertyCode] ?? [];
    }

    public function getIblockType(int $iblockId): string
    {
        return 'articles';
    }

    public function addElement(array $fields): int
    {
        $id = $this->nextId++;
        $this->added[] = ['id' => $id, 'fields' => $fields];
        return $id;
    }

    public function updateElement(int $id, array $fields): void
    {
        $this->updated[] = ['id' => $id, 'fields' => $fields];
    }

    public function setPropertyValues(int $iblockId, int $id, array $props): void
    {
        $this->props[] = ['id' => $id, 'props' => $props];
    }
}

function makeConfig(): array
{
    return [
        'iblocks' => ['articles' => 81],
        'base_url' => 'https://temed.ru',
    ];
}

function basePayload(): array
{
    return [
        'code' => 'test-article',
        'name' => 'Тестовая статья',
        'preview_text' => 'Анонс',
        'detail_html' => '<h2>Заголовок</h2><p>Текст</p>',
        'section_id' => 432,
    ];
}

// ── 1. Allowlist: неизвестные ключи отбрасываются с warning ──────────────────
$gw = new FakeWriteGateway();
$gw->enums['81|ARTICLE_TYPE'] = ['diagnostics' => 55];
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$result = $writer->write(basePayload() + [
    'properties' => [
        'PRIMARY_QUERY' => 'боль в спине',
        'ARTICLE_TYPE' => 'diagnostics',
        'NONEXISTENT_PROP' => 'x',
        'DETAIL_TEXT' => 'попытка перезаписать системное поле',
    ],
]);
$warnCodes = array_column($result['warnings'], 'code');
assertTrue(in_array('NONEXISTENT_PROP', $warnCodes, true), 'unknown property must warn');
assertTrue(in_array('DETAIL_TEXT', $warnCodes, true), 'non-allowlisted DETAIL_TEXT must warn');
$sentProps = $gw->props[0]['props'];
assertTrue(isset($sentProps['PRIMARY_QUERY']) && $sentProps['PRIMARY_QUERY'] === 'боль в спине', 'PRIMARY_QUERY kept');
assertTrue(($sentProps['ARTICLE_TYPE'] ?? null) === 55, 'ARTICLE_TYPE translated xml_id->enum id');
assertTrue(!array_key_exists('NONEXISTENT_PROP', $sentProps), 'unknown prop not written');
assertTrue(!array_key_exists('DETAIL_TEXT', $sentProps), 'DETAIL_TEXT not written as property');

// ── 2. ACTIVE всегда N, значение из запроса игнорируется ─────────────────────
$gw = new FakeWriteGateway();
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$writer->write(basePayload() + ['active' => 'Y', 'ACTIVE' => 'Y']);
assertTrue($gw->added[0]['fields']['ACTIVE'] === 'N', 'ACTIVE must be forced to N on create');

// ── 3. Создание нового элемента → created:true, 201 ─────────────────────────
$gw = new FakeWriteGateway();
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$result = $writer->write(basePayload());
assertTrue($result['http_status'] === 201, 'create returns 201');
assertTrue(($result['data']['created'] ?? false) === true, 'create sets created=true');
assertTrue($result['data']['element_id'] >= 1000, 'create returns element_id');
assertTrue(str_contains($result['data']['admin_url'], 'IBLOCK_ID=81'), 'admin_url points to iblock 81');
assertTrue(count($gw->updated) === 0, 'create must not call update');

// ── 4. Обновление существующего НЕАКТИВНОГО элемента → updated:true, 200 ─────
$gw = new FakeWriteGateway();
$gw->elements['test-article'] = ['id' => 777, 'active' => 'N', 'name' => 'Старое'];
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$result = $writer->write(basePayload());
assertTrue($result['http_status'] === 200, 'update returns 200');
assertTrue(($result['data']['updated'] ?? false) === true, 'update sets updated=true');
assertTrue($result['data']['element_id'] === 777, 'update targets existing element');
assertTrue(count($gw->added) === 0, 'update must not call add');
assertTrue($gw->updated[0]['fields']['ACTIVE'] === 'N', 'update keeps ACTIVE=N');

// ── 5. Активный элемент защищён → 409, ничего не меняется ────────────────────
$gw = new FakeWriteGateway();
$gw->elements['test-article'] = ['id' => 999, 'active' => 'Y', 'name' => 'Опубликовано'];
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$caught = null;
try {
    $writer->write(basePayload());
} catch (DraftWriteException $e) {
    $caught = $e;
}
assertTrue($caught !== null, 'active element must raise DraftWriteException');
assertTrue($caught->httpStatus === 409, 'active protection is 409');
assertTrue($caught->errorCode === 'ACTIVE_ELEMENT_PROTECTED', 'active protection error_code');
assertTrue(count($gw->added) === 0 && count($gw->updated) === 0, 'active element: no writes at all');
assertTrue(count($gw->props) === 0, 'active element: no property writes');

// ── 6. Валидация значений списка по XML_ID ──────────────────────────────────
$gw = new FakeWriteGateway();
$gw->enums['81|SEARCH_INTENT'] = ['informational' => 12];
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$result = $writer->write(basePayload() + [
    'properties' => [
        'SEARCH_INTENT' => 'informational',
        'REGION' => 'unknown-xml-id',
    ],
]);
$sentProps = $gw->props[0]['props'];
assertTrue(($sentProps['SEARCH_INTENT'] ?? null) === 12, 'valid enum xml_id translated');
assertTrue(!array_key_exists('REGION', $sentProps), 'invalid enum skipped');
$warnCodes = array_column($result['warnings'], 'code');
assertTrue(in_array('REGION', $warnCodes, true), 'invalid enum produces warning');

// ── 7. Множественные свойства и даты ────────────────────────────────────────
$gw = new FakeWriteGateway();
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$writer->write(basePayload() + [
    'properties' => [
        'SECONDARY_QUERIES' => ['лечение спины', 'реабилитация'],
        'RELATED_ARTICLES_V2' => [202, 203],
        'MEDICAL_REVIEWED_AT' => '2026-07-21',
    ],
]);
$sentProps = $gw->props[0]['props'];
assertTrue($sentProps['SECONDARY_QUERIES'] === ['лечение спины', 'реабилитация'], 'multiple string values kept');
assertTrue($sentProps['RELATED_ARTICLES_V2'] === [202, 203], 'multiple E values as ints');
assertTrue($sentProps['MEDICAL_REVIEWED_AT'] === '21.07.2026', 'date normalized to Bitrix format');

// ── 8. Защита обязательных полей ────────────────────────────────────────────
$gw = new FakeWriteGateway();
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$caught = null;
try {
    $writer->write(['name' => 'X', 'section_id' => 432]); // без code
} catch (DraftWriteException $e) {
    $caught = $e;
}
assertTrue($caught !== null && $caught->httpStatus === 400 && $caught->errorCode === 'INVALID_CODE', 'missing code → 400 INVALID_CODE');

$gw = new FakeWriteGateway();
$writer = new ArticleDraftWriter(makeConfig(), $gw);
$caught = null;
try {
    $writer->write(array_merge(basePayload(), ['section_id' => 5555])); // несуществующий раздел
} catch (DraftWriteException $e) {
    $caught = $e;
}
assertTrue($caught !== null && $caught->errorCode === 'SECTION_NOT_FOUND', 'unknown section → SECTION_NOT_FOUND');

fwrite(STDOUT, "ArticleDraftWriter: все проверки пройдены\n");
