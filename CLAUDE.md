# CelcoinHomolog — guia para sessões com Claude

Ambiente PHP que **simula** o ambiente de homologação da Celcoin. Não chama a API real da Celcoin daqui — esse é justamente o propósito do projeto.

## Stack
- PHP puro sobre um microframework próprio (`App\Core\*`).
- `composer.json` declara apenas o autoload PSR-4 `App\` → `app/`. Sem dependências de runtime.
- **Persistência:** SQLite via `pdo_sqlite` em `app/tmp/cslabs/cslabs.sqlite`. Tabelas:
  - `entities(client_id, type, id, entity_key, data, created_at, updated_at)` — entidades de negócio (onboardings, charges, billpayments, DICT, webhook_subscriptions, issued_tokens em `__global__`, etc.).
  - `interactions(client_id, request_id, received_at, received_at_us, worker_id, method, path, status_code, data)` — shots (request/response) exibidos no painel `/shots/<id>/`. Ordem por `received_at_us` (microssegundos) com fallback no `request_id`.
  - `client_workers(client_id, worker_id, ip, auth_hint, last_seen_at, data)` — rastreio de workers por cliente.
  - `client_origins(client_id, ip_hash, ip, worker_id, user_agent, first_seen_at, last_seen_at, data)` — origens IP por cliente (worker é resolvido a partir daqui).
  - `webhook_dispatches(client_id, webhook_id, request_id, event, status, target_url, created_at, updated_at, data)` — registro de webhooks despachados.
  Não existe mais nada em filesystem por cliente — `app/tmp/cslabs/clients/` foi removido. O `$database` em `config.php` ainda tem placeholders (`xxx`) — é legado, não está em uso.
- Logs: `app/tmp/errors.log` (configurado em `config.php`).
- Timezone fixado em `America/Sao_Paulo` (`.htaccess` e `config.php`).

## Como rodar
- Apache + `.htaccess` reescreve tudo para `index.php`. Servir o diretório raiz como DocumentRoot.
- Servidor embutido: `php -S 127.0.0.1:8000 index.php` — passar `index.php` como **arquivo de router** reproduz o rewrite do `.htaccess` e roteia tudo. (Sem o router, aí sim `php -S` não roteia.)

## Testes
- `tests/*_smoke.php`, sem framework: cada um é um script PHP que se auto-verifica e sai com código 1 em falha. Rodar todos: `for t in tests/*_smoke.php; do php "$t"; done`.
- A maioria chama os builders `Cslabs::*` direto e usa um `TMP` isolado (`tests/tmp_smoke/`). O warning `Constant TMP already defined` é esperado e vai pro **stderr** — não polua o stdout misturando `2>&1`, senão o JSON dos testes funcionais parece inválido.
- `celcoinv2_paths_smoke.php` é o único funcional: sobe o `php -S` e faz HTTP de verdade, porque testa branching que depende do path do request (`Cslabs::isV2()`) e do corpo lido de `php://input` — nada disso dá pra exercitar chamando builder. Isola o banco via `-d auto_prepend_file=tests/tmp_isolate.php`.

## Roteamento (resumo)
`index.php` → `axis.php` (constantes de diretórios) → `app/start.php` (bootstrap) → `app/web.php` (mapa de rotas) → `include app/streams/<stream>.php`.
Endpoints da API Celcoin ficam em `app/streams/api/`, um arquivo por rota.

## Convenções não-óbvias
- Todo stream de API começa com `include_once __DIR__ . '/api-stream.php';` — ele dispara `Cslabs::boot()` e instala um `ob_start` que injeta metadados no JSON de saída e persiste a interação.
- Corpo de requisição: usar `Cslabs::requestBody()` (resolve JSON e form).
- Respostas mockadas: métodos `Cslabs::*Response(...)` (ex.: `pixPaymentResponse`, `billPaymentAuthorizeResponse`).
- **Persistência via Cslabs::{writeEntity,readEntity,listEntities,deleteEntity}** — escrevem na tabela `entities` do SQLite (`app/Core/Db.php`). API igual à versão file-based anterior; não há diferença visível para o stream. `listEntities` ordena por `entity_key` (campo `entity` do payload).
- **Purge de cliente:** `Cslabs::purgeClient($clientId)` apaga, em `Db::transaction`, tudo que pertence ao cliente em todas as tabelas + `issued_tokens` em `__global__` cujo `$.client_id` JSON aponta pra ele. Botão "Excluir dados deste cliente" no painel `/shots/<id>/` chama esse método via POST com confirmação por client_id digitado.
- **Transações** — quando um stream faz múltiplos writes que precisam ser atômicos (ex.: onboarding + aliases por documentNumber/clientCode), envolver em `Db::transaction(fn() => ...)`. Já aplicado em onboarding, billpayment, pix payment, spb-transfer, dict-entry, kyc-fileupload, onboarding-bulk.
- **Duplicidade real** — onboarding PF/PJ rejeita repetições de documentNumber/clientCode/email/phone com códigos `CBE022/CBE025/CBE007/CBE023/CBE024`; DICT entry rejeita chave repetida com `CBE189`. Check + write rodam dentro de `Db::transaction`; o closure retorna a estrutura de erro e o stream devolve `HTTP 400` com esse payload. Email normalizado por case+trim, telefone por dígitos apenas.
- O prefixo `/celcoin/` em `basics.php::BASE` é **legado**. `Cslabs::boot()` faz `str_replace('/celcoin/','/',$path)` para neutralizar. Rotas em `web.php` são declaradas sem o prefixo.
- `DEV = true` em `config.php` liga `display_errors` — é o modo padrão local.

## O que evitar
- Chamar API real da Celcoin daqui.
- Adicionar dependências ao `composer.json` sem necessidade clara — o microframework é deliberadamente sem deps.
- Adicionar framework de teste (PHPUnit etc.) — os smokes são scripts PHP puros de propósito, seguindo a regra de zero deps.
- **Mudar shape de stream compartilhado v1/v2 sem checar os dois consumidores.** Vários streams servem os dois paths por alias em `web.php` (`payment-baas`, `key`, `emv`, `internal-transfer`, `wallet-movement`), e a Celcoin real responde shapes diferentes: v1 plano, V2 no envelope `{status, version, body}`. Os consumidores v1 leem caminhos FIXOS no topo, sem fallback. Regra: builder devolve o shape v1, o stream envelopa só quando `Cslabs::isV2()` (ou o path é o da V2). Ver `HOMOLOGACAO_CELCOIN_V2.md` §0.
- Reintroduzir o prefixo `/celcoin/` em URLs (foi removido em `cc6d0e0`).

## Onde encontrar o resto
- `docs/architecture.md` — fluxo de execução e módulos.
- `docs/endpoints.md` — catálogo das rotas simuladas (mapeamento URL → arquivo → método Celcoin).
- `docs/conventions.md` — padrões de código, formato de resposta, metadados.
- `WORK.md` — em andamento, próximo passo, concluído recente.
