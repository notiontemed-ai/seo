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

## Черновики в редакторе

Верхняя панель разделяет режимы `Редактор` и `Черновики`. Черновики не добавляются в левое пошаговое меню. В режиме редактора доступны название текущего черновика, номер версии, индикатор несохранённых изменений и кнопка `Сохранить черновик`.

Snapshot собирается функцией `collectDraftSnapshot()`, очищается от секретов рекурсивно и отправляется через существующий `proxy.php` функцией `callDraftApi()`. После `applyDraftSnapshot()` не запускаются OpenAI, TEXT.RU polling или платные проверки; восстанавливаются только поля редактора, завершённые результаты уникальности и состояние вёрстки.

Перед открытием другого черновика `confirmUnsavedDraft()` показывает предупреждение о несохранённых изменениях и по умолчанию отменяет переход.
