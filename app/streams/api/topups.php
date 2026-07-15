<?php

# Recarga de celular (celcoinv2 TopupService) — superfície v5:
#   GET /v5/transactions/topups/providers
#   GET /v5/transactions/topups/provider-values
#   GET /v5/transactions/topups/status-consult
#   PUT /v5/transactions/topups/{transactionId}/capture
#   POST /v5/transactions/topups
# NENHUM tenant exercitou topups em log: shapes INFERIDOS do contrato do consumidor
# (reservar lê body.transactionId; demais só precisam de 2xx). Ver HOMOLOGACAO_CELCOIN_V2.md §7.

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$url = (string) ($web->url ?? $_SERVER['REQUEST_URI'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function topupsJson(array $payload): void
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if (str_contains($url, '/providers')) {
    $providers = [];
    foreach (['Vivo', 'Claro', 'TIM', 'Oi'] as $i => $name) {
        $providers[] = ['providerId' => $i + 1, 'name' => $name, 'type' => 1, 'category' => 1];
    }
    topupsJson(['status' => 'SUCCESS', 'version' => '1.0.0', 'body' => ['providers' => $providers]]);
    return;
}

if (str_contains($url, '/provider-values')) {
    $providerId = (int) ($_GET['providerId'] ?? 0);
    $values = [];
    foreach ([15, 20, 25, 30, 50, 100] as $v) {
        $values[] = ['providerId' => $providerId, 'value' => (float) $v, 'description' => "Recarga R$ {$v},00"];
    }
    topupsJson(['status' => 'SUCCESS', 'version' => '1.0.0', 'body' => ['values' => $values]]);
    return;
}

if (str_contains($url, '/status-consult')) {
    $txid = trim((string) ($_GET['transactionId'] ?? $_GET['TransactionId'] ?? ''));
    topupsJson(['status' => 'CONFIRMED', 'version' => '1.0.0', 'body' => ['transactionId' => $txid, 'status' => 'CONFIRMED']]);
    return;
}

if (str_contains($url, '/capture')) {
    $txid = (string) ($web->args->transactionId ?? '');
    topupsJson(['status' => 'CONFIRMED', 'version' => '1.0.0', 'body' => ['transactionId' => $txid, 'status' => 'CONFIRMED']]);
    return;
}

if ($method === 'POST') {
    $body = Cslabs::requestBody();
    $body = is_array($body) ? $body : [];
    $transactionId = (int) str_pad((string) (abs(crc32(json_encode($body) . microtime(true))) % 900000000 + 100000000), 9, '0', STR_PAD_LEFT);
    topupsJson([
        'status' => 'PROCESSING',
        'version' => '1.0.0',
        'body' => [
            'transactionId' => $transactionId,
            'providerId' => $body['providerId'] ?? null,
            'value' => $body['topupData']['value'] ?? null,
            'status' => 'PROCESSING',
        ],
    ]);
    return;
}

http_response_code(405);
topupsJson(['status' => 'ERROR', 'version' => '1.0.0', 'error' => ['errorCode' => 'CSLAB405', 'message' => 'Método não permitido para topups.']]);
