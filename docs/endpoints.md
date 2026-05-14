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
| GET     | `/v5/transactions/billpayments/authorize`                                            | `api/billpayment-authorize`  | `consultaPagamentos` (consulta boleto)    |
| GET     | `/pix/v1/dict/v2/key`                                                                | `api/key-old`                | `consultarChave` (antigo)                 |
| GET     | `/celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/{account}/`            | `api/key`                    | Consulta de chave PIX (DICT BaaS)         |
| POST    | `/pix/v1/payment`                                                                    | `api/payment`                | `enviarPix`                               |
| POST    | `/baas-wallet-transactions-webservice/v1/pix/payment`                                | `api/payment-baas`           | `enviarPix` (BaaS)                        |
| POST    | `/baas-wallet-transactions-webservice/v1/wallet/internal/transfer`                   | `api/internal-transfer`      | Transferência interna entre contas        |
| GET     | `/v5/merchant/balance`                                                               | `api/balance`                | `saldo`                                   |
| GET     | `/baas-onboarding/v1/account/check/`                                                 | `api/account-status`         | Verificação de status da conta            |
| GET     | `/baas-accountmanager/v1/account/fetch/`                                             | `api/account`                | Busca de dados da conta                   |
| POST    | `/baas-webhookmanager/v1/webhook/subscription`                                       | `api/webhook-subscription`   | Assinatura de webhooks                    |

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
