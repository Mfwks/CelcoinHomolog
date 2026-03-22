<?php

namespace App\Core;

class Strings
{
	public static function slugify($in)
	{
		return strtolower(trim(preg_replace('~[^0-9a-z]+~i', '-', html_entity_decode(preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', htmlentities($in, ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8')), '-'));
	}

	public static function utf8Filter($in)
	{
		$regex = '/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u';
		return preg_replace($regex,' ', $in);
	}

	public static function distance($in, $list)
	{
		foreach ($list as $line) {
			$n[$line] = levenshtein($in, $line);
		}
		return $n ?? [];
	}

	public static function relevanTerms($terms) # [tmp] 2025-06-22 Sunday: import source from NLS-CX PRECAP
	{
		$terms = array_filter(array_map(function($a) { 
			return (strlen($a)>3) ? $a : null;
		},$terms));
		return $terms;
	}

	public static function stringFilter($in)
	{
		$in = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $in); # Emoticons
		$in = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $in); # Symbols & Pictographs
		$in = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $in); # Transport & Map Symbols
		$in = preg_replace('/[\x{1F700}-\x{1F77F}]/u', '', $in); # Alphabetic Presentation Forms
		$in = preg_replace('/[\x{1F780}-\x{1F7FF}]/u', '', $in); # Geometric Shapes Extended
		return $in;
	}

	public static function formatName($name)
	{
		$name = preg_replace('/[^\p{L}\p{N}\s\'-]/u', '', $name);
		$name = mb_strtolower($name);
		$prepositions = ['de', 'da', 'do', 'dos', 'das', 'e', 'del', 'la', 'las', 'los', 'di', 'du', 'der', 'den', 'des', 'von', 'van', 'of', 'af'];
		$words = explode(' ', $name);
		$capitalized = array_map(function($word) use ($prepositions) {
			return in_array($word, $prepositions) ? $word : self::capName($word);
		}, $words);
		$name = implode(' ', $capitalized);
		return preg_replace_callback('/(?:^|[-\'])(\p{L})/u', function ($matches) {
			return mb_strtoupper($matches[0]);
		}, $name);
	}

	public static function capName($name)
	{
		return mb_strtoupper(mb_substr($name, 0, 1, 'utf-8'), 'utf-8') . mb_substr($name, 1, null, 'utf-8');
	}
}
