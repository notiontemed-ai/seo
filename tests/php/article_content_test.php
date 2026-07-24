<?php

declare(strict_types=1);

require_once __DIR__ . '/../../local/api/seo/lib/ContentReferenceResolver.php';
require_once __DIR__ . '/../../local/api/seo/lib/ArticleContent.php';
require_once __DIR__ . '/../../local/api/seo/lib/HtmlRenderer.php';
require_once __DIR__ . '/../../local/api/seo/lib/HtmlToBlocksParser.php';

function assertTrue(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

function assertContains(string $haystack, string $needle, string $message): void
{
    assertTrue(str_contains($haystack, $needle), $message . ' (ожидалось вхождение: ' . $needle . ')');
}

/** Резолвер-заглушка: врач 501 существует, услуга 301 существует. */
final class FakeResolver implements ContentReferenceResolver
{
    public function doctorExists(int $doctorId): bool
    {
        return $doctorId === 501;
    }

    public function resolveDoctor(int $doctorId): ?array
    {
        return $doctorId === 501
            ? ['name' => 'Иванов И.И.', 'position' => 'Невролог', 'photo_url' => '/photo.jpg', 'url' => '/doctor/ivanov']
            : null;
    }

    public function resolveService(int $serviceId): ?array
    {
        return $serviceId === 301
            ? ['name' => 'МРТ', 'url' => '/service/mrt']
            : null;
    }
}

// ── ArticleContent: базовая валидация и требования ──────────────────────────
$content = [
    'schema_version' => '2.0',
    'blocks' => [
        ['type' => 'short_answer', 'text' => 'Кратко о теме'],
        ['type' => 'h2', 'text' => 'Заголовок'],
        ['type' => 'p', 'text' => 'Абзац текста'],
        ['type' => 'unknown_xxx', 'text' => 'мусор'],
        ['type' => 'p', 'text' => '   '],
    ],
];
$norm = ArticleContent::normalize($content);
$types = array_column($norm['blocks'], 'type');
assertTrue($types === ['short_answer', 'h2', 'p'], 'valid blocks kept, unknown+empty dropped');
assertTrue($norm['short_answer'] === 'Кратко о теме', 'short_answer extracted');
$warnCodes = array_column($norm['warnings'], 'type');
assertTrue(in_array('unknown_block_type', $warnCodes, true), 'unknown block warned');
assertTrue(in_array('empty_block', $warnCodes, true), 'empty block warned');

// ── Требование хотя бы одного h2 и одного p ─────────────────────────────────
$norm = ArticleContent::normalize(['blocks' => [['type' => 'p', 'text' => 'только абзац']]]);
$warnCodes = array_column($norm['warnings'], 'type');
$missing = array_column(array_filter($norm['warnings'], fn($w) => $w['type'] === 'missing_required'), 'block_type');
assertTrue(in_array('h2', $missing, true), 'missing h2 warned');

// ── expert_opinion вне справочника отбрасывается ────────────────────────────
$norm = ArticleContent::normalize([
    'blocks' => [
        ['type' => 'h2', 'text' => 'H'],
        ['type' => 'p', 'text' => 'P'],
        ['type' => 'expert_opinion', 'doctor_id' => 999, 'quote' => 'Цитата'],
        ['type' => 'expert_opinion', 'doctor_id' => 501, 'quote' => 'Правильная цитата'],
    ],
], new FakeResolver());
$experts = array_filter($norm['blocks'], fn($b) => $b['type'] === 'expert_opinion');
assertTrue(count($experts) === 1, 'expert with unknown doctor dropped, valid kept');
assertTrue(in_array('doctor_not_found', array_column($norm['warnings'], 'type'), true), 'doctor_not_found warned');
assertTrue($norm['has_medical'] === true, 'expert_opinion marks has_medical');

// ── stats_highlight без источника невалиден ─────────────────────────────────
$norm = ArticleContent::normalize([
    'blocks' => [
        ['type' => 'h2', 'text' => 'H'],
        ['type' => 'p', 'text' => 'P'],
        ['type' => 'stats_highlight', 'value' => '70%', 'description' => 'пациентов'],
        ['type' => 'stats_highlight', 'value' => '80%', 'description' => 'случаев', 'source_index' => 0],
    ],
]);
$stats = array_values(array_filter($norm['blocks'], fn($b) => $b['type'] === 'stats_highlight'));
assertTrue(count($stats) === 1 && $stats[0]['value'] === '80%', 'stat without source dropped, with source kept');
assertTrue(in_array('missing_source', array_column($norm['warnings'], 'type'), true), 'missing_source warned');

// ── HtmlRenderer: оглавление, якоря, FAQ schema.org, дисклеймер ─────────────
$renderer = new HtmlRenderer(new FakeResolver());
$norm = ArticleContent::normalize([
    'blocks' => [
        ['type' => 'short_answer', 'text' => 'Ответ **сразу**'],
        ['type' => 'h2', 'text' => 'Первый раздел'],
        ['type' => 'p', 'text' => 'Текст со [ссылкой](https://temed.ru) и *курсивом*.'],
        ['type' => 'h2', 'text' => 'Второй раздел'],
        ['type' => 'expert_opinion', 'doctor_id' => 501, 'quote' => 'Мнение врача'],
        ['type' => 'diagnostics', 'items' => [['method' => 'МРТ', 'what_shows' => 'структуру', 'related_service_id' => 301]]],
        ['type' => 'faq', 'items' => [['q' => 'Вопрос?', 'a' => 'Ответ.']]],
        ['type' => 'stats_highlight', 'value' => '90%', 'description' => 'успех', 'source_index' => 0],
        ['type' => 'sources', 'items' => ['Клинические рекомендации, 2025']],
    ],
], new FakeResolver());
$html = $renderer->render($norm);
assertContains($html, 'class="article-short-answer"', 'short answer callout rendered');
assertContains($html, '<strong>сразу</strong>', 'inline bold in short answer');
assertContains($html, 'class="article-toc"', 'TOC rendered for 2+ headings');
assertContains($html, '<h2 id="section-1">', 'h2 anchor id');
assertContains($html, 'href="#section-1"', 'TOC links to anchor');
assertContains($html, '<a href="https://temed.ru">ссылкой</a>', 'inline link rendered');
assertContains($html, '<em>курсивом</em>', 'inline italic rendered');
assertContains($html, 'itemtype="https://schema.org/FAQPage"', 'FAQ schema.org microdata');
assertContains($html, 'itemprop="acceptedAnswer"', 'FAQ answer microdata');
assertContains($html, 'Иванов И.И.', 'expert doctor enriched by resolver');
assertContains($html, '<a href="/service/mrt">', 'diagnostics service link');
assertContains($html, 'href="#source-1"', 'stat links to source anchor');
assertContains($html, 'id="source-1"', 'source anchor id');
assertContains($html, 'article-disclaimer', 'disclaimer in footer');

// ── HtmlRenderer: инъекция HTML экранируется ────────────────────────────────
$norm = ArticleContent::normalize([
    'blocks' => [
        ['type' => 'h2', 'text' => 'H'],
        ['type' => 'p', 'text' => '<script>alert(1)</script> и [x](javascript:alert(1))'],
    ],
]);
$html = $renderer->render($norm);
assertTrue(!str_contains($html, '<script>'), 'script tag escaped, not emitted');
assertTrue(!str_contains($html, 'javascript:'), 'javascript: url rejected');

// ── HtmlToBlocksParser: HTML → блоки (обратная совместимость) ────────────────
$parser = new HtmlToBlocksParser();
$parsed = $parser->parse(
    '<h2>Заголовок</h2><p>Абзац с <strong>жирным</strong> и <a href="/x">ссылкой</a>.</p>'
    . '<ul><li>Раз</li><li>Два</li></ul>'
    . '<table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>'
    . '<figure><figcaption>подпись</figcaption></figure>'
);
$ptypes = array_column($parsed['blocks'], 'type');
assertTrue(in_array('h2', $ptypes, true), 'parser: h2');
assertTrue(in_array('list', $ptypes, true), 'parser: list');
assertTrue(in_array('table', $ptypes, true), 'parser: table');
assertTrue(in_array('raw_html', $ptypes, true), 'parser: unknown → raw_html');
$pBlock = array_values(array_filter($parsed['blocks'], fn($b) => $b['type'] === 'p'))[0];
assertContains($pBlock['text'], '**жирным**', 'parser: strong → markdown bold');
assertContains($pBlock['text'], '[ссылкой](/x)', 'parser: a → markdown link');
assertTrue(in_array('unrecognized_element', array_column($parsed['warnings'], 'type'), true), 'parser warns on raw_html');

// ── Round-trip: parse(render(blocks)) сохраняет структуру ────────────────────
$norm = ArticleContent::normalize([
    'blocks' => [
        ['type' => 'h2', 'text' => 'Раздел'],
        ['type' => 'p', 'text' => 'Простой абзац'],
        ['type' => 'list', 'ordered' => false, 'items' => ['Один', 'Два']],
    ],
]);
$reparsed = $parser->parse((new HtmlRenderer())->render($norm, ['disclaimer' => '']));
$rtypes = array_column($reparsed['blocks'], 'type');
assertContains(implode(',', $rtypes), 'h2', 'round-trip keeps h2');
assertContains(implode(',', $rtypes), 'list', 'round-trip keeps list');

// ── Каталог блоков: 14 смысловых + базовые, raw_html скрыт ───────────────────
$catalog = ArticleContent::catalog();
$catalogTypes = array_column($catalog, 'type');
assertTrue(!in_array('raw_html', $catalogTypes, true), 'raw_html hidden from catalog');
foreach (['short_answer', 'expert_opinion', 'case_study', 'symptoms', 'diagnostics', 'treatment_methods', 'faq', 'comparison_table', 'myth_fact', 'stats_highlight', 'appointment_form', 'sources', 'when_to_see_doctor', 'causes'] as $t) {
    assertTrue(in_array($t, $catalogTypes, true), "catalog has semantic block: {$t}");
}
$medical = array_column(array_filter($catalog, fn($b) => $b['medical']), 'type');
sort($medical);
assertTrue($medical === ['case_study', 'expert_opinion', 'stats_highlight', 'symptoms'], 'medical flags: ' . implode(',', $medical));

fwrite(STDOUT, "ArticleContent/HtmlRenderer/HtmlToBlocksParser: все проверки пройдены\n");
