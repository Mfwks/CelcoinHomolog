<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$documentNumber = preg_replace('/\D+/', '', $_GET['DocumentNumber'] ?? '');

header('Content-Type: application/json');

if ($documentNumber === '') {
    echo json_encode([
        'DocumentNumber' => null,
    ], JSON_PRETTY_PRINT);
    return;
}

$account = Cslabs::readEntity('accounts', $documentNumber);

if ($account === false) {
    $seed = substr(hash('sha256', Cslabs::context()['client_id'] . '|' . $documentNumber), 0, 12);
    $numeric = (string) (hexdec(substr($seed, 0, 8)) % 900000 + 100000);

    $account = [
        'DocumentNumber' => $documentNumber,
        'Account' => [
            'Branch' => '0001',
            'AccountNumber' => $numeric,
            'Status' => 'ACTIVE',
        ],
        'Owner' => [
            'DocumentNumber' => $documentNumber,
        ],
        'CreatedAt' => date(DATE_ATOM),
    ];

    Cslabs::writeEntity('accounts', $documentNumber, $account);
}

echo json_encode($account, JSON_PRETTY_PRINT);
