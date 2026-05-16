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
        'error' => ['errorCode' => 'CSLAB400', 'message' => 'Payload JSON inválido ou ausente.'],
    ], JSON_PRETTY_PRINT);
    return;
}

$response = Cslabs::pixReverseResponse($body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$webhookUrl = Cslabs::webhookSubscriptionUrl('pix-reversal-out');
Cslabs::scheduleWebhook('pix-reversal-out', [
    'entity' => 'pix-reversal-out',
    'status' => 'CONFIRMED',
    'body' => $response['body'],
], 3, $webhookUrl);

http_response_code(201);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
