<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$clientCode = trim((string) ($_GET['clientCode'] ?? ''));
$onboardingId = trim((string) ($_GET['onboardingId'] ?? ''));

$record = false;

if ($onboardingId !== '') {
    $record = Cslabs::readEntity('onboardings', $onboardingId);
}

if ($record === false && $clientCode !== '') {
    $alias = Cslabs::readEntity('onboardings_by_client_code', $clientCode);
    if (is_array($alias) && !empty($alias['onboardingId'])) {
        $record = Cslabs::readEntity('onboardings', $alias['onboardingId']);
    }
}

if (!is_array($record)) {
    $entityId = $onboardingId !== '' ? $onboardingId : ($clientCode !== '' ? $clientCode : 'default');
    $account = Cslabs::generateAccountNumber($entityId . '|' . $clientCode);
    $record = [
        'onboardingId' => $entityId,
        'clientCode' => $clientCode !== '' ? $clientCode : null,
        'documentNumber' => '',
        'kind' => 'natural-person',
        'status' => 'CONFIRMED',
        'account' => [
            'account' => $account,
            'branch' => '0001',
        ],
        'request' => [],
        'created_at' => date(DATE_ATOM),
    ];

    Cslabs::writeEntity('onboardings', $entityId, $record);
} elseif (($record['status'] ?? null) === 'PROCESSING' && (time() - strtotime($record['created_at'] ?? 'now')) >= 3) {
    $record['status'] = 'CONFIRMED';
    Cslabs::writeEntity('onboardings', $record['onboardingId'], $record);
}

$request = is_array($record['request'] ?? null) ? $record['request'] : [];
$kind = (string) ($record['kind'] ?? 'natural-person');
$name = $kind === 'business'
    ? (string) ($request['businessName'] ?? $request['tradingName'] ?? '')
    : (string) ($request['socialName'] ?? $request['fullName'] ?? $request['name'] ?? '');

echo json_encode([
    'version' => '1.0.0',
    'status' => $record['status'],
    'body' => [
        'onboardingId' => $record['onboardingId'],
        'clientCode' => $record['clientCode'] ?? null,
        'createDate' => $record['created_at'] ?? date(DATE_ATOM),
        'entity' => 'account-create',
        'account' => [
            'branch' => (string) ($record['account']['branch'] ?? '0001'),
            'account' => (string) ($record['account']['account'] ?? ''),
            'name' => $name,
            'documentNumber' => (string) ($record['documentNumber'] ?? ($request['documentNumber'] ?? '')),
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
