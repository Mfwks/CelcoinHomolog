# Gap de homologação — endpoints e webhooks

Derivado da especificação do sistema consumidor (arquivo local `HOMOLOGACAO_CELCOIN.md`, não versionado). Checklist do que falta para o homologador responder a todos os pontos de chamada do cliente. Quando um item for entregue, marcar `[x]` e atualizar `docs/endpoints.md`. Quando tudo aqui virar `[x]`, retirar o arquivo.

Fonte da verdade atual: `app/web.php` + `app/streams/api/`. Já implementados:

- `POST /v5/token`
- `POST /baas-wallet-transactions-webservice/v1/pix/payment`
- `POST /baas-wallet-transactions-webservice/v1/wallet/internal/transfer`
- `POST /pix/v1/payment`
- `GET  /pix/v1/dict/v2/key` (legado)
- `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/{account}/`
- `GET  /v5/merchant/balance`
- `GET  /v5/transactions/billpayments/authorize` *(spec pede POST — ver discrepância 1)*
- `GET  /baas-onboarding/v1/account/check/`
- `GET  /baas-accountmanager/v1/account/fetch/`
- `POST /baas-webhookmanager/v1/webhook/subscription` *(stream lê `REQUEST_METHOD`; cobre só POST hoje — ver discrepância 2)*

## 1. Onboarding (criação de conta)

- [x] `POST /baas-onboarding/v1/account/natural-person/create`
- [x] `POST /baas-onboarding/v1/account/business/create`
- [ ] `POST /baas-onboarding/v1/account/natural-person/create/bulk`
- [ ] `POST /baas-onboarding/v1/account/natural-person/bulk`
- [ ] `POST /baas-onboarding/v1/account/business/create/bulk`
- [ ] `POST /onboarding/v1/onboarding-proposal/natural-person`
- [ ] `POST /onboarding/v1/onboarding-proposal/legal-person`
- [ ] `GET  /onboarding/v1/onboarding-proposal`

> Nota: spec descreve a chave de resposta como `onboardingId`, mas o log real do produtor mostra `onBoardingId` (capital B). Implementação seguiu o log.

## 2. KYC

- [ ] `POST /celcoinkyc/document/v1/fileupload` (multipart)

## 3. AccountManager

- [x] `PUT    /baas-accountmanager/v1/account/natural-person`
- [x] `PUT    /baas-accountmanager/v1/account/business`
- [x] `PUT    /baas-accountmanager/v1/account/status`
- [x] `DELETE /baas-accountmanager/v1/account/close`
- [x] `GET    /baas-accountmanager/v1/account/fetch-business`

## 4. Wallet / Reports

- [x] `GET      /baas-walletreports/v1/wallet/balance`
- [ ] `GET      /baas-walletreports/v1/wallet/movement` — precisa de log para shape de movimento
- [ ] `GET      /tools-conciliation/v1/ConsolidatedStatement` — precisa de log
- [ ] `GET      /tools-conciliation/v1/exportfile` — precisa de log (resposta é arquivo)
- [ ] `GET      /tools-conciliation/v1/exportfile/types` — precisa de log
- [x] `POST|PUT /baas-wallet-transactions-webservice/v1/wallet/entry/{conta}` (shape inferido do spec)
- [ ] `GET      /baas/v2/account/income-report` — precisa de log (resposta `body.incomeFile` base64)

## 5. Internal transfer

- [x] `GET /baas-wallet-transactions-webservice/v1/wallet/internal/transfer/status`

## 6. SPB (TED)

- [x] `POST /baas-wallet-transactions-webservice/v1/spb/transfer`
- [x] `GET  /baas-wallet-transactions-webservice/v1/spb/transfer`

## 7. Pix DICT

- [ ] `GET    /baas/v2/pix/dict/entry/external/{conta}/` — alias do BaaS já implementado; precisa registrar variante de rota
- [x] `GET    /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{conta}` (listar — shape inferido)
- [x] `POST   /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry`
- [x] `DELETE /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{chave}`

## 8. Pix Claims

- [x] `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim` (shape inferido)
- [x] `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/confirm`
- [x] `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/cancel`
- [x] `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/{id}`
- [x] `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/list`

## 9. Pix payment — status & reverse

- [x] `POST /pix/v2/reverse/pi/{id}` (shape inferido)
- [x] `POST /baas-wallet-transactions-webservice/v1/pix/reverse`
- [x] `GET  /baas/v2/pix/payment/status`
- [x] `GET  /baas-wallet-transactions-webservice/v1/pix/payment/status`
- [x] `GET  /pix/v1/payment/pi/status`
- [x] `GET  /pix/v1/receivement/status`

## 10. QR Code

- [x] `POST   /pix/v1/brcode/static` (shape inferido + EMV gerado)
- [x] `GET    /pix/v1/brcode/static/{id}/base64`
- [x] `POST   /pix/v1/brcode/dynamic` (shape inferido)
- [x] `POST   /pix/v1/collection/immediate` (alias do dynamic)
- [x] `GET    /pix/v1/collection/immediate/payload/{url}`
- [x] `GET    /pix/v1/collection/duedate/payload/{url}`
- [x] `POST   /pix/v1/emv`
- [x] `GET    /pix/v1/location/{id}/base64`
- [x] `DELETE /baas/v2/charge/{txid}`

## 11. Boletos

- [x] `POST /api-integration-baas-webservice/v1/charge`
- [x] `POST /baas/v2/billpayment`
- [x] `GET  /baas/v2/billpayment/status`

## 12. Webhook subscription — métodos faltantes

- [x] `GET    /baas-webhookmanager/v1/webhook/subscription` (e `?Entity=&Active=`)
- [x] `PUT    /baas-webhookmanager/v1/webhook/subscription/{entity}`
- [x] `DELETE /baas-webhookmanager/v1/webhook/subscription/entity` (e `/subscription/{entity}`)

## 13. Disparador de webhooks de saída

- [x] Persistir inscrições em `webhook_subscriptions` (já era feito; agora também guarda flag `active`).
- [x] Endpoint admin para disparar entity por status: `POST /cslabs/webhook/dispatch` com body `{entity, status, body?, error?, delaySeconds?, webhookUrl?}`. Usa template default por entity quando `body` não vem no request.
- [x] Cliente HTTP que dispara via `Cslabs::scheduleWebhook` + `Cslabs::sendJsonRequest` (já existente). Falta apenas integrar Basic Auth no `sendJsonRequest` para usar a `auth` da inscrição — pendente em iteração futura.

Templates de payload por entity em `Cslabs::sampleWebhookBody(entity, status)`. Entidades cobertas: `onboarding-create`, `onboarding-backgroundcheck`, `onboarding-documentscopy`, `onboarding-proposal`, `kyc`, `pix-payment-in/out`, `internal-transfer-in/out`, `spb-transfer-in/out`, `spb-reversal-in/out`, `charge-create`, `charge-in`, `charge-canceled`, `billpayment`, `billpayment-occurrence`, `account-status` (demais entities recebem template genérico `{id, timestamp}`).

## Discrepâncias contra o estado atual

1. ~~**`/v5/transactions/billpayments/authorize`** — o spec descreve `POST` com body~~ — resolvido: o stream usa `Cslabs::requestBody()` que parse JSON de POST com fallback para `$_GET`; a mesma rota serve ambos métodos. Resposta atualizada para o shape real (settleDate `dd/mm/YYYY`, `dueDate` ISO com `Z`, `registerData` com `payDueDate`, `dueDateRegister`, `allowChangeValue`, `totalUpdated`, etc.).
2. ~~**`/baas-webhookmanager/v1/webhook/subscription`** — estender métodos~~ — resolvido: stream atende GET (lista + filtros Entity/Active), POST/PUT/PATCH (criar/atualizar) e DELETE (por path `{entity}` ou query `?Entity=`). Rotas adicionais registradas no `web.php`.
3. **Trailing slash** — várias rotas atuais foram registradas com `/` final (`/baas-onboarding/v1/account/check/`, `.../fetch/`). O cliente envia sem barra. Conferir se o roteador normaliza; se não, duplicar registro ou padronizar.
4. **mTLS** — opcional pelo spec; o ambiente Apache deve aceitar conexão sem cert. Confirmar config.

## Prioridade sugerida

Implementar em camadas, do mais usado para o menos:

1. Boletos (`/api-integration-baas-webservice/v1/charge`, `/baas/v2/billpayment` + status) — é o fluxo que o último commit estava endereçando.
2. Onboarding PF/PJ + status check com diferentes cenários (`CONFIRMED`/`PROCESSING`/`ERROR`).
3. Pix DICT completo (criar/listar/excluir/claims) + reverse e status.
4. AccountManager (PUT/DELETE/fetch-business).
5. SPB transfer + status.
6. Wallet reports + extrato/income-report.
7. QR Code (estático e dinâmico).
8. Disparador de webhooks de saída.
