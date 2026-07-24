# Write-API: создание неактивного черновика (Этап 1)

Действие `create_or_update_draft` пишет черновик статьи напрямую в инфоблок
статей v2 (ID из `config['iblocks']['articles']`, захардкожен на сервере).
Заменяет прежний путь «XML-файл + ручной импорт». XML-экспортёр
(`internal/seo-editor/export.php`) остаётся как запасной механизм.

## Поток

```
browser → internal/seo-editor/proxy.php → local/api/seo/index.php (write_token)
```

Токен записи `temed_seo_api_write_token` хранится в `proxy` config и в браузер
не попадает. API проверяет отдельный `write_token` (не равен `read_token`;
пустой/`CHANGE_ME` → 503 `API_NOT_CONFIGURED`).

## Запрос

`POST` c JSON-телом (≤ 1 МБ):

| Поле | Тип | Описание |
|------|-----|----------|
| `action` | string | `create_or_update_draft` |
| `code` | string | символьный код (латиница/цифры/`_`/`-`) |
| `name` | string | название статьи |
| `preview_text` | string | анонс (plain) |
| `detail_html` | string | готовый HTML (обратная совместимость) |
| `article_content` | object | блоки `article_content` v2 — HTML соберётся на сервере через `HtmlRenderer` (см. `docs/article-content.md`) |
| `section_id` | int | ID раздела инфоблока 81 (проверяется на существование) |
| `properties` | map | `код свойства → значение|значения` |
| `seo` | object | SEO-мета элемента: `{title, description}` → `IPROPERTY_TEMPLATES` (`ELEMENT_META_TITLE`/`ELEMENT_META_DESCRIPTION`); допускаются плоские алиасы `seo_title`/`meta_description`; пустые значения не передаются и не затирают существующую мету |

- ID инфоблока из запроса **не принимается**.
- `ACTIVE` всегда `N` на сервере; значение из запроса игнорируется.
- `properties` фильтруются строгим allowlist по символьному коду. Неизвестные
  ключи отбрасываются и попадают в `warnings`.
- Списочные свойства (`ARTICLE_TYPE`, `SEARCH_INTENT`, `REGION`,
  `ARTICLE_TEMPLATE`, `SHOW_FORM`) передаются по XML_ID варианта и валидируются
  через `CIBlockPropertyEnum`; несовпадение → warning и пропуск значения.
- Даты (`MEDICAL_REVIEWED_AT`, `CONTENT_UPDATED_AT`) принимаются в
  `YYYY-MM-DD` или `DD.MM.YYYY`.

Allowlist свойств: `ARTICLE_TYPE, PRIMARY_QUERY, SECONDARY_QUERIES,
SEARCH_INTENT, SHORT_ANSWER, REGION, AUTHOR, MEDICAL_REVIEWER,
MEDICAL_REVIEWED_AT, CONTENT_UPDATED_AT, SOURCES, RELATED_ARTICLES,
ARTICLE_TEMPLATE, ARTICLE_STRUCTURE, ARTICLE_STRUCTURE_NAME,
ARTICLE_STRUCTURE_VERSION, RELATED_ARTICLES_V2, RELATED_SERVICES,
RELATED_CLINICS, FEATURED_IMAGE_ALT, SHOW_FORM, FORM_ID, FORM_BUTTON_TEXT`.

## Логика upsert (поиск по `CODE` в инфоблоке 81)

| Ситуация | Результат | HTTP |
|----------|-----------|------|
| элемент не найден | `CIBlockElement::Add` → `{created:true, element_id, admin_url}` | 201 |
| найден, `ACTIVE=N` | `Update` + `SetPropertyValuesEx` → `{updated:true, element_id, admin_url}` | 200 |
| найден, `ACTIVE=Y` | `ACTIVE_ELEMENT_PROTECTED`, ничего не меняется | 409 |

Активные элементы write-API не изменяет никогда и ни при каких условиях.

## Ответ

```json
{
  "success": true,
  "data": {
    "created": true,
    "element_id": 12345,
    "active": "N",
    "admin_url": "https://temed.ru/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=81&...",
    "warnings": []
  },
  "meta": { "warnings": [] }
}
```

## Коды ошибок

`API_NOT_CONFIGURED` (503), `INVALID_CODE`/`INVALID_NAME`/`INVALID_SECTION`
(400), `SECTION_NOT_FOUND` (400), `ACTIVE_ELEMENT_PROTECTED` (409),
`PAYLOAD_TOO_LARGE` (413), `ELEMENT_ADD_FAILED`/`ELEMENT_UPDATE_FAILED` (500).

## Тесты

`tests/php/article_draft_writer_test.php` — allowlist, форс `ACTIVE=N`,
защита активных элементов (409), upsert create/update, валидация XML_ID,
множественные свойства и даты. Запуск: `php tests/php/article_draft_writer_test.php`.
