<?php

/*
 * Resolve a URL que o QR dinâmico carrega (tag 26/25 do EMV / campo `location`).
 * É o que o app do pagador acessa depois de ler o QR: devolve os dados da
 * cobrança — valor, chave, txid, calendário e, se já foi paga, o bloco `pix`.
 *
 * No Pix real esse endpoint fica no PSP recebedor e a resposta é um JWS
 * assinado; aqui devolvemos o JSON puro, que é o que a simulação precisa.
 */

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$locationId = (string) ($web->args->locationId ?? '');

$response = Cslabs::cobPayloadForLocation($locationId);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(404);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
