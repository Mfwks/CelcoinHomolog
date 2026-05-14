# Convenções

Apenas o que **diverge** do óbvio. Padrões PSR e PHP comum não são repetidos aqui.

## Estrutura de um stream de API

Sempre nesta ordem:

```php
<?php
include_once __DIR__ . '/api-stream.php';   // boot + ob_start decorator
use App\Core\Cslabs;

$body = Cslabs::requestBody();              // não usar file_get_contents direto
$body = is_array($body) ? $body : $_GET;    // fallback para query string

header('Content-Type: application/json');
echo json_encode(Cslabs::xxxResponse($body), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
```

Não emitir headers de status manualmente — quem decide o código é o método `*Response` (via metadados injetados no JSON) ou o handler de 404 em `start.php`.

## Formato de resposta de erro

Espelhado de `start.php`:

```json
{
    "status": "ERROR",
    "error": {
        "errorCode": "CSLAB404",
        "message": "Registro ou funcionalidade inexistente."
    },
    "version": "1.0.0"
}
```

Códigos de erro internos seguem o padrão `CSLAB<NNN>`.

## Roteamento

- `$web->add('/path', 'subdir/file')` — `subdir/file` é resolvido como `app/streams/subdir/file.php`.
- Parâmetros de rota usam chaves: `'/shots/{identifier}/'`. A captura é responsabilidade do `Web` router; o stream lê via contexto/`$_GET`.
- Métodos HTTP **não** são restritos pelo router. Se um endpoint precisa rejeitar método, faz isso no próprio stream.

## Prefixo `/celcoin/` (legado)

- `app/basics.php` ainda define `BASE = '/celcoin/'`.
- `Cslabs::boot()` faz `str_replace('/celcoin/','/',$path)` para neutralizar.
- Decisão histórica: o prefixo foi removido da URL pública em `cc6d0e0` (2026-04-18). Rotas em `web.php` são declaradas sem ele.
- Não reintroduzir.

## Identidade e contexto

- `Cslabs::boot()` deriva `client_id` por uma destas fontes, em ordem: token bearer resolvido, credenciais no body, raw bearer, fingerprint da requisição. O campo `identity_source` no contexto registra qual foi usada.
- `request_id` é gerado por chamada (`req_<16 hex>`).
- Por cliente, é criado um diretório sob `storage_root` (`Cslabs::clientRoot($clientId)`).

## Metadados de view e build

Espalhados em `app/basics.php`:
- `$c[...]` — meta do site (title, headline, og:image, theme, etc.). Usado pelas views.
- `$software[...]` — info do app (nome, versão, autor, contato, suporte).
- `$platform[...]` — info do microframework (nome, versão, codinome).

Editar nesses arrays em vez de hardcodar nas views.

## Configuração de ambiente

- `DEV = true` em `config.php` liga `display_errors` e `error_reporting(E_ALL)`. Para subir a produção, esse flag precisa virar `false` (não há toggle por env var hoje).
- Sessão: nome `mfwks`, lifetime 30 dias, `secure=true`, `httponly=true`, `samesite=lax`.
- `$database` em `config.php` tem placeholders (`xxx`). Banco não é usado no estado atual.

## Logs

- `app/tmp/errors.log` recebe `error_log()` (configurado em `config.php`).
- Logs de request/response por interação são gerados pelo `Cslabs::finalizeInteraction` no `ob_start` decorator (`25d2cf0`).
- Pasta `logs/` na raiz está reservada mas sem uso ativo.

## Estilo

- Comentários PHP usam `#` para títulos de seção (`# Web`, `# Basics`) — padrão do autor, manter.
- Indentação: 4 espaços (vide arquivos existentes).
- Sem framework de teste — não adicionar PHPUnit/Pest sem alinhamento.
