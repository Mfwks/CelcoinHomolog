<?php

# Load Test

use App\Core\Data;
use App\Core\Migrate;

# Dev Mode: Show Errors
ini_set('display_errors', true);
ini_set('display_startup_erros', true);
error_reporting(E_ALL);

echo '<pre>';

// Migrate::init($conn);
// Migrate::new('db');

// var_dump($db->query('select * from mf_users')); return;

// var_dump($db->table('mf_users')->selectRow());
var_dump($db->table('mf_users')->selectOne(1));
// var_dump($db->table('mf_users')->selectOne(['id' => 2]));
// 
return;

// print_r($conn->query('show tables')->fetchAll()); return;
// print_r($db->query('show tables;')); return;

// var_dump($_SERVER);

// 
// var_dump($db->query('select * from mf_sessions')); return;
// var_dump($db->query('delete from mf_sessions'));

// var_dump($db->table);

// $db2 = clone $db;

// $db2->table('mf_sessions');

// var_dump($db2->table);
// var_dump($db->table);

// return;

// $db->table = '123';

// print_r($db->table);

// $handler->close();

// print_r($db->table);

// return;

// print_r($db->query('CREATE TABLE load_test (id INT AUTO_INCREMENT PRIMARY KEY, timeline INT UNSIGNED DEFAULT (UNIX_TIMESTAMP()), label VARCHAR (36), payload JSON);'));
// print_r($db->query('SHOW CREATE TABLE load_test'));
// print_r($db->query('DROP TABLE IF EXISTS load_test'));

// print_r($db->query('CREATE TABLE mf_meta (id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,meta VARCHAR(32) NOT NULL,meta_key VARCHAR(32) NOT NULL,value TEXT,UNIQUE KEY meta_lock (meta, meta_key)) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;'));
// print_r($db->query('SHOW CREATE TABLE mf_meta'));
// print_r($db->query('DROP TABLE IF EXISTS mf_meta'));
// print_r($db->insert(['meta' => 'system','meta_key' => 'interator', 'value' => 0]));
// var_dump($db->query('select * from mf_meta')); return;
// var_dump($db->increment('value','meta="system" AND meta_key="interator"'));
// var_dump($db->decrement('value','meta="system" AND meta_key="interator"'));
// return;

// print_r($db->query('CREATE TABLE mf_sessions (id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session VARCHAR(40) NOT NULL UNIQUE, access INT(10) UNSIGNED NOT NULL, expires INT(10) UNSIGNED NOT NULL, user_id INT(11) UNSIGNED, data TEXT, active BOOL DEFAULT 1, INDEX idx_session (session(8)), INDEX idx_expires (expires), INDEX idx_active (active), INDEX idx_user_id (user_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;'));
// print_r($db->query('SHOW CREATE TABLE mf_sessions'));
// print_r($db->query('DROP TABLE IF EXISTS mf_sessions'));

// print_r($db->selectAll());

// echo json_encode($db->table('load_test')->selectAll(), JSON_PRETTY_PRINT); return;

$label = $_GET['label'] ?? false;
$content = file_get_contents('php://input');

// print_r($db->table); return;

// print_r($content);

if (!$label and empty($content)) {
	echo 'Nenhuma informação enviada.';
	return;
}

echo 'Registro possivelmente inserido.';

$data['label'] = $label;
$data['payload'] = $content;

var_dump($db->increment('value','meta="system" AND meta_key="interator"'));

print_r($db->table('load_test')->insert($data));
