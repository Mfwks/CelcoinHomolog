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

        self::$context = [
            'request_id' => $requestId,
            'client_id' => $clientId,
            'worker_id' => $workerId,
            'auth_hint' => $bearer ? substr($bearer, -8) : null,
            'identity_source' => $resolvedClientId ? 'bearer_link' : ($credentialSeed ? 'client_credentials' : ($bearer ? 'bearer_raw' : 'request_fingerprint')),
            'authorization' => $authorization ? '[redacted]' : null,
            'ip' => $ip,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
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

    public static function clientSettings(): array
    {
        $settings = self::readEntity('settings', 'client');
        return is_array($settings) ? $settings : [];
    }

    public static function updateClientSettings(array $data): array
    {
        $settings = array_merge(self::clientSettings(), $data);
        self::writeEntity('settings', 'client', $settings);
        return $settings;
    }

    public static function webhookUrl(): ?string
    {
        $bodyUrl = self::extractWebhookUrlFromBody(self::requestBody());

        if ($bodyUrl !== null) {
            self::updateClientSettings([
                'webhook_url' => $bodyUrl,
                'webhook_updated_at' => date(DATE_ATOM),
                'webhook_source' => 'request_body',
            ]);

            return $bodyUrl;
        }

        $settings = self::clientSettings();
        $stored = trim((string) ($settings['webhook_url'] ?? ''));

        return filter_var($stored, FILTER_VALIDATE_URL) ? $stored : null;
    }

    public static function scheduleWebhook(string $event, array $payload, int $delaySeconds = 2, ?string $url = null): bool
    {
        $url ??= self::webhookUrl();

        if (!$url) {
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

    private static function extractWebhookUrlFromBody(array|string|null $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $candidates = [
            $body['webhookUrl'] ?? null,
            $body['webhookURL'] ?? null,
            $body['callbackUrl'] ?? null,
            $body['callbackURL'] ?? null,
            $body['notificationUrl'] ?? null,
            $body['notificationURL'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return null;
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
