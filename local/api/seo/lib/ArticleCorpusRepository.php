<?php

declare(strict_types=1);

final class ArticleCorpusRepository
{
    public function __construct(private array $config) {}

    /**
     * Корпус статей для проверок каннибализации/уникальности.
     * Для инфоблока 81 добавляются SEO-сигналы (PRIMARY_QUERY,
     * SECONDARY_QUERIES, SEARCH_INTENT); у legacy (68) SEO-свойств нет.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getArticles(): array
    {
        $items = [];
        foreach (['new' => 'articles', 'legacy' => 'legacy_articles'] as $source => $key) {
            $iblockId = (int)($this->config['iblocks'][$key] ?? 0);
            if ($iblockId <= 0 || !class_exists('CIBlockElement')) continue;
            $select = ['ID','IBLOCK_ID','NAME','CODE','ACTIVE','DETAIL_PAGE_URL','PREVIEW_TEXT','DETAIL_TEXT','TIMESTAMP_X'];
            $res = CIBlockElement::GetList(['TIMESTAMP_X' => 'DESC'], ['IBLOCK_ID' => $iblockId], false, false, $select);
            while ($row = $res->GetNext()) {
                $seo = ['short_answer' => '', 'primary_query' => '', 'secondary_queries' => [], 'search_intent' => ''];
                if ($source === 'new') {
                    $seo = $this->readSeoProps($iblockId, (int)$row['ID']) + $seo;
                }
                $items[] = [
                    'source'=>$source,'iblock_id'=>(int)$row['IBLOCK_ID'],'element_id'=>(int)$row['ID'],'name'=>(string)$row['NAME'],
                    'code'=>(string)$row['CODE'],'active'=>(string)$row['ACTIVE'],'url'=>(string)($row['DETAIL_PAGE_URL'] ?? ''),
                    'preview_text'=>(string)($row['PREVIEW_TEXT'] ?? ''),'detail_html'=>(string)($row['DETAIL_TEXT'] ?? ''),
                    'short_answer'=>(string)$seo['short_answer'],'primary_query'=>(string)$seo['primary_query'],
                    'secondary_queries'=>is_array($seo['secondary_queries']) ? $seo['secondary_queries'] : [],
                    'search_intent'=>(string)$seo['search_intent'],'updated_at'=>(string)($row['TIMESTAMP_X'] ?? ''),
                ];
            }
        }
        return $items;
    }

    /**
     * SEO-свойства одного элемента одним проходом по GetProperty.
     *
     * @return array{short_answer:string,primary_query:string,secondary_queries:array<int,string>,search_intent:string}
     */
    private function readSeoProps(int $iblockId, int $elementId): array
    {
        $out = ['short_answer' => '', 'primary_query' => '', 'secondary_queries' => [], 'search_intent' => ''];
        $props = CIBlockElement::GetProperty($iblockId, $elementId, [], []);
        while ($prop = $props->Fetch()) {
            $code = (string)($prop['CODE'] ?? '');
            $value = $prop['VALUE'] ?? '';
            if (is_array($value)) {
                $value = (string)($value['TEXT'] ?? reset($value) ?? '');
            } else {
                $value = (string)$value;
            }
            $value = trim($value);
            if ($value === '') continue;
            switch ($code) {
                case 'SHORT_ANSWER':
                    if ($out['short_answer'] === '') $out['short_answer'] = $value;
                    break;
                case 'PRIMARY_QUERY':
                    if ($out['primary_query'] === '') $out['primary_query'] = $value;
                    break;
                case 'SECONDARY_QUERIES':
                    $out['secondary_queries'][] = $value;
                    break;
                case 'SEARCH_INTENT':
                    if ($out['search_intent'] === '') {
                        $xmlId = trim((string)($prop['VALUE_XML_ID'] ?? ''));
                        $out['search_intent'] = $xmlId !== '' ? $xmlId : $value;
                    }
                    break;
            }
        }
        return $out;
    }
}
