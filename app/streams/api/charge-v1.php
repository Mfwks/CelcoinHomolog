<?php

/*
 * GET e DELETE de cobrança V1 pelo `externalId` no path.
 *
 * Um stream só para os dois métodos porque o roteador do mock casa por path e
 * ignora o método (App\Core\Web::match) — duas rotas com o mesmo map fariam a
 * segunda nunca ser alcançada. O ramo por método fica aqui, como já acontece
 * em `dict-claim-router`.
 *
 * A decisão de forma e de status HTTP mora nos builders `Cslabs::chargeV1*`;
 * este arquivo só entrega. Ver o docblock deles para o que é medido e o que é
 * inferido — em especial o fato de o GET e o DELETE terem formas DIFERENTES
 * para "não encontrei".
 */

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$externalId = (string) ($web->args->externalId ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$resultado = match ($method) {
    'GET' => Cslabs::chargeV1FetchResponse($externalId),
    'DELETE' => Cslabs::chargeV1CancelResponse($externalId, is_array($b = Cslabs::requestBody()) ? $b : []),
    default => [
        'http' => 405,
        'body' => [
            'version' => '1.2.0',
            'status' => 'ERROR',
            'error' => [
                'errorCode' => 'CSLAB405',
                // Código do mock, não da Celcoin: o real não foi medido nesta
                // rota, e vestir um CDE de verdade num caso inventado é o que
                // faz o mock ensinar contrato errado.
                'message' => 'Método ' . $method . ' não existe em /v1/charge/{externalId} — só GET e DELETE.',
            ],
        ],
    ],
};

http_response_code($resultado['http']);
echo json_encode($resultado['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
