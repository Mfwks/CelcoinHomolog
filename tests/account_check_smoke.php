<?php

# Smoke test: shape do response do account/check.

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

$_SERVER['REQUEST_URI'] = '/smoke/check';
$_SERVER['REQUEST_METHOD'] = 'GET';
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

# Simula um onboarding PF persistido pelo stream
$obId = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
$onboardingId = $obId; // alias preservado abaixo
$clientCode = '0000460';
$body = [
    'clientCode' => $clientCode,
    'documentNumber' => '69605971887',
    'socialName' => 'Marlene dos Santos Silvestre',
    'fullName' => 'Marlene dos Santos Silvestre',
];
$account = Cslabs::generateAccountNumber($onboardingId . '|' . $body['documentNumber']);
$record = [
    'onboardingId' => $onboardingId,
    'clientCode' => $clientCode,
    'documentNumber' => $body['documentNumber'],
    'kind' => 'natural-person',
    'status' => 'PROCESSING',
    'account' => ['account' => $account, 'branch' => '0001'],
    'request' => $body,
    'created_at' => date(DATE_ATOM, time() - 10),
];
Cslabs::writeEntity('onboardings', $onboardingId, $record);
Cslabs::writeEntity('onboardings_by_client_code', $clientCode, ['onboardingId' => $onboardingId]);

# Lookup by clientCode
$_GET = ['clientCode' => $clientCode];
ob_start();
include __DIR__ . '/../app/streams/api/account-status.php';
$json = ob_get_clean();
$out = json_decode($json, true);

expect(is_array($out), 'response decodifica');
expect($out['version'] === '1.0.0', 'version=1.0.0');
expect($out['status'] === 'CONFIRMED', 'status PROCESSING -> CONFIRMED após 3s');
expect(isset($out['body']['onboardingId']), 'body.onboardingId presente');
expect($out['body']['onboardingId'] === $obId, 'onboardingId bate');
expect($out['body']['clientCode'] === $clientCode, 'clientCode bate');
expect($out['body']['entity'] === 'account-create', 'entity=account-create (≠ webhook)');
expect(isset($out['body']['createDate']), 'createDate presente');
expect(isset($out['body']['account']), 'body.account presente');
expect($out['body']['account']['branch'] === '0001', 'account.branch=0001');
expect($out['body']['account']['account'] === $account, 'account.account bate com persistido');
expect($out['body']['account']['name'] === 'Marlene dos Santos Silvestre', 'account.name vem do socialName');
expect($out['body']['account']['documentNumber'] === '69605971887', 'account.documentNumber bate');

# Caso 2: lookup por onboardingId direto
$_GET = ['onboardingId' => $obId];
$expectedAccount = $account;
ob_start();
include __DIR__ . '/../app/streams/api/account-status.php';
$out2json = ob_get_clean();
$out2 = json_decode($out2json, true);
expect($out2['body']['account']['account'] === $expectedAccount && $out2['body']['onboardingId'] === $obId,
    'lookup por onboardingId mantém account (got ' . ($out2['body']['account']['account'] ?? 'null') . ', expected ' . $expectedAccount . ')');

# Caso 3: PJ — name vem de businessName
$pjId = 'pj-001';
Cslabs::writeEntity('onboardings', $pjId, [
    'onboardingId' => $pjId,
    'clientCode' => 'PJ-001',
    'documentNumber' => '00000000000191',
    'kind' => 'business',
    'status' => 'CONFIRMED',
    'account' => ['account' => '987654321', 'branch' => '0001'],
    'request' => ['businessName' => 'Empresa Demo LTDA'],
    'created_at' => date(DATE_ATOM),
]);
$_GET = ['onboardingId' => $pjId];
ob_start();
include __DIR__ . '/../app/streams/api/account-status.php';
$pjOut = json_decode(ob_get_clean(), true);
expect($pjOut['body']['account']['name'] === 'Empresa Demo LTDA', 'PJ: name vem de businessName');
expect($pjOut['body']['account']['account'] === '987654321', 'PJ: account preservada');

# Caso 4: lookup sem hit cria registro sintético com account e branch
$_GET = ['onboardingId' => 'nao-existe-123'];
ob_start();
include __DIR__ . '/../app/streams/api/account-status.php';
$synth = json_decode(ob_get_clean(), true);
expect($synth['status'] === 'CONFIRMED', 'lookup vazio: status CONFIRMED');
expect(!empty($synth['body']['account']['account']), 'lookup vazio: account sintetizado');
expect($synth['body']['account']['branch'] === '0001', 'lookup vazio: branch presente');

echo "\nALL OK\n";
