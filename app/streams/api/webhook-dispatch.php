<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'ERROR',
        'error' => ['errorCode' => 'CSLAB405', 'message' => 'Use POST para disparar um webhook.'],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'error' => ['errorCode' => 'CSLAB400', 'message' => 'Payload JSON inválido ou ausente.'],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$entity = trim((string) ($body['entity'] ?? ''));
$scenario = strtoupper(trim((string) ($body['status'] ?? $body['scenario'] ?? 'CONFIRMED')));
$payloadBody = is_array($body['body'] ?? null) ? $body['body'] : [];
$delay = max(0, (int) ($body['delaySeconds'] ?? 0));
$urlOverride = trim((string) ($body['webhookUrl'] ?? ''));

if ($entity === '') {
    http_response_code(422);
    echo json_encode([
        'status' => 'ERROR',
        'error' => ['errorCode' => 'CSLAB422', 'message' => 'entity é obrigatório.'],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$url = $urlOverride !== '' ? $urlOverride : Cslabs::webhookSubscriptionUrl($entity);

if (!$url) {
    http_response_code(404);
    echo json_encode([
        'status' => 'ERROR',
        'error' => ['errorCode' => 'CSLAB404', 'message' => 'Nenhuma URL de webhook registrada para esta entity.'],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$bodyForWebhook = $payloadBody !== [] ? $payloadBody : Cslabs::sampleWebhookBody($entity, $scenario);
$webhookPayload = Cslabs::webhookEnvelope($entity, $scenario, $bodyForWebhook);

if (isset($body['error']) && is_array($body['error'])) {
    $webhookPayload['error'] = $body['error'];
}

/*
 * Entrega SÍNCRONA por padrão. Até 07/08/2026 este endpoint só agendava e
 * respondia "Webhook agendado" — e no cslabs hospedado nada saía: nem o worker
 * spawnado por exec, nem o fallback de shutdown. O QA via 200 e concluía que
 * tinha disparado. Endpoint de teste precisa entregar e dizer o desfecho.
 *
 * `"async": true` mantém o caminho agendado, para exercitar o realista.
 */
$assincrono = filter_var($body['async'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($assincrono) {
    Cslabs::scheduleWebhook($entity, $webhookPayload, $delay, $url);

    echo json_encode([
        'status' => 'OK',
        'message' => 'Webhook agendado — esta resposta NÃO sabe se ele foi entregue. Drene com POST /cslabs/webhook/flush.',
        'entity' => $entity,
        'webhookUrl' => $url,
        'delaySeconds' => $delay,
        'delivery' => ['mode' => 'async'],
        'payload' => $webhookPayload,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$entrega = Cslabs::deliverWebhookNow($entity, $webhookPayload, $url);

if ($entrega === null) {
    http_response_code(500);
    echo json_encode([
        'status' => 'ERROR',
        'error' => ['errorCode' => 'CSLAB500', 'message' => 'O webhook não chegou a ser entregue — veja o painel do cliente.'],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$entregue = ($entrega['status'] ?? '') === 'delivered';
$corpo = (string) ($entrega['response_body'] ?? '');

# Truncado só na resposta: o corpo inteiro fica no registro do despacho.
if (strlen($corpo) > 2000) {
    $corpo = substr($corpo, 0, 2000) . '… [truncado]';
}

# 502 quando quem recusou foi o APP: o disparo funcionou, o destino é que não
# aceitou. É o que faz o QA por curl ver a falha sem ler o corpo.
http_response_code($entregue ? 200 : 502);

echo json_encode([
    'status' => $entregue ? 'OK' : 'ERROR',
    'message' => $entregue ? 'Webhook entregue.' : 'Webhook disparado, mas o destino não aceitou.',
    'entity' => $entity,
    'webhookUrl' => $url,
    'delaySeconds' => 0,
    'delivery' => [
        'mode' => 'sync',
        'outcome' => $entrega['status'] ?? null,
        'webhookId' => $entrega['webhook_id'] ?? null,
        'responseCode' => $entrega['response_code'] ?? null,
        'responseBody' => $corpo === '' ? null : $corpo,
        'error' => $entrega['error'] ?? null,
        'sentAt' => $entrega['sent_at'] ?? null,
        'delaySecondsIgnorado' => $delay > 0 ? $delay : null,
    ],
    'payload' => $webhookPayload,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
