<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$key = $_GET['key'] ?? 'ok@pix.com';
$ownerTaxId = $_GET['ownerTaxId'] ?? null;

$response = Cslabs::pixDictResponse($key, $ownerTaxId);

/*
 * Stream compartilhado: a v1 (celcoin-baas-pix-dict-webservice/v1/.../entry/external)
 * lê tudo PLANO e com caminho fixo — $dict->endtoEndId, $dict->account->account,
 * $dict->owner->documentNumber (modules/pix/models/CelcoinPix.php:1180-1214, sem
 * fallback) — enquanto a V2 (baas/v2/...) recebe o envelope {status, body, version}
 * e ainda ganha owner.type e isSameTaxId. Por isso o envelope entra só no path V2.
 * Shapes reais: HOMOLOGACAO_CELCOIN_V2.md §14 (Apêndice A, "Pix DICT").
 */
if (Cslabs::isV2() && ($response['status'] ?? null) !== 'ERROR') {
    $document = (string) ($response['owner']['documentNumber'] ?? '');
    $digits = preg_replace('/\D+/', '', $document) ?? '';

    $response = Cslabs::v2Envelope([
        'keyType' => $response['keyType'],
        'key' => $response['key'],
        'account' => $response['account'],
        'owner' => [
            'type' => strlen($digits) === 14 ? 'LEGAL_PERSON' : 'NATURAL_PERSON',
            'documentNumber' => $document,
            'name' => $response['owner']['name'] ?? '',
        ],
        'endtoEndId' => $response['endtoEndId'],
        'isSameTaxId' => $ownerTaxId !== null && $digits !== ''
            && preg_replace('/\D+/', '', (string) $ownerTaxId) === $digits,
    ]);
}

header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
