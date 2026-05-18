<?php

# Smoke test isolado: usa diretamente App\Core\Cslabs/Db sem boot HTTP.
# Cria conta, lê, atualiza, lista, verifica unicidade no SQLite.

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

# Helpers do framework usados por Cslabs (gerarHashMock etc.)
require __DIR__ . '/../app/functions.php';

# Simula contexto HTTP mínimo
$_SERVER['REQUEST_URI'] = '/smoke/test';
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

# Boot to fix client_id
Cslabs::boot();
$clientId = Cslabs::context()['client_id'];

# 1. Write + read básico
Cslabs::writeEntity('accounts', '12345', [
    'documentNumber' => '12345',
    'status' => 'CONFIRMED',
    'balance' => 1000.00,
    'entity' => 'account-12345',
]);

$read = Cslabs::readEntity('accounts', '12345');
expect(is_array($read), 'readEntity recupera registro recém-criado');
expect($read['status'] === 'CONFIRMED', 'status persistido');
expect($read['balance'] === 1000.00, 'balance preservado como float');

# 2. Upsert preserva created_at
$pdo = Db::pdo();
$row1 = $pdo->query("SELECT created_at, updated_at FROM entities WHERE type='accounts' AND id='12345'")->fetch();
sleep(1);
Cslabs::writeEntity('accounts', '12345', array_merge($read, ['status' => 'BLOCKED']));
$row2 = $pdo->query("SELECT created_at, updated_at FROM entities WHERE type='accounts' AND id='12345'")->fetch();
expect($row1['created_at'] === $row2['created_at'], 'upsert preserva created_at');
expect($row1['updated_at'] !== $row2['updated_at'], 'upsert atualiza updated_at');

# 3. listEntities ordena por entity_key
Cslabs::writeEntity('items', 'b', ['entity' => 'banana']);
Cslabs::writeEntity('items', 'a', ['entity' => 'apple']);
Cslabs::writeEntity('items', 'c', ['entity' => 'cherry']);
$items = Cslabs::listEntities('items');
expect(count($items) === 3, 'listEntities retorna 3 itens');
expect($items[0]['entity'] === 'apple', 'ordenação por entity_key (apple primeiro)');
expect($items[2]['entity'] === 'cherry', 'ordenação por entity_key (cherry último)');

# 4. Transação faz rollback em exceção
try {
    Db::transaction(function () {
        Cslabs::writeEntity('rollback_test', 'x', ['v' => 1]);
        throw new RuntimeException('boom');
    });
} catch (RuntimeException $e) {
    // expected
}
expect(Cslabs::readEntity('rollback_test', 'x') === false, 'transação faz rollback ao lançar');

# 5. Commit da transação persiste tudo
Db::transaction(function () {
    Cslabs::writeEntity('tx_test', 'a', ['v' => 1]);
    Cslabs::writeEntity('tx_test', 'b', ['v' => 2]);
});
expect(is_array(Cslabs::readEntity('tx_test', 'a')), 'commit persiste primeiro write');
expect(is_array(Cslabs::readEntity('tx_test', 'b')), 'commit persiste segundo write');

# 6. deleteEntity
Cslabs::writeEntity('to_delete', 'gone', ['x' => 1]);
expect(is_array(Cslabs::readEntity('to_delete', 'gone')), 'pré-delete existe');
expect(Cslabs::deleteEntity('to_delete', 'gone'), 'delete retorna true');
expect(Cslabs::readEntity('to_delete', 'gone') === false, 'pós-delete não existe');
expect(!Cslabs::deleteEntity('to_delete', 'gone'), 'delete em vazio retorna false');

# 7. Isolamento por client_id
Cslabs::writeEntity('iso', 'k', ['v' => 'A'], 'clientA');
Cslabs::writeEntity('iso', 'k', ['v' => 'B'], 'clientB');
$rA = Cslabs::readEntity('iso', 'k', 'clientA');
$rB = Cslabs::readEntity('iso', 'k', 'clientB');
expect($rA['v'] === 'A', 'clientA isolado');
expect($rB['v'] === 'B', 'clientB isolado');

# 8. Tabela e schema corretos
$schema = $pdo->query("SELECT sql FROM sqlite_master WHERE name='entities'")->fetchColumn();
expect(str_contains($schema, 'PRIMARY KEY'), 'PK composta presente no schema');

echo "\nALL OK · client_id=$clientId · db=$dbFile\n";
