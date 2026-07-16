<?php

/*
 * auto_prepend_file do servidor embutido em celcoinv2_paths_smoke.php.
 *
 * axis.php faz `define('TMP', APP . 'tmp/')` sem checar se já existe; definindo TMP
 * aqui antes, o define de lá vira no-op (com um warning em stderr, que o teste
 * descarta) e o primeiro valor prevalece. É o que mantém o smoke fora do SQLite real
 * do mock — sem isso, cada rodada plantaria pix_payments/shots no banco do dev.
 */

define('TMP', __DIR__ . '/tmp_smoke/');

if (!is_dir(TMP)) {
    mkdir(TMP, 0775, true);
}
