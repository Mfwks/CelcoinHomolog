<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;
use App\Core\Db;

header('Content-Type: application/json');

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : $_GET;

$response = Cslabs::pixPaymentResponse($body);

if (($response['status'] ?? null) === 'SUCCESS') {
    $id = $response['transactionId'];
    $clientCode = (string) ($body['clientCode'] ?? '');
    $endToEndId = (string) $response['endToEndId'];

    $state = [
        'status' => 'PROCESSING',
        'created_ts' => time(),
        'created_at' => date(DATE_ATOM),
        'body' => [
            'id' => $id,
            'amount' => (float) ($response['amount'] ?? 0),
            'clientCode' => $clientCode,
            'endToEndId' => $endToEndId,
            'initiationType' => (string) ($body['initiationType'] ?? 'DICT'),
            'paymentType' => (string) ($body['paymentType'] ?? 'IMMEDIATE'),
            'urgency' => (string) ($body['urgency'] ?? 'HIGH'),
            'transactionType' => (string) ($body['transactionType'] ?? 'TRANSFER'),
            'debitParty' => $body['debitParty'] ?? new stdClass(),
            'creditParty' => $body['creditParty'] ?? new stdClass(),
            'remittanceInformation' => (string) ($body['remittanceInformation'] ?? ''),
        ],
    ];

    Db::transaction(function () use ($id, $clientCode, $endToEndId, $state) {
        Cslabs::writeEntity('pix_payments', $id, $state);
        if ($clientCode !== '') {
            Cslabs::writeEntity('pix_payments', $clientCode, $state);
        }
        if ($endToEndId !== '') {
            Cslabs::writeEntity('pix_payments', $endToEndId, $state);
        }
    });
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
