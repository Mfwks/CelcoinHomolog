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

$response = Cslabs::brcodeStaticCreateResponse($body);

// Sucesso agora é plano (shape real) — só o erro carrega status/error.
$isError = ($response['status'] ?? null) === 'ERROR';

if (!$isError) {
    Cslabs::writeEntity('brcode_static', (string) $response['transactionId'], $response + ['key' => $body['key'] ?? null]);
}

// Real responde 200 no estático (log: "Response [200]"), não 201.
http_response_code($isError ? 422 : 200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
