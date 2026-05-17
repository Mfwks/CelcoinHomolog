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

$response = Cslabs::billPaymentResponse($body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
    echo json_encode($response, JSON_PRETTY_PRINT);
    return;
}

$id = $response['body']['id'];
$clientRequestId = (string) $response['body']['clientRequestId'];
$transactionIdAuthorize = $response['body']['transactionIdAuthorize'];
$digitable = (string) ($response['body']['barCodeInfo']['digitable'] ?? '');
$account = (string) ($body['account'] ?? '');
$amount = round((float) ($body['amount'] ?? 0), 2);

$state = [
    'id' => $id,
    'clientRequestId' => $clientRequestId,
    'transactionIdAuthorize' => $transactionIdAuthorize,
    'account' => $account,
    'amount' => $amount,
    'digitable' => $digitable,
    'hasOccurrence' => false,
    'status' => 'PROCESSING',
    'created_ts' => time(),
    'created_at' => date(DATE_ATOM),
];

Cslabs::writeEntity('bill_payments', $id, $state);
if ($clientRequestId !== '') {
    Cslabs::writeEntity('bill_payments_by_client_request_id', $clientRequestId, ['id' => $id]);
}
if ($transactionIdAuthorize !== null) {
    Cslabs::writeEntity('bill_payments_by_authorize', (string) $transactionIdAuthorize, ['id' => $id]);
}

$webhookUrl = Cslabs::webhookSubscriptionUrl('billpayment');
$webhookPayload = Cslabs::webhookEnvelope('billpayment', 'CONFIRMED', [
    'account' => $account,
    'amount' => $amount,
    'barCodeInfo' => [
        'type' => 1,
        'digitable' => $digitable,
        'barCode' => null,
    ],
    'clientRequestId' => $clientRequestId,
    'id' => $id,
    'tags' => [],
    'transactionIdAuthorize' => $transactionIdAuthorize,
    'authentication' => random_int(1000, 9999),
    'authenticationAPI' => [
        'bloco1' => strtoupper(substr(hash('sha1', $id . '1'), 0, 16)),
        'bloco2' => strtoupper(substr(hash('sha1', $id . '2'), 0, 16)),
        'blocoCompleto' => strtoupper(substr(hash('sha1', $id . '1'), 0, 16) . substr(hash('sha1', $id . '2'), 0, 16)),
    ],
    'convenant' => 'CIP CELCOIN',
    'createDate' => $state['created_at'],
    'isExpired' => false,
    'receipt' => [
        'receiptData' => '',
        'receiptformatted' => '',
    ],
    'settleDate' => date('Y-m-d\T00:00:00'),
    'status' => 'CONFIRMED',
    'transactionId' => $transactionIdAuthorize,
]);

Cslabs::scheduleWebhook('billpayment', $webhookPayload, 3, $webhookUrl);

http_response_code(201);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
