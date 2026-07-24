<?php

declare(strict_types=1);

require_once __DIR__ . '/../../local/api/seo/lib/TextNormalizer.php';
require_once __DIR__ . '/../../local/api/seo/lib/TextSignals.php';
require_once __DIR__ . '/../../local/api/seo/lib/ContentReferenceResolver.php';
require_once __DIR__ . '/../../local/api/seo/lib/ArticleContent.php';
require_once __DIR__ . '/../../local/api/seo/lib/DonorLinkParser.php';
require_once __DIR__ . '/../../local/api/seo/lib/LinkingService.php';

function assertTrue(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

// ── DonorLinkParser: подсчёт текстовых ссылок ───────────────────────────────
$parser = new DonorLinkParser();

// Ссылки в <p> и в ячейках таблицы считаются; в «Рекомендуем также», списках и
// источниках — нет.
$html = <<<HTML
<h2>Основной раздел</h2>
<p>Первый абзац со <a href="/method-a">ссылкой на метод</a> в тексте.</p>
<p>Второй абзац с <a href="/method-b">другой ссылкой</a> тоже считается.</p>
<table>
  <tr><th>Метод <a href="/th-link">в шапке</a></th><td>Значение с <a href="/td-link">ссылкой</a></td></tr>
</table>
<h3>Рекомендуем также</h3>
<ul>
  <li><a href="/rec-1">Рекомендация 1</a></li>
  <li><a href="/rec-2">Рекомендация 2</a></li>
</ul>
<p>Читайте также: <a href="/read-also">похожая статья</a></p>
<section class="article-sources">
  <h2>Источники</h2>
  <ol><li><a href="/src-1">Источник 1</a></li></ol>
</section>
HTML;

$res = $parser->parse($html);
// Считаются: 2 в <p> + 1 в <th> + 1 в <td> = 4. Рекомендуем/Читайте/источники — нет.
assertTrue($res['links_in_text'] === 4, 'text links counted = 4, got ' . $res['links_in_text']);
$hrefs = array_column($res['found_links'], 'href');
assertTrue(in_array('/method-a', $hrefs, true) && in_array('/td-link', $hrefs, true), 'p and td links present');
assertTrue(in_array('/th-link', $hrefs, true), 'th link present');
assertTrue(!in_array('/rec-1', $hrefs, true), 'recommend list link excluded');
assertTrue(!in_array('/read-also', $hrefs, true), 'читайте также paragraph excluded');
assertTrue(!in_array('/src-1', $hrefs, true), 'sources link excluded');

// ── Светофор нагрузки ───────────────────────────────────────────────────────
assertTrue(DonorLinkParser::linkLoad(2) === 'green', 'green <3');
assertTrue(DonorLinkParser::linkLoad(3) === 'yellow', 'yellow 3');
assertTrue(DonorLinkParser::linkLoad(5) === 'yellow', 'yellow 5');
assertTrue(DonorLinkParser::linkLoad(6) === 'red', 'red >5');

// ── already_linked: донор уже ссылается на цель ─────────────────────────────
$res = $parser->parse(
    '<p>Смотрите <a href="/target-article">целевую статью</a> подробнее.</p>',
    ['url' => '/target-article', 'code' => 'target-article']
);
assertTrue($res['already_linked'] === true, 'already_linked detected');
assertTrue($res['links_in_text'] === 1, 'the existing link still counted');

$res = $parser->parse('<p>Ссылка на <a href="/other">другое</a>.</p>', ['url' => '/target-article']);
assertTrue($res['already_linked'] === false, 'not linked when href differs');

// ── LinkingService: только активные доноры, релевантность, вставка ───────────
$config = ['iblocks' => ['articles' => 81, 'legacy_articles' => 68], 'base_url' => 'https://temed.ru'];
$corpus = [
    // Релевантный активный донор
    [
        'source' => 'new', 'iblock_id' => 81, 'element_id' => 10, 'name' => 'Лечение боли в спине',
        'code' => 'back-pain-treatment', 'active' => 'Y', 'url' => '/back-pain-treatment', 'updated_at' => '2026-01-01',
        'preview_text' => '', 'short_answer' => '',
        'detail_html' => '<p>Боль в спине лечится разными методами, важно вовремя обратиться к врачу при первых симптомах перегрузки мышц.</p><p>Массаж и ЛФК помогают восстановлению.</p>',
        'primary_query' => 'лечение боли в спине', 'secondary_queries' => ['боль в пояснице'], 'search_intent' => 'informational',
    ],
    // Неактивный донор — исключается
    [
        'source' => 'new', 'iblock_id' => 81, 'element_id' => 20, 'name' => 'Боль в спине черновик',
        'code' => 'draft', 'active' => 'N', 'url' => '/draft', 'updated_at' => '2026-01-02',
        'preview_text' => '', 'short_answer' => '', 'detail_html' => '<p>Боль в спине черновик про лечение спины.</p>',
        'primary_query' => 'боль в спине', 'secondary_queries' => [], 'search_intent' => 'informational',
    ],
    // Нерелевантный активный донор
    [
        'source' => 'new', 'iblock_id' => 81, 'element_id' => 30, 'name' => 'Отбеливание зубов',
        'code' => 'whitening', 'active' => 'Y', 'url' => '/whitening', 'updated_at' => '2026-01-03',
        'preview_text' => '', 'short_answer' => '', 'detail_html' => '<p>Отбеливание зубов проводится в клинике безопасно.</p>',
        'primary_query' => 'отбеливание зубов', 'secondary_queries' => [], 'search_intent' => 'commercial',
    ],
];

$payload = [
    'element_id' => 999,
    'name' => 'Боль в спине: причины и лечение',
    'primary_query' => 'боль в спине',
    'secondary_queries' => ['лечение спины'],
    'article_content' => ['blocks' => [
        ['type' => 'h2', 'text' => 'Лечение'],
        ['type' => 'p', 'text' => 'Боль в спине лечится методами восстановления мышц и обращением к врачу.'],
    ]],
    'limit' => 10,
];

$result = (new LinkingService($config, $corpus))->check($payload);
$ids = array_column($result['candidates'], 'element_id');
assertTrue(in_array(10, $ids, true), 'relevant active donor included');
assertTrue(!in_array(20, $ids, true), 'inactive donor excluded');
assertTrue(!in_array(30, $ids, true), 'irrelevant donor excluded');

$donor = $result['candidates'][0];
assertTrue($donor['element_id'] === 10, 'top candidate is the relevant donor');
assertTrue($donor['link_load'] === 'green', 'donor has green load (0 text links... actually 0)');
assertTrue(str_contains($donor['admin_url'], 'IBLOCK_ID=81'), 'admin_url present');
assertTrue($donor['insertion']['paragraph_quote'] !== '', 'insertion paragraph chosen');
assertTrue($donor['insertion']['anchor_options'] === ['боль в спине', 'лечение спины'], 'anchor options from queries');
assertTrue($result['anchor_options'] === ['боль в спине', 'лечение спины'], 'top-level anchor options');

// ── Самоисключение цели по element_id ───────────────────────────────────────
$corpusSelf = array_merge($corpus, [[
    'source' => 'new', 'iblock_id' => 81, 'element_id' => 999, 'name' => 'Боль в спине сама',
    'code' => 'self', 'active' => 'Y', 'url' => '/self', 'updated_at' => '2026-01-04',
    'preview_text' => '', 'short_answer' => '', 'detail_html' => '<p>Боль в спине лечение спины.</p>',
    'primary_query' => 'боль в спине', 'secondary_queries' => ['лечение спины'], 'search_intent' => 'informational',
]]);
$resultSelf = (new LinkingService($config, $corpusSelf))->check($payload);
assertTrue(!in_array(999, array_column($resultSelf['candidates'], 'element_id'), true), 'target itself excluded');

fwrite(STDOUT, "DonorLinkParser/LinkingService: все проверки пройдены\n");
