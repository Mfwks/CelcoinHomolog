<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$account = trim((string) ($_GET['Account'] ?? $_GET['account'] ?? ''));
$body = Cslabs::requestBody();

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(Cslabs::accountManagerError('CBE001', 'Payload JSON inválido ou ausente.'), JSON_PRETTY_PRINT);
    return;
}

if ($account === '') {
    http_response_code(422);
    echo json_encode(Cslabs::accountManagerError('CBE014', 'Account é obrigatório.'), JSON_PRETTY_PRINT);
    return;
}

$response = Cslabs::accountUpdateNaturalPersonScenario($body, $account);

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

Cslabs::writeEntity('account_updates', $account, [
    'account' => $account,
    'kind' => 'natural-person',
    'patch' => $body,
    'updated_at' => date(DATE_ATOM),
]);

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
