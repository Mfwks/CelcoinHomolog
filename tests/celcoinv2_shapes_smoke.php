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

# 5) Onboarding proposal list — body.proposal (singular) com item rico
Cslabs::writeEntity('onboarding_proposals', 'prop-1', [
    'proposalId' => 'prop-1', 'clientCode' => 'BO-CV2-1-1', 'documentNumber' => '12345678000199',
    'proposalStatus' => 'CREATED', 'createDate' => '2026-07-01T10:00:00Z',
]);
$pl = Cslabs::onboardingProposalListResponse([]);
ok(array_key_exists('proposal', $pl['body'] ?? []), 'proposal list: body.proposal (singular)');
ok(!array_key_exists('proposals', $pl['body'] ?? []), 'proposal list: body.proposals removido');
$it = $pl['body']['proposal'][0] ?? [];
ok(($it['proposalType'] ?? '') === 'PJ', 'proposal list: proposalType PJ (CNPJ 14 díg)');
ok(($it['status'] ?? '') === 'RESOURCE_CREATED', 'proposal list: status CREATED->RESOURCE_CREATED');
ok(isset($it['documentscopys'][0]['url']), 'proposal list: documentscopys[].url presente');
ok(array_key_exists('limit', $pl['body'] ?? []), 'proposal list: body.limit presente');

# 6) BR Code estático — PLANO (a v1 lê emvqrcps/transactionId no topo, sem fallback)
$bs = Cslabs::brcodeStaticCreateResponse(['key' => 'abc-key', 'amount' => '3041.91']);
ok(!array_key_exists('body', $bs), 'brcode static: sem envelope body (v1 lê topo)');
ok(is_int($bs['transactionId'] ?? null), 'brcode static: transactionId é int (real)');
ok(str_starts_with($bs['emvqrcps'] ?? '', '000201'), 'brcode static: emvqrcps no topo');
ok(array_key_exists('recurrency', $bs) && $bs['recurrency'] === null, 'brcode static: recurrency null');

# 7) BR Code dinâmico — duplo-envelope preservado (v1 lê caminhos fixos) + status int
$bd = Cslabs::brcodeDynamicCreateResponse(['key' => 'abc-key', 'amount' => '11.91']);
ok($bd['status'] === 201, 'brcode dynamic: status é INT 201 (real)');
ok(isset($bd['body']['body']['dynamicBRCodeData']['emvqrcps']), 'brcode dynamic: body.body.dynamicBRCodeData.emvqrcps (path fixo da v1)');
ok(isset($bd['body']['body']['amount']['original']), 'brcode dynamic: body.body.amount.original (path fixo da v1)');
ok(($bd['body']['entity'] ?? '') === 'DynamicBRCode', 'brcode dynamic: entity DynamicBRCode');

# 8) Cancelar cobrança — PROCESSING + cobrança inteira em 1.1.0
Cslabs::writeEntity('charges', 'chg-1', [
    'transactionId' => 'chg-1', 'externalId' => '4387954729', 'amount' => 100,
    'status' => 'PENDING', 'duedate' => '2026-06-18', 'receiver' => ['account' => '495440539'], 'key' => 'k',
]);
$cx = Cslabs::chargeCancelResponse('chg-1', ['reason' => 'x']);
ok(($cx['status'] ?? '') === 'PROCESSING', 'charge cancel: status PROCESSING (não SUCCESS)');
ok(($cx['version'] ?? '') === '1.1.0', 'charge cancel: version 1.1.0');
ok(array_key_exists('chargeType', $cx['body'] ?? []), 'charge cancel: body é a cobrança inteira');

# 9) Extrato — paginação no TOPO (irmã de body), item com balanceType/additionalInformation
$wm = Cslabs::walletMovementResponse(['Account' => '41003245', 'DateFrom' => '2026-05-12', 'DateTo' => '2026-05-13']);
ok(array_key_exists('totalItems', $wm), 'movement: totalItems no topo');
ok(array_key_exists('limitPerPage', $wm), 'movement: limitPerPage no topo');
ok(array_key_exists('dateFrom', $wm), 'movement: dateFrom no topo');
ok(!array_key_exists('totalItems', $wm['body'] ?? []), 'movement: paginação NÃO fica dentro de body');
$mv = $wm['body']['movements'][0] ?? [];
ok(in_array($mv['balanceType'] ?? '', ['DEBIT', 'CREDIT'], true), 'movement: balanceType DEBIT/CREDIT');
ok(in_array($mv['movementType'] ?? '', ['PIXPAYMENTOUT', 'PIXREVERSALIN'], true), 'movement: movementType real');
ok(($mv['status'] ?? '') === 'Saldo Liberado', 'movement: status "Saldo Liberado" (texto)');
ok(isset($mv['additionalInformation']['currentBalance']), 'movement: additionalInformation.currentBalance');
ok(!array_key_exists('counterParty', $mv), 'movement: counterParty removido (não existe no real)');

if ($fails > 0) {
    echo "\ncelcoinv2 shapes smoke: $fails FALHA(S)\n";
    exit(1);
}
echo "\ncelcoinv2 shapes smoke: OK\n";
