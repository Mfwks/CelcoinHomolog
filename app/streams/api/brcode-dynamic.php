<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => ['errorCode' => 'CSLAB400', 'message' => 'Payload JSON inválido ou ausente.'],
    ], JSON_PRETTY_PRINT);
    return;
}

$response = Cslabs::brcodeDynamicCreateResponse($body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
} else {
    Cslabs::writeEntity('brcode_dynamic', $response['body']['transactionId'], $response['body']);
    // Quirk real: HTTP 200, mas o envelope carrega status:201 (int). Ver log em mocks-v2.
    http_response_code(200);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
