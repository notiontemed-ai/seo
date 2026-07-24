# Деплой

## Сборка редактора

```bash
cd internal/seo-editor/app
npm install
npm run build      # → internal/seo-editor/dist/
```

## Что выкладывать на сервер (вручную, после бэкапа)

- `internal/seo-editor/dist/` (собранный редактор) + `index.php`, `proxy.php`.
- `local/api/seo/index.php` и `local/api/seo/lib/*`.
- При необходимости — `internal/seo-editor/export.php` и его lib (запасной XML).

Не выкладывать: `app/`, `node_modules/`, `references/`, `docs/`, `tests/`,
`config.php.example` поверх боевого `config.php`.

## Конфигурация (только на сервере, не в git)

- `local/api/seo/config.php`: `read_token` и `write_token` (различные), инфоблоки,
  `base_url`, `cache_ttl`.
- `internal/seo-editor/config.php`: `password_hash`, `temed_seo_api_url`,
  `temed_seo_api_token` (read), `temed_seo_api_write_token`, `n8n_base_url`,
  `n8n_secret`.
- n8n: импортировать `n8n/TEMED SEO Editor.json`, секрет — в credential/переменной
  окружения, не в JSON.

## Совместимость деплоя

n8n-контракт (`article_content` v2) и React-редактор совместимы между собой —
раскатывать вместе. Старый `editor.html` удалён.

## Rollback

Вернуть бэкап `dist/` и PHP-файлов, не менять `config.php`, сбросить opcode-кэш,
проверить `bootstrap`. Каталог `runtime/` (кэш корпуса) можно очистить —
пересоберётся автоматически.
