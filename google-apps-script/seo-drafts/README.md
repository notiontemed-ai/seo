# TEMED SEO Editor drafts Apps Script

Web App endpoint for `/internal/seo-editor/drafts.php`. The browser never calls this URL directly: PHP adds the shared secret and server-side editor user.

## Script Properties

Set in **Project Settings → Script properties**:

- `SPREADSHEET_ID` = `1DEpgU7rR7IsY0jF-Aarm25sFF4RkqPeEn2jm76DaBto`
- `TEMED_SEO_DRAFTS_SECRET` = random shared secret (do not commit it)
- `TEMED_SEO_DRAFTS_FOLDER_ID` = optional; created automatically after first `health`
- `TEST_SPREADSHEET_ID` = optional test spreadsheet for manual test functions

## Deploy

1. Create an Apps Script project.
2. Copy `Code.gs` and `appsscript.json`.
3. Set Script Properties.
4. Deploy → New deployment → Web app.
5. Execute as: the script owner with access to the spreadsheet and Drive.
6. Who has access: restricted according to the TEMED Google Workspace policy.
7. Copy the Web App URL into `internal/seo-editor/config.php` as `drafts_web_app_url`.

## Sheet headers

The script reads the first row and extracts the column code after the last line break, for example `Название\nNAME`. It does not rely on fixed column numbers.

## Tests

The `test_*` functions cover pure helpers (UUID, stable JSON, SHA-256, formula injection and version filenames) and are safe for production. End-to-end create/save/delete tests must be run only against a separate spreadsheet configured through `TEST_SPREADSHEET_ID`.
