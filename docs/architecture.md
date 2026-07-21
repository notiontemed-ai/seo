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

## Draft storage architecture

Draft versioning uses the existing browser → `/internal/seo-editor/proxy.php` → `/temed-seo-editor-v1` n8n webhook path. There is no `drafts.php`, separate webhook, Apps Script, Google Drive storage, second header secret, or second Google credential.

Spreadsheet `1DEpgU7rR7IsY0jF-Aarm25sFF4RkqPeEn2jm76DaBto` contains sheets `Черновики`, `Версии`, `Снимки`, `Журнал`, and `Справочники`. The `Снимки` sheet stores Base64 snapshot chunks up to 40,000 characters. Readers sort chunks by `CHUNK_INDEX`, verify count, UTF-8 size, SHA-256 hash, JSON validity, and `schema_version` before returning a snapshot.

Google Sheets writes use `valueInputOption=RAW`; full snapshots and HTML are not written to `Журнал`.
