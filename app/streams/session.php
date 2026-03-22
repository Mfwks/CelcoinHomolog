<?php

use App\Core\Session;

if (empty($db) OR empty($db->tab())) {
    return;
}

$handler = new Session($db);
session_set_save_handler($handler, true);
session_start();

$handler->user_id = 1;
