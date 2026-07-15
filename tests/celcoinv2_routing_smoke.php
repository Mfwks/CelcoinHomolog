<?php

# Smoke test: roteamento da superfície V2 (baas/v2/*) em app/web.php.
# Valida que cada URL+método do consumidor modules/celcoinv2 resolve para o
# stream esperado — foco na desambiguação por ordem (Web::search ignora método,
# {param} casa qualquer segmento). Não bate no HTTP: só exercita Web::init.

defined('BASE') or define('BASE', '/');

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

use App\Core\Web;

$web = new Web();
require __DIR__ . '/../app/web.php'; // popula $web->routes via $web->add(...)

function resolve(Web $web, string $uri, string $method): string
{
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = $method;
    $r = $web->init(BASE);
    return $r ? $web->stream : '(nenhuma rota)';
}

$fails = 0;
function check(Web $web, string $method, string $uri, string $expected): void
{
    global $fails;
    $got = resolve($web, $uri, $method);
    if ($got === $expected) {
        echo "ok: $method $uri -> $got\n";
    } else {
        echo "FAIL: $method $uri -> esperado '$expected', obtido '$got'\n";
        $fails++;
    }
}

# Conta / carteira
check($web, 'PUT',    '/baas/v2/account/status',                        'api/account-status-update');
check($web, 'DELETE', '/baas/v2/account/close',                         'api/account-close');
check($web, 'GET',    '/baas/v2/wallet/balance',                        'api/wallet-balance');
check($web, 'POST',   '/baas/v2/wallet/entry/494635683',                'api/wallet-entry');
check($web, 'POST',   '/baas/v2/wallet/internal/transfer',              'api/internal-transfer');
check($web, 'GET',    '/baas/v2/wallet/internal/transfer/status',       'api/internal-transfer-status');

# Pix devolução + EMV
check($web, 'POST',   '/baas/v2/pix/reverse',                           'api/pix-reverse-baas');
check($web, 'POST',   '/pix/v1/emv/full',                               'api/emv');
check($web, 'POST',   '/pix/v1/emv',                                    'api/emv'); // v1 preservado

# DICT entry — dispatcher e create; external NÃO pode ser sombreado pelo {key}
check($web, 'POST',   '/baas/v2/pix/dict/entry',                        'api/dict-entry-create');
check($web, 'GET',    '/baas/v2/pix/dict/entry/494635683',              'api/dict-entry-v2');       // listar
check($web, 'DELETE', '/baas/v2/pix/dict/entry/03be238f-5496-4c61',     'api/dict-entry-v2');       // excluir
check($web, 'POST',   '/baas/v2/pix/dict/entry/otp',                    'api/dict-entry-v2');       // otp
check($web, 'POST',   '/baas/v2/pix/dict/entry/confirm',                'api/dict-entry-v2');       // confirm
check($web, 'GET',    '/baas/v2/pix/dict/entry/external/494635683',     'api/key');                 // consultar (3 seg)

# DICT claims — literais antes do {id}
check($web, 'POST',   '/baas/v2/pix/dict/claim',                        'api/dict-claim');
check($web, 'GET',    '/baas/v2/pix/dict/claim/list',                   'api/dict-claim-list');
check($web, 'POST',   '/baas/v2/pix/dict/claim/confirm',                'api/dict-claim');
check($web, 'POST',   '/baas/v2/pix/dict/claim/cancel',                 'api/dict-claim');
check($web, 'GET',    '/baas/v2/pix/dict/claim/abc123def',              'api/dict-claim-router');

# Regressão: rotas V2 já existentes seguem intactas
check($web, 'POST',   '/baas/v2/pix/payment',                           'api/payment-baas');
check($web, 'GET',    '/baas/v2/pix/payment/status',                    'api/pix-payment-status-baas');
check($web, 'GET',    '/baas/v2/pix/dict/entry/external/999',           'api/key');
check($web, 'GET',    '/baas/v2/charge',                                'api/charge-fetch');
check($web, 'DELETE', '/baas/v2/charge/6fa5026f',                       'api/charge-cancel');
check($web, 'GET',    '/baas/v2/wallet/movement',                       'api/wallet-movement');

# Produtos novos (Commit B)
check($web, 'GET',    '/baas/v2/wallet/dayBalance',                      'api/wallet-day-balance');
check($web, 'GET',    '/baas/v2/charge/pdf/6fa5026f',                    'api/charge-pdf');          // 3 seg
check($web, 'DELETE', '/baas/v2/charge/6fa5026f',                       'api/charge-cancel');       // pdf não sombreia cancel
check($web, 'GET',    '/pix/v2/receivement/v2/status',                  'api/receivement-status');
check($web, 'GET',    '/baas/v2/webhook/replay/onboarding-create',      'api/webhook-replay');
check($web, 'GET',    '/baas/v2/webhook/replay/onboarding-create/details','api/webhook-replay');
check($web, 'PUT',    '/baas/v2/webhook/replay/onboarding-create',      'api/webhook-replay');
check($web, 'GET',    '/v5/transactions/topups/providers',              'api/topups');
check($web, 'GET',    '/v5/transactions/topups/provider-values',        'api/topups');
check($web, 'GET',    '/v5/transactions/topups/status-consult',         'api/topups');
check($web, 'PUT',    '/v5/transactions/topups/5332764900/capture',     'api/topups');
check($web, 'POST',   '/v5/transactions/topups',                        'api/topups');

# Regressão: v5 e token não afetados
check($web, 'POST',   '/v5/token',                                      'api/token');
check($web, 'POST',   '/v5/transactions/billpayments/authorize',        'api/billpayment-authorize');

# Regressão: v1 (baas-accountmanager) não afetado pelos aliases baas/v2
check($web, 'PUT',    '/baas-accountmanager/v1/account/status',         'api/account-status-update');

if ($fails > 0) {
    echo "\ncelcoinv2 routing smoke: $fails FALHA(S)\n";
    exit(1);
}

echo "\ncelcoinv2 routing smoke: OK\n";
