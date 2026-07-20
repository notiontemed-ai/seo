<?php

declare(strict_types=1);

final class TextNormalizer
{
    public static function normalize(string $html): array
    {
        $text = preg_replace('~<(script|style|iframe|form|noscript|svg)\b[^>]*>.*?</\1>~isu', ' ', $html) ?? $html;
        $text = preg_replace('~</?(p|div|br|hr|li|ul|ol|table|tr|td|th|h[1-6]|blockquote|section|article|header|footer)\b[^>]*>~iu', ' ', $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace('ё', 'е', $text);
        $text = strtr($text, ["—"=>'-', "–"=>'-', "−"=>'-', "“"=>'"', "”"=>'"', "«"=>'"', "»"=>'"', "’"=>"'"]);
        $text = preg_replace('~[^\p{L}\p{N}\+\#\.%\-/\s]+~u', ' ', $text) ?? $text;
        $text = preg_replace('~\s+~u', ' ', trim($text)) ?? trim($text);
        $words = $text === '' ? [] : preg_split('~\s+~u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return ['text' => $text, 'words' => $words ?: [], 'hash' => hash('sha256', $text)];
    }
}
