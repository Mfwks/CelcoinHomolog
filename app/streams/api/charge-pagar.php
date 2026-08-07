<?php

# POST /baas/v2/charge/{txid}/pagar — simula a liquidação do boleto.
#
# Ferramenta de teste do mock, NÃO existe na Celcoin: lá o boleto é pago no
# banco do sacado e o `charge-in` nasce dentro da Celcoin. Aqui não existe quem
# pague, então o gatilho tem que ser este endpoint — é o análogo do
# `pix-location-pagar.php`, que faz o mesmo para o QR dinâmico.
#
# No lugar do {txid} serve qualquer referência que um humano tenha na mão:
# transactionId, externalId, linha digitável ou código de barras.
#
# Body opcional: {"valorPago": 354.99, "tipoPagamento": "Boleto"}.
#
# Escreve no escopo do cliente DONO da cobrança — quem a emitiu, via app com
# bearer — e não no de quem chamou. Sem isso, liquidar por curl/painel deixaria
# a baixa invisível para o app, que seguiria vendo PENDING: falha silenciosa.

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$referencia = (string) ($web->args->txid ?? '');
$body = Cslabs::requestBody();
$body = is_array($body) ? $body : [];

$localizada = Cslabs::findCharge($referencia);

if ($localizada === null) {
    http_response_code(404);
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => ['errorCode' => 'CIE999', 'message' => 'Cobrança não encontrada.'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$pagamento = [];

if (isset($body['valorPago'])) {
    $pagamento['valorPago'] = (float) $body['valorPago'];
}

if (!empty($body['tipoPagamento'])) {
    $pagamento['tipoPagamento'] = (string) $body['tipoPagamento'];
}

$jaEstavaPaga = in_array(strtoupper((string) ($localizada['charge']['status'] ?? '')), ['PAID', 'CONFIRMED'], true);
$liquidou = Cslabs::settleCharge($localizada['transactionId'], $pagamento, $localizada['clientId']);

if (!$liquidou) {
    http_response_code(409);
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => [
            'errorCode' => 'CIE999',
            'message' => 'Cobrança não pôde ser liquidada — está cancelada ou o valor é inválido.',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

/*
 * `creditParty.account` vai na resposta para poupar uma consulta: é o campo que
 * se compara com o `bankAccount` do charge-create da MESMA cobrança. Isto é
 * endpoint de teste do mock, não contrato — pode devolver o que ajuda a conferir.
 */
echo json_encode([
    'status' => 'SUCCESS',
    'version' => '1.0.0',
    'body' => [
        'transactionId' => $localizada['transactionId'],
        'externalId' => (string) ($localizada['charge']['externalId'] ?? ''),
        'valorPago' => $pagamento['valorPago'] ?? round((float) ($localizada['charge']['amount'] ?? 0), 2),
        'creditParty' => ['account' => (string) ($localizada['charge']['receiver']['account'] ?? '')],
        /*
         * Diz o que foi MEDIDO (o estado antes da chamada), não o que se supõe ter
         * acontecido depois. Escrito primeiro como "charge-in não reemitido", uma
         * afirmação sobre comportamento que este stream não observa: removendo a
         * guarda de idempotência do settleCharge, o webhook saía duas vezes e a
         * mensagem continuava dizendo que não. Mensagem de diagnóstico que pode
         * mentir é pior que mensagem nenhuma.
         */
        'estadoAnterior' => $jaEstavaPaga ? 'já estava paga' : 'PENDING',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
