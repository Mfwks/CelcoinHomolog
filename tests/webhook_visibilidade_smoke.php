<?php

/*
 * Smoke da VISIBILIDADE do webhook: onde fica registrado o que saiu, e o que não saiu.
 *
 * Nasce de um caso real (05/08/2026). A sustentação rodou a bateria do LGR-011 contra
 * o mock em produção e o `spb-transfer-out CONFIRMED` nunca chegou ao app. O motivo era
 * legítimo — o client não tinha inscrição para essa entidade, e a Celcoin real também
 * não entregaria. O problema era o SILÊNCIO: `scheduleWebhook` devolvia `false` e nada
 * no painel, no shot ou na resposta dizia por quê. O diagnóstico só saiu lendo o código
 * do mock, de fora, por quem não é dono dele.
 *
 * Os dois casos abaixo são as duas metades da mesma pergunta "cadê meu webhook?":
 *   1. não saiu  → tem que estar em webhook_dispatches E no shot, com motivo e conserto;
 *   2. saiu      → o registro da entrega tem que cair no MESMO banco que o teste lê.
 *
 * O (2) parece trivial e não era: o worker é spawnado por `exec` e não herda o
 * `auto_prepend_file` que isola o TMP do smoke, então gravava no SQLite real do mock.
 * Um teste que perguntasse "entregou?" ao banco isolado ouvia "não" para sempre.
 */

require __DIR__ . '/http_harness.php';

$host = smoke_serve(8393);
$run = substr(hash('sha256', (string) getmypid() . microtime(true)), 0, 8);
$spb = '/baas-wallet-transactions-webservice/v1/spb/transfer';

$corpo = fn (string $clientCode) => [
    'amount' => 150.00,
    'clientCode' => $clientCode,
    'debitParty' => ['account' => '300547189179'],
    'creditParty' => [
        'bank' => '00000000',
        'account' => '987654',
        'branch' => '0001',
        'taxId' => '12345678000199',
        'name' => 'BENEFICIARIO HOMOLOGACAO',
        'accountType' => 'CC',
        'personType' => 'J',
    ],
    'clientFinality' => '10',
    'description' => 'TED de teste',
];

// Devolve a última interação gravada para o path do spb/transfer — é dela que saem o
// request_id e o client_id que amarram o dispatch ao shot.
$ultimaInteracao = function () use ($spb): array {
    $stmt = smoke_db()->prepare(
        'SELECT client_id, request_id, data FROM interactions WHERE path LIKE :p ORDER BY received_at_us DESC LIMIT 1'
    );
    $stmt->execute([':p' => '%' . $spb . '%']);
    $linha = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($linha) ? $linha : [];
};

# ── 1. Sem inscrição: o webhook não sai, e o mock DIZ isso ────────────────────
// Este client nunca assinou nada — é o estado em que a homologacao2 estava.
$semInscricao = smoke_token($host, 'sem-inscricao-' . $run);
smoke_ok($semInscricao !== null, 'auth: token do client sem inscrição');

[$cru, $resp, $http] = smoke_http($host, 'POST', $spb, $corpo('sem-sub-' . $run), $semInscricao);

smoke_ok($http === 201, 'sem inscrição: a TED em si continua aceita (201) — o webhook é que não sai');
smoke_ok(($resp['status'] ?? '') === 'PROCESSING', 'sem inscrição: status PROCESSING, igual a qualquer TED');

$interacao = $ultimaInteracao();
$dados = json_decode((string) ($interacao['data'] ?? ''), true);

$stmt = smoke_db()->prepare('SELECT status, target_url, data FROM webhook_dispatches WHERE request_id = :r LIMIT 1');
$stmt->execute([':r' => $interacao['request_id'] ?? '']);
$dispatch = $stmt->fetch(PDO::FETCH_ASSOC);
$dispatchData = is_array($dispatch) ? json_decode((string) $dispatch['data'], true) : [];

smoke_ok(is_array($dispatch), 'sem inscrição: o webhook que não saiu deixa linha em webhook_dispatches');
smoke_ok(
    is_array($dispatch) && $dispatch['status'] === 'skipped',
    'sem inscrição: status "skipped" — não é "scheduled" mentiroso nem ausência'
);
smoke_ok(
    str_contains((string) ($dispatchData['reason'] ?? ''), 'não há inscrição'),
    'sem inscrição: o motivo é legível e nomeia a causa — veio: ' . ($dispatchData['reason'] ?? '(vazio)')
);
smoke_ok(
    str_contains((string) ($dispatchData['fix'] ?? ''), '/baas/v2/webhook/subscription'),
    'sem inscrição: o registro diz COMO consertar (a rota de inscrição)'
);
smoke_ok(
    str_contains((string) ($dispatchData['fix'] ?? ''), 'client_id'),
    'sem inscrição: o conserto avisa que a inscrição é por client_id — é o que confunde'
);

/*
 * O painel /shots/<id>/ renderiza `webhooks[]` da interação. Se o skip não entrar aí,
 * quem for olhar o shot da request vê um POST 201 sem nenhum vestígio do webhook — que
 * é exatamente a tela que a sustentação viu.
 */
$webhooksDoShot = $dados['webhooks'] ?? [];
smoke_ok(
    is_array($webhooksDoShot) && count($webhooksDoShot) === 1,
    'sem inscrição: o skip aparece no shot da request (é a tela em que se procura)'
);
smoke_ok(
    ($webhooksDoShot[0]['status'] ?? '') === 'skipped' && ($webhooksDoShot[0]['event'] ?? '') === 'spb-transfer-out',
    'sem inscrição: o shot nomeia a entidade que faltou inscrever'
);

/*
 * E nada disso pode vazar para a resposta HTTP: o consumidor lê caminho fixo e a Celcoin
 * real não devolve campo de diagnóstico nenhum. Painel é do mock; resposta é contrato.
 */
smoke_ok(!str_contains($cru, 'skipped'), 'sem inscrição: o diagnóstico NÃO vaza para a resposta HTTP (contrato intacto)');
smoke_ok(!str_contains($cru, 'não há inscrição'), 'sem inscrição: o motivo também não vaza para a resposta');

# ── 2. Com inscrição: a entrega é registrada no banco QUE O TESTE LÊ ──────────
// Porta 9 é o discard: a entrega vai falhar por conexão recusada, e é o suficiente —
// o que se afirma aqui é ONDE o worker grava, não que o destino aceitou.
$comInscricao = smoke_token($host, 'com-inscricao-' . $run);
smoke_http($host, 'POST', '/baas/v2/webhook/subscription', [
    'entity' => 'spb-transfer-out',
    'webhookUrl' => 'http://127.0.0.1:9/webhook',
], $comInscricao);

[, , $httpComSub] = smoke_http($host, 'POST', $spb, $corpo('com-sub-' . $run), $comInscricao);
smoke_ok($httpComSub === 201, 'com inscrição: TED aceita');

$interacaoComSub = $ultimaInteracao();
$requestIdComSub = (string) ($interacaoComSub['request_id'] ?? '');

$stmt = smoke_db()->prepare('SELECT status FROM webhook_dispatches WHERE request_id = :r LIMIT 1');
$stmt->execute([':r' => $requestIdComSub]);
smoke_ok($stmt->fetchColumn() === 'scheduled', 'com inscrição: o webhook é agendado (não "skipped")');

/*
 * O delay do spb/transfer fora do accept_then_timeout é 3s. Espera-se o desfecho em vez
 * de dormir um tempo fixo: o worker é outro processo e a máquina pode estar ocupada.
 */
$desfecho = null;
for ($i = 0; $i < 40; $i++) {
    usleep(500000);
    $stmt = smoke_db()->prepare('SELECT status FROM webhook_dispatches WHERE request_id = :r LIMIT 1');
    $stmt->execute([':r' => $requestIdComSub]);
    $atual = (string) $stmt->fetchColumn();

    if ($atual !== 'scheduled') {
        $desfecho = $atual;
        break;
    }
}

smoke_ok(
    $desfecho !== null,
    'com inscrição: o worker gravou o desfecho no SQLITE DO SMOKE — sem isolar o TMP dele, isto nunca sai de "scheduled"'
);
smoke_ok(
    $desfecho === 'failed',
    'com inscrição: desfecho "failed" (porta 9 recusa) — o que se afirma é o registro, não o destino. Veio: ' . var_export($desfecho, true)
);

smoke_finish('webhook visibilidade smoke');
