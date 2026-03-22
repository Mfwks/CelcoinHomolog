<?php

namespace App\Core;

class System
{
    public static function shell()
    {
        $yes = function_exists('shell_exec');
        if (PHP_SAPI == 'cli') {
            echo ($yes ? "Método \033[32mshell_exec\033[0m habilitado" : "Método \033[32mshell_exec\033[0m desabilitado") . PHP_EOL;
        }
        return $yes;
    }

    public static function writable()
    {
        $yes = is_writable(APP) && is_writable(TMP) && is_writable(ASSETS);
        if (PHP_SAPI == 'cli') {
            echo ($yes ? 'Diretórios com permissão de gravação' : 'Diretórios sem permissão de gravação') . PHP_EOL;
        }
        return $yes;
    }

    public static function ext($ext = false)
    {
        if ($ext) {
            $yes = extension_loaded($ext);
            if (PHP_SAPI == 'cli') {
                echo ($yes ? 'Extensão carregada' : 'Extensão ausente') . PHP_EOL;
            }
            return $yes;
        }

        $exts = ['json','mbstring','ctype','openssl','curl','xml','zip','zlib','pdo','pdo_mysql',
        'mysqli','intl','calendar','sodium','hash','fileinfo','gd','exif','bcmath','soap'];

        foreach ($exts as $ext) {
            $yes = extension_loaded($ext);
            if (PHP_SAPI == 'cli') {
                echo ($yes ? "Extensão \033[32m$ext\033[0m carregada" : "Extensão \033[32m$ext\033[0m ausente") . PHP_EOL;
            }
        }
    }

    public static function conn()
    {
        global $conn;
        if (PHP_SAPI == 'cli') {
            echo ($conn ? 'Base de dados conectada' : 'Base de dados desconectada') . PHP_EOL;
        }
        return $conn;
    }
}
