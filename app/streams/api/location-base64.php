<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$locationId = (string) ($web->args->locationId ?? '');

echo json_encode(Cslabs::brcodeStaticBase64Response($locationId, 'png'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
