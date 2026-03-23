<?php

# Start

# Autoload
include WEB . 'vendor/autoload.php';

# Hold headers
ob_start();

# Basic Data, Config & Routes
include APP . 'functions.php';
include APP . 'basics.php';
include APP . 'config.php';
include APP . 'web.php';

# Request stream
$web->init();

if (empty($web->stream)) {
	include APP . 'streams/pages/404.php';
	exit;
}

if (!is_file(APP . 'streams/' . $web->stream . '.php')) {
	exit('There is not such stream');
}

# Stream
include APP . 'streams/' . $web->stream . '.php';
