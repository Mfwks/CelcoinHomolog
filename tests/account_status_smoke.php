<?php

# Smoke test: PUT /baas-accountmanager/v1/account/status (api/account-status-update).
# Foco no fluxo de reativação da Compra de Dívida: {"status":"BLOQUEADO"} -> {"status":"ATIVO"}.
# `reason` é OPCIONAL (a doc só exige no DELETE /account/close); cenários de erro
# controlados vêm por `scenario`/`mockScenario`, nunca por texto natural do reason.

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

$_SERVER['REQUEST_URI'] = '/smoke/account-status';
$_SERVER['REQUEST_METHOD'] = 'PUT';
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

# Conta que NÃO é KYC-pending pela heurística (último dígito % 4 != 0).
$acc = '300547189179';

# 1) Fluxo reativação com o payload exato do consumidor (sem reason).
$r = Cslabs::accountStatusScenario(['status' => 'BLOQUEADO'], $acc);
expect(($r['status'] ?? '') === 'SUCCESS', '{"status":"BLOQUEADO"} sem reason -> SUCCESS');

$r = Cslabs::accountStatusScenario(['status' => 'ATIVO'], $acc);
expect(($r['status'] ?? '') === 'SUCCESS', '{"status":"ATIVO"} sem reason -> SUCCESS');

# 2) Reason opcional presente (texto natural que contém "bloqueio") não dispara erro.
foreach (['desbloqueio da conta', 'reativação após bloqueio', 'compra de dívida', 'erro operacional'] as $reason) {
    $r = Cslabs::accountStatusScenario(['status' => 'ATIVO', 'reason' => $reason], $acc);
    expect(($r['status'] ?? '') === 'SUCCESS', "reason natural \"$reason\" -> SUCCESS");
}

# 3) Validação preservada: status ausente/ inválido -> CBE014.
$r = Cslabs::accountStatusScenario(['status' => 'FOO'], $acc);
expect(($r['error']['errorCode'] ?? '') === 'CBE014', 'status inválido -> CBE014');
$r = Cslabs::accountStatusScenario([], $acc);
expect(($r['error']['errorCode'] ?? '') === 'CBE014', 'status ausente -> CBE014');

# 4) Gatilhos de erro controlados via scenario/mockScenario (para testar rollback).
$r = Cslabs::accountStatusScenario(['status' => 'ATIVO', 'mockScenario' => 'error'], $acc);
expect(($r['error']['errorCode'] ?? '') === 'CBE015', 'mockScenario=error -> CBE015');
# Os gatilhos usam a convenção de palavra-chave (docs/scenarios.md §2), não o nome interno do cenário.
$r = Cslabs::accountStatusScenario(['status' => 'ATIVO', 'scenario' => 'inexistente'], $acc);
expect(($r['error']['errorCode'] ?? '') === 'CBE003', 'scenario=inexistente (not_found) -> CBE003');
$r = Cslabs::accountStatusScenario(['status' => 'ATIVO', 'scenario' => 'fraude'], $acc);
expect(($r['error']['errorCode'] ?? '') === 'CBE006', 'scenario=fraude -> CBE006');

# 5) Heurística KYC-pending por dígito da conta (último dígito % 4 == 0 -> CBE345).
$r = Cslabs::accountStatusScenario(['status' => 'ATIVO'], '300547189180');
expect(($r['error']['errorCode'] ?? '') === 'CBE345', 'conta terminada em 0 (KYC-pending) -> CBE345');
$r = Cslabs::accountStatusScenario(['status' => 'ATIVO'], '300547189195');
expect(($r['status'] ?? '') === 'SUCCESS', 'conta 300547189195 (não pendente) -> SUCCESS');

echo "\naccount/status smoke: OK\n";
