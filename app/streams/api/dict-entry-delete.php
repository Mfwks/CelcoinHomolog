<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$key = (string) ($web->args->key ?? '');
$body = Cslabs::requestBody();
$body = is_array($body) ? $body : [];

$response = Cslabs::pixDictDeleteResponse($key, $body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if (Cslabs::readEntity('pix_dict_entries', $key) !== false) {
    Cslabs::writeEntity('pix_dict_entries', $key, [
        'key' => $key,
        'deleted_at' => date(DATE_ATOM),
        'status' => 'DELETED',
    ]);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
