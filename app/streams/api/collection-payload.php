<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$payloadUrl = urldecode((string) ($web->args->payload ?? ''));

echo json_encode(Cslabs::collectionPayloadResponse($payloadUrl), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
