<?php

/*
 * Smoke do gatilho de LIQUIDAÇÃO do boleto — `POST /baas/v2/charge/{txid}/pagar`
 * e o webhook `charge-in` que ele emite.
 *
 * Pedido pela sessão A em 07/08/2026 (briefing `2026-08-07-mock-charge-in-bol-011.md`)
 * para destravar a SEGUNDA metade do critério de aceite do BOL-011. A primeira metade
 * — o boleto nasce sob a conta certa — o `charge-create` já provava. A segunda é onde o
 * dinheiro ASSENTA, e o campo que responde isso é `charge-in.creditParty.account`.
 *
 * É HTTP de verdade, e não chamada de builder, porque as duas coisas que este gatilho
 * pode errar só existem entre processos:
 *
 *   1. ESCOPO. Quem emite o boleto é o app, com bearer; quem clica em "pagar" é o
 *      navegador, sem token nenhum. Entidade no mock é escopada por client_id, então
 *      liquidar no escopo de quem clicou deixaria a cobrança eternamente PENDING para
 *      o app — falha silenciosa. Vale para a gravação E para a inscrição de webhook:
 *      é no client_id do app que ela vive.
 *   2. IDEMPOTÊNCIA. Pagar duas vezes não pode gerar dois `charge-in`. O app sai cedo
 *      quando o boleto já está PAGO, então uma duplicata passaria batida lá — e é por
 *      isso mesmo que não pode sair daqui: mascararia um defeito do consumidor.
 *
 * O shape do payload é MEDIDO num charge-in real de produção (webhook 4501, 06/08/2026),
 * não inventado: sem `amount`, com `valorPago`, e com `creditParty`.
 */

require __DIR__ . '/http_harness.php';

$host = smoke_serve(8394);
$run = substr(hash('sha256', (string) getmypid() . microtime(true)), 0, 8);

$CONTA_COBRANCA = '900112233';   // a conta Celcoin do receiver — o que o BOL-011 discute
$emitir = '/api-integration-baas-webservice/v1/charge';

$token = smoke_token($host, 'app-boleto-' . $run);
smoke_ok($token !== null, 'auth: token do client que emite os boletos');

// Porta 9 é o discard: a entrega vai falhar, e é irrelevante — o que se afirma aqui é
// o que foi AGENDADO (o payload fica gravado no despacho), não que o destino aceitou.
foreach (['charge-create', 'charge-in'] as $entidade) {
    smoke_http($host, 'POST', '/baas/v2/webhook/subscription', [
        'entity' => $entidade,
        'webhookUrl' => 'http://127.0.0.1:9/webhook',
    ], $token);
}

$corpoCobranca = function (string $externalId) use ($CONTA_COBRANCA): array {
    return [
        'amount' => 354.99,
        'externalId' => $externalId,
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
    ];
};

// Despachos de um evento, já com o payload decodificado, na ordem em que entraram.
$despachos = function (string $evento, string $transactionId): array {
    $stmt = smoke_db()->prepare(
        'SELECT client_id, status, data FROM webhook_dispatches WHERE event = :e ORDER BY created_at'
    );
    $stmt->execute([':e' => $evento]);
    $achados = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $dados = json_decode((string) $linha['data'], true);
        $body = $dados['payload']['body'] ?? [];

        if (($body['transactionId'] ?? null) === $transactionId) {
            $achados[] = ['client_id' => $linha['client_id'], 'status' => $linha['status'], 'dados' => $dados];
        }
    }

    return $achados;
};

$donoDaCobranca = function (string $transactionId): ?string {
    $stmt = smoke_db()->prepare('SELECT client_id FROM entities WHERE type = :t AND id = :i LIMIT 1');
    $stmt->execute([':t' => 'charges', ':i' => $transactionId]);
    $dono = $stmt->fetchColumn();

    return $dono === false ? null : (string) $dono;
};

# ── 1. Emissão: a metade que o mock já cobria ────────────────────────────────
[, $criada, $httpCriada] = smoke_http($host, 'POST', $emitir, $corpoCobranca('EXT-' . $run), $token);
$txid = (string) ($criada['body']['transactionId'] ?? '');

smoke_ok($httpCriada === 201 && $txid !== '', 'emissão: boleto criado (201) com transactionId');

$criacao = $despachos('charge-create', $txid);
$bankAccountEmissao = (string) ($criacao[0]['dados']['payload']['body']['boleto']['bankAccount'] ?? '');

smoke_ok(
    $bankAccountEmissao === $CONTA_COBRANCA,
    'emissão: charge-create.boleto.bankAccount = conta do receiver (a metade que já existia)'
);

# ── 2. Liquidação SEM token — é o navegador clicando no botão ────────────────
[, $paga, $httpPaga] = smoke_http($host, 'POST', '/baas/v2/charge/' . $txid . '/pagar', [], null);

smoke_ok($httpPaga === 200 && ($paga['status'] ?? '') === 'SUCCESS', 'liquidação: 200 SUCCESS sem token (é o navegador)');
smoke_ok(
    ($paga['body']['creditParty']['account'] ?? '') === $CONTA_COBRANCA,
    'liquidação: a resposta já mostra em qual conta assentou — poupa uma consulta na conferência'
);

/*
 * A asserção de escopo na PERSISTÊNCIA: quem consulta é o app, com o bearer dele. Se a
 * baixa tivesse sido gravada no escopo do navegador, isto aqui diria PENDING para sempre
 * e ninguém saberia por quê.
 */
[, $consulta] = smoke_http($host, 'GET', '/baas/v2/charge?TransactionId=' . urlencode($txid), null, $token);

smoke_ok(
    ($consulta['body']['status'] ?? '') === 'PAID',
    'escopo: o APP vê a cobrança como PAID — veio: ' . var_export($consulta['body']['status'] ?? null, true)
);
smoke_ok(
    ($consulta['body']['boleto']['status'] ?? '') === 'Pago',
    'escopo: o boleto aparece "Pago" na consulta do app'
);

# ── 3. O webhook charge-in ───────────────────────────────────────────────────
$entregas = $despachos('charge-in', $txid);
$body = $entregas[0]['dados']['payload']['body'] ?? [];

smoke_ok(count($entregas) === 1, 'webhook: exatamente 1 charge-in agendado — veio: ' . count($entregas));

/*
 * A asserção de escopo na INSCRIÇÃO, que é diferente da de persistência e foi o segundo
 * braço da mesma armadilha: a inscrição vive no client_id do app, e quem disparou foi o
 * navegador. Buscando no escopo de quem chamou, não haveria inscrição — e o webhook
 * sairia como `skipped` em vez de sair.
 */
smoke_ok(
    ($entregas[0]['client_id'] ?? '') === $donoDaCobranca($txid),
    'escopo: o despacho fica no client_id do DONO da cobrança, que é onde vive a inscrição'
);
// O `$entregas !== []` não é redundante com a contagem acima: sem ele, a lista vazia
// satisfaz o `!== 'skipped'` e a asserção passa afirmando nada.
smoke_ok(
    $entregas !== [] && ($entregas[0]['status'] ?? '') !== 'skipped',
    'escopo: o charge-in NÃO foi descartado por "sem inscrição" — veio: ' . ($entregas[0]['status'] ?? '(nenhum)')
);

// O critério 2 do aceite do BOL-011, in loco: emissão e liquidação apontam a MESMA conta.
smoke_ok(
    ($body['creditParty']['account'] ?? '') === $bankAccountEmissao,
    'BOL-011: charge-in.creditParty.account = charge-create.boleto.bankAccount da MESMA cobrança'
);
smoke_ok(
    ($body['creditParty']['account'] ?? '') === $CONTA_COBRANCA,
    'BOL-011: e a conta é a do receiver da cobrança, não uma constante do mock'
);

/*
 * Shape medido: a produção manda `valorPago` e NÃO manda `amount`. O app lê
 * `valorPago ?? amount` (ChargeService:482), então mandar `amount` funcionaria — e
 * ensinaria um shape que a Celcoin não usa.
 */
smoke_ok(($body['valorPago'] ?? null) === 354.99, 'shape: valorPago é o valor da cobrança');
smoke_ok(
    $body !== [] && !array_key_exists('amount', $body),
    'shape: `amount` NÃO vai no charge-in — a produção não manda'
);
smoke_ok(($body['status'] ?? '') === 'Pago', 'shape: status textual "Pago", como o V1 documenta');
smoke_ok(
    round(((float) ($body['currentBalance'] ?? 0)) - ((float) ($body['oldBalance'] ?? 0)), 2) === 354.99,
    'shape: currentBalance - oldBalance = valorPago — o par de saldos não mente'
);
smoke_ok(
    ($entregas[0]['dados']['payload']['webhookId'] ?? '') === $txid,
    'envelope: webhookId = transactionId, como no charge-in real'
);

# ── 4. Idempotência ──────────────────────────────────────────────────────────
[, $repetida, $httpRepetida] = smoke_http($host, 'POST', '/baas/v2/charge/' . $txid . '/pagar', [], null);

smoke_ok($httpRepetida === 200, 'idempotência: pagar de novo não é erro (200)');
smoke_ok(
    ($repetida['body']['estadoAnterior'] ?? '') === 'já estava paga',
    'idempotência: a resposta reporta o estado que ENCONTROU, não um comportamento que não observou'
);
smoke_ok(count($despachos('charge-in', $txid)) === 1, 'idempotência: continua 1 charge-in, não 2');

# ── 5. Resolver pela linha digitável — é o que um humano tem na mão ──────────
[, $outra] = smoke_http($host, 'POST', $emitir, $corpoCobranca('EXT-LD-' . $run), $token);
$txidLinha = (string) ($outra['body']['transactionId'] ?? '');

[, $consultaLinha] = smoke_http($host, 'GET', '/baas/v2/charge?TransactionId=' . urlencode($txidLinha), null, $token);
$linhaDigitavel = (string) ($consultaLinha['body']['boleto']['bankLine'] ?? '');

[, $pagaLinha, $httpLinha] = smoke_http($host, 'POST', '/baas/v2/charge/' . $linhaDigitavel . '/pagar', [], null);

smoke_ok($linhaDigitavel !== '' && $httpLinha === 200, 'referência: liquidar pela linha digitável funciona');
smoke_ok(
    ($pagaLinha['body']['transactionId'] ?? '') === $txidLinha,
    'referência: a linha digitável resolveu para a cobrança certa'
);

# ── 6. Os dois "não" ─────────────────────────────────────────────────────────
[, , $httpInexistente] = smoke_http($host, 'POST', '/baas/v2/charge/nao-existe-' . $run . '/pagar', [], null);
smoke_ok($httpInexistente === 404, 'inexistente: 404, e não um SUCCESS mentiroso');

[, $terceira] = smoke_http($host, 'POST', $emitir, $corpoCobranca('EXT-CANC-' . $run), $token);
$txidCancelada = (string) ($terceira['body']['transactionId'] ?? '');
smoke_http($host, 'DELETE', '/baas/v2/charge/' . $txidCancelada, ['reason' => 'teste'], $token);

[, , $httpCancelada] = smoke_http($host, 'POST', '/baas/v2/charge/' . $txidCancelada . '/pagar', [], null);
smoke_ok($httpCancelada === 409, 'cancelada: 409 — boleto cancelado não se paga');
smoke_ok(count($despachos('charge-in', $txidCancelada)) === 0, 'cancelada: nenhum charge-in emitido');

smoke_finish('charge-in smoke');
