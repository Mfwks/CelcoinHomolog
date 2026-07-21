<?php

# GET /pixqrcode/novo — cria um QR dinâmico de exemplo e abre a página dele.
#
# Ferramenta de teste do mock, não existe na Celcoin. Serve para gerar um QR
# para pagar sem montar request na mão: abre no navegador e pronto.
#
#   /pixqrcode/novo                  -> R$ 25,90
#   /pixqrcode/novo?valor=10         -> R$ 10,00
#   /pixqrcode/novo?valor=1,50       -> R$ 1,50  (aceita vírgula)
#   /pixqrcode/novo?chave=...&nome=...&cidade=...&expiracao=3600

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$valorBruto = trim((string) ($_GET['valor'] ?? '25.90'));
// Aceita "1.234,56" e "1234.56" — quem digita na URL usa o que estiver à mão.
$valor = (float) str_replace(',', '.', str_replace('.', '', $valorBruto));
if ($valor <= 0) {
    $valor = 25.90;
}

$payload = [
    'key' => (string) ($_GET['chave'] ?? 'cab8f3ed-18ae-4cb1-a5a1-1d525a4e86fd'),
    'amount' => number_format($valor, 2, '.', ''),
    'expiration' => max(60, (int) ($_GET['expiracao'] ?? 3600)),
    'clientRequestId' => 'exemplo-' . bin2hex(random_bytes(8)),
    'merchant' => [
        'merchantCategoryCode' => '0000',
        'city' => (string) ($_GET['cidade'] ?? 'SAO PAULO'),
        'name' => (string) ($_GET['nome'] ?? 'CSLABS MOCK'),
    ],
];

$response = Cslabs::brcodeDynamicCreateResponse($payload);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

// Mesma persistência do stream oficial — é o que faz a location resolver e o
// pagamento achar a cobrança pelo txid.
$location = (string) ($response['body']['body']['location'] ?? '');
$locationId = basename($location);
$txid = (string) ($response['body']['transactionIdentification'] ?? '');

Cslabs::writeEntity('brcode_dynamic', (string) $response['body']['transactionId'], $response['body']);
Cslabs::writeEntity('brcode_dynamic_by_location', $locationId, $response['body']);
if ($txid !== '') {
    Cslabs::writeEntity('brcode_dynamic_by_txid', $txid, [
        'locationId' => $locationId,
        'transactionId' => $response['body']['transactionId'] ?? null,
    ]);
}

// JSON para quem chamou por API; página para quem abriu no navegador.
if (str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
    header('Content-Type: application/json');
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

/*
 * Redirect derivado do caminho da própria request, e não de url(): url() usa a
 * constante SITE (domínio fixo de produção), o que jogaria quem testa local
 * para o servidor de verdade. Assim funciona igual servido em /celcoin/ ou na
 * raiz. O rtrim cobre a variante com barra no fim, que o router também aceita.
 */
$caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '/';
$prefixo = rtrim(dirname(rtrim($caminho, '/')), '/');

header('Location: ' . $prefixo . '/v2/' . $locationId . '/ver', true, 302);
