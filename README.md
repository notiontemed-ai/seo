# TEMED SEO Editor

Защищённый SEO-редактор медицинских статей TEMED. Рабочая структура репозитория соответствует развёртыванию: веб-редактор находится в `internal/seo-editor`, публичный read-only API — в `local/api/seo`, workflow n8n — в `n8n`.

## Развёртывание

1. Скопируйте `internal/seo-editor/config.php.example` в `internal/seo-editor/config.php` и заполните только на сервере.
2. Скопируйте `local/api/seo/config.php.example` в `local/api/seo/config.php` и заполните Bearer token только на сервере.
3. Импортируйте `n8n/TEMED SEO Editor.json` и задайте секрет через переменную окружения/credential, не в JSON workflow.
4. Проверьте, что `config.php`, `.env`, логи и `runtime/` не попадают в Git.
5. После ротации ранее скомпрометированного секрета очистите историю репозитория внешним инструментом и выполните secret scanning.

## Ограничения

Экспорт XML реализован детерминированным PHP-кодом и создаёт неактивный элемент инфоблока 81. Прямая запись в Bitrix отключена. TEXT.RU ключ хранится в n8n credential или серверной конфигурации, но не в браузере.
