<?php

# 429 Too Many Requests

header('HTTP/1.1 429 Too Many Requests');

$c['title']     = 'Muitas solicitações » ' . $c['site'];
$c['header'] 	= '429 | Too Many Requests';
$c['message'] 	= 'Muitas solicitações.';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
