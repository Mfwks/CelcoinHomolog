<?php

# 502 Bad Gateway

header('HTTP/1.0 502 Bad Gateway');

$header = $c['headline'] ?? '';

$c['title']     = 'Resposta inválida » ' . $c['site'];
$c['header'] 	= '502 Bad Gateway';
$c['message'] 	= 'Um serviço de terceiros retornou uma resposta inválida.';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
