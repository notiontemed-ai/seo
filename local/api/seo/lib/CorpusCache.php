<?php

declare(strict_types=1);

/**
 * Кэш корпуса статей на диск (runtime/corpus-cache.json, TTL из config.cache_ttl,
 * по умолчанию 1 час). ArticleCorpusRepository перечитывает все элементы на
 * каждый запрос — это главный тормоз проверок; кэш снимает его.
 */
final class CorpusCache
{
    public function __construct(
        private array $config,
        private ArticleCorpusRepository $repo
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getArticles(bool $forceRefresh = false): array
    {
        $ttl = (int)($this->config['cache_ttl'] ?? 3600);
        $file = $this->cacheFile();

        if (!$forceRefresh && $ttl > 0 && is_file($file)) {
            $age = time() - (int)@filemtime($file);
            if ($age >= 0 && $age < $ttl) {
                $data = json_decode((string)@file_get_contents($file), true);
                if (
                    is_array($data)
                    && ($data['signature'] ?? '') === $this->signature()
                    && is_array($data['articles'] ?? null)
                ) {
                    return $data['articles'];
                }
            }
        }

        $articles = $this->repo->getArticles();
        $this->write($file, [
            'signature' => $this->signature(),
            'generated_at' => date(DATE_ATOM),
            'count' => count($articles),
            'articles' => $articles,
        ]);

        return $articles;
    }

    /** Метаданные кэша (для отладки), без самого корпуса. */
    public function meta(): array
    {
        $file = $this->cacheFile();
        if (!is_file($file)) {
            return ['cached' => false];
        }
        $age = time() - (int)@filemtime($file);
        return [
            'cached' => true,
            'age_seconds' => max(0, $age),
            'ttl' => (int)($this->config['cache_ttl'] ?? 3600),
            'fresh' => $age < (int)($this->config['cache_ttl'] ?? 3600),
        ];
    }

    private function signature(): string
    {
        return md5(json_encode([
            'v' => 1,
            'articles' => (int)($this->config['iblocks']['articles'] ?? 0),
            'legacy' => (int)($this->config['iblocks']['legacy_articles'] ?? 0),
        ]));
    }

    private function cacheFile(): string
    {
        return dirname(__DIR__) . '/runtime/corpus-cache.json';
    }

    /** @param array<string,mixed> $data */
    private function write(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $file,
            (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
