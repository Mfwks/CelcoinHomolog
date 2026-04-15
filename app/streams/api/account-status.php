<?php

$data['clientCode'] = $_GET['clientCode'] ?? null;
$data['onboardingId'] = $_GET['onboardingId'] ?? null;

$data['status'] = 'CONFIRMED';

$data = array_filter($data);

header('Content-Type: application/json');
echo json_encode($data, JSON_PRETTY_PRINT);
