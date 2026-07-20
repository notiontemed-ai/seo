<?php

declare(strict_types=1);

final class InternalUniquenessService
{
    public const ALGORITHM_VERSION = 'internal-shingles-v1';
    public function __construct(private array $config, private ArticleCorpusRepository $repo) {}
    public function check(array $payload): array
    {
        $rawLen = strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        if ($rawLen > 350000) throw new InvalidArgumentException('Слишком большой payload');
        $article = $payload['article'] ?? null;
        if (!is_array($article)) throw new InvalidArgumentException('article должен быть объектом');
        $opts = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $shingle = max(3, min(10, (int)($opts['shingle_size'] ?? 5)));
        $minWords = max(8, min(50, (int)($opts['min_fragment_words'] ?? 12)));
        $maxMatches = max(1, min(50, (int)($opts['max_matches'] ?? 20)));
        $exclude = is_array($payload['exclude'] ?? null) ? $payload['exclude'] : [];
        $excludeSource = in_array(($exclude['source'] ?? ''), ['new','legacy'], true) ? (string)$exclude['source'] : '';
        $excludeId = isset($exclude['element_id']) && (int)$exclude['element_id'] > 0 ? (int)$exclude['element_id'] : 0;
        $input = TextNormalizer::normalize(implode(' ', [(string)($article['name'] ?? ''),(string)($article['preview_text'] ?? ''),(string)($article['short_answer'] ?? ''),(string)($article['detail_html'] ?? '')]));
        if ($input['text'] === '') throw new InvalidArgumentException('Текст статьи пуст после нормализации');
        $inputShingles = $this->shingles($input['words'], $shingle);
        $coverage = [];$matches=[];$meta=['name'=>[],'preview_text'=>[],'short_answer'=>[]];$counts=['new_articles'=>0,'legacy_articles'=>0,'total_articles'=>0];
        foreach ($this->repo->getArticles() as $doc) {
            $counts[$doc['source']==='new'?'new_articles':'legacy_articles']++; $counts['total_articles']++;
            if ($excludeSource === $doc['source'] && $excludeId === (int)$doc['element_id']) continue;
            $docNorm = TextNormalizer::normalize($doc['name'].' '.$doc['preview_text'].' '.$doc['short_answer'].' '.$doc['detail_html']);
            $docSet = array_flip(array_keys($this->shingles($docNorm['words'], $shingle)));
            $positions=[];
            foreach ($inputShingles as $hash=>$posList) if (isset($docSet[$hash])) foreach ($posList as $p) $positions[]=$p;
            sort($positions); $positions=array_values(array_unique($positions));
            foreach (['name','preview_text','short_answer'] as $field) {
                $a=TextNormalizer::normalize((string)($article[$field] ?? '')); $b=TextNormalizer::normalize((string)($doc[$field] ?? ''));
                if ($a['text']!=='' && $a['hash']===$b['hash']) $meta[$field][]=['source'=>$doc['source'],'element_id'=>$doc['element_id'],'name'=>$doc['name']];
            }
            if (!$positions) continue;
            $ranges=$this->ranges($positions,$shingle); foreach($ranges as $r) for($i=$r[0];$i<=$r[1];$i++) $coverage[$i]=true;
            $frags=[]; foreach(array_slice($ranges,0,10) as $r){$wc=$r[1]-$r[0]+1;if($wc >= $minWords)$frags[]=['text'=>implode(' ',array_slice($input['words'],$r[0],$wc)),'word_count'=>$wc,'requires_manual_medical_review'=>true];}
            $matches[]=['source'=>$doc['source'],'iblock_id'=>$doc['iblock_id'],'element_id'=>$doc['element_id'],'name'=>$doc['name'],'code'=>$doc['code'],'active'=>$doc['active'],'url'=>$doc['url'],'absolute_url'=>function_exists('buildAbsoluteUrl')?buildAbsoluteUrl($doc['url'],$this->config):$doc['url'],'updated_at'=>$doc['updated_at'],'matched_percent'=>round(count($positions)/max(1,count($inputShingles))*100,2),'matched_shingles'=>count($positions),'fragments'=>$frags];
        }
        usort($matches, fn($a,$b)=>[$b['matched_percent'],$b['matched_shingles'],$b['updated_at']] <=> [$a['matched_percent'],$a['matched_shingles'],$a['updated_at']]);
        $matchedPercent = round(count($coverage)/max(1,count($input['words']))*100,2);
        return ['status'=>'completed','algorithm_version'=>self::ALGORITHM_VERSION,'content_hash'=>$input['hash'],'checked_at'=>date(DATE_ATOM),'uniqueness_percent'=>round(max(0,100-$matchedPercent),2),'matched_percent'=>$matchedPercent,'matched_shingles'=>array_sum(array_column($matches,'matched_shingles')),'total_shingles'=>count($inputShingles),'corpus'=>$counts,'matches'=>array_slice($matches,0,$maxMatches),'metadata_duplicates'=>$meta,'warnings'=>['Фрагменты медицинского содержания требуют ручной проверки; автоматическое переписывание не выполняется.']];
    }
    private function shingles(array $words,int $n): array { $out=[]; for($i=0;$i<=count($words)-$n;$i++){ $out[hash('sha256',implode(' ',array_slice($words,$i,$n)))][]=$i; } return $out; }
    private function ranges(array $positions,int $n): array { $ranges=[]; foreach($positions as $p){$r=[$p,$p+$n-1]; if($ranges && $r[0] <= $ranges[count($ranges)-1][1]+1) $ranges[count($ranges)-1][1]=max($ranges[count($ranges)-1][1],$r[1]); else $ranges[]=$r;} return $ranges; }
}
