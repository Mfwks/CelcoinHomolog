<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode([
        'webhook_url' => Cslabs::webhookUrl(),
        'settings' => Cslabs::clientSettings(),
    ], JSON_PRETTY_PRINT);
    return;
}

if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    http_response_code(405);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB405',
            'message' => 'Método não permitido para configuração de webhook.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB400',
            'message' => 'Payload JSON inválido ou ausente.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$url = trim((string) ($body['webhookUrl'] ?? $body['url'] ?? ''));

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(422);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB422',
            'message' => 'Informe uma URL de webhook válida.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$settings = Cslabs::updateClientSettings([
    'webhook_url' => $url,
    'webhook_updated_at' => date(DATE_ATOM),
    'webhook_source' => 'config_endpoint',
]);

echo json_encode([
    'status' => 'OK',
    'message' => 'Webhook configurado com sucesso.',
    'webhook_url' => $settings['webhook_url'] ?? null,
], JSON_PRETTY_PRINT);
