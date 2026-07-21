<?php

# GET /pix/v1/brcode/static/{transactionId}/base64 — imagem do QR estático.

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$transactionId = (string) ($web->args->transactionId ?? '');
$imageType = strtolower((string) ($_GET['imageType'] ?? 'png'));

$response = Cslabs::brcodeStaticBase64Response($transactionId, $imageType);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(404);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
