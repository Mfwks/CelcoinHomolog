<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;
use App\Core\Db;

header('Content-Type: application/json');

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : $_GET;

// Idempotência: mesmo clientCode reenviado (retry) replica a transação original.
$replay = Cslabs::pixPaymentReplay((string) ($body['clientCode'] ?? ''));
if ($replay !== null) {
    echo json_encode($replay, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$response = Cslabs::pixPaymentResponse($body);

if (($response['status'] ?? null) === 'SUCCESS') {
    $id = $response['transactionId'];
    $clientCode = (string) ($body['clientCode'] ?? '');
    $endToEndId = (string) $response['endToEndId'];
    $clientRequestId = (string) ($response['clientRequestId'] ?? '');

    $state = [
        'status' => 'PROCESSING',
        'created_ts' => time(),
        'created_at' => date(DATE_ATOM),
        'body' => [
            'id' => $id,
            'amount' => (float) ($response['amount'] ?? 0),
            'clientCode' => $clientCode,
            'clientRequestId' => $clientRequestId,
            'endToEndId' => $endToEndId,
            'initiationType' => (string) ($body['initiationType'] ?? 'DICT'),
            'paymentType' => (string) ($body['paymentType'] ?? 'IMMEDIATE'),
            'urgency' => (string) ($body['urgency'] ?? 'HIGH'),
            'transactionType' => (string) ($body['transactionType'] ?? 'TRANSFER'),
            'debitParty' => $body['debitParty'] ?? new stdClass(),
            'creditParty' => $body['creditParty'] ?? new stdClass(),
            'remittanceInformation' => (string) ($body['remittanceInformation'] ?? ''),
            // Campos de saque/QR que o real sempre devolve (null fora desses fluxos).
            'transactionIdentification' => $body['transactionIdentification'] ?? null,
            'recurrencyAccept' => false,
            'taxIdPaymentInitiator' => null,
            'vlcpAmount' => null,
            'vldnAmount' => null,
            'withdrawalServiceProvider' => null,
            'withdrawalAgentMode' => null,
        ],
    ];

    /*
     * O bloco `body` vale para OS DOIS paths, não só o V2: a v1 lê
     * $retorno->body->id / ->body->endToEndId / ->body->debitParty->account
     * (modules/pix/models/Pix.php:979-983, sem fallback plano), então enquanto a
     * resposta era só plana o mov_pix ficava sem transaction_id. Os campos do
     * topo (status/transactionId/error) seguem porque ConexaoBaas::liquidarPix
     * lê $resp->status/$resp->error no topo. O real da V2 usa status PROCESSING.
     */
    $response['body'] = $state['body'];
    if (Cslabs::isV2()) {
        $response['status'] = 'PROCESSING';
    }

    Db::transaction(function () use ($id, $clientCode, $endToEndId, $state) {
        Cslabs::writeEntity('pix_payments', $id, $state);
        if ($clientCode !== '') {
            Cslabs::writeEntity('pix_payments', $clientCode, $state);
        }
        if ($endToEndId !== '') {
            Cslabs::writeEntity('pix_payments', $endToEndId, $state);
        }
    });

    $webhookUrl = Cslabs::webhookSubscriptionUrl('pix-payment-out');
    $webhookPayload = Cslabs::webhookEnvelope('pix-payment-out', 'CONFIRMED', [
        'id' => $id,
        'amount' => (float) ($response['amount'] ?? 0),
        'clientCode' => $clientCode !== '' ? $clientCode : 'CLI-' . substr($id, 0, 7),
        'reason' => null,
        'transactionIdentification' => substr($id, 0, 10),
        'endToEndId' => $endToEndId,
        'initiationType' => (string) ($body['initiationType'] ?? 'DICT'),
        'paymentType' => (string) ($body['paymentType'] ?? 'IMMEDIATE'),
        'urgency' => (string) ($body['urgency'] ?? 'HIGH'),
        'transactionType' => (string) ($body['transactionType'] ?? 'TRANSFER'),
        'debitParty' => $body['debitParty'] ?? new stdClass(),
        'creditParty' => $body['creditParty'] ?? new stdClass(),
        'remittanceInformation' => (string) ($body['remittanceInformation'] ?? ''),
        'currentBalance' => 19814.54,
        'oldBalance' => 19954.54,
        'dataInsercao' => date(DATE_ATOM),
    ]);

    Cslabs::scheduleWebhook('pix-payment-out', $webhookPayload, 2, $webhookUrl);
} elseif (($response['status'] ?? null) === 'ERROR') {
    http_response_code(Cslabs::scenarioHttpStatus(Cslabs::lastErrorScenario()));
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
