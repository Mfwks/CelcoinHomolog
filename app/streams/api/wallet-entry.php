<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$account = (string) ($web->args->account ?? '');
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

$response = Cslabs::walletEntryResponse($account, $body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
}

if (($response['status'] ?? null) === 'SUCCESS') {
    Cslabs::writeEntity('wallet_entries', $response['body']['id'], $response['body']);
    Cslabs::scheduleWebhook('launch-' . strtolower($response['body']['type']), [
        'entity' => 'launch-' . strtolower($response['body']['type']),
        'status' => 'CONFIRMED',
        'body' => $response['body'],
    ], 2, Cslabs::webhookSubscriptionUrl('launch-' . strtolower($response['body']['type'])));
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
