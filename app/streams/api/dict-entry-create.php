<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;
use App\Core\Db;

header('Content-Type: application/json');

$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CBE001',
            'message' => 'Payload JSON inválido ou ausente.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$response = Cslabs::pixDictCreateResponse($body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$key = $response['body']['key'];
$account = $response['body']['account']['account'];

$duplicate = Db::transaction(function () use ($key, $account, $response) {
    $dup = Cslabs::pixDictDuplicateError($key, $account);
    if ($dup !== null) {
        return $dup;
    }
    Cslabs::writeEntity('pix_dict_entries', $key, [
        'key' => $key,
        'keyType' => $response['body']['keyType'],
        'account' => $response['body']['account'],
        'owner' => $response['body']['owner'],
        'created_at' => date(DATE_ATOM),
    ]);
    Cslabs::writeEntity('pix_dict_entries_by_account', $account . '_' . $response['body']['keyType'], ['key' => $key]);
    return null;
});

if (is_array($duplicate)) {
    http_response_code(400);
    echo json_encode($duplicate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
