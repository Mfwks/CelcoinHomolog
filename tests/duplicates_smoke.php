<?php

# Smoke test: cenários de duplicidade no onboarding e DICT.

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

require __DIR__ . '/../app/functions.php';

$_SERVER['REQUEST_URI'] = '/smoke/dup';
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

# ---- Onboarding PF: simular write + check de duplicata ----

# Primeira conta PF (popula índices manualmente como o stream faz)
$pf1 = [
    'clientCode' => '0000001234',
    'documentNumber' => '06170097914',
    'phoneNumber' => '+5511999999999',
    'email' => 'fulano@example.com',
    'motherName' => 'MARIA',
    'fullName' => 'FULANO',
    'birthDate' => '01-01-1990',
    'address' => [
        'postalCode' => '01001000', 'street' => 'Rua', 'number' => '1',
        'neighborhood' => 'Centro', 'city' => 'SP', 'state' => 'SP',
    ],
];

Db::transaction(function () use ($pf1) {
    expect(Cslabs::onboardingDuplicateError($pf1, 'natural-person') === null, 'PF1: nenhum duplicado antes do write');
    Cslabs::writeEntity('onboardings', 'ob1', ['onboardingId' => 'ob1']);
    Cslabs::writeEntity('onboardings_by_client_code', $pf1['clientCode'], ['onboardingId' => 'ob1']);
    Cslabs::writeEntity('onboardings_by_document', $pf1['documentNumber'], ['onboardingId' => 'ob1']);
    Cslabs::writeEntity('onboardings_by_email', Cslabs::onboardingEmailKey($pf1, 'natural-person'), ['onboardingId' => 'ob1']);
    Cslabs::writeEntity('onboardings_by_phone', Cslabs::onboardingPhoneKey($pf1, 'natural-person'), ['onboardingId' => 'ob1']);
});

# Tentar mesmo CPF
$dup = Cslabs::onboardingDuplicateError($pf1, 'natural-person');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE022', 'PF: CPF duplicado retorna CBE022');

# Tentar mesmo email, CPF diferente
$pf2 = array_merge($pf1, ['documentNumber' => '12345678901', 'clientCode' => 'novo1', 'phoneNumber' => '+5511888888888']);
$dup = Cslabs::onboardingDuplicateError($pf2, 'natural-person');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE023', 'PF: email duplicado retorna CBE023');

# Tentar mesmo telefone, tudo o resto diferente
$pf3 = array_merge($pf1, [
    'documentNumber' => '22222222222', 'clientCode' => 'novo2',
    'email' => 'outro@example.com',
]);
$dup = Cslabs::onboardingDuplicateError($pf3, 'natural-person');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE024', 'PF: telefone duplicado retorna CBE024');

# Tentar mesmo clientCode, tudo o resto diferente
$pf4 = array_merge($pf1, [
    'documentNumber' => '33333333333',
    'phoneNumber' => '+5511777777777',
    'email' => 'outroemail@example.com',
]);
$dup = Cslabs::onboardingDuplicateError($pf4, 'natural-person');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE007', 'PF: clientCode duplicado retorna CBE007');

# Tudo novo → sem duplicata
$pf5 = [
    'clientCode' => 'fresh1', 'documentNumber' => '44444444444',
    'phoneNumber' => '+5511666666666', 'email' => 'novo@example.com',
    'motherName' => 'MARIA', 'fullName' => 'NOVO', 'birthDate' => '01-01-1990',
    'address' => $pf1['address'],
];
expect(Cslabs::onboardingDuplicateError($pf5, 'natural-person') === null, 'PF: registro distinto não duplica');

# Normalização: email maiúsculo / espaços deve bater com o existente
$pf_norm = array_merge($pf5, ['email' => '  FULANO@EXAMPLE.COM  ']);
$dup = Cslabs::onboardingDuplicateError($pf_norm, 'natural-person');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE023', 'PF: email é normalizado (case+trim)');

# Normalização: telefone com máscara deve bater (só dígitos)
$pf_phone = array_merge($pf5, ['phoneNumber' => '+55 (11) 99999-9999']);
$dup = Cslabs::onboardingDuplicateError($pf_phone, 'natural-person');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE024', 'PF: telefone é normalizado (só dígitos)');

# ---- Onboarding PJ ----

$pj1 = [
    'clientCode' => 'PJ-0001', 'documentNumber' => '00000000000191',
    'contactNumber' => '+5511444444444', 'businessEmail' => 'empresa@x.com',
    'businessName' => 'Empresa LTDA',
    'owner' => [['documentNumber' => '11111111111']],
    'businessAddress' => $pf1['address'],
];
Db::transaction(function () use ($pj1) {
    Cslabs::writeEntity('onboardings_by_client_code', $pj1['clientCode'], ['onboardingId' => 'pj1']);
    Cslabs::writeEntity('onboardings_by_document', $pj1['documentNumber'], ['onboardingId' => 'pj1']);
    Cslabs::writeEntity('onboardings_by_email', Cslabs::onboardingEmailKey($pj1, 'business'), ['onboardingId' => 'pj1']);
    Cslabs::writeEntity('onboardings_by_phone', Cslabs::onboardingPhoneKey($pj1, 'business'), ['onboardingId' => 'pj1']);
});

$dup = Cslabs::onboardingDuplicateError($pj1, 'business');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE025', 'PJ: CNPJ duplicado retorna CBE025');

$pj2 = array_merge($pj1, ['documentNumber' => '99999999999191', 'clientCode' => 'PJ-NEW']);
$dup = Cslabs::onboardingDuplicateError($pj2, 'business');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE023', 'PJ: businessEmail duplicado retorna CBE023');

$pj3 = array_merge($pj1, [
    'documentNumber' => '99999999999191', 'clientCode' => 'PJ-NEW2',
    'businessEmail' => 'novo@x.com',
]);
$dup = Cslabs::onboardingDuplicateError($pj3, 'business');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE024', 'PJ: contactNumber duplicado retorna CBE024');

# ---- Bulk: duplicidade dentro do mesmo batch ----

$batch = [
    array_merge($pf5, ['clientCode' => 'B-1', 'documentNumber' => '55555555555',
        'phoneNumber' => '+5511555555555', 'email' => 'a@b.com']),
    array_merge($pf5, ['clientCode' => 'B-2', 'documentNumber' => '55555555555', // doc duplicado intra-batch
        'phoneNumber' => '+5511555555556', 'email' => 'b@b.com']),
    array_merge($pf5, ['clientCode' => 'B-1', 'documentNumber' => '66666666666', // clientCode duplicado intra-batch
        'phoneNumber' => '+5511555555557', 'email' => 'c@b.com']),
    array_merge($pf5, ['clientCode' => 'B-3', 'documentNumber' => '77777777777',
        'phoneNumber' => '+5511555555555', 'email' => 'd@b.com']), // phone dup intra-batch
];
$resp = Cslabs::onboardingBulkResponse($batch, 'natural-person');
$items = $resp['body']['items'];
expect($items[0]['status'] === 'PROCESSING', 'bulk: item 0 aceito');
expect($items[1]['status'] === 'ERROR' && $items[1]['error']['errorCode'] === 'CBE022', 'bulk: doc duplicado intra-batch → CBE022');
expect($items[2]['status'] === 'ERROR' && $items[2]['error']['errorCode'] === 'CBE007', 'bulk: clientCode duplicado intra-batch → CBE007');
expect($items[3]['status'] === 'ERROR' && $items[3]['error']['errorCode'] === 'CBE024', 'bulk: phone duplicado intra-batch → CBE024');
expect($resp['status'] === 'PARTIAL', 'bulk: status PARTIAL quando há mistura');

# ---- Bulk: duplicidade contra DB (registro já existente) ----

$batch2 = [
    array_merge($pf5, ['clientCode' => 'POST-1', 'documentNumber' => '06170097914', // CPF do PF1 já no DB
        'phoneNumber' => '+5511500000001', 'email' => 'distinto1@x.com']),
];
$resp2 = Cslabs::onboardingBulkResponse($batch2, 'natural-person');
expect($resp2['body']['items'][0]['status'] === 'ERROR' && $resp2['body']['items'][0]['error']['errorCode'] === 'CBE022',
    'bulk: doc duplicado contra DB → CBE022');

# ---- DICT: chave PIX duplicada ----

Cslabs::writeEntity('pix_dict_entries', 'chave@x.com', [
    'key' => 'chave@x.com',
    'keyType' => 'EMAIL',
    'account' => ['account' => '443168489'],
]);

$dup = Cslabs::pixDictDuplicateError('chave@x.com', '443168490');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE189'
    && str_contains($dup['error']['message'], 'outro usuário'),
    'DICT: chave já em uso por outra conta → CBE189');

$dup = Cslabs::pixDictDuplicateError('chave@x.com', '443168489');
expect(is_array($dup) && $dup['error']['errorCode'] === 'CBE189'
    && str_contains($dup['error']['message'], 'esta conta'),
    'DICT: chave já cadastrada para a mesma conta → CBE189 (mensagem específica)');

expect(Cslabs::pixDictDuplicateError('outra@y.com', '443168489') === null, 'DICT: chave nova não duplica');

echo "\nALL OK\n";
