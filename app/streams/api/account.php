<?php

$data['DocumentNumber'] = $_GET['DocumentNumber'] ?? null;

$data = array_filter($data);

header('Content-Type: application/json');
echo json_encode($data, JSON_PRETTY_PRINT);
