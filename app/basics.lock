<?php

# Basics

# Domain
define('BASE', 		'/');
define('DOMAIN',	'mf.mfwks.com');
define('SITE', 		'https://' . DOMAIN);

# App Params
$table_prefix = 'mf_';

# Meta
$c['site'] 		= 'Microframeworks';
$c['name'] 		= 'Microframeworks';
$c['desc'] 		= 'A small, lightweight and easy-to-use framework';
$c['version']   = '0.9.0';
$c['released']  = '15 de novembro de 2023 (quarta-feira).';
$c['author'] 	= 'Daniel Eskelsen';
$c['header'] 	= 'Blank Template';
$c['paragraph'] = 'Blank Template';
$c['icon'] 		= url('assets/mfwks.png');
$c['logo'] 		= $c['icon'];
$c['og:image']  = url('assets/mfwks.png');;
$c['theme']     = '#4482A1';
$c['color']     = '#3A728D';
$c['this']   	= SITE . ($_SERVER['REQUEST_URI'] ?? ''); 
$c['agency']	= 'Microframeworks';
$c['location']	= 'São Paulo';

# Default Header
$c['title'] 	= $c['name'] . ' » ' . $c['site'];
$c['supline'] 	= 'Informações técnicas';
$c['headline'] 	= $c['name'];

# Software
$software['name']     = $c['name'];
$software['about']    = $c['desc'];
$software['version']  = $c['version'];
$software['released'] = $c['released'];

# Authorship
$software['author']   = 'Daniel Eskelsen';
$software['phone']    = '55 (11) 95354 4154';
$software['contact']  = 'eskelsen@yahoo.com';
$software['support']  = 'dev@microframeworks.com';
$software['mark']     = 'Microframeworks ©';
$software['link']     = '<a href="' . SITE . '" target="_blank">' . SITE . '/a>';

# Platform (microframework)
$platform['name']     = 'Microframework';
$platform['version']  = 'version 0.7.0';
$platform['surname']  = '(Oak)';
$platform['released'] = 'quarta-feira, 15 de novembro de 2023. São Paulo.';
$software['platform'] = implode(' ', $platform);

# Others
$c['generator'] = $platform['name'] . ' ' . $platform['version'];

# System
$configs = ini_get_all();
$system['PHP']      = phpversion();
$system['max_time'] = $configs['max_execution_time']['local_value'];
$system['memory']   = $configs['memory_limit']['local_value'];
$system['system']   = wordwrap(php_uname(),75);
