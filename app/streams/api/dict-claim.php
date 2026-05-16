<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$kind = 'open';
if (str_ends_with(rtrim($path, '/'), '/confirm')) {
    $kind = 'confirm';
} elseif (str_ends_with(rtrim($path, '/'), '/cancel')) {
    $kind = 'cancel';
}

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : [];

$response = Cslabs::pixDictClaimResponse($body, $kind);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
}

if (($response['status'] ?? null) === 'SUCCESS') {
    Cslabs::writeEntity('pix_dict_claims', $response['body']['id'], $response['body']);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
