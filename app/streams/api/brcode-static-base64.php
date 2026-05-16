<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$transactionId = (string) ($web->args->transactionId ?? '');
$imageType = strtolower((string) ($_GET['imageType'] ?? 'png'));

echo json_encode(Cslabs::brcodeStaticBase64Response($transactionId, $imageType), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
