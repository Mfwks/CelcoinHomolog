<?php

# Dispatcher V2 do DICT entry (baas/v2/pix/dict/entry/{key}).
# Web::search casa por padrão sem olhar método, então list/delete/otp/confirm
# (todos /entry/<seg>) chegam aqui e ramificam por método + segmento.
# Ver HOMOLOGACAO_CELCOIN_V2.md §4.4/§4.5.

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$seg = (string) ($web->args->key ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

# POST /entry/otp e /entry/confirm — verificação de posse de chave (EMAIL/PHONE).
# Sem tráfego real nos logs; shape mínimo de confirmação (inferido do código do consumidor).
if ($method === 'POST' && in_array($seg, ['otp', 'confirm'], true)) {
    $body = Cslabs::requestBody();
    $body = is_array($body) ? $body : [];

    $out = [
        'status' => 'SUCCESS',
        'version' => '1.0.0',
        'body' => [
            'account' => (string) ($body['account'] ?? ''),
            'keyType' => (string) ($body['keyType'] ?? ''),
            'key' => (string) ($body['key'] ?? ''),
        ],
    ];

    if ($seg === 'otp') {
        $out['body']['message'] = 'Código de verificação enviado para a chave informada.';
    } else {
        $out['body']['confirmationCode'] = (string) ($body['confirmationCode'] ?? '');
        $out['body']['message'] = 'Posse da chave confirmada.';
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

# DELETE /entry/{key} — exclusão de chave (mesma lógica de api/dict-entry-delete).
if ($method === 'DELETE') {
    $body = Cslabs::requestBody();
    $body = is_array($body) ? $body : [];

    $response = Cslabs::pixDictDeleteResponse($seg, $body);

    if (($response['status'] ?? null) === 'ERROR') {
        http_response_code(400);
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if (Cslabs::readEntity('pix_dict_entries', $seg) !== false) {
        Cslabs::writeEntity('pix_dict_entries', $seg, [
            'key' => $seg,
            'deleted_at' => date(DATE_ATOM),
            'status' => 'DELETED',
        ]);
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

# GET /entry/{account} — listar chaves da conta (mesma lógica de api/dict-entry-list).
$response = Cslabs::pixDictListByAccountResponse($seg);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
