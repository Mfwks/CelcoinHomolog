<?php

namespace App\Core;

class Web
{
	public array $routes;
	
	public string $url;
	public string $map;
	public string $stream;
	public string $acl;
	
	public object $args;

	public function init($in = BASE)
	{
		$call = $this->request($in);
		return $this->search($call);
	}
	
	public function add($map, $stream = false, $acl = false)
	{
		$map = ($map=='/') ? '/' : '/' . trim($map, '/') . '/';
		$this->routes[] = (object) ['map' => $map, 'stream' => $stream, 'acl' => $acl];
	}
	
	private function request($in = '')
	{
		$in = trim($in,'/');
		$request = $_SERVER['REQUEST_URI'] ?? '/';
		$mid = preg_replace('/^\/' . preg_quote($in, '/') . '/', '', parse_url($request, PHP_URL_PATH));
		return ($mid AND $mid!='/') ? '/' . trim($mid, '/') . '/' : '/';
	}
	
	private function search($in)
	{
		if (empty($this->routes)) {
			return false;
		}
		foreach ($this->routes as $route) {
			if ($args = $this->match($in,$route->map)) {
				$this->url 		= $in;
				$this->map 		= $route->map;
				$this->stream 	= $route->stream;
				$this->acl 		= $route->acl;
				if (is_object($args)) {
					$this->args = $args;
				}
				return $this;
			}
		}
		return false;
	}
	
	private function match($url,$pattern)
	{
		$map = '#^' . preg_replace('/{[^}]+}/', '([^/]+)', $pattern) . '$#';

		if (!preg_match($map, $url, $matches)) {
			return false;
		}
		
		if (!(preg_match_all('/{([^}]+)}/', $pattern, $fixas))) {
			return true;
		}

		$labels = $fixas[1];

		unset($matches[0]);

		$len = min(count($matches), count($labels));

		return (object) array_combine(array_slice($labels, 0, $len), array_slice($matches, 0, $len));
	}
}
