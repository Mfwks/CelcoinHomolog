<?php

/*
 * Smoke do par V1 por externalId — `GET` e `DELETE /v1/charge/{externalId}`.
 *
 * Pedido pela B-QA em 27/08/2026 (task #7, briefing
 * `2026-08-27-bqa-para-celcoin-mock-v1-charge-endpoints.md`). O app usa as duas
 * rotas desde 17/08 (`3099e547a`+`5b8a7748a` no GET, `cfaf5b24b` no DELETE) e o
 * mock não as tinha: quem apontasse uma instância para cá tomava 404 de rota, o
 * que reproduz o mesmo vermelho do sandbox por motivo completamente diferente.
 *
 * ## Por que funcional, e não chamada de builder
 *
 * O que se testa aqui só existe entre processos:
 *
 *   1. ROTEAMENTO. O par novo tem 2 segmentos onde a emissão tem 1, no mesmo
 *      prefixo. Se colidisse, o POST de emissão cairia no stream errado — e
 *      nenhum builder responde essa pergunta.
 *   2. MÉTODO. `App\Core\Web` casa por PATH e ignora o método. GET e DELETE
 *      chegam ao MESMO stream, e o ramo é lido de `$_SERVER['REQUEST_METHOD']`.
 *   3. STATUS HTTP. É metade do contrato medido: o DELETE de inexistente
 *      responde **400**, o GET de inexistente responde **404**.
 *
 * ## As duas formas de "não encontrei" são diferentes de propósito
 *
 * Medido em 26/08 no `totalishomolog` contra `sandbox.openfinance.celcoin.dev`:
 *
 *   DELETE /v1/charge/0000010474 → 400 {"version":"1.2.0","status":"ERROR",
 *                                       "error":{"errorCode":"CDE001",…}}
 *   GET    /v1/charge/0000010522 → 404 {"statusCode":404,
 *                                       "message":"Resource not found"}
 *
 * O primeiro é erro de negócio da Celcoin; o segundo é o 404 genérico do
 * gateway — a mesma cara de um path que não existe. As asserções abaixo
 * afirmam a diferença, e não só "deu erro": uniformizar as duas apagaria o
 * único sinal que temos sobre o GET (docs/scenarios.md §9).
 *
 * ## ⚠️ O envelope é afirmado aqui porque o app o lê errado
 *
 * `BoletoCelcoin::buscarEPopularLinhaDigitavel` lê
 * `$chargeDetails->boleto->bankLine`, mas `$chargeDetails` é o corpo INTEIRO da
 * resposta — e a família charge responde `{version,status,body:{…}}`. A leitura
 * certa é `->body->boleto->bankLine`, como o próprio
 * `BoletoCelcoin.php:79` faz com `->body->transactionId` na emissão.
 *
 * O caso §2 abaixo afirma o envelope E afirma que `boleto` NÃO está no topo,
 * exatamente para que ninguém "conserte" o mock achatando a resposta para o app
 * parar de ver null. Achatar faria o defeito do app sumir na homologação e
 * continuar em produção — que é o mal que o mock existe para não cometer.
 */

require __DIR__ . '/http_harness.php';

$host = smoke_serve(8397);
$run = substr(hash('sha256', (string) getmypid() . microtime(true)), 0, 8);

$V1 = '/api-integration-baas-webservice/v1/charge';

$token = smoke_token($host, 'app-boleto-v1-' . $run);
smoke_ok($token !== null, 'auth: token do client que emite os boletos');

smoke_http($host, 'POST', '/baas/v2/webhook/subscription', [
    'entity' => 'charge-canceled',
    'webhookUrl' => 'http://127.0.0.1:9/webhook',
], $token);

// externalId como o app monta: str_pad($boletoId, 10, '0', STR_PAD_LEFT).
$externalId = fn (int $id): string => str_pad((string) $id, 10, '0', STR_PAD_LEFT);

$emitir = function (string $extId) use ($host, $token, $V1): array {
    [, $json, $status] = smoke_http($host, 'POST', $V1, [
        'amount' => 805.66,
        'externalId' => $extId,
        'key' => 'cobranca@empresa.com',
        'duedate' => date('Y-m-d', strtotime('+30 days')),
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
        'receiver' => ['document' => '55980519000175', 'account' => '900112233'],
    ], $token);

    return [$json, $status];
};

# ─── 1. A rota nova não roubou o POST de emissão ────────────────────────────────
$ID_VIVO = $externalId(10474);
[$emissao, $statusEmissao] = $emitir($ID_VIVO);

smoke_ok($statusEmissao === 201, 'emissão: POST /v1/charge segue em 201 — deu ' . $statusEmissao);
smoke_ok(
    is_string($emissao['body']['transactionId'] ?? null),
    'emissão: devolve body.transactionId (a rota de 2 segmentos não capturou a de 1)'
);
$txid = (string) ($emissao['body']['transactionId'] ?? '');

# ─── 2. GET de cobrança viva ────────────────────────────────────────────────────
[, $get, $statusGet] = smoke_http($host, 'GET', $V1 . '/' . $ID_VIVO, null, $token);

smoke_ok($statusGet === 200, 'GET vivo: HTTP 200 — deu ' . $statusGet);
smoke_ok(($get['status'] ?? null) === 'SUCCESS', 'GET vivo: status SUCCESS no topo');
smoke_ok(($get['version'] ?? null) === '1.1.0', 'GET vivo: version 1.1.0, a da família charge (medida no POST V1 real)');
smoke_ok(($get['body']['transactionId'] ?? null) === $txid, 'GET vivo: resolve o mesmo transactionId da emissão');
smoke_ok(($get['body']['externalId'] ?? null) === $ID_VIVO, 'GET vivo: ecoa o externalId consultado');

$bankLine = (string) ($get['body']['boleto']['bankLine'] ?? '');
smoke_ok(strlen(preg_replace('/\D+/', '', $bankLine)) === 47, 'GET vivo: body.boleto.bankLine tem 47 dígitos — é o que o BOL-015 vai ler');
smoke_ok(($get['body']['boleto']['bankAccount'] ?? null) === '900112233', 'GET vivo: body.boleto.bankAccount é a conta do receiver');
smoke_ok(is_string($get['body']['boleto']['bankAgency'] ?? null), 'GET vivo: body.boleto.bankAgency presente (o fix do app também o persiste)');

/*
 * ⚠️ Estas duas defendem o ENVELOPE contra o achatamento — ver o cabeçalho.
 * Se um dia caírem porque alguém moveu `boleto` para o topo, o mock passou a
 * mentir para acomodar `buscarEPopularLinhaDigitavel`.
 */
smoke_ok(isset($get['body']) && is_array($get['body']), 'envelope: o payload da cobrança vive em `body`, como no GET V2 real');
smoke_ok(!isset($get['boleto']), '⚠️ envelope: `boleto` NÃO está no topo — o app lê ->boleto->bankLine e por isso vê null (defeito do app, não do mock)');

# ─── 3. GET de inexistente: 404 do gateway, não erro de negócio ─────────────────
[, $get404, $status404] = smoke_http($host, 'GET', $V1 . '/nao-existe-nunca-999', null, $token);

smoke_ok($status404 === 404, 'GET inexistente: HTTP 404 — deu ' . $status404);
smoke_ok(($get404['statusCode'] ?? null) === 404, 'GET inexistente: corpo {"statusCode":404,…}, literal do medido');
smoke_ok(($get404['message'] ?? null) === 'Resource not found', 'GET inexistente: message "Resource not found"');
smoke_ok(!isset($get404['error']), 'GET inexistente: NÃO usa o envelope Celcoin — é 404 de gateway, e a diferença é o sinal');

# ─── 4. DELETE de cobrança viva ────────────────────────────────────────────────
[, $del, $statusDel] = smoke_http($host, 'DELETE', $V1 . '/' . $ID_VIVO, null, $token);

smoke_ok($statusDel === 200, 'DELETE vivo: HTTP 200 — é o 2xx que o fix cfaf5b24b exige para gravar status=2');
smoke_ok(($del['status'] ?? null) === 'PROCESSING', 'DELETE vivo: PROCESSING no topo (o CANCELED chega por webhook)');
smoke_ok(($del['body']['transactionId'] ?? null) === $txid, 'DELETE vivo: devolve a cobrança inteira, como a V2');

[, $getPos] = smoke_http($host, 'GET', $V1 . '/' . $ID_VIVO, null, $token);
smoke_ok(($getPos['body']['status'] ?? null) === 'CANCELLED', 'DELETE vivo: o GET seguinte já vê CANCELLED');

$cancelados = smoke_db()->query(
    "SELECT data FROM webhook_dispatches WHERE event = 'charge-canceled'"
)->fetchAll(PDO::FETCH_COLUMN);
$corpos = array_map(fn ($linha) => json_decode((string) $linha, true)['payload']['body'] ?? [], $cancelados);
$meu = array_values(array_filter($corpos, fn ($c) => ($c['transactionId'] ?? null) === $txid));

smoke_ok(count($meu) === 1, 'webhook: exatamente 1 charge-canceled agendado — veio ' . count($meu));
smoke_ok(($meu[0]['externalId'] ?? null) === $ID_VIVO, 'webhook: traz externalId (93 amostras reais em webhooks-raw.log trazem)');

# ─── 5. DELETE de inexistente: erro de negócio CDE001 em 400 ───────────────────
[, $del400, $statusDel400] = smoke_http($host, 'DELETE', $V1 . '/nao-existe-nunca-888', null, $token);

smoke_ok($statusDel400 === 400, '⚠️ DELETE inexistente: HTTP 400, NÃO 404 — medido no sandbox real. Deu ' . $statusDel400);
smoke_ok(($del400['error']['errorCode'] ?? null) === 'CDE001', 'DELETE inexistente: errorCode CDE001');
smoke_ok(($del400['version'] ?? null) === '1.2.0', 'DELETE inexistente: version 1.2.0 (o erro usa outra que o sucesso — assim foi medido)');
smoke_ok(!isset($del400['statusCode']), 'DELETE inexistente: NÃO usa a forma de gateway do GET');

# ─── 6. A guarda de colisão do cenário por externalId ──────────────────────────
/*
 * ⚠️ Regressão de um defeito PRÉ-EXISTENTE, achado ao cobrir esta rota em 28/08.
 *
 * `scenarioFromValue` casava por SUBSTRING, e dois dos needles são numéricos:
 * "404" e "500". O boleto de id 10404 vira externalId `0000010404`, contém
 * "404" — e a EMISSÃO era recusada com not_found, antes de qualquer consulta.
 * O de id 5001 vira `0000005001`, contém "500", e tomava erro interno. Não era
 * defeito desta rota: `scenarioFromPayload` também recebe `documentNumber`,
 * `account`, `clientCode` e `transactionId`.
 *
 * Correção: valor só de dígitos casa needle por IGUALDADE. Mutação — devolver
 * `str_contains` incondicional em `scenarioFromValue` derruba as três asserções
 * seguintes (duas no 404, uma no 500).
 */
$ID_ARMADILHA = $externalId(10404);
$emitir($ID_ARMADILHA);
[, $armadilha, $statusArmadilha] = smoke_http($host, 'GET', $V1 . '/' . $ID_ARMADILHA, null, $token);

smoke_ok($statusArmadilha === 200, 'colisão: externalId 0000010404 contém "404" e ainda assim resolve — deu ' . $statusArmadilha);
smoke_ok(($armadilha['body']['externalId'] ?? null) === $ID_ARMADILHA, 'colisão: e resolve a cobrança certa');

$ID_500 = $externalId(5001);
$emitir($ID_500);
[, $cincoCem, $statusCincoCem] = smoke_http($host, 'GET', $V1 . '/' . $ID_500, null, $token);
smoke_ok($statusCincoCem === 200, 'colisão: externalId 0000005001 contém "500" e resolve — deu ' . $statusCincoCem);

# ─── 7. Cenário deliberado, só com hífen no externalId ─────────────────────────
$ID_BLOQUEADO = 'bqa-teste-blocked-' . $run;
$emitir($ID_BLOQUEADO);
[, $bloqueado, $statusBloqueado] = smoke_http($host, 'DELETE', $V1 . '/' . $ID_BLOQUEADO, null, $token);

smoke_ok($statusBloqueado === 403, 'cenário: externalId com hífen + "blocked" recusa o cancelamento em 403 — deu ' . $statusBloqueado);
smoke_ok(($bloqueado['error']['errorCode'] ?? null) === 'CSLAB423', 'cenário: código do catálogo chargeError (CSLAB423)');

// Hífen não é o gatilho — palavra-chave é. Um externalId igualmente hifenizado,
// sem needle nenhum, cai no 404 de gateway como qualquer inexistente.
[, $semNeedle, $statusSemNeedle] = smoke_http($host, 'GET', $V1 . '/bqa-teste-comum-' . $run, null, $token);
smoke_ok($statusSemNeedle === 404, 'cenário: externalId hifenizado SEM palavra-chave não vira cenário — deu ' . $statusSemNeedle);
smoke_ok(($semNeedle['statusCode'] ?? null) === 404, 'cenário: e cai no 404 de gateway, como qualquer inexistente');

# ─── 8. Método que não existe na rota ──────────────────────────────────────────
[, $put, $statusPut] = smoke_http($host, 'PUT', $V1 . '/' . $ID_VIVO, ['x' => 1], $token);

smoke_ok($statusPut === 405, 'PUT: HTTP 405 em vez de cair no ramo do GET — deu ' . $statusPut);
smoke_ok(($put['error']['errorCode'] ?? null) === 'CSLAB405', 'PUT: código do MOCK (CSLAB), não um CDE inventado');

smoke_finish('charge_v1_smoke');
