<?php

/*
 * Smoke do cenário `accept_then_timeout` no POST spb/transfer (amount 0,18).
 *
 * Pedido no briefing sustenance/dev/briefings/2026-08-04-mock-accept-then-timeout-lgr011.md
 * para destravar o QA do LGR-011: o app estorna o débito local quando o POST estoura,
 * SEM checar se a Celcoin aceitou. Reproduzir isso exige as duas coisas ao mesmo tempo
 * — aceite do lado da Celcoin e timeout do lado do app —, e nenhum cenário existente
 * fazia as duas.
 *
 * Por que é funcional e não unitário: o que este teste defende é a ORDEM dentro do
 * stream (persistir e agendar ANTES de dormir). Chamando o builder não existe nem
 * request para pendurar nem persistência para observar.
 *
 * O tempo real de 35s vira 3s aqui via CSLABS_HANG_SECONDS — um teste que esperasse
 * 35s ninguém rodaria, e a asserção que importa é a ordem, não a duração exata.
 *
 * ⚠️ Sob `php -S` (aqui e no dev local) o servidor é single-threaded: enquanto a
 * request está pendurada, ele não atende mais ninguém. Em produção o mock roda sob
 * Apache, que é multiprocesso — lá o hang prende um worker, não o serviço.
 */

require __DIR__ . '/http_harness.php';

$HANG = 3;

$host = smoke_serve(8392, ['CSLABS_HANG_SECONDS' => (string) $HANG]);
$token = smoke_token($host, 'accept-then-timeout-smoke');
smoke_ok($token !== null, 'auth: token emitido (fixa o client_id entre os casos)');

$run = substr(hash('sha256', (string) getmypid() . microtime(true)), 0, 8);
$spb = '/baas-wallet-transactions-webservice/v1/spb/transfer';

// Sem inscrição não há URL de destino e o scheduleWebhook desiste antes de registrar
// o dispatch — a asserção do agendamento (abaixo) exige que ela exista. Porta 9 é o
// discard: o worker vai tentar e levar recusa, que é o suficiente aqui.
smoke_http($host, 'POST', '/baas/v2/webhook/subscription', [
    'entity' => 'spb-transfer-out',
    'webhookUrl' => 'http://127.0.0.1:9/webhook',
], $token);

$corpo = fn (float $amount, string $clientCode) => [
    'amount' => $amount,
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

# ── O modo: aceita, agenda, e SÓ ENTÃO pendura ────────────────────────────────
$cc = 'lgr011-' . $run;
$t0 = microtime(true);
[, $p, $http] = smoke_http($host, 'POST', $spb, $corpo(0.18, $cc), $token, 30);
$decorrido = microtime(true) - $t0;

smoke_ok($http === 201, 'accept_then_timeout: HTTP 201 (aceite, não erro) — veio ' . $http);
smoke_ok(($p['status'] ?? '') === 'PROCESSING', 'accept_then_timeout: status PROCESSING como qualquer TED aceita');
smoke_ok(isset($p['body']['id']), 'accept_then_timeout: body.id — o app grava external_id daqui (TedService:151)');
smoke_ok($decorrido >= $HANG, sprintf('accept_then_timeout: a request pendura (%.1fs >= %ds)', $decorrido, $HANG));

$id = (string) ($p['body']['id'] ?? '');

/*
 * A asserção central, e a única que prova a ORDEM: `created_ts` é gravado no
 * Db::transaction do stream. Se ele está a menos de 1s do INÍCIO da request, a
 * persistência aconteceu antes do sleep — que é o que faz o CONFIRMED significar
 * "a Celcoin aceitou". Dormir primeiro esvaziaria o cenário, e nada na resposta
 * denunciaria a inversão: por isso a checagem vai no banco, não no corpo.
 */
$linha = smoke_db()->prepare("SELECT data FROM entities WHERE type = 'spb_transfers' AND id = ?");
$linha->execute([$id]);
$gravado = json_decode((string) $linha->fetchColumn(), true);

smoke_ok(is_array($gravado), 'accept_then_timeout: transferência persistida (é o "aceito" do lado Celcoin)');
smoke_ok(
    is_array($gravado) && ((int) $gravado['created_ts'] - (int) $t0) <= 1,
    'accept_then_timeout: persistiu ANTES de pendurar (created_ts colado no início da request)'
);

/*
 * O webhook tem que chegar depois do timeout do app. Se chegasse antes, o
 * processTedOut acharia a transferência ainda PENDENTE e conciliaria — o QA precisa
 * do par na ordem inversa: estorno cego primeiro, CONFIRMED engolido depois
 * (TedService::processTedOut:230).
 */
$wh = smoke_db()->query("SELECT event, status, data FROM webhook_dispatches WHERE event = 'spb-transfer-out' ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$whData = is_array($wh) ? json_decode((string) $wh['data'], true) : [];

smoke_ok(is_array($wh) && $wh['status'] === 'scheduled', 'accept_then_timeout: webhook spb-transfer-out agendado na mesma request');
smoke_ok(
    (int) ($whData['delay_seconds'] ?? 0) > $HANG,
    'accept_then_timeout: webhook atrasado além do hang (' . ($whData['delay_seconds'] ?? '?') . 's) — CONFIRMED depois do estorno, não antes'
);

# ── Consultável: do lado da Celcoin a TED existe ──────────────────────────────
[, $g] = smoke_http($host, 'GET', $spb . '?clienteCode=' . rawurlencode($cc), null, $token);
smoke_ok(($g['body']['id'] ?? '') === $id, 'accept_then_timeout: consultável por clienteCode (aceita de verdade, não só respondida)');

# ── Aditivo: os cenários que já existiam não podem ter mudado ─────────────────
// O 0,06 (timeout) é recusa limpa e IMEDIATA — é o que reproduz o LGR-002. Se ele
// passasse a pendurar, o teste do LGR-002 morreria junto.
$t0 = microtime(true);
[, $t, $httpT] = smoke_http($host, 'POST', $spb, $corpo(0.06, 'timeout-' . $run), $token, 30);
$imediato = microtime(true) - $t0;

smoke_ok($httpT === 504 && ($t['status'] ?? '') === 'ERROR', 'regressão: 0,06 (timeout) segue 504 ERROR');
smoke_ok($imediato < $HANG, sprintf('regressão: 0,06 responde na hora (%.1fs), não pendura', $imediato));

[, $e, $httpE] = smoke_http($host, 'POST', $spb, $corpo(0.15, 'erro-' . $run), $token, 30);
smoke_ok($httpE === 500 && ($e['error']['errorCode'] ?? '') === 'CBE500', 'regressão: 0,15 (error) segue 500 CBE500');

// Valor normal continua aceito e sem pendurar.
$t0 = microtime(true);
[, $n, $httpN] = smoke_http($host, 'POST', $spb, $corpo(150.00, 'normal-' . $run), $token, 30);
smoke_ok($httpN === 201 && ($n['status'] ?? '') === 'PROCESSING', 'regressão: valor normal segue aceito');
smoke_ok((microtime(true) - $t0) < $HANG, 'regressão: valor normal não pendura');

# ── Fora do spb/transfer o slug é uso indevido, e diz isso ────────────────────
// 0,18 num Pix cairia no fallback `error` e devolveria CBE500 — erro plausível de
// causa invisível. 501 + CSLAB501 nomeia o que aconteceu.
[, $pix, $httpPix] = smoke_http($host, 'POST', '/pix/v1/payment', [
    'amount' => 0.18,
    'clientCode' => 'pix-018-' . $run,
    'initiationType' => 'DICT',
    'debitParty' => ['account' => '300547189179'],
    'creditParty' => ['key' => 'destino@pix.com', 'name' => 'Credor'],
], $token, 30);

smoke_ok($httpPix === 501, 'accept_then_timeout fora do spb/transfer: HTTP 501 (não implementado aqui) — veio ' . $httpPix);
smoke_ok(($pix['error']['errorCode'] ?? '') === 'CSLAB501', 'accept_then_timeout fora do spb/transfer: CSLAB501 nomeia o uso indevido');

smoke_finish('spb accept_then_timeout smoke');
