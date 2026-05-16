<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$clientCode = trim((string) ($_GET['clientCode'] ?? ''));
$endToEndId = trim((string) ($_GET['endToEndId'] ?? ''));
$id = trim((string) ($_GET['id'] ?? ''));

$state = false;

foreach ([$clientCode, $endToEndId, $id] as $needle) {
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
    echo json_encode(Cslabs::transferNotFoundError(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$elapsed = time() - (int) ($state['created_ts'] ?? time());
$confirmed = ($state['status'] ?? 'PROCESSING') === 'CONFIRMED' || $elapsed >= 2;

echo json_encode([
    'status' => $confirmed ? 'CONFIRMED' : 'PROCESSING',
    'version' => '1.0.0',
    'body' => $state['body'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
