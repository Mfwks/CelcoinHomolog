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

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$kind = str_contains($path, '/business/') ? 'business' : 'natural-person';
$response = Cslabs::onboardingBulkResponse($body, $kind);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
} elseif (($response['status'] ?? null) === 'PARTIAL') {
    http_response_code(207);
}

foreach ($response['body']['items'] ?? [] as $item) {
    if (($item['status'] ?? null) !== 'PROCESSING') {
        continue;
    }
    $onboardingId = $item['onBoardingId'];
    $documentNumber = (string) ($item['documentNumber'] ?? '');
    $clientCode = (string) ($item['clientCode'] ?? '');
    $account = Cslabs::generateAccountNumber($onboardingId . '|' . $documentNumber);

    Cslabs::writeEntity('onboardings', $onboardingId, [
        'onboardingId' => $onboardingId,
        'clientCode' => $clientCode,
        'documentNumber' => $documentNumber,
        'kind' => $kind,
        'status' => 'PROCESSING',
        'account' => ['account' => $account, 'branch' => '0001'],
        'bulk' => true,
        'created_at' => date(DATE_ATOM),
    ]);
    if ($clientCode !== '') {
        Cslabs::writeEntity('onboardings_by_client_code', $clientCode, ['onboardingId' => $onboardingId]);
    }
    if ($documentNumber !== '') {
        Cslabs::writeEntity('onboardings_by_document', $documentNumber, ['onboardingId' => $onboardingId]);
    }

    $webhookUrl = Cslabs::webhookSubscriptionUrl('onboarding-create');
    $webhookPayload = Cslabs::webhookEnvelope('onboarding-create', 'CONFIRMED', [
        'account' => ['account' => $account, 'branch' => '0001', 'documentNumber' => $documentNumber, 'ispb' => '13935893'],
        'onboardingId' => $onboardingId,
        'clientCode' => $clientCode,
        'createDate' => date(DATE_ATOM),
    ]);
    Cslabs::scheduleWebhook('onboarding-create', $webhookPayload, 3, $webhookUrl);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
