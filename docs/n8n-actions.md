# TEMED SEO Editor

Документ обновлён под API 1.2.0. Production baseline хранится в `references/local-api-seo-index.php` и не разворачивается как endpoint. Рабочий read-only endpoint — `local/api/seo/index.php`, построенный поверх baseline и расширенный actions `system_manifest` и `internal_uniqueness`.

## Архитектура и границы

Browser editor обращается к `internal/seo-editor/proxy.php`. Proxy передаёт GET read-only actions в TEMED SEO API, отправляет `check_internal_uniqueness` напрямую в API POST `internal_uniqueness`, а AI/TEXT.RU actions — в n8n. XML экспорт остаётся в PHP (`internal/seo-editor/export.php`) и не создаёт элемент в Bitrix напрямую.

## Bitrix schema

Используются инфоблоки из `config.php`: articles 81, legacy_articles 68, doctors 65, prices 70, clinics 10. Статьи читаются из 81 и 68; для 81 дополнительно учитывается свойство `SHORT_ANSWER`. Свойства врачей фильтруются allowlist baseline, чувствительные свойства исключаются.

## Security

API требует `Authorization: Bearer <read_token>`. Отсутствующий, пустой или `CHANGE_ME` token переводит API в fail-closed `API_NOT_CONFIGURED` 503. Секреты, `config.php`, webhook URL, credential IDs и пути сервера не раскрываются в manifest. `bearer_token` поддержан временно с server-side warning и deprecated.

## Internal uniqueness

Алгоритм `internal-shingles-v1`: общая HTML/text нормализация, shingle coverage по последовательностям слов, сравнение с corpus инфоблоков 81 и 68, исключение только по паре `source + element_id`. Результат не меняет медицинский текст и помечает совпавшие фрагменты как требующие ручной проверки.

## Assistant

`assistant_chat` идёт через proxy в n8n model branch. Proxy добавляет live `system_manifest`, `article_structures` и безопасный summary dictionaries. Suggestions требуют подтверждения пользователя и запрещены для секретов, endpoint, credential, config и hidden security fields.

## Deployment

Выкладка только вручную после backup production файлов и smoke tests. Не копировать `references/**`, docs, tests и `config.php.example` поверх production `config.php`. Rollback: вернуть backup API/proxy/assets, не менять config, сбросить opcode cache и проверить bootstrap.

## Draft actions

The existing `TEMED SEO Editor` workflow now accepts the draft actions on the same `temed-seo-editor-v1` webhook: `draft_health`, `draft_get_dictionaries`, `draft_list`, `draft_get`, `draft_create`, `draft_save_version`, `draft_list_versions`, `draft_get_version`, `draft_restore_version`, `draft_delete`, `draft_restore`, `draft_purge`, and `draft_log_xml_export`.

Draft actions are detected in `Prepare request` with `action.startsWith('draft_')` and routed from `IF request valid` to `IF draft action` before the existing AI/TEXT.RU routing. Existing non-draft actions continue through the previous TEXT.RU/direct/model path.

The draft branch uses Google Sheets API HTTP Request nodes with predefined credential type `googleSheetsOAuth2Api` and credential `Google Sheets [ n8n.temed ]` (`j68oP4jTpcKIyBYP`). The workflow stores no OAuth token.
