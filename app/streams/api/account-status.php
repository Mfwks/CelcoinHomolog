<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$clientCode = trim($_GET['clientCode'] ?? '');
$onboardingId = trim($_GET['onboardingId'] ?? '');
$entityId = $onboardingId !== '' ? $onboardingId : ($clientCode !== '' ? $clientCode : 'default');

$status = Cslabs::readEntity('onboardings', $entityId);

if ($status === false) {
    $status = [
        'clientCode' => $clientCode !== '' ? $clientCode : null,
        'onboardingId' => $onboardingId !== '' ? $onboardingId : null,
        'status' => 'CONFIRMED',
        'UpdatedAt' => date(DATE_ATOM),
    ];

    Cslabs::writeEntity('onboardings', $entityId, $status);
}

header('Content-Type: application/json');
echo json_encode(array_filter($status, fn ($value) => $value !== null && $value !== ''), JSON_PRETTY_PRINT);
