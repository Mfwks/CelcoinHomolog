<?php

$key = $_GET['key'] ?? 'ok@pix.com';

if ($key=='erro@pix.com') {
    $data['status'] = 'ERROR';
    $data['code']['errorCode'] = 'OUTROCODIGO';
    $data['code']['message'] = 'Outro erro genérico';
    $data['version'] = '1.0.0';
    $response = json_encode($data);
    header('Content-Type: application/json');
    exit($response);
}

if ($key=='fraude@pix.com') {
    $data['status'] = 'ERROR';
    $data['code']['errorCode'] = 'CPD0013';
    $data['code']['message'] = 'Chave Pix com dados restritos por marcação de fraude';
    $data['version'] = '1.0.0';
    $response = json_encode($data);
    header('Content-Type: application/json');
    exit($response);
}

$data['endtoEndId'] = 'endtoendid';
$data['owner']['name'] = 'Daniel Eskelsen';
$data['owner']['documentNumber'] = '06170097914';
$data['account']['account'] = '127200';
$data['account']['participant'] = '487';
$data['account']['branch'] = '0001';
$data['account']['accountType'] = 'N';
$data['key'] = $key;
$data['keyType'] = 'email';
$data['description'] = 'CONSULTA COM SUCESSO.';

$response = json_encode($data);
header('Content-Type: application/json');
exit($response);
