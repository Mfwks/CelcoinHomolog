<?php

# Microframework Build Config

# Environment
define('DEV', true);

# Dev Mode: Show Errors
ini_set('display_errors', true);
ini_set('display_startup_erros', true);
error_reporting(E_ALL);

# Erros Log Output
ini_set('log_errors', 1);
ini_set('error_log', TMP  . 'errors.log');

# XDebug
ini_set('xdebug.var_display_max_depth', 7);

# Database Conection
$database = [
	'driver' => 'mysql',
	'host' 	 => 'localhost',
	'db'	 => 'xxx',
	'user' 	 => 'xxx',
	'psw' 	 => 'xxx'
];

# Execution Time Limit
set_time_limit(0);

# Session Parameters
session_set_cookie_params([
    'lifetime' => 60*60*24*30,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'lax'
]);

ini_set('session.serialize_handler', 'php_serialize');

session_name('mfwks');

# Timezone
date_default_timezone_set('America/Sao_Paulo');
