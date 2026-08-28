<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$txid = (string) ($web->args->txid ?? '');
$body = Cslabs::requestBody();
$body = is_array($body) ? $body : [];

// Resposta, mutação do registro e webhook — nessa ordem — vivem em
// Cslabs::applyChargeCancellation, compartilhado com a rota V1 por externalId.
$response = Cslabs::applyChargeCancellation($txid, (string) ($body['reason'] ?? ''));

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
