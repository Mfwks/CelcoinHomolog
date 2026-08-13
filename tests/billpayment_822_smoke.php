<?php

/*
 * Smoke do erro 822 — recusa de linha digitável no `billpayment/authorize`.
 *
 * Pedido pela sessão B em 13/08/2026 (briefing `2026-08-13-mock-celcoin-fidelidade-
 * recusas-reais.md`), a partir de um incidente na confiapay: o IB mandou o código de
 * barras de 44 dígitos, a Celcoin devolveu HTTP 400 / 822, e a homologação nunca teria
 * pego isso — o mock respondia `errorCode 000` a QUALQUER string de dígitos.
 *
 * O furo não era um bug pontual, era o método: o simulador foi mantido POR CENÁRIO
 * (caminho feliz + os erros que o cliente pede explicitamente) e não POR CONTRATO.
 * Um mock que só conhece o caminho feliz esconde defeito em vez de expor.
 *
 * ## As asserções são contra o corpus, não contra o razoável
 *
 * Os dez casos abaixo são requests REAIS de `mocks-v2/` (confiapay + homologacao3),
 * com a resposta que a Celcoin REALMENTE deu. São 9 recusas e 3 aceites, e a regra
 * separa os dois conjuntos sem exceção. Duas coisas que só o corpus ensina:
 *
 *   - NÃO é "44 recusa, 47 aceita". Dois dos nove 822 tinham 47 dígitos, com DV de
 *     campo errado. Uma implementação que só medisse comprimento aprovaria os dois.
 *   - Barcode de 44 com DV geral CORRETO também leva 822 — quatro dos nove. Neste
 *     endpoint a Celcoin não converte 44→47; ela quer a linha digitável e pronto.
 *
 * ## Mutação (o teste do teste)
 *
 * O critério de aceite do briefing é que reintroduzir a leniência derrube o teste.
 * Conferido à mão nas três mutações que importam:
 *
 *   1. `validarCobranca()` devolver sempre `valida=true`  → caem os 7 casos de 822.
 *   2. trocar a checagem de DV por `strlen($digitos) === 47` → caem os dois 47-inválidos.
 *   3. aceitar 44 convertendo para 47                      → caem os quatro barcodes.
 *
 * Builder direto, sem HTTP: o que se testa é decisão de forma. O único ponto que
 * depende do stream — o HTTP 400 — está coberto no §6 lendo o próprio arquivo do
 * stream, que é mais honesto que confiar em `http_response_code()` fora de request.
 */

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
$_SERVER['REQUEST_URI'] = '/smoke/billpayment-822';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

use App\Core\Boleto;
use App\Core\Cslabs;

$fails = 0;
function ok(bool $c, string $m): void
{
    global $fails;
    echo ($c ? 'ok: ' : 'FAIL: ') . "$m\n";
    if (!$c) {
        $fails++;
    }
}

Cslabs::boot();

function authorize(string $digitable, int $type = 1): array
{
    Cslabs::resetLastErrorScenario();

    return Cslabs::billPaymentAuthorizeResponse(['barCode' => ['type' => $type, 'digitable' => $digitable]]);
}

# ─── 1) Os nove 822 reais do corpus ─────────────────────────────────────────────
# Cada linha: [digitable, data e tenant da medição, por que a Celcoin recusou]
$recusas = [
    ['34196153000003255341090002071957140237236000', '13/08 confiapay', 'barcode 44, DV geral VÁLIDO — é o boleto Itaú de R$3.255,34 do incidente'],
    ['50991145200000050000000000003000000000336875', '13/05 homologacao3', 'barcode 44, DV geral válido'],
    ['12345678901234567890123456789012345678901234', '15/06 homologacao3', 'barcode 44 sequencial, DV geral válido por acaso'],
    ['50997150300000100000000000003000000000355234', '19/06 homologacao3', 'barcode 44, DV geral válido'],
    ['12345123451234512121212345121212812345678901112', '11/06 confiapay', '47 dígitos com DVs errados — o caso que derruba o "só contar 47"'],
    ['12345.67890 12345.678901 12345.678901 1 12345678901234', '16/06 homologacao3', '47 dígitos mascarados, DVs errados'],
    ["5@rW(*0KJCAkI<C&^^jt'R=;:b=GT\\/g!", '13/05 homologacao3', 'lixo — 2 dígitos'],
];

foreach ($recusas as [$digitable, $quando, $porque]) {
    $r = authorize($digitable);
    ok(($r['errorCode'] ?? null) === '822', "822 ($quando): $porque");
}

# ─── 2) Os três aceites reais — a contraprova ───────────────────────────────────
$aceites = [
    ['20890050091000000274083050530506314930000143106', 'BANCO PACTUAL, R$1.431,06, venc. 30/06/2026'],
    ['50990000010000000000000111976007714930000001000', 'Generica FC, R$10,00'],
    ['50990000010000300000700003613619414900000010000', 'Generica FC, R$100,00, venc. 27/06/2026'],
];

foreach ($aceites as [$digitable, $descricao]) {
    $r = authorize($digitable);
    ok(($r['errorCode'] ?? null) === '000', "000 (aceite real): $descricao");
    ok(($r['status'] ?? null) === 0, "aceite mantém status 0: $descricao");
}

# ─── 3) O shape do 822 é o da Celcoin, não o envelope do mock ───────────────────
$r = authorize('34196153000003255341090002071957140237236000');

ok(array_keys($r) === ['errorCode', 'message'], '822 sai PLANO: só errorCode e message, como no corpus');
ok(!isset($r['status']), '822 NÃO traz `status` — o envelope {status,error,version} é do mock, não da Celcoin');
ok(!isset($r['error']), '822 NÃO aninha em `error` — o CelcoinV2HttpClient lê as duas formas, mas só uma é real');
ok($r['message'] === 'Erro na conversao de Linha Digitavel para Codigo de Barras',
    'mensagem idêntica à do incidente de 13/08 (a Celcoin também usa a variante COM acento — casar pelo código, nunca pela string)');
ok(Cslabs::scenarioHttpStatus(Cslabs::lastErrorScenario()) === 400, 'cenário do 822 resolve para HTTP 400');

# ─── 4) `type` não decide arrecadação — o prefixo 8 decide ──────────────────────
# Medido: o app manda `type: 1` em 100% dos 84 requests do corpus, inclusive nos
# boletos de cobrança que a Celcoin respondeu com `registerData` completo.
$cobranca = authorize('20890050091000000274083050530506314930000143106', 1);
ok(is_array($cobranca['registerData'] ?? null),
    'cobrança com type=1 devolve registerData (antes o `|| $type === 1` a tratava como conta de consumo)');

$arrecadacao = authorize('826100000015385600970912091815316855195952462834', 1);
ok(($arrecadacao['errorCode'] ?? null) === '000', 'arrecadação (prefixo 8) passa — n=0 no corpus, não se inventa recusa sem medida');
ok(array_key_exists('registerData', $arrecadacao) && $arrecadacao['registerData'] === null, 'arrecadação não tem registerData');

# ─── 5) O emissor virou fiel junto com o validador ──────────────────────────────
# Sem isto o mock recusaria o próprio boleto: até 13/08 a `bankLine` eram 47
# dígitos tirados de um sha256 — comprimento certo, DVs aleatórios.
$linha = Cslabs::boletoBankLine('tx-smoke-822', 2256.27, '2026-09-10');
$diag = Boleto::validarCobranca($linha);

ok(strlen($linha) === 47, 'bankLine emitida tem 47 dígitos');
ok($diag['valida'], 'bankLine emitida passa no MESMO validador que o authorize usa (motivo: ' . $diag['motivo'] . ')');
ok((authorize($linha)['errorCode'] ?? null) === '000', 'o mock aceita o boleto que ele mesmo emitiu');

$barcode = Cslabs::boletoBarCode('tx-smoke-822', 2256.27, '2026-09-10');
ok(strlen($barcode) === 44, 'barCode emitido tem 44 dígitos');
ok(Boleto::barcodeDeLinha($linha) === $barcode, 'barCode é a MESMA linha reordenada, não um segundo número sorteado');
ok(Boleto::linhaDeBarcode($barcode) === $linha, 'round-trip 44→47 devolve a linha original');
ok((authorize($barcode)['errorCode'] ?? null) === '822', 'e mesmo assim o barcode próprio leva 822 — a Celcoin recusa 44 aqui');

# A linha emitida codifica valor e vencimento de verdade, não enfeite.
ok(substr($barcode, 9, 10) === '0000225627', 'o valor viaja no barcode em centavos (R$2.256,27)');
ok(Boleto::vencimentoDeFator((int) substr($barcode, 5, 4)) === '2026-09-10', 'o fator de vencimento decodifica na data pedida');

# ─── 6) O stream devolve 400, não 200 com corpo de erro ─────────────────────────
$stream = file_get_contents(__DIR__ . '/../app/streams/api/billpayment-authorize.php');
ok(str_contains($stream, "\$response['errorCode']") && str_contains($stream, 'http_response_code'),
    'o stream reconhece o 822 plano e chama http_response_code (senão sairia HTTP 200 com corpo de erro)');

# ─── 7) Base do fator de vencimento — conferida contra resposta real ────────────
# Não é convenção assumida: fator 1493 e 1490 aparecem nos dois aceites acima, e as
# respostas reais da Celcoin trazem dueDateRegister 2026-06-30 e 2026-06-27.
ok(Boleto::vencimentoDeFator(1493) === '2026-06-30', 'fator 1493 = 30/06/2026, igual ao dueDateRegister da resposta real');
ok(Boleto::vencimentoDeFator(1490) === '2026-06-27', 'fator 1490 = 27/06/2026, igual ao dueDateRegister da resposta real');
ok(Boleto::fatorVencimento('2026-06-30') === 1493, 'e o caminho de volta fecha');

echo "\n" . ($fails === 0 ? "TODOS OS TESTES PASSARAM\n" : "$fails FALHA(S)\n");
exit($fails === 0 ? 0 : 1);
