<?php

include APP . 'Core/Json.php';

use app\Core\Json;

$data = [];

foreach ($web->routes as $route) {
    $data[] = $route->map ?? null;
}

header('Content-Type: application/json');
echo Json::pretty($data, JSON_PRETTY_PRINT);