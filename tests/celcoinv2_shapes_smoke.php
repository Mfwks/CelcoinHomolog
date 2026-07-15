<?php

# Smoke test: shapes V2 corrigidos contra logs reais (Lote 1).
# Chama os builders do Cslabs diretamente e assere os campos que o consumidor lê.

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
$_SERVER['REQUEST_URI'] = '/smoke/shapes';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

use App\Core\Cslabs;

$fails = 0;
function ok(bool $c, string $m): void
{
    global $fails;
    echo ($c ? "ok: " : "FAIL: ") . "$m\n";
    if (!$c) {
        $fails++;
    }
}

Cslabs::boot();

# 1) DICT listar — body.listKeys (não pixKeys), sem totalElements
$r = Cslabs::pixDictListByAccountResponse('999888777');
ok(($r['status'] ?? '') === 'SUCCESS', 'listar: status SUCCESS');
ok(array_key_exists('listKeys', $r['body'] ?? []), 'listar: body.listKeys presente');
ok(!array_key_exists('pixKeys', $r['body'] ?? []), 'listar: body.pixKeys removido');
ok(!array_key_exists('totalElements', $r['body'] ?? []), 'listar: totalElements removido');

# 2) DICT claim open — status enum no topo, keyType UPPER, donorAccount, sem claimerAccount.branch
$c = Cslabs::pixDictClaimResponse(['key' => '+5511999999999', 'keyType' => 'PHONE', 'account' => '41003252', 'claimType' => 'OWNERSHIP'], 'open');
ok(($c['status'] ?? '') === 'OPEN', 'claim open: status top-level = OPEN (não SUCCESS)');
ok(($c['body']['keyType'] ?? '') === 'PHONE', 'claim: keyType UPPER (PHONE)');
ok(($c['body']['claimType'] ?? '') === 'OWNERSHIP', 'claim: claimType OWNERSHIP');
ok(isset($c['body']['donorAccount']['account']), 'claim: donorAccount presente');
ok(!isset($c['body']['claimerAccount']['branch']), 'claim: claimerAccount sem branch');
$cc = Cslabs::pixDictClaimResponse(['id' => 'X', 'key' => 'a@b.com', 'keyType' => 'EMAIL'], 'confirm');
ok(($cc['status'] ?? '') === 'CONFIRMED', 'claim confirm: status CONFIRMED no topo');

# 3) Billpayment — version 1.2.0
$bp = Cslabs::billPaymentResponse(['amount' => 100, 'clientRequestId' => '1', 'account' => '497115238', 'transactionIdAuthorize' => 2150495005, 'barCodeInfo' => ['digitable' => '50990000010000000000']]);
ok(($bp['version'] ?? '') === '1.2.0', 'billpayment pagar: version 1.2.0');
ok(($bp['status'] ?? '') === 'PROCESSING', 'billpayment pagar: status PROCESSING');
ok(isset($bp['body']['id']), 'billpayment pagar: body.id presente');
$st = Cslabs::billPaymentStatusRender(['id' => 'x', 'clientRequestId' => '1', 'account' => '41003252', 'amount' => 50, 'transactionIdAuthorize' => 2150310067, 'digitable' => '509'], true);
ok(($st['version'] ?? '') === '1.2.0', 'billpayment status: version 1.2.0');
ok(($st['status'] ?? '') === 'CONFIRMED', 'billpayment status: CONFIRMED');
ok(isset($st['body']['paymentDate']), 'billpayment status: paymentDate em CONFIRMED');

# 4) Charge fetch body — chargeType/informations/invoiceNumber/split
$fb = Cslabs::chargeFetchBody(['amount' => 10, 'status' => 'PENDING', 'duedate' => '2026-07-03', 'transactionId' => 'abc123', 'externalId' => '6267', 'receiver' => ['account' => '495440539'], 'key' => 'k']);
ok(($fb['chargeType'] ?? '') === 'BOLEPIX', 'charge fetch: chargeType BOLEPIX');
ok(array_key_exists('informations', $fb) && $fb['informations'] === null, 'charge fetch: informations null');
ok(array_key_exists('invoiceNumber', $fb['boleto'] ?? []) && $fb['boleto']['invoiceNumber'] === null, 'charge fetch: boleto.invoiceNumber null');
ok(is_array($fb['split'] ?? null), 'charge fetch: split é array');
ok(($fb['boleto']['bankEmissor'] ?? '') === 'CELCOIN INSTITUIÇÃO DE PAGAMENTO - SA', 'charge fetch: bankEmissor real');

if ($fails > 0) {
    echo "\ncelcoinv2 shapes smoke: $fails FALHA(S)\n";
    exit(1);
}
echo "\ncelcoinv2 shapes smoke: OK\n";
