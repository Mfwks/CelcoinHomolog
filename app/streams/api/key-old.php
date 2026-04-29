<?php

include_once __DIR__ . '/api-stream.php';

$content = file_get_contents('php://input');
$inputs = json_decode($content);

$key = $inputs->key ?? 'ok@pix.com';
$payerId = $inputs->payerId ?? '06170097914';

$type = strstr($key,'@',true);

if ($type=='erro' || $type=='error') {
    $data['code'] = 'NNN';
    $data['description'] = 'QUALQUER OUTRO ERRO (API antiga).';
    $response = json_encode($data);
    header('Content-Type: application/json');
    echo $response;
    return;
}

if ($type=='fraude' || $type=='fraud') {
    $data['code'] = '422';
    $data['description'] = 'CHAVE PIX COM DADOS RESTRITOS POR MARCAÇÃO DE FRAUDE (API antiga).';
    $response = json_encode($data);
    header('Content-Type: application/json');
    echo $response;
    return;
}

$data['endtoendid'] = 'endtoendid';
$data['account']['accountNumber'] = '127200';
$data['owner']['taxIdNumber'] = $payerId;
$data['code'] = '200';

$data['owner']['name'] = 'Daniel Eskelsen';
$data['account']['participant'] = '487';
$data['account']['branch'] = '0001';
$data['account']['accountType'] = 'N';
$data['key'] = $key;
$data['keyType'] = 'email';

$data['description'] = 'CONSULTA COM SUCESSO (API antiga).';

header('Content-Type: application/json');
echo json_encode($data, JSON_PRETTY_PRINT);
