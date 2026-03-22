<?php

# Maintenance

$c['title'] 	= 'Manutenção » ' . $c['site'];
$c['header'] 	= 'Manutenção';
$c['message']	= 'O sistema está temporariamente em manutenção. Voltaremos em breve.';
$c['off'] 		= '0';
$c['logo'] 	    = $c['og:image'];
$c['blink'] 	= 'p';
$c['off']		= 100;

include VIEWS . 'page.php';
exit;
