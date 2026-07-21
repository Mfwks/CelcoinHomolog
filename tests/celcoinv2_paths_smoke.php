<?php

/*
 * Smoke dos streams que ramificam por PATH (v1 plano × V2 enveloped).
 *
 * Por que é funcional e não unitário: o branch mora em Cslabs::isV2(), que lê o path
 * do request — chamar o builder direto nunca exercita isso. E por que HTTP de verdade:
 * Cslabs::requestBody() lê php://input, que não dá pra popular no CLI.
 *
 * O servidor embutido roteia tudo quando index.php é passado como ROUTER
 * (php -S host:porta index.php), que é o equivalente ao rewrite do .htaccess.
 *
 * Contrato defendido aqui: as rotas v1 NÃO podem ganhar envelope (os consumidores v1
 * leem caminhos fixos no topo, sem fallback), e as V2 precisam do envelope real.
 * Ver HOMOLOGACAO_CELCOIN_V2.md §0 e §1.1.
 */

$host = '127.0.0.1:8391';
$root = dirname(__DIR__);

// Se a porta já estiver ocupada, o php -S morre e o teste rodaria contra o serviço
// alheio — falhando de um jeito confuso. Melhor abortar dizendo o que houve.
$busy = @fsockopen('127.0.0.1', 8391, $errno, $errstr, 0.3);
if ($busy) {
    fclose($busy);
    fwrite(STDERR, "porta 8391 já está em uso — feche o processo e rode de novo\n");
    exit(1);
}

// auto_prepend_file isola o TMP: sem ele o smoke gravaria no SQLite real do mock.
$server = proc_open(
    'php -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/tmp_isolate.php')
        . ' -S ' . $host . ' index.php',
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $root
);

if (!is_resource($server)) {
    fwrite(STDERR, "não foi possível subir o servidor embutido\n");
    exit(1);
}

register_shutdown_function(function () use ($server) {
    $status = proc_get_status($server);
    if ($status['running'] ?? false) {
        // O php -S roda como filho do sh; matar o grupo evita deixar a porta presa.
        @exec('pkill -P ' . $status['pid'] . ' 2>/dev/null');
        proc_terminate($server);
    }
    proc_close($server);
});

// Espera a porta subir (em vez de sleep fixo).
$up = false;
for ($i = 0; $i < 50; $i++) {
    $conn = @fsockopen('127.0.0.1', 8391, $errno, $errstr, 0.2);
    if ($conn) {
        fclose($conn);
        $up = true;
        break;
    }
    usleep(100000);
}
if (!$up) {
    fwrite(STDERR, "servidor embutido não respondeu em 5s\n");
    exit(1);
}

$fails = 0;
function ok(bool $c, string $m): void
{
    global $fails;
    echo ($c ? "ok: " : "FAIL: ") . "$m\n";
    if (!$c) {
        $fails++;
    }
}

$token = null;

function call(string $method, string $uri, ?array $body = null): array
{
    global $host, $token;

    $headers = '';
    // Sem bearer, Cslabs::boot() deriva o client_id de um fingerprint do request —
    // que varia entre um POST com corpo e um GET. As entidades são escopadas por
    // cliente, então POST e consulta cairiam em namespaces diferentes e a consulta
    // daria 404. O consumidor real sempre manda token; o teste faz o mesmo.
    if ($token !== null) {
        $headers .= "Authorization: Bearer {$token}\r\n";
    }

    $opts = ['http' => [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => 10,
    ]];
    if ($body !== null) {
        $headers .= "Content-Type: application/json\r\n";
        $opts['http']['content'] = json_encode($body);
    }
    if ($headers !== '') {
        $opts['http']['header'] = $headers;
    }

    $raw = @file_get_contents('http://' . $host . $uri, false, stream_context_create($opts));
    $raw = $raw === false ? '' : $raw;
    return [$raw, json_decode($raw, true)];
}

$tokenOpts = ['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query([
        'client_id' => 'paths-smoke',
        'client_secret' => 'paths-smoke-secret',
        'grant_type' => 'client_credentials',
    ]),
    'ignore_errors' => true,
    'timeout' => 10,
]];
$tokenRaw = @file_get_contents('http://' . $host . '/v5/token', false, stream_context_create($tokenOpts));
$token = json_decode((string) $tokenRaw, true)['access_token'] ?? null;
ok($token !== null, 'auth: token emitido (fixa o client_id entre os casos)');

# ── DICT consultar: mesmo stream (api/key), shapes diferentes ──
[, $v1] = call('GET', '/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/123?key=ok@pix.com');
ok(isset($v1['endtoEndId']), 'dict v1: endtoEndId no TOPO (CelcoinPix::consultaChavePix lê fixo)');
ok(!isset($v1['body']), 'dict v1: sem envelope body');

[, $v2] = call('GET', '/baas/v2/pix/dict/entry/external/123?key=ok@pix.com');
ok(($v2['status'] ?? '') === 'SUCCESS', 'dict v2: status SUCCESS no topo');
ok(isset($v2['body']['endtoEndId']), 'dict v2: endtoEndId dentro de body');
ok(isset($v2['body']['owner']['type']), 'dict v2: owner.type (NATURAL_PERSON/LEGAL_PERSON)');
ok(($v2['body']['isSameTaxId'] ?? null) === false, 'dict v2: isSameTaxId false sem ownerTaxId');

$doc = $v1['owner']['documentNumber'] ?? '';
[, $same] = call('GET', '/baas/v2/pix/dict/entry/external/123?key=ok@pix.com&ownerTaxId=' . $doc);
ok(($same['body']['isSameTaxId'] ?? null) === true, 'dict v2: isSameTaxId true quando ownerTaxId casa');

# ── EMV: /pix/v1/emv (v1, plano) × /pix/v1/emv/full (V2, enveloped) ──
$emv = ['emv' => '00020126580014br.gov.bcb.pix0136abc-123456304ABCD'];
[, $e1] = call('POST', '/pix/v1/emv', $emv);
ok(isset($e1['merchantAccountInformation']['key']), 'emv v1: merchantAccountInformation.key no topo');
ok(array_key_exists('transactionAmount', $e1 ?? []), 'emv v1: transactionAmount no topo (path fixo da v1)');
ok(!isset($e1['body']), 'emv v1: sem envelope');

[, $e2] = call('POST', '/pix/v1/emv/full', $emv);
ok(($e2['status'] ?? null) === 200, 'emv/full: status é INT 200 (real)');
ok(isset($e2['body']['amount']['original']), 'emv/full: body.amount.original');
ok(array_key_exists('additionaldata', $e2['body']['merchantAccountInformation'] ?? []), 'emv/full: quirk additionaldata (d minúsculo)');
ok(($e2['body']['type'] ?? '') === 'STATIC', 'emv/full: type STATIC quando o EMV não é dinâmico');
// No real (mocks-v2, 14 amostras STATIC) `payload` vem NULL no QR estático —
// só o dinâmico traz a cobrança. url/gui idem.
ok(array_key_exists('payload', $e2['body']) && $e2['body']['payload'] === null, 'emv/full: payload null em QR estático (real)');
ok(array_key_exists('url', $e2['body']['merchantAccountInformation']) && $e2['body']['merchantAccountInformation']['url'] === null, 'emv/full: url null em QR estático (real)');

# ── Ciclo do QR dinâmico: criar → ler (decode) → pagar → location CONCLUIDA ──
// O banco do tmp_smoke sobrevive entre rodadas e o pagamento é idempotente por
// clientCode — sem sufixo único, a 2ª execução cairia no replay e não liquidaria.
$run = substr(hash('sha256', (string) getmypid() . microtime(true)), 0, 8);
[, $qr] = call('POST', '/pix/v1/brcode/dynamic', [
    'key' => 'cab8f3ed-18ae-4cb1-a5a1-1d525a4e86fd',
    'amount' => '73.42',
    'expiration' => 3600,
    'clientRequestId' => 'ciclo-e2e-' . $run,
    'merchant' => ['city' => 'Sao Pedro', 'name' => 'Loja Teste'],
]);
$emvDin = $qr['body']['body']['dynamicBRCodeData']['emvqrcps'] ?? '';
$locDin = $qr['body']['body']['location'] ?? '';
$txidDin = $qr['body']['transactionIdentification'] ?? '';
ok($emvDin !== '' && $locDin !== '', 'ciclo QR: criação devolveu emv + location');

// 1) Ler o QR tem que devolver o que foi cobrado — não um valor sintético.
[, $dec] = call('POST', '/pix/v1/emv', ['emv' => $emvDin]);
ok((string) ($dec['type'] ?? '') === '2', 'ciclo QR: decode reconhece dinâmico (type 2)');
ok((float) ($dec['transactionAmount'] ?? 0) === 73.42, 'ciclo QR: decode devolve o valor REAL da cobrança');
ok(($dec['merchantAccountInformation']['key'] ?? '') === 'cab8f3ed-18ae-4cb1-a5a1-1d525a4e86fd', 'ciclo QR: decode resolve a chave da cobrança (não está no EMV)');
ok(($dec['transactionIdentification'] ?? '') === $txidDin, 'ciclo QR: decode devolve o txid da cobrança');

// 2) A location responde ATIVA antes do pagamento.
$locEnc = rawurlencode($locDin);
[, $antes] = call('GET', '/pix/v1/collection/immediate/payload/' . $locEnc);
ok(($antes['status'] ?? '') === 'ATIVA', 'ciclo QR: location ATIVA antes de pagar');
ok(($antes['valor']['original'] ?? '') === '73.42', 'ciclo QR: location devolve o valor cobrado');

// 3) Pagar como STATIC_QRCODE tem que ser REJEITADO: o txid dinâmico tem 35
//    chars e a Celcoin real limita a 25 nesse initiationType (CBE136).
[, $errStatic] = call('POST', '/baas/v2/pix/payment', [
    'amount' => 73.42, 'clientCode' => 'ciclo-static-' . $run,
    'initiationType' => 'STATIC_QRCODE', 'transactionIdentification' => $txidDin,
    'debitParty' => ['account' => '300547189179'],
]);
ok(($errStatic['error']['errorCode'] ?? '') === 'CBE136', 'ciclo QR: CBE136 quando txid > 25 chars em STATIC_QRCODE (regra real)');

// 4) Pagar como DYNAMIC_QRCODE liquida a cobrança.
[, $pago] = call('POST', '/baas/v2/pix/payment', [
    'amount' => 73.42, 'clientCode' => 'ciclo-pago-' . $run,
    'initiationType' => 'DYNAMIC_QRCODE', 'transactionIdentification' => $txidDin,
    'debitParty' => ['account' => '300547189179'],
    'creditParty' => ['key' => 'cab8f3ed-18ae-4cb1-a5a1-1d525a4e86fd'],
    'remittanceInformation' => 'pagamento do ciclo',
]);
ok(isset($pago['body']['endToEndId']), 'ciclo QR: pagamento aceito');

// 5) Agora a location vira CONCLUIDA e mostra o pagamento recebido.
[, $depois] = call('GET', '/pix/v1/collection/immediate/payload/' . $locEnc);
ok(($depois['status'] ?? '') === 'CONCLUIDA', 'ciclo QR: location CONCLUIDA depois de pago');
ok(($depois['pix'][0]['valor'] ?? '') === '73.42', 'ciclo QR: bloco pix[] com o valor pago');
ok(($depois['pix'][0]['endToEndId'] ?? '') === ($pago['body']['endToEndId'] ?? 'x'), 'ciclo QR: endToEndId do pagamento no bloco pix[]');

// 6) Reenvio do mesmo pagamento não duplica o pix[] (idempotência).
call('POST', '/baas/v2/pix/payment', [
    'amount' => 73.42, 'clientCode' => 'ciclo-pago-' . $run,
    'initiationType' => 'DYNAMIC_QRCODE', 'transactionIdentification' => $txidDin,
    'debitParty' => ['account' => '300547189179'],
]);
[, $rep] = call('GET', '/pix/v1/collection/immediate/payload/' . $locEnc);
ok(count($rep['pix'] ?? []) === 1, 'ciclo QR: retry de pagamento não duplica pix[]');

// 7) O link do QR (a própria location) é servido pelo mock.
[, $viaLink] = call('GET', '/pixqrcode/v2/' . basename($locDin));
ok(($viaLink['status'] ?? '') === 'CONCLUIDA' && ($viaLink['txid'] ?? '') === $txidDin, 'ciclo QR: a URL impressa no QR resolve pelo mock');

# ── Pix payment: body nos DOIS (a v1 lê body.id), status PROCESSING só no V2 ──
$pix = [
    'amount' => 1500,
    'clientCode' => '0000501',
    'debitParty' => ['account' => '300547189179'],
    'creditParty' => ['key' => 'destino@pix.com', 'name' => 'Credor'],
];
[, $p1] = call('POST', '/baas-wallet-transactions-webservice/v1/pix/payment', $pix);
ok(($p1['status'] ?? '') === 'SUCCESS', 'pix v1: status SUCCESS no topo (ConexaoBaas lê $resp->status)');
ok(isset($p1['transactionId']), 'pix v1: transactionId segue no topo');
ok(isset($p1['body']['id']), 'pix v1: body.id presente (Pix.php:979 lê $retorno->body->id — era null antes)');
ok(($p1['body']['debitParty']['account'] ?? '') === '300547189179', 'pix v1: body.debitParty.account ecoado');

$pix['clientCode'] = '0000502';
[, $p2] = call('POST', '/baas/v2/pix/payment', $pix);
ok(($p2['status'] ?? '') === 'PROCESSING', 'pix v2: status PROCESSING (real)');
ok(isset($p2['body']['id']), 'pix v2: body.id presente');
ok(array_key_exists('recurrencyAccept', $p2['body'] ?? []), 'pix v2: body.recurrencyAccept (campo real)');
ok(array_key_exists('vlcpAmount', $p2['body'] ?? []), 'pix v2: body.vlcpAmount (campo real de saque)');

# Idempotência tem que devolver o MESMO shape (inclusive body) no replay.
[, $replay] = call('POST', '/baas/v2/pix/payment', $pix);
ok(($replay['body']['id'] ?? '') === ($p2['body']['id'] ?? 'x'), 'pix v2: replay repete o mesmo body.id');
ok(($replay['status'] ?? '') === 'PROCESSING', 'pix v2: replay mantém status PROCESSING');

# ── BR Code estático: plano nos dois (real não usa envelope) ──
[, $bs] = call('POST', '/pix/v1/brcode/static', ['key' => 'abc-key', 'amount' => '10.00']);
ok(isset($bs['emvqrcps']), 'brcode static: emvqrcps no topo (v1 lê fixo — era null antes)');
ok(!isset($bs['body']), 'brcode static: sem envelope');

# ── Transferência interna: o endToEndId tem que sobreviver POST → consulta ──
$cri = 'cri-' . bin2hex(random_bytes(6));
[, $t1] = call('POST', '/baas/v2/wallet/internal/transfer', [
    'amount' => 1500,
    'clientRequestId' => $cri,
    'debitParty' => ['account' => '41003245', 'name' => 'Pagador'],
    'creditParty' => ['account' => '41003252', 'name' => 'Recebedor'],
    'description' => 'Transferencia interna',
]);
ok(($t1['status'] ?? '') === 'PROCESSING', 'internal transfer: status PROCESSING (real)');
ok(isset($t1['body']['id']), 'internal transfer: body.id presente');
ok(($t1['body']['debitParty']['bank'] ?? '') === '13935893', 'internal transfer: partes trazem bank/branch/taxId');

[, $t2] = call('GET', '/baas/v2/wallet/internal/transfer/status?ClientRequestId=' . $cri);
ok(($t2['body']['id'] ?? '') === ($t1['body']['id'] ?? 'x'), 'internal transfer status: mesmo id do POST');
// Regressão: a consulta lia ['webhook']['endToEndId'] (sempre null) e sorteava um id novo a cada poll.
ok(($t2['body']['endToEndId'] ?? '') === ($t1['body']['endToEndId'] ?? 'x'), 'internal transfer status: endToEndId igual ao do POST (não sorteia novo)');
[, $t3] = call('GET', '/baas/v2/wallet/internal/transfer/status?ClientRequestId=' . $cri);
ok(($t3['body']['endToEndId'] ?? '') === ($t2['body']['endToEndId'] ?? 'x'), 'internal transfer status: endToEndId estável entre polls');

# ── Charge PDF: binário cru, não JSON ──
[$raw] = call('GET', '/baas/v2/charge/pdf/abc123');
ok(str_starts_with($raw, '%PDF'), 'charge/pdf: devolve PDF binário cru (real: application/pdf)');
ok(!str_contains($raw, 'cslabs_info'), 'charge/pdf: binário passa intacto pelo ob_start');

if ($fails > 0) {
    echo "\ncelcoinv2 paths smoke: $fails FALHA(S)\n";
    exit(1);
}
echo "\ncelcoinv2 paths smoke: OK\n";
