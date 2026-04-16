<?php

use App\Core\Cslabs;
use App\Core\Json;

$clientId = preg_replace('/[^a-zA-Z0-9._-]/', '', $web->args->identifier ?? '');
$selectedRequestId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['request'] ?? '');
$interactions = $clientId !== '' ? Cslabs::listInteractions($clientId) : [];
$selected = $selectedRequestId !== '' ? Cslabs::findInteraction($clientId, $selectedRequestId) : ($interactions[0] ?? null);

$workers = [];
foreach ($interactions as $item) {
    $workerId = $item['worker_id'] ?? 'unknown';
    $workers[$workerId] = true;
}

$summary = [
    'total' => count($interactions),
    'workers' => count($workers),
    'last_seen' => $interactions[0]['received_at'] ?? null,
];

function prettyPanel(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Sem dados.';
    }

    if (is_array($value)) {
        return Json::pretty($value) ?: json_encode($value, JSON_PRETTY_PRINT);
    }

    return (string) $value;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CSLabs Shots</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b1320;
            --bg-soft: #111b2e;
            --panel: #16233b;
            --panel-2: #1d2d4b;
            --line: #2f456a;
            --text: #e7edf7;
            --muted: #91a4c3;
            --accent: #70e1c8;
            --accent-2: #87a8ff;
            --danger: #ff7a7a;
            --mono: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            --sans: "Segoe UI", system-ui, sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: var(--sans);
            background:
                radial-gradient(circle at top left, rgba(112, 225, 200, 0.12), transparent 30%),
                radial-gradient(circle at top right, rgba(135, 168, 255, 0.18), transparent 25%),
                var(--bg);
            color: var(--text);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .page {
            max-width: 1500px;
            margin: 0 auto;
            padding: 24px;
        }

        .hero {
            margin-bottom: 20px;
            padding: 24px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(17, 27, 46, 0.85);
            backdrop-filter: blur(16px);
        }

        .hero h1,
        .hero p {
            margin: 0;
        }

        .hero h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .hero p {
            color: var(--muted);
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 18px;
        }

        .meta-card {
            padding: 14px 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--panel);
        }

        .meta-card span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .meta-card strong {
            display: block;
            margin-top: 6px;
            font-size: 18px;
            word-break: break-word;
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(320px, 430px) minmax(0, 1fr);
            gap: 20px;
        }

        .panel {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(22, 35, 59, 0.92);
            overflow: hidden;
        }

        .panel-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
            background: rgba(13, 22, 37, 0.55);
        }

        .panel-header h2,
        .panel-header p {
            margin: 0;
        }

        .panel-header p {
            margin-top: 6px;
            color: var(--muted);
        }

        .list {
            max-height: calc(100vh - 280px);
            overflow: auto;
            padding: 12px;
        }

        .item {
            display: block;
            margin-bottom: 10px;
            padding: 14px;
            border: 1px solid transparent;
            border-radius: 16px;
            background: var(--panel-2);
            transition: 0.18s ease;
        }

        .item:hover,
        .item.active {
            border-color: var(--accent-2);
            transform: translateY(-1px);
        }

        .item strong,
        .item span {
            display: block;
        }

        .item strong {
            margin-bottom: 8px;
            font-size: 14px;
            font-family: var(--mono);
        }

        .item span {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.45;
        }

        .detail {
            padding: 20px;
        }

        .detail-head {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(112, 225, 200, 0.12);
            color: var(--accent);
            font-size: 12px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .card {
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: rgba(12, 20, 33, 0.55);
        }

        .card h3 {
            margin: 0 0 10px;
            font-size: 14px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: var(--mono);
            font-size: 12px;
            line-height: 1.5;
            color: #d9f8f3;
        }

        .empty {
            padding: 28px;
            color: var(--muted);
        }

        .warn {
            color: var(--danger);
        }

        @media (max-width: 1080px) {
            .layout,
            .grid {
                grid-template-columns: 1fr;
            }

            .list {
                max-height: none;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="hero">
            <h1>CSLabs Shots</h1>
            <p>Visão consolidada das interações persistidas para um cliente de homologação.</p>

            <div class="meta">
                <div class="meta-card">
                    <span>Client ID</span>
                    <strong><?= htmlspecialchars($clientId !== '' ? $clientId : 'não informado') ?></strong>
                </div>
                <div class="meta-card">
                    <span>Total de interações</span>
                    <strong><?= $summary['total'] ?></strong>
                </div>
                <div class="meta-card">
                    <span>Workers vistos</span>
                    <strong><?= $summary['workers'] ?></strong>
                </div>
                <div class="meta-card">
                    <span>Última interação</span>
                    <strong><?= htmlspecialchars($summary['last_seen'] ?? 'sem registros') ?></strong>
                </div>
            </div>
        </section>

        <section class="layout">
            <aside class="panel">
                <div class="panel-header">
                    <h2>Interações</h2>
                    <p>Cada request fica salvo como um JSON próprio, com request, response e metadados.</p>
                </div>
                <div class="list">
                    <?php if (!$interactions): ?>
                        <div class="empty">Nenhuma interação encontrada para este cliente.</div>
                    <?php endif; ?>

                    <?php foreach ($interactions as $item): ?>
                        <?php
                            $requestId = $item['request_id'] ?? 'sem-id';
                            $active = $selected && ($selected['request_id'] ?? null) === $requestId;
                            $query = http_build_query(['request' => $requestId]);
                        ?>
                        <a class="item<?= $active ? ' active' : '' ?>" href="?<?= $query ?>">
                            <strong><?= htmlspecialchars($requestId) ?></strong>
                            <span><?= htmlspecialchars(($item['method'] ?? 'GET') . ' ' . ($item['path'] ?? '/')) ?></span>
                            <span><?= htmlspecialchars($item['received_at'] ?? 'sem horário') ?></span>
                            <span>worker: <?= htmlspecialchars($item['worker_id'] ?? 'desconhecido') ?></span>
                            <span>status: <?= htmlspecialchars((string) ($item['response']['status_code'] ?? '0')) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <main class="panel">
                <div class="panel-header">
                    <h2>Detalhe da interação</h2>
                    <p>Use este painel para inspecionar a chamada recebida e a resposta devolvida para o cliente.</p>
                </div>

                <?php if (!$selected): ?>
                    <div class="empty">Selecione uma interação para visualizar os detalhes.</div>
                <?php else: ?>
                    <div class="detail">
                        <div class="detail-head">
                            <div>
                                <div class="badge"><?= htmlspecialchars(($selected['method'] ?? 'GET') . ' ' . ($selected['path'] ?? '/')) ?></div>
                            </div>
                            <div class="badge">status <?= htmlspecialchars((string) ($selected['response']['status_code'] ?? 0)) ?></div>
                        </div>

                        <div class="grid">
                            <section class="card">
                                <h3>Resumo</h3>
                                <pre><?= htmlspecialchars(prettyPanel([
                                    'request_id' => $selected['request_id'] ?? null,
                                    'client_id' => $selected['client_id'] ?? null,
                                    'worker_id' => $selected['worker_id'] ?? null,
                                    'auth_hint' => $selected['auth_hint'] ?? null,
                                    'received_at' => $selected['received_at'] ?? null,
                                    'ip' => $selected['ip'] ?? null,
                                ])) ?></pre>
                            </section>

                            <section class="card">
                                <h3>Query e Headers</h3>
                                <pre><?= htmlspecialchars(prettyPanel([
                                    'query' => $selected['query'] ?? [],
                                    'headers' => $selected['headers'] ?? [],
                                ])) ?></pre>
                            </section>
                        </div>

                        <div class="grid">
                            <section class="card">
                                <h3>Request</h3>
                                <pre><?= htmlspecialchars(prettyPanel($selected['request'] ?? null)) ?></pre>
                            </section>

                            <section class="card">
                                <h3>Response</h3>
                                <pre><?= htmlspecialchars(prettyPanel($selected['response']['body'] ?? null)) ?></pre>
                            </section>
                        </div>

                        <?php if (!empty($selected['meta'])): ?>
                            <section class="card">
                                <h3>Meta</h3>
                                <pre><?= htmlspecialchars(prettyPanel($selected['meta'])) ?></pre>
                            </section>
                        <?php endif; ?>

                        <?php if (!empty($selected['webhooks'])): ?>
                            <section class="card">
                                <h3>Webhooks</h3>
                                <pre><?= htmlspecialchars(prettyPanel($selected['webhooks'])) ?></pre>
                            </section>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </main>
        </section>

        <?php if ($clientId === ''): ?>
            <p class="warn">A rota precisa de um identificador de cliente válido.</p>
        <?php endif; ?>
    </div>
</body>
</html>
