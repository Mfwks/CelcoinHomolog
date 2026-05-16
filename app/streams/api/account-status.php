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
    $record = [
        'onboardingId' => $entityId,
        'clientCode' => $clientCode !== '' ? $clientCode : null,
        'status' => 'CONFIRMED',
        'created_at' => date(DATE_ATOM),
    ];

    Cslabs::writeEntity('onboardings', $entityId, $record);
} elseif (($record['status'] ?? null) === 'PROCESSING' && (time() - strtotime($record['created_at'] ?? 'now')) >= 3) {
    $record['status'] = 'CONFIRMED';
    Cslabs::writeEntity('onboardings', $record['onboardingId'], $record);
}

echo json_encode([
    'onboardingId' => $record['onboardingId'],
    'status' => $record['status'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
