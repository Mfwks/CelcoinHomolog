<?php

# 501 Not Implemented

header('HTTP/1.0 501 Not Implemented');

refresh('/', 8);

$c['title']     ='Não implementado » ' . $c['site'];
$c['header']  	= '501 | Not Implemented';
$c['message'] 	= 'Este recurso estará disponível em breve.';
$c['blink'] 	  = 'p';

include VIEWS . 'special.php';
exit;
