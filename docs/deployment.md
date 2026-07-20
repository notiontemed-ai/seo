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

## Deprecated config key

`bearer_token` принимается только как временный fallback для совместимости. Production должен перейти на `read_token`; при fallback API пишет warning в `error_log`, но не возвращает его клиенту.

## Manual placement list

Размещать вручную только изменённые runtime-файлы: `/local/api/seo/index.php`, `/local/api/seo/lib/*`, `/internal/seo-editor/proxy.php`, при необходимости editor assets/export libs. n8n workflow импортируется отдельно в n8n, не копируется на Bitrix как runtime-файл.
