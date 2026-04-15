<?php

$data['clientCode'] = $_GET['clientCode'] ?? null;
$data['onboardingId'] = $_GET['onboardingId'] ?? null;

$data['status'] = 'CONFIRMED';

$data = array_filter($data);

$response = json_encode($data);
header('Content-Type: application/json');
echo $response;
