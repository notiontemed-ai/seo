<?php

declare(strict_types=1);

require_once __DIR__ . '/../../local/api/seo/lib/TextNormalizer.php';
require_once __DIR__ . '/../../local/api/seo/lib/ContentReferenceResolver.php';
require_once __DIR__ . '/../../local/api/seo/lib/ArticleContent.php';
require_once __DIR__ . '/../../local/api/seo/lib/CannibalizationService.php';

function assertTrue(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

function findCandidate(array $result, int $elementId): ?array
{
    foreach ($result['candidates'] as $c) {
        if ($c['element_id'] === $elementId) {
            return $c;
        }
    }
    return null;
}

$config = ['iblocks' => ['articles' => 81, 'legacy_articles' => 68], 'base_url' => 'https://temed.ru'];

$corpus = [
    // 100 — точное совпадение primary query + интент → high
    [
        'source' => 'new', 'iblock_id' => 81, 'element_id' => 100, 'name' => 'Боль в спине: причины',
        'code' => 'back-pain', 'active' => 'Y', 'url' => '/back-pain', 'updated_at' => '2026-01-01',
        'preview_text' => '', 'short_answer' => '', 'detail_html' => 'Боль в спине бывает разной.',
        'primary_query' => 'боль в спине', 'secondary_queries' => ['лечение спины'], 'search_intent' => 'informational',
    ],
    // 200 — пересечение запросов частичное, другой интент → medium/low
    [
        'source' => 'new', 'iblock_id' => 81, 'element_id' => 200, 'name' => 'Массаж спины в клинике',
        'code' => 'massage', 'active' => 'Y', 'url' => '/massage', 'updated_at' => '2026-01-02',
        'preview_text' => '', 'short_answer' => '', 'detail_html' => 'Массаж помогает расслабить мышцы.',
        'primary_query' => 'массаж спины цена', 'secondary_queries' => [], 'search_intent' => 'commercial',
    ],
    // 300 — legacy, большое текстовое совпадение → high по text_overlap
    [
        'source' => 'legacy', 'iblock_id' => 68, 'element_id' => 300, 'name' => 'Старая статья',
        'code' => 'legacy-1', 'active' => 'Y', 'url' => '/legacy-1', 'updated_at' => '2025-01-01',
        'preview_text' => '', 'short_answer' => '',
        'detail_html' => 'Боль в спине возникает при перегрузке мышц и связок в поясничном отделе позвоночника у большинства взрослых людей после длительной сидячей работы или подъёма тяжестей.',
        'primary_query' => '', 'secondary_queries' => [], 'search_intent' => '',
    ],
    // 400 — нерелевантная статья → не попадает в кандидаты
    [
        'source' => 'new', 'iblock_id' => 81, 'element_id' => 400, 'name' => 'Лечение кариеса зубов',
        'code' => 'caries', 'active' => 'Y', 'url' => '/caries', 'updated_at' => '2026-01-03',
        'preview_text' => '', 'short_answer' => '', 'detail_html' => 'Кариес лечится пломбированием.',
        'primary_query' => 'лечение кариеса', 'secondary_queries' => [], 'search_intent' => 'commercial',
    ],
];

// ── Ввод как article_content блоки ──────────────────────────────────────────
$payload = [
    'primary_query' => 'боль в спине',
    'secondary_queries' => ['лечение спины'],
    'search_intent' => 'informational',
    'name' => 'Боль в спине: полный гид',
    'article_content' => [
        'blocks' => [
            ['type' => 'h2', 'text' => 'Что вызывает боль'],
            ['type' => 'p', 'text' => 'Боль в спине возникает при перегрузке мышц и связок в поясничном отделе позвоночника у большинства взрослых людей после длительной сидячей работы или подъёма тяжестей.'],
        ],
    ],
];

$result = (new CannibalizationService($config, $corpus))->check($payload);

assertTrue($result['algorithm_version'] === 'cannibalization-v1', 'algorithm_version set');
assertTrue(isset($result['uniqueness_percent']), 'uniqueness_percent kept for BC');
assertTrue($result['corpus']['total_articles'] === 4, 'corpus counted');

// 100: primary↔primary → high
$c100 = findCandidate($result, 100);
assertTrue($c100 !== null && $c100['risk'] === 'high', '100 primary↔primary → high');
assertTrue($c100['signals']['primary_primary'] === true, '100 primary_primary flag');
assertTrue($c100['signals']['intent_match'] === true, '100 intent_match');

// 300: big text overlap → high, fragments present
$c300 = findCandidate($result, 300);
assertTrue($c300 !== null && $c300['risk'] === 'high', '300 text overlap → high');
assertTrue($c300['signals']['text_overlap_percent'] > 20, '300 text_overlap_percent high');
assertTrue(count($c300['fragments']) >= 1, '300 has fragments');

// 400: irrelevant → excluded
assertTrue(findCandidate($result, 400) === null, '400 irrelevant excluded');

// sorted by risk desc (high before medium/low)
$risks = array_column($result['candidates'], 'risk');
$rankMap = ['high' => 3, 'medium' => 2, 'low' => 1];
$ranks = array_map(fn($r) => $rankMap[$r], $risks);
$sorted = $ranks;
rsort($sorted);
assertTrue($ranks === $sorted, 'candidates sorted by risk desc');

// ── exclude выбрасывает саму статью (self) ──────────────────────────────────
$payloadSelf = $payload + ['exclude' => ['source' => 'new', 'element_id' => 100]];
$resultSelf = (new CannibalizationService($config, $corpus))->check($payloadSelf);
assertTrue(findCandidate($resultSelf, 100) === null, 'exclude removes self');

// ── Пустой ввод без текста и без primary_query → исключение ──────────────────
$threw = false;
try {
    (new CannibalizationService($config, $corpus))->check(['name' => '']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
assertTrue($threw, 'empty input throws');

// ── Ввод как готовый текст (без блоков) ─────────────────────────────────────
$resultText = (new CannibalizationService($config, $corpus))->check([
    'primary_query' => 'массаж спины цена',
    'search_intent' => 'commercial',
    'name' => 'Массаж спины',
    'text' => 'Массаж помогает расслабить мышцы спины.',
]);
$c200 = findCandidate($resultText, 200);
assertTrue($c200 !== null && $c200['risk'] === 'high', '200 primary↔primary on text input → high');

fwrite(STDOUT, "CannibalizationService: все проверки пройдены\n");
