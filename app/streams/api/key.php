<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$key = $_GET['key'] ?? 'ok@pix.com';
$ownerTaxId = $_GET['ownerTaxId'] ?? null;

header('Content-Type: application/json');
echo json_encode(Cslabs::pixDictResponse($key, $ownerTaxId), JSON_PRETTY_PRINT);
