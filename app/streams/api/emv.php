<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'version' => '1.0.0',
        'error' => ['errorCode' => 'CBE001', 'message' => 'Payload JSON inválido ou ausente.'],
    ], JSON_PRETTY_PRINT);
    return;
}

$response = Cslabs::emvDecodeResponse($body);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
} elseif (str_ends_with(Cslabs::context()['path'] ?? '', '/emv/full')) {
    /*
     * Mesmo stream serve /pix/v1/emv (v1) e /pix/v1/emv/full (V2), e os shapes
     * reais são diferentes — por isso o branch é pelo sufixo do path e não por
     * Cslabs::isV2() (nenhum dos dois está sob /baas/v2/).
     *
     * A v1 lê PLANO e fixo ($dadosEmv->type, ->collection, ->transactionAmount,
     * ->merchantAccountInformation->key — CelcoinPix::consultaQRCode:1290-1332),
     * então o shape acima não pode mudar. O /full real vem enveloped, com `status`
     * INT (200, não string), `type` textual (IMMEDIATE), amount detalhado e bloco
     * `payload`. Quirk real preservado: `additionaldata` com "d" minúsculo.
     * Ver HOMOLOGACAO_CELCOIN_V2.md §4.9.
     */
    $mai = $response['merchantAccountInformation'];
    $amount = (float) $response['transactionAmount'];
    $isDynamic = $response['collection'] === '1';

    $response = [
        'version' => '1.0.0',
        'status' => 200,
        'body' => [
            'type' => $isDynamic ? 'IMMEDIATE' : 'STATIC',
            'merchantAccountInformation' => [
                // No QR estático o real devolve url E gui nulos (mocks-v2).
                'url' => $isDynamic ? $mai['url'] : null,
                'gui' => $isDynamic ? $mai['gui'] : null,
                'merchantCategoryCode' => '0000',
                'additionaldata' => null,
                'withdrawalServiceProvider' => null,
                'merchantName' => $response['merchantName'],
                'merchantCity' => $response['merchantCity'],
                'postalCode' => $response['postalCode'],
            ],
            'key' => $mai['key'],
            'amount' => [
                'original' => $amount,
                'abatement' => null,
                'discount' => null,
                'interest' => null,
                'final' => $amount,
                'fine' => null,
                'canModifyFinalAmount' => false,
                'withdrawal' => null,
                'change' => null,
            ],
            'transactionIdentification' => $response['transactionIdentification'],
            /*
             * `payload` é a cobrança por trás da location — só existe no QR
             * dinâmico. No estático o real manda null (mocks-v2, 14 amostras),
             * e antes daqui saía o objeto nos dois casos.
             */
            'payload' => $isDynamic ? [
                'status' => 'ACTIVE',
                'revision' => 0,
                'calendar' => [
                    'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z', time() - 3600),
                    'presentation' => gmdate('Y-m-d\TH:i:s.v\Z'),
                    'dueDate' => null,
                    'validateAfterDuedate' => null,
                    'expiration' => 3946686,
                    'expirationDate' => gmdate('Y-m-d\TH:i:s.v\Z', time() + 86400),
                ],
                'debtor' => ['cpf' => null, 'cnpj' => null, 'name' => null],
                'receiver' => null,
                'payerQuestion' => null,
                'additionalInformation' => null,
            ] : null,
        ],
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
