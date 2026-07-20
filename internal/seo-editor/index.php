<?php

declare(strict_types=1);

$https = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
);

session_name('TEMED_SEO_EDITOR');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Strict',
]);

session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$configFile = __DIR__ . '/config.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('Не найден config.php. Скопируйте config.php.example в config.php и укажите password_hash.');
}

$config = require $configFile;
$passwordHash = trim((string)($config['password_hash'] ?? ''));

if ($passwordHash === '' || $passwordHash === 'PASTE_PASSWORD_HASH_HERE') {
    http_response_code(500);
    exit('В config.php не настроен password_hash.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: ./');
    exit;
}

$error = '';
$now = time();
$blockedUntil = (int)($_SESSION['login_blocked_until'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editor_password'])) {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Сессия устарела. Обновите страницу и повторите вход.';
    } elseif ($blockedUntil > $now) {
        $error = 'Слишком много попыток. Повторите вход через несколько минут.';
    } else {
        $password = (string)$_POST['editor_password'];

        if (password_verify($password, $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['seo_editor_authenticated'] = true;
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_blocked_until'] = 0;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: ./');
            exit;
        }

        $attempts = (int)($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['login_attempts'] = $attempts;

        if ($attempts >= 5) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['login_blocked_until'] = $now + 300;
            $error = 'Слишком много попыток. Вход заблокирован на 5 минут.';
        } else {
            $error = 'Неверный пароль.';
        }
    }
}

if (empty($_SESSION['seo_editor_authenticated'])) {
    ?>
    <!doctype html>
    <html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Вход · TEMED SEO</title>
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                background: #f4f6f3;
                color: #14212b;
                font: 14px/1.5 Arial, sans-serif;
            }
            form {
                width: min(360px, calc(100% - 32px));
                padding: 24px;
                background: #fff;
                border: 1px solid #dde3dd;
                border-radius: 12px;
            }
            h1 { margin: 0 0 18px; font-size: 22px; }
            label { display: block; margin-bottom: 6px; font-weight: 700; }
            input {
                width: 100%;
                box-sizing: border-box;
                padding: 10px 12px;
                border: 1px solid #ccd5ce;
                border-radius: 8px;
                font: inherit;
            }
            button {
                width: 100%;
                margin-top: 14px;
                padding: 10px 12px;
                border: 0;
                border-radius: 8px;
                background: #0d8a86;
                color: #fff;
                font: inherit;
                font-weight: 700;
                cursor: pointer;
            }
            .error {
                margin-bottom: 12px;
                padding: 9px 11px;
                border-radius: 8px;
                background: #fbeeea;
                color: #8c3d2e;
            }
        </style>
    </head>
    <body>
        <form method="post" autocomplete="off">
            <h1>TEMED SEO</h1>

            <?php if ($error !== ''): ?>
                <div class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            >

            <label for="editor_password">Пароль</label>
            <input
                id="editor_password"
                name="editor_password"
                type="password"
                required
                autofocus
                autocomplete="current-password"
            >

            <button type="submit">Войти</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

$editorFile = __DIR__ . '/editor.html';

if (!is_file($editorFile) || !is_readable($editorFile)) {
    http_response_code(500);
    exit('Файл editor.html недоступен.');
}

$html = file_get_contents($editorFile);

if ($html === false) {
    http_response_code(500);
    exit('Не удалось прочитать editor.html.');
}

$cssFile = __DIR__ . '/assets/css/editor.css';
$jsFile = __DIR__ . '/assets/js/editor.js';

function assetVersion(string $file): string
{
    if (!is_file($file) || !is_readable($file)) {
        return '';
    }

    $hash = hash_file('sha256', $file);

    if (!is_string($hash) || $hash === '') {
        return '';
    }

    return substr($hash, 0, 12);
}

$cssVersion = assetVersion($cssFile);
$jsVersion = assetVersion($jsFile);

if ($cssVersion === '' || $jsVersion === '') {
    error_log('TEMED SEO Editor asset file is unavailable or unreadable.');
    http_response_code(500);
    exit('Файлы интерфейса редактора недоступны.');
}

$html = str_replace(
    [
        '__EDITOR_CSS_VERSION__',
        '__EDITOR_JS_VERSION__',
    ],
    [
        htmlspecialchars($cssVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($jsVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ],
    $html
);

$logout = <<<'HTML'
<a
    href="?logout=1"
    style="position:fixed;right:16px;bottom:16px;z-index:9999;padding:8px 12px;border-radius:8px;background:#0f1b23;color:#fff;text-decoration:none;font:13px Arial,sans-serif"
>Выйти</a>
HTML;

$html = str_replace('</body>', $logout . "\n</body>", $html);

echo $html;
