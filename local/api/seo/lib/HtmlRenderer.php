<?php

declare(strict_types=1);

/**
 * Детерминированная сборка HTML (DETAIL_TEXT) из блоков `article_content` v2.
 *
 * Единые правила рендера — этот же алгоритм портируется в JS для предпросмотра
 * в React (этап 5). Запрещённые теги невозможны by design (генерим сами);
 * санитайзер оставлен как страховка для inline-ссылок и raw_html.
 *
 * Шаблон берётся из ARTICLE_TEMPLATE — пока поддержан один: `default`.
 */
final class HtmlRenderer
{
    private const DEFAULT_DISCLAIMER =
        'Материал носит справочный характер, не заменяет очную консультацию '
        . 'врача и не является основанием для самодиагностики или самолечения. '
        . 'При наличии симптомов обратитесь к специалисту.';

    private int $anchorCounter = 0;

    public function __construct(private ?ContentReferenceResolver $resolver = null)
    {
    }

    /**
     * @param array{blocks:array<int,array<string,mixed>>,short_answer?:string} $normalized
     * @param array<string,mixed> $options template, disclaimer, form
     */
    public function render(array $normalized, array $options = []): string
    {
        $this->anchorCounter = 0;
        $blocks = $normalized['blocks'] ?? [];

        // Присвоить якоря заголовкам и собрать оглавление.
        $toc = [];
        foreach ($blocks as $i => $block) {
            $type = (string)($block['type'] ?? '');
            if ($type === 'h2' || $type === 'h3') {
                $anchor = 'section-' . (++$this->anchorCounter);
                $blocks[$i]['_anchor'] = $anchor;
                $toc[] = ['level' => $type, 'text' => (string)$block['text'], 'anchor' => $anchor];
            }
        }

        $parts = [];

        // 1. Краткий ответ — выделенная плашка в начале статьи.
        $shortAnswer = trim((string)($normalized['short_answer'] ?? ''));
        if ($shortAnswer !== '') {
            $parts[] = '<div class="article-short-answer"><strong>Кратко:</strong> '
                . $this->inline($shortAnswer) . '</div>';
        }

        // 2. Оглавление по h2/h3 (при двух и более заголовках).
        if (count($toc) >= 2) {
            $parts[] = $this->renderToc($toc);
        }

        // 3. Тело статьи.
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'short_answer') {
                continue; // уже вынесен наверх плашкой
            }
            $html = $this->renderBlock($block, $options);
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        // 4. Дисклеймер в подвале.
        $disclaimer = trim((string)($options['disclaimer'] ?? self::DEFAULT_DISCLAIMER));
        if ($disclaimer !== '') {
            $parts[] = '<div class="article-disclaimer"><p>' . $this->esc($disclaimer) . '</p></div>';
        }

        return implode("\n", $parts);
    }

    /** @param array<int,array{level:string,text:string,anchor:string}> $toc */
    private function renderToc(array $toc): string
    {
        $items = [];
        foreach ($toc as $entry) {
            $cls = $entry['level'] === 'h3' ? ' class="toc-sub"' : '';
            $items[] = '<li' . $cls . '><a href="#' . $this->esc($entry['anchor']) . '">'
                . $this->inline($entry['text']) . '</a></li>';
        }

        return '<nav class="article-toc" aria-label="Содержание"><ol>'
            . implode('', $items) . '</ol></nav>';
    }

    /**
     * @param array<string,mixed> $block
     * @param array<string,mixed> $options
     */
    private function renderBlock(array $block, array $options): string
    {
        $type = (string)($block['type'] ?? '');

        switch ($type) {
            case 'h2':
                return '<h2 id="' . $this->esc((string)($block['_anchor'] ?? '')) . '">'
                    . $this->inline((string)$block['text']) . '</h2>';

            case 'h3':
                return '<h3 id="' . $this->esc((string)($block['_anchor'] ?? '')) . '">'
                    . $this->inline((string)$block['text']) . '</h3>';

            case 'p':
                return '<p>' . $this->inline((string)$block['text']) . '</p>';

            case 'list':
                return $this->renderList($block['items'] ?? [], (bool)($block['ordered'] ?? false));

            case 'table':
                return $this->renderTable($block['header'] ?? [], $block['rows'] ?? []);

            case 'expert_opinion':
                return $this->renderExpertOpinion($block);

            case 'case_study':
                return $this->renderCaseStudy($block);

            case 'symptoms':
                return $this->renderSymptoms($block['items'] ?? []);

            case 'when_to_see_doctor':
                return '<div class="article-callout article-when-to-see-doctor">'
                    . '<p class="callout-title">Когда обратиться к врачу</p>'
                    . $this->renderList($block['items'] ?? [], false) . '</div>';

            case 'causes':
                return '<div class="article-causes"><h3>Причины и факторы риска</h3>'
                    . $this->renderList($block['items'] ?? [], false) . '</div>';

            case 'diagnostics':
                return $this->renderMethods($block['items'] ?? [], 'Диагностика');

            case 'treatment_methods':
                return $this->renderMethods($block['items'] ?? [], 'Методы лечения');

            case 'faq':
                return $this->renderFaq($block['items'] ?? []);

            case 'comparison_table':
                return $this->renderComparison($block);

            case 'myth_fact':
                return $this->renderMythFact($block['items'] ?? []);

            case 'stats_highlight':
                return $this->renderStat($block);

            case 'appointment_form':
                return $this->renderForm($options);

            case 'sources':
                return $this->renderSources($block['items'] ?? []);

            case 'raw_html':
                return '<div class="article-raw-html" data-warning="Импортированный HTML требует проверки">'
                    . $this->sanitizeRawHtml((string)($block['html'] ?? '')) . '</div>';
        }

        return '';
    }

    /** @param array<int,string> $items */
    private function renderList(array $items, bool $ordered): string
    {
        if ($items === []) {
            return '';
        }
        $tag = $ordered ? 'ol' : 'ul';
        $li = '';
        foreach ($items as $item) {
            $li .= '<li>' . $this->inline((string)$item) . '</li>';
        }
        return "<{$tag}>{$li}</{$tag}>";
    }

    /**
     * @param array<int,string> $header
     * @param array<int,array<int,string>> $rows
     */
    private function renderTable(array $header, array $rows): string
    {
        $html = '<table>';
        if ($header !== []) {
            $html .= '<thead><tr>';
            foreach ($header as $cell) {
                $html .= '<th>' . $this->inline((string)$cell) . '</th>';
            }
            $html .= '</tr></thead>';
        }
        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $this->inline((string)$cell) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** @param array<string,mixed> $block */
    private function renderExpertOpinion(array $block): string
    {
        $doctorId = (int)($block['doctor_id'] ?? 0);
        $quote = $this->esc((string)($block['quote'] ?? ''));
        $doctor = $this->resolver?->resolveDoctor($doctorId);

        $byline = '';
        if ($doctor !== null) {
            $photo = trim((string)$doctor['photo_url']);
            if ($photo !== '') {
                $byline .= '<img class="expert-photo" src="' . $this->esc($photo) . '" alt="'
                    . $this->esc((string)$doctor['name']) . '" loading="lazy">';
            }
            $name = (string)$doctor['name'];
            $url = trim((string)$doctor['url']);
            $nameHtml = $url !== ''
                ? '<a href="' . $this->esc($url) . '">' . $this->esc($name) . '</a>'
                : $this->esc($name);
            $byline .= '<div class="expert-meta"><span class="expert-name">' . $nameHtml . '</span>';
            $position = trim((string)$doctor['position']);
            if ($position !== '') {
                $byline .= '<span class="expert-position">' . $this->esc($position) . '</span>';
            }
            $byline .= '</div>';
        }

        return '<figure class="article-expert-opinion">' . $byline
            . '<blockquote>' . $quote . '</blockquote></figure>';
    }

    /** @param array<string,mixed> $block */
    private function renderCaseStudy(array $block): string
    {
        $rows = [
            'Контекст пациента' => (string)($block['patient_context'] ?? ''),
            'Ситуация' => (string)($block['situation'] ?? ''),
            'Что сделали' => (string)($block['actions'] ?? ''),
            'Результат' => (string)($block['outcome'] ?? ''),
        ];
        $body = '';
        foreach ($rows as $label => $value) {
            if (trim($value) === '') {
                continue;
            }
            $body .= '<p><strong>' . $this->esc($label) . ':</strong> ' . $this->inline($value) . '</p>';
        }

        return '<section class="article-case-study"><h3>Случай из практики</h3>' . $body . '</section>';
    }

    /** @param array<int,array{text:string,red_flag:bool}> $items */
    private function renderSymptoms(array $items): string
    {
        $normal = [];
        $redFlags = [];
        foreach ($items as $item) {
            $text = (string)($item['text'] ?? '');
            if (!empty($item['red_flag'])) {
                $redFlags[] = $text;
            } else {
                $normal[] = $text;
            }
        }

        $html = '<div class="article-symptoms"><h3>Симптомы</h3>';
        if ($normal !== []) {
            $html .= $this->renderList($normal, false);
        }
        if ($redFlags !== []) {
            $html .= '<div class="article-red-flags"><p class="callout-title">Тревожные симптомы — '
                . 'обратитесь к врачу немедленно</p>' . $this->renderList($redFlags, false) . '</div>';
        }
        return $html . '</div>';
    }

    /**
     * @param array<int,array{method:string,what_shows:string,related_service_id?:int}> $items
     */
    private function renderMethods(array $items, string $title): string
    {
        $li = '';
        foreach ($items as $item) {
            $method = $this->inline((string)($item['method'] ?? ''));
            $service = null;
            if (isset($item['related_service_id'])) {
                $service = $this->resolver?->resolveService((int)$item['related_service_id']);
            }
            if ($service !== null && trim((string)$service['url']) !== '') {
                $method = '<a href="' . $this->esc((string)$service['url']) . '">' . $method . '</a>';
            }
            $whatShows = trim((string)($item['what_shows'] ?? ''));
            $li .= '<li><strong>' . $method . '</strong>'
                . ($whatShows !== '' ? ' — ' . $this->inline($whatShows) : '') . '</li>';
        }

        return '<div class="article-methods"><h3>' . $this->esc($title) . '</h3><ul>' . $li . '</ul></div>';
    }

    /**
     * FAQ с микроразметкой schema.org FAQPage (microdata переживает санитайзер
     * Bitrix лучше, чем JSON-LD script).
     *
     * @param array<int,array{q:string,a:string}> $items
     */
    private function renderFaq(array $items): string
    {
        $html = '<section class="article-faq" itemscope itemtype="https://schema.org/FAQPage">'
            . '<h2>Частые вопросы</h2>';
        foreach ($items as $item) {
            $q = $this->esc((string)($item['q'] ?? ''));
            $a = $this->inline((string)($item['a'] ?? ''));
            $html .= '<div class="faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">'
                . '<h3 itemprop="name">' . $q . '</h3>'
                . '<div itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">'
                . '<div itemprop="text">' . $a . '</div></div></div>';
        }
        return $html . '</section>';
    }

    /** @param array<string,mixed> $block */
    private function renderComparison(array $block): string
    {
        $criteria = $block['criteria'] ?? [];
        $options = $block['options'] ?? [];

        $html = '<table class="article-comparison"><thead><tr><th></th>';
        foreach ($options as $option) {
            $html .= '<th>' . $this->inline((string)($option['name'] ?? '')) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($criteria as $rowIndex => $criterion) {
            $html .= '<tr><th scope="row">' . $this->inline((string)$criterion) . '</th>';
            foreach ($options as $option) {
                $values = $option['values'] ?? [];
                $html .= '<td>' . $this->inline((string)($values[$rowIndex] ?? '')) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /** @param array<int,array{myth:string,fact:string}> $items */
    private function renderMythFact(array $items): string
    {
        $html = '<div class="article-myth-fact">';
        foreach ($items as $item) {
            $html .= '<div class="myth-fact-item">'
                . '<p class="myth"><strong>Миф:</strong> ' . $this->inline((string)($item['myth'] ?? '')) . '</p>'
                . '<p class="fact"><strong>Факт:</strong> ' . $this->inline((string)($item['fact'] ?? '')) . '</p>'
                . '</div>';
        }
        return $html . '</div>';
    }

    /** @param array<string,mixed> $block */
    private function renderStat(array $block): string
    {
        $value = $this->esc((string)($block['value'] ?? ''));
        $description = $this->inline((string)($block['description'] ?? ''));
        $sourceIndex = (int)($block['source_index'] ?? 0);
        $ref = '<sup><a href="#source-' . ($sourceIndex + 1) . '">[' . ($sourceIndex + 1) . ']</a></sup>';

        return '<div class="article-stat"><span class="stat-value">' . $value . '</span> '
            . $ref . '<span class="stat-description">' . $description . '</span></div>';
    }

    /** @param array<string,mixed> $options */
    private function renderForm(array $options): string
    {
        $form = is_array($options['form'] ?? null) ? $options['form'] : [];
        if (strtoupper((string)($form['show_form'] ?? 'N')) !== 'Y') {
            return '';
        }
        $formId = $this->esc((string)($form['form_id'] ?? ''));
        $buttonText = $this->esc((string)($form['button_text'] ?? 'Записаться на приём'));

        return '<div class="article-appointment-form" data-form-id="' . $formId . '">'
            . '<button type="button" class="appointment-button">' . $buttonText . '</button></div>';
    }

    /** @param array<int,string> $items */
    private function renderSources(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $li = '';
        $n = 0;
        foreach ($items as $item) {
            $n++;
            $li .= '<li id="source-' . $n . '">' . $this->inline((string)$item) . '</li>';
        }
        return '<section class="article-sources"><h2>Источники</h2><ol>' . $li . '</ol></section>';
    }

    /** Inline-markdown: **жирный**, *курсив*, [текст](url). HTML внутри экранируется. */
    private function inline(string $text): string
    {
        $links = [];
        $text = preg_replace_callback(
            '~\[([^\]]+)\]\(([^)\s]+)\)~u',
            static function (array $m) use (&$links): string {
                $i = count($links);
                $links[] = ['text' => $m[1], 'url' => $m[2]];
                return "\x00L{$i}\x00";
            },
            $text
        ) ?? $text;

        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('~\*\*(.+?)\*\*~us', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('~(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)~us', '<em>$1</em>', $text) ?? $text;

        $text = preg_replace_callback(
            '~\x00L(\d+)\x00~',
            function (array $m) use ($links): string {
                $link = $links[(int)$m[1]];
                $url = $this->sanitizeUrl((string)$link['url']);
                $label = htmlspecialchars((string)$link['text'], ENT_QUOTES, 'UTF-8');
                return $url === '' ? $label : '<a href="' . $url . '">' . $label . '</a>';
            },
            $text
        ) ?? $text;

        return $text;
    }

    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('~^(https?:|mailto:)~i', $url) === 1) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
        if ($url[0] === '/' || $url[0] === '#') {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
        return ''; // javascript:, data: и прочее отклоняем
    }

    /** Санитайзер-страховка для импортированного raw_html. */
    private function sanitizeRawHtml(string $html): string
    {
        $html = preg_replace('~<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>~is', '', $html) ?? $html;
        $html = preg_replace('~<(script|style|iframe|object|embed|form)\b[^>]*/?>~i', '', $html) ?? $html;
        $html = preg_replace('~\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html) ?? $html;
        $html = preg_replace('~(href|src)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\')~i', '$1="#"', $html) ?? $html;
        return $html;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
