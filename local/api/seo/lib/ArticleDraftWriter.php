<?php

declare(strict_types=1);

/**
 * Ошибка записи черновика статьи. Несёт HTTP-статус и машиночитаемый код,
 * чтобы обработчик в index.php мог отдать корректный ответ клиенту.
 */
final class DraftWriteException extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

/**
 * Абстракция над операциями Bitrix, нужными для записи черновика.
 * Позволяет тестировать ArticleDraftWriter без окружения Bitrix.
 */
interface ArticleWriteGateway
{
    /**
     * Найти элемент по символьному коду в инфоблоке.
     *
     * @return array{id:int,active:string,name:string}|null
     */
    public function findElementByCode(int $iblockId, string $code): ?array;

    /** Существует ли раздел с таким ID в указанном инфоблоке. */
    public function sectionExists(int $iblockId, int $sectionId): bool;

    /**
     * Карта XML_ID → ID варианта для списочного свойства.
     *
     * @return array<string,int>
     */
    public function getEnumMap(int $iblockId, string $propertyCode): array;

    /** Символьный тип инфоблока (IBLOCK_TYPE_ID) для построения admin_url. */
    public function getIblockType(int $iblockId): string;

    /**
     * Создать элемент, вернуть его ID.
     *
     * @param array<string,mixed> $fields
     */
    public function addElement(array $fields): int;

    /**
     * Обновить поля элемента.
     *
     * @param array<string,mixed> $fields
     */
    public function updateElement(int $id, array $fields): void;

    /**
     * Записать значения свойств (SetPropertyValuesEx-семантика: обновляются
     * только переданные свойства).
     *
     * @param array<string,mixed> $props code => value|values
     */
    public function setPropertyValues(int $iblockId, int $id, array $props): void;
}

/**
 * Пишет неактивный (ACTIVE=N) черновик статьи в инфоблок статей v2.
 *
 * Инвариант безопасности: активные элементы не изменяются никогда —
 * попытка записи по коду активного элемента приводит к 409.
 */
final class ArticleDraftWriter
{
    /**
     * Строгий allowlist свойств инфоблока 81 (по символьному коду).
     * Создание новых свойств запрещено — только этот список.
     * type: S(строка), L(список по XML_ID), N(число/привязка), E(привязка),
     * Date(дата). multiple — множественность.
     *
     * @var array<string,array{type:string,multiple:bool}>
     */
    private const ALLOWED_PROPERTIES = [
        'ARTICLE_TYPE'              => ['type' => 'L',    'multiple' => false],
        'PRIMARY_QUERY'            => ['type' => 'S',    'multiple' => false],
        'SECONDARY_QUERIES'        => ['type' => 'S',    'multiple' => true],
        'SEARCH_INTENT'            => ['type' => 'L',    'multiple' => false],
        'SHORT_ANSWER'             => ['type' => 'S',    'multiple' => false],
        'REGION'                   => ['type' => 'L',    'multiple' => false],
        'AUTHOR'                   => ['type' => 'N',    'multiple' => false],
        'MEDICAL_REVIEWER'         => ['type' => 'N',    'multiple' => false],
        'MEDICAL_REVIEWED_AT'      => ['type' => 'Date', 'multiple' => false],
        'CONTENT_UPDATED_AT'       => ['type' => 'Date', 'multiple' => false],
        'SOURCES'                  => ['type' => 'S',    'multiple' => true],
        'RELATED_ARTICLES'         => ['type' => 'N',    'multiple' => true],
        'ARTICLE_TEMPLATE'         => ['type' => 'L',    'multiple' => false],
        'ARTICLE_STRUCTURE'        => ['type' => 'S',    'multiple' => false],
        'ARTICLE_STRUCTURE_NAME'   => ['type' => 'S',    'multiple' => false],
        'ARTICLE_STRUCTURE_VERSION' => ['type' => 'S',   'multiple' => false],
        'RELATED_ARTICLES_V2'      => ['type' => 'E',    'multiple' => true],
        'RELATED_SERVICES'         => ['type' => 'E',    'multiple' => true],
        'RELATED_CLINICS'          => ['type' => 'E',    'multiple' => true],
        'FEATURED_IMAGE_ALT'       => ['type' => 'S',    'multiple' => false],
        'SHOW_FORM'                => ['type' => 'L',    'multiple' => false],
        'FORM_ID'                  => ['type' => 'S',    'multiple' => false],
        'FORM_BUTTON_TEXT'         => ['type' => 'S',    'multiple' => false],
    ];

    /** @var array<string,array<string,int>> кэш карт вариантов списков */
    private array $enumCache = [];

    public function __construct(
        private array $config,
        private ArticleWriteGateway $gateway,
        private ?HtmlRenderer $renderer = null,
        private ?ContentReferenceResolver $resolver = null
    ) {
    }

    /** Список разрешённых символьных кодов свойств (для документации/UI). */
    public static function allowedPropertyCodes(): array
    {
        return array_keys(self::ALLOWED_PROPERTIES);
    }

    /**
     * Upsert неактивного черновика.
     *
     * @param array<string,mixed> $payload
     * @return array{http_status:int,data:array<string,mixed>,warnings:array<int,array<string,mixed>>}
     */
    public function write(array $payload): array
    {
        $iblockId = (int)($this->config['iblocks']['articles'] ?? 0);
        if ($iblockId <= 0) {
            throw new DraftWriteException(
                'Инфоблок статей не настроен на сервере',
                500,
                'IBLOCK_NOT_CONFIGURED'
            );
        }

        $code = trim((string)($payload['code'] ?? ''));
        if ($code === '' || preg_match('~^[A-Za-z0-9_-]{1,255}$~', $code) !== 1) {
            throw new DraftWriteException(
                'Некорректный символьный код (допустимы латиница, цифры, _ и -)',
                400,
                'INVALID_CODE',
                ['code' => $code]
            );
        }

        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new DraftWriteException('Не передано название статьи', 400, 'INVALID_NAME');
        }

        $sectionId = (int)($payload['section_id'] ?? 0);
        if ($sectionId <= 0) {
            throw new DraftWriteException(
                'Не передан числовой section_id раздела инфоблока',
                400,
                'INVALID_SECTION'
            );
        }
        if (!$this->gateway->sectionExists($iblockId, $sectionId)) {
            throw new DraftWriteException(
                'Раздел не найден в инфоблоке статей',
                400,
                'SECTION_NOT_FOUND',
                ['section_id' => $sectionId, 'iblock_id' => $iblockId]
            );
        }

        $warnings = [];
        $propertiesInput = is_array($payload['properties'] ?? null) ? $payload['properties'] : [];

        // Контент как блоки article_content v2: HTML собирается детерминированно
        // на сервере тем же HtmlRenderer, что и предпросмотр в React.
        $detailHtml = (string)($payload['detail_html'] ?? '');
        $articleContent = is_array($payload['article_content'] ?? null) ? $payload['article_content'] : null;
        if ($articleContent !== null && $this->renderer !== null) {
            $normalized = ArticleContent::normalize($articleContent, $this->resolver);
            foreach ($normalized['warnings'] as $warning) {
                $warnings[] = $warning;
            }
            $detailHtml = $this->renderer->render($normalized, [
                'form' => [
                    'show_form' => $propertiesInput['SHOW_FORM'] ?? 'N',
                    'form_id' => $propertiesInput['FORM_ID'] ?? '',
                    'button_text' => $propertiesInput['FORM_BUTTON_TEXT'] ?? '',
                ],
            ]);
            // short_answer дублируется в свойство 851, если не задано явно.
            if ($normalized['short_answer'] !== '' && !isset($propertiesInput['SHORT_ANSWER'])) {
                $propertiesInput['SHORT_ANSWER'] = $normalized['short_answer'];
            }
        }

        $propValues = $this->buildProperties($iblockId, $propertiesInput, $warnings);

        $fields = [
            'IBLOCK_ID' => $iblockId,
            'IBLOCK_SECTION_ID' => $sectionId,
            'IBLOCK_SECTION' => [$sectionId],
            'NAME' => $name,
            'CODE' => $code,
            // ACTIVE всегда N на сервере; значение из запроса игнорируется.
            'ACTIVE' => 'N',
            'PREVIEW_TEXT' => (string)($payload['preview_text'] ?? ''),
            'PREVIEW_TEXT_TYPE' => 'text',
            'DETAIL_TEXT' => $detailHtml,
            'DETAIL_TEXT_TYPE' => 'html',
        ];

        // SEO-мета элемента (IPROPERTY): title и description страницы.
        // Принимается как seo:{title,description} либо seo_title/meta_description.
        $ipropertyTemplates = $this->buildSeoMeta($payload);
        if ($ipropertyTemplates !== []) {
            $fields['IPROPERTY_TEMPLATES'] = $ipropertyTemplates;
        }

        $existing = $this->gateway->findElementByCode($iblockId, $code);

        if ($existing === null) {
            $id = $this->gateway->addElement($fields);
            if ($propValues !== []) {
                $this->gateway->setPropertyValues($iblockId, $id, $propValues);
            }

            return $this->result(true, $iblockId, $id, $sectionId, $warnings);
        }

        if (strtoupper((string)($existing['active'] ?? '')) === 'Y') {
            // Активные элементы write-API не изменяет никогда.
            throw new DraftWriteException(
                'Элемент с этим кодом активен и защищён от изменений',
                409,
                'ACTIVE_ELEMENT_PROTECTED',
                ['element_id' => (int)$existing['id']]
            );
        }

        $id = (int)$existing['id'];
        $this->gateway->updateElement($id, $fields);
        if ($propValues !== []) {
            $this->gateway->setPropertyValues($iblockId, $id, $propValues);
        }

        return $this->result(false, $iblockId, $id, $sectionId, $warnings);
    }

    /**
     * @param array<int,array<string,mixed>> $warnings
     * @return array{http_status:int,data:array<string,mixed>,warnings:array<int,array<string,mixed>>}
     */
    private function result(bool $created, int $iblockId, int $id, int $sectionId, array $warnings): array
    {
        $data = [
            'element_id' => $id,
            'active' => 'N',
            'admin_url' => $this->adminUrl($iblockId, $id, $sectionId),
            'warnings' => $warnings,
        ];
        $data[$created ? 'created' : 'updated'] = true;

        return [
            'http_status' => $created ? 201 : 200,
            'data' => $data,
            'warnings' => $warnings,
        ];
    }

    private function adminUrl(int $iblockId, int $id, int $sectionId): string
    {
        $base = rtrim((string)($this->config['base_url'] ?? ''), '/');
        $query = http_build_query(
            [
                'IBLOCK_ID' => $iblockId,
                'type' => $this->gateway->getIblockType($iblockId),
                'ID' => $id,
                'lang' => 'ru',
                'find_section_section' => $sectionId,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return $base . '/bitrix/admin/iblock_element_edit.php?' . $query;
    }

    /**
     * Собрать шаблоны SEO-меты (IPROPERTY_TEMPLATES) из payload.
     * Пустые значения не передаются, чтобы не затирать существующую мету
     * при обновлении черновика.
     *
     * @param array<string,mixed> $payload
     * @return array<string,string>
     */
    private function buildSeoMeta(array $payload): array
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $title = trim((string)($seo['title'] ?? $payload['seo_title'] ?? ''));
        $description = trim((string)($seo['description'] ?? $payload['meta_description'] ?? ''));

        $templates = [];
        if ($title !== '') {
            $templates['ELEMENT_META_TITLE'] = $title;
        }
        if ($description !== '') {
            $templates['ELEMENT_META_DESCRIPTION'] = $description;
        }

        return $templates;
    }

    /**
     * Отфильтровать свойства по allowlist, провалидировать значения и собрать
     * map для SetPropertyValuesEx. Неизвестные ключи и невалидные значения
     * попадают в $warnings и пропускаются.
     *
     * @param array<string,mixed> $input
     * @param array<int,array<string,mixed>> $warnings
     * @return array<string,mixed>
     */
    private function buildProperties(int $iblockId, array $input, array &$warnings): array
    {
        $out = [];

        foreach ($input as $rawCode => $value) {
            $code = (string)$rawCode;

            if (!isset(self::ALLOWED_PROPERTIES[$code])) {
                $warnings[] = [
                    'type' => 'unknown_property',
                    'code' => $code,
                    'message' => 'Свойство не входит в allowlist и пропущено',
                ];
                continue;
            }

            $def = self::ALLOWED_PROPERTIES[$code];

            if ($def['multiple']) {
                $rawValues = is_array($value) ? array_values($value) : [$value];
            } else {
                $rawValues = is_array($value) ? [reset($value)] : [$value];
            }

            $prepared = [];
            foreach ($rawValues as $item) {
                $pv = $this->prepareValue($iblockId, $code, $def, $item, $warnings);
                if ($pv !== null) {
                    $prepared[] = $pv;
                }
            }

            if ($prepared === []) {
                continue;
            }

            $out[$code] = $def['multiple'] ? $prepared : $prepared[0];
        }

        return $out;
    }

    /**
     * @param array{type:string,multiple:bool} $def
     * @param array<int,array<string,mixed>> $warnings
     * @return int|string|null
     */
    private function prepareValue(int $iblockId, string $code, array $def, $value, array &$warnings)
    {
        if ($value === null) {
            return null;
        }

        switch ($def['type']) {
            case 'L':
                $xmlId = trim((string)$value);
                if ($xmlId === '') {
                    return null;
                }
                $map = $this->enumMap($iblockId, $code);
                if (!isset($map[$xmlId])) {
                    $warnings[] = [
                        'type' => 'invalid_enum',
                        'code' => $code,
                        'value' => $xmlId,
                        'message' => 'XML_ID варианта списка не найден, значение пропущено',
                    ];
                    return null;
                }
                return $map[$xmlId];

            case 'N':
            case 'E':
                $id = (int)$value;
                if ($id <= 0) {
                    $warnings[] = [
                        'type' => 'invalid_reference',
                        'code' => $code,
                        'value' => is_scalar($value) ? (string)$value : '',
                        'message' => 'Ожидался положительный числовой ID элемента',
                    ];
                    return null;
                }
                return $id;

            case 'Date':
                $date = $this->normalizeDate((string)$value);
                if ($date === null) {
                    $warnings[] = [
                        'type' => 'invalid_date',
                        'code' => $code,
                        'value' => is_scalar($value) ? (string)$value : '',
                        'message' => 'Некорректная дата (ожидается YYYY-MM-DD или DD.MM.YYYY)',
                    ];
                    return null;
                }
                return $date;

            case 'S':
            default:
                if (!is_scalar($value)) {
                    return null;
                }
                $string = trim((string)$value);
                return $string === '' ? null : $string;
        }
    }

    /** @return array<string,int> */
    private function enumMap(int $iblockId, string $code): array
    {
        $key = $iblockId . '|' . $code;
        if (!array_key_exists($key, $this->enumCache)) {
            $this->enumCache[$key] = $this->gateway->getEnumMap($iblockId, $code);
        }

        return $this->enumCache[$key];
    }

    /** Привести дату к формату Bitrix DD.MM.YYYY. */
    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('~^(\d{4})-(\d{2})-(\d{2})~', $value, $m) === 1) {
            return sprintf('%02d.%02d.%04d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        if (preg_match('~^(\d{2})\.(\d{2})\.(\d{4})$~', $value) === 1) {
            return $value;
        }

        return null;
    }
}

/**
 * Реальная реализация шлюза на API Bitrix. Используется только на сервере;
 * ссылки на классы CIBlock* резолвятся при вызове методов, поэтому файл можно
 * подключать и в тестах без окружения Bitrix.
 */
final class BitrixArticleWriteGateway implements ArticleWriteGateway
{
    public function findElementByCode(int $iblockId, string $code): ?array
    {
        $res = CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, '=CODE' => $code],
            false,
            false,
            ['ID', 'ACTIVE', 'NAME']
        );

        $row = $res->Fetch();
        if (!$row) {
            return null;
        }

        return [
            'id' => (int)$row['ID'],
            'active' => (string)$row['ACTIVE'],
            'name' => (string)$row['NAME'],
        ];
    }

    public function sectionExists(int $iblockId, int $sectionId): bool
    {
        $res = CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $sectionId],
            false,
            ['ID']
        );

        return (bool)$res->Fetch();
    }

    public function getEnumMap(int $iblockId, string $propertyCode): array
    {
        $propRes = CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
        );
        $prop = $propRes->Fetch();

        if (!$prop || (string)$prop['PROPERTY_TYPE'] !== 'L') {
            return [];
        }

        $map = [];
        $enumRes = CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => (int)$prop['ID']]);
        while ($enum = $enumRes->Fetch()) {
            $xmlId = (string)($enum['XML_ID'] ?? '');
            if ($xmlId !== '') {
                $map[$xmlId] = (int)$enum['ID'];
            }
        }

        return $map;
    }

    public function getIblockType(int $iblockId): string
    {
        $res = CIBlock::GetByID($iblockId);
        $row = $res->Fetch();

        return $row ? (string)($row['IBLOCK_TYPE_ID'] ?? '') : '';
    }

    public function addElement(array $fields): int
    {
        $element = new CIBlockElement();
        $id = $element->Add($fields);

        if (!$id) {
            throw new DraftWriteException(
                'Не удалось создать элемент: ' . (string)$element->LAST_ERROR,
                500,
                'ELEMENT_ADD_FAILED'
            );
        }

        return (int)$id;
    }

    public function updateElement(int $id, array $fields): void
    {
        $element = new CIBlockElement();

        if (!$element->Update($id, $fields)) {
            throw new DraftWriteException(
                'Не удалось обновить элемент: ' . (string)$element->LAST_ERROR,
                500,
                'ELEMENT_UPDATE_FAILED'
            );
        }
    }

    public function setPropertyValues(int $iblockId, int $id, array $props): void
    {
        CIBlockElement::SetPropertyValuesEx($id, $iblockId, $props);
    }
}
