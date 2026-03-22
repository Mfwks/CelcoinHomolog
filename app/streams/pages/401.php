<?php

# 401 Unauthorized

header('HTTP/1.1 401 Unauthorized');

$c['title']     = 'Não autorizado » ' . $c['site'];
$c['header'] 	= '401 | Unauthorized';
$c['message'] 	= 'Não autorizado.';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
