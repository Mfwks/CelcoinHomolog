<?php

namespace App\Core;

class Cslabs
{
    private static ?array $context = null;

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
            'received_at' => date(DATE_ATOM),
            'raw_body' => $rawBody,
            'body' => $body,
            'storage_root' => self::storageRoot(),
            'meta' => $meta,
        ];

        self::ensureDir(self::clientRoot($clientId));

        return self::$context;
    }

    public static function context(): array
    {
        return self::$context ?? self::boot();
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
        $payload = [
            'request_id' => $context['request_id'],
            'client_id' => $context['client_id'],
            'worker_id' => $context['worker_id'],
            'auth_hint' => $context['auth_hint'],
            'identity_source' => $context['identity_source'],
            'received_at' => $context['received_at'],
            'method' => $context['method'],
            'path' => $context['path'],
            'ip' => $context['ip'],
            'query' => $context['query'],
            'headers' => $context['headers'],
            'request' => $context['body'],
            'response' => [
                'status_code' => http_response_code() ?: 200,
                'headers' => headers_list(),
                'body' => self::decodePayload($responseBody),
            ],
            'meta' => [
                'info_url' => self::infoUrl($context['client_id']),
                'stream' => $context['meta']['stream'] ?? null,
            ],
        ];

        $day = date('Ymd');
        $directory = self::clientRoot($context['client_id']) . '/interactions/' . $day;
        self::ensureDir($directory);
        Json::write($directory . '/' . $context['request_id'] . '.json', $payload);

        $indexDir = self::clientRoot($context['client_id']) . '/indexes/workers';
        self::ensureDir($indexDir);
        Json::write($indexDir . '/' . $context['worker_id'] . '.json', [
            'worker_id' => $context['worker_id'],
            'client_id' => $context['client_id'],
            'ip' => $context['ip'],
            'last_seen_at' => $context['received_at'],
            'auth_hint' => $context['auth_hint'],
        ]);

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
        $base = self::clientRoot($clientId) . '/interactions';
        if (!is_dir($base)) {
            return [];
        }

        $items = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }

            $data = Json::read($file->getPathname());
            if (!$data) {
                continue;
            }

            $items[] = $data;
        }

        usort($items, function (array $a, array $b): int {
            return strcmp($b['received_at'] ?? '', $a['received_at'] ?? '');
        });

        return $items;
    }

    public static function findInteraction(string $clientId, string $requestId): array|false
    {
        foreach (self::listInteractions($clientId) as $item) {
            if (($item['request_id'] ?? null) === $requestId) {
                return $item;
            }
        }

        return false;
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
            $scenario = self::scenarioFromValue($value, '');

            if ($scenario !== '') {
                return $scenario;
            }
        }

        return $default;
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
            'endtoEndId' => 'E' . date('Ymd') . substr(hash('sha256', $key), 0, 24),
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

        $amount = (float) ($payload['amount'] ?? $payload['value'] ?? 1);
        $clientRequestId = trim((string) ($payload['clientRequestId'] ?? gerarHashMock()));
        $transactionId = 'pix_' . substr(hash('sha256', $clientRequestId), 0, 24);

        return [
            'status' => 'SUCCESS',
            'transactionId' => $transactionId,
            'clientRequestId' => $clientRequestId,
            'amount' => round($amount, 2),
            'endToEndId' => 'E' . date('Ymd') . substr(hash('sha256', $transactionId), 0, 24),
            'message' => 'Pix recebido com sucesso.',
            'version' => '1.0.0',
        ];
    }

    public static function billPaymentAuthorizeResponse(array $payload): array
    {
        $scenario = self::scenarioFromPayload($payload, [
            'scenario',
            'mockScenario',
            'mock_scenario',
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
        $scenario = self::scenarioFromPayload($payload, ['scenario', 'mockScenario', 'mock_scenario', 'reason']);
        if ($scenario !== 'success') {
            return self::paymentError($scenario);
        }

        $amount = (float) ($payload['amount'] ?? 0);
        $clientCode = trim((string) ($payload['clientCode'] ?? ''));
        $id = trim((string) ($payload['id'] ?? $transactionId ?? gerarHashMock()));

        if ($amount <= 0 || $id === '') {
            return self::paymentError('failed');
        }

        $endToEndId = trim((string) ($payload['endToEndId'] ?? '')) ?: ('E13935893' . date('YmdHi') . substr(hash('sha256', $id), 0, 11));
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
                    'createDate' => $entry['account']['createDate'] ?? ($entry['created_at'] ?? null),
                ];
            }
        }

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => [
                'pixKeys' => $entries,
                'totalElements' => count($entries),
            ],
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

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => $body,
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
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => self::buildPixDictClaimBody($id, '', 'OPEN', []),
        ];
    }

    private static function buildPixDictClaimBody(string $claimId, string $key, string $status, array $payload): array
    {
        // keyType: request usa UPPER (CPF/CNPJ/EMAIL/PHONE); response usa Pascal
        // (CPF/CNPJ/Email/Phone). Inconsistência preservada conforme doc oficial.
        $keyTypeRequest = strtoupper(trim((string) ($payload['keyType'] ?? '')));
        $pascalMap = ['CPF' => 'CPF', 'CNPJ' => 'CNPJ', 'EMAIL' => 'Email', 'PHONE' => 'Phone'];
        $detected = strtoupper(self::pixKeyType($key !== '' ? $key : 'fallback@pix.com'));
        $keyTypePascal = $pascalMap[$keyTypeRequest] ?? $pascalMap[$detected] ?? 'CPF';

        $accountInput = (string) ($payload['account'] ?? '');
        $accountDigits = preg_replace('/\D+/', '', $accountInput) ?: '';
        $claimerTaxId = $key !== '' ? self::pixKeyOwnerDocument($key) : '06170097914';

        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $periodEnd = gmdate('Y-m-d\TH:i:s.000\Z', time() + (7 * 86400));

        return [
            'id' => $claimId,
            'claimType' => strtoupper((string) ($payload['claimType'] ?? 'PORTABILITY')),
            'key' => $key,
            'keyType' => $keyTypePascal,
            'claimerAccount' => [
                'participant' => '13935893',
                'branch' => '0001',
                'account' => $accountDigits !== '' ? $accountDigits : self::accountNumberFromSeed(hash('sha256', $key . $claimId)),
                'accountType' => 'TRAN',
            ],
            'claimer' => [
                'personType' => strlen($claimerTaxId) === 14 ? 'LEGAL_PERSON' : 'NATURAL_PERSON',
                'taxId' => $claimerTaxId,
                'name' => (string) ($payload['claimerName'] ?? 'HOMOLOGACAO'),
            ],
            'donorParticipant' => (string) ($payload['donorParticipant'] ?? '60746948'),
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

        $transactionId = (string) random_int(1000000000, 9999999999);
        $amount = number_format((float) ($payload['amount'] ?? 0), 2, '.', '');

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => [
                'transactionId' => $transactionId,
                'emvqrcps' => self::buildEmv($key, $amount, (string) ($payload['transactionIdentification'] ?? 'PIX' . $transactionId)),
                'transactionIdentification' => (string) ($payload['transactionIdentification'] ?? 'PIX' . $transactionId),
                'key' => $key,
                'amount' => (float) $amount,
            ],
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

        $transactionId = gerarHashMock();
        $pactualId = gerarHashMock();
        $locationId = substr(hash('sha256', $clientRequestId), 0, 24);
        // Doc oficial: amount no QR dinâmico é string (default "5000.00"), diferente do estático (double).
        $amountInput = $payload['amount'] ?? null;
        $amountStr = ($amountInput === null || $amountInput === '')
            ? '5000.00'
            : number_format((float) $amountInput, 2, '.', '');
        $expiration = (int) ($payload['expiration'] ?? 86400);

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => [
                'clientRequestId' => $clientRequestId,
                'transactionId' => $transactionId,
                'pactualId' => $pactualId,
                'body' => [
                    'location' => 'qrcode.pix.celcoin.com.br/pixqrcode/v2/cobv/' . $locationId,
                    'calendar' => ['expiration' => $expiration],
                    'amount' => ['original' => $amountStr],
                    'dynamicBRCodeData' => [
                        'emvqrcps' => self::buildEmv($key, $amountStr, $clientRequestId),
                        'merchantAccountInformation' => [
                            'url' => 'qrcode.pix.celcoin.com.br/pixqrcode/v2/cobv/' . $locationId,
                        ],
                    ],
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

        return [
            'status' => 'SUCCESS',
            'version' => '1.0.0',
            'body' => [
                'transactionId' => $txid,
                'status' => 'CANCELLED',
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

    private static function buildEmv(string $key, string $amount, string $txid): string
    {
        $key = substr($key, 0, 77);
        $merchantInfo = sprintf('0014br.gov.bcb.pix01%02d%s', strlen($key), $key);
        $additional = sprintf('05%02d%s', strlen($txid), substr($txid, 0, 25));

        return sprintf(
            '000201%s5204000053039865%s5802BR5910CSLABS-MOCK6009SAO PAULO62%02d%s6304ABCD',
            sprintf('26%02d%s', strlen($merchantInfo), $merchantInfo),
            $amount !== '' && $amount !== '0.00' ? sprintf('54%02d%s', strlen($amount), $amount) : '',
            strlen($additional),
            $additional
        );
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

    public static function brcodeStaticBase64Response(string $transactionId, string $imageType = 'png'): array
    {
        return [
            'status' => 0,
            'base64image' => self::onePixelImageBase64($imageType),
        ];
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
        $isStatic = !str_contains($emv, 'gov.bcb.pix/url') && !preg_match('/[0-9a-f]{8}-/', $emv);
        $key = self::extractFromEmv($emv) ?? (gerarHashMock());
        $url = 'pix.celcoin.com.br/pix/v2/' . substr($seed, 0, 24);
        $amount = round((hexdec(substr($seed, 0, 6)) % 10000) / 100, 2);
        $txid = 'TXID' . strtoupper(substr($seed, 0, 28));

        return [
            'type' => $isStatic ? '1' : '2',
            'collection' => $isStatic ? '0' : '1',
            'payloadFormatIndicator' => '02',
            'merchantAccountInformation' => [
                'url' => $url,
                'gui' => 'br.gov.bcb.pix',
                'key' => $key,
                'additionalInformation' => '',
                'withdrawalServiceProvider' => null,
            ],
            'merchantCategoryCode' => 0,
            'transactionCurrency' => 0,
            'transactionAmount' => $amount,
            'countryCode' => null,
            'merchantName' => null,
            'merchantCity' => 'SAO PAULO',
            'postalCode' => null,
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

        $seed = hash('sha256', $payloadUrl);
        $amount = number_format(round((hexdec(substr($seed, 0, 6)) % 10000) / 100, 2), 2, '.', '');
        $txid = 'TXID' . strtoupper(substr($seed, 0, 28));
        $key = substr($seed, 0, 8) . '-' . substr($seed, 8, 4) . '-' . substr($seed, 12, 4) . '-' . substr($seed, 16, 4) . '-' . substr($seed, 20, 12);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return [
            'status' => 'ACTIVE',
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
                'criacao' => $now,
                'expiracao' => 300,
                'apresentacao' => gmdate('Y-m-d\TH:i:s.v\Z'),
                'validadeAposVencimento' => 0,
            ],
            'revisao' => 0,
        ];
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
        $scenario = self::scenarioFromPayload($payload, ['scenario', 'mockScenario', 'mock_scenario', 'description', 'clientCode']);
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
        if (is_array($duplicate) && !empty($duplicate['id']) && ($scenario === 'success' || $scenario === '')) {
            return [
                'version' => '1.0.0',
                'status' => 'ERROR',
                'error' => ['errorCode' => 'CBE100', 'message' => 'Existe um lançamento idêntico pendente. Favor aguarde para realizar esta operação para evitar duplicidade.'],
            ];
        }

        if ($scenario === 'fraud') {
            return ['version' => '1.0.0', 'status' => 'ERROR', 'error' => ['errorCode' => 'CBE171', 'message' => 'Transação bloqueada por suspeita de fraude.']];
        }
        if ($scenario === 'blocked') {
            return ['version' => '1.0.0', 'status' => 'ERROR', 'error' => ['errorCode' => 'CBE172', 'message' => 'Transação bloqueada para a conta informada.']];
        }
        if ($scenario === 'failed' || $scenario === 'error') {
            return ['version' => '1.0.0', 'status' => 'ERROR', 'error' => ['errorCode' => 'CBE100', 'message' => 'Existe um lançamento idêntico pendente. Favor aguarde para realizar esta operação para evitar duplicidade.']];
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
        $reason = trim((string) ($payload['reason'] ?? ''));

        if (!in_array($status, ['ATIVO', 'BLOQUEADO'], true)) {
            return self::accountManagerError('CBE014', 'status é obrigatório (ATIVO|BLOQUEADO).');
        }
        if ($reason === '') {
            return self::accountManagerError('CBE014', 'reason é obrigatório e deve conter um formato de texto válido.');
        }

        $scenario = self::scenarioFromPayload($payload, [
            'scenario',
            'mockScenario',
            'mock_scenario',
            'reason',
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
            'version' => '1.1.0',
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

        $digits = str_pad($digits, 47, '0');

        return sprintf(
            '%s.%s %s.%s %s.%s %s %s',
            substr($digits, 0, 5),
            substr($digits, 5, 5),
            substr($digits, 10, 5),
            substr($digits, 15, 6),
            substr($digits, 21, 5),
            substr($digits, 26, 6),
            substr($digits, 32, 1),
            substr($digits, 33, 14)
        );
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
            'version' => '1.1.0',
        ];
    }

    public static function webhookSubscription(string $entity): array|false
    {
        return self::readEntity('webhook_subscriptions', $entity);
    }

    public static function listWebhookSubscriptions(): array
    {
        return self::listEntities('webhook_subscriptions');
    }

    public static function webhookSubscriptionUrl(string $entity): ?string
    {
        $subscription = self::webhookSubscription($entity);
        $url = trim((string) ($subscription['webhookUrl'] ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    public static function saveWebhookSubscription(string $entity, string $url, ?array $auth = null, array $raw = [], bool $active = true): array
    {
        $existing = self::webhookSubscription($entity);
        $now = date(DATE_ATOM);
        $knownEntities = self::knownWebhookEntities();
        $record = [
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
                    'transactionId' => (string) random_int(100000, 999999),
                    'status' => $status === 'CONFIRMED' ? 'PAID' : 'PENDING',
                    'bankLine' => self::boletoBankLine($id, 2256.27, date('Y-m-d')),
                    'bankNumber' => substr($id, 0, 9),
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
    public static function scheduleWebhook(string $event, array $payload, int $delaySeconds = 2, ?string $url = null): bool
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
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

        register_shutdown_function(function () use ($url, $payload, $event, $requestId, $clientId, $webhookId, $delaySeconds, $auth): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            sleep(max(0, $delaySeconds));
            $sentAt = date(DATE_ATOM);
            $result = self::sendJsonRequest($url, $payload, $auth);

            $entry = [
                'webhook_id' => $webhookId,
                'request_id' => $requestId,
                'client_id' => $clientId,
                'event' => $event,
                'status' => $result['ok'] ? 'delivered' : 'failed',
                'target_url' => $url,
                'payload' => $payload,
                'sent_at' => $sentAt,
                'response_code' => $result['status_code'],
                'response_body' => $result['body'],
                'error' => $result['error'],
            ];

            self::registerWebhook($entry);
            self::appendWebhookToInteraction($requestId, $entry);
        });

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
        $errors = [
            'fraud' => ['CBE171', 'Transação bloqueada por suspeita de fraude. Contate o suporte para mais informações.'],
            'not_found' => ['CBE404', 'Transação ou recurso não encontrado.'],
            'blocked' => ['CBE172', 'Transação bloqueada para a conta informada.'],
            'failed' => ['CBE400', 'Transação rejeitada pela instituição recebedora.'],
            'error' => ['CBE500', 'Erro interno ao processar a transação.'],
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
        $errors = [
            'fraud' => ['CSLAB403', 'Boleto bloqueado por suspeita de fraude.'],
            'not_found' => ['CSLAB404', 'Registro ou funcionalidade inexistente.'],
            'blocked' => ['CSLAB423', 'Boleto bloqueado para pagamento.'],
            'failed' => ['CSLAB400', 'Boleto rejeitado pela instituição recebedora.'],
            'error' => ['CSLAB500', 'Erro interno ao consultar boleto.'],
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

        return [
            'status' => 'ERROR',
            'version' => '1.0.0',
            'error' => [
                'errorCode' => $code,
                'message' => $message,
            ],
        ];
    }

    private static function chargeError(string $scenario): array
    {
        $errors = [
            'fraud' => ['CSLAB403', 'Emissão bloqueada por suspeita de fraude.'],
            'not_found' => ['CSLAB404', 'Conta ou chave Pix não encontrada.'],
            'blocked' => ['CSLAB423', 'Conta bloqueada para emissão.'],
            'failed' => ['CSLAB400', 'Dados obrigatórios inválidos para emissão de boleto.'],
            'error' => ['CSLAB500', 'Erro interno ao emitir boleto.'],
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

    private static function storageRoot(): string
    {
        $root = TMP . 'cslabs';
        self::ensureDir($root);
        self::ensureDir($root . '/clients');
        return $root;
    }

    private static function clientRoot(string $clientId): string
    {
        return self::storageRoot() . '/clients/' . self::safeName($clientId);
    }

    private static function ensureDir(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
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
        $path = self::workerOriginPath($clientId, $ip);
        $origin = Json::read($path);

        if (is_array($origin) && !empty($origin['worker_id'])) {
            return (string) $origin['worker_id'];
        }

        $workerId = 'wrk_' . bin2hex(random_bytes(8));
        $payload = [
            'worker_id' => $workerId,
            'client_id' => $clientId,
            'ip' => $ip,
            'user_agent' => $headers['User-Agent'] ?? null,
            'first_seen_at' => date(DATE_ATOM),
            'last_seen_at' => date(DATE_ATOM),
        ];

        self::ensureDir(dirname($path));
        Json::write($path, $payload);

        return $workerId;
    }

    private static function touchWorkerOrigin(string $clientId, string $ip, string $workerId, array $headers): void
    {
        $path = self::workerOriginPath($clientId, $ip);
        $origin = Json::read($path);

        $payload = is_array($origin) ? $origin : [];
        $payload['worker_id'] = $workerId;
        $payload['client_id'] = $clientId;
        $payload['ip'] = $ip;
        $payload['user_agent'] = $payload['user_agent'] ?? ($headers['User-Agent'] ?? null);
        $payload['first_seen_at'] = $payload['first_seen_at'] ?? date(DATE_ATOM);
        $payload['last_seen_at'] = date(DATE_ATOM);

        self::ensureDir(dirname($path));
        Json::write($path, $payload);
    }

    private static function workerOriginPath(string $clientId, string $ip): string
    {
        return self::clientRoot($clientId) . '/indexes/origins/' . hash('sha256', $ip) . '.json';
    }

    private static function registerWebhook(array $entry): void
    {
        $clientId = (string) ($entry['client_id'] ?? self::context()['client_id']);
        $webhookId = self::safeName((string) ($entry['webhook_id'] ?? ('wh_' . bin2hex(random_bytes(8)))));
        $directory = self::clientRoot($clientId) . '/webhooks/' . date('Ymd');
        self::ensureDir($directory);
        Json::write($directory . '/' . $webhookId . '.json', $entry);
    }

    private static function appendWebhookToInteraction(string $requestId, array $entry): void
    {
        $interaction = self::findInteraction(self::context()['client_id'], $requestId);

        if (!$interaction) {
            return;
        }

        $interaction['webhooks'] ??= [];
        $interaction['webhooks'][] = $entry;

        $day = date('Ymd', strtotime((string) ($interaction['received_at'] ?? 'now')));
        $directory = self::clientRoot(self::context()['client_id']) . '/interactions/' . $day;
        self::ensureDir($directory);
        Json::write($directory . '/' . self::safeName($requestId) . '.json', $interaction);
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

        return [
            'version' => '1.0.0',
            'status' => 'SUCCESS',
            'body' => [
                'proposals' => $slice,
                'totalItems' => $total,
                'currentPage' => $page,
                'totalPages' => $total === 0 ? 0 : (int) ceil($total / $limit),
                'limitPerPage' => $limit,
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

        for ($i = 0; $i < $count; $i++) {
            $chunk = substr($seed, $i * 4, 8);
            $isCredit = (hexdec(substr($chunk, 0, 2)) % 2) === 0;
            $amount = round((hexdec(substr($chunk, 2, 4)) % 50000) / 100 + 10, 2);
            $movements[] = [
                'id' => gerarHashMock(),
                'amount' => $amount,
                'movementType' => $isCredit ? 'CREDIT' : 'DEBIT',
                'movementCode' => $isCredit ? 'PIX_IN' : 'PIX_OUT',
                'description' => $isCredit ? 'Pix recebido' : 'Pix enviado',
                'transactionIdentification' => 'E13935893' . date('YmdHi', $baseTs + ($i * 600)) . substr($chunk, 0, 11),
                'clientCode' => '',
                'createDate' => gmdate('Y-m-d\TH:i:s\Z', $baseTs + ($i * 600)),
                'balanceAfter' => round(3000000.00 + ($isCredit ? $amount : -$amount), 2),
                'counterParty' => [
                    'name' => $isCredit ? 'JOAO PAGADOR' : 'MARIA RECEBEDORA',
                    'documentNumber' => $isCredit ? '11122233344' : '55566677788',
                    'bank' => $isCredit ? '341' : '237',
                    'branch' => '0001',
                    'account' => '12345-6',
                ],
            ];
        }

        $movements = $order === 'desc' ? array_reverse($movements) : $movements;
        $total = count($movements);

        return [
            'version' => '1.0.0',
            'status' => 'SUCCESS',
            'body' => [
                'account' => $account !== '' ? $account : self::accountNumberFromSeed($seed),
                'documentNumber' => $document,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'movements' => array_slice($movements, ($page - 1) * $limit, $limit),
                'totalItems' => $total,
                'currentPage' => $page,
                'totalPages' => (int) ceil($total / $limit),
                'order' => $order,
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

    public static function kycFileUploadResponse(array $form, array $files): array
    {
        // KYC v1: campos em lowercase sem separador, conforme doc oficial.
        $cnpj = trim((string) ($form['cnpj'] ?? ''));
        $documentNumber = trim((string) ($form['documentnumber'] ?? ''));
        $filetype = strtoupper(trim((string) ($form['filetype'] ?? '')));
        $onboardingId = trim((string) ($form['onboardingId'] ?? ''));
        $front = $files['front'] ?? null;

        $allowedTypes = ['CNH', 'RG', 'PASSPORT', 'RNE'];

        if ($onboardingId === '') {
            return [
                'status' => 'ERROR',
                'version' => '1.0.0',
                'error' => ['errorCode' => 'CBE014', 'message' => 'onboardingId é obrigatório (multipart text).'],
            ];
        }
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

        $fileId = gerarHashMock();
        $record = [
            'fileId' => $fileId,
            'onboardingId' => $onboardingId,
            'documentNumber' => preg_replace('/\D+/', '', $documentNumber) ?: $documentNumber,
            'cnpj' => preg_replace('/\D+/', '', $cnpj) ?: $cnpj,
            'filetype' => $filetype,
            'originalFileName' => (string) ($front['name'] ?? 'upload.bin'),
            'fileSize' => (int) ($front['size'] ?? 0),
            'mimeType' => (string) ($front['type'] ?? 'application/octet-stream'),
            'status' => 'PROCESSING',
            'createDate' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        Db::transaction(function () use ($fileId, $onboardingId, $record) {
            self::writeEntity('kyc_uploads', $fileId, $record);
            self::writeEntity('kyc_uploads_by_onboarding', $onboardingId, ['fileId' => $fileId]);
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
