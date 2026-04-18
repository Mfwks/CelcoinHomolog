<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function webhookError(int $statusCode, string $errorCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => $errorCode,
            'message' => $message,
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
}

if ($method === 'GET') {
    $items = Cslabs::listWebhookSubscriptions();

    echo json_encode([
        'status' => 'OK',
        'items' => $items,
        'known_entities' => Cslabs::knownWebhookEntities(),
        'count' => count($items),
    ], JSON_PRETTY_PRINT);
    return;
}

if (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    webhookError(405, 'CSLAB405', 'Método não permitido para assinatura de webhook.');
    return;
}

$body = Cslabs::requestBody();

if (!is_array($body)) {
    webhookError(400, 'CSLAB400', 'Payload JSON inválido ou ausente.');
    return;
}

$entity = trim((string) ($body['entity'] ?? ''));
$url = trim((string) ($body['webhookUrl'] ?? $body['url'] ?? ''));
$auth = isset($body['auth']) && is_array($body['auth']) ? $body['auth'] : null;

if ($entity === '') {
    webhookError(422, 'CSLAB422', 'Informe a entidade do webhook.');
    return;
}

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    webhookError(422, 'CSLAB422', 'Informe uma URL de webhook válida.');
    return;
}

$record = Cslabs::saveWebhookSubscription($entity, $url, $auth, $body);

echo json_encode([
    'status' => 'OK',
    'message' => 'Webhook salvo com sucesso.',
    'entity' => $record['entity'],
    'webhookUrl' => $record['webhookUrl'],
    'auth' => $record['auth'],
    'knownEntity' => $record['known_entity'],
    'createdAt' => $record['created_at'],
    'updatedAt' => $record['updated_at'],
], JSON_PRETTY_PRINT);
