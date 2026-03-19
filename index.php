<?php

# Index Dock Mock

$in = explode('/',$_SERVER['REQUEST_URI'], 4);

$call = end($in);

if (!$call) {
    header('Content-Type: application/json');
    exit('{"error": "Nenhum endpoint informado"}');
}

include __DIR__ . '/functions.php';

$content = file_get_contents('php://input');
$data = json_decode($content, 1);

$fn = [
    'pix/v1/dict/v2/key' => 'consultarChaveAntigo',
    'enviar-pix' => 'enviarPix',
    'baas-wallet-transactions-webservice/v1/pix/payment' => 'enviarPix'
];

$function = $fn[$call] ?? false;

if (!$function) {
    if (preg_match('#^celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/([^/?]+)\?key=([^&]+)$#', $call, $matches)) {
        $function = 'consultarChave';
        $data['key'] = $matches[1] ?? null;
        $data['account'] = $matches[2] ?? null;
    }
}

if ($function==false || !function_exists(($function))) {
    header('Content-Type: application/json');
    http_response_code(404);	
    $log = '# ' . $call . "\n\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";
    file_put_contents(__DIR__ . '/logs/' . date('YmdHi') . '.log', $log, FILE_APPEND);
    exit('{"error": "Função inexistente para ' . $call. '"}');
}

$response = call_user_func($function,$data);

$log = '# ' . $call . "\n\n" . "function: $function\n\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n\n" . $response . "\n\n";

file_put_contents(__DIR__ . '/logs/' . date('YmdHi') . '.log', $log, FILE_APPEND);

header('Content-Type: application/json');
exit($response);
