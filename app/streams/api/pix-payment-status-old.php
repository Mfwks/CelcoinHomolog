<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$transactionId = trim((string) ($_GET['transactionId'] ?? ''));
$endToEndId = trim((string) ($_GET['endtoendId'] ?? $_GET['endToEndId'] ?? ''));

$state = false;
foreach ([$transactionId, $endToEndId] as $needle) {
    if ($needle === '') {
        continue;
    }
    $state = Cslabs::readEntity('pix_payments', $needle);
    if (is_array($state)) {
        break;
    }
}

if (!is_array($state)) {
    http_response_code(404);
    echo json_encode(Cslabs::pixOldStatusNotFoundError(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$elapsed = time() - (int) ($state['created_ts'] ?? time());
$confirmed = ($state['status'] ?? 'PROCESSING') === 'CONFIRMED' || $elapsed >= 2;

echo json_encode([
    'transactionId' => $state['body']['id'] ?? $transactionId,
    'endToEndId' => $state['body']['endToEndId'] ?? $endToEndId,
    'amount' => $state['body']['amount'] ?? 0,
    'status' => $confirmed ? 'CONFIRMED' : 'PROCESSING',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
