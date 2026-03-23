<?php

header('Content-Type: application/json');

$data['url'] = $web->url ?? null;
$data['args'] = $web->args ?? null;

if (!empty($_GET['key'])) {
    $data['args']->key = $_GET['key']; // gambiarra premium
}

echo json_encode($data, JSON_PRETTY_PRINT);
