<?php

use App\Core\Conn;
use App\Core\Data;

if (!($conn = Conn::start())) {
    return;
}

$db = new Data($conn);
