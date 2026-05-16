<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$clientRequestId = trim((string) ($_GET['ClientRequestId'] ?? $_GET['clientRequestId'] ?? ''));
$transactionId = trim((string) ($_GET['TransactionId'] ?? $_GET['transactionId'] ?? ''));

$state = false;

if ($clientRequestId !== '') {
    $state = Cslabs::readEntity('internal_transfers', $clientRequestId);
}

if (!is_array($state) && $transactionId !== '') {
    foreach (Cslabs::listEntities('internal_transfers') as $candidate) {
        if (($candidate['transfer']['transactionId'] ?? null) === $transactionId) {
            $state = $candidate;
            break;
        }
    }
}

if (!is_array($state)) {
    http_response_code(404);
    echo json_encode(Cslabs::transferNotFoundError(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$transfer = $state['transfer'] ?? [];
$created = isset($state['transfer']['createdAt']) ? strtotime($state['transfer']['createdAt']) : time();
$confirmed = (time() - $created) >= 2;

$endToEndId = $state['webhook']['endToEndId'] ?? gerarHashMock();

echo json_encode([
    'status' => $confirmed ? 'CONFIRMED' : 'PROCESSING',
    'version' => '1.0.0',
    'body' => [
        'id' => $transfer['transactionId'] ?? null,
        'amount' => $transfer['amount'] ?? 0,
        'clientRequestId' => $transfer['clientRequestId'] ?? $clientRequestId,
        'debitParty' => [
            'account' => $transfer['debitParty']['account'] ?? '',
            'taxId' => $transfer['debitParty']['taxId'] ?? '',
            'name' => $transfer['debitParty']['name'] ?? 'Conta debitada',
            'branch' => $transfer['debitParty']['branch'] ?? '0001',
        ],
        'creditParty' => [
            'account' => $transfer['creditParty']['account'] ?? '',
            'taxId' => $transfer['creditParty']['taxId'] ?? '',
            'name' => $transfer['creditParty']['name'] ?? 'Conta creditada',
            'branch' => $transfer['creditParty']['branch'] ?? '0001',
        ],
        'description' => $transfer['description'] ?? 'Transferencia interna',
        'endToEndId' => $endToEndId,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
