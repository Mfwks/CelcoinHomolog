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

$json = file_get_contents('php://input');

if ($json) {
    file_put_contents(WEB . 'logs/' . date('YmdHi') . '_request.log', $json);
}

ob_start(function ($buffer) {
    file_put_contents(WEB . 'logs/' . date('YmdHi') . '_response.log', $buffer);
    return $buffer;
});

# Request stream
$web->init();

if (empty($web->stream)) {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: application/json');
    $data = [
        'status' => 'ERROR',
        'error' => [
            'errorCode' => 'CSLAB404',
            'message' => 'Registro ou funcionalidade inexistente.',
        ],
        'version' => '1.0.0',
    ];
    echo json_encode($data, JSON_PRETTY_PRINT);
	exit;
}

if (!is_file(APP . 'streams/' . $web->stream . '.php')) {
	exit('There is not such stream');
}

# Stream
include APP . 'streams/' . $web->stream . '.php';
