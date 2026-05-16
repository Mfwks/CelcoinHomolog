<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$document = trim((string) ($_GET['DocumentNumber'] ?? $_GET['documentNumber'] ?? ''));

$response = Cslabs::accountFetchBusinessResponse($document);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
