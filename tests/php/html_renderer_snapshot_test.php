<?php

declare(strict_types=1);

$lib = __DIR__ . '/../../local/api/seo/lib/';
require $lib . 'TextNormalizer.php';
require $lib . 'ContentReferenceResolver.php';
require $lib . 'ArticleContent.php';
require $lib . 'HtmlRenderer.php';

function assertTrue(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

final class SnapshotResolver implements ContentReferenceResolver
{
    public function doctorExists(int $id): bool { return false; }
    public function resolveDoctor(int $id): ?array { return null; }
    public function resolveService(int $id): ?array
    {
        return $id === 301 ? ['name' => 'МРТ', 'url' => '/service/mrt'] : null;
    }
}

$fixtureDir = __DIR__ . '/../fixtures/';
$data = json_decode((string)file_get_contents($fixtureDir . 'html-renderer-blocks.json'), true);
assertTrue(is_array($data), 'fixture loads');

$normalized = ArticleContent::normalize($data, new SnapshotResolver());
$html = (new HtmlRenderer(new SnapshotResolver()))->render($normalized, ['disclaimer' => 'Дисклеймер.']);

$expected = rtrim((string)file_get_contents($fixtureDir . 'html-renderer-expected.html'), "\n");

if ($html !== $expected) {
    fwrite(STDERR, "FAIL: HtmlRenderer snapshot mismatch\n");
    fwrite(STDERR, "--- got ---\n" . $html . "\n--- expected ---\n" . $expected . "\n");
    fwrite(STDERR, "Если изменение рендера намеренное — перегенерируйте tests/fixtures/html-renderer-expected.html.\n");
    exit(1);
}

fwrite(STDOUT, "HtmlRenderer snapshot: совпадает с фикстурой\n");
