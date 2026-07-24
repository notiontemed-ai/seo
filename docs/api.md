# API `local/api/seo`

Актуально для API 1.8.0. Базовый эндпоинт — `local/api/seo/index.php`.

## Авторизация

- Read-действия: `Authorization: Bearer <read_token>`.
- Write-действия (`create_or_update_draft`): `Authorization: Bearer <write_token>`
  (отдельный токен, ≠ read).
- Пусто/`CHANGE_ME`/отсутствует → 503 `API_NOT_CONFIGURED`.
- `bearer_token` — временный deprecated-fallback для read (warning в error_log).

## Read-действия (GET)

`ping`, `capabilities`, `bootstrap`, `iblocks`, `iblock_properties`, `doctors`,
`doctor`, `doctor_properties`, `articles`, `article`, `article_properties`,
`article_sections`, `article_structures`, `clinics`, `clinic`, `prices`/`services`,
`price`/`service`, `dictionaries`, `system_manifest`.

Параметры списков: `q`, `active`, `section_id`, `limit`, `offset`.

Изменения контента v2 (обратно совместимые добавления):
- `article` дополнительно отдаёт `article_content` (разбор DETAIL_TEXT парсером);
- `dictionaries` отдаёт `content_blocks` — каталог блоков для UI.

## POST-действия

| action | назначение | док |
|--------|-----------|-----|
| `internal_uniqueness` | прежняя внутренняя уникальность (совместимость) | — |
| `cannibalization_check` | единый отчёт «Каннибализация» | `cannibalization.md` |
| `linking_candidates` | кандидаты-доноры для перелинковки | `linking.md` |
| `create_or_update_draft` | запись неактивного черновика (write_token) | `write-api.md` |

## Модульная структура (этап 6)

`index.php` — bootstrap и диспетчеризация. Функции вынесены в:
`lib/ApiResponse.php` (ответы), `lib/ApiAuth.php` (авторизация),
`lib/ApiSupport.php` (helpers), `lib/ReadActions.php` (обработчики read +
`getCapabilities`). Поведение read-действий не менялось.

## Кэш

Корпус для проверок кэшируется на диск: `local/api/seo/runtime/corpus-cache.json`
(TTL из `config.cache_ttl`, по умолчанию 1 час). Каталог `runtime/` не
коммитится и создаётся при первом запросе.
