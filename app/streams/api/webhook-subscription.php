<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

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
    http_response_code(405);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB405',
            'message' => 'Método não permitido para assinatura de webhook.',
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

$entity = trim((string) ($body['entity'] ?? ''));
$url = trim((string) ($body['webhookUrl'] ?? $body['url'] ?? ''));
$auth = isset($body['auth']) && is_array($body['auth']) ? $body['auth'] : null;

if ($entity === '') {
    http_response_code(422);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB422',
            'message' => 'Informe a entidade do webhook.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

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
