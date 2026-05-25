<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$entityFromPath = (string) ($web->args->entity ?? '');
$entityFromQuery = trim((string) ($_GET['Entity'] ?? $_GET['entity'] ?? ''));

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

    if ($entityFromQuery !== '') {
        $items = array_values(array_filter($items, fn ($item) => ($item['entity'] ?? null) === $entityFromQuery));
    }

    $active = isset($_GET['Active']) ? filter_var($_GET['Active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    if ($active !== null) {
        $items = array_values(array_filter($items, fn ($item) => (bool) ($item['active'] ?? true) === $active));
    }

    $subscriptions = array_map(fn ($r) => Cslabs::webhookSubscriptionView($r), $items);

    echo json_encode([
        'body' => [
            'subscriptions' => $subscriptions,
        ],
        'status'  => 'SUCCESS',
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

if ($method === 'DELETE') {
    $entity = $entityFromPath !== '' ? $entityFromPath : $entityFromQuery;

    if ($entity === '') {
        webhookError(422, 'CSLAB422', 'Informe a entidade do webhook a remover.');
        return;
    }

    $existed = Cslabs::webhookSubscription($entity);

    if ($existed === false) {
        webhookError(404, 'CSLAB404', 'Inscrição de webhook não encontrada.');
        return;
    }

    Cslabs::deleteWebhookSubscription($entity);

    echo json_encode([
        'version' => '1.0.0',
        'status' => 'SUCCESS',
        'body' => [
            'subscriptionId' => $existed['subscriptionId'] ?? null,
            'entity' => $entity,
            'message' => 'Webhook removido com sucesso.',
        ],
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

$entity = trim((string) ($body['entity'] ?? $entityFromPath));
$url = trim((string) ($body['webhookUrl'] ?? $body['url'] ?? ''));
$auth = isset($body['auth']) && is_array($body['auth']) ? $body['auth'] : null;
$active = array_key_exists('active', $body) ? (bool) $body['active'] : true;

if ($entity === '') {
    webhookError(422, 'CSLAB422', 'Informe a entidade do webhook.');
    return;
}

if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
    webhookError(422, 'CSLAB422', 'Informe uma URL de webhook válida.');
    return;
}

$record = Cslabs::saveWebhookSubscription($entity, $url, $auth, $body, $active);

echo json_encode([
    'version' => '1.0.0',
    'status' => 'SUCCESS',
    'body' => [
        'subscriptionId' => $record['subscriptionId'],
        'entity' => $record['entity'],
        'webhookUrl' => $record['webhookUrl'],
        'auth' => $record['auth'],
        'active' => $record['active'],
        'knownEntity' => $record['known_entity'],
        'createDate' => $record['created_at'],
        'lastUpdateDate' => $record['updated_at'],
    ],
], JSON_PRETTY_PRINT);
