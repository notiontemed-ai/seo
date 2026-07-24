# Bitrix: инфоблоки и свойства

## Инфоблоки (из `config.php`)

- статьи v2 — **81**
- legacy-статьи — **68**
- врачи — **65**
- услуги (prices) — **70**
- клиники — **10**

Статьи читаются из 81 и 68. Свойства врачей отдаются по allowlist; чувствительные
свойства (токены, пароли, webhook и т.п.) исключаются из любой выдачи.

## Свойства инфоблока 81 (allowlist записи)

Создание новых свойств/вариантов ЗАПРЕЩЕНО. Write-API принимает только эти коды
(тип: S строка, L список по XML_ID, N число/привязка, E привязка, Date дата;
«×N» — множественное):

| код | ID | тип | примечание |
|-----|----|-----|-----------|
| ARTICLE_TYPE | 847 | L | удалено из редактора (ТЗ v1.1): не заполняется, в экспорте пусто |
| PRIMARY_QUERY | 848 | S | |
| SECONDARY_QUERIES | 849 | S×N | |
| SEARCH_INTENT | 850 | L | |
| SHORT_ANSWER | 851 | S | дублируется из блока short_answer |
| REGION | 852 | L | |
| AUTHOR | 853 | N→65 | |
| MEDICAL_REVIEWER | 854 | N→65 | |
| MEDICAL_REVIEWED_AT | 855 | Date | |
| CONTENT_UPDATED_AT | 856 | Date | |
| SOURCES | 857 | S×N | |
| RELATED_ARTICLES | 858 | N→68 ×N | |
| ARTICLE_TEMPLATE | 864 | L | |
| ARTICLE_STRUCTURE | 865 | S | |
| ARTICLE_STRUCTURE_NAME | 866 | S | |
| ARTICLE_STRUCTURE_VERSION | 867 | S | |
| RELATED_ARTICLES_V2 | 868 | E→81 ×N | |
| RELATED_SERVICES | 869 | E→70 ×N | |
| RELATED_CLINICS | 870 | E→10 ×N | |
| FEATURED_IMAGE_ALT | 871 | S | |
| SHOW_FORM | 884 | L (Y/N) | |
| FORM_ID | 885 | S | |
| FORM_BUTTON_TEXT | 886 | S | |

Списочные значения передаются по XML_ID варианта и валидируются через
`CIBlockPropertyEnum`; при несовпадении значение пропускается с warning.

## SEO-мета элемента

SEO title и meta description — не свойства инфоблока, а IPROPERTY элемента.
Write-API принимает их в `seo:{title,description}` (или плоские
`seo_title`/`meta_description`) и передаёт в Bitrix через
`IPROPERTY_TEMPLATES` (`ELEMENT_META_TITLE`, `ELEMENT_META_DESCRIPTION`).
В редакторе SEO title и meta description заполняются после генерации в секции
«Мета и анонс» на шаге «Генерация» (ТЗ v1.1), а не на шаге «Задача».

Свойство `SOURCES` (857) в редакторе не имеет отдельного поля: значения
синхронизируются из блока `sources` при сборке payload публикации.

## Структуры статей

`internal/seo-editor/data/article_structures.json` — массив `configs` (9 SEO-архетипов
по ТЗ v1.1: 3 интента × 3 структуры). Каждая структура задаёт `intent`, `structure`
(порядок блоков с `required`/`repeat`) и `forbidden`. Конфиг перенесён из публичного
API в редактор (ТЗ 4.1) и отдаётся локально через `proxy.php?action=article_structures`;
используется ИИ при генерации и редактором при выборе структуры (интент → карточки
структур → детали). Библиотека блоков — см. `article-content.md`.
