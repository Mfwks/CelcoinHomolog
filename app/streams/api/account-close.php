<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$account = trim((string) ($_GET['Account'] ?? $_GET['account'] ?? ''));
$reason = trim((string) ($_GET['Reason'] ?? $_GET['reason'] ?? ''));

$response = Cslabs::accountCloseResponse($account, $reason);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(422);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

Cslabs::writeEntity('account_status', $account, [
    'account' => $account,
    'status' => 'ENCERRADA',
    'reason' => $reason,
    'closed_at' => date(DATE_ATOM),
]);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
