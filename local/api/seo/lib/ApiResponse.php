<?php

declare(strict_types=1);

// Ответы API (JSON, success, error). Вынесено из index.php (этап 6).
function sendJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
       
    );

    exit;
}

function sendSuccess($data, array $meta = [], int $statusCode = 200): void
{
    $payload = [
        'success' => true,
        'api_version' => API_VERSION,
        'data' => $data,
    ];

    if ($meta !== []) {
        $payload['meta'] = $meta;
    }

    sendJson($payload, $statusCode);
}

function temedSeoSendError(string $message, int $statusCode, array $details = []): void
{
    $payload = [
        'success' => false,
        'api_version' => API_VERSION,
        'error' => $message,
    ];

    if (isset($details['error_code'])) {
        $payload['error_code'] = (string)$details['error_code'];
        unset($details['error_code']);
    }

    if ($details !== []) {
        $payload['details'] = $details;
    }

    sendJson($payload, $statusCode);
}
