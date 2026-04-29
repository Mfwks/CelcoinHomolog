<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : [];

$key = $body['key'] ?? ($_GET['key'] ?? 'ok@pix.com');
$payerId = $body['payerId'] ?? ($_GET['payerId'] ?? '06170097914');

header('Content-Type: application/json');
echo json_encode(Cslabs::pixDictOldResponse($key, $payerId), JSON_PRETTY_PRINT);
