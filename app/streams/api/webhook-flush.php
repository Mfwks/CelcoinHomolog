<?php

/*
 * POST /cslabs/webhook/flush — drena os webhooks agendados ENTREGANDO na hora.
 *
 * Não existe na Celcoin. É o par do caminho realista: streams como o
 * `charge-create` e o gatilho `/pagar` agendam com delay, e num host onde o
 * background não roda (o cslabs hospedado, medido em 07/08/2026) o agendado
 * fica parado para sempre. O QA chama o flush logo depois e recebe o desfecho
 * verdadeiro de cada entrega.
 *
 * Corpo (tudo opcional):
 *   {"entity":"charge-in","limit":50,"clientId":"<escopo do dono>"}
 *
 * O escopo padrão é o de quem chama — então use o MESMO bearer do app que criou
 * a cobrança, senão a fila drenada é a do anônimo e volta vazia.
 */

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'ERROR',
        'error' => ['errorCode' => 'CSLAB405', 'message' => 'Use POST para drenar os webhooks agendados.'],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : [];

$entity = trim((string) ($body['entity'] ?? ''));
$clientId = trim((string) ($body['clientId'] ?? ''));
$limit = (int) ($body['limit'] ?? 50);

$desfechos = Cslabs::flushWebhookDispatches(
    $clientId !== '' ? $clientId : null,
    $entity !== '' ? $entity : null,
    $limit
);

$entregues = 0;
$itens = [];

foreach ($desfechos as $entrega) {
    $corpo = (string) ($entrega['response_body'] ?? '');
    if (strlen($corpo) > 2000) {
        $corpo = substr($corpo, 0, 2000) . '… [truncado]';
    }

    if (($entrega['status'] ?? '') === 'delivered') {
        $entregues++;
    }

    $itens[] = [
        'webhookId' => $entrega['webhook_id'] ?? null,
        'entity' => $entrega['event'] ?? null,
        'outcome' => $entrega['status'] ?? null,
        'webhookUrl' => $entrega['target_url'] ?? null,
        'responseCode' => $entrega['response_code'] ?? null,
        'responseBody' => $corpo === '' ? null : $corpo,
        'error' => $entrega['error'] ?? null,
    ];
}

echo json_encode([
    'status' => 'OK',
    'message' => $itens === []
        ? 'Nenhum webhook agendado neste escopo — se você esperava um, confira se o bearer é o mesmo do dono da entidade.'
        : sprintf('%d de %d entregues.', $entregues, count($itens)),
    'drenados' => count($itens),
    'entregues' => $entregues,
    'itens' => $itens,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
