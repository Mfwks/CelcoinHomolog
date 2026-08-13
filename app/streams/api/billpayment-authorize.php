<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : $_GET;

header('Content-Type: application/json');

$response = Cslabs::billPaymentAuthorizeResponse($body);

// Duas formas de erro, e é de propósito: os cenários do mock saem no envelope
// `{status:'ERROR', error:{…}}`, enquanto o 822 sai PLANO — porque é assim que a
// Celcoin real o devolve (ver Cslabs::billPaymentDigitableError). O `errorCode`
// de topo distingue: no sucesso ele vale '000'.
$erro = ($response['status'] ?? null) === 'ERROR'
    || (isset($response['errorCode']) && $response['errorCode'] !== '000' && !isset($response['assignor']));

if ($erro) {
    http_response_code(Cslabs::scenarioHttpStatus(Cslabs::lastErrorScenario()));
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
