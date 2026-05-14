# CelcoinHomolog — guia para sessões com Claude

Ambiente PHP que **simula** o ambiente de homologação da Celcoin. Não chama a API real da Celcoin daqui — esse é justamente o propósito do projeto.

## Stack
- PHP puro sobre um microframework próprio (`App\Core\*`).
- `composer.json` declara apenas o autoload PSR-4 `App\` → `app/`. Sem dependências de runtime.
- Sem banco em uso: `app/config.php` tem placeholders (`xxx`) em `$database`.
- Logs: `app/tmp/errors.log` (configurado em `config.php`).
- Timezone fixado em `America/Sao_Paulo` (`.htaccess` e `config.php`).

## Como rodar
- Apache + `.htaccess` reescreve tudo para `index.php`. Servir o diretório raiz como DocumentRoot.
- Servidor embutido (`php -S`) **não** consegue rotear sozinho — precisa de Apache (ou nginx replicando a regra).
- Não há suíte de testes: `tests/` está vazio.

## Roteamento (resumo)
`index.php` → `axis.php` (constantes de diretórios) → `app/start.php` (bootstrap) → `app/web.php` (mapa de rotas) → `include app/streams/<stream>.php`.
Endpoints da API Celcoin ficam em `app/streams/api/`, um arquivo por rota.

## Convenções não-óbvias
- Todo stream de API começa com `include_once __DIR__ . '/api-stream.php';` — ele dispara `Cslabs::boot()` e instala um `ob_start` que injeta metadados no JSON de saída e persiste a interação.
- Corpo de requisição: usar `Cslabs::requestBody()` (resolve JSON e form).
- Respostas mockadas: métodos `Cslabs::*Response(...)` (ex.: `pixPaymentResponse`, `billPaymentAuthorizeResponse`).
- O prefixo `/celcoin/` em `basics.php::BASE` é **legado**. `Cslabs::boot()` faz `str_replace('/celcoin/','/',$path)` para neutralizar. Rotas em `web.php` são declaradas sem o prefixo.
- `DEV = true` em `config.php` liga `display_errors` — é o modo padrão local.

## O que evitar
- Chamar API real da Celcoin daqui.
- Adicionar dependências ao `composer.json` sem necessidade clara — o microframework é deliberadamente sem deps.
- Criar testes em `tests/` sem alinhamento prévio — não há ferramental escolhido.
- Reintroduzir o prefixo `/celcoin/` em URLs (foi removido em `cc6d0e0`).

## Onde encontrar o resto
- `docs/architecture.md` — fluxo de execução e módulos.
- `docs/endpoints.md` — catálogo das rotas simuladas (mapeamento URL → arquivo → método Celcoin).
- `docs/conventions.md` — padrões de código, formato de resposta, metadados.
- `WORK.md` — em andamento, próximo passo, concluído recente.
