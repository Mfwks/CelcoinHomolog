<?php

namespace App\Core;

class Json
{
	public static function pretty($in){
		return json_encode($in, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
	}

	public static function read($file){
		$ctn = is_file($file) ? file_get_contents($file) : false;
		return ($ctn) ? json_decode($ctn, 1) : false;
	}

	public static function write($file, $data){
		$mid = is_string($data) ? json_decode($data, 1) : $data;
		$ctn = self::pretty($mid);
		return ($ctn) ? file_put_contents($file, $ctn) : false;
	}

	public static function valid($in) {
		json_decode($in);
		return (json_last_error() == JSON_ERROR_NONE);
	}
}
