<?php

# GET /pix/v2/receivement/v2/status?transactionId=... — status de recebimento Pix
# (celcoinv2 PixService::consultarRecebimentoPix). SEM tráfego real em log: shape
# INFERIDO do contrato do consumidor (lê response->body ?? response). Grafia do
# path (v2 duplicado) reproduz o literal chamado. Ver HOMOLOGACAO_CELCOIN_V2.md §4.10.

include_once __DIR__ . '/api-stream.php';

header('Content-Type: application/json');

$txid = trim((string) ($_GET['transactionId'] ?? $_GET['TransactionId'] ?? ''));

if ($txid === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => ['errorCode' => 'CSLAB400', 'message' => 'transactionId é obrigatório.'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$endToEndId = 'E13935893' . date('YmdHi') . substr(md5($txid), 0, 11);

echo json_encode([
    'status' => 'CONFIRMED',
    'version' => '1.0.0',
    'body' => [
        'id' => $txid,
        'amount' => round((crc32($txid) % 100000) / 100, 2),
        'endToEndId' => $endToEndId,
        'transactionType' => 'RECEIVEPIX',
        'paymentType' => 'IMMEDIATE',
        'status' => 'CONFIRMED',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
