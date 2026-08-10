# Ambiente de Homologação Celcoin — Especificação **V2** (`modules/celcoinv2`)

Documento **complementar** ao `HOMOLOGACAO_CELCOIN.md`. Aquele foi extraído de `modules/baas`, `modules/pix`, `modules/boleto`; **este** cobre a integração paralela `modules/celcoinv2` do sistema consumidor (`byii/fast`), que o doc original não incluiu.

Extraído da leitura direta de: `components/CelcoinV2HttpClient.php`, `services/{Onboarding,Account,Pix,Ted,Charge,BillPayment,Topup,CelcoinV2Webhook}Service.php`, `controllers/webhook/CelcoinController.php`, `components/CelcoinErrorCodes.php`.

---

## 0. Por que a V2 exige um capítulo próprio

A V2 **não** é uma reescrita de endpoints: é uma **superfície de rotas diferente**. O mesmo negócio (conta, wallet, pix, dict, webhook) que a v1 chama em nomes esparramados (`baas-accountmanager/v1`, `baas-walletreports/v1`, `celcoin-baas-pix-dict-webservice/v1`, `baas-webhookmanager/v1`), a V2 **consolida sob o prefixo `baas/v2/*`**. Além disso traz **produtos que a v1 não tem** (recarga/topup, webhook replay, DICT OTP, dayBalance, PDF de boleto).

Consequência prática para o mock: as rotas `baas/v2/*` que hoje **não** existem retornam **404** quando chamadas pela V2. Ver o mapa de implementação na seção 9.

**Diferenças estruturais em relação à v1:**

| Aspecto | v1 (`baas`/`pix`) | V2 (`celcoinv2`) |
|---|---|---|
| Base URL (env) | `CELCOIN_URL` | `CELCOIN_V2_URL` → fallback `CELCOIN_URL` |
| Credenciais | `CELCOIN_CLIENT_ID`/`_SECRET` | `CELCOIN_V2_CLIENT_ID`/`_SECRET` → fallback nas v1 |
| Convenção de rota | nomes de webservice por domínio | tudo em `baas/v2/*` (exceto token, onboarding, KYC, SPB, charge-create, topups) |
| Token | `POST /v5/token` | **igual** (`POST /v5/token`, form-urlencoded) |
| mTLS | `@cert/celcoin/*` | **igual** |

> Se `CELCOIN_V2_URL` não estiver apontando para o mock, o `celcoinv2` **não passa pelo homologador**. Confirmar essa env no deploy do consumidor.

---

## 1. Autenticação

Idêntica à v1. `CelcoinV2HttpClient::getToken()`:

`POST {base}/v5/token` — `Content-Type: application/x-www-form-urlencoded`

```
grant_type=client_credentials
client_id=$CELCOIN_V2_CLIENT_ID      (fallback $CELCOIN_CLIENT_ID)
client_secret=$CELCOIN_V2_CLIENT_SECRET (fallback $CELCOIN_CLIENT_SECRET)
```

Resposta 200 exigida: `{ "access_token": "...", "token_type": "bearer", "expires_in": 3600 }` (só `access_token` é lido). Non-2xx ou `access_token` vazio → o cliente lança exceção e aborta a operação.

Demais chamadas: `Authorization: Bearer <token>`, `Accept: application/json`, `Content-Type: application/json` (exceto QR estático/dinâmico, que mandam `Content-Type: application/json-patch+json`, e o KYC, que é multipart).

mTLS: anexa `@cert/celcoin/certificado.crt` + `@cert/celcoin/chave_decrypted.key` quando os arquivos existem; opcional, não deve falhar se ausentes.

### 1.1 Convenção de resposta (verificada em log real de produção)

> Os shapes desta seção e do **Apêndice A** foram extraídos de `runtime/logs/celcoin_prod.log` do consumidor — tráfego **real** contra `https://api.openfinance.celcoin.com.br` (gateway atual da Celcoin, não o `apicorp` do doc v1). Onde não houve tráfego real, o shape segue inferido do código e está marcado como tal.

No código o cliente faz `$providerBody = $response->body ?? $response;` (aceita envelopado ou plano). Na prática, a Celcoin **quase sempre envelopa**:

```json
{ "body": { ...campos... }, "status": "SUCCESS", "version": "1.0.0" }
```

Fatos reais que o mock deve reproduzir:

- **`version`**: `"1.0.0"` em onboarding/pix/wallet/webhook; **`"1.1.0"` em charge** (`api-integration-baas-webservice` e `baas/v2/charge`).
- **Ordem dos campos varia** entre endpoints (`status`/`version`/`body` aparecem em ordens diferentes) — não assuma ordem fixa.
- **`status`** no envelope: `SUCCESS`, `PROCESSING`, `CONFIRMED`, `ERROR` (depende da operação — ver cada endpoint).
- **Erro** (qualquer HTTP não-2xx): `{ "status":"ERROR", "error":{ "errorCode":"...","message":"..." }, "version":"..." }`. O cliente lê `error.errorCode` (ou `error.code`/`errorCode`/`code`) e vira `CelcoinApiException`.
- **Duas exceções ao envelope** (importantes):
  1. `POST /v5/transactions/billpayments/authorize` responde **plano, sem `body`/`version`/`status`** — usa `transactionId` numérico + `errorCode:"000"` + `status:0` (formato legado v5).
  2. `GET/PUT /baas/v2/account/status` 404 responde **objeto cru do gateway**: `{ "statusCode":404, "message":"Resource not found" }` (sem `error`/`version`).
- **Quirks reais a imitar:** mensagens de erro frequentemente usam espaço não-quebrável ` ` no lugar de espaço normal; casing inconsistente entre entities de webhook (`createTimestamp` no onboarding vs `createTimeStamp` no pix) e na chave e2e (`endtoEndId` no DICT vs `endToEndId` no pix payment).

Detalhe de cada endpoint no **Apêndice A** (shapes reais) e §10 (erros reais observados).

---

## 2. Onboarding

> A V2 cria conta via **proposta** (`onboarding/v1/onboarding-proposal/*`), não pelo `baas-onboarding/v1/account/*/create`. O `/account/check` é reaproveitado para status.

### 2.1 Criar proposta PF
`POST {base}/onboarding/v1/onboarding-proposal/natural-person`

Body (montado em `OnboardingService::buildPfProposalBody`):
```json
{
  "clientCode": "BO-CV2-<conta_id>-<epoch>",
  "documentNumber": "06170097914",
  "phoneNumber": "+5511999999999",
  "email": "x@y.com",
  "motherName": "MARIA SILVA",
  "fullName": "JOAO SOUZA",
  "socialName": "JOAO SOUZA",
  "birthDate": "31-12-1990",
  "address": { "postalCode":"01001000","street":"...","number":"123",
               "neighborhood":"...","city":"...","state":"SP","complement":"..." },
  "isPoliticallyExposedPerson": false,
  "onboardingType": "BAAS",
  "financialDetails": { "declaredIncome":"DINP02","occupation":"ONP31","netWorth":"NWNP02" }
}
```
Enums: `declaredIncome` DINP01..05, `occupation` ONP01..31, `netWorth` NWNP01..05.

### 2.2 Criar proposta PJ
`POST {base}/onboarding/v1/onboarding-proposal/legal-person`

```json
{
  "clientCode":"BO-CV2-...","contactNumber":"+55...","documentNumber":"00000000000191",
  "businessEmail":"...","businessName":"...","tradingName":"...","companyType":"PJ",
  "owner":[{ "ownerType":"SOCIO","documentNumber":"...","fullName":"...","phoneNumber":"+55...",
             "email":"...","motherName":"...","socialName":"","birthDate":"dd-mm-YYYY",
             "address":{ "postalCode","street","number","addressComplement","neighborhood","city","state" },
             "isPoliticallyExposedPerson":false }],
  "businessAddress":{ "postalCode","street","number","addressComplement","neighborhood","city","state" },
  "onboardingType":"BAAS",
  "financialCompanyDetails":{ "declaredCompanyRevenue":"DCRB02" },
  "financialOwnerDetails":{ "ownerDeclaredIncome":"ODIB02" }
}
```
Enums: `declaredCompanyRevenue` DCRB01..05, `ownerDeclaredIncome` ODIB01..05.

**Resposta consumida (PF e PJ)** — campos lidos em `normalizeOnboardingResponse`/`saveAccountMapping`, buscados na raiz **ou** em `body`, `body.proposal[0]`:
```json
{
  "body": {
    "clientCode": "...",
    "onboardingId": "<uuid>",
    "proposalId": "<uuid>",
    "status": "PROCESSING",
    "account": { "account": "443168489", "branch": "0001" },
    "urlDocumentscopy": "https://...",       // ou urlDocumentoscopy, ou documentscopys[0].url
    "documentscopys": [ { "url": "https://..." } ]
  },
  "status": "PROCESSING",
  "version": "1.0.0"
}
```
`status` mapeados: `PROCESSING`, `CONFIRMED`, `PENDING`, `APPROVED`, `REPROVED`/`REJECTED`, `ERROR`.

### 2.3 Consultar status do onboarding
`GET {base}/baas-onboarding/v1/account/check?clientCode={code}`
`GET {base}/baas-onboarding/v1/account/check?onboardingId={uuid}`

Resposta consumida: `body.status`, `body.account.account`, `body.account.branch` (ou os mesmos na raiz).

### 2.4 Consultar proposta
`GET {base}/onboarding/v1/onboarding-proposal?ProposalId={id}[&clientCode={code}]`

Resposta consumida: mesmos campos de 2.2 (`proposalId`, `onboardingId`, `clientCode`, `status`, `account.*`, `urlDocumentscopy`, `documentscopys[].url`). O cliente aceita o corpo em `body`, `body.proposal[0]` ou raiz.

### 2.5 Upload de documentos KYC (multipart)
`POST {base}/celcoinkyc/document/v1/fileupload` — `multipart/form-data`, Bearer

Campos enviados: `documentnumber`, `filetype`, `front` (arquivo), + `cpf` **ou** `cnpj`, + `verse` (arquivo, opcional). Resposta: qualquer 200 de confirmação. Em sucesso o cliente marca `kyc_status='SENT'`.

---

## 3. Conta (AccountManager) — **prefixo `baas/v2`**

### 3.1 Alterar status ⚠️
`PUT {base}/baas/v2/account/status?Account={account_number}`
```json
{ "status": "ATIVO" | "BLOQUEADO", "reason": "Segurança" }
```
> **A V2 sempre envia `reason`** (obrigatório na assinatura interna `alterarStatusConta`). O fix de `reason` opcional do mock foi feito no path v1 `baas-accountmanager/v1/account/status` — a V2 usa **`baas/v2/account/status`**, que o mock **ainda não roteia**. Fluxo de reativação (`BLOQUEADO`→`ATIVO`) da V2 depende **desta** rota existir e devolver 2xx.

Resposta consumida: `response->body ?? response` (o cliente só precisa de 2xx; grava o novo status localmente).

### 3.2 Encerrar conta
`DELETE {base}/baas/v2/account/close?Account={account_number}&Reason={motivo}` (sem body). 2xx → status local vira `ENCERRADO`.

### 3.3 Saldo
`GET {base}/baas/v2/wallet/balance?Account={account_number}&DocumentNumber={doc}`

Resposta consumida: `body.amount` (fallback `amount`), `body.blockedAmount` (fallback `blockedAmount`). Se vier `error`, o cliente trata como falha.

### 3.4 Extrato
`GET {base}/baas/v2/wallet/movement?Account={acc}&DateFrom=YYYY-MM-DD&DateTo=YYYY-MM-DD&Page=1&LimitPerPage=50&AdditionalInformation=true&Order=desc`

### 3.5 Saldo diário / movimentação consolidada
`GET {base}/baas/v2/wallet/dayBalance?account={acc}&dateFrom=YYYY-MM-DD&dateTo=YYYY-MM-DD&ShowMovementType={true|false}&Order={asc|desc}&Page=1&LimitPerPage=20`

> **Endpoint novo, sem equivalente na v1.** Usado tanto para saldo diário quanto (com `ShowMovementType=true`) para "movimentação consolidada".

### 3.6 Transferência interna (P2P intra-Celcoin)
`POST {base}/baas/v2/wallet/internal/transfer`
```json
{
  "amount": 4.5,
  "clientRequestId": "<uuid>",
  "debitParty":  { "account": "443168489" },
  "creditParty": { "account": "443168490" },
  "description": "Transferência interna"
}
```
Resposta: `response->body ?? response` (o `clientRequestId` é gerado pelo cliente e ecoado no retorno dele).

Status: `GET {base}/baas/v2/wallet/internal/transfer/status?Id={id}` (ou `?ClientRequestId=` / `?EndToEndId=`).

### 3.7 Adicionar saldo (sandbox)
`POST {base}/baas/v2/wallet/entry/{account_number}`
```json
{ "clientCode":"<uuid>", "amount": 20, "type":"CREDIT", "description":"Deposito sandbox" }
```

---

## 4. Pix — **prefixo `baas/v2` para DICT/pagamento/reverse/claim**

### 4.1 Pagamento Pix (cash-out)
`POST {base}/baas/v2/pix/payment`
```json
{
  "amount": 1.00,
  "paymentType": "IMMEDIATE",
  "urgency": "HIGH",
  "transactionType": "TRANSFER",
  "clientCode": "0000123",
  "initiationType": "DICT|MANUAL|STATIC_QRCODE|DYNAMIC_QRCODE",
  "debitParty":  { "account": "443168489" },
  "creditParty": { "bank":"30306294","key":"...","account":"...","branch":"...",
                   "taxId":"...","name":"...","accountType":"CACC" },
  "remittanceInformation": "Texto",
  "endToEndId": "E30306294...",              // opcional
  "transactionIdentification": "..."          // opcional
}
```
> **Idempotência igual à v1:** `clientCode = str_pad(mov_pix_id, 7, '0')` — o `mov_pix_id` é auto-increment, nunca reverte. Reenvio do mesmo `clientCode` deve **replicar** a transação original (mesmo `transactionId`/`endToEndId`). Vale o mesmo `pixPaymentReplay` do mock; o alias `baas/v2/pix/payment` já aponta para `api/payment-baas`, então **este endpoint já está coberto**.

Resposta consumida: `body.id` (fallback `body.transactionId`), `body.endToEndId`.

### 4.2 Status do pagamento
`GET {base}/baas/v2/pix/payment/status?id={transactionId}` — **já coberto** (`api/pix-payment-status-baas`).

### 4.3 DICT — consultar chave
`GET {base}/baas/v2/pix/dict/entry/external/{account_number}?key={chave}` — **já coberto** (alias `api/key`).

Resposta consumida: `account.participant`, `account.account`/`account.accountNumber`, `account.branch`, `account.accountType`, `owner.documentNumber`/`owner.taxIdNumber`, `owner.name`, `endtoEndId`/`endToEndId`. (O cash-out faz auto-DICT: se `initiationType` ∈ DICT/QRCODE e só tem `key`, ele consulta este endpoint e preenche o `creditParty` antes de pagar.)

### 4.4 DICT — criar / excluir / listar ⚠️ (rotas novas em `baas/v2`)
| Método | Path | Body |
|---|---|---|
| `POST` | `baas/v2/pix/dict/entry` | `{ "account","keyType":"EVP\|CPF\|CNPJ\|EMAIL\|PHONE","key" }` |
| `DELETE` | `baas/v2/pix/dict/entry/{key}` | `{ "account":"..." }` |
| `GET` | `baas/v2/pix/dict/entry/{account_number}` | — (lista chaves da conta) |

Criar consome `body.key` (valor efetivo da chave). O mock **tem** essas operações no path v1 `celcoin-baas-pix-dict-webservice/v1/...` — falta o **alias `baas/v2`**.

### 4.5 DICT — OTP ⚠️ (produto novo, sem equivalente v1)
| Método | Path | Body |
|---|---|---|
| `POST` | `baas/v2/pix/dict/entry/otp` | `{ "account","keyType","key" }` |
| `POST` | `baas/v2/pix/dict/entry/confirm` | `{ "account","keyType","key","confirmationCode" }` |

Fluxo de posse de chave (EMAIL/PHONE): pede OTP → confirma com o código. Resposta: qualquer 2xx com corpo.

### 4.6 Devolução (reverse) ⚠️
`POST {base}/baas/v2/pix/reverse`
```json
{ "id":"<transactionId>", "amount": 10.0, "reason":"...", "clientCode":"<uuid>" }
```

### 4.7 Claims / Portabilidade ⚠️ (rotas novas em `baas/v2`)
| Método | Path | Body |
|---|---|---|
| `POST` | `baas/v2/pix/dict/claim` | `{ "key","keyType","account","claimType":"PORTABILITY\|OWNERSHIP" }` |
| `POST` | `baas/v2/pix/dict/claim/confirm` | `{ "id":"<claimId>","reason":"USER_REQUESTED" }` |
| `POST` | `baas/v2/pix/dict/claim/cancel` | `{ "id":"<claimId>","reason":"FRAUD" }` |
| `GET` | `baas/v2/pix/dict/claim/{claimId}` | — |
| `GET` | `baas/v2/pix/dict/claim/list` | query livre |

Mock tem a lógica no path v1 — falta o alias `baas/v2`.

### 4.8 QR Code — **path `pix/v1`, já coberto**
- `POST {base}/pix/v1/brcode/static` — `Content-Type: application/json-patch+json`
  Body: `{ "key","amount":"10.00","merchant":{"merchantCategoryCode":"0000","postalCode","city","name"},"additionalInformation"? }`
  Consome: `transactionId`, `emvqrcps`/`emv`/`textContent`, `url`, `transactionIdentification`.
- `POST {base}/pix/v1/brcode/dynamic` — mesmo header
  Body: `{ "key","amount":"10.00","merchant":{...},"expiration":3600,"clientRequestId":"<uuid>","additionalInformation"? }`
  Consome: `transactionId`, `body.dynamicBRCodeData.emvqrcps`, `body.dynamicBRCodeData.merchantAccountInformation.url`, `transactionIdentification`.

### 4.9 Decodificar EMV ⚠️
`POST {base}/pix/v1/emv/full` — body `{ "emv":"..." }`. Mock tem só `pix/v1/emv` (sem `/full`).

### 4.10 Status de recebimento (cash-in) ⚠️
`GET {base}/pix/v2/receivement/v2/status?transactionId={id}`
> Literal no código tem o segmento `v2` **duplicado** (`.../receivement/v2/status`). Documentado verbatim; provável typo do lado deles, mas o mock precisa casar o que é efetivamente chamado. Vale confirmar com os devs se corrigem para `pix/v2/receivement/status`.

---

## 5. SPB / TED — **path `...-webservice/v1` (igual à v1)**

`POST {base}/baas-wallet-transactions-webservice/v1/spb/transfer` — **já coberto**.
```json
{
  "amount": 100.0,
  "clientCode": "T00000123",
  "debitParty": { "account": "443168489" },
  "creditParty": { "bank":"<ispb>","account":"12345-6","branch":"0001","taxId":"...",
                   "name":"...","accountType":"CACC","personType":"F|J" },
  "clientFinality": "00001",
  "description": "..."
}
```
`clientCode = 'T' + str_pad(transferencia_id, 8, '0')`. `clientFinality`: finalidade mapeada (`1→00001, 2→00003, 4→00005, 5→00006, 6→00002, 7→00004`, default `99999`).
Consome: `body.id` (fallback `body.transactionId`).

Status: `GET {base}/baas-wallet-transactions-webservice/v1/spb/transfer?clienteCode={code}` (sic, "clienteCode").

---

## 6. Boletos

### 6.1 Emissão de cobrança — **path `api-integration-baas-webservice/v1` (igual à v1)**, já coberto
`POST {base}/api-integration-baas-webservice/v1/charge`
```json
{
  "externalId":"0000000123","expirationAfterPayment":30,"duedate":"YYYY-MM-DD",
  "amount":50.00,"key":"<chave_pix>",
  "debtor":{ "name","document","postalCode","publicArea","number","complement","neighborhood","city","state" },
  "receiver":{ "document":"...","account":"443168489" },
  "instructions":{ "fine":2.0, "interest":1.0, "discount":{ "amount":1.5,"modality":"fixed","limitDate":"YYYY-MM-DD" } }
}
```
Consome: `body.transactionId`.

### 6.2 Consultar cobrança — **`baas/v2/charge`**, já coberto (GET)
`GET {base}/baas/v2/charge?transactionId={id}`
Consome (enriquecimento): `boleto.bankLine`, `boleto.bankAgency`, `boleto.bankAccount`, `boleto.transactionId`, `transactionId`, `externalId`, EMV do Pix (`emv`/`pixCopiaECola`/`emvqrcps`/`pix.*`/`body.*`).

### 6.3 PDF do boleto ⚠️ (novo, sem equivalente v1)
`GET {base}/baas/v2/charge/pdf/{id}` — resposta pode ser **PDF binário**, JSON `{pdf|pdfBase64|base64}` (aceita em `body`/`data` também) ou JSON `{url|pdfUrl}`. O cliente tenta vários ids candidatos e para no primeiro que devolver PDF. Aceitar 404/não-PDF sem quebrar.
> Atenção ao roteamento: `baas/v2/charge/pdf/{id}` vs `baas/v2/charge/{txid}` (cancelar). O mock precisa priorizar o segmento literal `pdf/` para não casar `{txid}="pdf"`.

### 6.4 Cancelar cobrança — **`baas/v2/charge/{txid}`**, já coberto
`DELETE {base}/baas/v2/charge/{txid}` — body `{ "reason":"..." }`.

### 6.5 Pagamento de conta (billpayment)
- Autorizar (CIP): `POST {base}/v5/transactions/billpayments/authorize` — **já coberto**
  ```json
  { "barCode": { "type": 1, "digitable": "<linha_digitavel>" } }   // type: NPC→1, TAXES→2
  ```
  Consome: `assignor` (ou `body.assignor`) para saber que achou; senão `body.message`/`message`. O corpo real (valores, `registerData`, `transactionId`) é repassado adiante.
- Pagar: `POST {base}/baas/v2/billpayment` — **já coberto**
  ```json
  { "clientRequestId":"<pagamento_id>","amount":1763.66,"account":"443168489",
    "transactionIdAuthorize":5283988433,"barCodeInfo":{"digitable":"..."} }
  ```
  `clientRequestId` = id numérico do `Pagamento` local. Consome: `body.id`, `body.transactionIdAuthorize`/`body.transactionId`.
- Status: `GET {base}/baas/v2/billpayment/status?ClientRequestId={id}[&Id={celcoin_id}]` — **já coberto**.

---

## 7. Recarga (Topup) — **produto novo, 100% em `v5`, mock não tem nada**

| Método | Path | Body / Query |
|---|---|---|
| `GET` | `v5/transactions/topups/providers` | `?stateCode=&type=&category=` |
| `GET` | `v5/transactions/topups/provider-values` | `?providerId=` |
| `POST` | `v5/transactions/topups` | ver body abaixo |
| `PUT` | `v5/transactions/topups/{transactionId}/capture` | `{ "externalNSU","externalTerminal" }` |
| `GET` | `v5/transactions/topups/status-consult` | `?transactionId=` |

Reservar:
```json
{
  "externalTerminal":"<10 dígitos>","externalNsu":<int>,
  "topupData":{ "value": 20.0 },
  "cpfCnpj":"...","signerCode":"<7 dígitos>","providerId":123,
  "phone":{ "stateCode":11,"countryCode":55,"number":999999999 }
}
```
Reservar consome: `body.transactionId`. Confirmar/status: qualquer 2xx.

---

## 8. Webhook Manager — **`baas/v2/webhook/*` (rotas novas)**

### 8.1 Subscription
| Método | Path | Uso |
|---|---|---|
| `GET` | `baas/v2/webhook/subscription?Entity={e}&Active=true` | consultar/listar por entity |
| `POST` | `baas/v2/webhook/subscription` | cadastrar |
| `PUT` | `baas/v2/webhook/subscription/{entity}` | atualizar / desativar |

Body cadastro/atualização:
```json
{
  "entity":"pix-payment-in",
  "webhookUrl":"https://<sub>.e-bancos.com.br/index.php?r=<rota>",
  "auth":{ "type":"basic","login":"<LOGIN_WEBHOOK_CELCOIN>","pwd":"<PWD_WEBHOOK_CELCOIN>" },
  "active": true,
  "subscriptionId":"<quando atualiza>"
}
```
O cliente **lista consultando cada entity** (um GET por entity de `tipos()`). Resposta esperada: array, ou `{items:[...]}`, ou `{subscriptions:[...]}`, ou objeto único com `entity`/`webhookUrl`/`subscriptionId`. Campos lidos por item: `entity`/`Entity`, `webhookUrl`/`url`, `subscriptionId`/`id`, `active`/`isEnabled`, `createdAt`.

### 8.2 Replay ⚠️ (produto novo)
| Método | Path | Uso |
|---|---|---|
| `GET` | `baas/v2/webhook/replay/{entity}` | quantidade pendente |
| `GET` | `baas/v2/webhook/replay/{entity}/details` | detalhes (paginado) |
| `PUT` | `baas/v2/webhook/replay/{entity}` | reenviar; body `{ "filter": { "documentNumber"?,"account"?,"id"?,"clientRequestId"? } }` |

Query aceita: `DateFrom`, `DateTo`, `OnlyPending`, `webhookId`, `documentNumber`, `account`, `id`, `clientRequestId` (+ `Page`/`Limit`/`LimitPerPage` no details).

---

## 9. Webhooks **emitidos para o consumidor** (V2)

Endpoint receptor: `controllers/webhook/CelcoinController::actionHandle`. Comportamento:
- Valida IP de origem; **responde 200 imediatamente** (`respondOk`) e processa depois.
- Idempotência por `(entity, externalId, status)`. `externalId` = primeiro de: `body.clientRequestId`, `body.transactionId`, `body.proposalId`, `body.onboardingId`, `body.id` (fallback md5 do payload).
- Envelope: `{ "entity","status","body":{...}, "error"?:{...} }` — mesma forma da v1.

### 9.1 Entities roteadas (mapa `$entityHandlers`)
`onboarding-create`, `kyc`, `onboarding-backgroundcheck`, `onboarding-documentscopy`, `onboarding-file`, `onboarding-proposal`, `account-status`, `pix-payment-out`, `pix-payment-in`, `pix-dict-claim-{open,waiting,confirmed,cancelled,completed}`, `internal-transfer-{out,in}`, `spb-transfer-{out,in}`, `spb-reversal-in`, `billpayment`, `billpayment-occurrence`, `charge-create`, `charge-canceled`, `charge-in`, `topup`.

> `pix-dict-claim-*`, `internal-transfer-*` e `topup` são apenas logados (sem persistência).
>
> ⚠️ **Desatualizado em 10/08/2026 quanto à devolução Pix.** Esta nota dizia que `pix-reversal-out`/`pix-reversal-in` eram assináveis mas **sem handler** — verdade até o LGR-004. Conferido hoje na `feat/celcoin-blaster`: `'pix-reversal-in' => 'handlePixReversalIn'` e `'pix-reversal-out' => 'handlePixReversalOut'` estão no mapa, com `PixService::processPixReversalIn/Out`. **Ainda não está na `dev`** — a branch estava 27 commits à frente quando isto foi medido. Campos consumidos em §9.2.

### 9.2 Bodies por entity (campos consumidos)

- **`pix-payment-out`** (`PixService::processPixPaymentOut`): `body.clientCode` (→ `mov_pix_id` = `ltrim(clientCode,'0')`), `body.id`/`transactionId`, `body.endToEndId`, `status` (`CONFIRMED`→confirma+tarifa; `ERROR`/`REJECTED`→estorna).
- **`pix-payment-in`** (`processPixPaymentIn`): `body.endToEndId`, `body.amount`, `body.id`, `body.creditParty.{key,account,taxId}` (resolve conta destino), `body.debitParty.{taxId,name,accountType,bank,branch,account}`, `body.transactionIdentification`, `body.transactionIdBRCode`.
- **`spb-transfer-out`** (`TedService::processTedOut`): `body.originalId`/`body.id` (casa `external_id`), `status`.
- **`spb-transfer-in`**: `body.id`/`originalId` + payload repassado a `Transferencia::receberTedCelcoin`.
- **`spb-reversal-in`**: `body.originalId`/`body.id`.
- **`pix-reversal-in`** (`PixService::processPixReversalIn`, LGR-004): chave de idempotência = `body.returnIdentification` → `body.endToEndId` → `body.id`; `body.amount` (>0, senão descarta); `body.creditParty` resolve a conta que recebe de volta (por `account` → `key` → `taxId`); `body.debitParty` para nome da contraparte. **Nunca `originalEndToEndId`**: ele é o E2E do envio original, e reusá-lo como chave acha a `mov_pix` do envio e conclui "já lançado" para sempre (família do LGR-007). Não cobra tarifa. ⚠️ O payload **não traz `endToEndId`** — é por isso que `celcoin_v2_webhook_events.end_to_end_id` fica NULL nesses eventos.
- **`pix-reversal-out`** (`processPixReversalOut`): simétrico, mas a conta nossa é a do `body.debitParty`, e a resolução é **estrita** (não resolveu → nada é debitado, evento fica pendente). Usa `originalPaymentId`/`clientCode`, tem `currentBalance`+`oldBalance` e `additionalInformation`; **não** repete o typo `originalEntoEndId`.
- **`onboarding-create` / `-backgroundcheck` / `-documentscopy` / `-proposal`**: `status`, `body.proposalId`, `body.onboardingId`, `body.clientCode`, `body.account.account`/`body.account` (escalar), `body.account.branch`/`body.branch`, `body.urlDocumentscopy`, `body.RejectedReason` (array, em REPROVED/REJECTED).
- **`onboarding-file`**: `body.proposalId`, `body.files[]` = `{ type, url }`. `type` ∈ `CNH_FRONT/RG_FRONT/RNE_FRONT/CNH_BACK/RG_BACK/RNE_BACK/SELFIE` (PF) + `CONTRATO_SOCIAL/PROCURACAO_PODERES` (PJ). O consumidor baixa a `url`.
- **`kyc`**: `status` (→ `kyc_status`), `body.onboardingId`/`clientCode`/`proposalId`.
- **`account-status`**: `status`, `body.clientCode`/`onboardingId`/`account`, `body.reason`/`statusReason`/`Reason`.
- **`billpayment` / `billpayment-occurrence`**: `body.clientRequestId` (id local do Pagamento), `status`/`body.status`, `body.occurrenceStatus` (`DEVOLUTION`→estorna). CONFIRMED/SUCCESS/COMPLETED→confirma; ERROR/REJECTED/CANCELLED→estorna.
- **`charge-create`**: `body.transactionId`, `body.boleto.{bankLine,bankAgency,bankAccount,status}` (`status=PENDING`).
- **`charge-in`**: `body.transactionId`, `body.valorPago`/`body.amount`.
- **`charge-canceled`**: `body.transactionId`.

---

## 10. Códigos de erro observados pela V2

Tabela **observada em log real** (contagem = ocorrências no `celcoin_prod.log`; ✓ = errorCode + shape confirmados em produção). Severidade vem do mapa `CelcoinErrorCodes` do consumidor (`info` = fluxo normal, nem alarma).

| Código | HTTP | Onde ocorre (real) | Sev. | Cnt |
|---|---|---|---|---|
| `CBE217` | 404 | `GET baas/v2/webhook/subscription` sem inscrição | info | 428 |
| `CSE002` | 400 | `GET baas/v2/charge` id inexistente (version **1.1.0**) | info | 122 |
| `OBE055` | 404 | `GET onboarding/v1/onboarding-proposal/files` sem arquivo | info | 96 |
| `CBE180` | 404 | `GET baas/v2/pix/dict/entry/external` chave não encontrada | info | 59 |
| `CBE410` | 400 | operação DICT/pix rejeitada sem motivo | error | 25 |
| `OBE062` | 400 | `POST onboarding-proposal/*` clientCode duplicado | error | 7 |
| `CBE173` | 400 | `POST baas/v2/pix/dict/entry` keyType inválido | error | 6 |
| `CBE175` | 400 | `POST baas/v2/pix/dict/entry` formato de chave inválido | error | 4 |
| `CBE032` | 404 | `GET baas-onboarding/v1/account/check` onboarding não encontrado | info | 3 |
| `CIE999` | 500 | erro interno Celcoin (genérico) | error | 3 |
| `OBE028` | 400 | `POST .../legal-person` tradingName obrigatório | error | 2 |
| `IVBE001` | 400 | `POST .../charge` request fora do padrão (version 1.1.0) | error | 2 |
| `CBE197` | 400 | `POST baas/v2/pix/dict/entry` tipo de chave não permitido | error | 2 |
| `CBE027` | 400 | `POST .../charge` CEP ≠ 8 dígitos (version 1.1.0) | error | 2 |
| `CBE030` | 404 | `GET baas/v2/wallet/balance` conta não encontrada | info | 1 |
| `PBE150`,`OBE064`,`OBE033`,`CBE354`,`CBE238`,`CBE236` | — | onboarding/pix diversos | vários | 1–2 |

Formato: `{ "status":"ERROR", "error":{ "errorCode":"<COD>","message":"..." }, "version":"1.0.0" }` (charge usa `1.1.0`). **Exceção:** 404 de `account/status` vem cru: `{ "statusCode":404, "message":"Resource not found" }`. Mensagens reais usam espaço não-quebrável ` ` — reproduzir para fidelidade.

**Códigos adicionais observados em sustentação (multi-tenant — confiapay/bcbr/totalis).** Fonte: `sustenance/confiapay/2026/2026-07-10-briefing-erros-celcoinv2.md` §2 (extraído de `celcoinv2/http/{response,error}` de produção). Úteis como cenários controlados do mock:

| Código / condição | Onde ocorre | Observação |
|---|---|---|
| `CBE410` / HTTP 422 "marcação de fraude" | criar chave, DICT consultar, pix out | CPF/CNPJ com restrição de fraude no DICT/BC — bloqueia chave e Pix de saída |
| `CBE171` | pix (fraude, tratado no v1) | marcação de fraude (equivalente v1 do 422) |
| `CBE236` | `POST pix/dict/entry` | chave já cadastrada em outro PSP → oferecer portabilidade (claim) |
| `CBE228` | `POST pix/dict/entry` | já existe registro da chave nesta conta |
| `CBE051` | consulta de chave | chave não localizada para a conta |
| `CBE136` | `POST pix/payment` STATIC_QRCODE | `transactionIdentification` > 25 chars → rejeita (validar antes de debitar) |
| `CBE123` | `POST pix/payment` | saldo insuficiente |
| `CBE100` | `POST pix/payment` | lançamento idêntico pendente (anti-duplicidade) |
| `CBE220` / `CBE312` | pix / transferência | Pix/TEF para a mesma conta |
| `CBE040` | onboarding | documentNumber inválido |
| `OBE019` / `OBE011` | onboarding | campo obrigatório/inválido |
| condição "key > 77 chars" | `dict/consultar` | é um copia-e-cola EMV (começa com `000201`), não uma chave |
| `PCE050` | `baas/v2/billpayment` (status/webhook) | "Boleto nao permite alterar valor." |
| `PCE088` | `baas/v2/billpayment` (webhook) | "Excede limite de saldo." |

> **Cuidado — nem todo erro V2 tem `errorCode` estruturado.** Vários fluxos (internal transfer, pix status, reverse, account/status-consulta) devolvem **texto HTTP puro** sem `error.errorCode`: ex. `CelcoinV2 HTTP 400: Transação não permitida. Conta com saldo insuficiente.`, `...Existe um lançamento idêntico pendente...`, `...Não é permitido enviar TEF para a mesma conta.`, `HTTP 404: Não encontramos nenhuma transação...`, `HTTP 404: Resource not found`. O mock deve reproduzir esse formato "mensagem crua" nesses fluxos, não forçar um errorCode.
>
> Como cenário de mock: além do catálogo magic-cents/keyword existente, dá pra mapear alguns desses a gatilhos controlados (ex.: chave/documento sentinela → `CBE410` fraude; txid > 25 → `CBE136`; boleto sentinela → `PCE050`/`PCE088`). Ver [[celcoin-scenario-amount-gotcha]].

---

## 11. Mapa de implementação no mock (o gap concreto)

Legenda: ✅ já responde · 🔁 operação existe no mock em outro path (falta **alias `baas/v2`**) · 🆕 produto novo (mock não tem nada).

> **Status (2026-07-16): implementado e alinhado aos shapes reais.** Os streams que servem v1 e V2 ao mesmo tempo ramificam por path (`Cslabs::isV2()`): o builder devolve o shape v1/plano e o envelope V2 entra no stream — porque os consumidores v1 leem caminhos FIXOS no topo, sem o `?? $response` defensivo da V2 (ver §0). Verificado por `tests/celcoinv2_paths_smoke.php`, que exercita os dois lados de cada branch por HTTP real.
>
> **Status (2026-07-15): implementado.** Os 🔁 (aliases `baas/v2/*`) e os 🆕 (dayBalance, charge/pdf, receivement, webhook replay, topups, DICT OTP) já estão no mock — ver `WORK.md` e `tests/celcoinv2_routing_smoke.php`. dayBalance usa o shape real do Apêndice B; topups/receivement/OTP são plausíveis (nenhum tenant exercitou em log) e marcados como inferidos nos streams.

### Já cobertos (nenhuma ação)
`POST /v5/token` · `onboarding/v1/onboarding-proposal/{natural-person,legal-person}` · `GET onboarding/v1/onboarding-proposal` · `GET baas-onboarding/v1/account/check` · `POST celcoinkyc/document/v1/fileupload` · `POST/GET baas-wallet-transactions-webservice/v1/spb/transfer` · `POST baas/v2/pix/payment` (+`/status`) · `GET baas/v2/pix/dict/entry/external/{acc}` · `pix/v1/brcode/{static,dynamic}` · `GET baas/v2/wallet/movement` · `POST api-integration-baas-webservice/v1/charge` · `GET baas/v2/charge` · `DELETE baas/v2/charge/{txid}` · `POST baas/v2/billpayment` (+`/status`) · `POST v5/transactions/billpayments/authorize`.

### 🔁 Falta alias `baas/v2` (lógica já existe no path v1)
| V2 chama | Reusar handler do mock |
|---|---|
| `PUT baas/v2/account/status` | `api/account-status-update` |
| `DELETE baas/v2/account/close` | `api/account-close` |
| `GET baas/v2/wallet/balance` | `api/wallet-balance` |
| `POST baas/v2/wallet/entry/{acc}` | `api/wallet-entry` |
| `POST baas/v2/wallet/internal/transfer` (+`/status`) | `api/internal-transfer(-status)` |
| `POST baas/v2/pix/reverse` | `api/pix-reverse-baas` |
| `POST baas/v2/pix/dict/entry` | `api/dict-entry-create` |
| `DELETE baas/v2/pix/dict/entry/{key}` | `api/dict-entry-delete` |
| `GET baas/v2/pix/dict/entry/{acc}` | `api/dict-entry-list` |
| `POST baas/v2/pix/dict/claim[/confirm\|/cancel]` | `api/dict-claim` |
| `GET baas/v2/pix/dict/claim/{id}` | `api/dict-claim-router` |
| `GET baas/v2/pix/dict/claim/list` | `api/dict-claim-list` |
| `GET/POST baas/v2/webhook/subscription[/{entity}]` | `api/webhook-subscription` |

> Atenção a colisões de rota ao adicionar os aliases: `baas/v2/pix/dict/entry/{key}` (DELETE) vs `baas/v2/pix/dict/entry/external/{account}` (GET) vs `baas/v2/pix/dict/entry/otp|confirm` (POST) — desambiguar por método + segmento literal.

### 🆕 Produto novo (implementar do zero, se entrar no escopo)
- `GET baas/v2/wallet/dayBalance`
- `POST baas/v2/pix/dict/entry/otp` + `POST .../confirm`
- `POST pix/v1/emv/full` (ou alias de `api/emv`)
- `GET pix/v2/receivement/v2/status` (confirmar grafia com os devs)
- `GET baas/v2/charge/pdf/{id}`
- `GET/PUT baas/v2/webhook/replay/{entity}[/details]`
- Recarga: `v5/transactions/topups/{providers,provider-values,(POST),{id}/capture,status-consult}`

---

## 12. Variáveis de ambiente novas (V2)

| Variável | Uso |
|---|---|
| `CELCOIN_V2_URL` | base URL da V2 (se ausente, cai em `CELCOIN_URL`) — **apontar para o mock** |
| `CELCOIN_V2_CLIENT_ID` / `CELCOIN_V2_CLIENT_SECRET` | credenciais V2 (fallback nas v1) |
| `LOGIN_WEBHOOK_CELCOIN` / `PWD_WEBHOOK_CELCOIN` | basic auth dos webhooks (compartilhado com v1) |

---

## 13. Itens a confirmar com os devs do consumidor

1. **`CELCOIN_V2_URL` aponta para o mock?** Sem isso a V2 não passa pelo homologador.
2. **`pix/v2/receivement/v2/status`** — o `v2` duplicado é intencional ou typo? O mock precisa casar o literal chamado.
3. **`account/status` da V2** usa `baas/v2/account/status` (a v1 usa `baas-accountmanager/v1`). Nos logs, a V2 **nunca** chamou esse endpoint — só apareceu o `PUT baas-accountmanager/v1/account/status` da v1 (contra o mock). Ou seja, o fluxo de reativação reportado no briefing veio pela **v1**, então o fix de `reason` opcional está no path certo. Confirmar se a V2 vai passar a usar o dela.
4. **Escopo:** dos 🆕 (topup, replay, OTP, dayBalance, PDF, receivement), quais realmente serão homologados agora?

---

## 14. Apêndice A — Shapes reais de produção (verificados em log)

Extraído de `runtime/logs/celcoin_prod.log` (tráfego real `api.openfinance.celcoin.com.br`, jun/2026). Documentos/CPFs parcialmente mascarados; base64 truncado.

### Onboarding

**`POST /onboarding/v1/onboarding-proposal/natural-person`** → resposta enxuta (só ecoa ids; conta/status vêm depois por webhook):
```json
{ "body": { "proposalId":"c1c0fb14-753e-4396-964d-b7c04a933c07",
            "clientCode":"BO-CV2-5-1780340735", "documentNumber":"34355747808" },
  "version":"1.0.0", "status":"PROCESSING" }
```
**`POST .../legal-person`** → **mesmo shape** (proposalId/clientCode/documentNumber).

**`GET /onboarding/v1/onboarding-proposal`** → lista paginada:
```json
{ "body": { "limit":200,"currentPage":1,"limitPerPage":200,"totalPages":1,"totalItems":1,
    "proposal":[ { "proposalId":"c1c0fb14-...","clientCode":"BO-CV2-5-1780340735",
      "documentNumber":"34355747808","status":"PENDING_DOCUMENTSCOPY","proposalType":"PF",
      "createdAt":"2026-06-01T16:05:53.987Z","updatedAt":"2026-06-01T16:06:53.006Z",
      "documentscopys":[ { "proposalId":"c1c0fb14-...","documentNumber":"34355747808",
        "documentscopyId":"6a1dd84c67dec900023e32a8","status":"PENDING",
        "url":"https://confiacapital.cadastro.io/aa685bf7...","createdAt":"...","updateAt":"..." } ] } ] },
  "version":"1.0.0","status":"SUCCESS" }
```
`proposal.status` visto: `RESOURCE_CREATED`, `PENDING_DOCUMENTSCOPY`, `PROCESSING_DOCUMENTSCOPY`, `APPROVED`, `REPROVED`. (Note o typo real `updateAt` sem "d" dentro de `documentscopys`.)

**🆕 `GET /onboarding/v1/onboarding-proposal/files`** (não estava no doc — 179 hits; é como o consumidor **puxa** as imagens KYC, além do push via webhook `onboarding-file`):
```json
{ "body": { "files":[
    { "type":"CNH_FRONT","url":"https://prdonboardingexterno.blob.core.windows.net/...&sig=...","expirationTime":"2026-06-16T10:25:21Z" },
    { "type":"SELFIE","url":"https://...","expirationTime":"..." } ] },
  "status":"SUCCESS","version":"1.0.0" }
```
URLs são SAS do Azure Blob, com expiração. Sem arquivos → `OBE055` (404). `account/check` success **não** apareceu em log (só 404 `CBE032`) — mantém inferido.

### Pix DICT

**`POST /baas/v2/pix/dict/entry`** (criar) → `status:"CONFIRMED"`:
```json
{ "status":"CONFIRMED",
  "body": { "keyType":"EVP","key":"03be238f-5496-4c61-a519-cba923d51b2c",
    "account":{ "participant":"13935893","branch":"0001","account":"494635683","accountType":"TRAN","createDate":"2026-06-03T10:32:11Z" },
    "owner":{ "type":"NATURAL_PERSON","documentNumber":"44868891855","name":"Karina Marques Pizolato" } },
  "version":"1.0.0" }
```
**`GET .../entry/{acc}`** (listar) → chaves em `body.listKeys[]` (mesmo item acima). **`DELETE`** → `{ "status":"SUCCESS","version":"1.0.0" }`.

**`GET .../entry/external/{acc}`** (consultar DICT) → adiciona `endtoEndId` (com "to" minúsculo) e `isSameTaxId`:
```json
{ "status":"SUCCESS","body":{ "keyType":"CPF","key":"22906273805",
    "account":{ "participant":"43180355","branch":"0","account":"...","accountType":"..." },
    "owner":{ "type":"...","documentNumber":"...062738**","name":"LUCAS ESCRIVANI DA SILVA" },
    "endtoEndId":"E13935893202606091922ZrdgG5S3l6p","isSameTaxId":false },"version":"1.0.0" }
```

### Pix pagamento

**`POST /baas/v2/pix/payment`** → `status:"PROCESSING"`, ecoa debit/creditParty resolvidos + campos null de saque:
```json
{ "status":"PROCESSING","version":"1.0.0",
  "body":{ "id":"0953f814-4591-41aa-8e08-9d98d49d7b8c","amount":0.5,"clientCode":"0000087",
    "transactionIdentification":null,"endToEndId":"E13935893202606091945JcxwRuM9IOh",
    "initiationType":"DICT","paymentType":"IMMEDIATE","urgency":"HIGH","transactionType":"TRANSFER",
    "debitParty":{ "account":"495308132","branch":"0001","taxId":"22906273805","name":"...","accountType":"TRAN" },
    "creditParty":{ "bank":"43180355","key":"22906273805","account":"...","branch":"0","taxId":"...","name":"...","accountType":"TRAN" },
    "remittanceInformation":"","recurrencyAccept":false,"taxIdPaymentInitiator":null,
    "vlcpAmount":null,"vldnAmount":null,"withdrawalServiceProvider":null,"withdrawalAgentMode":null } }
```
Liquidação chega depois via webhook `pix-payment-out`. `GET .../payment/status` **não** apareceu em log.

### Charge (version 1.1.0)

**`POST /api-integration-baas-webservice/v1/charge`** → só o id:
```json
{ "version":"1.1.0","status":"SUCCESS","body":{ "transactionId":"3733235b-1e14-4252-8b02-8aacfe6f4efa" } }
```
**`GET /baas/v2/charge`** → cobrança completa; `boleto`/`pix` ficam `null` até emitir; `chargeType:"BOLEPIX"`:
```json
{ "version":"1.1.0","status":"SUCCESS","body":{ "transactionId":"bf8c68a4-...","externalId":"6242001476",
    "amount":10,"amountConfirmed":null,"duedate":"2026-06-30 00:00:00","status":"PROCESSING",
    "debtor":{...},"receiver":{...},"instructions":null,"boleto":null,"pix":null,"split":[],
    "informations":null,"chargeType":"BOLEPIX" } }
```
**`DELETE /baas/v2/charge/{id}`** → `status:"PROCESSING"` com `boleto`+`pix` populados:
```json
{ "version":"1.1.0","status":"PROCESSING","body":{ "transactionId":"6fa5026f-...","externalId":"4387954729",
    "amount":100,"duedate":"2026-06-18 00:00:00","debtor":{...},"receiver":{...},
    "pix":{ "transactionId":"3010002870","transactionIdentification":"kk6g...","status":"PENDING","key":"8cfad979-...","emv":"00020101...6304A07C" },
    "boleto":{ "transactionId":"6a32a706...","status":"PENDING","bankEmissor":"CELCOIN INSTITUIÇÃO DE PAGAMENTO - SA",
      "bankNumber":"11387461","bankAgency":"0001","bankAccount":"495440539","barCode":"5099214810...","bankLine":"5099000001...","bankAssignor":"CELCOIN...","invoiceNumber":null },
    "chargeType":"BOLEPIX" } }
```

### Billpayment authorize (FLAT, sem envelope)

**`POST /v5/transactions/billpayments/authorize`**:
```json
{ "assignor":"Generica FC",
  "registerData":{ "documentRecipient":"400.019.928-56","documentPayer":"234.698.618-62",
    "payDueDate":"2026-07-30T00:00:00","dueDateRegister":"2026-06-30T00:00:00","allowChangeValue":false,
    "recipient":"...","payer":"Thiago de Oliveira","discountValue":0.00,"interestValueCalculated":0.00,
    "maxValue":10.00,"minValue":10.00,"fineValueCalculated":0.00,"originalValue":10.00,"totalUpdated":10.00,
    "paymentSpecies":99,"documentFinalRecipient":"400.019.928-56","finalRecipient":"..." },
  "settleDate":"10/06/2026","dueDate":"2026-06-30T00:00:00Z","endHour":"23:00","initeHour":"07:00",
  "nextSettle":"N","digitable":"5099...001000","transactionId":5332764900,"type":2,"value":10.0,
  "errorCode":"000","message":null,"status":0 }
```
`transactionId` numérico, `errorCode:"000"`, `status:0` = sucesso. `initeHour` é typo real da Celcoin. `baas/v2/billpayment` (executar) e status **não** apareceram em log.

### Wallet balance
**`GET /baas/v2/wallet/balance`** → `{ "status":"SUCCESS","version":"1.0.0","body":{ "amount":10.45 } }`. `wallet/movement`, `wallet/dayBalance`, `internal/transfer` **sem log**.

### Webhook manager
**`POST /baas/v2/webhook/subscription`** → `{ "body":{ "subscriptionId":"6a1dd84d8e71693074e1b108" },"status":"SUCCESS","version":"1.0.0" }`
**`GET`** → `{ "body":{ "subscriptions":[ { "subscriptionId":"...","entity":"onboarding-create","webhookUrl":"https://confiapay.e-bancos.com.br/index.php?r=celcoinv2%2Fwebhook%2Fcelcoin%2Fhandle","active":true,"createDate":"...","lastUpdateDate":null,"auth":null } ] },"status":"SUCCESS","version":"1.0.0" }`. Vazio → 404 `CBE217`.
**Replay** `GET .../replay/{entity}` → `body:{ onlyPending,entity,totalItems }`; `.../details` → `body.webhookDetails[]` com `request` (JSON original **escapado como string**); `PUT` reenvio → `body:{ entity,dateFrom,dateTo,totalItems }`.

### Webhooks recebidos (bodies reais)

Envelope varia: onboarding usa `createTimestamp`, pix usa `createTimeStamp` (casing!). Quase todos têm `webhookId`.

**`onboarding-create`**:
```json
{ "entity":"onboarding-create","createTimestamp":"2026-06-01T16:54:27.2623257","status":"CONFIRMED",
  "body":{ "account":{ "branch":"0001","account":"494554082","name":"Allan Diogo Bertho","documentNumber":"34355747808","ispb":"13935893" },
    "onboardingId":"c1c0fb14-...","clientCode":"BO-CV2-5-1780340735","createDate":"2026-06-01T16:54:27.2623257" },
  "webhookId":"c1c0fb14-..." }
```
**`onboarding-backgroundcheck` / `-documentscopy` / `-proposal`** (mesmo body, muda entity/status):
```json
{ "body":{ "proposalId":"c1c0fb14-...","clientCode":"BO-CV2-5-1780340735","documentNumber":"34355747808","proposalType":"PF","onboardingType":"BAAS" },
  "createTimestamp":"2026-06-01T16:05:55Z","entity":"onboarding-backgroundcheck","status":"PENDING","webhookId":"39a93ed4-..." }
```
Status: backgroundcheck `PENDING`→`APPROVED`/`REPROVED`; documentscopy `PROCESSING`; proposal `PROCESSING_DOCUMENTSCOPY`→`APPROVED`/`REPROVED`.

**`charge-create`** (`status:"CREATED"`):
```json
{ "createTimestamp":"2026-06-03T14:14:10.0035186","entity":"charge-create","status":"CREATED",
  "body":{ "amount":50,
    "boleto":{ "transactionId":"6a2036b0...","status":"PENDING","bankLine":"...","bankNumber":"10972976","barCode":"...","bankEmissor":"CELCOIN...","bankAgency":"0001","bankAccount":"494635683","bankAssignor":"..." },
    "pix":{ "transactionId":"2994799049","transactionIdentification":"kk6g...","status":"PENDING","locationId":"286597600","key":"03be238f-...","emv":"00020101...6304214A" },
    "debtor":{...},"receiver":{...},"duedate":"2026-06-04 00:00:00","expirationAfterPayment":30,
    "instructions":{ "fine":1,"interest":15 },"tags":[ { "key":"CHARG...<trunc>" } ] } }
```
**`charge-canceled`** (`status:"CANCELED"`): mesmo shape, `body.pix.status:"CANCELED"`, `body.boleto.status:"PENDING"`.

**`pix-payment-in`** (`createTimeStamp`, `transactionType:"RECEIVEPIX"`, add `currentBalance`/`oldBalance`):
```json
{ "entity":"pix-payment-in","createTimeStamp":"2026-06-09T16:21:37.9763696","status":"CONFIRMED",
  "body":{ "id":"ff6fc63a-...","amount":1,"endToEndId":"E139...","initiationType":"DICT","paymentType":"IMMEDIATE","urgency":"HIGH","transactionType":"RECEIVEPIX",
    "debitParty":{ "bank":"13935893","account":"489132431","branch":"0001","taxId":"22906273805","name":"LUCAS ...","accountType":"TRAN" },
    "creditParty":{ "bank":"13935893","key":"a48b7866-...","account":"495308132","branch":"0001","taxId":"...","name":"...","accountType":"TRAN" },
    "remittanceInformation":"","currentBalance":1,"oldBalance":0,"transactionIdBRCode":null },
  "webhookId":"ff6fc63a-..." }
```
**`pix-payment-out`** (`transactionType:"TRANSFER"`, add `currentBalance`/`oldBalance`/`dataInsercao`, `body.clientCode` = o `str_pad(mov_pix_id,7)`):
```json
{ "entity":"pix-payment-out","createTimeStamp":"2026-06-09T16:46:19.627","status":"CONFIRMED",
  "body":{ "id":"0953f814-...","amount":0.5,"clientCode":"0000087","reason":null,"endToEndId":"E139...",
    "transactionType":"TRANSFER","debitParty":{...},"creditParty":{...},
    "currentBalance":0.5,"oldBalance":1,"dataInsercao":"2026-06-09T16:46:19.627+00:00" },
  "webhookId":"0953f814-..." }
```

### Cobertura dos logs
O Apêndice A veio de um tenant só (confiapay). O **Apêndice B** abaixo fecha a maioria das lacunas com logs **multi-tenant** (staging `homologacao3` + prod `confiapay`): pix/payment/status, pix/reverse, wallet/movement, wallet/dayBalance, internal/transfer (+status), billpayment executar (+status), e os webhooks antes sem corpo (`internal-transfer-in/out`, `billpayment`, `account-status`, `pix-dict-claim-*`, `pix-reversal-in/out`, `charge-in`).

**O que AINDA fica inferido** (nenhum tenant exercitou): **topups inteiro** (`v5/transactions/topups/*`), **SPB/TED** (`spb/transfer` + webhooks `spb-*`), `PUT baas/v2/account/status` (saída), o **success** de `account/check`/`account/status-consulta`, o **request** de `wallet/internal/transfer` (só a response foi logada), e os webhooks `kyc`, `billpayment-occurrence`, `pix-dict-claim-confirmed/completed`, `onboarding-file`. Revisar quando aparecerem em log.

---

## 15. Apêndice B — Fluxos de gap e webhooks (log multi-tenant, redigido)

Fonte: `homologacao3` (staging, exercita fluxos que a prod não usa) + `confiapay` (prod). PII mascarada. **Quirks novos confirmados:** `version` varia (`billpayment` usou `1.1.0` e `1.2.0`); `billpayment/status` request usa `ClientRequestId` (C maiúsculo); posição do `error` varia (dentro de `body` no `billpayment/status`, mas irmão de `body` no webhook `billpayment`); typos reais da Celcoin: `convenant` (billpayment) e `originalEntoEndId` (pix-reversal-in); `charge-in` vem com **body em português**.

### B.1 — Saída (outbound)

**`POST /baas/v2/billpayment`** (executar) → `PROCESSING`; `transactionIdAuthorize` vai como string e volta int:
```json
{ "body":{ "id":"4820b399-...","clientRequestId":"1078","amount":100,"transactionIdAuthorize":2150495005,
    "barCodeInfo":{ "digitable":"509900000100...<trunc>" } }, "status":"PROCESSING","version":"1.2.0" }
```
**`GET /baas/v2/billpayment/status`** (request `{ "ClientRequestId":"1063" }`) → `CONFIRMED` traz `paymentDate`; `ERROR` traz `body.error`:
```json
{ "body":{ "id":"959d7eeb-...","clientRequestId":"1063","account":41003252,"amount":50,
    "transactionIdAuthorize":2150310067,"hasOccurrence":false,"barCodeInfo":{ "digitable":"...<trunc>" },
    "paymentDate":"2026-05-13T14:26:52Z" }, "status":"CONFIRMED","version":"1.2.0" }
// ERROR: body ganha "error":{ "errorCode":"PCE050","message":"Boleto nao permite alterar valor." } e some paymentDate
```

**`GET /baas/v2/pix/payment/status`** (request `{ "transactionId":"50ecbbbe-..." }`) → `CONFIRMED`:
```json
{ "status":"CONFIRMED","version":"1.0.0","body":{ "id":"50ecbbbe-...","amount":1.5,"clientCode":"0030169",
    "endToEndId":"E139...","initiationType":"DICT","paymentType":"IMMEDIATE","urgency":"HIGH","transactionType":"TRANSFER",
    "debitParty":{ "account":"41003245","branch":"0001","taxId":"696***87","name":"<nome>","accountType":"TRAN","bank":null },
    "creditParty":{ "bank":"13935893","key":"ed354f49-...","account":"41003252","branch":"0001","taxId":"586***08","name":"<razao>","accountType":"TRAN" },
    "remittanceInformation":"" } }
```
Não encontrado → texto `HTTP 404: Não encontramos nenhuma transação através do parâmetro informado.`

**`POST /baas/v2/pix/reverse`** (request `{ "id","amount","reason":"MD06","clientCode" }`) → `PROCESSING`:
```json
{ "status":"PROCESSING","version":"1.0.0","body":{ "id":"b3531874-...","amount":1,"clientCode":"1dbc6ea9-...",
    "originalPaymentId":"90fdecca-...","endToEndId":"E139...","returnIdentification":"D139...","reason":"MD06","reversalDescription":null } }
```

**`POST /baas/v2/wallet/internal/transfer`** → `PROCESSING` (⚠️ o **request** não é logado; body inferido na §3.6). Status via `GET .../status` → `CONFIRMED`:
```json
{ "status":"PROCESSING","version":"1.0.0","body":{ "id":"eb3135a0-...","amount":0.5,"clientRequestId":"b9f5cfaf-...",
    "endToEndId":"927918e9-...","debitParty":{ "account":"41003245","taxId":"696***87","name":"<nome>","branch":"0001","bank":"13935893" },
    "creditParty":{ "account":"41003252","taxId":"586***08","name":"<razao>","branch":"0001","bank":"13935893" },"description":"Transferncia interna" } }
```
`endToEndId` interno é UUID (não formato E2E do Pix). Erros são **texto** sem errorCode: saldo insuficiente / lançamento idêntico pendente / mesma conta / conta bloqueada.

**`GET /baas/v2/wallet/dayBalance`** → envelope paginado com `body.balances[]`:
```json
{ "status":"SUCCESS","version":"1.0.0","totalItems":1,"currentPage":1,"limitPerPage":20,"totalPages":1,
  "dateFrom":"2026-05-12T00:00:00","dateTo":"2026-05-12T23:59:59.9999999",
  "body":{ "account":"41003245","documentNumber":"696***87","currentBalance":15.5,
    "balances":[ { "date":"2026-05-12","balance":97.5,"totalMovement":0,"totalMovementDebit":0,"totalMovementCredit":0,"qtdMovement":0,"qtdMovementDebit":0,"qtdMovementCredit":0 } ] } }
```
**`GET /baas/v2/wallet/movement`** → mesmo envelope, `body.movements[]`:
```json
{ "status":"SUCCESS","version":"1.0.0","totalItems":7,"currentPage":1,"limitPerPage":50,"totalPages":1,
  "dateFrom":"...","dateTo":"...","body":{ "account":"41003245","documentNumber":"696***87",
    "movements":[ { "id":"b80cbe18-...","clientCode":"0030174","description":"pix","createDate":"2026-05-13T21:37:13","lastUpdateDate":"...",
      "amount":80,"status":"Saldo Liberado","balanceType":"DEBIT","movementType":"PIXPAYMENTOUT",
      "additionalInformation":{ "nameCredit":"<razao>","nameDebit":"<nome>","oldBalance":95.5,"currentBalance":15.5 } } ] } }
```
Enums: `balanceType` DEBIT/CREDIT; `movementType` PIXPAYMENTOUT/PIXREVERSALIN/…; `status="Saldo Liberado"` (texto). Em reversões `clientCode`/`description` = null. Janela > 7 dias → erro texto.

### B.2 — Webhooks (bodies reais que faltavam)

Envelope `{entity, createTimestamp, status, body, webhookId}`. (Casing `createTimestamp` nesses; `createTimeStamp` só em pix-payment-in/out.)

**`internal-transfer-out`** (tem `clientRequestId`) / **`internal-transfer-in`** (NÃO tem) — `oldBalance`/`currentBalance` do lado respectivo; `description` ex. "Tarifa: Pix":
```json
{ "entity":"internal-transfer-out","createTimestamp":"2026-07-02T17:27:51.358","status":"CONFIRMED",
  "body":{ "id":"9d0d8eb4-...","amount":1,"clientRequestId":"87d2e433-...",
    "creditParty":{ "account":"497592253","taxId":"602***20","name":"<razao>","branch":"0001","bank":"13935893" },
    "debitParty":{ "account":"494554082","taxId":"343***08","name":"<nome>","branch":"0001","bank":"13935893" },
    "endToEndId":"eda99feb-...","description":"Tarifa: Pix","oldBalance":7.04,"currentBalance":6.04 },
  "webhookId":"9d0d8eb4-..." }
```

**`billpayment`** — `CONFIRMED` traz recibo rico; `ERROR` põe `error` **irmão de `body`**:
```json
{ "entity":"billpayment","createTimestamp":"2026-06-26T10:50:01.067","status":"CONFIRMED",
  "body":{ "oldBalance":9288.5,"currentBalance":9188.5,"account":"41003252","amount":100,
    "barCodeInfo":{ "type":2,"digitable":"...<trunc>" },"clientRequestId":"1078","id":"4820b399-...","tags":[],
    "transactionIdAuthorize":2150495005,"authentication":998,
    "authenticationAPI":{ "bloco1":"95.18.28.EE...<trunc>","bloco2":"...","blocoCompleto":"..." },
    "convenant":"655 | BANCO VOTORANTIN / AGENCIA 0001","createDate":"2026-06-26T10:49:58","isExpired":false,
    "receipt":{ "receiptData":"","receiptformatted":"...<trunc>" },"settleDate":"2026-06-26T00:00:00","status":"CONFIRMED","transactionId":2150495006 },
  "webhookId":"96a308c1aed24021b4aad7fced1237fe" }
// ERROR: body enxuto (authenticationAPI:{}, receipt:{}, status:"ERROR") + "error":{ "errorCode":"PCE088","message":"Excede limite de saldo." } no envelope
```

**`account-status`** — `status` no envelope carrega o estado da conta (enum: `ATIVO`,`BLOQUEADO`,`BLOQUEIO-JUDICIAL`,`DESBLOQUEIO-JUDICIAL`,`ENCERRADO`); `processNumber` em bloqueio judicial:
```json
{ "entity":"account-status","createTimestamp":"2026-07-08T03:43:17.142","status":"BLOQUEIO-JUDICIAL",
  "body":{ "account":"497481325","processNumber":"000632393...","clientCode":"1ca0926a-...","documentNumber":"060***12","onboardingId":"e7a108a5-..." },
  "webhookId":"fdc0f727-..." }
```

**`pix-dict-claim-open`/`-waiting`/`-cancelled`** (mesmo body; muda entity/status = estado do claim `OPEN`/`WAITING_RESOLUTION`/`CANCELLED`); timestamps em UTC `...Z`:
```json
{ "entity":"pix-dict-claim-open","createTimestamp":"2026-05-14T01:47:30.568Z","status":"OPEN",
  "body":{ "id":"49ea2a61-...","claimType":"OWNERSHIP","key":"+55<phone>","keyType":"PHONE",
    "claimerAccount":{ "participant":"13935893","branch":"1","account":"41003252","accountType":"TRAN" },
    "claimer":{ "type":"LEGAL_PERSON","taxId":"586***08","name":"<razao>" },
    "donorParticipant":"13935893","donorAccount":{ "account":"41003245","branch":"0001","taxId":"696***87","name":"<nome>" },
    "completionPeriodEnd":"2026-05-28T01:45:00.000Z","lastModified":"...","confirmReason":"","cancelReason":"","cancelledBy":"","resolutionPeriodEnd":"2026-05-21T01:45:00.000Z" },
  "webhookId":"167d9e02-..." }
// -cancelled: confirmReason:"USER_REQUESTED", cancelReason:"DEFAULT_OPERATION"
```

**`pix-reversal-out`** (usa `originalPaymentId`; tem old+currentBalance) / **`pix-reversal-in`** (usa `originalId`/`originalClientCode`; só `oldBalance`; **typo Celcoin: manda `originalEndToEndId` E `originalEntoEndId`**):
```json
{ "entity":"pix-reversal-out","createTimestamp":"2026-05-13T16:08:09.574+00:00","status":"CONFIRMED",
  "body":{ "id":"b3531874-...","amount":1,"clientCode":"1dbc6ea9-...","originalPaymentId":"b3531874-...","originalEndToEndId":"E139...",
    "returnIdentification":"D139...","reason":"MD06","additionalInformation":"",
    "debitParty":{ "taxId":"586***08","accountType":"TRAN","name":"<razao>","branch":"0001","account":"41003252","bank":"13935893" },
    "creditParty":{ "taxId":"696***87","accountType":"TRAN","name":"<nome>","branch":"0001","account":"41003245","bank":"13935893" },
    "currentBalance":53.5,"oldBalance":54.5 },"webhookId":"b3531874-..." }
```

**`charge-in`** — **body em português**, datas `YYYY-MM-DD HH:MM:SS`, `body.status:"Pago"` (envelope `status:"CONFIRMED"`):
```json
{ "entity":"charge-in","createTimestamp":"2026-07-03T16:09:43.791","status":"CONFIRMED",
  "body":{ "dataPagamento":"2026-07-03 16:09:42","dataVencimento":"2026-08-03 00:00:00","externalId":"4419529708",
    "status":"Pago","tipoPagamento":"Pix","transactionId":"7cceb660-...","valorOriginal":11.91,"valorPago":11.91,
    "currentBalance":9689.65,"oldBalance":9677.74,"creditParty":{ "taxId":"560***17","account":"495612830" } },
  "webhookId":"7cceb660-..." }
```
