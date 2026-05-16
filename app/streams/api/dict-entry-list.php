<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$account = (string) ($web->args->account ?? '');
$response = Cslabs::pixDictListByAccountResponse($account);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
