<?php

declare(strict_types=1);

/*
 * Webhook delivery worker.
 *
 * Spawnado por `Cslabs::scheduleWebhook` via `exec(... &)`. Roda fora do
 * lifecycle da request, então funciona em qualquer SAPI — php -S, mod_php,
 * FPM, CLI. Lê o dispatch de um arquivo JSON, espera o delay e entrega
 * o webhook, persistindo o resultado em `webhook_dispatches` e anexando
 * em `interactions.webhooks[]`.
 *
 * Uso: php app/bin/webhook-worker.php <dispatch.json>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('worker only');
}

if ($argc < 2) {
    fwrite(STDERR, "uso: webhook-worker.php <dispatch.json>\n");
    exit(1);
}

$dispatchFile = (string) $argv[1];
if (!is_file($dispatchFile) || !is_readable($dispatchFile)) {
    fwrite(STDERR, "arquivo de dispatch ausente: {$dispatchFile}\n");
    exit(1);
}

$raw = file_get_contents($dispatchFile);
@unlink($dispatchFile);

$dispatch = json_decode((string) $raw, true);
if (!is_array($dispatch)) {
    fwrite(STDERR, "dispatch inválido\n");
    exit(1);
}

/*
 * O TMP tem que ser decidido ANTES do axis.php, que faz `define('TMP', APP.'tmp/')`
 * sem checar se já existe. O worker herda a árvore de quem o spawnou: o dispatch mora
 * em `<TMP>cslabs/dispatches/<id>.json`, então o TMP é o avô do arquivo.
 *
 * Sem isto o worker escapava do isolamento dos smokes — o `auto_prepend_file` só vale
 * para o processo do servidor, e o worker, spawnado por exec, caía no SQLite REAL do
 * mock. Efeito prático (medido em 05/08/2026): a entrega sumia do banco que o teste lê,
 * e o smoke concluía "webhook não entregue" sobre um webhook que tinha sido entregue.
 */
$pastaDoDispatch = basename(dirname($dispatchFile));
$pastaAvo = basename(dirname($dispatchFile, 2));

if ($pastaDoDispatch === 'dispatches' && $pastaAvo === 'cslabs') {
    define('TMP', dirname($dispatchFile, 3) . '/');
}

# Bootstrap mínimo (espelha axis + start, sem roteamento web).
chdir(dirname(__DIR__, 2));
require __DIR__ . '/../../axis.php';
require WEB . 'vendor/autoload.php';
require APP . 'functions.php';
require APP . 'basics.php';
require APP . 'config.php';

App\Core\Cslabs::deliverScheduledWebhook($dispatch);
