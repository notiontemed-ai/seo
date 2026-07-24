<?php

declare(strict_types=1);

/**
 * Перелинковка: поиск статей-доноров для анкоров на редактируемую (целевую)
 * статью. Результат — данные для ТЗ контент-менеджеру (что и где вставить).
 * Автоматическая правка доноров не выполняется.
 *
 * Доноры — только АКТИВНЫЕ элементы инфоблоков 81 и 68 (должны быть
 * опубликованы). Релевантность считается теми же лексическими сигналами, что и
 * в отчёте каннибализации (TextSignals).
 */
final class LinkingService
{
    public const ALGORITHM_VERSION = 'linking-v1';

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
        $limit = max(1, min(50, (int)($payload['limit'] ?? 20)));
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : $payload;

        $targetElementId = (int)($article['element_id'] ?? $payload['element_id'] ?? 0);
        $targetCode = trim((string)($article['code'] ?? $payload['code'] ?? ''));
        $targetName = (string)($article['name'] ?? $payload['name'] ?? '');
        $primaryQuery = (string)($article['primary_query'] ?? $payload['primary_query'] ?? '');
        $secondaryQueries = $this->toList($article['secondary_queries'] ?? $payload['secondary_queries'] ?? []);

        $bodyText = '';
        if (is_array($article['article_content'] ?? null)) {
            $bodyText = ArticleContent::toPlainText($article['article_content']);
        } elseif (is_array($payload['article_content'] ?? null)) {
            $bodyText = ArticleContent::toPlainText($payload['article_content']);
        }
        if ($bodyText === '') {
            $bodyText = (string)($article['text'] ?? $article['detail_html'] ?? '');
        }

        // Разрешаем цель по корпусу (для already_linked и исключения самой статьи).
        $target = $this->resolveTarget($targetElementId, $targetCode);

        $targetWords = TextSignals::wordSet([$targetName, $primaryQuery, implode(' ', $secondaryQueries), $bodyText]);
        $targetQuerySet = TextSignals::wordSet(array_merge([$primaryQuery], $secondaryQueries));
        $targetQueryBigrams = TextSignals::bigramSet(array_merge([$primaryQuery], $secondaryQueries));
        $targetNameSet = TextSignals::wordSet([$targetName]);

        $anchorOptions = $this->anchorOptions($primaryQuery, $secondaryQueries);
        $parser = new DonorLinkParser();

        $candidates = [];
        $counts = ['new_articles' => 0, 'legacy_articles' => 0, 'active_scanned' => 0];

        foreach ($this->corpus as $doc) {
            $counts[$doc['source'] === 'new' ? 'new_articles' : 'legacy_articles']++;

            // Доноры должны быть опубликованы.
            if (strtoupper((string)($doc['active'] ?? '')) !== 'Y') {
                continue;
            }
            // Не предлагаем саму целевую статью.
            if ($this->isSameAsTarget($doc, $target, $targetElementId, $targetCode)) {
                continue;
            }
            $counts['active_scanned']++;

            $docQuerySet = TextSignals::wordSet(array_merge(
                [(string)($doc['primary_query'] ?? '')],
                $this->toList($doc['secondary_queries'] ?? [])
            ));
            $docQueryBigrams = TextSignals::bigramSet(array_merge(
                [(string)($doc['primary_query'] ?? '')],
                $this->toList($doc['secondary_queries'] ?? [])
            ));
            $docNameSet = TextSignals::wordSet([(string)($doc['name'] ?? '')]);
            $docWords = TextSignals::wordSet([
                (string)($doc['name'] ?? ''),
                (string)($doc['preview_text'] ?? ''),
                (string)($doc['detail_html'] ?? ''),
            ]);

            $queryOverlap = max(
                TextSignals::jaccard($targetQuerySet, $docQuerySet),
                TextSignals::jaccard($targetQueryBigrams, $docQueryBigrams)
            );
            $titleSim = TextSignals::jaccard($targetNameSet, $docNameSet);
            $textOverlap = TextSignals::jaccard($targetWords, $docWords);

            $relevance = round(0.5 * $queryOverlap + 0.3 * $titleSim + 0.2 * $textOverlap, 4);
            if ($relevance < 0.1) {
                continue;
            }

            $links = $parser->parse((string)($doc['detail_html'] ?? ''), $target ?? []);
            $insertion = $this->pickInsertion($links['paragraphs'], $targetWords, $anchorOptions);

            $candidates[] = [
                'element_id' => (int)($doc['element_id'] ?? 0),
                'source' => (string)($doc['source'] ?? ''),
                'name' => (string)($doc['name'] ?? ''),
                'url' => (string)($doc['url'] ?? ''),
                'absolute_url' => function_exists('buildAbsoluteUrl')
                    ? buildAbsoluteUrl((string)($doc['url'] ?? ''), $this->config)
                    : (string)($doc['url'] ?? ''),
                'admin_url' => $this->adminUrl((int)($doc['iblock_id'] ?? 0), (int)($doc['element_id'] ?? 0)),
                'relevance' => $relevance,
                'signals' => [
                    'query_overlap' => round($queryOverlap, 3),
                    'title_similarity' => round($titleSim, 3),
                    'text_overlap' => round($textOverlap, 3),
                ],
                'link_load' => DonorLinkParser::linkLoad($links['links_in_text']),
                'links_in_text' => $links['links_in_text'],
                'found_links' => $links['found_links'],
                'already_linked' => $links['already_linked'],
                'insertion' => $insertion,
            ];
        }

        usort($candidates, static fn(array $a, array $b): int => $b['relevance'] <=> $a['relevance']);

        return [
            'status' => 'completed',
            'algorithm_version' => self::ALGORITHM_VERSION,
            'checked_at' => date(DATE_ATOM),
            'target' => [
                'element_id' => $target !== null ? $target['element_id'] : $targetElementId,
                'code' => $target !== null ? $target['code'] : $targetCode,
                'name' => $targetName,
                'resolved' => $target !== null,
            ],
            'corpus' => $counts,
            'anchor_options' => $anchorOptions,
            'candidates' => array_slice($candidates, 0, $limit),
            'notice' => 'Автоматическая правка доноров не выполняется — результат для ТЗ контент-менеджеру.',
        ];
    }

    /**
     * @param array<int,string> $paragraphs
     * @param array<string,int> $targetWords
     * @param array<int,string> $anchorOptions
     * @return array{paragraph_quote:string,position_hint:string,overlap:float,anchor_options:array<int,string>}
     */
    private function pickInsertion(array $paragraphs, array $targetWords, array $anchorOptions): array
    {
        $best = '';
        $bestScore = -1.0;
        $bestIndex = -1;
        foreach ($paragraphs as $index => $paragraph) {
            $score = TextSignals::jaccard($targetWords, TextSignals::wordSet([$paragraph]));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $paragraph;
                $bestIndex = $index;
            }
        }

        return [
            'paragraph_quote' => mb_substr($best, 0, 400, 'UTF-8'),
            'position_hint' => $bestIndex >= 0 ? 'Абзац №' . ($bestIndex + 1) . ' по тексту донора' : '',
            'overlap' => round(max(0.0, $bestScore), 3),
            'anchor_options' => $anchorOptions,
        ];
    }

    /**
     * @param array<int,string> $secondaryQueries
     * @return array<int,string>
     */
    private function anchorOptions(string $primaryQuery, array $secondaryQueries): array
    {
        $options = [];
        foreach (array_merge([$primaryQuery], $secondaryQueries) as $query) {
            $query = trim($query);
            if ($query !== '' && !in_array($query, $options, true)) {
                $options[] = $query;
            }
        }
        return array_slice($options, 0, 3);
    }

    /**
     * @return array{element_id:int,code:string,source:string,url:string,absolute_url:string}|null
     */
    private function resolveTarget(int $elementId, string $code): ?array
    {
        foreach ($this->corpus as $doc) {
            $matchesId = $elementId > 0 && (int)($doc['element_id'] ?? 0) === $elementId;
            $matchesCode = $code !== '' && (string)($doc['code'] ?? '') === $code;
            if ($matchesId || $matchesCode) {
                $url = (string)($doc['url'] ?? '');
                return [
                    'element_id' => (int)($doc['element_id'] ?? 0),
                    'code' => (string)($doc['code'] ?? ''),
                    'source' => (string)($doc['source'] ?? ''),
                    'url' => $url,
                    'absolute_url' => function_exists('buildAbsoluteUrl')
                        ? buildAbsoluteUrl($url, $this->config)
                        : $url,
                ];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $doc
     * @param array{element_id:int,code:string,source:string}|null $target
     */
    private function isSameAsTarget(array $doc, ?array $target, int $targetElementId, string $targetCode): bool
    {
        if ($target !== null) {
            return (int)($doc['element_id'] ?? 0) === $target['element_id']
                && (string)($doc['source'] ?? '') === $target['source'];
        }
        if ($targetElementId > 0 && (int)($doc['element_id'] ?? 0) === $targetElementId) {
            return true;
        }
        if ($targetCode !== '' && (string)($doc['code'] ?? '') === $targetCode) {
            return true;
        }
        return false;
    }

    private function adminUrl(int $iblockId, int $elementId): string
    {
        $base = rtrim((string)($this->config['base_url'] ?? ''), '/');
        $query = http_build_query(
            ['IBLOCK_ID' => $iblockId, 'ID' => $elementId, 'lang' => 'ru'],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        return $base . '/bitrix/admin/iblock_element_edit.php?' . $query;
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
}
