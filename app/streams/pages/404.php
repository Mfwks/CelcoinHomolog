<?php

# 404 Not Found

header('HTTP/1.1 404 Not Found');

$headers = getallheaders();

$accept = $headers['Accept'] ?? null;

    header('Content-Type: application/json');
    $data['status'] = 'ERROR';
    $data['error'] = ['message' => 'Registro ou funcionalidade não encontrado'];    
    return;
