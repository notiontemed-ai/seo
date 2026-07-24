<?php

declare(strict_types=1);

/**
 * Схема структурированного контента статьи `article_content` v2.
 *
 * ИИ генерирует блоки (JSON), не HTML. Этот класс валидирует и нормализует
 * блоки: отбрасывает неизвестные типы и блоки с невалидной схемой (с warning),
 * проверяет обязательные поля смысловых блоков. HTML собирается детерминированно
 * в HtmlRenderer.
 *
 * Библиотека расширяемая: новый блок = запись в CATALOG + normalizeBlock() +
 * рендер в HtmlRenderer, без миграции существующих статей.
 */
final class ArticleContent
{
    public const SCHEMA_VERSION = '2.0';

    /**
     * Каталог блоков: type => метаданные для валидатора и UI «Добавить блок».
     * category: base|semantic|system. medical: true → по блоку прицельно
     * генерируются вопросы для медицинской проверки.
     *
     * @var array<string,array{label:string,category:string,medical:bool}>
     */
    private const CATALOG = [
        // Базовые
        'h2'    => ['label' => 'Заголовок H2', 'category' => 'base', 'medical' => false],
        'h3'    => ['label' => 'Заголовок H3', 'category' => 'base', 'medical' => false],
        'p'     => ['label' => 'Абзац', 'category' => 'base', 'medical' => false],
        'list'  => ['label' => 'Список', 'category' => 'base', 'medical' => false],
        'table' => ['label' => 'Таблица', 'category' => 'base', 'medical' => false],
        // Смысловые
        'short_answer'      => ['label' => 'Краткий ответ', 'category' => 'semantic', 'medical' => false],
        'expert_opinion'    => ['label' => 'Мнение эксперта', 'category' => 'semantic', 'medical' => true],
        'case_study'        => ['label' => 'Случай из практики', 'category' => 'semantic', 'medical' => true],
        'symptoms'          => ['label' => 'Симптомы', 'category' => 'semantic', 'medical' => true],
        'when_to_see_doctor' => ['label' => 'Когда обратиться к врачу', 'category' => 'semantic', 'medical' => false],
        'causes'            => ['label' => 'Причины и факторы риска', 'category' => 'semantic', 'medical' => false],
        'diagnostics'       => ['label' => 'Диагностика', 'category' => 'semantic', 'medical' => false],
        'treatment_methods' => ['label' => 'Методы лечения', 'category' => 'semantic', 'medical' => false],
        'faq'               => ['label' => 'Вопросы и ответы (FAQ)', 'category' => 'semantic', 'medical' => false],
        'comparison_table'  => ['label' => 'Таблица сравнения', 'category' => 'semantic', 'medical' => false],
        'myth_fact'         => ['label' => 'Мифы и факты', 'category' => 'semantic', 'medical' => false],
        'stats_highlight'   => ['label' => 'Статистика', 'category' => 'semantic', 'medical' => true],
        'appointment_form'  => ['label' => 'Форма записи', 'category' => 'system', 'medical' => false],
        'sources'           => ['label' => 'Источники', 'category' => 'semantic', 'medical' => false],
        // Обратная совместимость (не предлагается в UI)
        'raw_html'          => ['label' => 'Нераспознанный HTML', 'category' => 'system', 'medical' => false],
    ];

    /** Каталог блоков для UI/справочников. */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::CATALOG as $type => $meta) {
            if ($type === 'raw_html') {
                continue;
            }
            $out[] = ['type' => $type] + $meta;
        }
        return $out;
    }

    public static function isKnownType(string $type): bool
    {
        return isset(self::CATALOG[$type]);
    }

    public static function isMedical(string $type): bool
    {
        return (bool)(self::CATALOG[$type]['medical'] ?? false);
    }

    /**
     * Провалидировать и нормализовать контент.
     *
     * @param array<string,mixed> $content
     * @return array{schema_version:string,blocks:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,short_answer:string,has_medical:bool}
     */
    public static function normalize(array $content, ?ContentReferenceResolver $resolver = null): array
    {
        $warnings = [];
        $blocksIn = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];
        $out = [];
        $shortAnswer = '';
        $hasMedical = false;

        foreach ($blocksIn as $index => $block) {
            if (!is_array($block)) {
                $warnings[] = self::warn('invalid_block', (int)$index, '', 'Блок не является объектом');
                continue;
            }

            $type = trim((string)($block['type'] ?? ''));
            if (!self::isKnownType($type)) {
                $warnings[] = self::warn('unknown_block_type', (int)$index, $type, 'Неизвестный тип блока, пропущен');
                continue;
            }

            $normalized = self::normalizeBlock($type, $block, $resolver, $warnings, (int)$index);
            if ($normalized === null) {
                continue;
            }

            if ($type === 'short_answer' && $shortAnswer === '') {
                $shortAnswer = (string)$normalized['text'];
            }
            if (self::isMedical($type)) {
                $hasMedical = true;
            }

            $out[] = $normalized;
        }

        $types = array_column($out, 'type');
        if (!in_array('h2', $types, true)) {
            $warnings[] = self::warn('missing_required', -1, 'h2', 'В статье должен быть хотя бы один блок h2');
        }
        if (!in_array('p', $types, true)) {
            $warnings[] = self::warn('missing_required', -1, 'p', 'В статье должен быть хотя бы один блок p');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'blocks' => $out,
            'warnings' => $warnings,
            'short_answer' => $shortAnswer,
            'has_medical' => $hasMedical,
        ];
    }

    /**
     * @param array<string,mixed> $block
     * @param array<int,array<string,mixed>> $warnings
     * @return array<string,mixed>|null
     */
    private static function normalizeBlock(
        string $type,
        array $block,
        ?ContentReferenceResolver $resolver,
        array &$warnings,
        int $index
    ): ?array {
        switch ($type) {
            case 'h2':
            case 'h3':
            case 'p':
            case 'short_answer':
                $text = self::str($block['text'] ?? '');
                if ($text === '') {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Пустой текст блока, пропущен');
                    return null;
                }
                return ['type' => $type, 'text' => $text];

            case 'list':
                $items = self::stringList($block['items'] ?? []);
                if ($items === []) {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Пустой список, пропущен');
                    return null;
                }
                return ['type' => 'list', 'ordered' => (bool)($block['ordered'] ?? false), 'items' => $items];

            case 'when_to_see_doctor':
            case 'causes':
                $items = self::stringList($block['items'] ?? []);
                if ($items === []) {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Пустой список, пропущен');
                    return null;
                }
                return ['type' => $type, 'items' => $items];

            case 'sources':
                $items = self::stringList($block['items'] ?? []);
                if ($items === []) {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Нет источников, блок пропущен');
                    return null;
                }
                return ['type' => 'sources', 'items' => $items];

            case 'table':
                $header = self::stringList($block['header'] ?? []);
                $rows = self::tableRows($block['rows'] ?? []);
                if ($rows === []) {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Пустая таблица, пропущена');
                    return null;
                }
                return ['type' => 'table', 'header' => $header, 'rows' => $rows];

            case 'expert_opinion':
                $doctorId = (int)($block['doctor_id'] ?? 0);
                $quote = self::str($block['quote'] ?? '');
                if ($doctorId <= 0 || $quote === '') {
                    $warnings[] = self::warn('invalid_schema', $index, $type, 'expert_opinion требует doctor_id и quote');
                    return null;
                }
                if ($resolver !== null && !$resolver->doctorExists($doctorId)) {
                    $warnings[] = self::warn('doctor_not_found', $index, $type, 'Врач вне справочника (инфоблок 65), блок отброшен');
                    return null;
                }
                return ['type' => 'expert_opinion', 'doctor_id' => $doctorId, 'quote' => $quote];

            case 'case_study':
                $fields = [
                    'patient_context' => self::str($block['patient_context'] ?? ''),
                    'situation' => self::str($block['situation'] ?? ''),
                    'actions' => self::str($block['actions'] ?? ''),
                    'outcome' => self::str($block['outcome'] ?? ''),
                ];
                if (implode('', $fields) === '') {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Пустой случай из практики, пропущен');
                    return null;
                }
                return ['type' => 'case_study'] + $fields;

            case 'symptoms':
                $items = [];
                foreach (self::itemArray($block['items'] ?? []) as $item) {
                    $text = self::str($item['text'] ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $items[] = ['text' => $text, 'red_flag' => (bool)($item['red_flag'] ?? false)];
                }
                if ($items === []) {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Нет симптомов, блок пропущен');
                    return null;
                }
                return ['type' => 'symptoms', 'items' => $items];

            case 'diagnostics':
            case 'treatment_methods':
                $items = [];
                foreach (self::itemArray($block['items'] ?? []) as $item) {
                    $method = self::str($item['method'] ?? '');
                    if ($method === '') {
                        continue;
                    }
                    $entry = ['method' => $method, 'what_shows' => self::str($item['what_shows'] ?? '')];
                    $serviceId = (int)($item['related_service_id'] ?? 0);
                    if ($serviceId > 0) {
                        $entry['related_service_id'] = $serviceId;
                    }
                    $items[] = $entry;
                }
                if ($items === []) {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Нет пунктов, блок пропущен');
                    return null;
                }
                return ['type' => $type, 'items' => $items];

            case 'faq':
                $items = [];
                foreach (self::itemArray($block['items'] ?? []) as $item) {
                    $q = self::str($item['q'] ?? '');
                    $a = self::str($item['a'] ?? '');
                    if ($q === '' || $a === '') {
                        continue;
                    }
                    $items[] = ['q' => $q, 'a' => $a];
                }
                if ($items === []) {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Пустой FAQ, блок пропущен');
                    return null;
                }
                return ['type' => 'faq', 'items' => $items];

            case 'comparison_table':
                $criteria = self::stringList($block['criteria'] ?? []);
                $options = [];
                foreach (self::itemArray($block['options'] ?? []) as $option) {
                    $name = self::str($option['name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $options[] = ['name' => $name, 'values' => self::stringList($option['values'] ?? [])];
                }
                if ($criteria === [] || $options === []) {
                    $warnings[] = self::warn('invalid_schema', $index, $type, 'comparison_table требует criteria и options');
                    return null;
                }
                return ['type' => 'comparison_table', 'criteria' => $criteria, 'options' => $options];

            case 'myth_fact':
                $items = [];
                foreach (self::itemArray($block['items'] ?? []) as $item) {
                    $myth = self::str($item['myth'] ?? '');
                    $fact = self::str($item['fact'] ?? '');
                    if ($myth === '' || $fact === '') {
                        continue;
                    }
                    $items[] = ['myth' => $myth, 'fact' => $fact];
                }
                if ($items === []) {
                    $warnings[] = self::warn('empty_block', $index, $type, 'Пустой блок мифов, пропущен');
                    return null;
                }
                return ['type' => 'myth_fact', 'items' => $items];

            case 'stats_highlight':
                $value = self::str($block['value'] ?? '');
                $description = self::str($block['description'] ?? '');
                $sourceIndex = $block['source_index'] ?? null;
                if ($value === '' || $sourceIndex === null || $sourceIndex === '' || (int)$sourceIndex < 0) {
                    // Правило «числа только с источником».
                    $warnings[] = self::warn('missing_source', $index, $type, 'stats_highlight без source_index невалиден и отброшен');
                    return null;
                }
                return [
                    'type' => 'stats_highlight',
                    'value' => $value,
                    'description' => $description,
                    'source_index' => (int)$sourceIndex,
                ];

            case 'appointment_form':
                return ['type' => 'appointment_form'];

            case 'raw_html':
                $html = self::str($block['html'] ?? ($block['text'] ?? ''));
                if ($html === '') {
                    return null;
                }
                return ['type' => 'raw_html', 'html' => $html];
        }

        $warnings[] = self::warn('unknown_block_type', $index, $type, 'Тип блока не обрабатывается');
        return null;
    }

    private static function str($value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /** @return array<int,string> */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $s = self::str($item);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function itemArray($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @return array<int,array<int,string>> */
    private static function tableRows($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = self::str($cell);
            }
            if (implode('', $cells) !== '') {
                $rows[] = $cells;
            }
        }
        return $rows;
    }

    /** @return array{type:string,index:int,block_type:string,message:string} */
    private static function warn(string $code, int $index, string $blockType, string $message): array
    {
        return [
            'type' => $code,
            'index' => $index,
            'block_type' => $blockType,
            'message' => $message,
        ];
    }
}
