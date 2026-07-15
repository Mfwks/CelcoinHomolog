<?php

# GET /baas/v2/wallet/dayBalance — saldo diário (celcoinv2 AccountService::saldoDia).
# Shape real: HOMOLOGACAO_CELCOIN_V2.md Apêndice B (envelope paginado com os
# campos de paginação no TOPO, irmãos de body; body.balances[] por dia).

include_once __DIR__ . '/api-stream.php';

header('Content-Type: application/json');

$account = trim((string) ($_GET['account'] ?? $_GET['Account'] ?? ''));
$documentNumber = trim((string) ($_GET['DocumentNumber'] ?? $_GET['documentNumber'] ?? ''));
$dateFrom = substr(trim((string) ($_GET['dateFrom'] ?? $_GET['DateFrom'] ?? date('Y-m-d'))), 0, 10);
$dateTo = substr(trim((string) ($_GET['dateTo'] ?? $_GET['DateTo'] ?? $dateFrom)), 0, 10);
$page = max(1, (int) ($_GET['Page'] ?? 1));
$limit = max(1, (int) ($_GET['LimitPerPage'] ?? 20));

$fromTs = strtotime($dateFrom) ?: time();
$toTs = strtotime($dateTo) ?: $fromTs;
if ($toTs < $fromTs) {
    $toTs = $fromTs;
}

# Saldo determinístico por conta+dia (estável entre chamadas, sem persistência).
$seed = crc32($account);
$balances = [];
for ($ts = $fromTs; $ts <= $toTs && count($balances) < 62; $ts += 86400) {
    $day = date('Y-m-d', $ts);
    $balances[] = [
        'date' => $day,
        'balance' => round((($seed ^ crc32($day)) % 1000000) / 100, 2),
        'totalMovement' => 0,
        'totalMovementDebit' => 0,
        'totalMovementCredit' => 0,
        'qtdMovement' => 0,
        'qtdMovementDebit' => 0,
        'qtdMovementCredit' => 0,
    ];
}

$total = count($balances);
$totalPages = max(1, (int) ceil($total / $limit));
$slice = array_slice($balances, ($page - 1) * $limit, $limit);
$currentBalance = $balances ? end($balances)['balance'] : 0;

echo json_encode([
    'status' => 'SUCCESS',
    'version' => '1.0.0',
    'totalItems' => $total,
    'currentPage' => $page,
    'limitPerPage' => $limit,
    'totalPages' => $totalPages,
    'dateFrom' => $dateFrom . 'T00:00:00',
    'dateTo' => $dateTo . 'T23:59:59.9999999',
    'body' => [
        'account' => $account,
        'documentNumber' => $documentNumber,
        'currentBalance' => $currentBalance,
        'balances' => array_values($slice),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
