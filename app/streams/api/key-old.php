<?php

$key = $web->args->key ?? 'ok@pix.com';

if ($key=='erro@pix.com' || $key=='error@pix.com') {
    $data['code'] = 'NNN';
    $data['description'] = 'QUALQUER OUTRO ERRO (API antiga).';
    $response = json_encode($data);
    header('Content-Type: application/json');
    exit($response);
}

if ($key=='fraude@pix.com') {
    $data['code'] = '422';
    $data['description'] = 'CHAVE PIX COM DADOS RESTRITOS POR MARCAÇÃO DE FRAUDE (API antiga).';
    $response = json_encode($data);
    header('Content-Type: application/json');
    exit($response);
}

$data['endtoendid'] = 'endtoendid';
$data['account']['accountNumber'] = '127200';
$data['owner']['taxIdNumber'] = '06170097914';
$data['code'] = '200';

$data['owner']['name'] = 'Daniel Eskelsen';
$data['account']['participant'] = '487';
$data['account']['branch'] = '0001';
$data['account']['accountType'] = 'N';
$data['key'] = $key;
$data['keyType'] = 'email';

$data['description'] = 'CONSULTA COM SUCESSO (API antiga).';

$response = json_encode($data);
header('Content-Type: application/json');
exit($response);
