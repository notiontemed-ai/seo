<?php

declare(strict_types=1);

/**
 * Единый отчёт «Каннибализация»: текстовые пересечения + пересечения запросов +
 * интент. Данные — только из Bitrix (инфоблоки 81 и 68). Расширяет прежний
 * internal_uniqueness: сохраняет метрики uniqueness_percent для совместимости и
 * добавляет интегральный risk по каждой статье корпуса.
 */
final class CannibalizationService
{
    public const ALGORITHM_VERSION = 'cannibalization-v1';
    public const TEXT_ALGORITHM_VERSION = 'internal-shingles-v1';

    /**
     * @param array<int,array<string,mixed>> $corpus
     */
    public function __construct(private array $config, private array $corpus)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function check(array $payload): array
    {
        $shingle = 5;
        $minWords = 12;
        $maxMatches = max(1, min(100, (int)($payload['max_matches'] ?? 50)));

        $article = is_array($payload['article'] ?? null) ? $payload['article'] : $payload;

        // Сборка текста статьи: из article_content (блоки) либо из готового текста.
        $bodyText = '';
        if (is_array($article['article_content'] ?? null)) {
            $bodyText = ArticleContent::toPlainText($article['article_content']);
        } elseif (is_array($payload['article_content'] ?? null)) {
            $bodyText = ArticleContent::toPlainText($payload['article_content']);
        }
        if ($bodyText === '') {
            $bodyText = (string)($article['text'] ?? $article['detail_html'] ?? $article['detail_text'] ?? '');
        }

        $name = (string)($article['name'] ?? $payload['name'] ?? '');
        $shortAnswer = (string)($article['short_answer'] ?? '');
        $primaryQuery = (string)($article['primary_query'] ?? $payload['primary_query'] ?? '');
        $secondaryQueries = $this->toList($article['secondary_queries'] ?? $payload['secondary_queries'] ?? []);
        $searchIntent = mb_strtolower(trim((string)($article['search_intent'] ?? $payload['search_intent'] ?? '')), 'UTF-8');

        $input = TextNormalizer::normalize(implode(' ', [$name, $shortAnswer, $bodyText]));
        if ($input['text'] === '' && $primaryQuery === '') {
            throw new InvalidArgumentException('Нужен текст статьи или primary_query');
        }

        $inputPrimaryWords = $this->words($primaryQuery);
        $inputQueryWords = $this->wordSet(array_merge([$primaryQuery], $secondaryQueries));
        $inputQueryBigrams = $this->bigramSet(array_merge([$primaryQuery], $secondaryQueries));
        $inputNameWords = $this->wordSet([$name]);

        $inputShingles = $this->shingles($input['words'], $shingle);

        $exclude = is_array($payload['exclude'] ?? null) ? $payload['exclude'] : [];
        $excludeSource = in_array(($exclude['source'] ?? ''), ['new', 'legacy'], true) ? (string)$exclude['source'] : '';
        $excludeId = (int)($exclude['element_id'] ?? 0);

        $candidates = [];
        $coverage = [];
        $counts = ['new_articles' => 0, 'legacy_articles' => 0, 'total_articles' => 0];

        foreach ($this->corpus as $doc) {
            $counts[$doc['source'] === 'new' ? 'new_articles' : 'legacy_articles']++;
            $counts['total_articles']++;

            if ($excludeSource === ($doc['source'] ?? '') && $excludeId === (int)($doc['element_id'] ?? 0)) {
                continue;
            }

            // — text_overlap (шинглы) —
            $docNorm = TextNormalizer::normalize(
                (string)($doc['name'] ?? '') . ' '
                . (string)($doc['preview_text'] ?? '') . ' '
                . (string)($doc['short_answer'] ?? '') . ' '
                . (string)($doc['detail_html'] ?? '')
            );
            $docSet = array_flip(array_keys($this->shingles($docNorm['words'], $shingle)));
            $positions = [];
            foreach ($inputShingles as $hash => $posList) {
                if (isset($docSet[$hash])) {
                    foreach ($posList as $p) {
                        $positions[] = $p;
                    }
                }
            }
            sort($positions);
            $positions = array_values(array_unique($positions));
            $textPct = round(count($positions) / max(1, count($inputShingles)) * 100, 2);

            $fragments = [];
            if ($positions !== []) {
                $ranges = $this->ranges($positions, $shingle);
                foreach ($ranges as $r) {
                    for ($i = $r[0]; $i <= $r[1]; $i++) {
                        $coverage[$i] = true;
                    }
                }
                foreach (array_slice($ranges, 0, 10) as $r) {
                    $wc = $r[1] - $r[0] + 1;
                    if ($wc >= $minWords) {
                        $fragments[] = [
                            'text' => implode(' ', array_slice($input['words'], $r[0], $wc)),
                            'word_count' => $wc,
                            'requires_manual_medical_review' => true,
                        ];
                    }
                }
            }

            // — query_overlap / intent / title —
            $docPrimaryWords = $this->words((string)($doc['primary_query'] ?? ''));
            $docQueryWords = $this->wordSet(array_merge(
                [(string)($doc['primary_query'] ?? '')],
                $this->toList($doc['secondary_queries'] ?? [])
            ));
            $docQueryBigrams = $this->bigramSet(array_merge(
                [(string)($doc['primary_query'] ?? '')],
                $this->toList($doc['secondary_queries'] ?? [])
            ));

            $primaryPrimary = $inputPrimaryWords !== [] && $docPrimaryWords !== []
                && $this->jaccard($this->flip($inputPrimaryWords), $this->flip($docPrimaryWords)) >= 0.6;
            $queryOverlap = max(
                $this->jaccard($inputQueryWords, $docQueryWords),
                $this->jaccard($inputQueryBigrams, $docQueryBigrams)
            );
            $docIntent = mb_strtolower(trim((string)($doc['search_intent'] ?? '')), 'UTF-8');
            $intentMatch = $doc['source'] === 'new' && $searchIntent !== '' && $searchIntent === $docIntent;
            $titleSim = $this->jaccard($inputNameWords, $this->wordSet([(string)($doc['name'] ?? '')]));

            $risk = $this->integralRisk($primaryPrimary, $queryOverlap, $intentMatch, $titleSim, (float)$textPct);
            $score = $this->score($primaryPrimary, $queryOverlap, $intentMatch, $titleSim, (float)$textPct);

            // Порог отсечения шума: одиночное пересечение частого слова между
            // короткими запросами даёт ~0.14–0.16 — такие статьи не показываем.
            if ($risk === 'low' && $score < 0.2) {
                continue;
            }

            $candidates[] = [
                'source' => $doc['source'],
                'element_id' => (int)($doc['element_id'] ?? 0),
                'iblock_id' => (int)($doc['iblock_id'] ?? 0),
                'name' => (string)($doc['name'] ?? ''),
                'code' => (string)($doc['code'] ?? ''),
                'active' => (string)($doc['active'] ?? ''),
                'url' => (string)($doc['url'] ?? ''),
                'absolute_url' => function_exists('buildAbsoluteUrl')
                    ? buildAbsoluteUrl((string)($doc['url'] ?? ''), $this->config)
                    : (string)($doc['url'] ?? ''),
                'updated_at' => (string)($doc['updated_at'] ?? ''),
                'risk' => $risk,
                'signals' => [
                    'query_overlap' => round($queryOverlap, 3),
                    'primary_primary' => $primaryPrimary,
                    'intent_match' => $intentMatch,
                    'title_similarity' => round($titleSim, 3),
                    'text_overlap_percent' => $textPct,
                    'matched_shingles' => count($positions),
                ],
                'fragments' => $fragments,
                '_score' => $score,
            ];
        }

        $rank = ['high' => 3, 'medium' => 2, 'low' => 1];
        usort($candidates, static function (array $a, array $b) use ($rank): int {
            return [$rank[$b['risk']], $b['_score']] <=> [$rank[$a['risk']], $a['_score']];
        });

        $summary = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($candidates as &$candidate) {
            $summary[$candidate['risk']]++;
            unset($candidate['_score']);
        }
        unset($candidate);

        $matchedPercent = round(count($coverage) / max(1, count($input['words'])) * 100, 2);

        return [
            'status' => 'completed',
            'algorithm_version' => self::ALGORITHM_VERSION,
            'text_algorithm_version' => self::TEXT_ALGORITHM_VERSION,
            'content_hash' => $input['hash'],
            'checked_at' => date(DATE_ATOM),
            'corpus' => $counts,
            'risk_summary' => $summary,
            'candidates' => array_slice($candidates, 0, $maxMatches),
            // Совместимость с прежним internal_uniqueness.
            'uniqueness_percent' => round(max(0, 100 - $matchedPercent), 2),
            'matched_percent' => $matchedPercent,
            'total_shingles' => count($inputShingles),
            'warnings' => ['Фрагменты медицинского содержания требуют ручной проверки; автоматическое переписывание не выполняется.'],
        ];
    }

    private function integralRisk(bool $primaryPrimary, float $queryOverlap, bool $intentMatch, float $titleSim, float $textPct): string
    {
        if ($primaryPrimary) {
            return 'high';
        }
        if ($textPct >= 20.0) {
            return 'high';
        }
        if ($queryOverlap >= 0.5 && $intentMatch) {
            return 'high';
        }
        if (
            $queryOverlap >= 0.5
            || $titleSim >= 0.5
            || $textPct >= 8.0
            || ($queryOverlap >= 0.3 && $intentMatch)
        ) {
            return 'medium';
        }
        return 'low';
    }

    private function score(bool $primaryPrimary, float $queryOverlap, bool $intentMatch, float $titleSim, float $textPct): float
    {
        $base = max($queryOverlap, $titleSim, $textPct / 100);
        return round($base + ($intentMatch ? 0.1 : 0) + ($primaryPrimary ? 1.0 : 0), 4);
    }

    /** @return array<int,string> */
    private function words(string $text): array
    {
        $norm = TextNormalizer::normalize($text);
        return $norm['words'];
    }

    /**
     * @param array<int,string> $strings
     * @return array<string,int>
     */
    private function wordSet(array $strings): array
    {
        $words = $this->words(implode(' ', $strings));
        return $this->flip($words);
    }

    /**
     * @param array<int,string> $strings
     * @return array<string,int>
     */
    private function bigramSet(array $strings): array
    {
        $set = [];
        foreach ($strings as $string) {
            $words = $this->words($string);
            $count = count($words);
            for ($i = 0; $i < $count - 1; $i++) {
                $set[$words[$i] . ' ' . $words[$i + 1]] = 1;
            }
        }
        return $set;
    }

    /**
     * @param array<int,string> $words
     * @return array<string,int>
     */
    private function flip(array $words): array
    {
        return $words === [] ? [] : array_fill_keys($words, 1);
    }

    /**
     * @param array<string,int> $a
     * @param array<string,int> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect_key($a, $b));
        $union = count($a + $b);
        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function toList($value): array
    {
        if (is_string($value)) {
            $value = preg_split('~[\r\n]+~', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $s = trim((string)$item);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<int,string> $words
     * @return array<string,array<int,int>>
     */
    private function shingles(array $words, int $n): array
    {
        $out = [];
        $count = count($words);
        for ($i = 0; $i <= $count - $n; $i++) {
            $out[hash('sha256', implode(' ', array_slice($words, $i, $n)))][] = $i;
        }
        return $out;
    }

    /**
     * @param array<int,int> $positions
     * @return array<int,array{0:int,1:int}>
     */
    private function ranges(array $positions, int $n): array
    {
        $ranges = [];
        foreach ($positions as $p) {
            $r = [$p, $p + $n - 1];
            if ($ranges && $r[0] <= $ranges[count($ranges) - 1][1] + 1) {
                $ranges[count($ranges) - 1][1] = max($ranges[count($ranges) - 1][1], $r[1]);
            } else {
                $ranges[] = $r;
            }
        }
        return $ranges;
    }
}
