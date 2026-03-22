<?php

use App\Core\System;

$style = '<style>
.status{
  display:inline-flex;
  align-items:center;
  gap:.5rem;
  padding:.45rem .7rem;
  border-radius:999px;
  border:1px solid rgba(0,0,0,.06);
  background: #fff;
  font-weight:600;
  font-family:system-ui,Segoe UI,Roboto,Arial;
  cursor:default;
  box-shadow: 0 4px 12px rgba(0,0,0,.04);
  margin:.25rem;
}
.status span{
  width:12px;
  height:12px;
  border-radius:50%;
  display:inline-block;
  box-shadow: 0 0 0 rgba(0,0,0,0);
  transform-origin:center;
}
.green span{ background:#16a34a; animation:status-ok 1s infinite; }
.red   span{ background:#ef4444; animation:status-danger 1s infinite; }
.gray  span{ background:#9ca3af; }

@keyframes status-ok{
  0%   { opacity:1; transform:scale(1); box-shadow: 0 0 0 0 rgba(0,0,0,0); }
  50%  { opacity:.9; transform:scale(.9); box-shadow: 0 0 12px 4px rgba(0,0,0,0.02); }

}

@keyframes status-danger{
  0%   { opacity:1; transform:scale(1); box-shadow: 0 0 0 0 rgba(0,0,0,0); }
  50%  { opacity:.25; transform:scale(.85); box-shadow: 0 0 12px 4px rgba(0,0,0,0.02); }
  100% { opacity:1; transform:scale(1); box-shadow: 0 0 0 0 rgba(0,0,0,0); }
}
</style>';
	
$opstatus = System::shell();

[$class,$msg] = $opstatus ? ['green','Funções do sistema habilitadas'] : ['red','Funções do sistema desabilitadas'];

$status = '<button class="status ' . $class . '" aria-label="' . $msg . '" 
    data-bs-toggle="tooltip"
    title="' . $msg . '"><span></span>Funções do sistema</button>';

$opstatus = System::writable();

[$class,$msg] = $opstatus ? ['green','Permissão de escrita desabilitada'] : ['red','Permissão de escrita desabilitada'];

$status .= '<button class="status ' . $class . '" aria-label="' . $msg . '" 
    data-bs-toggle="tooltip"
    title="' . $msg . '"><span></span>Permissão de escrita</button>';

$opstatus = System::conn();

[$class,$msg] = $opstatus ? ['green','Base de dados conectado'] : ['gray','Base de dados desconectado'];

$status .= '<button class="status ' . $class . '" aria-label="' . $msg . '" 
    data-bs-toggle="tooltip"
    title="' . $msg . '"><span></span>Base de dados</button>';

$c['title']     = 'Status » ' . $c['site'];
$c['header'] 	= 'Status do sistema';
$c['message'] 	= $style . "\n" . $status;
$c['blink'] 	= 'p"';
$c['off']		= 0;

include VIEWS . 'page.php';
exit;
