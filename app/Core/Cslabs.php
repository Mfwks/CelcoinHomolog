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
        $directory = self::clientRoot($clientId) . '/entities/' . $safeType;
        self::ensureDir($directory);
        $path = $directory . '/' . $safeId . '.json';
        Json::write($path, $data);
        return $path;
    }

    public static function readEntity(string $type, string $entityId, ?string $clientId = null): array|false
    {
        $clientId ??= self::context()['client_id'];
        $path = self::clientRoot($clientId) . '/entities/' . self::safeName($type) . '/' . self::safeName($entityId) . '.json';
        return Json::read($path);
    }

    public static function listEntities(string $type, ?string $clientId = null): array
    {
        $clientId ??= self::context()['client_id'];
        $directory = self::clientRoot($clientId) . '/entities/' . self::safeName($type);

        if (!is_dir($directory)) {
            return [];
        }

        $items = [];
        $iterator = new \DirectoryIterator($directory);

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') {
                continue;
            }

            $data = Json::read($file->getPathname());

            if (is_array($data)) {
                $items[] = $data;
            }
        }

        usort($items, function (array $a, array $b): int {
            return strcmp((string) ($a['entity'] ?? ''), (string) ($b['entity'] ?? ''));
        });

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
        $directory = self::storageRoot() . '/tokens';
        self::ensureDir($directory);

        Json::write($directory . '/' . $tokenHash . '.json', [
            'token_hash' => $tokenHash,
            'client_id' => $context['client_id'],
            'issued_at' => date(DATE_ATOM),
            'auth_hint' => substr($token, -8),
            'source' => 'v5_token',
            'meta' => $meta,
        ]);
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
        $dueDate = self::billPaymentDueDate($digits, $seed);
        $settleDate = date('m/d/Y', strtotime('+1 weekday'));
        $assignor = self::pickBySeed($seed, [
            'CLARO SP DDD 11',
            'ENEL DISTRIBUICAO SAO PAULO',
            'SABESP',
            'VIVO FIXO BRASIL',
            'BANCO DO BRASIL S/A',
            'ITAU UNIBANCO S.A.',
            'CAIXA ECONOMICA FEDERAL',
        ]);
        $transactionId = 1000000000 + (hexdec(substr($seed, 0, 8)) % 8999999999);

        return [
            'assignor' => $assignor,
            'registerData' => $isUtilityBill ? null : self::billPaymentRegisterData($assignor, $value, $dueDate, $seed),
            'settleDate' => $settleDate,
            'dueDate' => $isUtilityBill ? null : $dueDate,
            'endHour' => '21:00',
            'initeHour' => '07:00',
            'nextSettle' => 'N',
            'digitable' => $digitable,
            'transactionId' => $transactionId,
            'type' => $isUtilityBill ? 1 : ($type ?: 2),
            'value' => $value,
            'maxValue' => null,
            'minValue' => null,
            'errorCode' => '000',
            'message' => null,
            'status' => 0,
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

    public static function saveWebhookSubscription(string $entity, string $url, ?array $auth = null, array $raw = []): array
    {
        $existing = self::webhookSubscription($entity);
        $now = date(DATE_ATOM);
        $knownEntities = self::knownWebhookEntities();
        $record = [
            'entity' => $entity,
            'webhookUrl' => $url,
            'auth' => $auth,
            'known_entity' => in_array($entity, $knownEntities, true),
            'updated_at' => $now,
            'created_at' => is_array($existing) ? ($existing['created_at'] ?? $now) : $now,
            'raw_request' => $raw,
        ];

        self::writeEntity('webhook_subscriptions', $entity, $record);

        return $record;
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

        register_shutdown_function(function () use ($url, $payload, $event, $requestId, $clientId, $webhookId, $delaySeconds): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            sleep(max(0, $delaySeconds));
            $sentAt = date(DATE_ATOM);
            $result = self::sendJsonRequest($url, $payload);

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

    private static function billPaymentDueDate(string $digits, string $seed): string
    {
        $days = 3 + (hexdec(substr($seed, 14, 4)) % 40);

        if (strlen($digits) >= 33 && preg_match('/^[0-9]{47,48}$/', $digits)) {
            $factor = (int) substr($digits, 33, 4);

            if ($factor > 0) {
                $base = strtotime('1997-10-07');
                $resolved = strtotime('+' . $factor . ' days', $base);

                if ($resolved !== false && $resolved >= strtotime('-30 days') && $resolved <= strtotime('+5 years')) {
                    return date('m/d/Y', $resolved);
                }
            }
        }

        return date('m/d/Y', strtotime('+' . $days . ' days'));
    }

    private static function billPaymentRegisterData(string $assignor, float $value, string $dueDate, string $seed): array
    {
        $discount = (hexdec(substr($seed, 18, 2)) % 3) === 0 ? round($value * 0.02, 2) : 0;
        $interest = (hexdec(substr($seed, 20, 2)) % 4) === 0 ? round($value * 0.01, 2) : 0;
        $fine = (hexdec(substr($seed, 22, 2)) % 5) === 0 ? round($value * 0.02, 2) : 0;

        return [
            'recipient' => $assignor,
            'documentRecipient' => self::pickBySeed($seed . 'recipient', ['60746948000112', '17189525000168', '00000000000191']),
            'payer' => self::pickBySeed($seed . 'payer', ['CLIENTE HOMOLOGACAO', 'MARIA SILVA', 'JOAO SOUZA']),
            'documentPayer' => self::pickBySeed($seed . 'document', ['06170097914', '11144477735', '12345678000195']),
            'originalValue' => $value,
            'discountValue' => $discount,
            'interestValueCalculated' => $interest,
            'fineValueCalculated' => $fine,
            'dueDate' => $dueDate,
        ];
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
        $path = self::storageRoot() . '/tokens/' . hash('sha256', $token) . '.json';
        $tokenData = Json::read($path);

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

    private static function sendJsonRequest(string $url, array $payload): array
    {
        $curl = curl_init();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Content-Length: ' . strlen((string) $body),
            ],
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
}
