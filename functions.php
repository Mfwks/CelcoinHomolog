<?php

# Functions

function consultarChaveAntigo($in){
    $key = $in['key'] ?? 'sucesso@pix.com';
    if ($key=='erro@pix.com') {
        $data['code'] = 'NNN';
        $data['description'] = 'QUALQUER OUTRO ERRO (API antiga).';
        return json_encode($data);
    }
    if ($key=='fraude@pix.com') {
        $data['code'] = '422';
        $data['description'] = 'CHAVE PIX COM DADOS RESTRITOS POR MARCAÇÃO DE FRAUDE (API antiga).';
        return json_encode($data);
    }
    $data['endtoendid'] = 'endtoendid';
    $data['account']['accountNumber'] = '127200';
    $data['owner']['taxIdNumber'] = '06170097914';
    $data['code'] = '200';

    $data['owner']['name'] = 'Daniel Eskelsen';
    $data['account']['participant'] = '487';
    $data['account']['branch'] = '0001';
    $data['account']['accountType'] = 'N';
    $data['key'] = $key;
    $data['keyType'] = 'email';

    $data['description'] = 'CONSULTA COM SUCESSO (API antiga).';
    return json_encode($data);
}

function consultarChave($in){
    $key = $in['key'] ?? 'sucesso@pix.com';
    if ($key=='erro@pix.com') {
        $data['status'] = 'ERROR';
        $data['code']['errorCode'] = 'OUTROCODIGO';
        $data['code']['message'] = 'Outro erro genérico';
        $data['version'] = '1.0.0';
        return json_encode($data);
    }
    if ($key=='fraude@pix.com') {
        $data['status'] = 'ERROR';
        $data['code']['errorCode'] = 'CPD0013';
        $data['code']['message'] = 'Chave Pix com dados restritos por marcação de fraude';
        $data['version'] = '1.0.0';
        return json_encode($data);
    }
    $data['endtoEndId'] = 'endtoendid';
    $data['owner']['name'] = 'Daniel Eskelsen';
    $data['owner']['documentNumber'] = '06170097914';
    $data['account']['account'] = '127200';
    $data['account']['participant'] = '487';
    $data['account']['branch'] = '0001';
    $data['account']['accountType'] = 'N';
    $data['key'] = $key;
    $data['keyType'] = 'email';
    $data['description'] = 'CONSULTA COM SUCESSO.';
    return json_encode($data);
}

// remaining

function criarOuDeletarChave($data){
    if (!$data) {
		header('Content-Type: application/json');
        exit('{"error": "Dados de payload ausente"}');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method=='DELETE') {
        return '{"status": true}'; # [tmp]
    }
    return '{"key": "' . gerarHashMock() . '"}'; # [tmp]
}

function gerarQRCode(){
    return json_encode([
        "emv" => '00020101021126400014br.gov.bcb.pix0118eskelsen@yahoo.com52040000530398654040.015802BR5915DANIEL ESKELSEN6009SAO PAULO6219051512312312312312363045134',
        "image" => "iVBORw0KGgoAAAANSUhEUgAAARgAAAEYAQMAAAC9QHvPAAAABlBMVEX///8AAABVwtN+AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAEk0lEQVRogc2aQY6rQAxEjVj0khuEm8C1WCCBxMXgJj03YMkC0b+eO9Gf5awiR5E+P9RIMbbLVe6Y/eG1lFLy2eZy5HScy2OzPuMyPV3Jne4+X8VMZgJswpTLunUsa6/3NZiN+stuNRviYUYBzqWkvbex3Hr3vPfOjLi2cgXF8MiL4rL2KKvp32ssV8ONwJibG4Jtb6zSUnYlKSiG+jlnRZKtKXdzWn8t5RpPhXbbrxoLhKl9OvfpyNfQbQeXG5dq1fn1u5fjYPylolHNK5h5VFyJdHSKZ/5NeJEw3qdtUSJs6O5GgUA2hxq3o2WNuL6JaVQYuhRWD3cVzJK4zrrkOVAw8TAaHVtRCeiSCAa6zuA6xbVRFurFcJi9W3vdIBdqx4chCHucquf26TwX0TAT404cZ2MWI9+DtWK8rCGoql4bkjSEw2jcrb3IQtSmYGblIoufFa1KaHt0z5swFmbRI88EU8sEsoCfm5IYL+eSI2J2fcrXRxUJ6TVvVyNdZ+Ln1SJikJym4ScmPMq2q+alkfQ/VB58+GVMIv9iYs2LtPO8xW7qQWnjHR40gomGmQYRnc0MtlLFfKmYT1zwRjAMNUFcgqielQ4NlBb9UyC6KuaiYaaxfF6ag4TWJw9G92b3J084jJhYdLdBzl4xouq0GvVTTntV5ouGwZRKFSHbVUL3QPnf6A0kfSauPRxmQllKHl/Qy+niXfWTas1bTEwdHUy+XgmAootmCv5U9hoYufgiZqIHpR7S06PS9Kjt0phzfvYZFxCzHOVzicdHz+s5q8Srni8RMf7I5UGqbL+pFFEHev4o90u14WuVYBjWDwrGmlwxeM/CgETPwxvxMJqDojbVj1P06nG1XOoDeZC2WEBMnSlZcZlHWYoLI3LhmxXnllgYdJBTSJMvxst7+NUNlk/FJxxmatSkGOkjMw8P0qJR+DBT1LWre6uvYqBhfUWet7tNakOdSQ82Z50p8TCMX5Vv9ocMRa/m3pOdsGuJaBjX7FLBwlC58MZ7/0NhvP1yMIy858KuUj0ozExGsJ8De2zqp2c3GAxT9bxuDAwSX16az25U56ursQfDYDzYBkpvKK7l7fUSOyty4e45GmZSofesBw/WVitPH133oDdW7tXdeyjM8lTnD5vIXnufFsaLexDc6vNVjA11F+KS/Fx2Lm8vYifBt1+OhZnY9AkmOpaIv4daHmwux8p18HMwTH1RG2g22ZC2Yk7Ww053TzhMcl2GGmZx5r4JJyrv6dWNDQ2H4WDD7KPn14a43C8n3w23ruSCYVQ/S65SmEUKjkQfsKuUnv+p+9VoGC9xhJHnoj04Q5yx+2QG3xQQYyQCKTRmXwhXVZyOE5Xnev75NsbwnnVFsRU/h831BEbc/fnOoTCLjxAksO920GycbR3uOtihfc5qA2EmLBAepNIwqjOt71XQ+nrvrKJhRvf4/juZvXpPzBvrYYVYSlwMR1j/92xuOPF67+YMiuG3ELJ74mcnEs49vU+3HBFD/SDfDqqb38lgltLuA/KHbdAeDuN9ynmc+PCpGknqQ0GJHVfO+C8Lh/nD6x8G44oclJyMTgAAAABJRU5ErkJggg==",
        "image2" => "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAARgAAAEYAQMAAAC9QHvPAAAABlBMVEX///8AAABVwtN+AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAEk0lEQVRogc2aQY6rQAxEjVj0khuEm8C1WCCBxMXgJj03YMkC0b+eO9Gf5awiR5E+P9RIMbbLVe6Y/eG1lFLy2eZy5HScy2OzPuMyPV3Jne4+X8VMZgJswpTLunUsa6/3NZiN+stuNRviYUYBzqWkvbex3Hr3vPfOjLi2cgXF8MiL4rL2KKvp32ssV8ONwJibG4Jtb6zSUnYlKSiG+jlnRZKtKXdzWn8t5RpPhXbbrxoLhKl9OvfpyNfQbQeXG5dq1fn1u5fjYPylolHNK5h5VFyJdHSKZ/5NeJEw3qdtUSJs6O5GgUA2hxq3o2WNuL6JaVQYuhRWD3cVzJK4zrrkOVAw8TAaHVtRCeiSCAa6zuA6xbVRFurFcJi9W3vdIBdqx4chCHucquf26TwX0TAT404cZ2MWI9+DtWK8rCGoql4bkjSEw2jcrb3IQtSmYGblIoufFa1KaHt0z5swFmbRI88EU8sEsoCfm5IYL+eSI2J2fcrXRxUJ6TVvVyNdZ+Ln1SJikJym4ScmPMq2q+alkfQ/VB58+GVMIv9iYs2LtPO8xW7qQWnjHR40gomGmQYRnc0MtlLFfKmYT1zwRjAMNUFcgqielQ4NlBb9UyC6KuaiYaaxfF6ag4TWJw9G92b3J084jJhYdLdBzl4xouq0GvVTTntV5ouGwZRKFSHbVUL3QPnf6A0kfSauPRxmQllKHl/Qy+niXfWTas1bTEwdHUy+XgmAootmCv5U9hoYufgiZqIHpR7S06PS9Kjt0phzfvYZFxCzHOVzicdHz+s5q8Srni8RMf7I5UGqbL+pFFEHev4o90u14WuVYBjWDwrGmlwxeM/CgETPwxvxMJqDojbVj1P06nG1XOoDeZC2WEBMnSlZcZlHWYoLI3LhmxXnllgYdJBTSJMvxst7+NUNlk/FJxxmatSkGOkjMw8P0qJR+DBT1LWre6uvYqBhfUWet7tNakOdSQ82Z50p8TCMX5Vv9ocMRa/m3pOdsGuJaBjX7FLBwlC58MZ7/0NhvP1yMIy858KuUj0ozExGsJ8De2zqp2c3GAxT9bxuDAwSX16az25U56ursQfDYDzYBkpvKK7l7fUSOyty4e45GmZSofesBw/WVitPH133oDdW7tXdeyjM8lTnD5vIXnufFsaLexDc6vNVjA11F+KS/Fx2Lm8vYifBt1+OhZnY9AkmOpaIv4daHmwux8p18HMwTH1RG2g22ZC2Yk7Ww053TzhMcl2GGmZx5r4JJyrv6dWNDQ2H4WDD7KPn14a43C8n3w23ruSCYVQ/S65SmEUKjkQfsKuUnv+p+9VoGC9xhJHnoj04Q5yx+2QG3xQQYyQCKTRmXwhXVZyOE5Xnev75NsbwnnVFsRU/h831BEbc/fnOoTCLjxAksO920GycbR3uOtihfc5qA2EmLBAepNIwqjOt71XQ+nrvrKJhRvf4/juZvXpPzBvrYYVYSlwMR1j/92xuOPF67+YMiuG3ELJ74mcnEs49vU+3HBFD/SDfDqqb38lgltLuA/KHbdAeDuN9ynmc+PCpGknqQ0GJHVfO+C8Lh/nD6x8G44oclJyMTgAAAABJRU5ErkJggg=="
    ],JSON_PRETTY_PRINT);
}

function gerarToken(){
    return json_encode([
        'expires_in'    => time() + 60,
        'access_token'  => gerarHashMock()
    ],JSON_PRETTY_PRINT);
}


function saldo(){
    return '{"balance": 3000000.00}';
}

function gerarHashMock(){
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    return $uuid;
}

function retriveX($emv){
	$data = decode_brcode($emv);
	$data = array_filter($data);
	$static = $data['26']['01'] ?? null;
	$dynamic = $data['26']['25'] ?? null;
	$key = $static ? $static : $dynamic;
	$idTx = $data['62']['05'] ?? '';
	return [$key,$idTx];
} 

function decode_brcode($brcode){
   # Autor: Eng. Renato Monteiro Batista
   $n=0;
   while($n < strlen($brcode)) {
		$codigo=substr($brcode,$n,2);
		$n += 2;
		$tamanho=intval(substr($brcode,$n,2));
		if (!is_numeric($tamanho)) {
			return false;
		}
		$n += 2;
		$valor=substr($brcode,$n,$tamanho);
		$n+=$tamanho;
		if (preg_match("/^[0-9]{4}.+$/",$valor) && ($codigo != 54)){
			$retorno[$codigo]=decode_brcode($valor);
		} else {
			$retorno[$codigo]="$valor";
		}
   }
   return $retorno;
}

function obterTermos(){
    return geraTPMock('termo_de_uso.pdf');
}

function obterPolitica(){
    return geraTPMock('politica_de_privacidade.pdf');
}

function geraTPMock($type){
    return json_encode([
        'result' => [
            'regulatoryDocuments' => [
                [
                    'regDocObj' => 'https://mfwks.com/labs/dock/' . $type,
                    'token' => gerarHashMock()
                ]
            ]
        ]
    ],JSON_PRETTY_PRINT);
}

function aceitarTermos(){
    return json_encode(['message' => 'accepted success'],JSON_PRETTY_PRINT);
}

function enviarPix(){
	return '{"status":"ERROR","error":{"errorCode":"CBE171","message":"Transação bloqueada por suspeita de fraude. Contate o suporte para mais informações."},"version":"1.0.0"}';
}
