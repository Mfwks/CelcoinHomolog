<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$id = (string) ($web->args->id ?? '');
$stored = Cslabs::readEntity('pix_dict_claims', $id);

if (is_array($stored)) {
    echo json_encode([
        'status' => $stored['status'] ?? 'OPEN',
        'body' => $stored,
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

echo json_encode(Cslabs::pixDictClaimGetResponse($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
