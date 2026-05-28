<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : $_GET;

header('Content-Type: application/json');

$response = Cslabs::billPaymentAuthorizeResponse($body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(Cslabs::scenarioHttpStatus(Cslabs::lastErrorScenario()));
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
