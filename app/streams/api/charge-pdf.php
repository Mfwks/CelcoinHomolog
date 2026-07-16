<?php

# GET /baas/v2/charge/pdf/{id} — PDF do boleto (celcoinv2 ChargeService::fetchBoletoPdf).
#
# BINÁRIO de propósito: o real devolve o PDF cru, não JSON
# (log: "Response raw [200] (129144 bytes, application/pdf)"). O consumidor aceita
# os dois — ChargeService::extrairPdfBase64 testa `%PDF`/application-pdf antes de
# tentar JSON {pdf|pdfBase64|base64|url} — mas o caminho exercitado em produção é o
# binário, então é ele que o mock precisa reproduzir.
#
# O ob_start de api-stream.php só reescreve buffer que decodifica como objeto JSON
# (Cslabs::injectInfoIntoJson), então o binário passa intacto — sem cslabs_info aqui.
# Ver HOMOLOGACAO_CELCOIN_V2.md §6.3.

include_once __DIR__ . '/api-stream.php';

$id = (string) ($web->args->id ?? '');

$pdf = "%PDF-1.4\n"
    . "% Mock CSLabs — boleto da cobranca {$id}\n"
    . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\n"
    . "trailer<</Root 1 0 R>>\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: inline; filename="boleto-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id) . '.pdf"');

echo $pdf;
