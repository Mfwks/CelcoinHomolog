# Arquitetura

## Fluxo de inicialização

```
index.php
└─ axis.php                # constantes: WEB, APP, STREAMS, VIEWS, TMP, ...
└─ app/start.php
   ├─ vendor/autoload.php
   ├─ app/functions.php    # helpers globais (url(), etc.)
   ├─ app/basics.php       # metadados do site/PWA, $c, $software, $platform
   ├─ app/config.php       # DEV flag, errors, sessão, timezone, $database
   ├─ app/web.php          # registra rotas via $web->add()
   ├─ $web->init()         # casa URI → $web->stream
   └─ include app/streams/<stream>.php
```

Se nenhuma rota casa, `start.php` devolve `HTTP 404` com JSON `{ status: "ERROR", error: { errorCode: "CSLAB404", message }, version }`. Se a rota casa mas o arquivo de stream não existe, mata com `exit('There is not such stream')`.

## Módulos (`app/Core/`, namespace `App\Core\*`)

- **`Web`** — router. `add($path, $stream)` registra rota; `init()` resolve a URI atual e popula `$web->stream` e `$web->routes`.
- **`Cslabs`** — núcleo de simulação Celcoin:
  - `boot()` constrói o contexto da requisição (request_id, client_id derivado de token/credenciais/fingerprint, IP, headers, body, storage_root).
  - `requestBody()` lê `php://input` e parseia conforme o `Content-Type`.
  - `*Response(...)` — geradores de payload por endpoint (`pixPaymentResponse`, `billPaymentAuthorizeResponse`, etc.).
  - `injectInfoIntoJson()` + `finalizeInteraction()` — instalados via `ob_start` em `api-stream.php`; injetam metadados no JSON de saída e persistem a interação por cliente.
- **`Http`, `Json`, `Hash`, `Strings`, `Date`, `System`** — utilitários.

## Pastas

- `app/streams/api/` — um arquivo por endpoint Celcoin (ver `docs/endpoints.md`).
- `app/streams/pages/` — páginas HTML para códigos de erro (400, 401, 403, 404, 429, 500, 501, 502, 503, 504), `maintenance`, `status`, `status-fake`, `redirect`.
- `app/streams/home/` — `home` (landing) e `load-test`.
- `app/views/` — `page.php` e `special.php`, templates simples.
- `app/tmp/` — `errors.log` gerado em runtime.
- `assets/` — estáticos.
- `logs/` — pasta reservada para logs externos (sem uso ativo no momento).
- `tests/` — vazio.

## Ciclo de uma requisição de API

1. Apache reescreve a URI para `index.php` via `.htaccess` (qualquer caminho que não seja arquivo/diretório real).
2. Bootstrap: `start.php` carrega autoload, helpers, `basics`, `config`, `web` e chama `$web->init()`.
3. `web.php` mapeou a URI para um stream em `app/streams/api/<nome>`. `start.php` faz o `include`.
4. O stream inclui `api-stream.php` (boot + ob_handler), lê `Cslabs::requestBody()`, monta a resposta com `Cslabs::*Response(...)` e dá `echo json_encode(...)`.
5. O `ob_start` decorador adiciona metadados no JSON e dispara persistência da interação.

## Onde mockamos o quê

- **Respostas Celcoin** → métodos em `App\Core\Cslabs` (um por endpoint).
- **Identidade do chamador** → `Cslabs::boot()` deriva `client_id` por (a) token bearer, (b) credenciais no corpo, (c) fingerprint da requisição.
- **Persistência por cliente** → `Cslabs::ensureDir(self::clientRoot($clientId))` sob `storage_root`.
- **Logs de request/response** → introduzidos em `25d2cf0` ("Implements logs for requests and responses").
