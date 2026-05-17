<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

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

$amount = isset($body['amount']) ? (float) $body['amount'] : 0.0;
$clientRequestId = trim((string) ($body['clientRequestId'] ?? ''));
$debitAccount = trim((string) ($body['debitParty']['account'] ?? ''));
$creditAccount = trim((string) ($body['creditParty']['account'] ?? ''));
$description = trim((string) ($body['description'] ?? ''));

if ($amount <= 0 || $clientRequestId === '' || $debitAccount === '' || $creditAccount === '') {
    http_response_code(422);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB422',
            'message' => 'Campos obrigatórios ausentes para transferência interna.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$existing = Cslabs::readEntity('internal_transfers', $clientRequestId);

if ($existing !== false) {
    echo json_encode($existing['response'], JSON_PRETTY_PRINT);
    return;
}

$transactionId = gerarHashMock();
$transfer = [
    'transactionId' => $transactionId,
    'clientRequestId' => $clientRequestId,
    'amount' => round($amount, 2),
    'status' => 'PROCESSING',
    'description' => $description !== '' ? $description : 'Transferencia interna',
    'debitParty' => [
        'account' => $debitAccount,
    ],
    'creditParty' => [
        'account' => $creditAccount,
    ],
    'createdAt' => date(DATE_ATOM),
];

$response = [
    'transactionId' => $transactionId,
    'clientRequestId' => $clientRequestId,
    'status' => 'PROCESSING',
    'amount' => round($amount, 2),
    'message' => 'Transferência interna recebida com sucesso.',
];

$webhookBody = [
    'id' => $transactionId,
    'amount' => round($amount, 2),
    'clientRequestId' => $clientRequestId,
    'creditParty' => $transfer['creditParty'],
    'debitParty' => $transfer['debitParty'],
    'endToEndId' => gerarHashMock(),
    'description' => $transfer['description'],
    'oldBalance' => null,
    'currentBalance' => null,
];

$webhookPayload = Cslabs::webhookEnvelope('internal-transfer-out', 'CONFIRMED', $webhookBody);
$webhookUrl = Cslabs::webhookSubscriptionUrl('internal-transfer-out');

Cslabs::writeEntity('internal_transfers', $clientRequestId, [
    'request' => $body,
    'transfer' => $transfer,
    'response' => $response,
    'webhook' => $webhookPayload,
    'webhook_url' => $webhookUrl,
]);

Cslabs::scheduleWebhook('internal-transfer-out', $webhookPayload, 2, $webhookUrl);

http_response_code(201);
echo json_encode($response, JSON_PRETTY_PRINT);
