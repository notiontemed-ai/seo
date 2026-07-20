# Схема Bitrix инфоблока 81

> Важно: этот файл должен быть сверян с фактическим экспортом тестового инфоблока 81 перед production-импортом.

## Базовые поля элемента

| Поле | Тип | Обязательное | Формат |
|---|---:|---:|---|
| IBLOCK_ID | integer | да | `81` |
| ACTIVE | enum | да | `N` для ручного импорта черновика |
| NAME | string | да | Заголовок статьи |
| CODE | string | да | Символьный код |
| PREVIEW_TEXT | text | да | Текст анонса |
| DETAIL_TEXT | html | да | HTML статьи |
| DETAIL_TEXT_TYPE | enum | да | `html` |
| SECTION_ID | integer | да | ID раздела |
| SEO_TITLE | string | нет | SEO title |
| META_DESCRIPTION | string | нет | meta description |

## Свойства

| Код | Тип | Обязательное | Множественное | Формат значения |
|---|---|---:|---:|---|
| ARTICLE_STRUCTURE | string | да | нет | XML_ID структуры |
| ARTICLE_STRUCTURE_NAME | string | нет | нет | Название структуры |
| ARTICLE_STRUCTURE_VERSION | string | да | нет | Версия структуры |
| SEARCH_INTENT | list | да | нет | XML_ID значения |
| ARTICLE_TYPE | list | да | нет | XML_ID или code из справочника |
| AUTHOR | element | да | нет | ID врача |
| MEDICAL_REVIEWER | element | да | нет | ID врача |
| REGION | list | нет | нет | XML_ID региона |
| RELATED_ARTICLES | element | нет | да | ID элементов |

## Пример

См. `tests/fixtures/bitrix-iblock-81-reference.xml` и `tests/fixtures/bitrix-article-payload.json`.
