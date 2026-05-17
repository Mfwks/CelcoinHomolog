# Catálogo de endpoints simulados

Fonte da verdade: `app/web.php`. Esta tabela espelha o que está registrado lá; se houver divergência, o código vence — atualizar este doc.

O microframework não restringe método HTTP no roteador; o método aceito é definido (ou implicitamente assumido) dentro do próprio stream. A coluna **Método** abaixo reflete o uso esperado pelo contrato Celcoin equivalente.

## Rotas internas (não-Celcoin)

| URL                     | Stream                      | Propósito                                    |
| ----------------------- | --------------------------- | -------------------------------------------- |
| `/`                     | `home/home`                 | Landing.                                     |
| `/home`                 | `api/home`                  | Home da API (informativo).                   |
| `/endpoints`            | `api/endpoints`             | Lista as rotas registradas (introspecção).   |
| `/shots/{identifier}/`  | `api/shots`                 | Captura/inspeção de chamadas.                |
| `/pages`, `/400`..`/504`, `/maintenance`, `/status`, `/status-fake` | `pages/*` | Páginas de erro e status. |

## Rotas Celcoin

| Método  | URL                                                                                  | Stream                       | Operação Celcoin                          |
| ------- | ------------------------------------------------------------------------------------ | ---------------------------- | ----------------------------------------- |
| POST    | `/v5/token`                                                                          | `api/token`                  | `gerarToken`                              |
| POST    | `/v5/transactions/billpayments/authorize`                                            | `api/billpayment-authorize`  | `consultaPagamentos` (consulta boleto)    |
| POST    | `/baas/v2/billpayment`                                                               | `api/billpayment`            | `efetuaPagamento` (pagamento de boleto)   |
| GET     | `/baas/v2/billpayment/status`                                                        | `api/billpayment-status`     | `confirmarPagamento` (status pagamento)   |
| POST    | `/api-integration-baas-webservice/v1/charge`                                         | `api/charge`                 | `emissaoBoletoCobranca`                   |
| GET     | `/pix/v1/dict/v2/key`                                                                | `api/key-old`                | `consultarChave` (antigo)                 |
| GET     | `/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/{account}/`            | `api/key`                    | Consulta de chave PIX (DICT BaaS)         |
| GET     | `/baas/v2/pix/dict/entry/external/{account}[/]`                                      | `api/key`                    | Consulta de chave PIX (alias v2)          |
| POST    | `/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry`                                | `api/dict-entry-create`      | Criar chave Pix                           |
| DELETE  | `/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{key}`                          | `api/dict-entry-delete`      | Excluir chave Pix                         |
| POST    | `/pix/v1/payment`                                                                    | `api/payment`                | `enviarPix`                               |
| POST    | `/baas-wallet-transactions-webservice/v1/pix/payment`                                | `api/payment-baas`           | `enviarPix` (BaaS)                        |
| POST    | `/baas-wallet-transactions-webservice/v1/wallet/internal/transfer`                   | `api/internal-transfer`      | Transferência interna entre contas        |
| GET     | `/baas-wallet-transactions-webservice/v1/wallet/internal/transfer/status`            | `api/internal-transfer-status` | Status de transferência interna         |
| POST/GET| `/baas-wallet-transactions-webservice/v1/spb/transfer`                               | `api/spb-transfer`           | TED SPB (envio + consulta)                |
| GET     | `/baas/v2/pix/payment/status`                                                        | `api/pix-payment-status-baas`| Status pagamento Pix (BaaS)               |
| GET     | `/pix/v1/payment/pi/status`                                                          | `api/pix-payment-status-old` | Status pagamento Pix (legado)             |
| GET     | `/v5/merchant/balance`                                                               | `api/balance`                | `saldo`                                   |
| GET     | `/baas-walletreports/v1/wallet/balance`                                              | `api/wallet-balance`         | Saldo por documento                       |
| GET     | `/pix/v1/brcode/static/{transactionId}/base64`                                       | `api/brcode-static-base64`   | Imagem QR estático em base64              |
| POST    | `/pix/v1/emv`                                                                        | `api/emv`                    | Decodificar EMV (QR dinâmico)             |
| GET     | `/pix/v1/collection/{immediate,duedate}/payload/{url}`                               | `api/collection-payload`     | Consulta payload da cobrança              |
| POST    | `/baas-onboarding/v1/account/natural-person/create`                                  | `api/onboarding-natural-person` | Criação de conta PF                    |
| POST    | `/baas-onboarding/v1/account/business/create`                                        | `api/onboarding-business`    | Criação de conta PJ                       |
| GET     | `/baas-onboarding/v1/account/check`, `/baas-onboarding/v1/account/check/`            | `api/account-status`         | Verificação de status da conta            |
| GET     | `/baas-accountmanager/v1/account/fetch`, `/baas-accountmanager/v1/account/fetch/`    | `api/account`                | Busca de dados da conta                   |
| PUT     | `/baas-accountmanager/v1/account/status`                                             | `api/account-status-update`  | Ativar/bloquear conta                     |
| PUT     | `/baas-accountmanager/v1/account/natural-person`                                     | `api/account-update-natural-person` | Atualizar dados PF                  |
| POST/GET/PUT/DELETE | `/baas-webhookmanager/v1/webhook/subscription[/{entity}]`                | `api/webhook-subscription`   | CRUD de assinaturas de webhook            |
| POST    | `/cslabs/webhook/dispatch`                                                           | `api/webhook-dispatch`       | Disparar webhook para uma `entity` (admin)|
| POST    | `/baas-wallet-transactions-webservice/v1/pix/reverse`                                | `api/pix-reverse-baas`       | Devolução Pix (BaaS)                      |
| POST    | `/pix/v2/reverse/pi/{transactionId}`                                                 | `api/pix-reverse-old`        | Devolução Pix (legado)                    |
| GET     | `/baas-wallet-transactions-webservice/v1/pix/payment/status`                         | `api/pix-payment-status-baas`| Status pagamento Pix (BaaS, alias)        |
| GET     | `/pix/v1/receivement/status`                                                         | `api/pix-payment-status-old` | Status recebimento Pix (legado)           |
| POST/PUT| `/baas-wallet-transactions-webservice/v1/wallet/entry/{account}`                     | `api/wallet-entry`           | Lançamento manual CREDIT/DEBIT            |
| GET     | `/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{account}`                      | `api/dict-entry-list`        | Listar chaves de uma conta                |
| POST    | `/celcoin-baas-pix-dict-webservice/v1/pix/dict/claim[/{confirm,cancel}]`             | `api/dict-claim`             | Abrir/confirmar/cancelar claim            |
| GET     | `/celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/list`                           | `api/dict-claim-list`        | Listar claims                             |
| GET     | `/celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/{id}`                           | `api/dict-claim-router`      | Consultar claim por id                    |
| POST    | `/pix/v1/brcode/static`                                                              | `api/brcode-static`          | Criar QR estático                         |
| POST    | `/pix/v1/brcode/dynamic`, `/pix/v1/collection/immediate`                             | `api/brcode-dynamic`         | Criar QR dinâmico                         |
| GET     | `/pix/v1/location/{locationId}/base64`                                               | `api/location-base64`        | Imagem da location                        |
| DELETE  | `/baas/v2/charge/{txid}`                                                             | `api/charge-cancel`          | Cancelar cobrança                         |
| PUT     | `/baas-accountmanager/v1/account/business`                                           | `api/account-update-business`| Atualizar dados PJ                        |
| DELETE  | `/baas-accountmanager/v1/account/close`                                              | `api/account-close`          | Encerrar conta                            |
| GET     | `/baas-accountmanager/v1/account/fetch-business`                                     | `api/account-fetch-business` | Buscar conta PJ por documento             |
| POST    | `/onboarding/v1/onboarding-proposal/natural-person`                                  | `api/onboarding-proposal-natural-person` | Criar proposta de onboarding PF       |
| POST    | `/onboarding/v1/onboarding-proposal/legal-person`                                    | `api/onboarding-proposal-legal-person` | Criar proposta de onboarding PJ        |
| GET     | `/onboarding/v1/onboarding-proposal`                                                 | `api/onboarding-proposal-list` | Consultar propostas com filtros           |
| GET     | `/baas-walletreports/v1/wallet/movement`, `/baas/v2/wallet/movement`                 | `api/wallet-movement`        | Extrato/movimentação por conta            |
| GET     | `/baas/v2/account/income-report`                                                     | `api/income-report`          | Informe de rendimentos (PDF base64)       |
| GET     | `/tools-conciliation/v1/ConsolidatedStatement`                                       | `api/consolidated-statement` | Extrato consolidado (campos PT-BR)        |
| GET     | `/tools-conciliation/v1/exportfile/types`                                            | `api/exportfile-types`       | Dicionário de tipos de arquivo            |

## Padrão de cada stream

```php
<?php
include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

$body = Cslabs::requestBody();
$body = is_array($body) ? $body : $_GET;

header('Content-Type: application/json');
echo json_encode(
    Cslabs::<operacao>Response($body),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
```

A geração da resposta vive em `App\Core\Cslabs::<operacao>Response()`. Para adicionar um endpoint novo:
1. Registrar a rota em `app/web.php`.
2. Criar o arquivo em `app/streams/api/<nome>.php` seguindo o padrão acima.
3. Adicionar o método `<operacao>Response` em `App\Core\Cslabs`.
4. Atualizar este catálogo.
