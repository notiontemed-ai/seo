# Архитектура TEMED SEO Editor

Актуально для API 1.8.0.

## Стек и границы

- **PHP API** — `local/api/seo/index.php` + `lib/*`. Read-действия (справочники
  Bitrix) и POST-действия: проверки (`cannibalization_check`), перелинковка
  (`linking_candidates`) и запись черновика (`create_or_update_draft`).
- **Редактор** — React + Vite. Исходники `internal/seo-editor/app/`, сборка
  `internal/seo-editor/dist/`. `index.php` редактора отдаёт `dist/index.html`
  после авторизации по паролю (сессия).
- **proxy** — `internal/seo-editor/proxy.php`. Браузер ходит только в proxy
  (same-origin). Proxy маршрутизирует: GET read-действия и POST-проверки/запись
  → PHP API (read/write-токены на сервере); AI/TEXT.RU/черновики/транскрибация →
  n8n. Токены и секреты в браузер не попадают.
- **n8n** — `n8n/TEMED SEO Editor.json`. ИИ-генерация (`article_content` v2),
  TEXT.RU, ассистент, `suggest_anchor`, черновики (Google Sheets), транскрибация.

## Контент

Статья — структурированные блоки `article_content` v2 (не HTML), см.
`article-content.md`. HTML собирается детерминированно `HtmlRenderer` (PHP при
записи в Bitrix, JS при предпросмотре в редакторе).

## Публикация

Основной путь — write-API `create_or_update_draft` (см. `write-api.md`): прямая
запись **неактивного** элемента в инфоблок 81. Активные элементы не изменяются.
XML-экспорт (`internal/seo-editor/export.php`) — запасной механизм.

## Модули PHP API (после разнесения, этап 6)

- `lib/ApiResponse.php` — JSON-ответы (success/error).
- `lib/ApiAuth.php` — авторизация (read/write-токены), разрешение инфоблоков.
- `lib/ApiSupport.php` — helpers: URL, параметры запроса, текст, свойства.
- `lib/ReadActions.php` — обработчики read-действий (Bitrix) + capabilities.
- `lib/ArticleContent.php`, `HtmlRenderer.php`, `HtmlToBlocksParser.php` —
  контент v2 и рендер.
- `lib/CannibalizationService.php`, `CorpusCache.php`, `DonorLinkParser.php`,
  `LinkingService.php`, `TextSignals.php` — проверки и перелинковка.
- `lib/ArticleDraftWriter.php`, `ContentReferenceResolver.php` — запись.
- `index.php` — bootstrap: конфиг, авторизация, диспетчеризация действий.

Helpers и read-обработчики оставлены глобальными функциями в модулях (не
переведены в классы), чтобы не менять ~80 мест вызова и гарантировать
неизменность поведения read-действий (совместимость с n8n и внешними
потребителями).

## Восстановленные функции (этап 8)

- **Поля статьи** — SEO title/meta description (IPROPERTY через write-API),
  SHORT_ANSWER, SOURCES (синхронизация из блока `sources`), пикеры
  RELATED_ARTICLES/RELATED_ARTICLES_V2/RELATED_SERVICES/RELATED_CLINICS с
  поиском и чипсами, даты 855/856 (дефолт «сегодня»), FEATURED_IMAGE_ALT.
- **Кейсовый вход** — транскрибация показывает аннотацию и темы
  (`transcribe_case`); выбор темы предзаполняет шаг «Задача» (название,
  запрос, транслит-код, тип по эвристике), кейс прикрепляется блоком
  `case_study`; темы сразу проверяются лёгкой каннибализацией
  (только запросы/заголовки).
- **Устаревание проверок** — хеш контента (blocks + name + запросы)
  фиксируется при запуске TEXT.RU/каннибализации/перелинковки; при изменении
  контента результат помечается «устарел»; активная проверка TEXT.RU
  переживает перезагрузку (sessionStorage: text_uid, content_hash, attempt);
  шаг публикации предупреждает об устаревших/незапущенных проверках.
- **Внешний ассистент генерации** — переключатель встроенный/внешний;
  задание (бриф + структура + разрешённые блоки + контракт article_content
  v2) копируется в буфер; результат валидируется той же схемой, что у
  встроенной генерации; хеш-механика прежней версии не восстановлена.

## Безопасность

- Read-действия — `Authorization: Bearer <read_token>`; write-действия —
  отдельный `<write_token>`. Отсутствие/пусто/`CHANGE_ME` → 503
  `API_NOT_CONFIGURED` (fail-closed).
- Чувствительные свойства (токены, пароли) исключаются из выдачи; свойства
  врачей — по allowlist.
- Медицинский текст не переписывается автоматически ни одной проверкой.
