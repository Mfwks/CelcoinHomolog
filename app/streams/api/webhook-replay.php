<?php

# Webhook replay (celcoinv2 CelcoinV2WebhookService):
#   GET  /baas/v2/webhook/replay/{entity}          -> quantidade
#   GET  /baas/v2/webhook/replay/{entity}/details  -> detalhes (paginado)
#   PUT  /baas/v2/webhook/replay/{entity}          -> reenviar
# Shapes reais no Apêndice A do doc. totalItems/details derivam da inscrição
# real do entity (Cslabs::webhookSubscription) quando existe.

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$entity = (string) ($web->args->entity ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isDetails = str_contains((string) ($web->url ?? $_SERVER['REQUEST_URI'] ?? ''), '/details');
$onlyPending = isset($_GET['OnlyPending']) ? filter_var($_GET['OnlyPending'], FILTER_VALIDATE_BOOLEAN) : false;

$subscription = Cslabs::webhookSubscription($entity);
$hasSub = is_array($subscription);
$total = $hasSub ? 1 : 0;
$webhookUrl = $hasSub ? (string) ($subscription['webhookUrl'] ?? '') : '';

if ($method === 'GET' && $isDetails) {
    $details = [];
    if ($hasSub) {
        $details[] = [
            'webhookId' => substr(md5($entity . $webhookUrl), 0, 32),
            'httpStatusCode' => 200,
            'webhookUrl' => $webhookUrl,
            'request' => json_encode([
                'entity' => $entity,
                'status' => 'CONFIRMED',
                'body' => (object) [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    echo json_encode([
        'body' => [
            'limit' => (int) ($_GET['Limit'] ?? 200),
            'currentPage' => (int) ($_GET['Page'] ?? 1),
            'limitPerPage' => (int) ($_GET['LimitPerPage'] ?? 50),
            'totalPages' => 1,
            'webhookDetails' => $details,
        ],
        'status' => 'SUCCESS',
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if ($method === 'PUT') {
    echo json_encode([
        'body' => [
            'onlyPending' => $onlyPending,
            'entity' => $entity,
            'dateFrom' => $_GET['DateFrom'] ?? null,
            'dateTo' => $_GET['DateTo'] ?? null,
            'totalItems' => $total,
        ],
        'status' => 'SUCCESS',
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

# GET quantidade
echo json_encode([
    'body' => [
        'onlyPending' => $onlyPending,
        'entity' => $entity,
        'totalItems' => $total,
    ],
    'status' => 'SUCCESS',
    'version' => '1.0.0',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
