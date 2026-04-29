<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : $_GET;

header('Content-Type: application/json');
echo json_encode(Cslabs::pixPaymentResponse($body), JSON_PRETTY_PRINT);
