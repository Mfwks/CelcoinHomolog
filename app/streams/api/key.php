<?php

include_once __DIR__ . '/api-stream.php';

$key = $_GET['key'] ?? 'ok@pix.com';

$type = strstr($key,'@',true);

if ($type=='erro' || $type=='error') {
    $data['status'] = 'ERROR';
    $data['code']['errorCode'] = 'OUTROCODIGO';
    $data['code']['message'] = 'Outro erro genérico';
    $data['version'] = '1.0.0';
    $response = json_encode($data);
    header('Content-Type: application/json');
    echo $response;
    return;
}

if ($type=='fraude' || $type=='fraud') {
    $data['status'] = 'ERROR';
    $data['code']['errorCode'] = 'CPD0013';
    $data['code']['message'] = 'Chave Pix com dados restritos por marcação de fraude';
    $data['version'] = '1.0.0';
    $response = json_encode($data);
    header('Content-Type: application/json');
    echo $response;
    return;
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

header('Content-Type: application/json');
echo json_encode($data, JSON_PRETTY_PRINT);
