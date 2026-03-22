<?php

# 401 Forbidden

header('HTTP/1.1 403 Forbidden');

$c['title']     = 'Não autorizado » ' . $c['site'];
$c['header'] 	= '403 | Forbidden';
$c['message'] 	= 'Não autorizado.';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
