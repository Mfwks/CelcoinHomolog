<?php

# GET /pixqrcode/v2/{locationId}/imagem — PNG do QR dinâmico, binário.
#
# Serve para apontar a câmera na tela ou embutir num <img>. O ob_start de
# api-stream.php só reescreve buffer que decodifica como objeto JSON, então o
# binário passa intacto (mesmo caminho do charge-pdf).

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;
use App\Core\QrCode;

$locationId = (string) ($web->args->locationId ?? '');
$emv = Cslabs::brcodeEmvForLocation($locationId, true); // sem escopo: aberto pelo navegador

if ($emv === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => ['errorCode' => 'CBE014', 'message' => 'QR Code não encontrado para a location informada.'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

// `escala` permite gerar maior para impressão; limitado para não virar vetor de
// consumo de CPU/memória com ?escala=9999.
$escala = (int) ($_GET['escala'] ?? 6);
$escala = max(1, min(20, $escala));

$png = QrCode::png(QrCode::encode($emv), $escala, 4);

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Content-Disposition: inline; filename="qr-' . preg_replace('/[^A-Za-z0-9_-]/', '', $locationId) . '.png"');

echo $png;
