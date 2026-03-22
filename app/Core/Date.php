<?php

# Date Fy (https://www.php.net/manual/en/datetime.format.php)

	# [tmp] 2025-07-21 Monday: work on it

function weekfy($cap = false){
	$week = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 
		'quinta-feira', 'sexta-feira', 'sábado'];
	$date = $week[date('w')] ?? null;
	return ($cap) ? ucfirst($date) : $date;
}

function monthfy($cap = false){
	$month = [null,'janeiro','fevereiro','março','abril','maio','junho',
		'julho','agosto','setembro','outubro','novembro','dezembro'];
	$date = $month[date('n')] ?? null;
	return ($cap) ? ucfirst($date) : $date;
}

# Timelapse

function timefork($timestamp){
	return ($timestamp>=time()) ? foresight($timestamp) : timelapse($timestamp);
}

function timelapse($timestamp) {
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return "Há $diff seg";
    } elseif ($diff < 3600) {
        return 'Há ' . floor($diff / 60) . ' min';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        $min = floor(($diff % 3600) / 60);
        return 'Há ' . $hours . ' hora' . ($hours > 1 ? 's' : '') . ($min > 0 ? ' e ' . $min . ' min' : '');
    }
	return 'Há mais de 24 horas';
}

function foresight($timestamp) {
    $diff = $timestamp - time();
    if ($diff < 0) {
        return 'A qualquer momento';
    } elseif ($diff < 60) {
        return 'Em menos de 1 min';
    } elseif ($diff < 3600) {
        return 'Em aproximadamente ' . floor($diff / 60) . ' min';
    } elseif ($diff < 7200) {
        return 'Em aproximadamente 1 hora';
    } elseif ($diff < 86400) {
        return 'Em aproximadamente ' . floor($diff / 60 / 60) . ' horas';
    } elseif ($diff < 172800) {
        return 'Em aproximadamente 1 dia';
    } elseif ($diff < 2851200) { # 33 dias
        return 'Em aproximadamente ' . floor($diff / 60 / 60 / 24) . ' dias';
    }
	return 'Indiponível';
}

# Date

function smallTimes($in){
    $sec = floor($in%60);
    $sec = $sec ? $sec . ' s' : '';
    $min = minutes($in);
    $min = $min ? $min . ' m' : '';
    $and = ($min AND $sec) ? ' e ' : '';
    return $min . $and . $sec;
}

function minutes($in){
    return floor($in/60);
}

function hours($in){
    return floor($in/3606);
}

function days($in){
    return floor($in/84600);
}

function brDate($in){
    return date('d/m/Y',strtotime($in));
}

function writeDate($in){
	$c['week']  = [null,'Segunda-feira','Terça-feira','Quarta-feira','Quinta-feira',
		'Sexta-feira','Sábado','Domingo'];

	$c['month'] = [null,'Janeiro','Fevereiro','Março','Abril','Maio','Junho',
		'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    [$y,$m,$d,$w]   = explode('-',date('Y-m-d-w',strtotime($in)));
    $d              = (int) $d;
    $m              = (int) $m;
    $month          = isset($c['month'][$m]) ? strtolower($c['month'][$m]) : '';
    $week           = $c['week'][$w] ?? '';
    return "$d de $month de $y. $week.";
}

function socialDate($in){
    $in = date('Y-m-d',strtotime($in));
    $timestamp = strtotime($in);
    if ($timestamp<=time()) {
        return dateSince($timestamp);
    }
    return daysLeft($timestamp);
}

function dateSince($in){
    $data = date('Y-m-d',$in);
    if ($data==date('Y-m-d')) {
        return 'Hoje';
    } else {
        if ($data==date('Y-m-d',time()-86400)) {
            return 'Ontem';
        } else {
            return daysSince($in);
        }
    }
}

function daysSince($in){
    $d = floor((time()-$in)/86400);
    return "Há $d dias";
}

function daysLeft($in){
    $d = ceil(($in-time())/86400);
    if ($d==1) {
        return 'Amanhã';
    }
    return "Em $d dias";
}

function dataDistance($a,$b){
    return abs(round((strtotime($a)-strtotime($b))/86400)) . ' dias';
}


function daysDistance($a,$b){
    return abs(round((strtotime($a)-strtotime($b))/86400));
}

function daysFromToday($in){
    return daysDistance(date('Y-m-d'),$in);
}

# Social Date

function dateNormalize($in){
    return date('d-m-Y',strtotime($in));
}
