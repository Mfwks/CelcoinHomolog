<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => [
            'errorCode' => 'CBE001',
            'message' => 'Payload JSON inválido ou ausente.',
        ],
    ], JSON_PRETTY_PRINT);
    return;
}

$response = Cslabs::onboardingResponse($body, 'natural-person');

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$onboardingId = $response['body']['onBoardingId'];
$clientCode = (string) ($body['clientCode'] ?? '');
$documentNumber = (string) ($body['documentNumber'] ?? '');
$account = Cslabs::generateAccountNumber($onboardingId . '|' . $documentNumber);

$record = [
    'onboardingId' => $onboardingId,
    'clientCode' => $clientCode,
    'documentNumber' => $documentNumber,
    'kind' => 'natural-person',
    'status' => 'PROCESSING',
    'account' => [
        'account' => $account,
        'branch' => '0001',
    ],
    'request' => $body,
    'created_at' => date(DATE_ATOM),
];

Cslabs::writeEntity('onboardings', $onboardingId, $record);
if ($clientCode !== '') {
    Cslabs::writeEntity('onboardings_by_client_code', $clientCode, ['onboardingId' => $onboardingId]);
}
if ($documentNumber !== '') {
    Cslabs::writeEntity('onboardings_by_document', $documentNumber, ['onboardingId' => $onboardingId]);
}

$webhookUrl = Cslabs::webhookSubscriptionUrl('onboarding-create');
$webhookPayload = Cslabs::webhookEnvelope('onboarding-create', 'CONFIRMED', [
    'account' => array_merge($record['account'], [
        'name' => (string) ($body['socialName'] ?? $body['name'] ?? ''),
        'documentNumber' => $documentNumber,
        'ispb' => '13935893',
    ]),
    'onboardingId' => $onboardingId,
    'clientCode' => $clientCode,
    'createDate' => $record['created_at'],
]);

Cslabs::scheduleWebhook('onboarding-create', $webhookPayload, 3, $webhookUrl);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
