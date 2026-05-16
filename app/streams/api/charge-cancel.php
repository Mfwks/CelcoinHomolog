<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$txid = (string) ($web->args->txid ?? '');
$body = Cslabs::requestBody();
$body = is_array($body) ? $body : [];

$response = Cslabs::chargeCancelResponse($txid, $body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
}

if (($response['status'] ?? null) === 'SUCCESS') {
    $existing = Cslabs::readEntity('charges', $txid);
    if (is_array($existing)) {
        $existing['status'] = 'CANCELLED';
        $existing['cancelled_at'] = date(DATE_ATOM);
        Cslabs::writeEntity('charges', $txid, $existing);
    }

    Cslabs::scheduleWebhook('charge-canceled', [
        'entity' => 'charge-canceled',
        'status' => 'CONFIRMED',
        'body' => ['transactionId' => $txid, 'status' => 'Cancelado', 'reason' => (string) ($body['reason'] ?? '')],
    ], 2, Cslabs::webhookSubscriptionUrl('charge-canceled'));
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
