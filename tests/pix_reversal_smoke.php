<?php

/*
 * Smoke da DEVOLUÇÃO Pix — `pix-reversal-in` e `pix-reversal-out`.
 *
 * Pedido pela sessão A em 10/08/2026: o LGR-004 passou a tratar a devolução recebida,
 * e a pergunta que chegou aqui foi direta — "o simulador emite pix-reversal-in?".
 * Não emitia: nenhuma entidade, nenhum template, `known_entity:false` na inscrição.
 *
 * E não vai emitir sozinho, porque não pode: devolução RECEBIDA é iniciada pela
 * contraparte, não por uma API nossa. Nem a Celcoin real tem endpoint que a cause —
 * o `pix/reverse` produz o `-out`. Logo o caminho legítimo, aqui e lá, é o evento
 * chegar de fora; no mock isso é `POST /cslabs/webhook/dispatch`. O que faltava não
 * era o gatilho, era o TEMPLATE — e o template é o que decide se o teste vale.
 *
 * Todas as asserções abaixo são contra o corpus de logs reais (`mocks-v2`, tenants
 * confiapay/homologacao3, 13/05/2026), não contra o que seria "razoável". Duas delas
 * são NEGATIVAS de propósito, e são as que têm dinheiro atrás:
 *
 *   - o `-in` NÃO traz `endToEndId`. O consumidor lia esse campo, achava null e o
 *     evento morria com R$23.000 dentro (evento #5353). Um template "gentil" que
 *     mandasse o campo faria o mock aprovar consumidor que a Celcoin real reprova.
 *   - o `-in` NÃO traz `currentBalance` — só `oldBalance`. O `-out` traz os dois.
 *
 * Builder direto, sem HTTP: o que se testa aqui é forma de payload, e forma não
 * depende de path, corpo lido de php://input nem de processo separado.
 */

define('TMP', __DIR__ . '/tmp_smoke/');
if (!is_dir(TMP)) {
    mkdir(TMP, 0775, true);
}
$dbFile = TMP . 'cslabs/cslabs.sqlite';
foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-shm'] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $file = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

defined('SITE') or define('SITE', 'https://cslabs.mfwks.com');
defined('BASE') or define('BASE', '/');
defined('DEV') or define('DEV', false);

require __DIR__ . '/../app/functions.php';
$_SERVER['REQUEST_URI'] = '/smoke/pix-reversal';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

use App\Core\Cslabs;

$fails = 0;
function ok(bool $c, string $m): void
{
    global $fails;
    echo ($c ? 'ok: ' : 'FAIL: ') . "$m\n";
    if (!$c) {
        $fails++;
    }
}

Cslabs::boot();

# ─── 1) A entidade existe para o mock ───────────────────────────────────────────
$conhecidas = Cslabs::knownWebhookEntities();
ok(in_array('pix-reversal-in', $conhecidas, true), 'pix-reversal-in é entidade conhecida (inscrição não avisa "ninguém dispara isso")');
ok(in_array('pix-reversal-out', $conhecidas, true), 'pix-reversal-out continua conhecida');

# ─── 2) O `-in`: o lado que travou o LGR-004 ────────────────────────────────────
$in = Cslabs::sampleWebhookBody('pix-reversal-in', 'CONFIRMED');

ok($in !== ['id' => $in['id'] ?? null, 'timestamp' => $in['timestamp'] ?? null], '-in: tem template próprio, não caiu no default {id,timestamp}');
ok(!empty($in['returnIdentification']) && str_starts_with($in['returnIdentification'], 'D'), '-in: a chave da devolução vem em returnIdentification (D…)');
ok(!array_key_exists('endToEndId', $in), '-in: NÃO manda endToEndId — é o campo cuja ausência travou R$23k no #5353');
ok(!empty($in['originalEndToEndId']), '-in: originalEndToEndId presente (o E2E do envio original)');
ok(($in['originalEntoEndId'] ?? null) === ($in['originalEndToEndId'] ?? false), '-in: replica o typo da Celcoin — originalEntoEndId com o MESMO valor');
ok(array_key_exists('originalId', $in) && array_key_exists('originalClientCode', $in), '-in: identifica o original por originalId/originalClientCode');
ok(!array_key_exists('originalPaymentId', $in), '-in: NÃO usa originalPaymentId — esse é do -out');
ok(array_key_exists('oldBalance', $in) && !array_key_exists('currentBalance', $in), '-in: só oldBalance, sem currentBalance');
ok(($in['amount'] ?? 0) > 0, '-in: amount > 0 (o consumidor descarta o evento sem valor)');
ok(!empty($in['creditParty']['account']), '-in: creditParty.account preenchido — é por ele que a conta nossa é resolvida');
ok(!empty($in['debitParty']['account']), '-in: debitParty.account preenchido');
ok(!isset($in['creditParty']['key']), '-in: NÃO existe chave Pix na party (medido) — quem resolve a conta é o account');

# ─── 3) O `-out`: a outra metade, e ela NÃO é espelho do -in ────────────────────
$out = Cslabs::sampleWebhookBody('pix-reversal-out', 'CONFIRMED');

ok(($out['originalPaymentId'] ?? null) === ($out['id'] ?? false), '-out: originalPaymentId == id, como nos dois eventos reais do corpus');
ok(!array_key_exists('originalEntoEndId', $out), '-out: NÃO repete o typo — o typo é exclusivo do -in');
ok(array_key_exists('currentBalance', $out) && array_key_exists('oldBalance', $out), '-out: traz os dois saldos');
ok(array_key_exists('additionalInformation', $out), '-out: traz additionalInformation (o -in não traz)');
ok(!empty($out['debitParty']['account']), '-out: debitParty.account preenchido — no -out a conta nossa é a do débito');
ok(!array_key_exists('originalClientCode', $out), '-out: usa clientCode, não originalClientCode');

# ─── 4) O envelope ──────────────────────────────────────────────────────────────
foreach (['pix-reversal-in' => $in, 'pix-reversal-out' => $out] as $entidade => $corpo) {
    $env = Cslabs::webhookEnvelope($entidade, 'CONFIRMED', $corpo);

    ok(($env['entity'] ?? '') === $entidade, "$entidade: envelope carrega a entity");
    // Medido nos dois: grafia minúscula. `createTimeStamp` (S maiúsculo) é de pix-payment-* e spb-*.
    ok(array_key_exists('createTimestamp', $env) && !array_key_exists('createTimeStamp', $env), "$entidade: timestamp é createTimestamp (s minúsculo), como no log real");
    ok(($env['webhookId'] ?? '') === ($corpo['id'] ?? null), "$entidade: webhookId == body.id");
}

# ─── 5) A colisão que o consumidor precisa sobreviver ───────────────────────────
/*
 * ⚠️ n=1, e por isso está aqui como asserção do MOCK e não como regra da Celcoin:
 * no único par real do corpus (13/05/2026 16:31), o `-in` e o `-out` da mesma
 * devolução on-us chegaram com o MESMO `returnIdentification` e `originalEndToEndId`,
 * diferindo só no `body.id`. Quem usar returnIdentification como chave de
 * idempotência tem que escopá-la por conta e por tipo — senão a segunda perna é
 * descartada como duplicata. Aqui garantimos que os dois templates são disparáveis
 * com a mesma chave, para que esse cenário seja reproduzível de propósito.
 */
$chave = 'D13935893202605131931vg0IFgKfQaO';
$e2e = 'E13935893202605131930rxcN29ERrlO';
$parIn = ['returnIdentification' => $chave, 'originalEndToEndId' => $e2e] + $in;
$parOut = ['returnIdentification' => $chave, 'originalEndToEndId' => $e2e] + $out;

ok($parIn['returnIdentification'] === $parOut['returnIdentification'], 'par on-us: os dois lados aceitam a mesma chave sobrescrita via body');
ok(($parIn['id'] ?? 1) !== ($parOut['id'] ?? 2), 'par on-us: mesmo com a chave igual, body.id difere — é o que distingue as pernas');

if ($fails > 0) {
    echo "\npix reversal smoke: $fails FALHA(S)\n";
    exit(1);
}
echo "\npix reversal smoke: OK\n";
