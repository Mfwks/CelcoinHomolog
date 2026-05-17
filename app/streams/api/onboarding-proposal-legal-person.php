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
        'error' => ['errorCode' => 'CBE001', 'message' => 'Payload JSON inválido ou ausente.'],
    ], JSON_PRETTY_PRINT);
    return;
}

$response = Cslabs::onboardingProposalCreateResponse($body, 'legal-person');

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$proposalId = $response['body']['proposalId'];
$clientCode = (string) ($response['body']['clientCode'] ?? '');
$documentNumber = (string) ($response['body']['documentNumber'] ?? '');

$record = array_merge($response['body'], [
    'kind' => 'legal-person',
    'companyType' => (string) ($body['companyType'] ?? 'PJ'),
    'onboardingType' => (string) ($body['onboardingType'] ?? 'BAAS'),
    'request' => $body,
    'created_at' => date(DATE_ATOM),
]);

Cslabs::writeEntity('onboarding_proposals', $proposalId, $record);
if ($clientCode !== '') {
    Cslabs::writeEntity('onboarding_proposals_by_client_code', $clientCode, ['proposalId' => $proposalId]);
}
if ($documentNumber !== '') {
    Cslabs::writeEntity('onboarding_proposals_by_document', $documentNumber, ['proposalId' => $proposalId]);
}

$webhookUrl = Cslabs::webhookSubscriptionUrl('onboarding-proposal');
$webhookPayload = Cslabs::webhookEnvelope('onboarding-proposal', 'CREATED', [
    'proposalId' => $proposalId,
    'clientCode' => $clientCode,
    'documentNumber' => $documentNumber,
    'proposalStatus' => 'CREATED',
    'createDate' => $record['created_at'],
]);
Cslabs::scheduleWebhook('onboarding-proposal', $webhookPayload, 3, $webhookUrl);

http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
