<?php

# 503 Service Unavailable

header('HTTP/1.0 503 Service Unavailable');

$header = $c['headline'] ?? '';

$c['title']     = 'Erro interno no servidor » ' . $c['site'];
$c['header'] 	= '503 | Service Unavailable';
$c['message'] 	= 'Erro interno no servidor.';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
