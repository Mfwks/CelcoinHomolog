# Gap de homologação — endpoints e webhooks

Derivado da especificação do sistema consumidor (arquivo local `HOMOLOGACAO_CELCOIN.md`, não versionado). Checklist do que falta para o homologador responder a todos os pontos de chamada do cliente. Quando um item for entregue, marcar `[x]` e atualizar `docs/endpoints.md`. Quando tudo aqui virar `[x]`, retirar o arquivo.

## Fontes

- **Doc oficial pública** (referência de shape): https://developers.celcoin.com.br/reference/ (slug por endpoint linkado em cada item).
- **Caminhos do simulador**: `app/web.php` + `app/streams/api/`. Mantemos os paths legados (`/baas-onboarding/v1/...`, `/baas-accountmanager/v1/...`, `/celcoin-baas-pix-dict-webservice/v1/...`, `/baas-walletreports/v1/...`) porque o sistema consumidor foi escrito contra eles — a doc pública só expõe a consolidação v2 (`/baas/v2/...`), mas o homologador precisa responder ao path que o cliente realmente chama. Sempre que a doc v2 diverge, anotamos abaixo.

## Já implementados

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
  - doc v2: https://developers.celcoin.com.br/reference/criar-conta-pf (caminho canônico hoje: `/baas/v2/account/natural-person/create`).
  - Campos: `clientCode`, `documentNumber`, `phoneNumber`, `email`, `motherName`, `fullName`, `socialName?`, `birthDate`, `address`, `isPoliticallyExposedPerson?`, `cadastraChavePix?` (português, cria EVP), `accountOnboardingType` (default `BANKACCOUNT`), `financialDetails?`.
- [x] `POST /baas-onboarding/v1/account/business/create`
  - doc v2: https://developers.celcoin.com.br/reference/criar-conta-pj (canônico: `/baas/v2/account/business/create`).
  - Campos: `clientCode`, `documentNumber` (CNPJ), `contactNumber`, `businessEmail`, `businessName`, `tradingName`, `owner`, `businessAddress`, `cadastraChavePix?`, `financialCompanyDetails?`, `accountOnboardingType`.
- [ ] `POST /baas-onboarding/v1/account/natural-person/create/bulk`
- [ ] `POST /baas-onboarding/v1/account/natural-person/bulk`
- [ ] `POST /baas-onboarding/v1/account/business/create/bulk`
  - doc v2: **não consta na doc pública** — nenhuma página `/reference/` cobre bulk de onboarding. Pode ser interface legada do contrato do cliente; manter inferido a partir dos `create` quando for implementar.
- [ ] `POST /onboarding/v1/onboarding-proposal/natural-person`
  - doc: https://developers.celcoin.com.br/reference/criar-proposta-pessoa-fisica.
  - Campos: `clientCode`, `documentNumber` (11), `phoneNumber` (≤14), `email` (≤100), `motherName`, `fullName` (≤120), `socialName?`, `birthDate`, `address`, `isPoliticallyExposedPerson?` (default `false`), `onboardingType` (default `BAAS`).
- [ ] `POST /onboarding/v1/onboarding-proposal/legal-person`
  - doc: https://developers.celcoin.com.br/reference/criar-proposta-pessoa-juridica.
  - Campos: `clientCode`, `contactNumber` (≤14), `documentNumber` (14), `businessEmail` (≤100), `businessName` (≤350), `tradingName` (≤120), `companyType` (enum `PJ`/`MEI`/`ME`, default `PJ`), `owner` (array — quadro societário), `businessAddress`, `onboardingType` (default `BAAS`).
- [ ] `GET  /onboarding/v1/onboarding-proposal`
  - doc: https://developers.celcoin.com.br/reference/consultar-proposta.
  - Query: `ProposalId?`, `ClientCode?`, `DateFrom?`, `DateTo?`, `Status?` (enum: `CREATED`/`PENDING`/`PENDING_DOCUMENTSCOPY`/`APPROVED`/`REPROVED`/`RESOURCE_ERROR`/`RESOURCE_CREATED`/`PROCESSING_DOCUMENTSCOPY`), `DocumentNumber?`, `Page` (default 1), `Limit` (1–200, default 200), `LimitPerPage` (1–200, default 200 — duplicado com `Limit`, vestígio histórico).

> Nota: spec do consumidor descreve a chave de resposta como `onboardingId`, mas o log real do produtor mostra `onBoardingId` (capital B). Implementação seguiu o log.

## 2. KYC

- [ ] `POST /celcoinkyc/document/v1/fileupload` (multipart)
  - doc: sem página `/reference/`; canônica é o suporte: https://suporte.celcoin.com.br/hc/pt-br/articles/21976851137947.
  - Campos multipart (lowercase sem separador): `cnpj`, `documentnumber` (CPF), `filetype` (`CNH`/`RG`/`PASSPORT`/`RNE`), `front` (file), `onboardingId` (UUID, obrigatório desde 2024-02-27).
  - **Atenção**: KYC v1 foi descontinuado em 2025-04-29 (https://suporte.celcoin.com.br/hc/pt-br/articles/35833363119387). Sucessor é o webview pelo `onboarding-proposal`. Implementar só se o consumidor ainda chama.

## 3. AccountManager

- [x] `PUT    /baas-accountmanager/v1/account/natural-person`
- [x] `PUT    /baas-accountmanager/v1/account/business`
- [x] `PUT    /baas-accountmanager/v1/account/status`
- [x] `DELETE /baas-accountmanager/v1/account/close`
  - doc v2: https://developers.celcoin.com.br/reference/encerra-conta (canônico: `DELETE /baas/v2/account/close`).
  - Query: `Account` ou `DocumentNumber`, `Reason` (obrigatório). DELETE sem body, parâmetros vão em query string.
- [x] `GET    /baas-accountmanager/v1/account/fetch-business`
  - doc v2: **não existe rota dedicada para "business"**. Doc unifica PF e PJ em `GET /baas/v2/account/fetch` (https://developers.celcoin.com.br/docs/listar-contas), discriminando pelo `documentNumber`. Listagem em `GET /baas/v2/account/fetch-all` (janela máx 7 dias).
  - Query `fetch`: `Account` (≤20) **ou** `documentNumber` (camelCase no PJ). Erros típicos: `CBE073`, `CBE039–042`, `CBE370`, `CBE078`.
  - ✅ Revisado: stream aceita `Account` ou `DocumentNumber`; response expandida com `businessAddress` e `owner[]`.

## 4. Wallet / Reports

- [x] `GET      /baas-walletreports/v1/wallet/balance`
- [ ] `GET      /baas-walletreports/v1/wallet/movement`
  - doc v2: https://developers.celcoin.com.br/reference/consultar-extrato (canônico: `/baas/v2/wallet/movement`).
  - Query: `Account?`, `DocumentNumber?`, `DateFrom` (`yyyy-MM-dd`, obrigatório), `DateTo` (idem, obrigatório), `LimitPerPage?`, `Page?`, `Order` (`asc`/`desc` minúsculas, default `asc`).
- [ ] `GET      /tools-conciliation/v1/ConsolidatedStatement`
  - doc: https://developers.celcoin.com.br/reference/consultar-extrato-consolidado.
  - Query: `startDate` (date), `endDate` (date), `page` (default 1), `quantity` (default 1000). Janela máx 15 dias na v2.
  - Campos da resposta (português): `dataContabil`, `nomeHistorico`, `qtdOperacoes`, `debito`, `credito`, `saldoDia`, `saldo`, `historicoId`, `nsa`.
  - URL é **case-sensitive** com `C` maiúsculo em `ConsolidatedStatement`.
- [ ] `GET      /tools-conciliation/v1/exportfile`
  - doc: https://developers.celcoin.com.br/reference/extrair-arquivo (canônico com barra final: `/tools-conciliation/v1/exportfile/`).
  - Query (lowercase sem separador): `filetype` (int32 obrigatório), `accountdate` (`YYYY-MM-DD` obrigatório), `page` (default 1), `quantity` (default 1000).
  - Response polimórfica (15 schemas por tipo de arquivo). Disponível só a partir das 06h do dia, janela máx 6 meses para trás, dados desde jul/2022.
- [ ] `GET      /tools-conciliation/v1/exportfile/types`
  - doc: https://developers.celcoin.com.br/reference/buscar-tipos-de-arquivos.
  - Sem query params. Dicionário do `filetype` numérico usado pelo `exportfile`.
- [x] `POST|PUT /baas-wallet-transactions-webservice/v1/wallet/entry/{conta}`
  - doc: https://developers.celcoin.com.br/reference/gerar-lan%C3%A7amento-1.
  - **Método na doc é apenas POST** (não PUT). Apenas sandbox (não funciona em produção). Campos: `clientCode` (≤200), `amount` (>0), `type` (`CREDIT`/`DEBIT` — tutorial diz só `CREDIT`, mas referência diz "Crédito ou Débito"), `description?` (≤250).
  - ✅ Revisado: status alterado de `SUCCESS` → `CONFIRMED` conforme doc. Stream aceita ambos POST e PUT (rota agnóstica) — manter alias até o consumidor confirmar.
- [ ] `GET      /baas/v2/account/income-report`
  - doc: https://developers.celcoin.com.br/reference/consultar-informe-de-rendimentos.
  - Query: `Account`, `CalendarYear` (default `2023`), `Quarter` (default `1`, só PJ).
  - Response: `body.payerSource{name,documentNumber}`, `body.owner{documentNumber,name,type:NATURAL_PERSON|LEGAL_PERSON,createDate}`, `body.account{branch,account}`, `body.balances[]{calendarYear,amount,currency:BRL,type:SALDO}`, `body.incomeFile` (base64 do PDF — senha = 6 primeiros dígitos do documento), `body.fileType`.

## 5. Internal transfer

- [x] `GET /baas-wallet-transactions-webservice/v1/wallet/internal/transfer/status`

## 6. SPB (TED)

- [x] `POST /baas-wallet-transactions-webservice/v1/spb/transfer`
- [x] `GET  /baas-wallet-transactions-webservice/v1/spb/transfer`

## 7. Pix DICT

- [x] `GET    /baas/v2/pix/dict/entry/external/{conta}/` — alias do BaaS, com e sem trailing slash
- [x] `GET    /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{conta}` (listar — shape inferido)
  - doc v2 (canônico): https://developers.celcoin.com.br/reference/consulta-informa%C3%A7%C3%B5es-da-chave-pix (`GET /baas/v2/pix/dict/entry/{account}`).
  - ✅ Revisado: doc não detalha shape específico de listagem; mantido `{pixKeys[], totalElements}` com cada entry contendo `keyType`/`key`/`account`/`owner`/`createDate`. `keyType` em UPPER (consistente com `pixDictCreateResponse`).
- [x] `POST   /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry`
- [x] `DELETE /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{chave}`

## 8. Pix Claims

- [x] `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim` (shape inferido)
  - doc v2 (canônico `/baas/v2/pix/dict/claim`): guia em https://developers.celcoin.com.br/docs/solicitar-portabilidade-de-chave-pix; slug `/reference/` exato não localizado.
  - Request: `key`, `keyType` (`CPF`/`CNPJ`/`EMAIL`/`PHONE`), `account` (≤20), `claimType` (`PORTABILITY`/`OWNERSHIP`).
  - Response inclui `keyType` em capitalização **Pascal** (`Email`, `Phone`, `CPF`, `CNPJ`) — diferente do request (UPPER). `claimerAccount.accountType` vem como `TRAN` (valor cru do DICT). `createTimestamp` ISO-8601. `status` enum: `OPEN`/`WAITING`/`CONFIRMED`/`CANCELLED`/`COMPLETED`.
  - ✅ Revisado: response expandida com `claimerAccount{participant,branch,account,accountType}`, `claimer{personType,taxId,name}`, `donorParticipant`, `createTimestamp`/`completionPeriodEnd`/`resolutionPeriodEnd`/`lastModified`. Inconsistência `keyType` UPPER→Pascal implementada via mapa (helper privado `buildPixDictClaimBody`).
- [x] `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/confirm`
  - doc canônica: https://developers.celcoin.com.br/docs/responder-portabilidade-de-chave-pix. Slug `/reference/` exato não localizado.
  - Body: `id` (UUID), `reason` (default `USER_REQUESTED`; valores `USER_REQUESTED`/`ACCOUNT_CLOSURE`/`FRAUD`/`DEFAULT_OPERATION`). Códigos: `CBE306` (não está pendente), `CBE320` (não encontrada).
- [x] `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/cancel`
  - doc v2: https://developers.celcoin.com.br/reference/cancela-reivindicacao-e-portabilidade-de-chave-pix.
  - Body: `id`, `reason?` (mesmos 4 enums).
- [x] `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/{id}`
  - doc v2: https://developers.celcoin.com.br/reference/consulta-reivindicacao-e-portabilidade-de-chave-pix.
  - Response: `id`, `claimType`, `key`, `keyType`, `claimerAccount`, `claimer`, `donorParticipant`, `createTimestamp`, `completionPeriodEnd`, `resolutionPeriodEnd`, `lastModified`, `confirmReason`, `cancelReason`, `cancelledBy`, `donorAccount?`. Códigos: `CBE303`, `CBE320`, `CBE348`, `CBE351`.
- [x] `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/list`
  - doc v2: https://developers.celcoin.com.br/reference/consulta-lista-de-reivindicacao-e-portabilidade-de-chave-pix.
  - Query: `DateFrom`, `DateTo`, `LimitPerPage` (string, default `10`), `Page` (string, default `1`), `Status` (default `CONFIRMED`), `claimType` (default `OWNERSHIP`). Note `LimitPerPage`/`Page` como **string** na doc.

## 9. Pix payment — status & reverse

- [x] `POST /pix/v2/reverse/pi/{id}` (shape inferido)
  - doc: https://developers.celcoin.com.br/reference/devolver-um-pagamento-pix-recebido.
  - Path: `{transactionId}`. Body: `clientCode`, `amount` (≤ valor original), `reason` (enum BCB: `BE08`/`FR01`/`MD06`/`SL02`), `additionalInformation?` (≤105), `reversalDescription?` (≤140, texto para o pagador). Limite 90 dias após recebimento. Resultado via webhook `pix-reversal-out`.
  - ✅ Revisado: `originalId` → `originalPaymentId` (campo correto da doc), `returnIdentification` adicionado, `additionalInformation` ecoado, default `reason` → `MD06` (BCB enum).
- [x] `POST /baas-wallet-transactions-webservice/v1/pix/reverse`
  - doc v2: https://developers.celcoin.com.br/reference/iniciar-uma-devolu%C3%A7%C3%A3o-pix (canônico também em `/baas/v2/pix/reverse`).
  - Body: `id`, `endToEndId`, `clientCode` (≤200, único), `amount` (>0), `reason` (`BE08`/`FR01`/`MD06`/`SL02`), `reversalDescription?` (≤140).
  - Response: `{status:"PROCESSING",version,body:{id,amount,clientCode,originalPaymentId,endToEndId,returnIdentification,reason,reversalDescription}}`. GET de status irmão em `.../pix/reverse/status`.
- [x] `GET  /baas/v2/pix/payment/status`
- [x] `GET  /baas-wallet-transactions-webservice/v1/pix/payment/status`
- [x] `GET  /pix/v1/payment/pi/status`
- [x] `GET  /pix/v1/receivement/status`

## 10. QR Code

- [x] `POST   /pix/v1/brcode/static` (shape inferido + EMV gerado)
  - doc: https://developers.celcoin.com.br/reference/criar-um-qrcode-estatico.
  - Body: `key` (obrigatório), `amount?` (double — opcional), `transactionIdentification?`, `merchant?`, `tags?`, `additionalInformation?`, `withdrawal?`, `withdrawalServiceProvider?`. Endpoint **v1** (não v2).
- [x] `GET    /pix/v1/brcode/static/{id}/base64`
- [x] `POST   /pix/v1/brcode/dynamic` (shape inferido)
  - doc: https://developers.celcoin.com.br/reference/criar-um-qrcode-dinamico-dynamic.
  - Body: `clientRequestId`, `key`, `amount` (**string!** default `"5000.00"`), `merchant`, `payerName?`, `payerCPF?` xor `payerCNPJ?` (só dígitos), `payerQuestion?` (≤140), `additionalInformation?` (array de objects), `expiration` (segundos int, default 86400). PUT em `.../dynamic/{transactionId}` para atualizar.
  - ✅ Revisado: `amount.original` agora é string formatada (`"5000.00"`), não float; default aplicado quando ausente. QR estático mantém double conforme doc (inconsistência preservada).
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
- [x] Cliente HTTP que dispara via `Cslabs::scheduleWebhook` + `Cslabs::sendJsonRequest` (já existente) com Basic Auth integrado.
- [x] Envelope centralizado em `Cslabs::webhookEnvelope(entity, status, body)`: `{entity, createTime[s|S]tamp, status, body, webhookId}`. Grafia do timestamp segue logs reais (`createTimeStamp` para pix/spb-in/spb-reversal; `createTimestamp` para o resto). Todos os streams que agendam webhooks (charge, charge-cancel, billpayment, onboarding PF/PJ, internal-transfer, spb-transfer, pix-reverse, wallet-entry, account-status-update, dispatch) usam este helper.
- [x] Corrigida entity de internal-transfer: streams disparavam `wallet.internal.transfer.completed` (legado), agora `internal-transfer-out` conforme log real.

Templates de payload por entity em `Cslabs::sampleWebhookBody(entity, status)` — agora baseados em logs reais para `onboarding-create`, `pix-payment-in`, `pix-payment-out`, `internal-transfer-in/out`, `spb-transfer-in`, `spb-transfer-out`, `spb-reversal-in/out`, `charge-create/in` (PENDING/CONFIRMED/ERROR) e `billpayment/billpayment-occurrence`. Demais entities (`onboarding-backgroundcheck/documentscopy/proposal`, `kyc`, `charge-canceled`, `account-status`) seguem com templates inferidos.

## Caminhos legados × canônicos v2

Os paths que o consumidor chama (e que o simulador serve) são **legados**. A doc pública atual da Celcoin só expõe a consolidação v2 — abaixo, o mapeamento para futura referência. Mantemos os legados no simulador; só usamos os v2 como fonte de shape.

| Simulador (legado) | Doc oficial (v2) |
|---|---|
| `POST /baas-onboarding/v1/account/natural-person/create` | `POST /baas/v2/account/natural-person/create` |
| `POST /baas-onboarding/v1/account/business/create` | `POST /baas/v2/account/business/create` |
| `GET  /baas-onboarding/v1/account/check/` | `GET  /baas/v2/account/check` |
| `GET  /baas-accountmanager/v1/account/fetch/` | `GET  /baas/v2/account/fetch` |
| `GET  /baas-accountmanager/v1/account/fetch-business` | (não existe) — unificado em `/baas/v2/account/fetch` |
| `PUT  /baas-accountmanager/v1/account/natural-person` | `PUT  /baas/v2/account/natural-person` |
| `PUT  /baas-accountmanager/v1/account/business` | `PUT  /baas/v2/account/business` |
| `PUT  /baas-accountmanager/v1/account/status` | `PUT  /baas/v2/account/status` |
| `DELETE /baas-accountmanager/v1/account/close` | `DELETE /baas/v2/account/close` |
| `GET  /baas-walletreports/v1/wallet/balance` | `GET  /baas/v2/wallet/balance` |
| `GET  /baas-walletreports/v1/wallet/movement` | `GET  /baas/v2/wallet/movement` |
| `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{account}` | `GET  /baas/v2/pix/dict/entry/{account}` |
| `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/external/{account}` | `GET  /baas/v2/pix/dict/entry/external/{account}` |
| `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry` | `POST /baas/v2/pix/dict/entry` |
| `DELETE /celcoin-baas-pix-dict-webservice/v1/pix/dict/entry/{key}` | `DELETE /baas/v2/pix/dict/entry/{key}` |
| `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim` | `POST /baas/v2/pix/dict/claim` |
| `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/confirm` | `POST /baas/v2/pix/dict/claim/confirm` |
| `POST /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/cancel` | `POST /baas/v2/pix/dict/claim/cancel` |
| `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/{id}` | `GET  /baas/v2/pix/dict/claim/{id}` |
| `GET  /celcoin-baas-pix-dict-webservice/v1/pix/dict/claim/list` | `GET  /baas/v2/pix/dict/claim/list` |

Já em **v1 canônica** (sem variante v2 publicada): `pix/v1/brcode/*`, `pix/v1/collection/*`, `pix/v1/emv`, `pix/v1/location/*`, `pix/v1/payment`, `pix/v1/dict/v2/key`, `pix/v1/receivement/status`, `pix/v1/payment/pi/status`, `pix/v2/reverse/pi/{id}` (essa é v2), `baas-wallet-transactions-webservice/v1/*`, `tools-conciliation/v1/*`, `onboarding/v1/onboarding-proposal/*`, `celcoinkyc/document/v1/fileupload`, `api-integration-baas-webservice/v1/charge`, `baas/v2/billpayment*`, `baas/v2/charge/{txid}`.

## Inconsistências reais documentadas (preservar no simulador)

São quirks confirmados na doc oficial — não corrigir, replicar fielmente:

1. `cadastraChavePix` (em **português**) no body de criação de conta PF/PJ — único campo em PT-BR no payload.
2. DICT claim: request envia `keyType: "CPF"`/`"EMAIL"`/`"PHONE"` (UPPER); response devolve `keyType: "CPF"`/`"Email"`/`"Phone"` (capitalização **Pascal**). Bug histórico que virou contrato.
3. QR Code estático tem `amount: double`; QR Code dinâmico tem `amount: string` (`"5000.00"`).
4. `expiration` no QR dinâmico é **inteiro de segundos**, não ISO-8601 duration.
5. Webhooks: timestamp ora vem como `createTimeStamp` (capital S) — para `pix-payment-*`, `spb-transfer-in`, `spb-reversal-*` — ora como `createTimestamp` — para `onboarding-*`, `internal-transfer-*`, `spb-transfer-out`, `billpayment`. Grafia variada confirmada por logs reais e replicada em `Cslabs::webhookTimestampKey`.
6. Conciliação `exportfile`: campos em query são lowercase sem separador (`filetype`, `accountdate`), diferente do resto da API que é PascalCase.
7. KYC `fileupload`: idem — `documentnumber`, `filetype` lowercase. Endpoint sem hífen no path (`celcoinkyc`).
8. `ConsolidatedStatement`: case-sensitive na URL (C maiúsculo). Campos da resposta em **português** (`dataContabil`, `nomeHistorico`, `qtdOperacoes`, `saldoDia`).
9. Onboarding response usa `onBoardingId` (B maiúsculo) ao invés de `onboardingId`.
10. `billpayment` webhook usa `convenant` (typo de `covenant`) — preservado em logs.

## Discrepâncias contra o estado atual

1. ~~**`/v5/transactions/billpayments/authorize`** — o spec descreve `POST` com body~~ — resolvido: o stream usa `Cslabs::requestBody()` que parse JSON de POST com fallback para `$_GET`; a mesma rota serve ambos métodos. Resposta atualizada para o shape real (settleDate `dd/mm/YYYY`, `dueDate` ISO com `Z`, `registerData` com `payDueDate`, `dueDateRegister`, `allowChangeValue`, `totalUpdated`, etc.).
2. ~~**`/baas-webhookmanager/v1/webhook/subscription`** — estender métodos~~ — resolvido: stream atende GET (lista + filtros Entity/Active), POST/PUT/PATCH (criar/atualizar) e DELETE (por path `{entity}` ou query `?Entity=`). Rotas adicionais registradas no `web.php`.
3. **Trailing slash** — várias rotas atuais foram registradas com `/` final (`/baas-onboarding/v1/account/check/`, `.../fetch/`). O cliente envia sem barra. Conferir se o roteador normaliza; se não, duplicar registro ou padronizar.
4. **mTLS** — opcional pelo spec; o ambiente Apache deve aceitar conexão sem cert. Confirmar config.
5. **Bulk de onboarding** — `/create/bulk` e `/bulk` em PF e PJ não constam da doc pública v2. Se o consumidor realmente chama, manter inferência a partir do `create` (array de objetos com mesmos campos, resposta com array de `onBoardingId`).
6. **KYC v1 descontinuado** — `/celcoinkyc/document/v1/fileupload` foi descontinuado em 2025-04-29. Manter no simulador apenas se o consumidor ainda usa.
7. **`fetch-business`** — não existe rota dedicada na doc v2 (PF e PJ unificados em `/baas/v2/account/fetch`). Stream atual segue o nome legado do consumidor; shape do PJ deve seguir `fetch` da doc v2.

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
