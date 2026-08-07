<?php

/*
 * GET /cslabs/webhook/diagnostico — como este host entrega webhook.
 *
 * Não existe na Celcoin. Existe porque a causa do "agendado mas nunca entregue"
 * é config de PHP do servidor hospedado, e isso não se mede de fora: as três
 * suspeitas do briefing de 07/08/2026 (exec em disable_functions, shutdown
 * cortado, binário/caminho do worker errado) só se separam rodando no próprio
 * host. Uma requisição responde qual ramo roda e o que o impede.
 */

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$diagnostico = Cslabs::webhookDeliveryDiagnostics();

echo json_encode([
    'status' => 'OK',
    'message' => $diagnostico['worker_utilizavel']
        ? 'O worker em background é utilizável neste host.'
        : 'O worker em background NÃO roda aqui — use o dispatch síncrono ou o flush.',
    'diagnostico' => $diagnostico,
    'version' => '1.0.0',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
