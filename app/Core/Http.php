<?php

namespace App\Core;

class Http
{
	public static function response($msgdata, $code = 200)
	{
		$response_key = ($code < 400) ? 'response' : 'error';
		$response[$response_key] = $msgdata;
		$code = self::http($code);
		header("HTTP/1.0 $code");
		header("Content-Type: application/json; charset=utf-8");
		exit(Json::pretty($response));
	}

	public static function http($in)
	{
		$n[100] = '100 Continue';
		$n[101] = '101 Switching Protocols';
		$n[102] = '102 Processing';

		$n[200] = '200 OK';
		$n[201] = '201 Created';
		$n[202] = '202 Accepted';
		$n[203] = '203 Non-Authoritative Information';
		$n[204] = '204 No Content';
		$n[205] = '205 Reset Content';
		$n[206] = '206 Partial Content';
		$n[207] = '207 Multi-Status';
		$n[208] = '208 Already Reported';
		$n[226] = '226 IM Used';

		$n[300] = '300 Multiple Choices';
		$n[301] = '301 Moved Permanently';
		$n[302] = '302 Found';
		$n[303] = '303 See Other';
		$n[304] = '304 Not Modified';
		$n[307] = '307 Temporary Redirect';
		$n[308] = '308 Permanent Redirect';

		$n[400] = '400 Bad Request';
		$n[401] = '401 Unauthorized';
		$n[402] = '402 Payment Required';
		$n[403] = '403 Forbidden';
		$n[404] = '404 Not Found';
		$n[405] = '405 Method Not Allowed';
		$n[406] = '406 Not Acceptable';
		$n[407] = '407 Proxy Authentication Required';
		$n[408] = '408 Request Timeout';
		$n[409] = '409 Conflict';
		$n[410] = '410 Gone';
		$n[411] = '411 Length Required';
		$n[412] = '412 Precondition Failed';
		$n[413] = '413 Payload Too Large';
		$n[414] = '414 URI Too Long';
		$n[415] = '415 Unsupported Media Type';
		$n[416] = '416 Range Not Satisfiable';
		$n[417] = '417 Expectation Failed';
		$n[418] = "418 I'm a teapot";
		$n[421] = '421 Misdirected Request';
		$n[422] = '422 Unprocessable Entity';
		$n[423] = '423 Locked';
		$n[424] = '424 Failed Dependency';
		$n[425] = '425 Too Early';
		$n[426] = '426 Upgrade Required';
		$n[428] = '428 Precondition Required';
		$n[429] = '429 Too Many Requests';
		$n[431] = '431 Request Header Fields Too Large';
		$n[451] = '451 Unavailable For Legal Reasons';

		$n[500] = '500 Internal Server Error';
		$n[501] = '501 Not Implemented';
		$n[502] = '502 Bad Gateway';
		$n[503] = '503 Service Unavailable';
		$n[504] = '504 Gateway Timeout';
		$n[505] = '505 HTTP Version Not Supported';
		$n[506] = '506 Variant Also Negotiates';
		$n[507] = '507 Insufficient Storage';
		$n[508] = '508 Loop Detected';
		$n[510] = '510 Not Extended';
		$n[511] = '511 Network Authentication Required';

		return $n[$in] ?? $n[500];
	}

	public static function statusOk($in)
	{
		$out = filter_var($in, FILTER_VALIDATE_URL) ? get_headers($in) : 'This is not a valid URL.';
		if (!empty($out[0]) and (strpos($out[0], ' 20') !== false or strpos($out[0], ' 30') !== false)) {
			return true;
		}
		echo is_array($out) ? $out[0] : $out;
		return false;
	}

	public static function getHttpResponseCode($in)
	{
		$out = get_headers($in);
		return substr($out[0], 9, 3);
	}

	public static function dispatcher($url, $data = '', $method = 'GET', $type = true)
	{

		$curl = curl_init();
		$data = is_string($data) ? $data : json_encode($data);

		$application = ($type) ? 'application/json' : 'application/x-www-form-urlencoded; charset=utf-8';

		$headers = [
			'Content-Type: ' 		 . $application,
			'Content-Length: ' 		 . strlen($data)
		];

		curl_setopt_array($curl, [
			CURLOPT_URL             => $url,
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_ENCODING        => '',
			CURLOPT_MAXREDIRS       => 10,
			CURLOPT_TIMEOUT         => 30,
			CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST   => $method,
			CURLOPT_POSTFIELDS      => $data,
			CURLOPT_HTTPHEADER      => $headers
		]);

		$response = curl_exec($curl);

		$e = curl_error($curl);

		curl_close($curl);

		if ($e) {
			exit(json_encode(['status' => false, 'message' => $e]));
		}

		return $response;
	}
}
