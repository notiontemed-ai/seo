<?php

declare(strict_types=1);

// Авторизация и разрешение инфоблоков. Вынесено из index.php (этап 6).
function getAuthorizationHeader(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    return trim((string)$value);
                }
            }
        }
    }

    return '';
}

function requireGetMethod(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method !== 'GET') {
        header('Allow: GET');
        temedSeoSendError('Метод не поддерживается', 405, ['allowed_methods' => ['GET']]);
    }
}

function authenticateWithToken(array $config, string $configKey): void
{
    $expectedToken = trim((string)($config[$configKey] ?? ''));
    if ($configKey === 'read_token' && $expectedToken === '' && isset($config['bearer_token'])) {
        error_log('[TEMED SEO API] Deprecated bearer_token config key is used; migrate to read_token.');
        $expectedToken = trim((string)$config['bearer_token']);
    }
    if ($expectedToken === '' || $expectedToken === 'CHANGE_ME') {
        temedSeoSendError('API_NOT_CONFIGURED', 503, ['error_code' => 'API_NOT_CONFIGURED']);
    }
    $authorization = getAuthorizationHeader();

    if (!hash_equals('Bearer ' . $expectedToken, $authorization)) {
        temedSeoSendError('Unauthorized', 401);
    }
}

function authenticate(array $config): void
{
    authenticateWithToken($config, 'read_token');
}

function getConfiguredIblockId(array $config, string $key): int
{
    $iblockId = (int)($config['iblocks'][$key] ?? 0);

    if ($iblockId <= 0) {
        temedSeoSendError(
            'В config.php не указан инфоблок',
            500,
            ['config_key' => $key]
        );
    }

    return $iblockId;
}
