<?php

# 500 Internal Server Error

header('HTTP/1.1 500 Internal Server Error');

$header = 'Erro no servidor';

$c['title']     = 'Erro no servidor » ' . $c['site'];
$c['header'] 	= '500 | Internal Server Error';
$c['message'] 	= 'Algum erro ao processar a solicitação.';
$c['blink'] 	= 'p';
$c['off'] 		= 100;

include VIEWS . 'page.php';
exit;
