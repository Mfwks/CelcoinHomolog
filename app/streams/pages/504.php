<?php

# 504 Gateway Timeout

header('HTTP/1.0 504 Gateway Timeout');

$header = $c['headline'] ?? '';

$c['title']     = 'Tempo Limite de Gateway » ' . $c['site'];
$c['header'] 	= '504 | Gateway Timeout';
$c['message'] 	= 'Tempo Limite de Gateway';
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
