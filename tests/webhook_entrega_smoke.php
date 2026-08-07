<?php

/*
 * Smoke da ENTREGA de webhook — o transporte, não o conteúdo.
 *
 * Pedido pela sustentação em 07/08/2026 (briefing `2026-08-07-celcoin-mock-entrega-webhook.md`):
 * no cslabs hospedado, todo webhook agendado respondia "Webhook agendado" e nunca saía.
 * Zero requests inbound do IP de egresso do mock, medido do lado do app. O disparo dava
 * 200 e o QA concluía que tinha disparado — o "agendado" escondia a falha.
 *
 * O que se afirma aqui é o que aquele bug tornava impossível afirmar:
 *
 *   1. O dispatch manual ENTREGA e reporta o desfecho VERDADEIRO — inclusive quando o
 *      destino recusa, que vira 502 em vez de 200 alegre.
 *   2. O agendado é DRENÁVEL: `/cslabs/webhook/flush` entrega o que ficou parado. É o que
 *      dá teste determinístico num host onde background não roda.
 *   3. Ninguém entrega duas vezes. Flush e worker disputam o mesmo despacho, e o claim
 *      condicional decide quem manda — o outro desiste.
 *   4. O diagnóstico mede o host em vez de adivinhar: qual binário de CLI, qual SAPI,
 *      o que impede o worker.
 *
 * É HTTP de verdade porque o defeito É de transporte entre processos. E o mock entrega
 * para SI MESMO (`/home`, que responde 200 sem tocar no banco): daí o PHP_CLI_SERVER_WORKERS,
 * sem o qual o `php -S` de um worker só travaria esperando a própria conexão.
 */

require __DIR__ . '/http_harness.php';

$host = smoke_serve(8395, ['PHP_CLI_SERVER_WORKERS' => '4']);
$run = substr(hash('sha256', (string) getmypid() . microtime(true)), 0, 8);

$receptor = 'http://' . $host . '/home';
$CONTA_COBRANCA = '900112233';

$token = smoke_token($host, 'app-entrega-' . $run);
smoke_ok($token !== null, 'auth: token do client que assina os webhooks');

// Despacho gravado cujo payload carrega este transactionId.
$despachoPorTxid = function (string $txid): ?array {
    $stmt = smoke_db()->query('SELECT webhook_id, status, data FROM webhook_dispatches ORDER BY created_at');

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $dados = json_decode((string) $linha['data'], true);
        if ((($dados['payload']['body']['transactionId'] ?? null)) === $txid) {
            return ['webhook_id' => $linha['webhook_id'], 'status' => $linha['status'], 'dados' => $dados];
        }
    }

    return null;
};

$entregasPorTxid = function (string $txid): array {
    $stmt = smoke_db()->query("SELECT status, data FROM webhook_dispatches WHERE status = 'delivered'");
    $achados = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $dados = json_decode((string) $linha['data'], true);
        if ((($dados['payload']['body']['transactionId'] ?? null)) === $txid) {
            $achados[] = $dados;
        }
    }

    return $achados;
};

# ── 1. Diagnóstico: mede o host, não adivinha ────────────────────────────────

[, $diag, $status] = smoke_http($host, 'GET', '/cslabs/webhook/diagnostico');
$d = $diag['diagnostico'] ?? [];

smoke_ok($status === 200 && ($diag['status'] ?? null) === 'OK', 'diagnóstico responde');
smoke_ok(($d['cli']['sapi'] ?? null) === 'cli', 'diagnóstico EXECUTA o candidato a binário e confere que é CLI mesmo');
smoke_ok(($d['sapi_web'] ?? null) === 'cli-server', 'diagnóstico separa o SAPI do servidor do SAPI do worker');
smoke_ok(array_key_exists('litespeed_finish_request', $d), 'diagnóstico reporta o finish_request do LiteSpeed, não só o do FPM');
smoke_ok(($d['worker_utilizavel'] ?? null) === true && ($d['impedimentos'] ?? null) === [], 'aqui o worker é utilizável — e a lista de impedimentos diz isso explicitamente');

# ── 2. Inscrição: URL quebrada não passa mais calada ─────────────────────────

[, $sub, $status] = smoke_http($host, 'POST', '/baas/v2/webhook/subscription', [
    'entity' => 'charge-in',
    'webhookUrl' => $receptor,
], $token);

smoke_ok($status === 200, 'inscrição de charge-in aceita');
smoke_ok(($sub['body']['knownEntity'] ?? null) === true, 'charge-in é entidade conhecida — o mock emite, então a lista tem que saber');
smoke_ok(($sub['body']['urlWarnings'] ?? null) === [], 'URL limpa não gera aviso');

// O caso real: a inscrição de charge-create no cslabs estava em
// `https://.e-bancos.com.br/…?r=r=boleto/…` — host com rótulo vazio E parâmetro dobrado.
[, $recusa, $status] = smoke_http($host, 'POST', '/baas/v2/webhook/subscription', [
    'entity' => 'charge-canceled',
    'webhookUrl' => 'https://.e-bancos.com.br/webhook',
], $token);

smoke_ok($status === 422, 'host com rótulo vazio é recusado');
smoke_ok(str_contains((string) ($recusa['error']['message'] ?? ''), 'rótulo vazio'), 'e a recusa DIZ qual é o defeito — o FILTER_VALIDATE_URL recusa calado, e o veredito dele varia com a versão do PHP');

[, $suspeita, $status] = smoke_http($host, 'POST', '/baas/v2/webhook/subscription', [
    'entity' => 'billpayment',
    'webhookUrl' => 'https://homologacao2.e-bancos.com.br/?r=r=boleto/webhook/celcoin/charge-create',
], $token);

smoke_ok($status === 200, 'URL com parâmetro dobrado é aceita — ela entrega, o defeito é de conteúdo');
smoke_ok(($suspeita['body']['urlWarnings'] ?? []) !== [], 'mas volta com aviso em vez de silêncio');

# ── 3. Dispatch manual: entrega e conta o que aconteceu ──────────────────────

$txSync = 'tx-sync-' . $run;

[, $disp, $status] = smoke_http($host, 'POST', '/cslabs/webhook/dispatch', [
    'entity' => 'charge-in',
    'status' => 'CONFIRMED',
    'body' => ['transactionId' => $txSync, 'valorPago' => 25],
], $token, 20);

smoke_ok($status === 200, 'dispatch manual devolve 200 quando entregou');
smoke_ok(($disp['delivery']['mode'] ?? null) === 'sync', 'o modo padrão é síncrono — endpoint de teste entrega, não agenda');
smoke_ok(($disp['delivery']['outcome'] ?? null) === 'delivered', 'a resposta diz "delivered", não "agendado"');
smoke_ok(($disp['delivery']['responseCode'] ?? null) === 200, 'e carrega o código HTTP que o DESTINO devolveu');

$gravado = $despachoPorTxid($txSync);
smoke_ok(($gravado['status'] ?? null) === 'delivered', 'o despacho fica gravado como delivered, e não parado em scheduled');

# ── 4. Destino que recusa não vira 200 alegre ────────────────────────────────

// Porta 9 é o discard: conexão recusada na hora.
smoke_http($host, 'POST', '/baas/v2/webhook/subscription', [
    'entity' => 'kyc',
    'webhookUrl' => 'http://127.0.0.1:9/webhook',
], $token);

$txFalha = 'tx-falha-' . $run;

[, $falha, $status] = smoke_http($host, 'POST', '/cslabs/webhook/dispatch', [
    'entity' => 'kyc',
    'body' => ['transactionId' => $txFalha],
], $token, 20);

smoke_ok($status === 502, 'destino que recusa vira 502 — quem testa por curl vê a falha sem ler o corpo');
smoke_ok(($falha['delivery']['outcome'] ?? null) === 'failed', 'com outcome failed');
smoke_ok(!empty($falha['delivery']['error']), 'e com o erro de transporte de verdade');

# ── 5. O agendado é drenável, e ninguém entrega duas vezes ───────────────────

$txAsync = 'tx-async-' . $run;

[, $agendado, $status] = smoke_http($host, 'POST', '/cslabs/webhook/dispatch', [
    'entity' => 'charge-in',
    'async' => true,
    'delaySeconds' => 2,
    'body' => ['transactionId' => $txAsync],
], $token);

smoke_ok($status === 200 && ($agendado['delivery']['mode'] ?? null) === 'async', 'async:true mantém o caminho agendado, para exercitar o realista');
smoke_ok(str_contains((string) ($agendado['message'] ?? ''), 'NÃO sabe'), 'e a resposta admite que não sabe o desfecho, em vez de sugerir sucesso');

$naFila = $despachoPorTxid($txAsync);
smoke_ok(($naFila['status'] ?? null) === 'scheduled', 'o agendado espera na fila com status scheduled');
smoke_ok(!empty($naFila['dados']['delivery_mode']), 'o registro diz por qual caminho a entrega foi tentada — é o que se lê no painel do host');

[, $flush, $status] = smoke_http($host, 'POST', '/cslabs/webhook/flush', ['entity' => 'charge-in'], $token, 20);
$item = null;
foreach ($flush['itens'] ?? [] as $linha) {
    if (($linha['webhookId'] ?? null) === ($naFila['webhook_id'] ?? null)) {
        $item = $linha;
    }
}

smoke_ok($status === 200 && ($flush['entregues'] ?? 0) >= 1, 'o flush drena a fila entregando na hora');
smoke_ok($item !== null && $item['outcome'] === 'delivered' && $item['responseCode'] === 200, 'e reporta o desfecho de cada despacho, um a um');

[, $flushVazio] = smoke_http($host, 'POST', '/cslabs/webhook/flush', ['entity' => 'charge-in'], $token, 20);
smoke_ok(($flushVazio['drenados'] ?? null) === 0, 'segundo flush não reentrega o que já saiu');

// O worker deste despacho foi spawnado com delay 2 e ainda está dormindo. Quando acordar,
// o claim já terá sido tomado pelo flush — se ele entregasse mesmo assim, o app receberia
// o mesmo charge-in duas vezes, que é justamente o que a idempotência do app esconderia.
$sentAtAntes = $despachoPorTxid($txAsync)['dados']['sent_at'] ?? null;
sleep(3);
$depois = $despachoPorTxid($txAsync);

smoke_ok($sentAtAntes !== null && ($depois['dados']['sent_at'] ?? null) === $sentAtAntes, 'o worker acordou depois do flush e NÃO entregou de novo — o claim segurou');
smoke_ok(count($entregasPorTxid($txAsync)) === 1, 'exatamente um despacho entregue para o webhook agendado');

# ── 6. O fluxo do briefing, ponta a ponta: /pagar e depois flush ─────────────

[, $cobranca] = smoke_http($host, 'POST', '/api-integration-baas-webservice/v1/charge', [
    'amount' => 25.00,
    'externalId' => 'EXT-' . $run,
    'key' => 'cobranca@empresa.com',
    'duedate' => date('Y-m-d', strtotime('+3 days')),
    'debtor' => [
        'name' => 'SACADO DE TESTE',
        'document' => '12345678901',
        'postalCode' => '01001000',
        'publicArea' => 'Rua A',
        'number' => '100',
        'neighborhood' => 'Centro',
        'city' => 'São Paulo',
        'state' => 'SP',
    ],
    'receiver' => [
        'name' => 'EMPRESA HOMOLOG',
        'document' => '49966300000119',
        'account' => $CONTA_COBRANCA,
        'city' => 'São Paulo',
        'state' => 'SP',
        'postalCode' => '04570001',
        'publicArea' => 'Avenida Nova Independência',
    ],
], $token, 20);

$txPagar = (string) ($cobranca['body']['transactionId'] ?? '');
smoke_ok($txPagar !== '', 'cobrança emitida pelo app');

// Sem bearer: é o navegador clicando, como no gatilho de liquidação.
smoke_http($host, 'POST', '/baas/v2/charge/' . $txPagar . '/pagar', null, null, 20);

// O despacho é do DONO da cobrança, então o flush precisa do bearer do app.
[, $flushPagar, $status] = smoke_http($host, 'POST', '/cslabs/webhook/flush', ['entity' => 'charge-in'], $token, 20);

smoke_ok($status === 200 && ($flushPagar['entregues'] ?? 0) >= 1, '/pagar agenda e o flush entrega — é o caminho do repro do briefing');

$entregas = $entregasPorTxid($txPagar);
smoke_ok(count($entregas) === 1, 'exatamente um charge-in entregue para a cobrança paga');
smoke_ok(
    $entregas !== [] && ($entregas[0]['payload']['body']['creditParty']['account'] ?? null) === $CONTA_COBRANCA,
    'o charge-in entregue leva creditParty.account do receiver — o campo que decide o BOL-011'
);
smoke_ok($entregas !== [] && (int) ($entregas[0]['response_code'] ?? 0) === 200, 'e o desfecho registrado tem o código HTTP do destino');

smoke_finish('webhook_entrega_smoke');
