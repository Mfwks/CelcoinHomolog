<?php

# POST /pixqrcode/v2/{locationId}/pagar — simula o pagamento da cobrança.
#
# Ferramenta de teste do mock, não existe na Celcoin: é o botão da página
# `.../ver`, para fechar o ciclo visual (gerar -> ver -> pagar -> CONCLUIDA)
# sem precisar montar a request de pagamento na mão.
#
# Escreve no escopo do cliente DONO da cobrança (quem criou o QR, via app com
# bearer) e não no do navegador — senão a baixa existiria só para quem clicou e
# o app continuaria vendo ATIVA.

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$locationId = (string) ($web->args->locationId ?? '');
$stored = Cslabs::readEntityAnyClient('brcode_dynamic_by_location', $locationId);
$dono = Cslabs::entityOwnerClient('brcode_dynamic_by_location', $locationId);

if (!is_array($stored) || $dono === null) {
    http_response_code(404);
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => ['errorCode' => 'CBE014', 'message' => 'Cobrança não encontrada para a location informada.'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$txid = (string) ($stored['transactionIdentification'] ?? '');
$valor = (float) ($stored['body']['amount']['original'] ?? 0);
$endToEndId = Cslabs::generateEndToEndId();

$liquidou = Cslabs::settleDynamicBrcode($txid, [
    'endToEndId' => $endToEndId,
    'txid' => $txid,
    'valor' => number_format($valor, 2, '.', ''),
    'horario' => gmdate('Y-m-d\TH:i:s.v\Z'),
    'infoPagador' => 'Pagamento simulado pelo painel do mock',
    'debitParty' => ['account' => '000000000', 'name' => 'PAGADOR SIMULADO'],
    'creditParty' => ['key' => $stored['body']['key'] ?? null],
], $dono);

if (!$liquidou) {
    http_response_code(409);
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => ['errorCode' => 'CBE014', 'message' => 'Não foi possível liquidar a cobrança.'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

echo json_encode([
    'status' => 'SUCCESS',
    'version' => '1.0.0',
    'body' => ['endToEndId' => $endToEndId, 'txid' => $txid, 'amount' => $valor],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
