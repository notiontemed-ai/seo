<?php

declare(strict_types=1);

final class ArticleCorpusRepository
{
    public function __construct(private array $config) {}

    public function getArticles(): array
    {
        $items = [];
        foreach (['new' => 'articles', 'legacy' => 'legacy_articles'] as $source => $key) {
            $iblockId = (int)($this->config['iblocks'][$key] ?? 0);
            if ($iblockId <= 0 || !class_exists('CIBlockElement')) continue;
            $select = ['ID','IBLOCK_ID','NAME','CODE','ACTIVE','DETAIL_PAGE_URL','PREVIEW_TEXT','DETAIL_TEXT','TIMESTAMP_X'];
            $res = CIBlockElement::GetList(['TIMESTAMP_X' => 'DESC'], ['IBLOCK_ID' => $iblockId], false, false, $select);
            while ($row = $res->GetNext()) {
                $short = '';
                if ($source === 'new') {
                    $props = CIBlockElement::GetProperty($iblockId, (int)$row['ID'], [], ['CODE' => 'SHORT_ANSWER']);
                    if ($prop = $props->Fetch()) $short = (string)($prop['VALUE']['TEXT'] ?? $prop['VALUE'] ?? '');
                }
                $items[] = [
                    'source'=>$source,'iblock_id'=>(int)$row['IBLOCK_ID'],'element_id'=>(int)$row['ID'],'name'=>(string)$row['NAME'],
                    'code'=>(string)$row['CODE'],'active'=>(string)$row['ACTIVE'],'url'=>(string)($row['DETAIL_PAGE_URL'] ?? ''),
                    'preview_text'=>(string)($row['PREVIEW_TEXT'] ?? ''),'detail_html'=>(string)($row['DETAIL_TEXT'] ?? ''),
                    'short_answer'=>$short,'updated_at'=>(string)($row['TIMESTAMP_X'] ?? ''),
                ];
            }
        }
        return $items;
    }
}
