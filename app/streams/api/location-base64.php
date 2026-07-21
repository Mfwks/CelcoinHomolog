<?php

# GET /pix/v1/location/{locationId}/base64 — imagem do QR dinâmico.
#
# Antes este stream ignorava o locationId e devolvia um PNG de 1x1 pixel para
# qualquer entrada. Agora renderiza o BR Code que foi realmente emitido.

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$response = Cslabs::locationBase64Response((string) ($web->args->locationId ?? ''));

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(404);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
