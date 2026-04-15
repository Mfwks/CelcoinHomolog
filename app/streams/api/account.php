<?php

$data['DocumentNumber'] = $_GET['DocumentNumber'] ?? null;

$data = array_filter($data);

$response = json_encode($data);
header('Content-Type: application/json');
echo $response;
