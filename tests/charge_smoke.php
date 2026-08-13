<?php

# Smoke test: emissão, fetch e reconhecimento de boleto próprio no authorize.

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

$_SERVER['REQUEST_URI'] = '/smoke/charge';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

use App\Core\Cslabs;
use App\Core\Db;

function expect(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
    echo "ok: $msg\n";
}

Cslabs::boot();

# 1) Emitir boleto via chargeResponse + persistência espelhando o stream
$body = [
    'amount' => 250.00,
    'externalId' => 'EXT-CHARGE-001',
    'key' => 'cobranca@empresa.com',
    'duedate' => '2026-06-30',
    'debtor' => [
        'name' => 'Cliente Teste',
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
        'account' => '300541554121',
        'city' => 'São Paulo',
        'state' => 'SP',
        'postalCode' => '04570001',
        'publicArea' => 'Avenida Nova Independência',
    ],
];

$response = Cslabs::chargeResponse($body);
expect(($response['status'] ?? null) === 'SUCCESS', 'charge emitido com SUCCESS');
$txid = $response['body']['transactionId'];
expect(!empty($txid), 'transactionId presente');

$dueDate = $body['duedate'];
$amount = round((float) $body['amount'], 2);
$bankLine = Cslabs::boletoBankLine($txid, $amount, $dueDate);
$bankLineDigits = Cslabs::boletoBankLineDigits($bankLine);
$barCode = Cslabs::boletoBarCode($txid, $amount, $dueDate);
expect(strlen($bankLineDigits) === 47, 'bankLine tem 47 dígitos (got ' . strlen($bankLineDigits) . ')');
expect(strlen($barCode) === 44, 'barCode tem 44 dígitos (got ' . strlen($barCode) . ')');

$charge = [
    'transactionId' => $txid,
    'externalId' => $body['externalId'],
    'amount' => $amount,
    'duedate' => $dueDate,
    'key' => $body['key'],
    'debtor' => $body['debtor'],
    'receiver' => $body['receiver'],
    'instructions' => null,
    'split' => null,
    'bankLine' => $bankLine,
    'barCode' => $barCode,
    'status' => 'PENDING',
    'created_at' => date(DATE_ATOM),
];

Db::transaction(function () use ($txid, $body, $bankLineDigits, $barCode, $charge) {
    Cslabs::writeEntity('charges', $txid, $charge);
    Cslabs::writeEntity('charges_by_external_id', $body['externalId'], ['transactionId' => $txid]);
    Cslabs::writeEntity('charges_by_bank_line', $bankLineDigits, ['transactionId' => $txid]);
    Cslabs::writeEntity('charges_by_bar_code', $barCode, ['transactionId' => $txid]);
});

# 2) Fetch por TransactionId
$fetch = Cslabs::chargeFetchResponse($txid, null);
expect($fetch['status'] === 'SUCCESS', 'fetch por txid SUCCESS');
expect($fetch['body']['transactionId'] === $txid, 'fetch.body.transactionId bate');
expect($fetch['body']['amount'] === $amount, 'fetch.body.amount bate');
expect($fetch['body']['debtor']['name'] === 'Cliente Teste', 'fetch.body.debtor.name bate');
expect($fetch['body']['boleto']['bankLine'] === $bankLine, 'fetch.body.boleto.bankLine bate');
expect($fetch['body']['boleto']['barCode'] === $barCode, 'fetch.body.boleto.barCode bate');
expect($fetch['body']['boleto']['status'] === 'Registrado', 'fetch.body.boleto.status=Registrado para PENDING');

# 3) Fetch por ExternalId
$fetchExt = Cslabs::chargeFetchResponse(null, 'EXT-CHARGE-001');
expect($fetchExt['status'] === 'SUCCESS', 'fetch por externalId SUCCESS');
expect($fetchExt['body']['transactionId'] === $txid, 'externalId resolve no txid correto');

# 4) Fetch inexistente
$miss = Cslabs::chargeFetchResponse('nao-existe', null);
expect($miss['status'] === 'ERROR', 'fetch miss → ERROR');
expect($miss['error']['errorCode'] === 'CIE999', 'fetch miss → CIE999');

# 5) Billpayment authorize encontra boleto próprio (por linha digitável)
$auth = Cslabs::billPaymentAuthorizeResponse([
    'barCode' => ['digitable' => $bankLine, 'type' => 2],
]);
expect($auth['transactionId'] === $txid, 'authorize por bankLine identifica o boleto próprio (txid bate)');
expect($auth['value'] === $amount, 'authorize devolve amount real do boleto próprio');
expect($auth['assignor'] === 'CELCOIN INSTITUIÇÃO DE PAGAMENTO - SA', 'authorize cessionário=Celcoin');
expect(isset($auth['registerData']['payer']['name']) && $auth['registerData']['payer']['name'] === 'Cliente Teste',
    'authorize.registerData.payer.name vem do debtor persistido');

# 6) Billpayment authorize com bankLine bruta (só dígitos) também bate
$auth2 = Cslabs::billPaymentAuthorizeResponse([
    'barCode' => ['digitable' => $bankLineDigits, 'type' => 2],
]);
expect($auth2['transactionId'] === $txid, 'authorize por bankLine só-dígitos bate');

# 7) Billpayment authorize com barCode 44 dígitos é RECUSADO com 822
# Invertido em 13/08/2026. Antes esta linha afirmava que o 44 "também encontra" —
# era a leniência que fazia a homologação dar verde no incidente da confiapay, onde
# o IB mandou o código de barras e a Celcoin real devolveu HTTP 400 / 822. O 44 é
# recusado mesmo sendo o barcode do NOSSO próprio boleto: neste endpoint a Celcoin
# quer a linha digitável, e o mock só vale enquanto for fiel a isso.
$auth3 = Cslabs::billPaymentAuthorizeResponse([
    'barCode' => ['digitable' => $barCode, 'type' => 2],
]);
expect(($auth3['errorCode'] ?? null) === '822', 'authorize por barCode 44d é recusado com 822, como na Celcoin real');
expect(!isset($auth3['transactionId']), 'recusa 822 não devolve transactionId');

# 8) Billpayment authorize com linha desconhecida (boleto de terceiro) usa fallback sintético
# A linha é a do BANCO PACTUAL medida em produção (`mocks-v2`), que a Celcoin aceitou
# com errorCode 000 — precisa ser uma linha VÁLIDA, senão o que se testa aqui vira o 822.
$auth4 = Cslabs::billPaymentAuthorizeResponse([
    'barCode' => ['digitable' => '20890050091000000274083050530506314930000143106', 'type' => 2],
]);
expect($auth4['transactionId'] !== $txid, 'boleto desconhecido NÃO devolve nosso txid');
expect(isset($auth4['transactionId']) && $auth4['transactionId'] > 0, 'boleto desconhecido devolve txid sintético');
expect($auth4['assignor'] !== 'CELCOIN INSTITUIÇÃO DE PAGAMENTO - SA', 'boleto desconhecido vem com assignor sintético (não Celcoin)');

# 9) Após cancelamento, fetch reflete status CANCELLED
$cancelResp = Cslabs::chargeCancelResponse($txid, ['reason' => 'Teste']);
# Real: cancelar devolve PROCESSING + a cobrança inteira, em 1.1.0 (o CANCELED vem por webhook).
expect($cancelResp['status'] === 'PROCESSING', 'cancel PROCESSING (shape real)');
expect($cancelResp['version'] === '1.1.0', 'cancel version 1.1.0');
expect(($cancelResp['body']['transactionId'] ?? '') === $txid, 'cancel body = cobrança inteira (transactionId)');
expect(array_key_exists('chargeType', $cancelResp['body']), 'cancel body traz chargeType (body de cobrança, não recibo)');
$updated = Cslabs::readEntity('charges', $txid);
$updated['status'] = 'CANCELLED';
Cslabs::writeEntity('charges', $txid, $updated);
$fetchAfter = Cslabs::chargeFetchResponse($txid, null);
expect($fetchAfter['body']['status'] === 'CANCELLED', 'fetch pós-cancel: status=CANCELLED');
expect($fetchAfter['body']['boleto']['status'] === 'Cancelado', 'fetch pós-cancel: boleto.status=Cancelado');

echo "\nALL OK\n";
