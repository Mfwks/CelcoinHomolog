<?php

/*
 * Harness dos smokes FUNCIONAIS — os que precisam de HTTP de verdade.
 *
 * Não é um framework de teste (a regra de zero deps continua valendo): é só o
 * boilerplate de subir/derrubar o `php -S`, que já estava escrito uma vez em
 * celcoinv2_paths_smoke.php e ia ser escrito de novo a cada smoke novo. Assertion
 * continua sendo `if` + echo.
 *
 * O nome NÃO termina em `_smoke.php` de propósito: o runner é
 * `for t in tests/*_smoke.php`, e um include seria executado como teste.
 *
 * Quando um smoke precisa de HTTP: quando o comportamento depende do path do request
 * (Cslabs::isV2()), do corpo lido de php://input, ou do tempo/ordem dentro do stream
 * — nada disso se exercita chamando builder.
 */

$SMOKE_FAILS = 0;

function smoke_ok(bool $cond, string $msg): void
{
    global $SMOKE_FAILS;

    echo ($cond ? 'ok: ' : 'FAIL: ') . $msg . "\n";

    if (!$cond) {
        $SMOKE_FAILS++;
    }
}

function smoke_finish(string $nome): void
{
    global $SMOKE_FAILS;

    if ($SMOKE_FAILS > 0) {
        echo "\n{$nome}: {$SMOKE_FAILS} FALHA(S)\n";
        exit(1);
    }

    echo "\n{$nome}: OK\n";
}

/*
 * Sobe o servidor embutido com index.php como ROUTER — é o equivalente ao rewrite
 * do .htaccess; sem ele o php -S não roteia. Devolve o host.
 *
 * $env vira variável de ambiente do SERVIDOR (o processo que executa o stream),
 * não deste script. É por putenv() porque o proc_open sem $env_vars herda o
 * ambiente do pai — passar um array explícito apagaria PATH e o `php` sumiria.
 */
function smoke_serve(int $port, array $env = []): string
{
    $host = '127.0.0.1:' . $port;
    $root = dirname(__DIR__);

    // Se a porta já estiver ocupada, o php -S morre e o teste rodaria contra o serviço
    // alheio — falhando de um jeito confuso. Melhor abortar dizendo o que houve.
    $busy = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
    if ($busy) {
        fclose($busy);
        fwrite(STDERR, "porta {$port} já está em uso — feche o processo e rode de novo\n");
        exit(1);
    }

    foreach ($env as $k => $v) {
        putenv($k . '=' . $v);
    }

    // auto_prepend_file isola o TMP: sem ele o smoke gravaria no SQLite real do mock.
    $server = proc_open(
        'php -d auto_prepend_file=' . escapeshellarg(__DIR__ . '/tmp_isolate.php')
            . ' -S ' . $host . ' index.php',
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $root
    );

    if (!is_resource($server)) {
        fwrite(STDERR, "não foi possível subir o servidor embutido\n");
        exit(1);
    }

    register_shutdown_function(function () use ($server) {
        $status = proc_get_status($server);
        if ($status['running'] ?? false) {
            // O php -S roda como filho do sh; matar o grupo evita deixar a porta presa.
            @exec('pkill -P ' . $status['pid'] . ' 2>/dev/null');
            proc_terminate($server);
        }
        proc_close($server);
    });

    // Espera a porta subir (em vez de sleep fixo).
    for ($i = 0; $i < 50; $i++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($conn) {
            fclose($conn);
            return $host;
        }
        usleep(100000);
    }

    fwrite(STDERR, "servidor embutido não respondeu em 5s\n");
    exit(1);
}

/*
 * Devolve [corpo cru, corpo decodificado, status HTTP].
 *
 * O token importa: sem bearer, Cslabs::boot() deriva o client_id de um fingerprint do
 * request — que varia entre um POST com corpo e um GET. As entidades são escopadas por
 * cliente, então POST e consulta cairiam em namespaces diferentes e a consulta daria
 * 404. O consumidor real sempre manda token; o teste faz o mesmo.
 */
function smoke_http(string $host, string $method, string $uri, ?array $body = null, ?string $token = null, int $timeout = 10): array
{
    $headers = '';

    if ($token !== null) {
        $headers .= "Authorization: Bearer {$token}\r\n";
    }

    $opts = ['http' => [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => $timeout,
    ]];

    if ($body !== null) {
        $headers .= "Content-Type: application/json\r\n";
        $opts['http']['content'] = json_encode($body);
    }

    if ($headers !== '') {
        $opts['http']['header'] = $headers;
    }

    $raw = @file_get_contents('http://' . $host . $uri, false, stream_context_create($opts));
    $raw = $raw === false ? '' : $raw;

    // ignore_errors=true faz o 4xx/5xx voltar com corpo em vez de false — o status só
    // dá pra ler aqui, no $http_response_header que a chamada acima popula.
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('~ (\d{3}) ~', $http_response_header[0] . ' ', $m)) {
        $status = (int) $m[1];
    }

    return [$raw, json_decode($raw, true), $status];
}

function smoke_token(string $host, string $clientId): ?string
{
    $opts = ['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientId . '-secret',
            'grant_type' => 'client_credentials',
        ]),
        'ignore_errors' => true,
        'timeout' => 10,
    ]];

    $raw = @file_get_contents('http://' . $host . '/v5/token', false, stream_context_create($opts));

    return json_decode((string) $raw, true)['access_token'] ?? null;
}

/*
 * PDO no SQLite isolado do smoke. Existe para os poucos casos em que a asserção é
 * sobre o que o mock GRAVOU e não sobre o que respondeu — o accept_then_timeout é o
 * caso-tipo: a ordem "persistiu antes de dormir" não aparece em nenhuma resposta.
 */
function smoke_db(): PDO
{
    $path = __DIR__ . '/tmp_smoke/cslabs/cslabs.sqlite';

    if (!is_file($path)) {
        fwrite(STDERR, "sqlite do smoke não encontrado em {$path}\n");
        exit(1);
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}
