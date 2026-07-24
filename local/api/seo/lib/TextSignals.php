<?php

declare(strict_types=1);

/**
 * Общие лексические сигналы (слова, биграммы, Jaccard) для проверок
 * каннибализации и перелинковки. Нормализация — через TextNormalizer.
 */
final class TextSignals
{
    /** @return array<int,string> */
    public static function words(string $text): array
    {
        return TextNormalizer::normalize($text)['words'];
    }

    /**
     * @param array<int,string>|string $strings
     * @return array<string,int>
     */
    public static function wordSet($strings): array
    {
        $strings = is_array($strings) ? $strings : [$strings];
        $words = self::words(implode(' ', $strings));
        return $words === [] ? [] : array_fill_keys($words, 1);
    }

    /**
     * @param array<int,string>|string $strings
     * @return array<string,int>
     */
    public static function bigramSet($strings): array
    {
        $strings = is_array($strings) ? $strings : [$strings];
        $set = [];
        foreach ($strings as $string) {
            $words = self::words((string)$string);
            $count = count($words);
            for ($i = 0; $i < $count - 1; $i++) {
                $set[$words[$i] . ' ' . $words[$i + 1]] = 1;
            }
        }
        return $set;
    }

    /**
     * @param array<string,int> $a
     * @param array<string,int> $b
     */
    public static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect_key($a, $b));
        $union = count($a + $b);
        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
