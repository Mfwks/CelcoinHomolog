<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$id = trim((string) ($_GET['Id'] ?? $_GET['id'] ?? ''));
$clientRequestId = trim((string) ($_GET['ClientRequestId'] ?? $_GET['clientRequestId'] ?? ''));

$state = false;

if ($id !== '') {
    $state = Cslabs::readEntity('bill_payments', $id);

    if ($state === false) {
        $alias = Cslabs::readEntity('bill_payments_by_authorize', $id);
        if (is_array($alias) && !empty($alias['id'])) {
            $state = Cslabs::readEntity('bill_payments', $alias['id']);
        }
    }
}

if ($state === false && $clientRequestId !== '') {
    $alias = Cslabs::readEntity('bill_payments_by_client_request_id', $clientRequestId);
    if (is_array($alias) && !empty($alias['id'])) {
        $state = Cslabs::readEntity('bill_payments', $alias['id']);
    }
}

if (!is_array($state)) {
    http_response_code(404);
    echo json_encode([
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB404',
            'message' => 'Pagamento de boleto não encontrado.',
        ],
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT);
    return;
}

$elapsed = time() - (int) ($state['created_ts'] ?? time());
$confirmed = ($state['status'] ?? 'PROCESSING') === 'CONFIRMED' || $elapsed >= 2;

if ($confirmed && ($state['status'] ?? null) !== 'CONFIRMED') {
    $state['status'] = 'CONFIRMED';
    $state['paymentDate'] = gmdate('Y-m-d\TH:i:s\Z');
    Cslabs::writeEntity('bill_payments', $state['id'], $state);
}

echo json_encode(Cslabs::billPaymentStatusRender($state, $confirmed), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
