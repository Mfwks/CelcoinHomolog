<?php

# 400 Bad Request

header('HTTP/1.1 400 Bad Request');

$c['title']     = 'Solicitação inválida » ' . $c['site'];
$c['header'] 	= '400 | Bad Request';
$c['message'] 	= 'Solicitação inválida.';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
