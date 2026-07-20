<?php

declare(strict_types=1);

final class BitrixArticleXmlExporter
{
    public function export(array $payload): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->appendChild($doc->createElement('temed_bitrix_articles'));
        $root->appendChild($doc->createElement('iblock_id', '81'));
        $element = $root->appendChild($doc->createElement('element'));

        $fields = [
            'ACTIVE' => 'N',
            'NAME' => (string)$payload['name'],
            'CODE' => (string)$payload['code'],
            'IBLOCK_SECTION_ID' => (string)$payload['section'],
            'PREVIEW_TEXT' => (string)($payload['preview_text'] ?? ''),
            'DETAIL_TEXT_TYPE' => 'html',
            'DETAIL_TEXT' => (string)$payload['detail_html'],
            'SEO_TITLE' => (string)($payload['seo_title'] ?? ''),
            'META_DESCRIPTION' => (string)($payload['meta_description'] ?? ''),
        ];
        $fieldsNode = $element->appendChild($doc->createElement('fields'));
        foreach ($fields as $name => $value) {
            $node = $fieldsNode->appendChild($doc->createElement(strtolower($name)));
            $node->appendChild($doc->createCDATASection($this->safeCdata($value)));
        }

        $properties = [
            'ARTICLE_STRUCTURE' => $payload['article_structure'] ?? '',
            'ARTICLE_STRUCTURE_NAME' => $payload['article_structure_name'] ?? '',
            'ARTICLE_STRUCTURE_VERSION' => $payload['article_structure_version'] ?? '',
            'SEARCH_INTENT' => $payload['search_intent'] ?? '',
            'ARTICLE_TYPE' => $payload['article_type'] ?? '',
            'AUTHOR' => $payload['author_id'] ?? '',
            'MEDICAL_REVIEWER' => $payload['medical_reviewer_id'] ?? '',
            'REGION' => $payload['region'] ?? '',
            'RELATED_ARTICLES' => $payload['related_articles'] ?? [],
        ];
        $propsNode = $element->appendChild($doc->createElement('properties'));
        foreach ($properties as $code => $value) {
            $prop = $propsNode->appendChild($doc->createElement('property'));
            $prop->setAttribute('code', $code);
            foreach ((array)$value as $item) {
                $prop->appendChild($doc->createElement('value'))->appendChild($doc->createTextNode((string)$item));
            }
        }

        return $doc;
    }

    public function filename(array $payload): string
    {
        $code = preg_replace('/[^a-z0-9_-]+/i', '-', (string)($payload['code'] ?? 'bitrix-article')) ?: 'bitrix-article';
        return strtolower(trim($code, '-')) . '-' . gmdate('Ymd') . '.xml';
    }

    private function safeCdata(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }
}
