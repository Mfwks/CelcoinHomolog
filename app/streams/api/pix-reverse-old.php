<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$transactionId = (string) ($web->args->transactionId ?? '');
$body = Cslabs::requestBody();
$body = is_array($body) ? $body : [];

$response = Cslabs::pixReverseResponse($body, $transactionId);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$webhookUrl = Cslabs::webhookSubscriptionUrl('pix-reversal-out');
Cslabs::scheduleWebhook(
    'pix-reversal-out',
    Cslabs::webhookEnvelope('pix-reversal-out', 'CONFIRMED', $response['body']),
    3,
    $webhookUrl
);

http_response_code(201);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
