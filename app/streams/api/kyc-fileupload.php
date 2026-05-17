<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$response = Cslabs::kycFileUploadResponse($_POST, $_FILES);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
}

if (($response['status'] ?? null) === 'SUCCESS') {
    $webhookUrl = Cslabs::webhookSubscriptionUrl('kyc');
    $webhookPayload = Cslabs::webhookEnvelope('kyc', 'CONFIRMED', [
        'fileId' => $response['body']['fileId'],
        'onboardingId' => $response['body']['onboardingId'],
        'filetype' => $response['body']['filetype'],
        'status' => 'CONFIRMED',
        'createDate' => $response['body']['createDate'],
    ]);
    Cslabs::scheduleWebhook('kyc', $webhookPayload, 3, $webhookUrl);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
