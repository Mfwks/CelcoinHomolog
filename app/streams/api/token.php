<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$accessToken = gerarHashMock();

Cslabs::registerIssuedToken($accessToken, [
    'grant_type' => is_array(Cslabs::requestBody()) ? (Cslabs::requestBody()['grant_type'] ?? null) : null,
    'client_id_hint' => is_array(Cslabs::requestBody()) ? (Cslabs::requestBody()['client_id'] ?? null) : null,
]);

echo json_encode([
    'expires_in' => time() + 60,
    'access_token' => $accessToken,
], JSON_PRETTY_PRINT);
