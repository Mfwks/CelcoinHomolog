<?php

# Smoke test: PIX payment (baas) — sucesso para qualquer amount >= R$ 1,00,
# independente de clientCode; magic-amount (< R$ 1,00) segue disparando cenários.
# Regressão do CBE500 falso quando o amount continha "500" (ex.: 1500, 2500).

define('TMP', __DIR__ . '/tmp_smoke/');
if (!is_dir(TMP)) {
    mkdir(TMP, 0775, true);
}
$dbFile = TMP . 'cslabs/cslabs.sqlite';
if (is_file($dbFile)) {
    @unlink($dbFile);
    @unlink($dbFile . '-wal');
    @unlink($dbFile . '-shm');
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 4));
    $file = __DIR__ . '/../app/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

defined('SITE') or define('SITE', 'https://cslabs.mfwks.com');
defined('BASE') or define('BASE', '/');
defined('DEV') or define('DEV', false);

require __DIR__ . '/../app/functions.php';

$_SERVER['REQUEST_URI'] = '/smoke/pix-payment';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

use App\Core\Cslabs;

function expect(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
    echo "ok: $msg\n";
}

Cslabs::boot();

function pixReq(array $over = []): array
{
    return array_merge([
        'amount' => 2000,
        'clientCode' => '0000000',
        'initiationType' => 'DICT',
        'paymentType' => 'IMMEDIATE',
        'urgency' => 'HIGH',
        'transactionType' => 'TRANSFER',
        'debitParty' => ['account' => '300547189179'],
        'creditParty' => ['bank' => '', 'key' => 'financeiro@credor.com', 'name' => 'Credor PIX S.A.', 'accountType' => ''],
        'remittanceInformation' => 'Compra de divida',
    ], $over);
}

# 1) Caso do briefing que já funcionava (amount 2000, clientCode 0000000).
$r = Cslabs::pixPaymentResponse(pixReq(['amount' => 2000, 'clientCode' => '0000000']));
expect(($r['status'] ?? '') === 'SUCCESS', 'amount 2000 / clientCode 0000000 -> SUCCESS');
expect(str_starts_with($r['transactionId'] ?? '', 'pix_'), '2000 -> transactionId pix_*');
expect(str_starts_with($r['endToEndId'] ?? '', 'E'), '2000 -> endToEndId E*');

# 2) Caso do briefing que devolvia CBE500 falso (amount 1500, clientCode 0000319).
$r = Cslabs::pixPaymentResponse(pixReq([
    'amount' => 1500,
    'clientCode' => '0000319',
    'debitParty' => ['account' => '300547189195'],
    'creditParty' => ['bank' => '', 'key' => 'credor2@pix.com', 'name' => 'Credor PIX Dois S.A.', 'accountType' => ''],
]));
expect(($r['status'] ?? '') === 'SUCCESS', 'amount 1500 / clientCode 0000319 -> SUCCESS (regressao CBE500)');

# 3) Outros amounts que contêm "500" como substring — todos sucesso.
foreach ([500, 2500, 5000, 15000, 500000] as $amt) {
    $r = Cslabs::pixPaymentResponse(pixReq(['amount' => $amt]));
    expect(($r['status'] ?? '') === 'SUCCESS', "amount $amt -> SUCCESS");
}

# 4) transactionId/endToEndId são únicos por requisição (clientRequestId aleatório).
$a = Cslabs::pixPaymentResponse(pixReq(['amount' => 1500]));
$b = Cslabs::pixPaymentResponse(pixReq(['amount' => 1500]));
expect($a['transactionId'] !== $b['transactionId'], 'transactionId único por requisição');
expect($a['endToEndId'] !== $b['endToEndId'], 'endToEndId único por requisição');

# 5) clientCode não influencia o resultado (várias variações -> sucesso).
foreach (['0000000', '0000319', '9999999', '', 'CLI-ABC'] as $cc) {
    $r = Cslabs::pixPaymentResponse(pixReq(['amount' => 1500, 'clientCode' => $cc]));
    expect(($r['status'] ?? '') === 'SUCCESS', "clientCode '$cc' -> SUCCESS");
}

# 6) Gatilho controlado de erro (magic-amount preservado).
$sentinels = [
    [0.15, 'CBE500'],
    [0.01, 'CBE301'],
    [0.02, 'CBE189'],
    [0.03, 'CBE171'],
];
foreach ($sentinels as [$amt, $code]) {
    $r = Cslabs::pixPaymentResponse(pixReq(['amount' => $amt]));
    expect(($r['status'] ?? '') === 'ERROR', "amount $amt -> ERROR");
    expect(($r['error']['errorCode'] ?? '') === $code, "amount $amt -> $code");
}

# 7) Palavra-chave de erro na chave PIX ainda funciona (endpoint sem sentinela de amount usa a chave).
#    Aqui validamos que amount >= 1 sozinho nunca dispara erro por conteúdo textual.
$r = Cslabs::pixPaymentResponse(pixReq(['amount' => 1500, 'creditParty' => ['key' => 'pagamento500@credor.com']]));
expect(($r['status'] ?? '') === 'SUCCESS', 'creditParty.key nao é inspecionada -> SUCCESS');

# 8) Idempotência por clientCode (mesmo mov_pix_id reenviado -> replay da transação original).
# Simula o que o stream payment-baas faz ao persistir um sucesso.
$cc = '0000777';
expect(Cslabs::pixPaymentReplay($cc) === null, 'replay ausente antes da primeira transação');

$first = Cslabs::pixPaymentResponse(pixReq(['amount' => 1500, 'clientCode' => $cc]));
$state = [
    'status' => 'PROCESSING',
    'created_ts' => time(),
    'created_at' => date(DATE_ATOM),
    'body' => [
        'id' => $first['transactionId'],
        'amount' => $first['amount'],
        'clientCode' => $cc,
        'clientRequestId' => $first['clientRequestId'],
        'endToEndId' => $first['endToEndId'],
    ],
];
Cslabs::writeEntity('pix_payments', $first['transactionId'], $state);
Cslabs::writeEntity('pix_payments', $cc, $state);
Cslabs::writeEntity('pix_payments', $first['endToEndId'], $state);

$replay = Cslabs::pixPaymentReplay($cc);
expect(is_array($replay), 'replay presente após persistir');
expect($replay['status'] === 'SUCCESS', 'replay -> SUCCESS');
expect($replay['transactionId'] === $first['transactionId'], 'replay mantém o mesmo transactionId');
expect($replay['endToEndId'] === $first['endToEndId'], 'replay mantém o mesmo endToEndId');
expect($replay['clientRequestId'] === $first['clientRequestId'], 'replay mantém o mesmo clientRequestId');
expect(abs($replay['amount'] - $first['amount']) < 0.001, 'replay mantém o mesmo amount');

# clientCode não persistido não replica; vazio nunca replica.
expect(Cslabs::pixPaymentReplay('0000778') === null, 'clientCode desconhecido -> sem replay');
expect(Cslabs::pixPaymentReplay('') === null, 'clientCode vazio -> sem replay');

echo "\nPIX payment smoke: OK\n";
