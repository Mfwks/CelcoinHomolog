<?php

namespace App\Core;

class Cslabs
{
    private static ?array $context = null;

    /** Webhooks que esta request deixou de agendar — ver noteWebhookSkipped(). */
    private static array $webhookSkips = [];

    public static function boot(array $meta = []): array
    {
        if (self::$context !== null) {
            return self::$context;
        }

        $headers = self::headers();
        $authorization = $headers['Authorization'] ?? '';
        $bearer = self::extractBearerToken($authorization);
        $rawBody = file_get_contents('php://input') ?: '';
        $body = self::parseRequestBody($rawBody, $headers);
        $credentialSeed = self::credentialSeed($body);
        $resolvedClientId = $bearer ? self::resolveClientIdFromToken($bearer) : null;
        $clientSeed = $credentialSeed ?: ($bearer ?: self::requestFingerprint($headers));
        $clientId = $resolvedClientId ?: self::clientIdFromSeed($clientSeed);
        $ip = self::requestIp();

        $workerId = self::resolveWorkerId($clientId, $ip, $headers);
        $requestId = 'req_' . bin2hex(random_bytes(8));

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        error_log($path);
        $path = str_replace('/celcoin/','/',$path);
        error_log($path);
        $receivedAtMicro = microtime(true);
        self::$context = [
            'request_id' => $requestId,
            'client_id' => $clientId,
            'worker_id' => $workerId,
            'auth_hint' => $bearer ? substr($bearer, -8) : null,
            'identity_source' => $resolvedClientId ? 'bearer_link' : ($credentialSeed ? 'client_credentials' : ($bearer ? 'bearer_raw' : 'request_fingerprint')),
            'authorization' => $authorization ? '[redacted]' : null,
            'ip' => $ip,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'path' => $path,
            'query' => $_GET,
            'headers' => self::sanitizeHeaders($headers),
            'received_at' => date(DATE_ATOM, (int) $receivedAtMicro),
            'received_at_us' => $receivedAtMicro,
            'raw_body' => $rawBody,
            'body' => $body,
            'meta' => $meta,
        ];

        return self::$context;
    }

    public static function context(): array
    {
        return self::$context ?? self::boot();
    }

    /*
     * V1 e V2 compartilham streams em vários pontos (aliases em web.php), mas a
     * Celcoin real responde shapes DIFERENTES nos dois: a v1 é plana, a V2 vem
     * no envelope {status, version, body}. Como os consumidores v1 leem caminhos
     * fixos no topo (ex.: CelcoinPix::consultaChavePix lê $dict->endtoEndId), não
     * dá pra mudar o shape do stream inteiro — os builders continuam devolvendo
     * o shape v1/plano e os streams envelopam só quando a chamada veio por
     * /baas/v2/*. Ver HOMOLOGACAO_CELCOIN_V2.md §1.1.
     */
    public static function isV2(): bool
    {
        $path = self::context()['path'] ?? '';
        return strpos($path, '/baas/v2/') === 0;
    }

    /*
     * Envelope real da V2. A ordem dos campos varia na Celcoin real, então não
     * vale a pena fixar — o que importa é status/version/body.
     */
    public static function v2Envelope(array $body, string $status = 'SUCCESS', string $version = '1.0.0'): array
    {
        return [
            'status' => $status,
            'version' => $version,
            'body' => $body,
        ];
    }

    public static function infoUrl(?string $clientId = null): string
    {
        $clientId ??= self::context()['client_id'];
        return url('shots/' . $clientId . '/');
    }

    public static function rawBody(): string
    {
        return self::context()['raw_body'];
    }

    public static function requestBody(): array|string|null
    {
        return self::context()['body'];
    }

    public static function header(string $name): ?string
    {
        $headers = self::context()['headers'] ?? [];

        foreach ($headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    public static function injectInfoIntoJson(string $buffer): string
    {
        $decoded = json_decode($buffer, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            return $buffer;
        }

        $decoded['cslabs_info'] = self::infoUrl();

        return Json::pretty($decoded) ?: $buffer;
    }

    public static function finalizeInteraction(string $responseBody): void
    {
        $context = self::context();
        $statusCode = http_response_code() ?: 200;
        $payload = [
            'request_id' => $context['request_id'],
            'client_id' => $context['client_id'],
            'worker_id' => $context['worker_id'],
            'auth_hint' => $context['auth_hint'],
            'identity_source' => $context['identity_source'],
            'received_at' => $context['received_at'],
            'received_at_us' => $context['received_at_us'] ?? null,
            'method' => $context['method'],
            'path' => $context['path'],
            'ip' => $context['ip'],
            'query' => $context['query'],
            'headers' => $context['headers'],
            'request' => $context['body'],
            'response' => [
                'status_code' => $statusCode,
                'headers' => headers_list(),
                'body' => self::decodePayload($responseBody),
            ],
            'meta' => [
                'info_url' => self::infoUrl($context['client_id']),
                'stream' => $context['meta']['stream'] ?? null,
            ],
        ];

        /*
         * Os que NÃO saíram entram no mesmo `webhooks[]` que o painel já renderiza,
         * ao lado dos entregues. Vai aqui e não no appendWebhookToInteraction porque
         * a linha da interação só nasce nesta função — durante a request ela ainda
         * não existe, e o append cairia no `return` de "não achei a interação".
         */
        if (self::$webhookSkips !== []) {
            $payload['webhooks'] = self::$webhookSkips;
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(<<<'SQL'
            INSERT INTO interactions
                (client_id, request_id, received_at, received_at_us, worker_id, method, path, status_code, data)
            VALUES
                (:c, :r, :ra, :ru, :w, :m, :p, :s, :d)
            ON CONFLICT(client_id, request_id) DO UPDATE SET
                received_at    = excluded.received_at,
                received_at_us = excluded.received_at_us,
                worker_id      = excluded.worker_id,
                method         = excluded.method,
                path           = excluded.path,
                status_code    = excluded.status_code,
                data           = excluded.data
        SQL);
        $stmt->execute([
            ':c'  => $context['client_id'],
            ':r'  => $context['request_id'],
            ':ra' => $context['received_at'],
            ':ru' => $context['received_at_us'],
            ':w'  => $context['worker_id'],
            ':m'  => $context['method'],
            ':p'  => $context['path'],
            ':s'  => $statusCode,
            ':d'  => Json::pretty($payload),
        ]);

        self::upsertWorker(
            $context['client_id'],
            $context['worker_id'],
            $context['ip'],
            $context['auth_hint'],
            $context['received_at']
        );

        self::touchWorkerOrigin($context['client_id'], $context['ip'], $context['worker_id'], $context['headers']);
    }

    public static function writeEntity(string $type, string $entityId, array $data, ?string $clientId = null): string
    {
        $clientId ??= self::context()['client_id'];
        $safeType = self::safeName($type);
        $safeId = self::safeName($entityId);
        $now = date(DATE_ATOM);
        $payload = Json::pretty($data);
        $entityKey = (string) ($data['entity'] ?? '');

        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT created_at FROM entities WHERE client_id = :c AND type = :t AND id = :i');
        $stmt->execute([':c' => $clientId, ':t' => $safeType, ':i' => $safeId]);
        $existing = $stmt->fetchColumn();
        $createdAt = $existing !== false ? (string) $existing : $now;

        $upsert = $pdo->prepare(<<<'SQL'
            INSERT INTO entities (client_id, type, id, entity_key, data, created_at, updated_at)
            VALUES (:c, :t, :i, :k, :d, :ca, :ua)
            ON CONFLICT(client_id, type, id) DO UPDATE SET
                entity_key = excluded.entity_key,
                data = excluded.data,
                updated_at = excluded.updated_at
        SQL);
        $upsert->execute([
            ':c' => $clientId,
            ':t' => $safeType,
            ':i' => $safeId,
            ':k' => $entityKey,
            ':d' => $payload,
            ':ca' => $createdAt,
            ':ua' => $now,
        ]);

        return sprintf('sqlite://entities/%s/%s/%s', $clientId, $safeType, $safeId);
    }

    /**
     * Lê uma entidade IGNORANDO o escopo de cliente.
     *
     * Existe só para as telas do próprio mock (`pixqrcode/v2/…`): o QR é criado
     * pelo app, com bearer, e fica no client_id dele; o navegador não manda
     * bearer nenhum e cairia em outro escopo, levando 404 na hora de ver o QR.
     *
     * NÃO usar em stream que imita endpoint da Celcoin — lá o isolamento por
     * cliente é o comportamento correto e some com a separação entre tenants.
     */
    public static function readEntityAnyClient(string $type, string $entityId): array|false
    {
        $stmt = Db::pdo()->prepare(
            'SELECT data FROM entities WHERE type = :t AND id = :i ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([
            ':t' => self::safeName($type),
            ':i' => self::safeName($entityId),
        ]);
        $row = $stmt->fetchColumn();

        if ($row === false) {
            return false;
        }

        $decoded = json_decode((string) $row, true);

        return is_array($decoded) ? $decoded : false;
    }

    /**
     * client_id dono de uma entidade. Complementa readEntityAnyClient: as telas
     * do mock precisam ESCREVER no escopo de quem criou a cobrança (o app), e
     * não no do navegador, senão a baixa fica invisível para o app.
     */
    public static function entityOwnerClient(string $type, string $entityId): ?string
    {
        $stmt = Db::pdo()->prepare(
            'SELECT client_id FROM entities WHERE type = :t AND id = :i ORDER BY updated_at DESC LIMIT 1'
        );
        $stmt->execute([':t' => self::safeName($type), ':i' => self::safeName($entityId)]);
        $row = $stmt->fetchColumn();

        return $row === false ? null : (string) $row;
    }

    public static function readEntity(string $type, string $entityId, ?string $clientId = null): array|false
    {
        $clientId ??= self::context()['client_id'];
        $stmt = Db::pdo()->prepare('SELECT data FROM entities WHERE client_id = :c AND type = :t AND id = :i LIMIT 1');
        $stmt->execute([
            ':c' => $clientId,
            ':t' => self::safeName($type),
            ':i' => self::safeName($entityId),
        ]);
        $row = $stmt->fetchColumn();
        if ($row === false) {
            return false;
        }
        $decoded = json_decode((string) $row, true);
        return is_array($decoded) ? $decoded : false;
    }

    public static function deleteEntity(string $type, string $entityId, ?string $clientId = null): bool
    {
        $clientId ??= self::context()['client_id'];
        $stmt = Db::pdo()->prepare('DELETE FROM entities WHERE client_id = :c AND type = :t AND id = :i');
        $stmt->execute([
            ':c' => $clientId,
            ':t' => self::safeName($type),
            ':i' => self::safeName($entityId),
        ]);
        return $stmt->rowCount() > 0;
    }

    public static function purgeClient(string $clientId): array
    {
        if ($clientId === '' || $clientId === '__global__') {
            return ['deleted' => [], 'client_id' => $clientId, 'ok' => false];
        }

        return Db::transaction(function (\PDO $pdo) use ($clientId): array {
            $deleted = [];

            foreach (['entities', 'interactions', 'client_workers', 'client_origins', 'webhook_dispatches'] as $table) {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE client_id = :c");
                $stmt->execute([':c' => $clientId]);
                $deleted[$table] = $stmt->rowCount();
            }

            $tokens = $pdo->prepare(<<<'SQL'
                DELETE FROM entities
                WHERE client_id = '__global__'
                  AND type = 'issued_tokens'
                  AND json_extract(data, '$.client_id') = :c
            SQL);
            $tokens->execute([':c' => $clientId]);
            $deleted['issued_tokens'] = $tokens->rowCount();

            return ['deleted' => $deleted, 'client_id' => $clientId, 'ok' => true];
        });
    }

    public static function listEntities(string $type, ?string $clientId = null): array
    {
        $clientId ??= self::context()['client_id'];
        $stmt = Db::pdo()->prepare(<<<'SQL'
            SELECT data
              FROM entities
             WHERE client_id = :c
               AND type = :t
             ORDER BY entity_key
        SQL);
        $stmt->execute([':c' => $clientId, ':t' => self::safeName($type)]);

        $items = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return $items;
    }

    public static function listInteractions(string $clientId): array
    {
        $stmt = Db::pdo()->prepare(<<<'SQL'
            SELECT data FROM interactions
            WHERE client_id = :c
            ORDER BY received_at_us DESC, request_id DESC
        SQL);
        $stmt->execute([':c' => $clientId]);

        $items = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return $items;
    }

    public static function findInteraction(string $clientId, string $requestId): array|false
    {
        $stmt = Db::pdo()->prepare('SELECT data FROM interactions WHERE client_id = :c AND request_id = :r LIMIT 1');
        $stmt->execute([':c' => $clientId, ':r' => $requestId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return false;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : false;
    }

    public static function registerIssuedToken(string $token, array $meta = []): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        $context = self::context();
        $tokenHash = hash('sha256', $token);

        self::writeEntity('issued_tokens', $tokenHash, [
            'token_hash' => $tokenHash,
            'client_id' => $context['client_id'],
            'issued_at' => date(DATE_ATOM),
            'auth_hint' => substr($token, -8),
            'source' => 'v5_token',
            'meta' => $meta,
        ], '__global__');
    }

    public static function knownWebhookEntities(): array
    {
        return [
            'onboarding-create',
            'internal-transfer-out',
            'pix-payment-out',
            'pix-payment-in',
            'pix-dict-claim-open',
            'pix-dict-claim-waiting',
            'pix-dict-claim-confirmed',
            'pix-dict-claim-cancelled',
            'pix-dict-claim-completed',
            'spb-transfer-out',
            'spb-transfer-in',
            'spb-reversal-in',
            'spb-reversal-out',
            'charge-create',
            'charge-canceled',
            'billpayment',
            'billpayment-occurrence',
            'judicial-movement-in',
            'judicial-movement-out',
            'kyc',
            'pix-med-balance-blocked',
            'pix-med-balance-unblocked',
        ];
    }

    public static function scenarioFromValue(mixed $value, string $default = 'success'): string
    {
        $text = strtolower(trim((string) $value));

        if ($text === '') {
            return $default;
        }

        $map = [
            // Primeiro do mapa de propósito: o match abaixo é por substring, e este é
            // o único slug composto — deixá-lo no fim abriria a chance de um needle
            // curto de outro cenário mordê-lo antes.
            'accept_then_timeout' => ['accept_then_timeout', 'accept-then-timeout', 'aceita-e-pendura'],
            'fraud' => ['fraude', 'fraud', 'suspeita', 'restrito', 'restricted'],
            'error' => ['erro', 'error', '500', 'outroerro'],
            'failed' => ['falha', 'fail', 'failed', 'rejeitado', 'rejected'],
            'not_found' => ['404', 'notfound', 'not-found', 'inexistente', 'naoencontrado', 'nao-encontrado'],
            'blocked' => ['bloqueio', 'bloqueado', 'blocked', 'block'],
        ];

        foreach ($map as $scenario => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $scenario;
                }
            }
        }

        return $default;
    }

    public static function scenarioFromPayload(array $payload, array $fields, string $default = 'success'): string
    {
        foreach ($fields as $field) {
            $value = self::arrayGet($payload, $field);

            if (in_array($field, ['amount', 'value'], true)) {
                // Amount usa exclusivamente o catálogo magic-cents (valores < R$ 1,00).
                // Valores >= R$ 1,00 seguem sucesso e NUNCA passam pelo match de
                // palavra-chave — senão um amount como "1500" casaria o needle "500"
                // do cenário `error` e devolveria CBE500 (docs/scenarios.md §1).
                $scenario = self::scenarioFromAmount($value, '');

                if ($scenario !== '') {
                    return self::rememberScenario($scenario);
                }

                continue;
            }

            $scenario = self::scenarioFromValue($value, '');

            if ($scenario !== '') {
                return self::rememberScenario($scenario);
            }
        }

        return self::rememberScenario($default);
    }

    /*
     * Cenário resolvido nesta request. O builder resolve internamente e devolve só a
     * resposta; o stream precisa do slug para decidir o que fazer DEPOIS de aceitar —
     * é o caso do `accept_then_timeout` no spb-transfer. Recomputar no stream exigiria
     * repetir a lista de campos, e é a duplicata que sai de sincronia.
     */
    private static string $lastScenario = 'success';

    public static function lastScenario(): string
    {
        return self::$lastScenario;
    }

    private static function rememberScenario(string $scenario): string
    {
        self::$lastScenario = $scenario;

        return $scenario;
    }

    /*
     * Magic-amount: valores < R$ 1,00 viram códigos de cenário (centavos = código).
     * Disparado universalmente pelos streams transacionais que já consultam
     * scenarioFromPayload com o campo `amount`/`value`. Catálogo em docs/scenarios.md.
     */
    public static function scenarioFromAmount(mixed $amount, string $default = 'success'): string
    {
        if ($amount === null || $amount === '') {
            return $default;
        }

        $value = is_numeric($amount) ? (float) $amount : null;

        if ($value === null || $value <= 0 || $value >= 1.0) {
            return $default;
        }

        $cents = (int) round($value * 100);

        return self::SCENARIO_BY_CENTS[$cents] ?? $default;
    }

    public const SCENARIO_BY_CENTS = [
        1  => 'insufficient_funds',
        2  => 'key_not_found',
        3  => 'fraud',
        4  => 'limit_exceeded',
        5  => 'blocked',
        6  => 'timeout',
        7  => 'bank_unavailable',
        8  => 'duplicate',
        9  => 'invalid_document',
        10 => 'daily_limit',
        11 => 'receiver_not_found',
        12 => 'invalid_key',
        13 => 'kyc_pending',
        14 => 'rate_limit',
        15 => 'error',
        16 => 'not_found',
        17 => 'failed',
        18 => 'accept_then_timeout',
    ];

    /*
     * ── accept_then_timeout: aceitar e SÓ ENTÃO pendurar ──────────────────────────
     *
     * Pedido pela sustentação no briefing 2026-08-04 para destravar o QA do LGR-011
     * (TED V2, estorno cego no timeout). O defeito só aparece quando as duas coisas
     * acontecem juntas: a Celcoin ACEITOU a TED e mesmo assim o POST do app estourou.
     * O app estorna o débito local por uma TED que saiu de verdade — e quando o
     * `spb-transfer-out CONFIRMED` chega depois, o `TedService::processTedOut:230` vê a
     * transferência já ESTORNADA e a descarta com um `Yii::info`, mascarando a
     * divergência.
     *
     * Nenhum cenário existente reproduz isso. O `timeout` (0,06) responde HTTP 504 na
     * hora: é recusa limpa, cai no LGR-002, e nada foi aceito.
     *
     * A ORDEM é o cenário. Persistir + agendar o webhook ANTES de dormir — dormir
     * primeiro faria o CONFIRMED não representar "aceito" e o teste perderia o sentido.
     *
     * 35s porque o Guzzle do app corta em 30 (`CelcoinV2HttpClient.php:22`).
     */
    public const SCENARIO_ACCEPT_THEN_TIMEOUT = 'accept_then_timeout';

    public const ACCEPT_THEN_TIMEOUT_SECONDS = 35;

    /*
     * Cenários em que o mock ACEITA normalmente: o desvio está no transporte, não na
     * regra de negócio. Precisam passar pelo caminho de sucesso do builder — caindo no
     * ramo de erro não haveria o que persistir nem o que confirmar depois.
     */
    public static function scenarioIsAccept(string $scenario): bool
    {
        return $scenario === '' || $scenario === 'success' || $scenario === self::SCENARIO_ACCEPT_THEN_TIMEOUT;
    }

    /*
     * Sobrescrevível por env só para o smoke: um teste que esperasse 35s de verdade
     * ninguém rodaria. Teto de 120s para que a variável não vire um jeito de pendurar
     * um worker indefinidamente.
     */
    public static function acceptThenTimeoutSeconds(): int
    {
        $override = getenv('CSLABS_HANG_SECONDS');

        if (is_numeric($override) && (int) $override >= 0 && (int) $override <= 120) {
            return (int) $override;
        }

        return self::ACCEPT_THEN_TIMEOUT_SECONDS;
    }

    /*
     * Pendura a request DEPOIS de ela já ter sido aceita e persistida.
     *
     * `set_time_limit(0)`: sleep não conta para o max_execution_time em Linux, mas o
     * teto continua valendo para o resto do script — zerar evita que a resposta morra
     * por um limite baixo de php.ini.
     *
     * `ignore_user_abort(true)`: aos 30s o cliente fecha a conexão. Sem isso o PHP
     * abortaria o script na primeira escrita e o `ob_start` do api-stream não rodaria —
     * a interação não seria persistida e o shot do painel não mostraria o que houve.
     * O shot é justamente a evidência que o QA vai ler depois.
     */
    public static function hangAfterAccept(): int
    {
        $seconds = self::acceptThenTimeoutSeconds();

        @set_time_limit(0);
        ignore_user_abort(true);

        if ($seconds > 0) {
            sleep($seconds);
        }

        return $seconds;
    }

    /*
     * Último cenário disparado por paymentError/billPaymentError/chargeError nesta request.
     * Streams checam pra setar http_response_code coerente (429, 503, 504, etc.).
     */
    private static string $lastErrorScenario = 'success';

    public static function lastErrorScenario(): string
    {
        return self::$lastErrorScenario;
    }

    public static function resetLastErrorScenario(): void
    {
        self::$lastErrorScenario = 'success';
    }

    /*
     * Resposta de erro genérica reutilizando o catálogo do paymentError.
     * Útil em streams sem método de cenário dedicado (ex.: internal-transfer).
     */
    public static function scenarioErrorResponse(string $scenario): array
    {
        return self::paymentError($scenario);
    }

    /*
     * HTTP status mapeado por cenário. Usado pelos streams que ecoam erro
     * para retornar o código realista (429, 503, 504, etc.).
     */
    public static function scenarioHttpStatus(string $scenario): int
    {
        return [
            'insufficient_funds' => 422,
            'key_not_found'      => 404,
            'fraud'              => 422,
            'limit_exceeded'     => 422,
            'blocked'            => 403,
            'timeout'            => 504,
            'bank_unavailable'   => 503,
            'duplicate'          => 409,
            'invalid_document'   => 422,
            'daily_limit'        => 422,
            'receiver_not_found' => 404,
            'invalid_key'        => 422,
            'kyc_pending'        => 403,
            'rate_limit'         => 429,
            'error'              => 500,
            'not_found'          => 404,
            'failed'             => 400,
            // Só o spb/transfer implementa o modo; nos demais streams o slug cai no
            // ramo de erro e 501 diz exatamente isso, em vez de um 400 mudo.
            'accept_then_timeout' => 501,
        ][$scenario] ?? 400;
    }

    public static function pixKeyType(string $key): string
    {
        $key = trim($key);
        $digits = preg_replace('/\D+/', '', $key);

        if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }

        if (preg_match('/^\+?\d{12,13}$/', $key) || preg_match('/^\+55\d{10,11}$/', $key)) {
            return 'phone';
        }

        if (strlen($digits) === 11) {
            return 'cpf';
        }

        if (strlen($digits) === 14) {
            return 'cnpj';
        }

        return 'evp';
    }

    public static function pixKeyOwnerDocument(string $key, ?string $fallback = null): string
    {
        $digits = preg_replace('/\D+/', '', $key);

        if (in_array(strlen($digits), [11, 14], true)) {
            return $digits;
        }

        return preg_replace('/\D+/', '', (string) $fallback) ?: '06170097914';
    }

    public static function pixDictResponse(string $key, ?string $ownerTaxId = null): array
    {
        $key = trim($key) !== '' ? trim($key) : 'ok@pix.com';
        $scenario = self::scenarioFromValue($key);

        if ($scenario !== 'success') {
            return self::pixDictError($scenario);
        }

        $type = self::pixKeyType($key);
        $document = self::pixKeyOwnerDocument($key, $ownerTaxId);
        $account = self::accountNumberFromSeed($key . '|' . $document);

        return [
            'endtoEndId' => self::generateEndToEndId(),
            'owner' => [
                'name' => strlen($document) === 14 ? 'Empresa Homologacao Celcoin' : 'Daniel Eskelsen',
                'documentNumber' => $document,
            ],
            'account' => [
                'account' => $account,
                'participant' => '487',
                'branch' => '0001',
                'accountType' => 'N',
            ],
            'key' => $key,
            'keyType' => $type,
            'description' => 'CONSULTA COM SUCESSO.',
        ];
    }

    public static function pixDictOldResponse(string $key, ?string $payerId = null): array
    {
        $response = self::pixDictResponse($key, $payerId);

        if (($response['status'] ?? null) === 'ERROR') {
            $errorCode = $response['code']['errorCode'] ?? 'NNN';
            return [
                'code' => $errorCode === 'CPD0013' ? '422' : 'NNN',
                'description' => ($response['code']['message'] ?? 'QUALQUER OUTRO ERRO') . ' (API antiga).',
            ];
        }

        return [
            'endtoendid' => $response['endtoEndId'],
            'account' => [
                'accountNumber' => $response['account']['account'],
                'participant' => $response['account']['participant'],
                'branch' => $response['account']['branch'],
                'accountType' => $response['account']['accountType'],
            ],
            'owner' => [
                'taxIdNumber' => $response['owner']['documentNumber'],
                'name' => $response['owner']['name'],
            ],
            'code' => '200',
            'key' => $response['key'],
            'keyType' => $response['keyType'],
            'description' => 'CONSULTA COM SUCESSO (API antiga).',
        ];
    }

    public static function pixPaymentResponse(array $payload): array
    {
        $scenario = self::scenarioFromPayload($payload, [
            'key',
            'pixKey',
            'clientRequestId',
            'transactionId',
            'description',
            'amount',
        ]);

        if ($scenario !== 'success') {
            return self::paymentError($scenario);
        }

        /*
         * Regra REAL da Celcoin (log em mocks-v2, 2 de 4 pagamentos de QR
         * falharam assim): com initiationType STATIC_QRCODE o
         * transactionIdentification não pode passar de 25 caracteres. Pega em
         * cheio quem lê um QR dinâmico (txid de 35 chars) e paga como estático.
         */
        $initiationType = strtoupper(trim((string) ($payload['initiationType'] ?? '')));
        $txIdentification = (string) ($payload['transactionIdentification'] ?? '');

        if ($initiationType === 'STATIC_QRCODE' && strlen($txIdentification) > 25) {
            self::$lastErrorScenario = 'error';
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => [
                    'errorCode' => 'CBE136',
                    'message' => 'Quando initiationType igual a STATIC_QRCODE o campo transactionIdentification não pode ultrapassar 25 caracteres.',
                ],
            ];
        }

        $amount = (float) ($payload['amount'] ?? $payload['value'] ?? 1);
        $clientRequestId = trim((string) ($payload['clientRequestId'] ?? gerarHashMock()));
        $transactionId = 'pix_' . substr(hash('sha256', $clientRequestId), 0, 24);

        return [
            'status' => 'SUCCESS',
            'transactionId' => $transactionId,
            'clientRequestId' => $clientRequestId,
            'amount' => round($amount, 2),
            'endToEndId' => trim((string) ($payload['endToEndId'] ?? '')) ?: self::generateEndToEndId(),
            'message' => 'Pix recebido com sucesso.',
            'version' => '1.0.0',
        ];
    }

    /*
     * Idempotência do PIX out: o clientCode é a chave de referência única por
     * transação no lado do consumidor (str_pad(mov_pix_id, 7, '0')) e, na Celcoin
     * real, reenviar o mesmo clientCode replica a transação original em vez de
     * criar outra. Se já existe um pix_payments gravado para este clientCode,
     * devolve a MESMA resposta de sucesso (mesmo transactionId/endToEndId) — sem
     * regravar nem disparar novo webhook. Retorna null quando não há replay.
     */
    public static function pixPaymentReplay(string $clientCode): ?array
    {
        $clientCode = trim($clientCode);
        if ($clientCode === '') {
            return null;
        }

        $state = self::readEntity('pix_payments', $clientCode);
        if (!is_array($state) || !isset($state['body']['id'])) {
            return null;
        }

        $body = $state['body'];

        // O replay tem que devolver o MESMO shape da resposta original — inclusive
        // o bloco `body`, que é de onde v1 e V2 leem o id/endToEndId.
        return [
            'status' => self::isV2() ? 'PROCESSING' : 'SUCCESS',
            'transactionId' => (string) $body['id'],
            'clientRequestId' => (string) ($body['clientRequestId'] ?? ''),
            'amount' => round((float) ($body['amount'] ?? 0), 2),
            'endToEndId' => (string) ($body['endToEndId'] ?? ''),
            'message' => 'Pix recebido com sucesso.',
            'version' => '1.0.0',
            'body' => $body,
        ];
    }

    public static function billPaymentAuthorizeResponse(array $payload): array
    {
        $scenario = self::scenarioFromPayload($payload, [
            'scenario',
            'mockScenario',
            'mock_scenario',
            'amount',
        ]);

        $digitable = trim((string) self::arrayGet($payload, 'barCode.digitable'));
        $type = (int) (self::arrayGet($payload, 'barCode.type') ?? 0);

        if ($digitable === '' && $scenario === 'success') {
            $scenario = 'not_found';
        }

        if ($scenario !== 'success') {
            return self::billPaymentError($scenario);
        }

        $digits = preg_replace('/\D+/', '', $digitable) ?: $digitable;

        $ownCharge = self::chargeRecordByDigits($digits);
        if (is_array($ownCharge)) {
            return self::billPaymentAuthorizeFromCharge($ownCharge, $digitable, $type);
        }

        $seed = hash('sha256', $digits);
        $isUtilityBill = str_starts_with($digits, '8') || $type === 1;
        $value = self::billPaymentValue($digits, $seed);
        $dueIso = self::billPaymentDueIso($digits, $seed);
        $settleDate = date('d/m/Y', strtotime('+1 weekday'));
        $assignor = self::pickBySeed($seed, [
            'BANCO ITAU S.A.',
            'Banco Inter S.A.',
            'BANCO SANTANDER S.A',
            'BANCO DO BRASIL S.A.',
            'CAIXA ECONOMICA FEDERAL',
            'CLARO SP DDD 11',
            'ENEL DISTRIBUICAO SAO PAULO',
            'SABESP',
            'VIVO FIXO BRASIL',
        ]);
        $transactionId = 1000000000 + (hexdec(substr($seed, 0, 8)) % 8999999999);
        $resolvedType = $isUtilityBill ? 1 : ($type ?: 2);

        return [
            'assignor' => $assignor,
            'registerData' => $isUtilityBill ? null : self::billPaymentRegisterData($assignor, $value, $dueIso, $seed),
            'settleDate' => $settleDate,
            'dueDate' => $isUtilityBill && !str_starts_with($digits, '858') ? null : $dueIso . 'Z',
            'endHour' => $isUtilityBill ? '22:00' : '23:00',
            'initeHour' => '07:00',
            'nextSettle' => 'N',
            'digitable' => $digitable,
            'transactionId' => $transactionId,
            'type' => $resolvedType,
            'value' => $value,
            'maxValue' => null,
            'minValue' => null,
            'errorCode' => '000',
            'message' => null,
            'status' => 0,
        ];
    }

    public static function pixReverseResponse(array $payload, ?string $transactionId = null): array
    {
        $scenario = self::scenarioFromPayload($payload, ['scenario', 'mockScenario', 'mock_scenario', 'reason', 'amount']);
        if ($scenario !== 'success') {
            return self::paymentError($scenario);
        }

        $amount = (float) ($payload['amount'] ?? 0);
        $clientCode = trim((string) ($payload['clientCode'] ?? ''));
        $id = trim((string) ($payload['id'] ?? $transactionId ?? gerarHashMock()));

        if ($amount <= 0 || $id === '') {
            return self::paymentError('failed');
        }

        $endToEndId = trim((string) ($payload['endToEndId'] ?? '')) ?: self::generateEndToEndId();
        $returnIdentification = 'D13935893' . date('Ymd') . substr(hash('sha256', $id . '-ret'), 0, 11);

        return [
            'status' => 'PROCESSING',
            'version' => '1.0.0',
            'body' => [
                'id' => gerarHashMock(),
                'clientCode' => $clientCode !== '' ? $clientCode : ('REV-' . substr($id, 0, 8)),
                'amount' => round($amount, 2),
                'originalPaymentId' => $id,
                'endToEndId' => $endToEndId,
                'returnIdentification' => $returnIdentification,
                'reason' => (string) ($payload['reason'] ?? 'MD06'),
                'reversalDescription' => (string) ($payload['reversalDescription'] ?? ''),
                'additionalInformation' => (string) ($payload['additionalInformation'] ?? ''),
            ],
        ];
    }

    public static function walletEntryResponse(string $account, array $payload): array
    {
        $amount = (float) ($payload['amount'] ?? 0);
        $type = strtoupper(trim((string) ($payload['type'] ?? '')));
        $clientCode = trim((string) ($payload['clientCode'] ?? ''));

        if ($account === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'Account é obrigatório.'],
            ];
        }
        if ($amount <= 0 || !in_array($type, ['CREDIT', 'DEBIT'], true) || $clientCode === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'amount, type (CREDIT|DEBIT) e clientCode são obrigatórios.'],
            ];
        }

        return [
            'status' => 'CONFIRMED',
            'version' => '1.0.0',
            'body' => [
                'id' => gerarHashMock(),
                'clientCode' => $clientCode,
                'account' => $account,
                'amount' => round($amount, 2),
                'type' => $type,
                'description' => (string) ($payload['description'] ?? ''),
                'createDate' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    public static function pixDictListByAccountResponse(string $account): array
    {
        if (trim($account) === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'Conta é obrigatória.'],
            ];
        }

        $entries = [];
        foreach (self::listEntities('pix_dict_entries') as $entry) {
            $entryAccount = $entry['account']['account'] ?? null;
            if ($entryAccount === $account && ($entry['status'] ?? null) !== 'DELETED') {
                $entries[] = [
                    'keyType' => $entry['keyType'] ?? null,
                    'key' => $entry['key'] ?? null,
                    'account' => $entry['account'] ?? null,
                    'owner' => $entry['owner'] ?? null,
                ];
            }
        }

        // Real: body.listKeys[] (não pixKeys), sem totalElements. Ver HOMOLOGACAO_CELCOIN_V2.md Apêndice A.
        return [
            'status' => 'SUCCESS',
            'body' => [
                'listKeys' => $entries,
            ],
            'version' => '1.0.0',
        ];
    }

    public static function pixDictClaimResponse(array $payload, string $kind): array
    {
        $allowed = ['open', 'confirm', 'cancel'];
        if (!in_array($kind, $allowed, true)) {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CSLAB400', 'message' => 'Tipo de claim inválido.'],
            ];
        }

        $statusByKind = [
            'open' => 'OPEN',
            'confirm' => 'CONFIRMED',
            'cancel' => 'CANCELLED',
        ];

        $key = trim((string) ($payload['key'] ?? ''));
        if ($kind === 'open' && $key === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'key é obrigatório para abrir claim.'],
            ];
        }

        $claimId = trim((string) ($payload['id'] ?? $payload['claimId'] ?? '')) ?: gerarHashMock();
        $body = self::buildPixDictClaimBody($claimId, $key, $statusByKind[$kind], $payload);

        // Real: o status do claim (OPEN/CONFIRMED/CANCELLED/...) vem no TOPO, não "SUCCESS".
        return [
            'status' => $statusByKind[$kind],
            'body' => $body,
            'version' => '1.0.0',
        ];
    }

    public static function pixDictClaimGetResponse(string $id): array
    {
        if (trim($id) === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'id é obrigatório.'],
            ];
        }

        return [
            'status' => 'OPEN',
            'body' => self::buildPixDictClaimBody($id, '', 'OPEN', []),
            'version' => '1.0.0',
        ];
    }

    private static function buildPixDictClaimBody(string $claimId, string $key, string $status, array $payload): array
    {
        // Real: keyType em UPPER (CPF/CNPJ/EMAIL/PHONE) — confirmado nos logs de produção
        // (o Apêndice v1 dizia Pascal, mas o tráfego real da V2 é UPPER).
        $keyTypeRequest = strtoupper(trim((string) ($payload['keyType'] ?? '')));
        $detected = strtoupper(self::pixKeyType($key !== '' ? $key : 'fallback@pix.com'));
        $keyType = $keyTypeRequest !== '' ? $keyTypeRequest : $detected;

        $accountInput = (string) ($payload['account'] ?? '');
        $accountDigits = preg_replace('/\D+/', '', $accountInput) ?: '';
        $claimerTaxId = $key !== '' ? self::pixKeyOwnerDocument($key) : '06170097914';
        $claimAccount = $accountDigits !== '' ? $accountDigits : self::accountNumberFromSeed(hash('sha256', $key . $claimId));

        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $periodEnd = gmdate('Y-m-d\TH:i:s.000\Z', time() + (7 * 86400));

        return [
            'id' => $claimId,
            'claimType' => strtoupper((string) ($payload['claimType'] ?? 'OWNERSHIP')),
            'key' => $key,
            'keyType' => $keyType,
            // Real claimerAccount = {participant, account, accountType} (sem branch).
            'claimerAccount' => [
                'participant' => '13935893',
                'account' => $claimAccount,
                'accountType' => 'TRAN',
            ],
            'claimer' => [
                'personType' => strlen($claimerTaxId) === 14 ? 'LEGAL_PERSON' : 'NATURAL_PERSON',
                'taxId' => $claimerTaxId,
                'name' => (string) ($payload['claimerName'] ?? 'HOMOLOGACAO'),
            ],
            'donorParticipant' => (string) ($payload['donorParticipant'] ?? '13935893'),
            // Real: consultar/listar trazem donorAccount (open não traz; extra é inofensivo).
            'donorAccount' => [
                'account' => self::accountNumberFromSeed(hash('sha256', 'donor' . $key . $claimId)),
                'branch' => '0001',
                'taxId' => $claimerTaxId,
                'name' => 'HOMOLOGACAO',
            ],
            'createTimestamp' => $now,
            'completionPeriodEnd' => $periodEnd,
            'resolutionPeriodEnd' => $periodEnd,
            'lastModified' => $now,
            'status' => $status,
        ];
    }

    public static function pixDictClaimListResponse(array $query): array
    {
        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => [
                'totalElements' => 0,
                'claims' => [],
                'page' => (int) ($query['Page'] ?? 1),
                'limitPerPage' => (int) ($query['LimitPerPage'] ?? 10),
            ],
        ];
    }

    public static function brcodeStaticCreateResponse(array $payload): array
    {
        $key = trim((string) ($payload['key'] ?? ''));
        if ($key === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'key é obrigatório.'],
            ];
        }

        $transactionId = random_int(1000000000, 9999999999);
        $amount = number_format((float) ($payload['amount'] ?? 0), 2, '.', '');
        $transactionIdentification = (string) ($payload['transactionIdentification'] ?? 'PIX' . $transactionId);

        /*
         * PLANO de propósito: o QR estático real da Celcoin não usa envelope
         * (log: {"transactionId":1782034592,"emvqrcps":"000201...","transactionIdentification":"***","recurrency":null}),
         * e é assim que a v1 lê (CelcoinPix::criarQRCodeEstatico → $responseBody->emvqrcps,
         * sem fallback). Enquanto isso ficou dentro de body, a v1 recebia null.
         * transactionId é INT no real. Ver HOMOLOGACAO_CELCOIN_V2.md §4.8.
         */
        $merchant = is_array($payload['merchant'] ?? null) ? $payload['merchant'] : [];

        return [
            'transactionId' => $transactionId,
            'emvqrcps' => self::buildEmv(
                $key,
                $amount,
                $transactionIdentification,
                (string) ($merchant['name'] ?? 'CSLABS MOCK'),
                (string) ($merchant['city'] ?? 'SAO PAULO'),
                (string) ($merchant['postalCode'] ?? ''),
                (string) ($merchant['merchantCategoryCode'] ?? '0000')
            ),
            'transactionIdentification' => $transactionIdentification,
            'recurrency' => null,
        ];
    }

    public static function brcodeDynamicCreateResponse(array $payload): array
    {
        $clientRequestId = trim((string) ($payload['clientRequestId'] ?? '')) ?: gerarHashMock();
        $key = trim((string) ($payload['key'] ?? ''));

        if ($key === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'key é obrigatório.'],
            ];
        }

        /*
         * transactionId real é uma STRING de 10 dígitos ("3026988995"), não hash.
         * pactualId vem null. locationId tem 30 hex. Log real em mocks-v2.
         */
        $transactionId = (string) random_int(1000000000, 9999999999);
        $locationId = substr(hash('sha256', $clientRequestId), 0, 30);
        $amountInput = $payload['amount'] ?? null;
        $amountStr = ($amountInput === null || $amountInput === '')
            ? '5000.00'
            : number_format((float) $amountInput, 2, '.', '');
        $expiration = (int) ($payload['expiration'] ?? 86400);

        $merchant = is_array($payload['merchant'] ?? null) ? $payload['merchant'] : [];
        $merchantName = (string) ($merchant['name'] ?? 'CSLABS MOCK');
        $merchantCity = (string) ($merchant['city'] ?? 'SAO PAULO');
        $mcc = (string) ($merchant['merchantCategoryCode'] ?? '0000');
        $location = self::brcodeLocationHost() . '/pixqrcode/v2/' . $locationId;

        /*
         * O duplo-envelope aqui NÃO é acidente: a v1 lê caminhos fixos
         * $qrDinamico->body->body->dynamicBRCodeData->emvqrcps, ->body->body->amount->original,
         * ->body->transactionId etc. (CelcoinPix::geraQRCodeDinamico:2005-2016, sem
         * fallback), e o real da Celcoin devolve exatamente isso. Não achatar.
         * `status` no topo é INT 201 no real (a v1 não lê status nesta rota).
         * Demais campos (createTimestamp, entity, tags, transactionIdentification…)
         * são o que o log mostra. Ver HOMOLOGACAO_CELCOIN_V2.md §4.8.
         */
        $now = time();

        return [
            'version' => '1.0.0',
            'status' => 201,
            'body' => [
                'clientRequestId' => $clientRequestId,
                'pactualId' => null,
                'transactionId' => $transactionId,
                'createTimestamp' => self::dotNetTimestamp($now, true),
                'lastUpdateTimestamp' => '0001-01-01T00:00:00',
                'entity' => 'DynamicBRCode',
                'status' => 'ACTIVE',
                'tags' => null,
                'transactionIdentification' => 'kk6g232xel65a0daee4dd13kk' . $transactionId,
                'body' => [
                    'key' => $key,
                    'revision' => '0',
                    'location' => $location,
                    'debtor' => ['name' => null, 'cpf' => null, 'cnpj' => null],
                    // NUMÉRICO no real (11.91) — ao contrário do transactionAmount abaixo, que é string.
                    'amount' => ['original' => (float) $amountStr],
                    'calendar' => [
                        'expiration' => $expiration,
                        // Real: horário LOCAL (America/Sao_Paulo) + expiration, sem sufixo Z.
                        'dueDate' => self::dotNetTimestamp($now - 10800 + $expiration, false),
                    ],
                    'dynamicBRCodeData' => [
                        'pointOfInitiationMethod' => '12',
                        'payloadFormatIndicator' => '01',
                        'countryCode' => 'BR',
                        'merchantName' => $merchantName,
                        'merchantCity' => $merchantCity,
                        'transactionIdentification' => '***',
                        'transactionAmount' => $amountStr,
                        'emvqrcps' => self::buildDynamicEmv($location, $merchantName, $merchantCity, $mcc),
                        'merchantCategoryCode' => (int) $mcc,
                        'transactionCurrency' => 986,
                        'merchantAccountInformation' => ['url' => $location],
                    ],
                    'additionalInformation' => null,
                ],
            ],
        ];
    }

    public static function chargeCancelResponse(string $txid, array $payload): array
    {
        if (trim($txid) === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'txid é obrigatório.'],
            ];
        }

        /*
         * O cancelamento real não devolve um recibo enxuto: devolve a cobrança
         * INTEIRA (mesmo body do GET /baas/v2/charge, com boleto e pix populados),
         * status PROCESSING no topo — o CANCELED só chega depois pelo webhook
         * charge-canceled — e version 1.1.0 como o resto do produto charge.
         * Ver HOMOLOGACAO_CELCOIN_V2.md §14 (Apêndice A, "Charge").
         * Rota exclusiva da V2 (DELETE /baas/v2/charge/{txid}), sem par v1.
         */
        $record = self::readEntity('charges', $txid);

        return [
            'status' => 'PROCESSING',
            'version' => '1.1.0',
            'body' => is_array($record)
                ? self::chargeFetchBody($record)
                : [
                    'transactionId' => $txid,
                    'status' => 'PROCESSING',
                    'reason' => (string) ($payload['reason'] ?? ''),
                    'cancelDate' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
        ];
    }

    public static function accountUpdateBusinessScenario(array $payload, string $account): array
    {
        if (self::accountHasPendingKyc($account)) {
            return self::accountManagerError('CBE352', 'Não é permitido alterar dados cadastrais para uma conta pendente kyc.');
        }
        $scenario = self::scenarioFromPayload($payload, ['scenario', 'mockScenario', 'mock_scenario', 'businessName']);
        if ($scenario === 'not_found') {
            return self::accountManagerError('CBE003', 'Conta não encontrada.');
        }
        return self::accountManagerOk();
    }

    public static function accountCloseResponse(string $account, string $reason): array
    {
        if ($account === '') {
            return self::accountManagerError('CBE014', 'Account é obrigatório.');
        }
        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => [
                'account' => $account,
                'reason' => $reason,
                'closedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    public static function accountFetchBusinessResponse(string $document, string $account = ''): array
    {
        $document = trim($document);
        $account = trim($account);

        if ($document === '' && $account === '') {
            return self::accountManagerError('CBE014', 'DocumentNumber ou Account é obrigatório.');
        }

        $digits = preg_replace('/\D+/', '', $document) ?? '';
        $seed = hash('sha256', $digits !== '' ? $digits : $account);
        $accountNumber = $account !== '' ? $account : self::accountNumberFromSeed($seed);

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => [
                'documentNumber' => $digits !== '' ? $digits : '00000000000000',
                'businessName' => 'EMPRESA HOMOLOGACAO LTDA',
                'tradingName' => 'HOMOLOG',
                'businessEmail' => 'contato@homolog.example',
                'contactNumber' => '+5511999999999',
                'account' => $accountNumber,
                'branch' => '0001',
                'status' => 'ATIVO',
                'createDate' => gmdate('Y-m-d\TH:i:s\Z'),
                'businessAddress' => [
                    'postalCode' => '01310100',
                    'street' => 'Av. Paulista',
                    'number' => '1000',
                    'addressComplement' => '',
                    'neighborhood' => 'Bela Vista',
                    'city' => 'São Paulo',
                    'state' => 'SP',
                    'addressType' => 'COMMERCIAL',
                ],
                'owner' => [
                    [
                        'documentNumber' => '06170097914',
                        'fullName' => 'SOCIO HOMOLOGACAO',
                        'socialName' => null,
                        'motherName' => 'MAE HOMOLOGACAO',
                        'birthDate' => '1980-01-01',
                        'email' => 'socio@homolog.example',
                        'phoneNumber' => '+5511988888888',
                        'isPoliticallyExposedPerson' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Campo TLV do BR Code: id (2) + tamanho (2, zero-padded) + valor.
     */
    private static function emvTlv(string $id, string $value): string
    {
        return $id . sprintf('%02d', strlen($value)) . $value;
    }

    /**
     * CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF), 4 hex maiúsculos.
     * É o que o BR Code exige no campo 63 — e o que os EMVs reais da Celcoin
     * usam (conferido contra `mocks-v2`: os 4 dígitos finais batem).
     * O `$payload` já deve terminar em "6304".
     */
    private static function emvCrc16(string $payload): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
            $crc ^= ord($payload[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(sprintf('%04X', $crc));
    }

    /**
     * Normaliza texto para o charset do BR Code (ASCII imprimível, sem acento).
     * A Celcoin real remove a barra: "Confia Capital SAAS S/A" vira
     * "Confia Capital SAAS SA" no EMV (log real em mocks-v2).
     */
    private static function emvText(string $value, int $max): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $value = $ascii === false ? $value : $ascii;
        $value = preg_replace('/[^A-Za-z0-9 .,\-]/', '', $value) ?? '';

        return substr(trim($value), 0, $max);
    }

    /**
     * BR Code ESTÁTICO. Shape conferido contra EMVs reais em `mocks-v2`, ex.:
     * 00020126330014br.gov.bcb.pix011140088609839520400005303986540510.00
     * 5802BR5924Marlene dos Santos Silve6014Sao Bernardo d610909851-320
     * 62070503***6304B55D
     * Sem tag 01 (point of initiation), com 54 (valor) e 61 (CEP) opcionais,
     * e 62/05 SEMPRE "***" — o identificador de verdade volta no campo
     * `transactionIdentification` da response, não dentro do EMV.
     */
    private static function buildEmv(
        string $key,
        string $amount,
        string $txid,
        string $merchantName = 'CSLABS MOCK',
        string $merchantCity = 'SAO PAULO',
        string $postalCode = '',
        string $mcc = '0000'
    ): string {
        $merchantInfo = '0014br.gov.bcb.pix' . self::emvTlv('01', substr($key, 0, 77));
        $postalCode = preg_replace('/\D+/', '', $postalCode) ?? '';

        $payload = '000201'
            . self::emvTlv('26', $merchantInfo)
            . self::emvTlv('52', substr($mcc, 0, 4) ?: '0000')
            . self::emvTlv('53', '986')
            . ($amount !== '' && $amount !== '0.00' ? self::emvTlv('54', $amount) : '')
            . self::emvTlv('58', 'BR')
            . self::emvTlv('59', self::emvText($merchantName, 24) ?: 'CSLABS MOCK')
            . self::emvTlv('60', self::emvText($merchantCity, 14) ?: 'SAO PAULO')
            . ($postalCode !== '' ? self::emvTlv('61', self::formatPostalCode($postalCode)) : '')
            . self::emvTlv('62', self::emvTlv('05', '***'))
            . '6304';

        return $payload . self::emvCrc16($payload);
    }

    /**
     * BR Code DINÂMICO. Difere do estático em quatro pontos, todos confirmados
     * nos logs reais (`mocks-v2`), ex.:
     * 00020101021226910014br.gov.bcb.pix2569qrcode.pix.celcoin.com.br/
     * pixqrcode/v2/926f4a26fba9292613efe87c6dbe985204000053039865802BR
     * 5922Confia Capital SAAS SA6009Sao Pedro62070503***63046C21
     *   1. tag 01 = "12" (point of initiation method: uso único/dinâmico);
     *   2. tag 26 carrega a URL (subtag 25), NÃO a chave Pix (subtag 01);
     *   3. sem tag 54 — o valor vive no payload servido pela URL, não no QR;
     *   4. sem tag 61 (CEP).
     */
    private static function buildDynamicEmv(
        string $locationUrl,
        string $merchantName,
        string $merchantCity,
        string $mcc = '0000'
    ): string {
        $merchantInfo = '0014br.gov.bcb.pix' . self::emvTlv('25', $locationUrl);

        $payload = '000201'
            . self::emvTlv('01', '12')
            . self::emvTlv('26', $merchantInfo)
            . self::emvTlv('52', substr($mcc, 0, 4) ?: '0000')
            . self::emvTlv('53', '986')
            . self::emvTlv('58', 'BR')
            . self::emvTlv('59', self::emvText($merchantName, 24) ?: 'CSLABS MOCK')
            . self::emvTlv('60', self::emvText($merchantCity, 14) ?: 'SAO PAULO')
            . self::emvTlv('62', self::emvTlv('05', '***'))
            . '6304';

        return $payload . self::emvCrc16($payload);
    }

    /**
     * Host (sem esquema, como manda o BR Code) da `location` do QR dinâmico.
     *
     * O real usa `qrcode.pix.celcoin.com.br`; aqui apontamos para o PRÓPRIO
     * mock. É a ÚNICA divergência deliberada do EMV em relação ao real, e a
     * decisão é de produto: queremos controle total sobre a URL e um QR que
     * abre no navegador para teste visual. O `locationId` é gerado localmente,
     * então a URL da Celcoin não teria cobrança por trás de qualquer forma.
     *
     * Não afeta o app: ele nunca busca esta URL — repassa como parâmetro para
     * `pix/v1/collection/…/payload/…`, que resolve pelo último segmento.
     * Para voltar à grafia da Celcoin, basta trocar o retorno desta função.
     */
    private static function brcodeLocationHost(): string
    {
        $host = defined('DOMAIN') ? (string) DOMAIN : 'cslabs.mfwks.com';
        $base = defined('BASE') ? trim((string) BASE, '/') : '';

        return $base === '' ? $host : $host . '/' . $base;
    }

    /**
     * Faz o parse TLV de um BR Code. Devolve os campos de primeiro nível; os
     * compostos (26 = merchant account information, 62 = additional data) vêm
     * com os subcampos já resolvidos em `26.01`, `26.25`, `62.05`, etc.
     * Retorna [] se o payload não for TLV válido.
     */
    public static function emvParse(string $emv): array
    {
        $emv = trim($emv);
        $out = [];
        $i = 0;
        $len = strlen($emv);

        while ($i + 4 <= $len) {
            $id = substr($emv, $i, 2);
            $size = substr($emv, $i + 2, 2);

            if (!ctype_digit($id) || !ctype_digit($size)) {
                return [];
            }

            $size = (int) $size;
            if ($i + 4 + $size > $len) {
                return [];
            }

            $value = substr($emv, $i + 4, $size);
            $out[$id] = $value;
            $i += 4 + $size;

            // 26–51 (merchant account information) e 62 (additional data) são compostos.
            if (($id >= '26' && $id <= '51') || $id === '62') {
                $j = 0;
                while ($j + 4 <= strlen($value)) {
                    $sid = substr($value, $j, 2);
                    $ssize = substr($value, $j + 2, 2);
                    if (!ctype_digit($sid) || !ctype_digit($ssize)) {
                        break;
                    }
                    $ssize = (int) $ssize;
                    if ($j + 4 + $ssize > strlen($value)) {
                        break;
                    }
                    $out[$id . '.' . $sid] = substr($value, $j + 4, $ssize);
                    $j += 4 + $ssize;
                }
            }
        }

        return $out;
    }

    /**
     * Confere o CRC do campo 63 contra o payload. Um BR Code adulterado (ou
     * inventado) falha aqui — é a validação que qualquer app de banco faz.
     */
    public static function emvCrcIsValid(string $emv): bool
    {
        $emv = trim($emv);
        $pos = strrpos($emv, '6304');

        if ($pos === false || $pos !== strlen($emv) - 8) {
            return false;
        }

        return strcasecmp(self::emvCrc16(substr($emv, 0, -4)), substr($emv, -4)) === 0;
    }

    /**
     * Timestamp com 7 casas de fração (ticks .NET) — é como a Celcoin serializa
     * `createTimestamp` ("2026-07-03T19:50:35.9688673Z") e `calendar.dueDate`
     * (sem o Z). O PHP só vai até microssegundo, então as 7 casas saem de `u`+0.
     */
    private static function dotNetTimestamp(int $ts, bool $utcSuffix): string
    {
        return gmdate('Y-m-d\TH:i:s', $ts) . '.' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '0'
            . ($utcSuffix ? 'Z' : '');
    }

    private static function formatPostalCode(string $digits): string
    {
        return strlen($digits) === 8 ? substr($digits, 0, 5) . '-' . substr($digits, 5) : $digits;
    }

    public static function walletBalanceResponse(string $document): array
    {
        $document = preg_replace('/\D+/', '', $document) ?? '';

        if ($document === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'DocumentNumber é obrigatório.'],
            ];
        }

        $amount = round((hexdec(substr(hash('sha256', $document), 0, 8)) % 1_000_000) / 10, 2);

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => ['amount' => $amount],
        ];
    }

    /**
     * Imagem do QR estático em base64.
     *
     * Antes devolvia um PNG de 1x1 pixel transparente para QUALQUER id — status
     * 0 e `base64image` plausível, imagem vazia. Agora renderiza o BR Code
     * realmente emitido; id desconhecido vira erro explícito, porque devolver
     * uma imagem qualquer é pior do que devolver erro.
     */
    public static function brcodeStaticBase64Response(string $transactionId, string $imageType = 'png'): array
    {
        $emv = self::brcodeEmvForStatic($transactionId);

        if ($emv === null) {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'QR Code não encontrado para o transactionId informado.'],
            ];
        }

        return [
            'status' => 0,
            'base64image' => base64_encode(QrCode::png(QrCode::encode($emv), 6, 4)),
        ];
    }

    /** Imagem do QR dinâmico em base64, localizada pelo id da `location`. */
    public static function locationBase64Response(string $locationId): array
    {
        $emv = self::brcodeEmvForLocation($locationId, true);

        if ($emv === null) {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'QR Code não encontrado para a location informada.'],
            ];
        }

        return [
            'status' => 0,
            'base64image' => base64_encode(QrCode::png(QrCode::encode($emv), 6, 4)),
        ];
    }

    /** EMV do QR dinâmico gravado na criação, ou null se a location é desconhecida. */
    public static function brcodeEmvForLocation(string $locationId, bool $qualquerCliente = false): ?string
    {
        $stored = $qualquerCliente
            ? self::readEntityAnyClient('brcode_dynamic_by_location', trim($locationId))
            : self::readEntity('brcode_dynamic_by_location', trim($locationId));
        $emv = $stored['body']['dynamicBRCodeData']['emvqrcps'] ?? null;

        return is_string($emv) && $emv !== '' ? $emv : null;
    }

    /** EMV do QR estático gravado na criação, ou null se o id é desconhecido. */
    public static function brcodeEmvForStatic(string $transactionId): ?string
    {
        $stored = self::readEntity('brcode_static', trim($transactionId));
        $emv = $stored['emvqrcps'] ?? null;

        return is_string($emv) && $emv !== '' ? $emv : null;
    }

    public static function emvDecodeResponse(array $payload): array
    {
        $emv = trim((string) ($payload['emv'] ?? ''));

        if ($emv === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'emv é obrigatório.'],
            ];
        }

        $seed = hash('sha256', $emv);

        /*
         * Antes isto não decodificava nada: valor, chave e txid saíam de um
         * hash do EMV. Ou seja, ler um QR gerado aqui devolvia dados que não
         * eram os do QR, e pagar por esse caminho era pagar outra coisa.
         * Agora fazemos o parse TLV de verdade; o sintético só sobrou como
         * fallback para EMV que não é BR Code válido (é o que os smokes usam).
         */
        $tags = self::emvParse($emv);
        $isStatic = !isset($tags['26.25']) && ($tags['01'] ?? '') !== '12';
        $key = self::extractFromEmv($emv) ?? gerarHashMock();
        $url = 'pix.celcoin.com.br/pix/v2/' . substr($seed, 0, 24);
        $amount = round((hexdec(substr($seed, 0, 6)) % 10000) / 100, 2);
        $txid = 'TXID' . strtoupper(substr($seed, 0, 28));
        $additionalInformation = '';
        $merchantName = null;
        $merchantCity = 'SAO PAULO';
        $postalCode = null;

        if ($tags !== []) {
            $merchantName = $tags['59'] ?? null;
            $merchantCity = $tags['60'] ?? $merchantCity;
            $postalCode = $tags['61'] ?? null;
            // 62/05 é "***" nos QRs reais; nesse caso não há txid no próprio EMV.
            $emvTxid = (string) ($tags['62.05'] ?? '');
            $txid = ($emvTxid !== '' && $emvTxid !== '***') ? $emvTxid : $txid;

            if ($isStatic) {
                $key = (string) ($tags['26.01'] ?? $key);
                $amount = isset($tags['54']) ? (float) $tags['54'] : 0.0;
                $url = '';
            } else {
                $url = (string) $tags['26.25'];
                /*
                 * QR dinâmico não carrega chave nem valor no EMV — eles vivem na
                 * cobrança apontada pela `location`. A Celcoin resolve isso do
                 * lado dela; aqui resolvemos pelo mesmo índice que a criação
                 * gravou, para o pagador ler EXATAMENTE o que foi cobrado.
                 */
                /*
                 * `true` = busca a cobrança em qualquer cliente. Não é atalho:
                 * quem decodifica um QR é o PAGADOR, que por definição NÃO é
                 * quem emitiu. Escopado por cliente, o app caía no sintético e
                 * recebia chave e valor aleatórios — o QR "válido" virava
                 * inválido no app, sem erro em lugar nenhum.
                 */
                $cob = self::cobPayloadForLocation(basename(rtrim($url, '/')), '', true);
                $key = (string) ($cob['chave'] ?? $key);
                $amount = (float) ($cob['valor']['original'] ?? 0);
                $txid = (string) ($cob['txid'] ?? $txid);
            }
        }

        return [
            'type' => $isStatic ? '1' : '2',
            'collection' => $isStatic ? '0' : '1',
            'payloadFormatIndicator' => '02',
            'merchantAccountInformation' => [
                'url' => $url,
                'gui' => 'br.gov.bcb.pix',
                'key' => $key,
                // A v1 lê este campo SEM `??` mesmo em QR dinâmico
                // (CelcoinPix::consultaQRCode:1322) — nunca pode sumir.
                'additionalInformation' => $additionalInformation,
                'withdrawalServiceProvider' => null,
            ],
            'merchantCategoryCode' => 0,
            'transactionCurrency' => 0,
            'transactionAmount' => $amount,
            'countryCode' => $tags !== [] ? ($tags['58'] ?? null) : null,
            'merchantName' => $merchantName,
            'merchantCity' => $merchantCity,
            'postalCode' => $postalCode,
            'initiationMethod' => null,
            'transactionIdentification' => $txid,
        ];
    }

    public static function collectionPayloadResponse(string $payloadUrl): array
    {
        $payloadUrl = trim($payloadUrl);
        if ($payloadUrl === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'payload é obrigatório.'],
            ];
        }

        /*
         * O último segmento da URL é o locationId — a mesma chave que a criação
         * do QR dinâmico gravou. Sem isso o payload servido pela `location` não
         * teria relação nenhuma com o QR que o pagador leu.
         */
        // Mesmo motivo do decode: quem resolve a location é o pagador.
        return self::cobPayloadForLocation(
            basename(rtrim(parse_url($payloadUrl, PHP_URL_PATH) ?: $payloadUrl, '/')),
            $payloadUrl,
            true
        );
    }

    /**
     * Dados da cobrança apontada por uma `location` de QR dinâmico.
     *
     * SHAPE INFERIDO — auditoria do corpus real (`mocks-v2`, 14 tenants) não
     * encontrou NENHUMA chamada a `pix/v1/collection/…/payload/…`, nem os campos
     * PT-BR abaixo, nem os status ATIVA/CONCLUIDA. A base aqui é o spec do
     * Bacen + o que o consumidor efetivamente lê: `chave|key`,
     * `valor.original|amount.original` e `txid|transactionIdentification`
     * (CelcoinPix::consultaQRCode:1307,1324,1328). Revisar se um log real
     * aparecer.
     *
     * Se o QR foi criado neste mock, devolve os dados REAIS dele (valor, chave,
     * txid, calendário) e — se já foi pago — status CONCLUIDA com o bloco `pix`.
     * Para uma location desconhecida cai no sintético determinístico de antes,
     * para não quebrar quem só quer um payload qualquer.
     */
    public static function cobPayloadForLocation(string $locationId, string $seedSource = '', bool $qualquerCliente = false): array
    {
        $locationId = trim($locationId);
        $seed = hash('sha256', $seedSource !== '' ? $seedSource : $locationId);

        $amount = number_format(round((hexdec(substr($seed, 0, 6)) % 10000) / 100, 2), 2, '.', '');
        $txid = 'TXID' . strtoupper(substr($seed, 0, 28));
        $key = substr($seed, 0, 8) . '-' . substr($seed, 8, 4) . '-' . substr($seed, 12, 4) . '-' . substr($seed, 16, 4) . '-' . substr($seed, 20, 12);
        $criacao = gmdate('Y-m-d\TH:i:s\Z');
        $expiration = 300;
        $status = 'ATIVA';
        $pagamentos = [];

        $stored = false;
        if ($locationId !== '') {
            $stored = $qualquerCliente
                ? self::readEntityAnyClient('brcode_dynamic_by_location', $locationId)
                : self::readEntity('brcode_dynamic_by_location', $locationId);
        }

        if (is_array($stored)) {
            $inner = $stored['body'] ?? [];
            $amount = number_format((float) ($inner['amount']['original'] ?? 0), 2, '.', '');
            $txid = (string) ($stored['transactionIdentification'] ?? $txid);
            $key = (string) ($inner['key'] ?? $key);
            $expiration = (int) ($inner['calendar']['expiration'] ?? $expiration);
            $criacao = (string) ($stored['createTimestamp'] ?? $criacao);
            $pagamentos = is_array($stored['pix'] ?? null) ? $stored['pix'] : [];
            $status = $pagamentos !== [] ? 'CONCLUIDA' : 'ATIVA';
        }

        $out = [
            'status' => $status,
            'infoAdicionais' => [],
            'txid' => $txid,
            'chave' => $key,
            'solicitacaoPagador' => null,
            'valor' => [
                'original' => $amount,
                'abatimento' => '0.00',
                'desconto' => '0.00',
                'multa' => '0.00',
                'juros' => '0.00',
                'final' => $amount,
                'modalidadeAlteracao' => 0,
                'retirada' => null,
            ],
            'calendario' => [
                'criacao' => $criacao,
                'expiracao' => $expiration,
                'apresentacao' => gmdate('Y-m-d\TH:i:s.v\Z'),
                'validadeAposVencimento' => 0,
            ],
            'revisao' => 0,
        ];

        // Só existe depois de pago — é o que muda ATIVA para CONCLUIDA.
        if ($pagamentos !== []) {
            $out['pix'] = $pagamentos;
        }

        return $out;
    }

    /**
     * Liquida a cobrança de um QR dinâmico: registra o pagamento recebido e faz
     * a `location` passar de ATIVA para CONCLUIDA, como no Pix real.
     *
     * O elo é o `transactionIdentification` — é o que o consumidor lê da
     * location e devolve no pagamento (initiationType DYNAMIC_QRCODE, ver
     * CelcoinPix::enviarPix). Devolve false quando o txid não é de um QR
     * criado aqui (pagamento de QR de terceiro), sem efeito colateral.
     */
    public static function settleDynamicBrcode(string $transactionIdentification, array $pagamento, ?string $clientId = null): bool
    {
        $transactionIdentification = trim($transactionIdentification);

        if ($transactionIdentification === '') {
            return false;
        }

        $ref = self::readEntity('brcode_dynamic_by_txid', $transactionIdentification, $clientId);

        /*
         * Conveniência de mock: se o txid não está no escopo de quem pagou,
         * procura em qualquer cliente e liquida no escopo do DONO.
         *
         * Sem isso, gerar o QR de um jeito (curl, painel, sem token) e pagar de
         * outro (o app, com bearer) resultava em pagamento aceito e cobrança
         * eternamente ATIVA — falha silenciosa, o pior tipo. Num ambiente único
         * de teste isso é atrito puro; o txid é praticamente único, então o
         * risco de casar com a cobrança de outro tenant é desprezível.
         */
        if (!is_array($ref) && $clientId === null) {
            $ref = self::readEntityAnyClient('brcode_dynamic_by_txid', $transactionIdentification);
            $clientId = self::entityOwnerClient('brcode_dynamic_by_txid', $transactionIdentification);
        }

        if (!is_array($ref) || empty($ref['locationId'])) {
            return false;
        }

        $locationId = (string) $ref['locationId'];
        $stored = self::readEntity('brcode_dynamic_by_location', $locationId, $clientId);

        if (!is_array($stored)) {
            return false;
        }

        // Idempotente: o mesmo endToEndId não entra duas vezes (retry de pagamento).
        $pagamentos = is_array($stored['pix'] ?? null) ? $stored['pix'] : [];
        foreach ($pagamentos as $existente) {
            if (($existente['endToEndId'] ?? null) === ($pagamento['endToEndId'] ?? '')) {
                return true;
            }
        }

        $pagamentos[] = $pagamento;
        $stored['pix'] = $pagamentos;
        $stored['status'] = 'CONCLUIDA';

        Db::transaction(function () use ($stored, $locationId, $ref, $clientId) {
            self::writeEntity('brcode_dynamic_by_location', $locationId, $stored, $clientId);
            if (!empty($ref['transactionId'])) {
                self::writeEntity('brcode_dynamic', (string) $ref['transactionId'], $stored, $clientId);
            }
        });

        /*
         * Quem criou o QR é o RECEBEDOR: no real ele é avisado por um webhook
         * pix-payment-in. Shape conferido em mocks-v2 — o caso DYNAMIC_QRCODE é
         * o único que carrega `transactionIdBRCode` (o transactionId numérico da
         * cobrança) e `clientRequestId`; em STATIC_QRCODE/DICT essas chaves nem
         * existem, e aí não há ponteiro nenhum para a cobrança.
         */
        $id = (string) ($pagamento['endToEndId'] ?? gerarHashMock());
        $webhookBody = [
            'id' => $id,
            'amount' => (float) ($pagamento['valor'] ?? 0),
            'clientRequestId' => $stored['clientRequestId'] ?? null,
            'transactionIdentification' => $transactionIdentification,
            'endToEndId' => $pagamento['endToEndId'] ?? '',
            'initiationType' => 'DYNAMIC_QRCODE',
            'paymentType' => 'IMMEDIATE',
            'urgency' => 'HIGH',
            'transactionType' => 'RECEIVEPIX',
            'debitParty' => $pagamento['debitParty'] ?? new \stdClass(),
            'creditParty' => $pagamento['creditParty'] ?? new \stdClass(),
            'remittanceInformation' => $pagamento['infoPagador'] ?? null,
            'currentBalance' => 9784.19,
            'oldBalance' => 9754.23,
            'transactionIdBRCode' => (string) ($ref['transactionId'] ?? ''),
        ];

        self::scheduleWebhook(
            'pix-payment-in',
            self::webhookEnvelope('pix-payment-in', 'CONFIRMED', $webhookBody),
            2,
            self::webhookSubscriptionUrl('pix-payment-in')
        );

        return true;
    }

    private static function extractFromEmv(string $emv): ?string
    {
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $emv, $matches)) {
            return $matches[0];
        }
        return null;
    }

    private static function onePixelImageBase64(string $type): string
    {
        $png = base64_encode(
            "\x89PNG\r\n\x1a\n" .
            "\x00\x00\x00\x0dIHDR" .
            "\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89" .
            "\x00\x00\x00\x0dIDATx\x9cc\xf8\xcf\xc0\x00\x00\x00\x03\x00\x01\x5f\xe8\x35\xe7" .
            "\x00\x00\x00\x00IEND\xaeB`\x82"
        );
        return $png;
    }

    public static function spbTransferResponse(array $payload): array
    {
        $scenario = self::scenarioFromPayload($payload, ['scenario', 'mockScenario', 'mock_scenario', 'description', 'clientCode', 'amount']);
        $amount = (float) ($payload['amount'] ?? 0);
        $clientCode = trim((string) ($payload['clientCode'] ?? ''));
        $debit = is_array($payload['debitParty'] ?? null) ? $payload['debitParty'] : [];
        $credit = is_array($payload['creditParty'] ?? null) ? $payload['creditParty'] : [];

        if ($amount <= 0 || $clientCode === '' || empty($debit['account']) || empty($credit['account'])) {
            return [
                'version' => '1.0.0',
                'status' => 'ERROR',
                'error' => ['errorCode' => 'CBE014', 'message' => 'Campos obrigatórios ausentes para transferência SPB.'],
            ];
        }

        $duplicate = self::readEntity('spb_transfers_by_client_code', $clientCode);
        if (is_array($duplicate) && !empty($duplicate['id']) && self::scenarioIsAccept($scenario)) {
            return [
                'version' => '1.0.0',
                'status' => 'ERROR',
                'error' => ['errorCode' => 'CBE100', 'message' => 'Existe um lançamento idêntico pendente. Favor aguarde para realizar esta operação para evitar duplicidade.'],
            ];
        }

        // `accept_then_timeout` NÃO é erro: daqui em diante ele segue idêntico ao
        // sucesso. Quem pendura é o stream, depois de persistir e agendar o webhook.
        if (!self::scenarioIsAccept($scenario)) {
            $err = self::paymentError($scenario);
            return ['version' => '1.0.0', 'status' => 'ERROR', 'error' => $err['error']];
        }

        $debitSeed = hash('sha256', (string) $debit['account']);
        $creditSeed = hash('sha256', (string) $credit['account']);

        return [
            'status' => 'PROCESSING',
            'version' => '1.0.0',
            'body' => [
                'id' => gerarHashMock(),
                'amount' => round($amount, 2),
                'clientCode' => $clientCode,
                'debitParty' => [
                    'account' => (string) $debit['account'],
                    'branch' => (string) ($debit['branch'] ?? '0001'),
                    'taxId' => (string) ($debit['taxId'] ?? self::deterministicCnpj($debitSeed)),
                    'name' => (string) ($debit['name'] ?? 'EMPRESA HOMOLOGACAO'),
                    'accountType' => (string) ($debit['accountType'] ?? 'PG'),
                    'personType' => (string) ($debit['personType'] ?? 'J'),
                    'bank' => '13935893',
                ],
                'creditParty' => [
                    'bank' => (string) ($credit['bank'] ?? '00000000'),
                    'account' => (string) $credit['account'],
                    'branch' => (string) ($credit['branch'] ?? '0001'),
                    'taxId' => (string) ($credit['taxId'] ?? self::deterministicCnpj($creditSeed)),
                    'name' => (string) ($credit['name'] ?? 'BENEFICIARIO HOMOLOGACAO'),
                    'accountType' => (string) ($credit['accountType'] ?? 'CC'),
                    'personType' => (string) ($credit['personType'] ?? 'J'),
                ],
            ],
        ];
    }

    public static function transferNotFoundError(): array
    {
        return [
            'version' => '1.0.0',
            'status' => 'ERROR',
            'error' => [
                'errorCode' => 'CBE106',
                'message' => 'Não encontramos nenhuma transação através do parâmetro informado.',
            ],
        ];
    }

    public static function pixOldStatusNotFoundError(): array
    {
        return [
            'errorCode' => 'CLP005',
            'message' => 'Não localizamos nenhum pagamento associado ao parâmetro informado.',
        ];
    }

    public static function pixDictCreateResponse(array $payload): array
    {
        $account = trim((string) ($payload['account'] ?? ''));
        $keyType = strtoupper(trim((string) ($payload['keyType'] ?? '')));
        $key = trim((string) ($payload['key'] ?? ''));

        if ($account === '') {
            return self::pixDictCreateError('CBE014', 'account é obrigatório e deve conter um formato de texto válido.');
        }

        $allowed = ['EVP', 'CPF', 'CNPJ', 'EMAIL', 'PHONE'];
        if (!in_array($keyType, $allowed, true)) {
            return self::pixDictCreateError('CBE175', 'Chave invalida. Verifique o formato da chave informada.');
        }

        if ($keyType === 'EVP') {
            $key = $key !== '' ? $key : gerarHashMock();
        } else {
            if ($key === '' || !self::validatePixKey($keyType, $key)) {
                return self::pixDictCreateError('CBE175', 'Chave invalida. Verifique o formato da chave informada.');
            }
        }

        if (self::accountHasPendingKyc($account)) {
            return self::pixDictCreateError('CBE345', 'Cadastro com pendencias no KYC, favor verificar.');
        }

        $scenario = self::scenarioFromPayload($payload, ['scenario', 'mockScenario', 'mock_scenario']);
        if ($scenario === 'fraud') {
            return self::pixDictCreateError('CBE006', 'Chave bloqueada por suspeita de fraude.');
        }

        $seed = hash('sha256', $account);
        $isLegal = (hexdec(substr($seed, 0, 2)) % 3) === 0;
        $owner = self::pixDictOwnerFromAccount($account, $seed, $isLegal);

        return [
            'status' => 'CONFIRMED',
            'body' => [
                'keyType' => $keyType,
                'key' => $key,
                'account' => [
                    'participant' => '13935893',
                    'branch' => '0001',
                    'account' => $account,
                    'accountType' => 'TRAN',
                    'createDate' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
                'owner' => $owner,
            ],
            'version' => '1.0.0',
        ];
    }

    public static function pixDictDuplicateError(string $key, string $account): ?array
    {
        if ($key === '') {
            return null;
        }
        $existing = self::readEntity('pix_dict_entries', $key);
        if (!is_array($existing) || empty($existing['key'])) {
            return null;
        }

        $existingAccount = (string) self::arrayGet($existing, 'account.account');
        if ($existingAccount !== '' && $existingAccount === $account) {
            return self::pixDictCreateError('CBE189', 'Chave já cadastrada para esta conta.');
        }
        return self::pixDictCreateError('CBE189', 'Chave já cadastrada e em uso por outro usuário.');
    }

    public static function pixDictDeleteResponse(string $key, ?array $payload = null): array
    {
        if (trim($key) === '') {
            return self::pixDictCreateError('CBE014', 'Chave é obrigatória.');
        }

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
        ];
    }

    private static function pixDictCreateError(string $code, string $message): array
    {
        return [
            'status' => 'ERROR',
            'error' => [
                'errorCode' => $code,
                'message' => $message,
            ],
            'version' => '1.0.0',
        ];
    }

    private static function validatePixKey(string $type, string $key): bool
    {
        $digits = preg_replace('/\D+/', '', $key) ?? '';
        return match ($type) {
            'CPF' => strlen($digits) === 11,
            'CNPJ' => strlen($digits) === 14,
            'EMAIL' => (bool) filter_var($key, FILTER_VALIDATE_EMAIL),
            'PHONE' => (bool) preg_match('/^\+?\d{12,13}$/', $key),
            default => true,
        };
    }

    private static function pixDictOwnerFromAccount(string $account, string $seed, bool $isLegal): array
    {
        if ($isLegal) {
            return [
                'type' => 'LEGAL_PERSON',
                'documentNumber' => self::deterministicCnpj($seed),
                'name' => self::pickBySeed($seed . 'pj', [
                    'EMPRESA HOMOLOGACAO LTDA',
                    'COTA CAPITAL GESTORA DE ATIVOS LTDA',
                    'AURUSTECH SOLUTIONS LTDA',
                    'CONTABILIDADE M RODRIGUES LTDA',
                ]),
                'tradeName' => self::pickBySeed($seed . 'trade', ['HOMOLOG', 'COTA', 'AURUSTECH']),
            ];
        }

        return [
            'type' => 'NATURAL_PERSON',
            'documentNumber' => self::deterministicCpf($seed),
            'name' => self::pickBySeed($seed . 'pf', [
                'Daniel Eskelsen',
                'Luan Lima da Silva',
                'Maria Silva',
                'Joao Souza Santos',
            ]),
        ];
    }

    private static function deterministicCpf(string $seed): string
    {
        $digits = '';
        for ($i = 0; strlen($digits) < 11; $i++) {
            $digits .= (string) (hexdec(substr($seed, $i % 32 * 2, 2)) % 10);
        }
        return substr($digits, 0, 11);
    }

    private static function deterministicCnpj(string $seed): string
    {
        $digits = '';
        for ($i = 0; strlen($digits) < 14; $i++) {
            $digits .= (string) (hexdec(substr($seed, $i % 32 * 2, 2)) % 10);
        }
        return substr($digits, 0, 14);
    }

    public static function accountManagerOk(): array
    {
        return [
            'version' => '1.0.0',
            'status' => 'SUCCESS',
        ];
    }

    public static function accountManagerError(string $code, string $message): array
    {
        return [
            'status' => 'ERROR',
            'version' => '1.0.0',
            'error' => [
                'errorCode' => $code,
                'message' => $message,
            ],
        ];
    }

    public static function accountStatusScenario(array $payload, string $account): array
    {
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));

        if (!in_array($status, ['ATIVO', 'BLOQUEADO'], true)) {
            return self::accountManagerError('CBE014', 'status é obrigatório (ATIVO|BLOQUEADO).');
        }

        // `reason` é opcional aqui — a doc oficial só o exige no DELETE /account/close.
        // Cenários de erro controlados vêm por `scenario`/`mockScenario` (não por `reason`,
        // senão um texto natural como "desbloqueio" casaria o needle "bloqueio" → CBE345).
        $scenario = self::scenarioFromPayload($payload, [
            'scenario',
            'mockScenario',
            'mock_scenario',
        ]);

        if ($scenario === 'blocked' || self::accountHasPendingKyc($account)) {
            return self::accountManagerError('CBE345', 'Cadastro com pendencias no KYC, favor verificar.');
        }
        if ($scenario === 'not_found') {
            return self::accountManagerError('CBE003', 'Conta não encontrada.');
        }
        if ($scenario === 'fraud') {
            return self::accountManagerError('CBE006', 'Conta bloqueada por suspeita de fraude.');
        }
        if ($scenario === 'failed' || $scenario === 'error') {
            return self::accountManagerError('CBE015', 'Erro interno ao atualizar status da conta.');
        }

        return self::accountManagerOk();
    }

    public static function accountUpdateNaturalPersonScenario(array $payload, string $account): array
    {
        $scenario = self::scenarioFromPayload($payload, [
            'scenario',
            'mockScenario',
            'mock_scenario',
            'fullName',
        ]);

        if ($scenario === 'blocked' || self::accountHasPendingKyc($account)) {
            return self::accountManagerError('CBE352', 'Não é permitido alterar dados cadastrais para uma conta pendente kyc.');
        }
        if ($scenario === 'not_found') {
            return self::accountManagerError('CBE003', 'Conta não encontrada.');
        }
        if ($scenario === 'failed') {
            return self::accountManagerError('CBE001', 'Dados de atualização inválidos.');
        }

        return self::accountManagerOk();
    }

    private static function accountHasPendingKyc(string $account): bool
    {
        if ($account === '') {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $account) ?? '';
        if ($digits === '') {
            return false;
        }
        return ((int) substr($digits, -1)) % 4 === 0;
    }

    public static function onboardingResponse(array $payload, string $kind): array
    {
        $scenario = self::scenarioFromPayload($payload, [
            'scenario',
            'mockScenario',
            'mock_scenario',
            'fullName',
            'businessName',
            'documentNumber',
        ]);

        $missing = $kind === 'business'
            ? self::missingOnboardingBusinessFields($payload)
            : self::missingOnboardingNaturalPersonFields($payload);

        if ($missing !== null) {
            return self::onboardingValidationError($missing);
        }

        if ($scenario !== 'success') {
            return self::onboardingError($scenario);
        }

        return [
            'version' => '1.0.0',
            'status' => 'PROCESSING',
            'body' => [
                'onBoardingId' => gerarHashMock(),
            ],
        ];
    }

    public static function generateAccountNumber(string $seed): string
    {
        return self::accountNumberFromSeed($seed);
    }

    public static function chargeResponse(array $payload): array
    {
        $scenario = self::scenarioFromPayload($payload, [
            'scenario',
            'mockScenario',
            'mock_scenario',
            'externalId',
            'key',
            'amount',
        ]);

        if ($scenario !== 'success') {
            return self::chargeError($scenario);
        }

        $amount = (float) ($payload['amount'] ?? 0);
        $externalId = trim((string) ($payload['externalId'] ?? ''));
        $key = trim((string) ($payload['key'] ?? ''));

        if ($amount <= 0 || $externalId === '' || $key === '') {
            return self::chargeError('failed');
        }

        return [
            'body' => [
                'transactionId' => gerarHashMock(),
            ],
            'version' => '1.1.0',
            'status' => 'SUCCESS',
        ];
    }

    public static function billPaymentResponse(array $payload): array
    {
        $scenario = self::scenarioFromPayload($payload, [
            'scenario',
            'mockScenario',
            'mock_scenario',
            'clientRequestId',
            'account',
            'amount',
        ]);

        if ($scenario !== 'success') {
            return self::billPaymentError($scenario);
        }

        $amount = (float) ($payload['amount'] ?? 0);
        $clientRequestId = trim((string) ($payload['clientRequestId'] ?? ''));
        $transactionIdAuthorize = $payload['transactionIdAuthorize'] ?? null;
        $digitable = (string) self::arrayGet($payload, 'barCodeInfo.digitable');

        if ($amount <= 0 || $clientRequestId === '' || $transactionIdAuthorize === null || $digitable === '') {
            return self::billPaymentError('failed');
        }

        return [
            'body' => [
                'id' => gerarHashMock(),
                'clientRequestId' => $clientRequestId,
                'amount' => round($amount, 2),
                'transactionIdAuthorize' => is_numeric($transactionIdAuthorize) ? (int) $transactionIdAuthorize : $transactionIdAuthorize,
                'barCodeInfo' => ['digitable' => $digitable],
            ],
            'status' => 'PROCESSING',
            'version' => '1.2.0', // real: baas/v2/billpayment usa 1.2.0
        ];
    }

    public static function boletoBankLine(string $transactionId, float $amount, string $dueDate): string
    {
        $seed = hash('sha256', $transactionId . '|' . $amount . '|' . $dueDate);
        $digits = '';

        for ($i = 0, $len = strlen($seed); $i < $len && strlen($digits) < 47; $i++) {
            $char = $seed[$i];
            if (ctype_digit($char)) {
                $digits .= $char;
            } else {
                $digits .= (string) (ord($char) % 10);
            }
        }

        # Celcoin emite a linha digitável com 47 dígitos puros (sem máscara) no
        # webhook e no GET fetch — a máscara fica por conta da renderização do
        # boleto, não do payload. Ref.: developers.celcoin.com.br/docs/criar-uma-cobranca-avulsa.
        return str_pad($digits, 47, '0');
    }

    public static function chargeRecordByDigits(string $digits): ?array
    {
        if ($digits === '') {
            return null;
        }
        $alias = self::readEntity('charges_by_bank_line', $digits);
        if (!is_array($alias)) {
            $alias = self::readEntity('charges_by_bar_code', $digits);
        }
        if (!is_array($alias) || empty($alias['transactionId'])) {
            return null;
        }
        $record = self::readEntity('charges', $alias['transactionId']);
        return is_array($record) ? $record : null;
    }

    public static function billPaymentAuthorizeFromCharge(array $charge, string $digitable, int $type): array
    {
        $amount = round((float) ($charge['amount'] ?? 0), 2);
        $dueDate = (string) ($charge['duedate'] ?? date('Y-m-d'));
        $dueIso = preg_match('/^\d{4}-\d{2}-\d{2}/', $dueDate)
            ? substr($dueDate, 0, 10) . 'T00:00:00.0000000'
            : date('Y-m-d') . 'T00:00:00.0000000';
        $settleDate = date('d/m/Y', strtotime('+1 weekday'));
        $debtorName = (string) self::arrayGet($charge, 'debtor.name');
        $debtorDoc = (string) self::arrayGet($charge, 'debtor.document');
        $assignor = 'CELCOIN INSTITUIÇÃO DE PAGAMENTO - SA';
        $registerData = [
            'identificationField' => $digitable,
            'recipient' => [
                'name' => (string) self::arrayGet($charge, 'receiver.name') ?: $assignor,
                'documentType' => 2,
                'document' => (string) self::arrayGet($charge, 'receiver.document'),
            ],
            'payer' => [
                'name' => $debtorName,
                'documentType' => strlen(preg_replace('/\D+/', '', $debtorDoc)) === 14 ? 2 : 1,
                'document' => $debtorDoc,
            ],
            'allowChangeValue' => false,
            'totalUpdated' => $amount,
            'originalValue' => $amount,
            'discountValue' => 0,
            'rebateValue' => 0,
            'fineValue' => 0,
            'interestValue' => 0,
            'dueDate' => $dueIso . 'Z',
            'nextSettle' => 'N',
        ];

        return [
            'assignor' => $assignor,
            'registerData' => $registerData,
            'settleDate' => $settleDate,
            'dueDate' => $dueIso . 'Z',
            'endHour' => '23:00',
            'initeHour' => '07:00',
            'nextSettle' => 'N',
            'digitable' => $digitable,
            'transactionId' => (string) ($charge['transactionId'] ?? ''),
            'type' => $type ?: 2,
            'value' => $amount,
            'maxValue' => null,
            'minValue' => null,
            'errorCode' => '000',
            'message' => null,
            'status' => 0,
        ];
    }

    public static function boletoBarCode(string $transactionId, float $amount, string $dueDate): string
    {
        $seed = hash('sha256', $transactionId . '|' . $amount . '|' . $dueDate . '|barcode');
        $digits = '';
        $len = strlen($seed);
        for ($i = 0; $i < $len && strlen($digits) < 44; $i++) {
            $char = $seed[$i];
            $digits .= ctype_digit($char) ? $char : (string) (ord($char) % 10);
        }
        return $digits;
    }

    public static function boletoBankLineDigits(string $bankLine): string
    {
        return (string) preg_replace('/\D+/', '', $bankLine);
    }

    public static function chargeFetchResponse(?string $transactionId, ?string $externalId): array
    {
        $record = false;

        if ($transactionId !== null && $transactionId !== '') {
            $record = self::readEntity('charges', $transactionId);
        }

        if (!is_array($record) && $externalId !== null && $externalId !== '') {
            $alias = self::readEntity('charges_by_external_id', $externalId);
            if (is_array($alias) && !empty($alias['transactionId'])) {
                $record = self::readEntity('charges', $alias['transactionId']);
            }
        }

        if (!is_array($record)) {
            return [
                'version' => '1.0.0',
                'status' => 'ERROR',
                'error' => [
                    'errorCode' => 'CIE999',
                    'message' => 'Cobrança não encontrada.',
                ],
            ];
        }

        return [
            'version' => '1.1.0', // real: baas/v2/charge usa 1.1.0
            'status' => 'SUCCESS',
            'body' => self::chargeFetchBody($record),
        ];
    }

    public static function chargeFetchBody(array $record): array
    {
        $amount = round((float) ($record['amount'] ?? 0), 2);
        $status = (string) ($record['status'] ?? 'PENDING');
        $dueDate = (string) ($record['duedate'] ?? '');
        $bankLine = (string) ($record['bankLine'] ?? '');
        $barCode = (string) ($record['barCode'] ?? '');
        $bankAccount = (string) self::arrayGet($record, 'receiver.account');
        $txid = (string) ($record['transactionId'] ?? '');

        $boletoStatusLabel = match (strtoupper($status)) {
            'PAID', 'CONFIRMED' => 'Pago',
            'CANCELLED', 'CANCELED' => 'Cancelado',
            default => 'Registrado',
        };

        $pixStatusLabel = match (strtoupper($status)) {
            'PAID', 'CONFIRMED' => 'Pago',
            'CANCELLED', 'CANCELED' => 'Cancelado',
            default => 'Ativa',
        };

        return [
            'transactionId' => $txid,
            'externalId' => (string) ($record['externalId'] ?? ''),
            'amount' => $amount,
            'amountConfirmed' => isset($record['amountConfirmed']) ? round((float) $record['amountConfirmed'], 2) : null,
            'duedate' => $dueDate,
            'status' => $status,
            'debtor' => $record['debtor'] ?? null,
            'receiver' => $record['receiver'] ?? null,
            'instructions' => $record['instructions'] ?? null,
            'boleto' => [
                'transactionId' => (string) ($record['boleto']['transactionId'] ?? self::chargeBoletoIds($txid)['transactionId']),
                'status' => $boletoStatusLabel,
                'bankEmissor' => 'CELCOIN INSTITUIÇÃO DE PAGAMENTO - SA',
                'bankNumber' => (string) ($record['boleto']['bankNumber'] ?? self::chargeBoletoIds($txid)['bankNumber']),
                'bankAgency' => '0001',
                'bankAccount' => $bankAccount,
                'barCode' => $barCode,
                'bankLine' => $bankLine,
                'bankAssignor' => 'CELCOIN INSTITUIÇÃO DE PAGAMENTO - SA',
                'invoiceNumber' => null,
            ],
            'pix' => [
                'transactionId' => (string) ($record['pix']['transactionId'] ?? substr($txid, 0, 9)),
                'transactionIdentification' => (string) ($record['pix']['transactionIdentification'] ?? substr(str_replace('-', '', $txid), 0, 30)),
                'status' => $pixStatusLabel,
                'key' => (string) ($record['key'] ?? ''),
                'emv' => (string) ($record['pix']['emv'] ?? ''),
            ],
            'split' => $record['split'] ?? [],
            'informations' => null,
            'chargeType' => 'BOLEPIX',
        ];
    }

    public static function billPaymentStatusRender(array $state, bool $confirmed): array
    {
        $body = [
            'id' => $state['id'],
            'clientRequestId' => $state['clientRequestId'],
            'account' => is_numeric($state['account']) ? (int) $state['account'] : $state['account'],
            'amount' => round((float) $state['amount'], 2),
            'transactionIdAuthorize' => is_numeric($state['transactionIdAuthorize'] ?? '') ? (int) $state['transactionIdAuthorize'] : $state['transactionIdAuthorize'],
            'hasOccurrence' => (bool) ($state['hasOccurrence'] ?? false),
            'barCodeInfo' => ['digitable' => $state['digitable'] ?? ''],
        ];

        if ($confirmed) {
            $body['paymentDate'] = $state['paymentDate'] ?? gmdate('Y-m-d\TH:i:s\Z');
        }

        return [
            'body' => $body,
            'status' => $confirmed ? 'CONFIRMED' : 'PROCESSING',
            'version' => '1.2.0', // real: baas/v2/billpayment/status usa 1.2.0
        ];
    }

    public static function webhookSubscription(string $entity): array|false
    {
        return self::readEntity('webhook_subscriptions', $entity);
    }

    public static function listWebhookSubscriptions(): array
    {
        $items = self::listEntities('webhook_subscriptions');
        foreach ($items as $i => $item) {
            if (empty($item['subscriptionId'])) {
                $item['subscriptionId'] = gerarHashMock();
                self::writeEntity('webhook_subscriptions', $item['entity'], $item);
                $items[$i] = $item;
            }
        }
        return $items;
    }

    public static function webhookSubscriptionUrl(string $entity): ?string
    {
        $subscription = self::webhookSubscription($entity);
        $url = trim((string) ($subscription['webhookUrl'] ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * IDs determinísticos do boleto derivados do transactionId da charge.
     * Garante que o mesmo boleto sempre exibe o mesmo `boleto.transactionId`
     * (6 dígitos numéricos) e `boleto.bankNumber` (9 dígitos numéricos) em
     * webhook + GET fetch, alinhado ao formato real do `itauAgreement`.
     */
    public static function chargeBoletoIds(string $transactionId): array
    {
        $seed = $transactionId !== '' ? $transactionId : gerarHashMock();

        return [
            'transactionId' => (string) (hexdec(substr(hash('sha256', $seed . '|boleto-txn'), 0, 8)) % 900000 + 100000),
            'bankNumber'    => (string) (hexdec(substr(hash('sha256', $seed . '|boleto-bank'), 0, 12)) % 900000000 + 100000000),
        ];
    }

    /**
     * Projeta um registro interno de inscrição no shape oficial da Celcoin
     * (`GET /baas-webhookmanager/v1/webhook/subscription`).
     */
    public static function webhookSubscriptionView(array $record): array
    {
        $entity = (string) ($record['entity'] ?? '');
        $subscriptionId = (string) ($record['subscriptionId'] ?? '');
        if ($subscriptionId === '') {
            $subscriptionId = substr(hash('sha256', $entity), 0, 24);
        }
        $auth = is_array($record['auth'] ?? null) ? $record['auth'] : [];

        return [
            'subscriptionId' => $subscriptionId,
            'entity'         => $entity,
            'webhookUrl'     => (string) ($record['webhookUrl'] ?? ''),
            'active'         => (bool) ($record['active'] ?? true),
            'createDate'     => self::formatCelcoinIsoZ((string) ($record['created_at'] ?? '')),
            'lastUpdateDate' => self::formatCelcoinIsoZ((string) ($record['updated_at'] ?? '')),
            'auth' => [
                'login' => (string) ($auth['login'] ?? ''),
                'pwd'   => (string) ($auth['pwd'] ?? $auth['password'] ?? ''),
                'type'  => (string) ($auth['type'] ?? ''),
            ],
        ];
    }

    private static function formatCelcoinIsoZ(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Exception $e) {
            return $iso;
        }
    }

    public static function saveWebhookSubscription(string $entity, string $url, ?array $auth = null, array $raw = [], bool $active = true): array
    {
        $existing = self::webhookSubscription($entity);
        $now = date(DATE_ATOM);
        $knownEntities = self::knownWebhookEntities();
        $record = [
            'subscriptionId' => is_array($existing) ? ($existing['subscriptionId'] ?? gerarHashMock()) : gerarHashMock(),
            'entity' => $entity,
            'webhookUrl' => $url,
            'auth' => $auth,
            'active' => $active,
            'known_entity' => in_array($entity, $knownEntities, true),
            'updated_at' => $now,
            'created_at' => is_array($existing) ? ($existing['created_at'] ?? $now) : $now,
            'raw_request' => $raw,
        ];

        self::writeEntity('webhook_subscriptions', $entity, $record);

        return $record;
    }

    public static function sampleWebhookBody(string $entity, string $status): array
    {
        $id = gerarHashMock();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $endToEnd = 'E13935893' . date('YmdHi') . substr($id, 0, 11);

        return match ($entity) {
            'onboarding-create' => [
                'account' => [
                    'branch' => '0001',
                    'account' => substr(hash('sha256', $id), 0, 9),
                    'name' => 'EMPRESA HOMOLOG',
                    'documentNumber' => '49966300000119',
                    'ispb' => '13935893',
                ],
                'onboardingId' => $id,
                'clientCode' => 'CLI-' . substr($id, 0, 8),
                'createDate' => $now,
            ],
            'onboarding-backgroundcheck', 'onboarding-documentscopy', 'onboarding-proposal' => [
                'proposalId' => $id,
                'proposalType' => 'PF',
                'rejectedReason' => $status === 'REPROVED' ? ['Documento inválido'] : [],
                'urlDocumentscopy' => $entity === 'onboarding-documentscopy' && $status === 'PENDING' ? 'https://homolog.celcoin/docscopy/' . $id : null,
            ],
            'kyc' => [
                'onboardingId' => $id,
            ],
            'pix-payment-in' => [
                'id' => $id,
                'amount' => 25.00,
                'endToEndId' => $endToEnd,
                'initiationType' => 'MANUAL',
                'paymentType' => 'IMMEDIATE',
                'urgency' => 'HIGH',
                'transactionType' => 'RECEIVEPIX',
                'debitParty' => ['bank' => '10573521', 'account' => '96514838590', 'branch' => '0001', 'taxId' => '40996994807', 'name' => 'Rafael Adabo Gastaldi', 'accountType' => 'CACC'],
                'creditParty' => ['bank' => '13935893', 'key' => '', 'account' => '3005415542261', 'branch' => '0001', 'taxId' => '36000285000108', 'name' => 'EMPRESA HOMOLOG', 'accountType' => 'TRAN'],
                'remittanceInformation' => null,
                'currentBalance' => 12003.03,
                'oldBalance' => 11978.03,
                'transactionIdBRCode' => null,
            ],
            'pix-payment-out' => [
                'id' => $id,
                'amount' => 140.00,
                'clientCode' => 'CLI-' . substr($id, 0, 7),
                'reason' => null,
                'transactionIdentification' => substr($id, 0, 10),
                'endToEndId' => $endToEnd,
                'initiationType' => 'STATIC_QRCODE',
                'paymentType' => 'IMMEDIATE',
                'urgency' => 'HIGH',
                'transactionType' => 'TRANSFER',
                'debitParty' => ['account' => '447959768', 'branch' => '0001', 'taxId' => '62519201000157', 'name' => 'EMPRESA HOMOLOG', 'accountType' => 'TRAN'],
                'creditParty' => ['bank' => '18236120', 'key' => '23839794811', 'account' => '260265413', 'branch' => '0001', 'taxId' => '23839794811', 'name' => 'Linli Jin', 'accountType' => 'TRAN'],
                'remittanceInformation' => '',
                'currentBalance' => 19814.54,
                'oldBalance' => 19954.54,
                'dataInsercao' => $now,
            ],
            'internal-transfer-out', 'internal-transfer-in' => [
                'id' => $id,
                'amount' => 6.90,
                'clientRequestId' => $id,
                'creditParty' => ['account' => '300541554121', 'taxId' => '49966300000119', 'name' => 'EMPRESA HOMOLOG', 'branch' => '0001', 'bank' => '13935893'],
                'debitParty' => ['account' => '447959768', 'taxId' => '62519201000157', 'name' => 'EMPRESA DEBITO', 'branch' => '0001', 'bank' => '13935893'],
                'endToEndId' => $endToEnd,
                'description' => 'Transferencia interna',
                'oldBalance' => 19814.54,
                'currentBalance' => 19807.64,
            ],
            'spb-transfer-out' => [
                'id' => $id,
                'amount' => 34392.22,
                'clientCode' => 'T-' . substr($id, 0, 8),
                'description' => 'Repasse',
                'clientFinality' => '10',
                'numCtrlStr' => 'STR' . date('Ymd') . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
                'debitParty' => ['name' => 'EMPRESA HOMOLOG', 'personType' => 'J', 'accountType' => 'PG', 'bank' => '13935893', 'account' => '3005415541891', 'branch' => '0001', 'taxId' => '14718532000173'],
                'creditParty' => ['name' => 'STL ELETRONICA E TI', 'personType' => 'J', 'accountType' => 'CC', 'bank' => '60701190', 'account' => '168895', 'branch' => '0375', 'taxId' => '35288962000172'],
                'currentBalance' => 22156.65,
                'oldBalance' => 56548.87,
            ],
            'spb-transfer-in' => [
                'id' => $id,
                'amount' => 270000.00,
                'debitParty' => ['account' => '8001', 'branch' => '3235', 'taxId' => '52885021000135', 'accountType' => 'CC', 'name' => 'CINQ CAPITAL', 'bank' => '00000000', 'personType' => 'J'],
                'creditParty' => ['account' => '300541554121', 'branch' => '1', 'taxId' => '49966300000119', 'accountType' => 'CC', 'name' => 'EMPRESA HOMOLOG', 'bank' => '13935893', 'personType' => 'J'],
                'reason' => 'PAGAMENTOS DIVERSOS',
                'clientfinality' => '99999',
                'typeCode' => 'STR0008R2',
                'numCtrlSTR' => 'STR' . date('Ymd') . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
                'currentBalance' => 464354.24,
                'oldBalance' => 194354.24,
            ],
            'spb-reversal-in', 'spb-reversal-out' => [
                'id' => $id,
                'amount' => 500.00,
                'debitParty' => ['account' => '108552263', 'branch' => '0001', 'taxId' => '33630661000150', 'accountType' => 'CC', 'name' => 'Latam Gateway', 'bank' => '71027866', 'personType' => 'J'],
                'creditParty' => ['account' => '3005415542618', 'branch' => '0001', 'taxId' => '89714601800', 'accountType' => 'PG', 'name' => 'EMPRESA HOMOLOG', 'bank' => '13935893', 'personType' => 'F'],
                'originalId' => gerarHashMock(),
                'reason' => 'SPB Cashin Notification',
                'originalClientCode' => 'T-' . substr($id, 0, 8),
                'numCtrlSTR' => null,
                'currentBalance' => null,
                'oldBalance' => null,
            ],
            'charge-create', 'charge-in' => $status === 'ERROR' ? [
                'error' => ['message' => 'Falha em geração de boleto, favor tente novamente.'],
                'externalId' => '0000000001',
                'status' => 'ERROR',
                'transactionId' => $id,
            ] : [
                'amount' => 2256.27,
                'boleto' => [
                    'transactionId' => self::chargeBoletoIds($id)['transactionId'],
                    'status' => $status === 'CONFIRMED' ? 'PAID' : 'PENDING',
                    'bankLine' => self::boletoBankLine($id, 2256.27, date('Y-m-d')),
                    'bankNumber' => self::chargeBoletoIds($id)['bankNumber'],
                    'barCode' => '34197978000002256271098014789950910156496000',
                    'bankEmissor' => 'itauAgreement',
                    'bankAgency' => '0910',
                    'bankAccount' => '15649',
                    'bankAssignor' => 'CELCOIN INSTITUIÇÃO DE PAGAMENTO - SA',
                ],
                'debtor' => ['city' => 'São Paulo', 'complement' => 'Sem complemento', 'document' => '49438599000139', 'name' => 'ATELIE DR COSTURA', 'neighborhood' => 'Brás', 'number' => '2071', 'postalCode' => '03001000', 'state' => 'SP', 'publicArea' => 'Avenida Rangel Pestana'],
                'duedate' => date('Y-m-d 00:00:00'),
                'expirationAfterPayment' => 1,
                'pix' => [
                    'transactionId' => (string) random_int(1000000000, 9999999999),
                    'transactionIdentification' => substr(str_replace('-', '', $id), 0, 30),
                    'status' => $status === 'CONFIRMED' ? 'PAID' : 'PENDING',
                    'locationId' => (string) random_int(100000000, 999999999),
                    'key' => $id,
                    'emv' => '00020101021226960014br.gov.bcb.pix2574qrcode.pix.celcoin.com.br/pixqrcode/v2/cobv/' . substr(str_replace('-', '', $id), 0, 28) . '5204000053039865802BR5911EMPRESAHOM6009Sao Paulo62070503***6304ABCD',
                ],
                'receiver' => ['city' => 'São Paulo', 'document' => '49966300000119', 'name' => 'EMPRESA HOMOLOG', 'postalCode' => '04570001', 'publicArea' => 'Avenida Nova Independência', 'state' => 'SP', 'account' => '300541554121'],
                'externalId' => '0000000001',
                'status' => $status,
                'transactionId' => $id,
            ],
            'charge-canceled' => [
                'transactionId' => $id,
                'status' => 'CANCELLED',
                'reason' => 'Cancelado pelo emissor',
            ],
            'billpayment', 'billpayment-occurrence' => [
                'account' => '414998567',
                'amount' => 138.56,
                'barCodeInfo' => [
                    'type' => 1,
                    'digitable' => '826100000015385600970912091815316855195952462834',
                    'barCode' => null,
                ],
                'clientRequestId' => substr($id, 0, 8),
                'id' => $id,
                'tags' => [],
                'transactionIdAuthorize' => random_int(4000000000, 4999999999),
                'authentication' => random_int(1000, 9999),
                'authenticationAPI' => [
                    'bloco1' => 'C7.56.2F.D7.C0.38.44.D5',
                    'bloco2' => '05.02.85.96.F3.94.70.46',
                    'blocoCompleto' => 'C7.56.2F.D7.C0.38.44.D5.05.02.85.96.F3.94.70.46',
                ],
                'convenant' => 'CIP CELCOIN',
                'createDate' => $now,
                'isExpired' => false,
                'receipt' => [
                    'receiptData' => '',
                    'receiptformatted' => "    EMPRESA HOMOLOG\r\n          PROTOCOLO " . random_int(4000000000, 4999999999) . "\r\n",
                ],
                'settleDate' => date('Y-m-d\T00:00:00'),
                'status' => $status,
                'transactionId' => random_int(4000000000, 4999999999),
            ],
            'account-status' => [
                'account' => '443168489',
                'status' => $status === 'CONFIRMED' ? 'ATIVO' : 'BLOQUEADO',
                'reason' => 'Segurança',
            ],
            default => ['id' => $id, 'timestamp' => $now],
        };
    }

    public static function webhookEnvelope(string $entity, string $status, array $body): array
    {
        $stampKey = self::webhookTimestampKey($entity);
        $timestamp = gmdate('Y-m-d\TH:i:s.u');
        $webhookId = (string) ($body['id'] ?? ($body['onboardingId'] ?? ($body['transactionId'] ?? gerarHashMock())));

        return [
            'entity' => $entity,
            $stampKey => $timestamp,
            'status' => $status,
            'body' => $body,
            'webhookId' => $webhookId,
        ];
    }

    private static function webhookTimestampKey(string $entity): string
    {
        $sMaiusculo = [
            'pix-payment-in', 'pix-payment-out',
            'spb-transfer-in', 'spb-reversal-in', 'spb-reversal-out',
        ];

        return in_array($entity, $sMaiusculo, true) ? 'createTimeStamp' : 'createTimestamp';
    }

    public static function deleteWebhookSubscription(string $entity): bool
    {
        return self::deleteEntity('webhook_subscriptions', $entity);
    }

    /**
     * Registra o webhook que NÃO saiu — e por quê.
     *
     * O `scheduleWebhook` desiste quando não há URL de destino, e isso é correto:
     * a Celcoin real também só entrega para entidade inscrita. O que estava errado
     * era desistir **em silêncio**. Em 05/08/2026 a sustentação perdeu uma bateria
     * do LGR-011 por causa disso: o `spb-transfer-out CONFIRMED` nunca foi agendado
     * porque o client não tinha inscrição, e nada no painel, no shot ou na resposta
     * dizia isso — descobriu-se lendo o código do mock.
     *
     * ⚠️ Só toca painel e `webhook_dispatches`. A resposta HTTP não muda: quem
     * consome estes streams lê caminho fixo, e a Celcoin real não devolveria nada
     * parecido. Diagnóstico de mock não é contrato de API.
     */
    private static function noteWebhookSkipped(string $event, ?string $url): void
    {
        $context = self::context();
        $inscricao = self::webhookSubscription($event);

        $motivo = is_array($inscricao)
            ? sprintf("há inscrição para '%s', mas o webhookUrl não é uma URL válida (%s)", $event, var_export($url, true))
            : sprintf("não há inscrição de webhook para '%s' neste client_id", $event);

        $entry = [
            'webhook_id'  => 'wh_skip_' . bin2hex(random_bytes(6)),
            'request_id'  => $context['request_id'],
            'client_id'   => $context['client_id'],
            'event'       => $event,
            'status'      => 'skipped',
            'target_url'  => null,
            'reason'      => $motivo,
            'fix'         => sprintf(
                'POST /baas/v2/webhook/subscription {"entity":"%s","webhookUrl":"https://<app>/…"} com o MESMO bearer que fez esta request — a inscrição é por client_id.',
                $event
            ),
            'skipped_at'  => date(DATE_ATOM),
        ];

        self::$webhookSkips[] = $entry;
        self::registerWebhook($entry);
    }
    public static function scheduleWebhook(string $event, array $payload, int $delaySeconds = 2, ?string $url = null): bool
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            self::noteWebhookSkipped($event, $url);
            return false;
        }

        $context = self::context();
        $requestId = $context['request_id'];
        $clientId = $context['client_id'];
        $webhookId = 'wh_' . bin2hex(random_bytes(8));
        $subscription = self::webhookSubscription($event);
        $auth = is_array($subscription) && is_array($subscription['auth'] ?? null) ? $subscription['auth'] : null;

        self::registerWebhook([
            'webhook_id' => $webhookId,
            'request_id' => $requestId,
            'client_id' => $clientId,
            'event' => $event,
            'status' => 'scheduled',
            'target_url' => $url,
            'payload' => $payload,
            'scheduled_at' => date(DATE_ATOM),
            'delay_seconds' => $delaySeconds,
        ]);

        $dispatch = [
            'webhook_id'    => $webhookId,
            'request_id'    => $requestId,
            'client_id'     => $clientId,
            'event'         => $event,
            'url'           => $url,
            'payload'       => $payload,
            'auth'          => $auth,
            'delay_seconds' => $delaySeconds,
        ];

        if (self::spawnWebhookWorker($dispatch)) {
            return true;
        }

        # Fallback se exec() estiver indisponível: dispatch in-process via shutdown.
        # Em SAPIs sem fastcgi_finish_request a entrega vai bloquear a resposta —
        # mas pelo menos ainda acontece.
        register_shutdown_function(static function () use ($dispatch): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            self::deliverScheduledWebhook($dispatch);
        });

        return true;
    }

    /**
     * Entrega um webhook agendado: sleep do delay, POST JSON, persistência do
     * resultado em `webhook_dispatches` e anexação em `interactions.webhooks[]`.
     * Recebe o `client_id` explícito porque é invocada fora do contexto HTTP
     * (pelo worker spawnado em `bin/webhook-worker.php`).
     */
    public static function deliverScheduledWebhook(array $dispatch): void
    {
        $url       = (string) ($dispatch['url'] ?? '');
        $payload   = is_array($dispatch['payload'] ?? null) ? $dispatch['payload'] : [];
        $event     = (string) ($dispatch['event'] ?? '');
        $requestId = (string) ($dispatch['request_id'] ?? '');
        $clientId  = (string) ($dispatch['client_id'] ?? '');
        $webhookId = (string) ($dispatch['webhook_id'] ?? '');
        $delay     = (int) ($dispatch['delay_seconds'] ?? 0);
        $auth      = is_array($dispatch['auth'] ?? null) ? $dispatch['auth'] : null;

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        sleep(max(0, $delay));
        $sentAt = date(DATE_ATOM);
        $result = self::sendJsonRequest($url, $payload, $auth);

        $entry = [
            'webhook_id'    => $webhookId,
            'request_id'    => $requestId,
            'client_id'     => $clientId,
            'event'         => $event,
            'status'        => $result['ok'] ? 'delivered' : 'failed',
            'target_url'    => $url,
            'payload'       => $payload,
            'sent_at'       => $sentAt,
            'response_code' => $result['status_code'],
            'response_body' => $result['body'],
            'error'         => $result['error'],
        ];

        self::registerWebhook($entry);
        self::appendWebhookToInteraction($requestId, $entry, $clientId);
    }

    private static function spawnWebhookWorker(array $dispatch): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return false;
        }

        $dir = (defined('TMP') ? TMP : sys_get_temp_dir() . '/') . 'cslabs/dispatches';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $file = $dir . '/' . ($dispatch['webhook_id'] ?? bin2hex(random_bytes(8))) . '.json';
        if (file_put_contents($file, Json::pretty($dispatch)) === false) {
            return false;
        }

        $worker = (defined('APP') ? APP : __DIR__ . '/../') . 'bin/webhook-worker.php';
        $php    = PHP_BINARY !== '' ? PHP_BINARY : 'php';

        # Desconecta stdin/stdout/stderr e backgrounda — funciona em php -S,
        # mod_php, FPM e CLI. Independe de fastcgi_finish_request.
        $cmd = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellcmd($php),
            escapeshellarg($worker),
            escapeshellarg($file)
        );

        exec($cmd);
        return true;
    }

    private static function arrayGet(array $payload, string $path): mixed
    {
        $value = $payload;

        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }

    private static function accountNumberFromSeed(string $seed): string
    {
        return (string) (hexdec(substr(hash('sha256', $seed), 0, 8)) % 900000 + 100000);
    }

    /*
     * EndToEndId no formato SPI: `E` + ISPB (8) + YYYYMMDDHHMM (12) + 11 alfanuméricos.
     * Cada chamada gera um valor novo — o SPI emite no momento da transação,
     * então mesma chave/transação consultadas duas vezes devem produzir IDs distintos.
     */
    public static function generateEndToEndId(string $ispb = '13935893'): string
    {
        $ispb = preg_replace('/\D+/', '', $ispb) ?: '13935893';
        $ispb = str_pad(substr($ispb, 0, 8), 8, '0', STR_PAD_LEFT);
        $suffix = strtoupper(substr(bin2hex(random_bytes(8)), 0, 11));
        return 'E' . $ispb . date('YmdHi') . $suffix;
    }

    private static function pixDictError(string $scenario): array
    {
        $errors = [
            'fraud' => ['CPD0013', 'Chave Pix com dados restritos por marcação de fraude'],
            'not_found' => ['CPD0007', 'Chave Pix não encontrada.'],
            'blocked' => ['CPD0014', 'Chave Pix temporariamente bloqueada.'],
            'failed' => ['CPD0001', 'Falha ao consultar a chave Pix.'],
            'error' => ['OUTROCODIGO', 'Outro erro genérico'],
        ];

        [$code, $message] = $errors[$scenario] ?? $errors['error'];

        return [
            'status' => 'ERROR',
            'code' => [
                'errorCode' => $code,
                'message' => $message,
            ],
            'version' => '1.0.0',
        ];
    }

    private static function paymentError(string $scenario): array
    {
        self::$lastErrorScenario = $scenario;
        $errors = [
            'fraud' => ['CBE171', 'Transação bloqueada por suspeita de fraude. Contate o suporte para mais informações.'],
            'not_found' => ['CBE404', 'Transação ou recurso não encontrado.'],
            'blocked' => ['CBE172', 'Transação bloqueada para a conta informada.'],
            'failed' => ['CBE400', 'Transação rejeitada pela instituição recebedora.'],
            'error' => ['CBE500', 'Erro interno ao processar a transação.'],
            'insufficient_funds' => ['CBE301', 'Saldo insuficiente para concluir a operação.'],
            'key_not_found' => ['CBE189', 'Chave Pix não encontrada no DICT.'],
            'limit_exceeded' => ['CBE410', 'Valor excede o limite por transação configurado para a conta.'],
            'daily_limit' => ['CBE411', 'Limite diário de transações Pix excedido.'],
            'receiver_not_found' => ['CBE405', 'Conta destinatária não localizada na instituição informada.'],
            'invalid_key' => ['CBE190', 'Chave Pix inválida ou em formato não suportado.'],
            'invalid_document' => ['CBE007', 'CPF/CNPJ informado é inválido.'],
            'kyc_pending' => ['CBE401', 'Cliente possui processo KYC pendente. Operação indisponível.'],
            'timeout' => ['CBE504', 'Tempo de resposta do SPI excedido. Tente novamente em instantes.'],
            'bank_unavailable' => ['CBE503', 'Instituição financeira destinatária temporariamente indisponível.'],
            'duplicate' => ['CBE100', 'Existe um lançamento idêntico pendente. Aguarde para evitar duplicidade.'],
            'rate_limit' => ['CBE429', 'Limite de requisições excedido. Tente novamente em instantes.'],
            // Um amount 0,18 aqui é uso indevido: o modo existe só no POST spb/transfer.
            // Sem esta entrada cairia no fallback `error` e devolveria CBE500 — erro
            // plausível, causa invisível.
            'accept_then_timeout' => ['CSLAB501', 'Cenário accept_then_timeout (amount 0,18) só é implementado no POST spb/transfer.'],
        ];

        [$code, $message] = $errors[$scenario] ?? $errors['error'];

        return [
            'status' => 'ERROR',
            'error' => [
                'errorCode' => $code,
                'message' => $message,
            ],
            'version' => '1.0.0',
        ];
    }

    private static function billPaymentError(string $scenario): array
    {
        self::$lastErrorScenario = $scenario;
        $errors = [
            'fraud' => ['CSLAB403', 'Boleto bloqueado por suspeita de fraude.'],
            'not_found' => ['CSLAB404', 'Registro ou funcionalidade inexistente.'],
            'blocked' => ['CSLAB423', 'Boleto bloqueado para pagamento.'],
            'failed' => ['CSLAB400', 'Boleto rejeitado pela instituição recebedora.'],
            'error' => ['CSLAB500', 'Erro interno ao consultar boleto.'],
            'insufficient_funds' => ['CBE301', 'Saldo insuficiente para pagamento do boleto.'],
            'limit_exceeded' => ['CBE410', 'Valor do boleto excede o limite configurado para a conta.'],
            'daily_limit' => ['CBE411', 'Limite diário de pagamentos excedido.'],
            'invalid_document' => ['CBE007', 'CPF/CNPJ do pagador é inválido.'],
            'kyc_pending' => ['CBE401', 'Cliente possui KYC pendente. Pagamento de boleto indisponível.'],
            'timeout' => ['CBE504', 'Tempo de resposta da arrecadadora excedido.'],
            'bank_unavailable' => ['CBE503', 'Arrecadadora temporariamente indisponível.'],
            'duplicate' => ['CBE100', 'Boleto já em processamento. Aguarde para evitar duplicidade.'],
            'rate_limit' => ['CBE429', 'Limite de requisições excedido. Tente novamente em instantes.'],
            'key_not_found' => ['CSLAB404', 'Linha digitável não localizada.'],
            'receiver_not_found' => ['CSLAB404', 'Arrecadadora não localizada.'],
            'invalid_key' => ['CSLAB400', 'Linha digitável inválida.'],
        ];

        [$code, $message] = $errors[$scenario] ?? $errors['error'];

        return [
            'status' => 'ERROR',
            'error' => [
                'errorCode' => $code,
                'message' => $message,
            ],
            'version' => '1.0.0',
        ];
    }

    private static function missingOnboardingNaturalPersonFields(array $payload): ?string
    {
        $required = [
            'clientCode' => 'clientCode',
            'documentNumber' => 'documentNumber',
            'phoneNumber' => 'phoneNumber',
            'email' => 'email',
            'motherName' => 'motherName',
            'fullName' => 'fullName',
            'birthDate' => 'birthDate',
        ];

        foreach ($required as $field => $label) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                return $label;
            }
        }

        return self::missingAddressField($payload['address'] ?? null);
    }

    private static function missingOnboardingBusinessFields(array $payload): ?string
    {
        $required = [
            'clientCode' => 'clientCode',
            'documentNumber' => 'documentNumber',
            'contactNumber' => 'contactNumber',
            'businessEmail' => 'businessEmail',
            'businessName' => 'businessName',
        ];

        foreach ($required as $field => $label) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                return $label;
            }
        }

        $owner = $payload['owner'] ?? null;
        if (!is_array($owner) || $owner === []) {
            return 'owner';
        }

        return self::missingAddressField($payload['businessAddress'] ?? null);
    }

    private static function missingAddressField(mixed $address): ?string
    {
        if (!is_array($address)) {
            return 'address';
        }

        foreach (['postalCode', 'street', 'number', 'neighborhood', 'city', 'state'] as $field) {
            if (trim((string) ($address[$field] ?? '')) === '') {
                return $field;
            }
        }

        return null;
    }

    private static function onboardingValidationError(string $field): array
    {
        return [
            'status' => 'ERROR',
            'version' => '1.0.0',
            'error' => [
                'errorCode' => 'CBE014',
                'message' => $field . ' é obrigatório e deve conter um formato de texto válido.',
            ],
        ];
    }

    private static function onboardingError(string $scenario): array
    {
        $errors = [
            'fraud' => ['CBE006', 'Onboarding bloqueado por suspeita de fraude.'],
            'not_found' => ['CBE003', 'Cliente não encontrado para o documento informado.'],
            'blocked' => ['CBE007', 'Conta já existe ou está bloqueada para este documento.'],
            'failed' => ['CBE001', 'Dados de onboarding inválidos.'],
            'error' => ['CBE015', 'Erro interno ao processar onboarding.'],
        ];

        [$code, $message] = $errors[$scenario] ?? $errors['error'];

        return self::onboardingErrorWith($code, $message);
    }

    private static function onboardingErrorWith(string $code, string $message): array
    {
        return [
            'status' => 'ERROR',
            'version' => '1.0.0',
            'error' => [
                'errorCode' => $code,
                'message' => $message,
            ],
        ];
    }

    public static function onboardingDuplicateError(array $payload, string $kind): ?array
    {
        $clientCode = trim((string) ($payload['clientCode'] ?? ''));
        $documentNumber = self::onlyDigits((string) ($payload['documentNumber'] ?? ''));

        if ($kind === 'business') {
            $email = self::normalizeEmail((string) ($payload['businessEmail'] ?? ''));
            $phone = self::onlyDigits((string) ($payload['contactNumber'] ?? ''));
            $docCode = 'CBE025';
            $docMessage = 'Já existe uma conta vinculada a este CNPJ.';
        } else {
            $email = self::normalizeEmail((string) ($payload['email'] ?? ''));
            $phone = self::onlyDigits((string) ($payload['phoneNumber'] ?? ''));
            $docCode = 'CBE022';
            $docMessage = 'Já existe uma conta vinculada a este CPF.';
        }

        if ($documentNumber !== '' && self::readEntity('onboardings_by_document', $documentNumber) !== false) {
            return self::onboardingErrorWith($docCode, $docMessage);
        }
        if ($clientCode !== '' && self::readEntity('onboardings_by_client_code', $clientCode) !== false) {
            return self::onboardingErrorWith('CBE007', 'Conta já existe ou está bloqueada para o clientCode informado.');
        }
        if ($email !== '' && self::readEntity('onboardings_by_email', $email) !== false) {
            return self::onboardingErrorWith('CBE023', 'Já existe uma conta vinculada a este e-mail.');
        }
        if ($phone !== '' && self::readEntity('onboardings_by_phone', $phone) !== false) {
            return self::onboardingErrorWith('CBE024', 'Já existe uma conta vinculada a este telefone.');
        }

        return null;
    }

    private static function bulkInBatchDuplicate(
        array $item,
        string $kind,
        array &$seenDocs,
        array &$seenClientCodes,
        array &$seenEmails,
        array &$seenPhones
    ): ?array {
        $clientCode = trim((string) ($item['clientCode'] ?? ''));
        $documentNumber = self::onlyDigits((string) ($item['documentNumber'] ?? ''));
        $email = self::onboardingEmailKey($item, $kind);
        $phone = self::onboardingPhoneKey($item, $kind);
        $docCode = $kind === 'business' ? 'CBE025' : 'CBE022';
        $docMessage = $kind === 'business'
            ? 'Já existe uma conta vinculada a este CNPJ.'
            : 'Já existe uma conta vinculada a este CPF.';

        if ($documentNumber !== '' && isset($seenDocs[$documentNumber])) {
            return self::onboardingErrorWith($docCode, $docMessage);
        }
        if ($clientCode !== '' && isset($seenClientCodes[$clientCode])) {
            return self::onboardingErrorWith('CBE007', 'Conta já existe ou está bloqueada para o clientCode informado.');
        }
        if ($email !== '' && isset($seenEmails[$email])) {
            return self::onboardingErrorWith('CBE023', 'Já existe uma conta vinculada a este e-mail.');
        }
        if ($phone !== '' && isset($seenPhones[$phone])) {
            return self::onboardingErrorWith('CBE024', 'Já existe uma conta vinculada a este telefone.');
        }

        if ($documentNumber !== '') { $seenDocs[$documentNumber] = true; }
        if ($clientCode !== '') { $seenClientCodes[$clientCode] = true; }
        if ($email !== '') { $seenEmails[$email] = true; }
        if ($phone !== '') { $seenPhones[$phone] = true; }
        return null;
    }

    public static function onboardingEmailKey(array $payload, string $kind): string
    {
        $raw = $kind === 'business' ? ($payload['businessEmail'] ?? '') : ($payload['email'] ?? '');
        return self::normalizeEmail((string) $raw);
    }

    public static function onboardingPhoneKey(array $payload, string $kind): string
    {
        $raw = $kind === 'business' ? ($payload['contactNumber'] ?? '') : ($payload['phoneNumber'] ?? '');
        return self::onlyDigits((string) $raw);
    }

    private static function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private static function normalizeEmail(string $value): string
    {
        return strtolower(trim($value));
    }

    private static function chargeError(string $scenario): array
    {
        self::$lastErrorScenario = $scenario;
        $errors = [
            'fraud' => ['CSLAB403', 'Emissão bloqueada por suspeita de fraude.'],
            'not_found' => ['CSLAB404', 'Conta ou chave Pix não encontrada.'],
            'blocked' => ['CSLAB423', 'Conta bloqueada para emissão.'],
            'failed' => ['CSLAB400', 'Dados obrigatórios inválidos para emissão de boleto.'],
            'error' => ['CSLAB500', 'Erro interno ao emitir boleto.'],
            'insufficient_funds' => ['CBE301', 'Conta recebedora sem saldo mínimo para emissão.'],
            'key_not_found' => ['CBE189', 'Chave Pix da cobrança não encontrada no DICT.'],
            'invalid_key' => ['CBE190', 'Chave Pix da cobrança inválida.'],
            'limit_exceeded' => ['CBE410', 'Valor da cobrança excede o limite configurado.'],
            'daily_limit' => ['CBE411', 'Limite diário de emissão excedido.'],
            'invalid_document' => ['CBE007', 'CPF/CNPJ do beneficiário é inválido.'],
            'receiver_not_found' => ['CSLAB404', 'Conta beneficiária não localizada.'],
            'kyc_pending' => ['CBE401', 'Beneficiário possui KYC pendente. Emissão indisponível.'],
            'timeout' => ['CBE504', 'Tempo de resposta excedido ao emitir boleto.'],
            'bank_unavailable' => ['CBE503', 'Emissor temporariamente indisponível.'],
            'duplicate' => ['CBE100', 'Cobrança duplicada para o externalId informado.'],
            'rate_limit' => ['CBE429', 'Limite de requisições excedido. Tente novamente em instantes.'],
        ];

        [$code, $message] = $errors[$scenario] ?? $errors['error'];

        return [
            'status' => 'ERROR',
            'error' => [
                'errorCode' => $code,
                'message' => $message,
            ],
            'version' => '1.0.0',
        ];
    }

    private static function billPaymentValue(string $digits, string $seed): float
    {
        if (str_starts_with($digits, '8') && strlen($digits) >= 15) {
            $candidate = (int) substr($digits, 4, 11);

            if ($candidate > 0) {
                return round($candidate / 100, 2);
            }
        }

        if (strlen($digits) >= 10) {
            $candidate = (int) substr($digits, -10);

            if ($candidate > 0 && $candidate < 10000000) {
                return round($candidate / 100, 2);
            }
        }

        return round((1000 + (hexdec(substr($seed, 8, 6)) % 190000)) / 100, 2);
    }

    private static function billPaymentDueIso(string $digits, string $seed): string
    {
        $days = 3 + (hexdec(substr($seed, 14, 4)) % 40);

        if (strlen($digits) >= 33 && preg_match('/^[0-9]{47,48}$/', $digits)) {
            $factor = (int) substr($digits, 33, 4);

            if ($factor > 0) {
                $base = strtotime('1997-10-07');
                $resolved = strtotime('+' . $factor . ' days', $base);

                if ($resolved !== false && $resolved >= strtotime('-30 days') && $resolved <= strtotime('+5 years')) {
                    return date('Y-m-d\T00:00:00', $resolved);
                }
            }
        }

        return date('Y-m-d\T00:00:00', strtotime('+' . $days . ' days'));
    }

    private static function billPaymentRegisterData(string $assignor, float $value, string $dueIso, string $seed): array
    {
        $discount = (hexdec(substr($seed, 18, 2)) % 3) === 0 ? round($value * 0.02, 2) : 0;
        $interest = (hexdec(substr($seed, 20, 2)) % 4) === 0 ? round($value * 0.01, 2) : 0;
        $fine = (hexdec(substr($seed, 22, 2)) % 5) === 0 ? round($value * 0.02, 2) : 0;
        $additional = round($interest + $fine, 2);
        $totalUpdated = round($value - $discount + $additional, 2);
        $payDueDate = date('Y-m-d\T00:00:00', strtotime('+10 years', strtotime($dueIso)));
        $allowChangeValue = (hexdec(substr($seed, 24, 2)) % 4) === 0;
        $recipientDoc = self::pickBySeed($seed . 'recipient', ['60746948000112', '17189525000168', '00000000000191']);
        $payerDoc = self::pickBySeed($seed . 'document', ['06170097914', '11144477735', '12345678000195']);

        return [
            'documentRecipient' => self::formatDocument($recipientDoc),
            'documentPayer' => self::formatDocument($payerDoc),
            'payDueDate' => $payDueDate,
            'dueDateRegister' => $dueIso,
            'allowChangeValue' => $allowChangeValue,
            'recipient' => $assignor,
            'payer' => self::pickBySeed($seed . 'payer', ['CLIENTE HOMOLOGACAO', 'MARIA SILVA', 'JOAO SOUZA SANTOS', 'EMPRESA HOMOLOGACAO LTDA']),
            'discountValue' => $discount,
            'interestValueCalculated' => $interest,
            'maxValue' => $allowChangeValue ? round($totalUpdated * 1.05, 2) : $totalUpdated,
            'minValue' => $allowChangeValue ? round($totalUpdated * 0.95, 2) : $totalUpdated,
            'fineValueCalculated' => $fine,
            'originalValue' => $value,
            'totalUpdated' => $totalUpdated,
            'totalWithDiscount' => $discount > 0 ? round($value - $discount, 2) : 0,
            'totalWithAdditional' => $additional,
        ];
    }

    private static function formatDocument(string $digits): string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        if (strlen($digits) === 14) {
            return sprintf('%s.%s.%s/%s-%s', substr($digits, 0, 2), substr($digits, 2, 3), substr($digits, 5, 3), substr($digits, 8, 4), substr($digits, 12, 2));
        }

        if (strlen($digits) === 11) {
            return sprintf('%s.%s.%s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3), substr($digits, 9, 2));
        }

        return $digits;
    }

    private static function pickBySeed(string $seed, array $items): mixed
    {
        if ($items === []) {
            return null;
        }

        return $items[hexdec(substr(hash('sha256', $seed), 0, 6)) % count($items)];
    }

    private static function headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
                continue;
            }

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'AUTHORIZATION'], true)) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private static function sanitizeHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $headers[$name] = '[redacted]';
            }
        }

        return $headers;
    }

    private static function decodePayload(string $payload): array|string|null
    {
        if ($payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $payload;
    }

    private static function parseRequestBody(string $rawBody, array $headers): array|string|null
    {
        if ($rawBody === '') {
            return null;
        }

        $contentType = strtolower($headers['Content-Type'] ?? '');
        $decodedJson = json_decode($rawBody, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decodedJson;
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawBody, $parsed);
            return $parsed;
        }

        return $rawBody;
    }

    private static function extractBearerToken(string $authorization): ?string
    {
        if (!preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    private static function requestIp(): string
    {
        return function_exists('ip') ? ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private static function requestFingerprint(array $headers): string
    {
        return self::requestIp() . '|' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . '|' . ($headers['User-Agent'] ?? '');
    }

    private static function credentialSeed(array|string|null $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $clientId = trim((string) ($body['client_id'] ?? ''));
        $clientSecret = trim((string) ($body['client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        return $clientId . '|' . $clientSecret;
    }

    private static function clientIdFromSeed(string $seed): string
    {
        return substr(hash('sha256', $seed), 0, 24);
    }

    private static function resolveClientIdFromToken(string $token): ?string
    {
        $tokenData = self::readEntity('issued_tokens', hash('sha256', $token), '__global__');

        if (!is_array($tokenData) || empty($tokenData['client_id'])) {
            return null;
        }

        return (string) $tokenData['client_id'];
    }

    private static function resolveWorkerId(string $clientId, string $ip, array $headers): string
    {
        $ipHash = hash('sha256', $ip);
        $stmt = Db::pdo()->prepare('SELECT worker_id FROM client_origins WHERE client_id = :c AND ip_hash = :h LIMIT 1');
        $stmt->execute([':c' => $clientId, ':h' => $ipHash]);
        $existing = $stmt->fetchColumn();
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return 'wrk_' . bin2hex(random_bytes(8));
    }

    private static function upsertWorker(string $clientId, string $workerId, string $ip, ?string $authHint, string $lastSeenAt): void
    {
        $payload = [
            'worker_id'    => $workerId,
            'client_id'    => $clientId,
            'ip'           => $ip,
            'auth_hint'    => $authHint,
            'last_seen_at' => $lastSeenAt,
        ];

        $stmt = Db::pdo()->prepare(<<<'SQL'
            INSERT INTO client_workers
                (client_id, worker_id, ip, auth_hint, last_seen_at, data)
            VALUES
                (:c, :w, :i, :a, :l, :d)
            ON CONFLICT(client_id, worker_id) DO UPDATE SET
                ip           = excluded.ip,
                auth_hint    = excluded.auth_hint,
                last_seen_at = excluded.last_seen_at,
                data         = excluded.data
        SQL);
        $stmt->execute([
            ':c' => $clientId,
            ':w' => $workerId,
            ':i' => $ip,
            ':a' => $authHint,
            ':l' => $lastSeenAt,
            ':d' => Json::pretty($payload),
        ]);
    }

    private static function touchWorkerOrigin(string $clientId, string $ip, string $workerId, array $headers): void
    {
        $ipHash = hash('sha256', $ip);
        $now = date(DATE_ATOM);
        $userAgent = $headers['User-Agent'] ?? null;

        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT data FROM client_origins WHERE client_id = :c AND ip_hash = :h LIMIT 1');
        $stmt->execute([':c' => $clientId, ':h' => $ipHash]);
        $existingRaw = $stmt->fetchColumn();

        $payload = [];
        if ($existingRaw !== false) {
            $decoded = json_decode((string) $existingRaw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $payload['worker_id']     = $workerId;
        $payload['client_id']     = $clientId;
        $payload['ip']            = $ip;
        $payload['user_agent']    = $payload['user_agent'] ?? $userAgent;
        $payload['first_seen_at'] = $payload['first_seen_at'] ?? $now;
        $payload['last_seen_at']  = $now;

        $upsert = $pdo->prepare(<<<'SQL'
            INSERT INTO client_origins
                (client_id, ip_hash, ip, worker_id, user_agent, first_seen_at, last_seen_at, data)
            VALUES
                (:c, :h, :i, :w, :u, :f, :l, :d)
            ON CONFLICT(client_id, ip_hash) DO UPDATE SET
                ip            = excluded.ip,
                worker_id     = excluded.worker_id,
                user_agent    = COALESCE(client_origins.user_agent, excluded.user_agent),
                last_seen_at  = excluded.last_seen_at,
                data          = excluded.data
        SQL);
        $upsert->execute([
            ':c' => $clientId,
            ':h' => $ipHash,
            ':i' => $ip,
            ':w' => $workerId,
            ':u' => $payload['user_agent'],
            ':f' => $payload['first_seen_at'],
            ':l' => $now,
            ':d' => Json::pretty($payload),
        ]);
    }

    private static function registerWebhook(array $entry): void
    {
        $clientId = (string) ($entry['client_id'] ?? self::context()['client_id']);
        $webhookId = self::safeName((string) ($entry['webhook_id'] ?? ('wh_' . bin2hex(random_bytes(8)))));
        $now = date(DATE_ATOM);

        $stmt = Db::pdo()->prepare(<<<'SQL'
            INSERT INTO webhook_dispatches
                (client_id, webhook_id, request_id, event, status, target_url, created_at, updated_at, data)
            VALUES
                (:c, :w, :r, :e, :s, :u, :ca, :ua, :d)
            ON CONFLICT(client_id, webhook_id) DO UPDATE SET
                request_id = excluded.request_id,
                event      = excluded.event,
                status     = excluded.status,
                target_url = excluded.target_url,
                updated_at = excluded.updated_at,
                data       = excluded.data
        SQL);
        $stmt->execute([
            ':c'  => $clientId,
            ':w'  => $webhookId,
            ':r'  => $entry['request_id'] ?? null,
            ':e'  => $entry['event'] ?? null,
            ':s'  => $entry['status'] ?? null,
            ':u'  => $entry['target_url'] ?? null,
            ':ca' => $now,
            ':ua' => $now,
            ':d'  => Json::pretty($entry),
        ]);
    }

    private static function appendWebhookToInteraction(string $requestId, array $entry, ?string $clientId = null): void
    {
        if ($clientId === null || $clientId === '') {
            $clientId = self::context()['client_id'];
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT data FROM interactions WHERE client_id = :c AND request_id = :r LIMIT 1');
        $stmt->execute([':c' => $clientId, ':r' => $requestId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return;
        }
        $interaction = json_decode((string) $raw, true);
        if (!is_array($interaction)) {
            return;
        }

        $interaction['webhooks'] ??= [];
        $interaction['webhooks'][] = $entry;

        $update = $pdo->prepare('UPDATE interactions SET data = :d WHERE client_id = :c AND request_id = :r');
        $update->execute([
            ':d' => Json::pretty($interaction),
            ':c' => $clientId,
            ':r' => $requestId,
        ]);
    }

    private static function sendJsonRequest(string $url, array $payload, ?array $auth = null): array
    {
        $curl = curl_init();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen((string) $body),
        ];

        if (is_array($auth) && strtolower((string) ($auth['type'] ?? '')) === 'basic') {
            $login = (string) ($auth['login'] ?? '');
            $password = (string) ($auth['pwd'] ?? $auth['password'] ?? '');
            if ($login !== '') {
                $headers[] = 'Authorization: Basic ' . base64_encode($login . ':' . $password);
            }
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl) ?: null;
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [
            'ok' => $error === null && $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'body' => $response ?: null,
            'error' => $error,
        ];
    }

    private static function safeName(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9._-]/', '_', $value) ?: 'item';
        return trim($value, '._-') ?: 'item';
    }

    public static function onboardingProposalCreateResponse(array $payload, string $kind): array
    {
        $scenario = self::scenarioFromPayload($payload, [
            'scenario', 'mockScenario', 'mock_scenario',
            'fullName', 'businessName', 'documentNumber',
        ]);

        $missing = $kind === 'legal-person'
            ? self::missingProposalLegalFields($payload)
            : self::missingProposalNaturalFields($payload);

        if ($missing !== null) {
            return self::onboardingValidationError($missing);
        }

        if ($scenario !== 'success') {
            return self::onboardingError($scenario);
        }

        $proposalId = gerarHashMock();
        $clientCode = (string) ($payload['clientCode'] ?? '');
        $documentNumber = preg_replace('/\D+/', '', (string) ($payload['documentNumber'] ?? '')) ?: '';

        return [
            'version' => '1.0.0',
            'status' => 'PROCESSING',
            'body' => [
                'proposalId' => $proposalId,
                'clientCode' => $clientCode,
                'documentNumber' => $documentNumber,
                'proposalStatus' => 'CREATED',
                'createDate' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    public static function onboardingProposalListResponse(array $query): array
    {
        $page = max(1, (int) ($query['Page'] ?? 1));
        $limit = max(1, min(200, (int) ($query['LimitPerPage'] ?? $query['Limit'] ?? 200)));

        $filters = [
            'ProposalId' => trim((string) ($query['ProposalId'] ?? '')),
            'ClientCode' => trim((string) ($query['ClientCode'] ?? '')),
            'DocumentNumber' => trim((string) ($query['DocumentNumber'] ?? '')),
            'Status' => strtoupper(trim((string) ($query['Status'] ?? ''))),
        ];

        $all = [];
        foreach (self::listEntities('onboarding_proposals') as $row) {
            if ($filters['ProposalId'] !== '' && ($row['proposalId'] ?? '') !== $filters['ProposalId']) {
                continue;
            }
            if ($filters['ClientCode'] !== '' && ($row['clientCode'] ?? '') !== $filters['ClientCode']) {
                continue;
            }
            if ($filters['DocumentNumber'] !== '' && ($row['documentNumber'] ?? '') !== $filters['DocumentNumber']) {
                continue;
            }
            if ($filters['Status'] !== '' && strtoupper((string) ($row['proposalStatus'] ?? '')) !== $filters['Status']) {
                continue;
            }
            $all[] = $row;
        }

        $total = count($all);
        $offset = ($page - 1) * $limit;
        $slice = array_slice($all, $offset, $limit);

        // Real: body.proposal[] (singular), item com status(enum)/proposalType/
        // createdAt/updatedAt/documentscopys[]. Ver HOMOLOGACAO_CELCOIN_V2.md Apêndice A.
        $statusMap = ['CREATED' => 'RESOURCE_CREATED'];
        $items = [];
        foreach ($slice as $row) {
            $proposalId = (string) ($row['proposalId'] ?? '');
            $document = (string) ($row['documentNumber'] ?? '');
            $status = strtoupper((string) ($row['status'] ?? $row['proposalStatus'] ?? 'CREATED'));
            $status = $statusMap[$status] ?? $status;
            $type = strtoupper((string) ($row['proposalType'] ?? ''));
            if ($type === '') {
                $type = strlen(preg_replace('/\D+/', '', $document)) === 14 ? 'PJ' : 'PF';
            }
            $created = (string) ($row['createdAt'] ?? $row['createDate'] ?? gmdate('Y-m-d\TH:i:s.v\Z'));
            $updated = (string) ($row['updatedAt'] ?? $created);

            $items[] = [
                'proposalId' => $proposalId,
                'clientCode' => (string) ($row['clientCode'] ?? ''),
                'documentNumber' => $document,
                'status' => $status,
                'proposalType' => $type,
                'createdAt' => $created,
                'updatedAt' => $updated,
                'documentscopys' => [[
                    'proposalId' => $proposalId,
                    'documentNumber' => $document,
                    'documentscopyId' => substr(hash('sha256', $proposalId . '|doc'), 0, 24),
                    'status' => $status === 'REPROVED' ? 'REPROVED' : 'PENDING',
                    'url' => 'https://cadastro.io/' . substr(hash('sha256', $proposalId . '|url'), 0, 32),
                    'createdAt' => $created,
                    'updateAt' => $updated, // sic: real usa "updateAt" (sem d) dentro de documentscopys
                ]],
            ];
        }

        return [
            'version' => '1.0.0',
            'status' => 'SUCCESS',
            'body' => [
                'limit' => $limit,
                'currentPage' => $page,
                'limitPerPage' => $limit,
                'totalPages' => $total === 0 ? 0 : (int) ceil($total / $limit),
                'totalItems' => $total,
                'proposal' => $items,
            ],
        ];
    }

    public static function walletMovementResponse(array $query): array
    {
        $account = trim((string) ($query['Account'] ?? ''));
        $document = preg_replace('/\D+/', '', (string) ($query['DocumentNumber'] ?? '')) ?: '';
        $dateFrom = trim((string) ($query['DateFrom'] ?? ''));
        $dateTo = trim((string) ($query['DateTo'] ?? ''));
        $order = strtolower(trim((string) ($query['Order'] ?? 'asc')));
        $page = max(1, (int) ($query['Page'] ?? 1));
        $limit = max(1, (int) ($query['LimitPerPage'] ?? 100));

        if ($dateFrom === '' || $dateTo === '') {
            return [
                'version' => '1.0.0',
                'status' => 'ERROR',
                'error' => ['errorCode' => 'CBE014', 'message' => 'DateFrom e DateTo são obrigatórios (yyyy-MM-dd).'],
            ];
        }

        $seed = hash('sha256', $account . '|' . $document . '|' . $dateFrom);
        $count = (hexdec(substr($seed, 0, 2)) % 4) + 2; // 2..5 movimentações
        $movements = [];
        $baseTs = strtotime($dateFrom . ' 09:00:00') ?: time();

        /*
         * Shape real (HOMOLOGACAO_CELCOIN_V2.md §15/B.1): paginação e janela ficam
         * no TOPO, irmãs de `body` — não dentro dele; `body` só tem account,
         * documentNumber e movements[]. O item real usa `balanceType` (DEBIT/CREDIT)
         * + `movementType` (PIXPAYMENTOUT/PIXREVERSALIN/…), `status` é texto em
         * português ("Saldo Liberado") e os nomes/saldos vêm em `additionalInformation`
         * — não existe counterParty/movementCode/balanceAfter no real.
         * Vale para os dois paths: nenhum consumidor v1 lê campo deste extrato
         * (CelcoinBaas::extrato só devolve o corpo cru pra log).
         */
        $balance = 3000000.00;

        for ($i = 0; $i < $count; $i++) {
            $chunk = substr($seed, $i * 4, 8);
            $isCredit = (hexdec(substr($chunk, 0, 2)) % 2) === 0;
            $amount = round((hexdec(substr($chunk, 2, 4)) % 50000) / 100 + 10, 2);
            $oldBalance = $balance;
            $balance = round($balance + ($isCredit ? $amount : -$amount), 2);
            $ts = $baseTs + ($i * 600);

            $movements[] = [
                'id' => gerarHashMock(),
                // Em reversão o real manda clientCode/description null.
                'clientCode' => $isCredit ? null : str_pad((string) (30000 + $i), 7, '0', STR_PAD_LEFT),
                'description' => $isCredit ? null : 'pix',
                'createDate' => gmdate('Y-m-d\TH:i:s', $ts),
                'lastUpdateDate' => gmdate('Y-m-d\TH:i:s', $ts),
                'amount' => $amount,
                'status' => 'Saldo Liberado',
                'balanceType' => $isCredit ? 'CREDIT' : 'DEBIT',
                'movementType' => $isCredit ? 'PIXREVERSALIN' : 'PIXPAYMENTOUT',
                'additionalInformation' => [
                    'nameCredit' => $isCredit ? 'MARIA RECEBEDORA' : 'JOAO PAGADOR',
                    'nameDebit' => $isCredit ? 'JOAO PAGADOR' : 'MARIA RECEBEDORA',
                    'oldBalance' => $oldBalance,
                    'currentBalance' => $balance,
                ],
            ];
        }

        $movements = $order === 'desc' ? array_reverse($movements) : $movements;
        $total = count($movements);

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'totalItems' => $total,
            'currentPage' => $page,
            'limitPerPage' => $limit,
            'totalPages' => (int) ceil($total / $limit),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'body' => [
                'account' => $account !== '' ? $account : self::accountNumberFromSeed($seed),
                'documentNumber' => $document,
                'movements' => array_slice($movements, ($page - 1) * $limit, $limit),
            ],
        ];
    }

    public static function consolidatedStatementResponse(array $query): array
    {
        $startDate = trim((string) ($query['startDate'] ?? ''));
        $endDate = trim((string) ($query['endDate'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $quantity = max(1, (int) ($query['quantity'] ?? 1000));

        if ($startDate === '' || $endDate === '') {
            return [
                'status' => 'ERROR',
                'error' => ['errorCode' => 'AttributeValidation', 'message' => 'startDate e endDate são obrigatórios.'],
            ];
        }

        $startTs = strtotime($startDate);
        $endTs = strtotime($endDate);
        if ($startTs === false || $endTs === false || $endTs < $startTs) {
            return [
                'status' => 'ERROR',
                'error' => ['errorCode' => 'DateValidation', 'message' => 'Período inválido (máx 15 dias).'],
            ];
        }
        if (($endTs - $startTs) > (15 * 86400)) {
            return [
                'status' => 'ERROR',
                'error' => ['errorCode' => 'DateValidation', 'message' => 'Janela máxima é de 15 dias.'],
            ];
        }

        $data = [];
        $saldo = 3000000.00;
        $nsa = 1;
        $tipos = [
            ['nomeHistorico' => 'PIX RECEBIDO',  'credito' => 1500.00, 'debito' => 0.00,    'historicoId' => 101],
            ['nomeHistorico' => 'PIX ENVIADO',   'credito' => 0.00,    'debito' => 850.00,  'historicoId' => 102],
            ['nomeHistorico' => 'TARIFA TED',    'credito' => 0.00,    'debito' => 8.50,    'historicoId' => 201],
            ['nomeHistorico' => 'BOLETO PAGO',   'credito' => 0.00,    'debito' => 320.00,  'historicoId' => 301],
            ['nomeHistorico' => 'TED RECEBIDA',  'credito' => 5000.00, 'debito' => 0.00,    'historicoId' => 104],
        ];

        for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
            $diaCredito = 0.00;
            $diaDebito = 0.00;
            $diaOps = 0;
            $linhasDia = [];
            $seed = hash('sha256', date('Y-m-d', $ts));
            $linhas = (hexdec(substr($seed, 0, 2)) % 3) + 1;
            for ($i = 0; $i < $linhas; $i++) {
                $tipo = $tipos[hexdec(substr($seed, $i * 2, 2)) % count($tipos)];
                $linhasDia[] = $tipo;
                $diaCredito += $tipo['credito'];
                $diaDebito += $tipo['debito'];
                $diaOps += 1 + (hexdec(substr($seed, ($i * 2) + 4, 2)) % 3);
            }
            $saldo = round($saldo + $diaCredito - $diaDebito, 2);
            foreach ($linhasDia as $linha) {
                $data[] = [
                    'data' => date('Y-m-d', $ts),
                    'dataContabil' => date('Y-m-d', $ts),
                    'nomeHistorico' => $linha['nomeHistorico'],
                    'qtdOperacoes' => 1,
                    'debito' => round($linha['debito'], 2),
                    'credito' => round($linha['credito'], 2),
                    'saldoDia' => round($diaCredito - $diaDebito, 2),
                    'saldo' => $saldo,
                    'historicoId' => $linha['historicoId'],
                    'nsa' => $nsa++,
                ];
            }
        }

        $total = count($data);
        $offset = ($page - 1) * $quantity;

        return [
            'status' => 'SUCCESS',
            'body' => [
                'records' => array_slice($data, $offset, $quantity),
                'totalRecords' => $total,
                'page' => $page,
                'quantity' => $quantity,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ],
        ];
    }

    public static function exportFileTypesResponse(): array
    {
        return [
            'status' => 'SUCCESS',
            'body' => [
                'types' => [
                    ['filetype' => 1,  'description' => 'Movimentação'],
                    ['filetype' => 2,  'description' => 'Recusas'],
                    ['filetype' => 3,  'description' => 'Transferências'],
                    ['filetype' => 4,  'description' => 'Pix enviados'],
                    ['filetype' => 5,  'description' => 'Pix recebidos'],
                    ['filetype' => 6,  'description' => 'Pix devoluções'],
                    ['filetype' => 7,  'description' => 'Boletos pagos'],
                    ['filetype' => 8,  'description' => 'Boletos emitidos'],
                    ['filetype' => 9,  'description' => 'Débito veicular'],
                    ['filetype' => 10, 'description' => 'Recargas'],
                    ['filetype' => 11, 'description' => 'TED enviadas'],
                    ['filetype' => 12, 'description' => 'TED recebidas'],
                    ['filetype' => 13, 'description' => 'Tarifas'],
                    ['filetype' => 14, 'description' => 'IOF'],
                    ['filetype' => 15, 'description' => 'Bloqueios judiciais'],
                ],
            ],
        ];
    }

    public static function incomeReportResponse(array $query): array
    {
        $account = trim((string) ($query['Account'] ?? ''));
        $calendarYear = trim((string) ($query['CalendarYear'] ?? date('Y')));
        $quarter = trim((string) ($query['Quarter'] ?? ''));

        if ($account === '') {
            return [
                'version' => '1.0.0',
                'status' => 'ERROR',
                'error' => ['errorCode' => 'CBE014', 'message' => 'Account é obrigatório.'],
            ];
        }

        $seed = hash('sha256', $account . '|' . $calendarYear . '|' . $quarter);
        $isLegal = (hexdec(substr($seed, 0, 2)) % 4) === 0;
        $documentNumber = $isLegal
            ? str_pad((string) (hexdec(substr($seed, 0, 6)) % 99999999999999), 14, '0', STR_PAD_LEFT)
            : str_pad((string) (hexdec(substr($seed, 0, 6)) % 99999999999), 11, '0', STR_PAD_LEFT);

        $balance = round(10000 + (hexdec(substr($seed, 0, 4)) % 9999999) / 100, 2);
        $pdfText = sprintf(
            "Celcoin - Informe de Rendimentos\nAno-calendário: %s\nConta: %s\nDocumento: %s\nSaldo: R$ %s\n",
            $calendarYear,
            $account,
            $documentNumber,
            number_format($balance, 2, ',', '.')
        );
        $incomeFile = base64_encode("%PDF-1.4\n%mock\n" . $pdfText);

        return [
            'version' => '1.0.0',
            'status' => 'SUCCESS',
            'body' => [
                'payerSource' => [
                    'name' => 'CELCOIN INSTITUICAO DE PAGAMENTO S.A.',
                    'documentNumber' => '13935893000109',
                ],
                'owner' => [
                    'documentNumber' => $documentNumber,
                    'name' => $isLegal ? 'EMPRESA HOMOLOGACAO LTDA' : 'CLIENTE HOMOLOGACAO',
                    'type' => $isLegal ? 'LEGAL_PERSON' : 'NATURAL_PERSON',
                    'createDate' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
                'account' => [
                    'branch' => '0001',
                    'account' => $account,
                ],
                'balances' => [
                    [
                        'calendarYear' => $calendarYear,
                        'amount' => $balance,
                        'currency' => 'BRL',
                        'type' => 'SALDO',
                    ],
                ],
                'incomeFile' => $incomeFile,
                'fileType' => 'application/pdf',
            ],
        ];
    }

    private static function missingProposalNaturalFields(array $payload): ?string
    {
        $required = ['clientCode', 'documentNumber', 'phoneNumber', 'email', 'motherName', 'fullName', 'birthDate', 'address'];
        foreach ($required as $field) {
            if (!isset($payload[$field]) || (is_string($payload[$field]) && trim($payload[$field]) === '')) {
                return $field;
            }
        }
        return null;
    }

    private static function missingProposalLegalFields(array $payload): ?string
    {
        $required = ['clientCode', 'contactNumber', 'documentNumber', 'businessEmail', 'businessName', 'tradingName', 'owner', 'businessAddress'];
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                return $field;
            }
            if (is_string($payload[$field]) && trim($payload[$field]) === '') {
                return $field;
            }
            if ($field === 'owner' && (!is_array($payload[$field]) || count($payload[$field]) === 0)) {
                return $field;
            }
        }
        return null;
    }

    public static function onboardingBulkResponse(array $payload, string $kind): array
    {
        $items = $payload;
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
        }

        if (!is_array($items) || $items === [] || !isset($items[0])) {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE001', 'message' => 'Body deve ser array de contas ou objeto com chave "items".'],
            ];
        }

        $results = [];
        $accepted = 0;
        $rejected = 0;
        $seenDocs = [];
        $seenClientCodes = [];
        $seenEmails = [];
        $seenPhones = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $results[] = [
                    'index' => $index,
                    'status' => 'ERROR',
                    'error' => ['errorCode' => 'CBE001', 'message' => 'Item inválido.'],
                ];
                $rejected++;
                continue;
            }

            $single = self::onboardingResponse($item, $kind);
            if (($single['status'] ?? null) !== 'ERROR') {
                $inBatchDup = self::bulkInBatchDuplicate($item, $kind, $seenDocs, $seenClientCodes, $seenEmails, $seenPhones);
                if ($inBatchDup === null) {
                    $inBatchDup = self::onboardingDuplicateError($item, $kind);
                }
                if ($inBatchDup !== null) {
                    $single = $inBatchDup;
                }
            }

            if (($single['status'] ?? null) === 'ERROR') {
                $results[] = [
                    'index' => $index,
                    'clientCode' => (string) ($item['clientCode'] ?? ''),
                    'documentNumber' => preg_replace('/\D+/', '', (string) ($item['documentNumber'] ?? '')) ?: '',
                    'status' => 'ERROR',
                    'error' => $single['error'],
                ];
                $rejected++;
            } else {
                $results[] = [
                    'index' => $index,
                    'clientCode' => (string) ($item['clientCode'] ?? ''),
                    'documentNumber' => preg_replace('/\D+/', '', (string) ($item['documentNumber'] ?? '')) ?: '',
                    'status' => 'PROCESSING',
                    'onBoardingId' => $single['body']['onBoardingId'],
                ];
                $accepted++;
            }
        }

        return [
            'version' => '1.0.0',
            'status' => $rejected === 0 ? 'PROCESSING' : ($accepted === 0 ? 'ERROR' : 'PARTIAL'),
            'body' => [
                'items' => $results,
                'totalItems' => count($results),
                'accepted' => $accepted,
                'rejected' => $rejected,
            ],
        ];
    }

    /**
     * Quantos envios do MESMO tipo de documento o mesmo documentNumber pode fazer.
     *
     * A Celcoin real bloqueia depois de algumas tentativas, mas NUNCA nos informou o
     * numero — o que sabemos e o efeito, medido em producao (bcbr, 28/07/2026, conta
     * 3098): a partir de certo ponto o upload passa a devolver HTTP 400 dizendo que
     * o limite de envios para RG foi atingido, e a conta trava sem poder corrigir o
     * documento. Aqui sao 3 para a bateria de teste ser curta; nao e a afirmacao de
     * que o limite dela seja 3.
     */
    public const KYC_LIMITE_ENVIOS_POR_DOCUMENTO = 3;

    /**
     * Erro de cota de envio de documento, no shape PLANO que a Celcoin devolveu em
     * producao — diferente do envelope {status, version, error} do resto da API:
     *
     *   {"errorCode":400,"errorMessage":"Você atingiu o limite máximo de envios
     *    para RG, por favor entre em contato com suporte"}
     *
     * Copiado do log do bcbr de 28/07/2026 13:58:57. O stream reconhece o shape pelo
     * errorCode no topo e devolve HTTP 400 sem envelopar.
     */
    public static function kycQuotaExcedidaResponse(string $filetype): array
    {
        return [
            'errorCode' => 400,
            'errorMessage' => 'Você atingiu o limite máximo de envios para ' . $filetype
                . ', por favor entre em contato com suporte',
        ];
    }

    /**
     * UUID v4-shaped estavel para um documento sem onboarding registrado.
     *
     * Deterministico de proposito: o mesmo documento precisa produzir sempre o mesmo
     * onboardingId, senao o webhook PENDING e o de veredito chegariam com ids
     * diferentes e o consumidor nao conseguiria parear.
     */
    private static function onboardingIdDeterministico(string $documento): string
    {
        $hash = hash('sha256', 'kyc-onboarding:' . $documento);

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 1)) & 0x3) | 0x8),
            substr($hash, 17, 3),
            substr($hash, 20, 12)
        );
    }

    public static function kycFileUploadResponse(array $form, array $files): array
    {
        // KYC v1: campos em lowercase sem separador, conforme doc oficial.
        $cnpj = trim((string) ($form['cnpj'] ?? ''));
        $documentNumber = trim((string) ($form['documentnumber'] ?? ''));
        $filetype = strtoupper(trim((string) ($form['filetype'] ?? '')));
        $onboardingId = trim((string) ($form['onboardingId'] ?? ''));
        $front = $files['front'] ?? null;

        $allowedTypes = ['CNH', 'RG', 'PASSPORT', 'RNE'];

        if ($documentNumber === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'documentnumber é obrigatório (multipart text, lowercase).'],
            ];
        }
        if (!in_array($filetype, $allowedTypes, true)) {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'filetype inválido. Aceita: ' . implode(', ', $allowedTypes) . '.'],
            ];
        }
        if (!is_array($front) || ($front['size'] ?? 0) <= 0) {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'front é obrigatório (multipart file).'],
            ];
        }

        $documentoLimpo = preg_replace('/\D+/', '', $documentNumber) ?: $documentNumber;

        // onboardingId e OPCIONAL: quem identifica o titular aqui e o documento.
        //
        // Ate 28/07/2026 este builder exigia o campo e devolvia CBE014 sem ele. Errado:
        // nenhum dos seis call sites do banco digital envia onboardingId no multipart
        // (o postData tem cpf, documentnumber, filetype, front e verse), e a Celcoin
        // REAL aceita assim — em producao ela chegou a devolver o 400 de cota para essa
        // mesma requisicao, ou seja, contou o envio. Exigindo o campo, todo envio do app
        // morria em CBE014 antes do contador, e o mock ficava inutil justamente para
        // reproduzir o caso que ele existe para reproduzir. Achado pela bateria de QA em
        // sustenance/dev/2026/07-28-bateria-qa-kyc-sms/.
        if ($onboardingId === '') {
            $vinculo = self::readEntity('onboardings_by_document', $documentoLimpo);
            $onboardingId = is_array($vinculo) ? trim((string) ($vinculo['onboardingId'] ?? '')) : '';
        }

        if ($onboardingId === '') {
            // Documento sem onboarding conhecido neste mock. Id ESTAVEL derivado do
            // documento, para que os dois webhooks (PENDING e veredito) cheguem com o
            // mesmo identificador e o consumidor consiga parear.
            $onboardingId = self::onboardingIdDeterministico($documentoLimpo);
        }

        $chaveCota = $documentoLimpo . ':' . $filetype;
        $cota = self::readEntity('kyc_upload_quota', $chaveCota);
        $jaEnviados = (int) (is_array($cota) ? ($cota['count'] ?? 0) : 0);

        if ($jaEnviados >= self::KYC_LIMITE_ENVIOS_POR_DOCUMENTO) {
            return self::kycQuotaExcedidaResponse($filetype);
        }

        $fileId = gerarHashMock();
        $record = [
            'fileId' => $fileId,
            'onboardingId' => $onboardingId,
            'documentNumber' => $documentoLimpo,
            'cnpj' => preg_replace('/\D+/', '', $cnpj) ?: $cnpj,
            'filetype' => $filetype,
            'originalFileName' => (string) ($front['name'] ?? 'upload.bin'),
            'fileSize' => (int) ($front['size'] ?? 0),
            'mimeType' => (string) ($front['type'] ?? 'application/octet-stream'),
            'status' => 'PROCESSING',
            'createDate' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        Db::transaction(function () use ($fileId, $onboardingId, $record, $chaveCota, $jaEnviados) {
            self::writeEntity('kyc_uploads', $fileId, $record);
            self::writeEntity('kyc_uploads_by_onboarding', $onboardingId, ['fileId' => $fileId]);
            self::writeEntity('kyc_upload_quota', $chaveCota, [
                'entity' => $chaveCota,
                'count' => $jaEnviados + 1,
                'limit' => self::KYC_LIMITE_ENVIOS_POR_DOCUMENTO,
                'lastFileId' => $fileId,
                'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        });

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => [
                'fileId' => $fileId,
                'onboardingId' => $onboardingId,
                'filetype' => $filetype,
                'status' => 'PROCESSING',
                'createDate' => $record['createDate'],
            ],
        ];
    }

    public static function exportFileResponse(array $query): array
    {
        $filetype = (int) ($query['filetype'] ?? 0);
        $accountdate = trim((string) ($query['accountdate'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $quantity = max(1, (int) ($query['quantity'] ?? 1000));

        if ($filetype <= 0) {
            return [
                'status' => 'ERROR',
                'error' => ['errorCode' => 'AttributeValidation', 'message' => 'filetype é obrigatório (numérico).'],
            ];
        }
        if ($accountdate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $accountdate)) {
            return [
                'status' => 'ERROR',
                'error' => ['errorCode' => 'AttributeValidation', 'message' => 'accountdate é obrigatório no formato YYYY-MM-DD.'],
            ];
        }

        $typeMap = [
            1  => 'Movimentação',
            2  => 'Recusas',
            3  => 'Transferências',
            4  => 'Pix enviados',
            5  => 'Pix recebidos',
            6  => 'Pix devoluções',
            7  => 'Boletos pagos',
            8  => 'Boletos emitidos',
            9  => 'Débito veicular',
            10 => 'Recargas',
            11 => 'TED enviadas',
            12 => 'TED recebidas',
            13 => 'Tarifas',
            14 => 'IOF',
            15 => 'Bloqueios judiciais',
        ];

        if (!isset($typeMap[$filetype])) {
            return [
                'status' => 'ERROR',
                'error' => ['errorCode' => 'FileNotFound', 'message' => 'filetype não encontrado. Consulte /exportfile/types.'],
            ];
        }

        $records = self::exportFileRecordsByType($filetype, $accountdate);
        $total = count($records);
        $offset = ($page - 1) * $quantity;

        return [
            'status' => 'SUCCESS',
            'body' => [
                'filetype' => $filetype,
                'description' => $typeMap[$filetype],
                'accountdate' => $accountdate,
                'records' => array_slice($records, $offset, $quantity),
                'totalRecords' => $total,
                'page' => $page,
                'quantity' => $quantity,
            ],
        ];
    }

    private static function exportFileRecordsByType(int $filetype, string $accountdate): array
    {
        $seed = hash('sha256', $filetype . '|' . $accountdate);
        $count = (hexdec(substr($seed, 0, 2)) % 4) + 2; // 2..5
        $records = [];
        $baseTs = strtotime($accountdate . ' 09:00:00') ?: time();

        for ($i = 0; $i < $count; $i++) {
            $chunk = substr($seed, $i * 6, 12) ?: $seed;
            $ts = $baseTs + ($i * 1800);
            $amount = round((hexdec(substr($chunk, 0, 4)) % 50000) / 100 + 5, 2);
            $records[] = self::exportFileRecordSchema($filetype, $i, $chunk, $ts, $amount);
        }
        return $records;
    }

    private static function exportFileRecordSchema(int $filetype, int $index, string $chunk, int $ts, float $amount): array
    {
        $id = gerarHashMock();
        $date = gmdate('Y-m-d\TH:i:s\Z', $ts);

        switch ($filetype) {
            case 1: // Movimentação
                $isCredit = (hexdec(substr($chunk, 0, 2)) % 2) === 0;
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'type' => $isCredit ? 'CREDIT' : 'DEBIT',
                    'description' => $isCredit ? 'Crédito em conta' : 'Débito em conta',
                ];
            case 2: // Recusas
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'reason' => 'INSUFFICIENT_FUNDS',
                    'reasonDescription' => 'Saldo insuficiente.',
                    'originalTransactionId' => substr($chunk, 0, 12),
                ];
            case 3: // Transferências
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'direction' => ($index % 2) === 0 ? 'IN' : 'OUT',
                    'counterParty' => [
                        'name' => 'CONTRAPARTE HOMOLOG', 'documentNumber' => '12345678901',
                        'bank' => '341', 'branch' => '0001', 'account' => '12345-6',
                    ],
                ];
            case 4: // Pix enviados
            case 5: // Pix recebidos
                $direction = $filetype === 4 ? 'OUT' : 'IN';
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount, 'direction' => $direction,
                    'endToEndId' => 'E13935893' . date('YmdHi', $ts) . substr($chunk, 0, 11),
                    'key' => 'teste' . $index . '@pix.com', 'keyType' => 'EMAIL',
                    'counterParty' => [
                        'name' => $direction === 'IN' ? 'PAGADOR HOMOLOG' : 'RECEBEDOR HOMOLOG',
                        'documentNumber' => '12345678901',
                    ],
                ];
            case 6: // Pix devoluções
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'originalPaymentId' => substr($chunk, 0, 12),
                    'reason' => 'MD06', 'reasonDescription' => 'Cliente final solicitou.',
                ];
            case 7: // Boletos pagos
            case 8: // Boletos emitidos
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'barcode' => '34191' . substr($chunk, 0, 39),
                    'dueDate' => gmdate('Y-m-d', $ts + (5 * 86400)),
                    'beneficiary' => 'BENEFICIARIO HOMOLOG',
                    'status' => $filetype === 7 ? 'PAID' : 'REGISTERED',
                ];
            case 9: // Débito veicular
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'plate' => 'ABC' . str_pad((string) (hexdec(substr($chunk, 0, 4)) % 9999), 4, '0', STR_PAD_LEFT),
                    'renavam' => str_pad((string) (hexdec(substr($chunk, 0, 8)) % 99999999999), 11, '0', STR_PAD_LEFT),
                    'category' => 'IPVA',
                ];
            case 10: // Recargas
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'operator' => ($index % 2) === 0 ? 'TIM' : 'VIVO',
                    'phoneNumber' => '+551199999' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                ];
            case 11: // TED enviadas
            case 12: // TED recebidas
                $direction = $filetype === 11 ? 'OUT' : 'IN';
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount, 'direction' => $direction,
                    'numCtrlStr' => 'STR' . date('Ymd', $ts) . substr($chunk, 0, 9),
                    'counterParty' => [
                        'name' => 'CONTRAPARTE HOMOLOG', 'documentNumber' => '12345678901',
                        'bank' => '237', 'branch' => '0001', 'account' => '67890-1',
                    ],
                ];
            case 13: // Tarifas
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'tariffType' => 'TED', 'description' => 'Tarifa TED enviada',
                ];
            case 14: // IOF
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'baseAmount' => round($amount / 0.0038, 2), 'rate' => 0.0038,
                ];
            case 15: // Bloqueios judiciais
                return [
                    'id' => $id, 'date' => $date, 'amount' => $amount,
                    'orderNumber' => 'BC-' . substr($chunk, 0, 10), 'court' => 'TJSP', 'status' => 'BLOCKED',
                ];
            default:
                return ['id' => $id, 'date' => $date, 'amount' => $amount];
        }
    }
}
