<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$transactionId = trim((string) ($_GET['TransactionId'] ?? $_GET['transactionId'] ?? ''));
$externalId = trim((string) ($_GET['ExternalId'] ?? $_GET['externalId'] ?? ''));

$response = Cslabs::chargeFetchResponse($transactionId, $externalId);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(404);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
