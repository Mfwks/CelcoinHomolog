<?php

# GET /baas/v2/charge/pdf/{id} — PDF do boleto (celcoinv2 ChargeService::fetchBoletoPdf).
# O consumidor aceita PDF binário, JSON {pdf|pdfBase64|base64} ou {url}; e busca
# em body/data também. Devolvemos JSON com body.pdf = base64 de um PDF mínimo
# (o consumidor decodifica e valida o prefixo %PDF). Ver HOMOLOGACAO_CELCOIN_V2.md §6.3.

include_once __DIR__ . '/api-stream.php';

header('Content-Type: application/json');

$id = (string) ($web->args->id ?? '');

$pdf = "%PDF-1.4\n"
    . "% Mock CSLabs — boleto da cobranca {$id}\n"
    . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n"
    . "trailer<</Root 1 0 R>>\n%%EOF";

echo json_encode([
    'status' => 'SUCCESS',
    'version' => '1.1.0',
    'body' => [
        'transactionId' => $id,
        'pdf' => base64_encode($pdf),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
