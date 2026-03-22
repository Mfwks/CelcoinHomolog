<?php

# Microframework Axis

# Time start
$start_time = microtime(true);

# Application Directories
define('WEB',		__DIR__ . '/');
define('APP', 		WEB . 'app/');
define('ASSETS',	WEB . 'assets/');
define('APIS',		APP	. 'apis/');
define('CRONS',		APP	. 'crons/');
define('COMMANDS',	APP	. 'cmds/');
define('DATA',		APP . 'data/');
define('LIBS',		APP	. 'libs/');
define('PACKS',		APP	. 'packs/');
define('STREAMS',	APP . 'streams/');
define('TMP', 		APP . 'tmp/');
define('VIEWS',		APP . 'views/');
define('WEBHOOKS',	APP . 'webhooks/');
