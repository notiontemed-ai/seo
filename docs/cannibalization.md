# Каннибализация — единый отчёт (Этап 3)

Действие `cannibalization_check` (POST) объединяет три сигнала пересечения с
корпусом Bitrix в интегральный `risk`. Расширяет прежний `internal_uniqueness`
(тот сохранён для обратной совместимости). Данные — только из Bitrix
(инфоблоки 81 и 68), без Вебмастера/GSC.

## Поток

```
browser → proxy.php → local/api/seo/index.php (POST cannibalization_check)
```

TEXT.RU остаётся отдельной внешней проверкой (n8n), в этот отчёт не входит.

## Вход

```json
{
  "action": "cannibalization_check",
  "article": {
    "name": "",
    "primary_query": "",
    "secondary_queries": ["", ""],
    "search_intent": "informational",
    "article_content": { "schema_version": "2.0", "blocks": [] }
  },
  "exclude": { "source": "new", "element_id": 123 },
  "max_matches": 50,
  "refresh_corpus": false
}
```

Поля можно передавать и на верхнем уровне payload (не только в `article`).
Текст статьи берётся из `article_content` (блоки) либо из `text`/`detail_html`.
`refresh_corpus: true` принудительно перестраивает кэш корпуса.

## Корпус и кэш

Корпус — инфоблок 81 (name, preview, SHORT_ANSWER, detail, PRIMARY_QUERY,
SECONDARY_QUERIES, SEARCH_INTENT) и 68 (name, preview, detail; SEO-свойств нет).
Кэшируется на диск (`local/api/seo/runtime/corpus-cache.json`, TTL из
`config.cache_ttl`, по умолчанию 1 час) — раньше корпус перечитывался на каждый
запрос, это был главный тормоз.

## Сигналы и интегральный risk

По каждой статье корпуса:

- `query_overlap` — нормализованное пересечение primary/secondary запросов
  (без лемматизации; нормализация как в TextNormalizer, сравнение по словам и
  биграммам, Jaccard). `primary_primary` (совпадение primary↔primary ≥ 0.6) →
  сразу `high`.
- `intent_match` — совпадение SEARCH_INTENT (только для 81).
- `title_similarity` — пересечение слов name↔name.
- `text_overlap_percent` — существующий шингловый алгоритм
  (`internal-shingles-v1`), фрагменты совпадений сохраняются.

Интегральный `risk`:

| Условие | risk |
|---------|------|
| `primary_primary`, или `text_overlap ≥ 20%`, или (`query_overlap ≥ 0.5` и `intent_match`) | high |
| `query_overlap ≥ 0.5`, или `title_similarity ≥ 0.5`, или `text_overlap ≥ 8%`, или (`query_overlap ≥ 0.3` и `intent_match`) | medium |
| иначе | low |

Статьи с низким сигналом (score < 0.2 и risk=low) в выдачу не попадают.

## Ответ

```json
{
  "algorithm_version": "cannibalization-v1",
  "corpus": { "new_articles": 0, "legacy_articles": 0, "total_articles": 0 },
  "risk_summary": { "high": 0, "medium": 0, "low": 0 },
  "candidates": [
    {
      "source": "new", "element_id": 100, "name": "", "url": "", "absolute_url": "",
      "risk": "high",
      "signals": {
        "query_overlap": 1.0, "primary_primary": true, "intent_match": true,
        "title_similarity": 0.4, "text_overlap_percent": 12.5, "matched_shingles": 8
      },
      "fragments": [{ "text": "", "word_count": 14, "requires_manual_medical_review": true }]
    }
  ],
  "uniqueness_percent": 84.2,
  "matched_percent": 15.8,
  "warnings": ["..."]
}
```

`uniqueness_percent`/`matched_percent` сохранены для совместимости с прежним
`internal_uniqueness`.

## Тесты

`tests/php/cannibalization_test.php` — high по primary↔primary и по text_overlap,
отсечение нерелевантных, сортировка по риску, `exclude`, ввод блоками и готовым
текстом.
