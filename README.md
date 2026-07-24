# TEMED SEO Editor

Защищённый SEO-редактор медицинских статей TEMED. Стек: PHP (API + write-endpoint
на сервере Bitrix), React + Vite (редактор, деплой папкой `dist/`), n8n (ИИ и
TEXT.RU). Структура репозитория: веб-редактор — `internal/seo-editor` (исходники
в `app/`, сборка в `dist/`), API — `local/api/seo`, workflow n8n — `n8n`.

## Развёртывание

1. Скопируйте `internal/seo-editor/config.php.example` в `internal/seo-editor/config.php`
   и заполните только на сервере (в т.ч. `temed_seo_api_write_token`).
2. Скопируйте `local/api/seo/config.php.example` в `local/api/seo/config.php` и
   заполните `read_token` и `write_token` (различные) только на сервере.
3. Импортируйте `n8n/TEMED SEO Editor.json` и задайте секрет через переменную
   окружения/credential, не в JSON workflow.
4. Проверьте, что `config.php`, `.env`, логи, `runtime/` и `node_modules/` не
   попадают в Git.
5. После ротации ранее скомпрометированного секрета очистите историю репозитория
   внешним инструментом и выполните secret scanning.

## Сборка редактора (React + Vite)

Исходники редактора — в `internal/seo-editor/app/`, сборка кладётся в
`internal/seo-editor/dist/`. `index.php` отдаёт `dist/index.html` после
авторизации и переписывает относительные пути ассетов в `dist/assets/...`.

```bash
cd internal/seo-editor/app
npm install
npm run build      # → internal/seo-editor/dist/
```

Деплой: залить на сервер `internal/seo-editor/dist/` (+ PHP-файлы
`index.php`, `proxy.php`, `config.php`). Каталог `app/` и `node_modules/` на
проде не нужны и веб-сервером не отдаются.

## Публикация статей

Основной путь — прямая запись неактивного черновика в инфоблок 81 через write-API
(`create_or_update_draft`, см. `docs/write-api.md`). XML-экспорт (`export.php`)
оставлен как запасной механизм. Активные элементы write-API не изменяет.

Контент статьи — структурированные блоки `article_content` v2
(`docs/article-content.md`); HTML собирается детерминированно `HtmlRenderer` при
отправке в Bitrix. Проверки: TEXT.RU (внешняя, n8n) и «Каннибализация»
(`docs/cannibalization.md`). Перелинковка — `docs/linking.md`. Секреты только в
серверных config/n8n credentials, в браузер не попадают.
