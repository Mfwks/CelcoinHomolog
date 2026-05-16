<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $clientCode = trim((string) ($_GET['clienteCode'] ?? $_GET['clientCode'] ?? ''));

    if ($clientCode === '') {
        http_response_code(422);
        echo json_encode([
            'version' => '1.0.0',
            'status' => 'ERROR',
            'error' => ['errorCode' => 'CBE014', 'message' => 'clienteCode é obrigatório.'],
        ], JSON_PRETTY_PRINT);
        return;
    }

    $alias = Cslabs::readEntity('spb_transfers_by_client_code', $clientCode);
    $state = is_array($alias) && !empty($alias['id'])
        ? Cslabs::readEntity('spb_transfers', $alias['id'])
        : false;

    if (!is_array($state)) {
        http_response_code(404);
        echo json_encode(Cslabs::transferNotFoundError(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return;
    }

    $elapsed = time() - (int) ($state['created_ts'] ?? time());
    $confirmed = ($state['status'] ?? 'PROCESSING') === 'CONFIRMED' || $elapsed >= 2;

    echo json_encode([
        'status' => $confirmed ? 'CONFIRMED' : 'PROCESSING',
        'version' => '1.0.0',
        'body' => $state['body'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'version' => '1.0.0',
        'status' => 'ERROR',
        'error' => ['errorCode' => 'CBE001', 'message' => 'Payload JSON inválido ou ausente.'],
    ], JSON_PRETTY_PRINT);
    return;
}

$response = Cslabs::spbTransferResponse($body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$id = $response['body']['id'];
$clientCode = $response['body']['clientCode'];

Cslabs::writeEntity('spb_transfers', $id, [
    'id' => $id,
    'status' => 'PROCESSING',
    'body' => $response['body'],
    'created_ts' => time(),
    'created_at' => date(DATE_ATOM),
]);
Cslabs::writeEntity('spb_transfers_by_client_code', $clientCode, ['id' => $id]);

$webhookUrl = Cslabs::webhookSubscriptionUrl('spb-transfer-out');
Cslabs::scheduleWebhook('spb-transfer-out', [
    'entity' => 'spb-transfer-out',
    'status' => 'CONFIRMED',
    'body' => array_merge($response['body'], [
        'originalId' => $id,
        'description' => (string) ($body['description'] ?? ''),
        'clientFinality' => (string) ($body['clientFinality'] ?? '10'),
        'numCtrlStr' => 'STR' . date('Ymd') . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
    ]),
], 3, $webhookUrl);

http_response_code(201);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
