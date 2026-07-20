<?php

declare(strict_types=1);

final class ArticleContentNormalizer
{
    public static function normalizeForTextRu(array $article): array
    {
        $text = self::buildText($article);
        return ['text' => $text, 'hash' => hash('sha256', $text)];
    }

    public static function normalizeForInternal(array $article): array
    {
        $normalized = self::normalizeForTextRu($article);
        $text = str_replace(['Ё', 'ё'], ['Е', 'е'], $normalized['text']);
        $text = mb_strtolower($text, 'UTF-8');
        return ['text' => $text, 'hash' => hash('sha256', $text), 'words' => self::words($text)];
    }

    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), $matches);
        return $matches[0] ?? [];
    }

    private static function buildText(array $article): string
    {
        $parts = [
            (string)($article['name'] ?? $article['result_name'] ?? ''),
            (string)($article['preview_text'] ?? $article['result_preview'] ?? ''),
            (string)($article['short_answer'] ?? $article['result_short_answer'] ?? ''),
            self::htmlToText((string)($article['detail_html'] ?? $article['result_detail_html'] ?? '')),
        ];

        return self::normalizeWhitespace(html_entity_decode(implode("\n\n", $parts), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style|iframe|form)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<\/?(p|div|section|article|header|footer|h[1-6]|li|tr|br)\b[^>]*>/i', "\n", $html) ?? $html;
        return strip_tags($html);
    }

    private static function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/[\t\r ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n\s+/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }
}
