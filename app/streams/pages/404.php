<?php

# 404 Not Found

header('HTTP/1.1 404 Not Found');

$c['title']     = 'Não encontrado » ' . $c['site'];
$c['header'] 	= '404 | Not Found';
$c['message'] 	= 'Endereço inexistente no sistema.';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
