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
$webhookPayload = [
    'entity' => 'billpayment',
    'status' => 'CONFIRMED',
    'body' => [
        'clientRequestId' => $clientRequestId,
        'amount' => $amount,
        'id' => $id,
    ],
];

Cslabs::scheduleWebhook('billpayment', $webhookPayload, 3, $webhookUrl);

http_response_code(201);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
