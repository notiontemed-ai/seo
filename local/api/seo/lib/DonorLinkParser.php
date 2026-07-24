<?php

declare(strict_types=1);

/**
 * Разбор DETAIL_TEXT статьи-донора: подсчёт «текстовых» ссылок для светофора
 * нагрузки и извлечение абзацев-кандидатов для вставки.
 *
 * Считаются только ссылки в тексте — `<a>` внутри `<p>` и ячеек таблиц
 * (`<td>/<th>`). Не считаются: ссылки в списках; блоки, чей ближайший
 * предыдущий заголовок/абзац матчится с /рекоменду|читайте также|похожие|
 * источник/i; блок источников; свойства RELATED_* (их в DETAIL_TEXT нет).
 */
final class DonorLinkParser
{
    private const EXCLUDE_REGEX = '~рекоменду|читайте\s+также|похожие|источник~iu';

    /**
     * @param array{url?:string,absolute_url?:string,code?:string} $target
     * @return array{links_in_text:int,found_links:array<int,array{anchor:string,href:string}>,already_linked:bool,paragraphs:array<int,string>}
     */
    public function parse(string $html, array $target = []): array
    {
        $html = trim($html);
        $result = ['links_in_text' => 0, 'found_links' => [], 'already_linked' => false, 'paragraphs' => []];
        if ($html === '') {
            return $result;
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*');
        if ($elements === false) {
            return $result;
        }

        $targetNeedles = $this->targetNeedles($target);
        $recommend = false;

        foreach ($elements as $el) {
            if (!($el instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($el->tagName);

            // Документоориентированное отслеживание «рекомендательного» региона:
            // заголовок задаёт новую секцию, абзац-ярлык включает исключение.
            if (preg_match('~^h[1-6]$~', $tag)) {
                $recommend = $this->matchesExclude((string)$el->textContent);
                continue;
            }
            if ($tag === 'p') {
                $text = trim((string)$el->textContent);
                if ($this->isLabel($text) && $this->matchesExclude($text)) {
                    $recommend = true;
                }
                // Абзац-кандидат для вставки: контентный, вне рекоменд/источников.
                if (!$recommend && !$this->inSourcesRegion($el) && mb_strlen($text, 'UTF-8') >= 40) {
                    $result['paragraphs'][] = $text;
                }
            }

            if ($tag !== 'a') {
                continue;
            }

            $href = trim($el->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            if (!$result['already_linked'] && $this->hrefMatchesTarget($href, $targetNeedles)) {
                $result['already_linked'] = true;
            }

            // «Текстовая» ссылка: ближайший блочный предок — p / td / th.
            $block = $this->nearestBlock($el);
            if ($block === null || !in_array($block, ['p', 'td', 'th'], true)) {
                continue;
            }
            if ($recommend || $this->inSourcesRegion($el)) {
                continue;
            }

            $result['found_links'][] = [
                'anchor' => trim((string)$el->textContent),
                'href' => $href,
            ];
            $result['links_in_text']++;
        }

        return $result;
    }

    /** Светофор нагрузки донора по числу текстовых ссылок. */
    public static function linkLoad(int $linksInText): string
    {
        if ($linksInText > 5) {
            return 'red';
        }
        if ($linksInText >= 3) {
            return 'yellow';
        }
        return 'green';
    }

    private function nearestBlock(DOMElement $el): ?string
    {
        $node = $el->parentNode;
        while ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['p', 'td', 'th', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                return $tag;
            }
            $node = $node->parentNode;
        }
        return null;
    }

    private function inSourcesRegion(DOMElement $el): bool
    {
        $node = $el;
        while ($node instanceof DOMElement) {
            $class = strtolower($node->getAttribute('class'));
            if (str_contains($class, 'sources') || str_contains($class, 'article-sources')) {
                return true;
            }
            $node = $node->parentNode;
        }
        return false;
    }

    private function matchesExclude(string $text): bool
    {
        return preg_match(self::EXCLUDE_REGEX, $text) === 1;
    }

    private function isLabel(string $text): bool
    {
        return mb_strlen($text, 'UTF-8') < 80 || str_ends_with(rtrim($text), ':');
    }

    /** @return array<int,string> */
    private function targetNeedles(array $target): array
    {
        $needles = [];
        foreach (['url', 'absolute_url', 'code'] as $key) {
            $value = trim((string)($target[$key] ?? ''));
            if ($value !== '' && $value !== '/') {
                $needles[] = $value;
            }
        }
        return $needles;
    }

    /** @param array<int,string> $needles */
    private function hrefMatchesTarget(string $href, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($href, $needle)) {
                return true;
            }
        }
        return false;
    }
}
