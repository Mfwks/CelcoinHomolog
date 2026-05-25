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

# Bootstrap mínimo (espelha axis + start, sem roteamento web).
chdir(dirname(__DIR__, 2));
require __DIR__ . '/../../axis.php';
require WEB . 'vendor/autoload.php';
require APP . 'functions.php';
require APP . 'basics.php';
require APP . 'config.php';

App\Core\Cslabs::deliverScheduledWebhook($dispatch);
