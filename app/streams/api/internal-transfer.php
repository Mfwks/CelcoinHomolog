<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;
use App\Core\Db;

header('Content-Type: application/json');

$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB400',
            'message' => 'Payload JSON inválido ou ausente.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$amount = isset($body['amount']) ? (float) $body['amount'] : 0.0;
$clientRequestId = trim((string) ($body['clientRequestId'] ?? ''));
$debitAccount = trim((string) ($body['debitParty']['account'] ?? ''));
$creditAccount = trim((string) ($body['creditParty']['account'] ?? ''));
$description = trim((string) ($body['description'] ?? ''));

$scenario = Cslabs::scenarioFromAmount($amount);
if ($scenario !== 'success') {
    http_response_code(Cslabs::scenarioHttpStatus($scenario));
    echo json_encode(Cslabs::scenarioErrorResponse($scenario), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

if ($amount <= 0 || $clientRequestId === '' || $debitAccount === '' || $creditAccount === '') {
    http_response_code(422);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB422',
            'message' => 'Campos obrigatórios ausentes para transferência interna.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$existing = Db::transaction(function () use ($clientRequestId) {
    return Cslabs::readEntity('internal_transfers', $clientRequestId);
});

if ($existing !== false) {
    echo json_encode($existing['response'], JSON_PRETTY_PRINT);
    return;
}

$transactionId = gerarHashMock();
// endToEndId da transferência interna é UUID, não E2E de Pix (log real).
$endToEndId = gerarHashMock();

/*
 * Shape real (HOMOLOGACAO_CELCOIN_V2.md §15/B.1): envelope {status:PROCESSING,
 * version, body:{id, amount, clientRequestId, endToEndId, debitParty, creditParty,
 * description}}, com as partes trazendo account/taxId/name/branch/bank. Vale para
 * os dois paths: nenhum consumidor v1 lê campo desta resposta (CelcoinBaas.php:1087
 * e CartaoController só fazem json_encode pra log), então não há shape v1 a preservar.
 */
$party = function (array $raw, string $account): array {
    return [
        'account' => $account,
        'taxId' => (string) ($raw['taxId'] ?? ''),
        'name' => (string) ($raw['name'] ?? ''),
        'branch' => (string) ($raw['branch'] ?? '0001'),
        'bank' => (string) ($raw['bank'] ?? '13935893'),
    ];
};

$transferBody = [
    'id' => $transactionId,
    'amount' => round($amount, 2),
    'clientRequestId' => $clientRequestId,
    'endToEndId' => $endToEndId,
    'debitParty' => $party($body['debitParty'] ?? [], $debitAccount),
    'creditParty' => $party($body['creditParty'] ?? [], $creditAccount),
    'description' => $description !== '' ? $description : 'Transferencia interna',
];

$transfer = $transferBody + [
    'transactionId' => $transactionId,
    'status' => 'PROCESSING',
    'createdAt' => date(DATE_ATOM),
];

$response = Cslabs::v2Envelope($transferBody, 'PROCESSING');

$webhookBody = $transferBody + [
    'oldBalance' => null,
    'currentBalance' => null,
];

$webhookPayload = Cslabs::webhookEnvelope('internal-transfer-out', 'CONFIRMED', $webhookBody);
$webhookUrl = Cslabs::webhookSubscriptionUrl('internal-transfer-out');

Cslabs::writeEntity('internal_transfers', $clientRequestId, [
    'request' => $body,
    'transfer' => $transfer,
    'response' => $response,
    'webhook' => $webhookPayload,
    'webhook_url' => $webhookUrl,
]);

Cslabs::scheduleWebhook('internal-transfer-out', $webhookPayload, 2, $webhookUrl);

// Real responde 200 (não 201) — confirmado em log multi-tenant.
http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
