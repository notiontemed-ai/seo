<?php

declare(strict_types=1);

final class BitrixArticleXmlExporter
{
    private const PROPERTY_IDS = [
        'ARTICLE_TYPE' => '847', 'PRIMARY_QUERY' => '848', 'SECONDARY_QUERIES' => '849',
        'SEARCH_INTENT' => '850', 'SHORT_ANSWER' => '851', 'REGION' => '852',
        'AUTHOR' => '853', 'MEDICAL_REVIEWER' => '854', 'MEDICAL_REVIEWED_AT' => '855',
        'CONTENT_UPDATED_AT' => '856', 'SOURCES' => '857', 'RELATED_ARTICLES' => '858',
        'ARTICLE_TEMPLATE' => '864',
    ];

    /** @var list<string> */
    private array $warnings = [];
    private int $filledProperties = 0;

    public function export(array $payload): DOMDocument
    {
        $this->warnings = [];
        $this->filledProperties = 0;
        $template = dirname(__DIR__, 3) . '/tests/fixtures/bitrix-iblock-81-reference.xml';
        if (!is_file($template)) {
            $template = dirname(__DIR__, 3) . '/../tests/fixtures/bitrix-iblock-81-reference.xml';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;
        if (is_file($template)) {
            $doc->load($template);
        } else {
            $root = $doc->appendChild($doc->createElement('КоммерческаяИнформация'));
            $root->setAttribute('ВерсияСхемы', '2.021');
            $catalog = $root->appendChild($doc->createElement('Каталог'));
            $catalog->appendChild($doc->createElement('Товары'));
        }
        $root = $doc->documentElement;
        if ($root) {
            $root->setAttribute('ДатаФормирования', gmdate('Y-m-d\TH:i:s'));
        }
        $goods = $doc->getElementsByTagName('Товары')->item(0);
        if (!$goods) {
            $catalog = $doc->getElementsByTagName('Каталог')->item(0) ?: $doc->documentElement?->appendChild($doc->createElement('Каталог'));
            $goods = $catalog->appendChild($doc->createElement('Товары'));
        }
        while ($goods->firstChild) {
            $goods->removeChild($goods->firstChild);
        }
        $goods->appendChild($this->createProduct($doc, $this->normalizePayload($payload)));
        return $doc;
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
        $html = $this->sanitizeHtml((string)($payload['detail_html'] ?? $payload['result_detail_html'] ?? ''));
        return [
            'code' => (string)($payload['code'] ?? $payload['result_code'] ?? ''),
            'name' => (string)($payload['name'] ?? $payload['result_name'] ?? ''),
            'preview' => (string)($payload['preview_text'] ?? $payload['result_preview'] ?? ''),
            'detail' => $html,
            'section' => (string)($payload['section'] ?? $payload['article_section_id'] ?? ''),
            'primary_query' => (string)($payload['primary_query'] ?? ''),
            'secondary_queries' => $this->list($payload['secondary_queries'] ?? []),
            'search_intent' => (string)($payload['search_intent_xml_id'] ?? $payload['search_intent'] ?? ''),
            'short_answer' => (string)($payload['short_answer'] ?? $payload['result_short_answer'] ?? ''),
            'region' => (string)($payload['region_xml_id'] ?? $payload['region'] ?? ''),
            'article_template' => (string)($payload['article_template_xml_id'] ?? $payload['article_structure'] ?? 'default'),
            'author_id' => (string)($payload['author_id'] ?? ''),
            'medical_reviewer_id' => (string)($payload['medical_reviewer_id'] ?? ''),
            'medical_reviewed_at' => $this->date((string)($payload['medical_reviewed_at'] ?? 'today')),
            'content_updated_at' => $this->date((string)($payload['content_updated_at'] ?? 'today')),
            'sources' => $this->list($payload['sources'] ?? $payload['result_sources'] ?? []),
            'related_articles' => $this->related($payload['related_articles'] ?? []),
            'article_type' => (string)($payload['article_type_xml_id'] ?? $payload['article_type'] ?? 'article'),
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
        $this->property($doc, $props, 'ARTICLE_TYPE', [$p['article_type']]);
        $this->property($doc, $props, 'PRIMARY_QUERY', [$p['primary_query']]);
        $this->property($doc, $props, 'SECONDARY_QUERIES', $p['secondary_queries']);
        $this->property($doc, $props, 'SEARCH_INTENT', [$p['search_intent']]);
        $this->property($doc, $props, 'SHORT_ANSWER', [$p['short_answer']]);
        $this->property($doc, $props, 'REGION', [$p['region']]);
        $this->property($doc, $props, 'AUTHOR', [$p['author_id']]);
        $this->property($doc, $props, 'MEDICAL_REVIEWER', [$p['medical_reviewer_id']]);
        $this->property($doc, $props, 'MEDICAL_REVIEWED_AT', [$p['medical_reviewed_at']]);
        $this->property($doc, $props, 'CONTENT_UPDATED_AT', [$p['content_updated_at']]);
        $this->property($doc, $props, 'SOURCES', $p['sources']);
        $this->property($doc, $props, 'RELATED_ARTICLES', $p['related_articles']);
        $this->property($doc, $props, 'ARTICLE_TEMPLATE', [$p['article_template'] ?: 'default']);
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
        if ($value === '' || $value === 'today') return gmdate('d.m.Y');
        $ts = strtotime($value);
        return $ts ? gmdate('d.m.Y', $ts) : $value;
    }

    private function list(mixed $value): array
    {
        if (is_array($value)) return array_values(array_filter(array_map('strval', $value), static fn($v) => trim($v) !== ''));
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string)$value) ?: [])));
    }

    private function related(mixed $value): array
    {
        $items = is_array($value) ? $value : $this->list($value);
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $iblock = (int)($item['iblock_id'] ?? $item['source_iblock_id'] ?? 68);
                if ($iblock === 81) { $this->warnings[] = 'Связанная статья инфоблока 81 исключена из RELATED_ARTICLES.'; continue; }
                $id = (string)($item['element_id'] ?? $item['id'] ?? '');
            } else { $id = (string)$item; }
            if (preg_match('/^\d+$/', $id)) $out[] = $id;
        }
        return array_values(array_unique($out));
    }
}
