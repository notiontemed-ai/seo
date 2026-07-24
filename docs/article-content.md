# article_content v2 — структурированный контент (Этап 2)

ИИ генерирует **структурированные блоки (JSON), не HTML**. Редактор правит текст
поблочно и HTML не видит. HTML собирается детерминированно кодом (`HtmlRenderer`)
в момент отправки в Bitrix и для предпросмотра в React (единые правила).

## Схема

```json
{
  "schema_version": "2.0",
  "blocks": [
    {"type": "h2", "text": ""},
    {"type": "p", "text": "текст с **жирным**, *курсивом*, [ссылкой](url)"}
  ]
}
```

В `text` допускается ограниченный inline-markdown (`**жирный**`, `*курсив*`,
`[текст](url)`). HTML внутри блоков запрещён и экранируется при рендере.

## Библиотека блоков

Базовые: `h2`, `h3`, `p`, `list` (`{ordered, items[]}`), `table`
(`{header[], rows[][]}`).

Смысловые (14):

| type | поля | медицинский |
|------|------|:---:|
| `short_answer` | `text` (дублируется в свойство 851, плашка в начале) | |
| `expert_opinion` | `doctor_id` (только инфоблок 65), `quote` (не перефразируется) | ✓ |
| `case_study` | `patient_context, situation, actions, outcome` | ✓ |
| `symptoms` | `items[{text, red_flag}]` (red_flag → акцентный список) | ✓ |
| `when_to_see_doctor` | `items[]` | |
| `causes` | `items[]` | |
| `diagnostics` | `items[{method, what_shows, related_service_id?}]` | |
| `treatment_methods` | `items[{method, what_shows, related_service_id?}]` | |
| `faq` | `items[{q, a}]` (schema.org FAQPage microdata) | |
| `comparison_table` | `criteria[]`, `options[{name, values[]}]` | |
| `myth_fact` | `items[{myth, fact}]` | |
| `stats_highlight` | `value, description, source_index` (без источника невалиден) | ✓ |
| `appointment_form` | — (использует SHOW_FORM/FORM_ID/FORM_BUTTON_TEXT) | |
| `sources` | `items[]` (нумерованный список в конце) | |

`raw_html` — служебный блок обратной совместимости (не предлагается в UI).

Каталог отдаётся в `dictionaries.content_blocks` для карточек «Добавить блок».
Библиотека расширяемая: новый блок = запись в `ArticleContent::CATALOG` +
`normalizeBlock()` + рендер в `HtmlRenderer`, без миграции статей.

Связка со структурами: `data/article_structures.json` → каждый `config` задаёт
`recommended_blocks` (порядок смысловых блоков). ИИ при генерации получает этот
список; редактор затем добавляет/убирает блоки вручную.

## Валидация (`ArticleContent::normalize`)

- тип блока — из allowlist; неизвестные и пустые блоки отбрасываются с warning;
- обязателен хотя бы один `h2` и один `p`;
- `expert_opinion` с `doctor_id` вне справочника 65 — отбрасывается;
- `stats_highlight` без `source_index` — отбрасывается («числа только с источником»);
- флаг «медицинский» на блоках `expert_opinion`, `case_study`, `symptoms`,
  `stats_highlight` — по ним прицельно генерируются вопросы для med-review.

Та же валидация лёгкой версией продублирована в n8n-узле «Parse model JSON» для
`generate_article` / `revise_article` / `apply_med_answers`.

## Сборка HTML (`HtmlRenderer`)

- оглавление по `h2`/`h3` с якорями (`#section-N`);
- FAQ с микроразметкой schema.org FAQPage;
- источники нумерованным `<ol>` с якорями `#source-N`; `stats_highlight`
  ссылается на источник через `source_index`;
- дисклеймер в подвале;
- шаблон из ARTICLE_TEMPLATE (пока один — `default`);
- запрещённые теги невозможны by design; санитайзер — страховка для inline-ссылок
  и `raw_html`.

Write-API (`create_or_update_draft`) принимает `article_content` и собирает
`DETAIL_TEXT` на сервере тем же `HtmlRenderer`. Если передан `detail_html` —
используется он (обратная совместимость).

## Обратная совместимость

Загрузка существующей статьи (`action=article`) возвращает `article_content` —
результат парсера `HtmlToBlocksParser` (`h2/h3/p/ul/ol/table` по эвристикам;
нераспознанное → блок `raw_html` с предупреждением).

## Тесты

`tests/php/article_content_test.php` — валидация схемы, отбрасывание невалидных
блоков, рендер (оглавление, якоря, FAQ schema.org, экранирование, ссылки на врача
и услуги), парсер HTML→blocks, round-trip, каталог и медицинские флаги.
