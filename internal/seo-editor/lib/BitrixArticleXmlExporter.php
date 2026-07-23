<?php

declare(strict_types=1);

final class BitrixArticleXmlExporter
{
    private const CLASSIFIER_ID = '81';
    private const CLASSIFIER_NAME = 'Медицинские статьи';
    private const CATALOG_ID = '81';
    private const CATALOG_DESCRIPTION = '/articles2/#ELEMENT_CODE#/';

    private const STANDARD_FIELD_SCHEMA = [
        'CML2_ACTIVE' => [
            'name' => 'БитриксАктивность',
            'multiple' => false,
        ],
        'CML2_CODE' => [
            'name' => 'Символьный код',
            'multiple' => false,
        ],
        'CML2_SORT' => [
            'name' => 'Сортировка',
            'multiple' => false,
        ],
        'CML2_ACTIVE_FROM' => [
            'name' => 'Начало активности',
            'multiple' => false,
        ],
        'CML2_ACTIVE_TO' => [
            'name' => 'Окончание активности',
            'multiple' => false,
        ],
        'CML2_PREVIEW_TEXT' => [
            'name' => 'Анонс',
            'multiple' => false,
        ],
        'CML2_DETAIL_TEXT' => [
            'name' => 'Описание',
            'multiple' => false,
        ],
        'CML2_PREVIEW_PICTURE' => [
            'name' => 'Картинка анонса',
            'multiple' => false,
        ],
    ];

    private const PROPERTY_SCHEMA = [
        'ARTICLE_TYPE' => ['id' => '847', 'type' => 'Справочник', 'values' => ['disease' => 'Заболевание', 'symptom' => 'Симптом', 'diagnostics' => 'Диагностика', 'treatment_method' => 'Метод лечения', 'procedure' => 'Процедура', 'rehabilitation' => 'Упражнения и реабилитация', 'prevention' => 'Профилактика', 'patient_question' => 'Вопрос пациента', 'comparison' => 'Сравнение', 'surgical_treatment' => 'Оперативное лечение']],
        'PRIMARY_QUERY' => ['id' => '848', 'type' => 'Строка'],
        'SECONDARY_QUERIES' => ['id' => '849', 'type' => 'Строка', 'multiple' => true],
        'SEARCH_INTENT' => ['id' => '850', 'type' => 'Справочник', 'values' => ['informational' => 'Информационный', 'commercial_informational' => 'Коммерческо-информационный', 'comparative' => 'Сравнительный']],
        'SHORT_ANSWER' => ['id' => '851', 'type' => 'Строка'],
        'REGION' => ['id' => '852', 'type' => 'Справочник', 'values' => ['moscow' => 'Москва', 'saint_petersburg' => 'Санкт-Петербург', 'kazan' => 'Казань', 'ufa' => 'Уфа', 'ekaterinburg' => 'Екатеринбург', 'krasnodar' => 'Краснодар']],
        'AUTHOR' => ['id' => '853', 'type' => 'Число', 'linked_iblock' => '65'],
        'MEDICAL_REVIEWER' => ['id' => '854', 'type' => 'Число', 'linked_iblock' => '65'],
        'MEDICAL_REVIEWED_AT' => ['id' => '855', 'type' => 'Дата'],
        'CONTENT_UPDATED_AT' => ['id' => '856', 'type' => 'Дата'],
        'SOURCES' => ['id' => '857', 'type' => 'Строка', 'multiple' => true],
        'RELATED_ARTICLES' => ['id' => '858', 'type' => 'Число', 'multiple' => true, 'linked_iblock' => '68'],
        'ARTICLE_TEMPLATE' => ['id' => '864', 'type' => 'Справочник', 'values' => ['default' => 'Стандартная статья']],
        'ARTICLE_STRUCTURE' => ['id' => '865', 'type' => 'Строка'],
        'ARTICLE_STRUCTURE_NAME' => ['id' => '866', 'type' => 'Строка'],
        'ARTICLE_STRUCTURE_VERSION' => ['id' => '867', 'type' => 'Строка'],
        'RELATED_ARTICLES_V2' => ['id' => '868', 'type' => 'Число', 'multiple' => true, 'linked_iblock' => '81'],
        'RELATED_SERVICES' => ['id' => '869', 'type' => 'Число', 'multiple' => true, 'linked_iblock' => '70'],
        'RELATED_CLINICS' => ['id' => '870', 'type' => 'Число', 'multiple' => true, 'linked_iblock' => '10'],
        'FEATURED_IMAGE_ALT' => ['id' => '871', 'type' => 'Строка'],
        'SHOW_FORM' => ['id' => '884', 'type' => 'Справочник', 'values' => ['Y' => 'Да', 'N' => 'Нет']],
        'FORM_ID' => ['id' => '885', 'type' => 'Строка'],
        'FORM_BUTTON_TEXT' => ['id' => '886', 'type' => 'Строка'],
    ];

    /** @var list<string> */
    private array $warnings = [];
    private int $filledProperties = 0;

    public function export(array $payload): DOMDocument
    {
        $this->warnings = [];
        $this->filledProperties = 0;
        $normalized = $this->normalizePayload($payload);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;
        $root = $doc->appendChild($doc->createElement('КоммерческаяИнформация'));
        $root->setAttribute('ВерсияСхемы', '2.021');
        $root->setAttribute('ДатаФормирования', gmdate('Y-m-d\TH:i:s'));
        $root->appendChild($this->createClassifier($doc, $normalized));
        $root->appendChild($this->createCatalog($doc, $normalized));
        return $doc;
    }

    /** @return list<string> */
    public function validate(DOMDocument $doc): array
    {
        $xp = new DOMXPath($doc);
        $missing = [];
        if ($doc->documentElement?->tagName !== 'КоммерческаяИнформация') $missing[] = 'В сформированном XML отсутствует корневой узел КоммерческаяИнформация.';
        if ($xp->query('//Классификатор')->length === 0) $missing[] = 'В сформированном XML отсутствует Классификатор.';
        if (trim((string)$xp->evaluate('string(//Классификатор/Ид)')) !== self::CLASSIFIER_ID) $missing[] = 'В сформированном XML неверный Классификатор/Ид.';

        foreach (self::STANDARD_FIELD_SCHEMA as $code => $schema) {
            $query = '//Классификатор/Свойства/Свойство[Ид="' . $code . '" and Наименование="' . $schema['name'] . '"]';
            if ($xp->query($query)->length === 0) $missing[] = 'В сформированном XML отсутствует стандартное поле ' . $code . ' (' . $schema['name'] . ') в классификаторе.';
        }

        $actual = [];
        foreach ($xp->query('//Классификатор/Свойства/Свойство') as $property) {
            $code = trim((string)$xp->evaluate('string(Наименование)', $property));
            $id = trim((string)$xp->evaluate('string(Ид)', $property));
            if ($code !== '') $actual[$code] = $id;
        }
        foreach (self::PROPERTY_SCHEMA as $code => $schema) {
            if (!array_key_exists($code, $actual)) $missing[] = 'В сформированном XML отсутствует свойство ' . $code . ' с ID ' . $schema['id'] . '.';
            elseif ($actual[$code] !== $schema['id']) $missing[] = 'В сформированном XML свойство ' . $code . ' имеет ID ' . $actual[$code] . ' вместо ' . $schema['id'] . '.';
        }

        if (trim((string)$xp->evaluate('string(//Каталог/Ид)')) !== self::CATALOG_ID) $missing[] = 'В сформированном XML неверный Каталог/Ид.';
        if (trim((string)$xp->evaluate('string(//Каталог/ИдКлассификатора)')) !== self::CLASSIFIER_ID) $missing[] = 'В сформированном XML неверный Каталог/ИдКлассификатора.';
        if ($xp->query('//Товар')->length === 0) $missing[] = 'В сформированном XML отсутствует Товар.';
        foreach (['Ид', 'Наименование'] as $tag) if (trim((string)$xp->evaluate('string(//Товар/' . $tag . ')')) === '') $missing[] = 'В сформированном XML пустой Товар/' . $tag . '.';
        if (trim((string)$xp->evaluate('string(//Товар/Группы/Ид)')) === '') $missing[] = 'В сформированном XML пустой Товар/Группы/Ид.';

        if ($xp->query('//Товар/ЗначениеРеквизита[starts-with(Наименование, "CML2_")]')->length > 0) $missing[] = 'В сформированном XML стандартные поля CML2 ошибочно выгружены как ЗначениеРеквизита.';

        $codeValue = trim((string)$xp->evaluate('string(//Товар/ЗначенияСвойств/ЗначенияСвойства[Ид="CML2_CODE"]/Значение)'));
        $productCode = preg_replace('/^medical-article-(.*)-\d{8}$/', '$1', trim((string)$xp->evaluate('string(//Товар/Ид)')));
        if ($codeValue === '') $missing[] = 'В сформированном XML пустой CML2_CODE товара.';
        elseif ($productCode !== '' && $codeValue !== $productCode) $missing[] = 'В сформированном XML CML2_CODE товара не совпадает с нормализованным кодом статьи.';
        foreach (['CML2_ACTIVE', 'CML2_SORT', 'CML2_PREVIEW_TEXT', 'CML2_DETAIL_TEXT'] as $code) {
            if ($xp->query('//Товар/ЗначенияСвойств/ЗначенияСвойства[Ид="' . $code . '"]')->length === 0) $missing[] = 'В сформированном XML отсутствует стандартное поле товара ' . $code . '.';
        }

        $knownIds = array_merge(array_keys(self::STANDARD_FIELD_SCHEMA), array_column(self::PROPERTY_SCHEMA, 'id'));
        foreach ($xp->query('//Товар/ЗначенияСвойств/ЗначенияСвойства/Ид') as $idNode) {
            $id = trim($idNode->textContent);
            if (!in_array($id, $knownIds, true)) $missing[] = 'В сформированном XML значение свойства с неизвестным ID ' . $id . '.';
        }
        return $missing;
    }

    public function filename(array $payload): string
    {
        $code = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($payload['code'] ?? $payload['result_code'] ?? 'medical-article')) ?: 'medical-article';
        return strtolower(trim($code, '-')) . '-' . gmdate('Ymd') . '.xml';
    }

    /** @return list<string> */
    public function warnings(): array { return $this->warnings; }
    public function filledProperties(): int { return $this->filledProperties; }

    private function createClassifier(DOMDocument $doc, array $p): DOMElement
    {
        $classifier = $doc->createElement('Классификатор');
        $classifier->appendChild($doc->createElement('Ид', self::CLASSIFIER_ID));
        $classifier->appendChild($doc->createElement('Наименование', self::CLASSIFIER_NAME));
        $props = $classifier->appendChild($doc->createElement('Свойства'));
        foreach (self::STANDARD_FIELD_SCHEMA as $code => $schema) $props->appendChild($this->createStandardFieldDefinition($doc, $code, $schema));
        foreach (self::PROPERTY_SCHEMA as $code => $schema) $props->appendChild($this->createPropertyDefinition($doc, $code, $schema));
        $groups = $classifier->appendChild($doc->createElement('Группы'));
        $group = $groups->appendChild($doc->createElement('Группа'));
        $group->appendChild($doc->createElement('Ид', $p['section']));
        $group->appendChild($doc->createElement('Наименование'))->appendChild($doc->createTextNode($p['section_name'] ?: $p['section']));
        return $classifier;
    }

    private function createStandardFieldDefinition(DOMDocument $doc, string $code, array $schema): DOMElement
    {
        $property = $doc->createElement('Свойство');
        $property->appendChild($doc->createElement('Ид', $code));
        $property->appendChild($doc->createElement('Наименование'))->appendChild($doc->createTextNode((string)$schema['name']));
        $property->appendChild($doc->createElement('Множественное', !empty($schema['multiple']) ? 'true' : 'false'));
        return $property;
    }

    private function createPropertyDefinition(DOMDocument $doc, string $code, array $schema): DOMElement
    {
        $property = $doc->createElement('Свойство');
        $property->appendChild($doc->createElement('Ид', (string)$schema['id']));
        $property->appendChild($doc->createElement('Наименование', $code));
        $property->appendChild($doc->createElement('ТипЗначений', (string)$schema['type']));
        if (!empty($schema['multiple'])) $property->appendChild($doc->createElement('Множественное', 'true'));
        if (!empty($schema['linked_iblock'])) $property->appendChild($doc->createElement('СсылочныйИнфоблок', (string)$schema['linked_iblock']));
        if (!empty($schema['values']) && is_array($schema['values'])) {
            $values = $property->appendChild($doc->createElement('ВариантыЗначений'));
            foreach ($schema['values'] as $xmlId => $label) {
                $item = $values->appendChild($doc->createElement('Справочник'));
                $item->appendChild($doc->createElement('ИдЗначения', (string)$xmlId));
                $item->appendChild($doc->createElement('Значение'))->appendChild($doc->createTextNode((string)$label));
            }
        }
        return $property;
    }

    private function createCatalog(DOMDocument $doc, array $p): DOMElement
    {
        $catalog = $doc->createElement('Каталог');
        $catalog->appendChild($doc->createElement('Ид', self::CATALOG_ID));
        $catalog->appendChild($doc->createElement('ИдКлассификатора', self::CLASSIFIER_ID));
        $catalog->appendChild($doc->createElement('Наименование', self::CLASSIFIER_NAME));
        $catalog->appendChild($doc->createElement('Описание', self::CATALOG_DESCRIPTION));
        $goods = $catalog->appendChild($doc->createElement('Товары'));
        $goods->appendChild($this->createProduct($doc, $p));
        return $catalog;
    }

    /** @return array<string,mixed> */
    private function normalizePayload(array $payload): array
    {
        [$legacy, $v2] = $this->splitArticleRelations($payload['related_articles'] ?? []);
        $explicitV2 = $this->relationIds($payload['related_articles_v2'] ?? []);
        $formId = trim((string)($payload['form_id'] ?? ''));
        $showForm = strtoupper(trim((string)($payload['show_form'] ?? '')));
        if (!in_array($showForm, ['Y', 'N'], true)) {
            $showForm = $formId !== '' ? 'Y' : 'N';
        }
        $formButtonText = trim((string)($payload['form_button_text'] ?? ''));
        return [
            'code' => (string)($payload['code'] ?? $payload['result_code'] ?? ''),
            'name' => (string)($payload['name'] ?? $payload['result_name'] ?? ''),
            'preview' => (string)($payload['preview_text'] ?? $payload['result_preview'] ?? ''),
            'detail' => $this->sanitizeHtml((string)($payload['detail_html'] ?? $payload['result_detail_html'] ?? '')),
            'section' => (string)($payload['section'] ?? $payload['article_section_id'] ?? ''),
            'section_name' => (string)($payload['section_name'] ?? $payload['article_section'] ?? ''),
            'primary_query' => (string)($payload['primary_query'] ?? ''),
            'secondary_queries' => $this->list($payload['secondary_queries'] ?? []),
            'search_intent' => (string)($payload['search_intent_xml_id'] ?? $payload['search_intent'] ?? ''),
            'short_answer' => (string)($payload['short_answer'] ?? $payload['result_short_answer'] ?? ''),
            'region' => (string)($payload['region_xml_id'] ?? $payload['region'] ?? ''),
            'article_template' => (string)($payload['article_template_xml_id'] ?? $payload['article_template'] ?? 'default'),
            'author_id' => (string)($payload['author_id'] ?? ''),
            'medical_reviewer_id' => (string)($payload['medical_reviewer_id'] ?? ''),
            'medical_reviewed_at' => $this->date((string)($payload['medical_reviewed_at'] ?? '')),
            'content_updated_at' => $this->date((string)($payload['content_updated_at'] ?? '')),
            'sources' => $this->list($payload['sources'] ?? $payload['result_sources'] ?? []),
            'related_articles' => $legacy,
            'related_articles_v2' => $this->unique([...$v2, ...$explicitV2]),
            'related_services' => $this->relationIds($payload['related_services'] ?? []),
            'related_clinics' => $this->relationIds($payload['related_clinics'] ?? []),
            'article_type' => (string)($payload['article_type_xml_id'] ?? $payload['article_type'] ?? ''),
            'article_structure' => (string)($payload['article_structure'] ?? ''),
            'article_structure_name' => (string)($payload['article_structure_name'] ?? ''),
            'article_structure_version' => (string)($payload['article_structure_version'] ?? ''),
            'featured_image_alt' => strip_tags((string)($payload['featured_image_alt'] ?? '')),
            'show_form' => $showForm,
            'form_id' => $formId,
            'form_button_text' => $formButtonText,
        ];
    }

    private function createProduct(DOMDocument $doc, array $p): DOMElement
    {
        $product = $doc->createElement('Товар');
        $product->appendChild($doc->createElement('Ид', 'medical-article-' . $p['code'] . '-' . gmdate('Ymd')));
        $product->appendChild($doc->createElement('Наименование'))->appendChild($doc->createTextNode($p['name']));
        $product->appendChild($doc->createElement('БитриксТеги'));
        $groups = $product->appendChild($doc->createElement('Группы'));
        $groups->appendChild($doc->createElement('Ид', $p['section']));
        $product->appendChild($doc->createElement('Картинка'));
        $product->appendChild($doc->createElement('Описание'))->appendChild($doc->createTextNode($p['preview']));
        $props = $product->appendChild($doc->createElement('ЗначенияСвойств'));
        $this->standardField($doc, $props, 'CML2_ACTIVE', 'false');
        $this->standardField($doc, $props, 'CML2_CODE', trim($p['code']));
        $this->standardField($doc, $props, 'CML2_SORT', '500');
        $this->standardField($doc, $props, 'CML2_ACTIVE_FROM', '');
        $this->standardField($doc, $props, 'CML2_ACTIVE_TO', '');
        $this->standardField($doc, $props, 'CML2_PREVIEW_TEXT', $p['preview'], 'text');
        $this->standardField($doc, $props, 'CML2_DETAIL_TEXT', $p['detail'], 'html');
        $this->standardField($doc, $props, 'CML2_PREVIEW_PICTURE', '');
        foreach ([
            'ARTICLE_TYPE'=>[$p['article_type']], 'PRIMARY_QUERY'=>[$p['primary_query']], 'SECONDARY_QUERIES'=>$p['secondary_queries'],
            'SEARCH_INTENT'=>[$p['search_intent']], 'SHORT_ANSWER'=>[$p['short_answer']], 'REGION'=>[$p['region']],
            'AUTHOR'=>[$p['author_id']], 'MEDICAL_REVIEWER'=>[$p['medical_reviewer_id']],
            'MEDICAL_REVIEWED_AT'=>[$p['medical_reviewed_at']], 'CONTENT_UPDATED_AT'=>[$p['content_updated_at']],
            'SOURCES'=>$p['sources'], 'RELATED_ARTICLES'=>$p['related_articles'], 'ARTICLE_TEMPLATE'=>[$p['article_template'] ?: 'default'],
            'ARTICLE_STRUCTURE'=>[$p['article_structure']], 'ARTICLE_STRUCTURE_NAME'=>[$p['article_structure_name']],
            'ARTICLE_STRUCTURE_VERSION'=>[$p['article_structure_version']], 'RELATED_ARTICLES_V2'=>$p['related_articles_v2'],
            'RELATED_SERVICES'=>$p['related_services'], 'RELATED_CLINICS'=>$p['related_clinics'], 'FEATURED_IMAGE_ALT'=>[$p['featured_image_alt']],
            'SHOW_FORM'=>[$p['show_form']], 'FORM_ID'=>[$p['form_id']], 'FORM_BUTTON_TEXT'=>[$p['form_button_text']],
        ] as $code => $values) $this->property($doc, $props, $code, $values);
        return $product;
    }

    private function standardField(DOMDocument $doc, DOMElement $properties, string $code, string $value, ?string $type = null): void
    {
        if (!array_key_exists($code, self::STANDARD_FIELD_SCHEMA)) throw new InvalidArgumentException('Неизвестное стандартное поле XML: ' . $code);
        $node = $properties->appendChild($doc->createElement('ЗначенияСвойства'));
        $node->appendChild($doc->createElement('Ид', $code));
        $node->appendChild($doc->createElement('Значение'))->appendChild($doc->createTextNode($value));
        if ($type !== null) $node->appendChild($doc->createElement('Тип', $type));
    }

    private function propertyId(string $code): string
    {
        $propertyId = self::PROPERTY_SCHEMA[$code]['id'] ?? null;
        if ($propertyId === null) throw new InvalidArgumentException('Неизвестное свойство XML: ' . $code);
        return (string)$propertyId;
    }

    private function property(DOMDocument $doc, DOMElement $props, string $code, array $values): void
    {
        $propertyId = $this->propertyId($code);
        $values = array_values(array_filter(array_map('strval', $values), static fn($v) => trim($v) !== ''));
        if (!$values) return;
        $this->filledProperties++;
        $node = $props->appendChild($doc->createElement('ЗначенияСвойства'));
        $node->appendChild($doc->createElement('Ид', $propertyId));
        $node->appendChild($doc->createElement('Наименование', $code));
        foreach ($values as $value) $node->appendChild($doc->createElement('Значение'))->appendChild($doc->createTextNode($value));
    }

    private function sanitizeHtml(string $html): string
    {
        $clean = preg_replace('#<(script|style|form)\b[^>]*>.*?</\1>#isu', '', $html) ?? '';
        $clean = preg_replace('#<iframe\b(?![^>]*\bsrc=["\']https://(?:www\.)?(?:youtube\.com|rutube\.ru|vk\.com)/)[^>]*>.*?</iframe>#isu', '', $clean) ?? '';
        return str_replace(']]>', ']]&gt;', $clean);
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return $m[3] . '.' . $m[2] . '.' . $m[1];
        $ts = strtotime($value);
        return $ts ? gmdate('d.m.Y', $ts) : $value;
    }

    private function list(mixed $value): array
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
        }
        if (is_array($value)) return array_values(array_filter(array_map('strval', $value), static fn($v) => trim($v) !== ''));
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string)$value) ?: []), static fn($v) => $v !== ''));
    }

    /** @return list<string> */
    private function relationIds(mixed $value): array
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
        }
        $items = is_array($value) ? $value : $this->list($value);
        $out = [];
        foreach ($items as $item) {
            $id = is_array($item) ? (string)($item['element_id'] ?? $item['id'] ?? '') : (string)$item;
            if (preg_match('/(?:^|\D)(\d+)(?:\D|$)/', $id, $m) && (int)$m[1] > 0) $out[] = $m[1];
        }
        return $this->unique($out);
    }

    /** @return array{0:list<string>,1:list<string>} */
    private function splitArticleRelations(mixed $value): array
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
        }
        $items = is_array($value) ? $value : $this->list($value);
        $legacy = []; $v2 = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $id = (string)($item['element_id'] ?? $item['id'] ?? '');
                $source = (string)($item['source'] ?? '');
                $iblock = (int)($item['source_iblock_id'] ?? $item['iblock_id'] ?? ($source === 'legacy' ? 68 : ($source === 'new' ? 81 : 68)));
            } else {
                $text = (string)$item;
                $iblock = str_starts_with($text, 'new:') ? 81 : 68;
                $id = $text;
            }
            if (!preg_match('/(?:^|\D)(\d+)(?:\D|$)/', $id, $m) || (int)$m[1] <= 0) continue;
            if ($iblock === 81) $v2[] = $m[1];
            elseif ($iblock === 68) $legacy[] = $m[1];
        }
        return [$this->unique($legacy), $this->unique($v2)];
    }

    /** @param list<string> $values @return list<string> */
    private function unique(array $values): array
    {
        $seen = []; $out = [];
        foreach ($values as $value) if (!isset($seen[$value])) { $seen[$value] = true; $out[] = $value; }
        return $out;
    }
}
