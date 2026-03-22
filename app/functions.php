<?php

# Functions

# [tmp] 2025-07-05 Saturday: move to another place, separate functions

function vd($in){
	echo '<pre>';
	if (func_num_args()==1) {
		var_dump($in);
		echo '</pre>';
		return;
	}
	foreach (func_get_args() as $in) {
		var_dump($in);
	}
    echo '</pre>';
}

function mrk(){
	$_ENV['mrk'] = empty($_ENV['mrk']) ? 1 : ($_ENV['mrk'] + 1);
	echo PHP_EOL . $_ENV['mrk'] . PHP_EOL;
}

function redirect($in = '/'){
	header('Location: ' . url($in));
}

function refresh($in = '/', $time = 3){
	header("Refresh: $time; " . url($in));
}

function img($in){
	return url($in) . '?t=' . time();
}

function url($path = ''){
	return SITE . rtrim(BASE,'/') . '/' . ltrim($path,'/');
}

function url_query($path, $add_query){
	$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '';
	parse_str($query, $old_data);
	$data = array_merge($old_data, $add_query);
	return url($path) . '?' . http_build_query($data);
}

function completeRequest(){
    return urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
}

function dinamicUrl($path = ''){
    return $_SERVER["REQUEST_SCHEME"] . '://' . $_SERVER["HTTP_HOST"] . '/' . ltrim($path,'/');
}

function ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = $_SERVER[$k];
            if ($k === 'HTTP_X_FORWARDED_FOR' && str_contains($ip, ',')) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}
