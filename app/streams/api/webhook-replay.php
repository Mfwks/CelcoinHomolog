<?php

# Webhook replay (celcoinv2 CelcoinV2WebhookService):
#   GET  /baas/v2/webhook/replay/{entity}          -> quantidade
#   GET  /baas/v2/webhook/replay/{entity}/details  -> detalhes (paginado)
#   PUT  /baas/v2/webhook/replay/{entity}          -> reenviar
# Shapes reais no Apêndice A do doc (validados no corpus multi-tenant). totalItems
# e o item de details derivam da inscrição real do entity quando existe.

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$entity = (string) ($web->args->entity ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isDetails = str_contains((string) ($web->url ?? $_SERVER['REQUEST_URI'] ?? ''), '/details');
$onlyPending = isset($_GET['OnlyPending']) ? filter_var($_GET['OnlyPending'], FILTER_VALIDATE_BOOLEAN) : false;

# Real sempre traz dateFrom/dateTo em ISO-8601 com Z (default: janela de 7 dias).
$dateFrom = trim((string) ($_GET['DateFrom'] ?? '')) ?: gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400);
$dateTo = trim((string) ($_GET['DateTo'] ?? '')) ?: gmdate('Y-m-d\TH:i:s\Z');

$subscription = Cslabs::webhookSubscription($entity);
$hasSub = is_array($subscription);
$total = $hasSub ? 1 : 0;
$webhookUrl = $hasSub ? (string) ($subscription['webhookUrl'] ?? '') : '';

if ($method === 'GET' && $isDetails) {
    $details = [];
    if ($hasSub) {
        $webhookId = substr(md5($entity . $webhookUrl), 0, 32);
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $details[] = [
            'webhookId' => $webhookId,
            'httpStatusCode' => null,
            'webhookUrl' => null,
            'request' => json_encode([
                'createTimestamp' => $now,
                'entity' => $entity,
                'status' => 'CONFIRMED',
                'body' => (object) [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'response' => null,
            'status' => 'PENDENTE',
            'createDate' => $now,
            'lastUpdateDate' => $now,
            'filter' => [
                'documentNumber' => null,
                'account' => null,
                'id' => $webhookId,
                'clientRequestId' => null,
            ],
        ];
    }

    echo json_encode([
        'body' => [
            'limit' => (int) ($_GET['Limit'] ?? 200),
            'currentPage' => (int) ($_GET['Page'] ?? 1),
            'limitPerPage' => (int) ($_GET['LimitPerPage'] ?? 50),
            'totalPages' => 1,
            'webhookDetails' => $details,
            'onlyPending' => $onlyPending,
            'entity' => $entity,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'totalItems' => $total,
        ],
        'status' => 'SUCCESS',
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

# GET quantidade e PUT reenviar têm o mesmo corpo {onlyPending,entity,dateFrom,dateTo,totalItems}.
echo json_encode([
    'body' => [
        'onlyPending' => $onlyPending,
        'entity' => $entity,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'totalItems' => $total,
    ],
    'status' => 'SUCCESS',
    'version' => '1.0.0',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
