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

$response = Cslabs::chargeResponse($body);

if (($response['status'] ?? null) !== 'SUCCESS') {
    http_response_code(422);
    echo json_encode($response, JSON_PRETTY_PRINT);
    return;
}

$transactionId = $response['body']['transactionId'];
$externalId = (string) ($body['externalId'] ?? '');
$amount = round((float) ($body['amount'] ?? 0), 2);
$receiverAccount = (string) ($body['receiver']['account'] ?? '');
$bankLine = Cslabs::boletoBankLine($transactionId, $amount, (string) ($body['duedate'] ?? ''));

$charge = [
    'transactionId' => $transactionId,
    'externalId' => $externalId,
    'amount' => $amount,
    'duedate' => (string) ($body['duedate'] ?? ''),
    'key' => (string) ($body['key'] ?? ''),
    'debtor' => $body['debtor'] ?? null,
    'receiver' => $body['receiver'] ?? null,
    'bankLine' => $bankLine,
    'status' => 'PENDING',
    'created_at' => date(DATE_ATOM),
];

Cslabs::writeEntity('charges', $transactionId, $charge);
if ($externalId !== '') {
    Cslabs::writeEntity('charges_by_external_id', $externalId, ['transactionId' => $transactionId]);
}

$webhookUrl = Cslabs::webhookSubscriptionUrl('charge-create');
$webhookPayload = [
    'entity' => 'charge-create',
    'status' => 'SUCCESS',
    'body' => [
        'transactionId' => $transactionId,
        'externalId' => $externalId,
        'boleto' => [
            'bankLine' => $bankLine,
            'bankAccount' => $receiverAccount,
            'bankAgency' => '0001',
            'status' => 'PENDING',
        ],
    ],
];

Cslabs::scheduleWebhook('charge-create', $webhookPayload, 2, $webhookUrl);

http_response_code(201);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
