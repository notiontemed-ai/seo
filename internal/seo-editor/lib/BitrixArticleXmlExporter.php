<?php

declare(strict_types=1);

final class BitrixArticleXmlExporter
{
    private const PROPERTY_IDS = [
        'ARTICLE_TYPE' => '847', 'PRIMARY_QUERY' => '848', 'SECONDARY_QUERIES' => '849',
        'SEARCH_INTENT' => '850', 'SHORT_ANSWER' => '851', 'REGION' => '852',
        'AUTHOR' => '853', 'MEDICAL_REVIEWER' => '854', 'MEDICAL_REVIEWED_AT' => '855',
        'CONTENT_UPDATED_AT' => '856', 'SOURCES' => '857', 'RELATED_ARTICLES' => '858',
        'ARTICLE_TEMPLATE' => '864', 'ARTICLE_STRUCTURE' => '865', 'ARTICLE_STRUCTURE_NAME' => '866',
        'ARTICLE_STRUCTURE_VERSION' => '867', 'RELATED_ARTICLES_V2' => '868',
        'RELATED_SERVICES' => '869', 'RELATED_CLINICS' => '870', 'FEATURED_IMAGE_ALT' => '871',
    ];

    /** @var list<string> */
    private array $warnings = [];
    private int $filledProperties = 0;

    /** Пути поиска шаблона: сначала внутри деплоймента, затем прежние tests/fixtures (совместимость/тесты). @return list<string> */
    public function templatePaths(): array
    {
        return [
            __DIR__ . '/fixtures/bitrix-iblock-81-reference.xml',
            dirname(__DIR__, 3) . '/tests/fixtures/bitrix-iblock-81-reference.xml',
            dirname(__DIR__, 3) . '/../tests/fixtures/bitrix-iblock-81-reference.xml',
        ];
    }

    private function locateTemplate(): string
    {
        foreach ($this->templatePaths() as $path) {
            if (is_file($path)) return $path;
        }
        throw new RuntimeException('Шаблон bitrix-iblock-81-reference.xml не найден ни по одному из путей: ' . implode(', ', $this->templatePaths()));
    }

    public function export(array $payload): DOMDocument
    {
        $this->warnings = [];
        $this->filledProperties = 0;
        $template = $this->locateTemplate();
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;
        $doc->load($template);
        $doc->documentElement?->setAttribute('ДатаФормирования', gmdate('Y-m-d\TH:i:s'));
        $normalized = $this->normalizePayload($payload);
        $this->validateSection($doc, $normalized['section']);
        $goods = $doc->getElementsByTagName('Товары')->item(0);
        if (!$goods) {
            $catalog = $doc->getElementsByTagName('Каталог')->item(0) ?: $doc->documentElement?->appendChild($doc->createElement('Каталог'));
            $goods = $catalog->appendChild($doc->createElement('Товары'));
        }
        while ($goods->firstChild) $goods->removeChild($goods->firstChild);
        $goods->appendChild($this->createProduct($doc, $normalized));
        return $doc;
    }

    /**
     * Самопроверка собранного документа: обязательные узлы Классификатора/Каталога на месте и группа товара непуста.
     * @return list<string> перечень отсутствующих/пустых узлов (пустой список — документ валиден)
     */
    public function validate(DOMDocument $doc): array
    {
        $xp = new DOMXPath($doc);
        $missing = [];
        if ($xp->query('//Классификатор')->length === 0) $missing[] = 'Классификатор';
        if ($xp->query('//Каталог/Ид')->length === 0 || trim((string)$xp->evaluate('string(//Каталог/Ид)')) === '') $missing[] = 'Каталог/Ид';
        if ($xp->query('//Каталог/ИдКлассификатора')->length === 0 || trim((string)$xp->evaluate('string(//Каталог/ИдКлассификатора)')) === '') $missing[] = 'Каталог/ИдКлассификатора';
        if ($xp->query('//Классификатор/Свойства/Свойство')->length === 0) $missing[] = 'Классификатор/Свойства/Свойство';
        $group = trim((string)$xp->evaluate('string(//Товар/Группы/Ид)'));
        if ($xp->query('//Товар/Группы/Ид')->length === 0 || $group === '') $missing[] = 'Товар/Группы/Ид';
        return $missing;
    }

    /** Проверить, что раздел товара присутствует в группах Классификатора шаблона; иначе — предупреждение. */
    private function validateSection(DOMDocument $doc, string $section): void
    {
        $section = trim($section);
        if ($section === '') return;
        $xp = new DOMXPath($doc);
        $groups = [];
        foreach ($xp->query('//Классификатор/Группы//Группа/Ид') as $node) {
            $id = trim($node->textContent);
            if ($id !== '') $groups[$id] = true;
        }
        if ($groups && !isset($groups[$section])) {
            $this->warnings[] = 'Раздел ' . $section . ' отсутствует в Классификаторе шаблона; убедитесь, что в инфоблоке 81 существует раздел с этим XML_ID.';
        }
    }

    public function filename(array $payload): string
    {
        $code = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($payload['code'] ?? $payload['result_code'] ?? 'medical-article')) ?: 'medical-article';
        return strtolower(trim($code, '-')) . '-' . gmdate('Ymd') . '.xml';
    }

    /** @return list<string> */
    public function warnings(): array { return $this->warnings; }
    public function filledProperties(): int { return $this->filledProperties; }

    /** @return array<string,mixed> */
    private function normalizePayload(array $payload): array
    {
        [$legacy, $v2] = $this->splitArticleRelations($payload['related_articles'] ?? []);
        $explicitV2 = $this->relationIds($payload['related_articles_v2'] ?? []);
        return [
            'code' => (string)($payload['code'] ?? $payload['result_code'] ?? ''),
            'name' => (string)($payload['name'] ?? $payload['result_name'] ?? ''),
            'preview' => (string)($payload['preview_text'] ?? $payload['result_preview'] ?? ''),
            'detail' => $this->sanitizeHtml((string)($payload['detail_html'] ?? $payload['result_detail_html'] ?? '')),
            'section' => (string)($payload['section'] ?? $payload['article_section_id'] ?? ''),
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
        ];
    }

    private function createProduct(DOMDocument $doc, array $p): DOMElement
    {
        $product = $doc->createElement('Товар');
        $product->appendChild($doc->createElement('Ид', 'medical-article-' . $p['code'] . '-' . gmdate('Ymd')));
        $product->appendChild($doc->createElement('Наименование'))->appendChild($doc->createTextNode($p['name']));
        $groups = $product->appendChild($doc->createElement('Группы'));
        $groups->appendChild($doc->createElement('Ид', $p['section']));
        $product->appendChild($doc->createElement('Описание'))->appendChild($doc->createTextNode($p['preview']));
        $this->requisite($doc, $product, 'CML2_ACTIVE', 'false');
        $this->requisite($doc, $product, 'CML2_SORT', '500');
        $this->requisite($doc, $product, 'CML2_CODE', $p['code']);
        $this->requisite($doc, $product, 'CML2_PREVIEW_TEXT', $p['preview'], 'text');
        $this->requisite($doc, $product, 'CML2_DETAIL_TEXT', $p['detail'], 'html');
        $props = $product->appendChild($doc->createElement('ЗначенияСвойств'));
        foreach ([
            'ARTICLE_TYPE'=>[$p['article_type']], 'PRIMARY_QUERY'=>[$p['primary_query']], 'SECONDARY_QUERIES'=>$p['secondary_queries'],
            'SEARCH_INTENT'=>[$p['search_intent']], 'SHORT_ANSWER'=>[$p['short_answer']], 'REGION'=>[$p['region']],
            'AUTHOR'=>[$p['author_id']], 'MEDICAL_REVIEWER'=>[$p['medical_reviewer_id']],
            'MEDICAL_REVIEWED_AT'=>[$p['medical_reviewed_at']], 'CONTENT_UPDATED_AT'=>[$p['content_updated_at']],
            'SOURCES'=>$p['sources'], 'RELATED_ARTICLES'=>$p['related_articles'], 'ARTICLE_TEMPLATE'=>[$p['article_template'] ?: 'default'],
            'ARTICLE_STRUCTURE'=>[$p['article_structure']], 'ARTICLE_STRUCTURE_NAME'=>[$p['article_structure_name']],
            'ARTICLE_STRUCTURE_VERSION'=>[$p['article_structure_version']], 'RELATED_ARTICLES_V2'=>$p['related_articles_v2'],
            'RELATED_SERVICES'=>$p['related_services'], 'RELATED_CLINICS'=>$p['related_clinics'], 'FEATURED_IMAGE_ALT'=>[$p['featured_image_alt']],
        ] as $code => $values) $this->property($doc, $props, $code, $values);
        return $product;
    }

    private function requisite(DOMDocument $doc, DOMElement $product, string $name, string $value, ?string $type = null): void
    {
        $node = $product->appendChild($doc->createElement('ЗначениеРеквизита'));
        $node->appendChild($doc->createElement('Наименование', $name));
        if ($type !== null) $node->appendChild($doc->createElement('Тип', $type));
        $node->appendChild($doc->createElement('Значение'))->appendChild($doc->createTextNode($value));
    }

    private function property(DOMDocument $doc, DOMElement $props, string $code, array $values): void
    {
        $values = array_values(array_filter(array_map('strval', $values), static fn($v) => trim($v) !== ''));
        if (!$values) return;
        $this->filledProperties++;
        $node = $props->appendChild($doc->createElement('ЗначенияСвойства'));
        $node->appendChild($doc->createElement('Ид', self::PROPERTY_IDS[$code]));
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
