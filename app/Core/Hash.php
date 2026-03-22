<?php

namespace App\Core;

class Hash
{
	private $db;
	
	public function __construct($db)
	{
		$this->db = $db; # [tmp] 2025-10-13 Monday: work on it
	}

	
	
	public function identifier(){
		return hash('fnv1a32',time() . rand(0,10000));
	}

	public function uniqueHash($t,$f,$alg = 'fnv1a32'){
		$hash = hash($alg,uniqid());
		$exists = $this->db->field($t,$f,"WHERE $f=?",[$hash]);
		return ($exists) ? $this->uniqueHash($t,$f,$alg) : $hash;
	}

	public function uniqueCode($t,$f,$alg = 'fnv1a32'){
		$hash = strtoupper(hash($alg,uniqid()));
		$exists = $this->db->field($t,$f,"WHERE $f='$hash'");
		return ($exists) ? $this->uniqueCode($t,$f,$alg) : $hash;
	}

	public function locatorGenerator($size = 6, $type = 2){
		if (!$size) {
			return false;
		}
		$range[0] = array_merge(range(0,9),range('A','Z'));
		$range[1] = range('A','Z');
		$range[2] = range(0,9);
		$string = $range[$type] ?? $range[2];
		$max = count($string) - 1;
		for ($i=0;$i<$size;$i++) {
			$n[] = $string[rand(0,$max)];
		}
		return empty($n) ? false : implode('',$n);
	}

	public static function b62enc($n){
		$c='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
		$r='';
		while($n>0){$r=$c[$n%62].$r;$n=intdiv($n,62);}
		return $r;
	}

	public static function b62dec($s){
		$c='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
		$n=0;
		for($i=0;$i<strlen($s);$i++) $n=$n*62+strpos($c,$s[$i]);
		return $n;
	}

	public static function b62obs($n,$key=0xA7F39B2C51){
		return self::b62enc($n ^ $key);
	}

	public static function b62deobs($s,$key=0xA7F39B2C51){
		return self::b62dec($s) ^ $key;
	}
}
