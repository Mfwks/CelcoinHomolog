<?php

# 404 Not Found

header('HTTP/1.1 404 Not Found');

$headers = getallheaders();

$accept = $headers['Accept'] ?? null;

if ($accept=='application/json') {
    echo json_encode(['status' => false, 'error' => 'Registro não encontrado', 'code' => 404]);
    return;
}

$c['title']     = 'Não encontrado » ' . $c['site'];
$c['header'] 	= '404 | Not Found';
$c['message'] 	= 'Endereço inexistente no sistema.';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
