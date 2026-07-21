<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : $_GET;

header('Content-Type: application/json');

$response = Cslabs::pixPaymentResponse($body);

/*
 * Mesma liquidação de QR dinâmico do payment-baas: este é o endpoint que o
 * fluxo v1 padrão (CelcoinPix::pagamentoPix) usa para pagar, então sem isto
 * pagar por ele deixaria a cobrança ATIVA para sempre.
 * Quirk do consumidor v1: aqui o E2E vai como `endtoendid`, tudo minúsculo
 * (CelcoinPix::pagamentoPix) — por isso os dois nomes são aceitos.
 */
if (($response['status'] ?? null) === 'SUCCESS') {
    $initiationType = strtoupper((string) ($body['initiationType'] ?? ''));
    $qrTxid = (string) ($body['transactionIdentification'] ?? '');

    if ($qrTxid !== '' && in_array($initiationType, ['DYNAMIC_QRCODE', 'STATIC_QRCODE'], true)) {
        Cslabs::settleDynamicBrcode($qrTxid, [
            'endToEndId' => (string) ($body['endToEndId'] ?? $body['endtoendid'] ?? $response['endToEndId'] ?? ''),
            'txid' => $qrTxid,
            'valor' => number_format((float) ($response['amount'] ?? 0), 2, '.', ''),
            'horario' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'infoPagador' => (string) ($body['remittanceInformation'] ?? ''),
            'debitParty' => $body['debitParty'] ?? new stdClass(),
            'creditParty' => $body['creditParty'] ?? new stdClass(),
        ]);
    }
} elseif (($response['status'] ?? null) === 'ERROR') {
    http_response_code(Cslabs::scenarioHttpStatus(Cslabs::lastErrorScenario()));
}

echo json_encode($response, JSON_PRETTY_PRINT);
