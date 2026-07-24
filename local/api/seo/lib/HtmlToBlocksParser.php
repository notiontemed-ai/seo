<?php

declare(strict_types=1);

/**
 * Обратная совместимость: разбор существующего DETAIL_TEXT (HTML) в блоки
 * `article_content` v2 по эвристикам. Нераспознанные узлы становятся блоком
 * raw_html с предупреждением и правятся в редакторе как текст.
 */
final class HtmlToBlocksParser
{
    /**
     * @return array{schema_version:string,blocks:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>}
     */
    public function parse(string $html): array
    {
        $html = trim($html);
        $blocks = [];
        $warnings = [];

        if ($html === '') {
            return ['schema_version' => ArticleContent::SCHEMA_VERSION, 'blocks' => [], 'warnings' => []];
        }

        $dom = new DOMDocument();
        $prevErrors = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>';
        $dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        $root = $dom->getElementById('__root__');
        if ($root === null) {
            return [
                'schema_version' => ArticleContent::SCHEMA_VERSION,
                'blocks' => [['type' => 'raw_html', 'html' => $html]],
                'warnings' => [['type' => 'unparsed_html', 'message' => 'HTML не удалось разобрать, сохранён как raw_html']],
            ];
        }

        foreach ($root->childNodes as $node) {
            $this->handleNode($node, $blocks, $warnings);
        }

        return [
            'schema_version' => ArticleContent::SCHEMA_VERSION,
            'blocks' => $blocks,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,array<string,mixed>> $warnings
     */
    private function handleNode(DOMNode $node, array &$blocks, array &$warnings): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim((string)$node->textContent);
            if ($text !== '') {
                $blocks[] = ['type' => 'p', 'text' => $text];
            }
            return;
        }

        if (!($node instanceof DOMElement)) {
            return;
        }

        $tag = strtolower($node->tagName);

        switch ($tag) {
            case 'h1':
            case 'h2':
                $this->pushText($blocks, 'h2', $node);
                return;
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $this->pushText($blocks, 'h3', $node);
                return;
            case 'p':
                $this->pushText($blocks, 'p', $node);
                return;
            case 'blockquote':
                $this->pushText($blocks, 'p', $node);
                return;
            case 'ul':
            case 'ol':
                $items = $this->listItems($node);
                if ($items !== []) {
                    $blocks[] = ['type' => 'list', 'ordered' => $tag === 'ol', 'items' => $items];
                }
                return;
            case 'table':
                $table = $this->parseTable($node);
                if ($table !== null) {
                    $blocks[] = $table;
                }
                return;
            case 'br':
                return;
            case 'div':
            case 'section':
            case 'article':
                // Контейнер: спускаемся внутрь, если есть блочные потомки.
                if ($this->hasBlockChildren($node)) {
                    foreach ($node->childNodes as $child) {
                        $this->handleNode($child, $blocks, $warnings);
                    }
                    return;
                }
                $text = trim((string)$node->textContent);
                if ($text !== '') {
                    $blocks[] = ['type' => 'p', 'text' => $this->inlineMarkdown($node)];
                }
                return;
        }

        // Нераспознанный элемент → raw_html с предупреждением.
        $rawHtml = $this->outerHtml($node);
        if (trim(strip_tags($rawHtml)) === '') {
            return;
        }
        $blocks[] = ['type' => 'raw_html', 'html' => $rawHtml];
        $warnings[] = [
            'type' => 'unrecognized_element',
            'block_type' => 'raw_html',
            'message' => 'Тег <' . $tag . '> не распознан, сохранён как raw_html для ручной правки',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     */
    private function pushText(array &$blocks, string $type, DOMElement $node): void
    {
        $text = trim($this->inlineMarkdown($node));
        if ($text !== '') {
            $blocks[] = ['type' => $type, 'text' => $text];
        }
    }

    /** @return array<int,string> */
    private function listItems(DOMElement $node): array
    {
        $items = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $text = trim($this->inlineMarkdown($child));
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }
        return $items;
    }

    /** @return array<string,mixed>|null */
    private function parseTable(DOMElement $node): ?array
    {
        $header = [];
        $rows = [];

        $trNodes = $node->getElementsByTagName('tr');
        foreach ($trNodes as $tr) {
            $cells = [];
            $isHeader = false;
            foreach ($tr->childNodes as $cell) {
                if (!($cell instanceof DOMElement)) {
                    continue;
                }
                $cellTag = strtolower($cell->tagName);
                if ($cellTag !== 'td' && $cellTag !== 'th') {
                    continue;
                }
                if ($cellTag === 'th') {
                    $isHeader = true;
                }
                $cells[] = trim($this->inlineMarkdown($cell));
            }
            if ($cells === []) {
                continue;
            }
            if ($isHeader && $header === []) {
                $header = $cells;
            } else {
                $rows[] = $cells;
            }
        }

        if ($rows === [] && $header === []) {
            return null;
        }

        return ['type' => 'table', 'header' => $header, 'rows' => $rows];
    }

    private function hasBlockChildren(DOMElement $node): bool
    {
        $blockTags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'ul', 'ol', 'table', 'blockquote', 'div', 'section', 'article'];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), $blockTags, true)) {
                return true;
            }
        }
        return false;
    }

    /** Инлайн-содержимое узла → текст с ограниченным markdown. */
    private function inlineMarkdown(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $out .= (string)$child->textContent;
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);
            $inner = $this->inlineMarkdown($child);
            switch ($tag) {
                case 'strong':
                case 'b':
                    $out .= '**' . trim($inner) . '**';
                    break;
                case 'em':
                case 'i':
                    $out .= '*' . trim($inner) . '*';
                    break;
                case 'a':
                    $href = trim($child->getAttribute('href'));
                    $out .= $href !== '' ? '[' . trim($inner) . '](' . $href . ')' : $inner;
                    break;
                case 'br':
                    $out .= ' ';
                    break;
                default:
                    $out .= $inner;
            }
        }

        return preg_replace('~[ \t]+~u', ' ', $out) ?? $out;
    }

    private function outerHtml(DOMElement $node): string
    {
        return (string)$node->ownerDocument->saveHTML($node);
    }
}
