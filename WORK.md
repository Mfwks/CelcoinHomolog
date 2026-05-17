# Trabalho em andamento

Arquivo vivo, três seções. Cada linha: `YYYY-MM-DD · descrição curta · ref (commit/PR/issue)`. Quando "Concluído" passar de ~20 entradas, mover o excedente para `docs/changelog.md`.

## Em andamento

- _(vazio)_

## Próximo

- 2026-05-17 · Trailing slash: conferir se o roteador normaliza ou se precisamos duplicar mais rotas.
- 2026-05-17 · mTLS: confirmar config do Apache para aceitar conexão sem cert (opcional pelo spec).
- 2026-05-17 · Quando logs reais chegarem, revisar shape de: bulk onboarding (3 variantes inferidas), claims (5 endpoints), QR estático/dinâmico criar, DELETE charge, AccountManager business/close/fetch-business, SPB reversal, KYC fileupload, exportfile (15 schemas inferidos).

## Concluído recente

- 2026-05-17 · 3 últimos pendentes do gap implementados: bulk onboarding PF/PJ (3 rotas → `api/onboarding-bulk`, aceita array ou `{items}`, HTTP 207 em PARTIAL, webhooks só para aceitos), KYC v1 fileupload multipart (`api/kyc-fileupload`, valida `onboardingId`/`documentnumber`/`filetype` enum + arquivo `front`, persiste em `kyc_uploads`), exportfile polimórfico (`api/exportfile`, helper `exportFileRecordSchema` com schemas distintos para os 15 `filetype`).
- 2026-05-17 · 7 endpoints novos implementados a partir da doc oficial: onboarding proposal (POST PF, POST PJ, GET com filtros e paginação), `GET /baas-walletreports/v1/wallet/movement` (+ alias `/baas/v2/wallet/movement`) com counterParty e seed determinística, `GET /tools-conciliation/v1/ConsolidatedStatement` com campos PT-BR (`dataContabil`/`nomeHistorico`/`saldoDia`/etc) e validação de janela ≤15d, `GET /tools-conciliation/v1/exportfile/types` (dicionário de 15 tipos), `GET /baas/v2/account/income-report` com `incomeFile` PDF base64. Cslabs ganhou 6 métodos novos + 2 validadores de campos.
- 2026-05-17 · Streams marcados como "shape inferido" revisados contra a doc oficial: `pixReverseResponse` ganhou `originalPaymentId`/`returnIdentification`/`additionalInformation` e default `reason=MD06`; `pixDictClaimResponse` expandiu com `claimerAccount`/`claimer`/`donorParticipant`/períodos + helper `buildPixDictClaimBody` que aplica grafia Pascal no `keyType` da response (EMAIL→Email, PHONE→Phone); `brcodeDynamicCreateResponse` retorna `amount.original` como string (`"5000.00"`); `walletEntryResponse` agora retorna status `CONFIRMED`; `accountFetchBusinessResponse` aceita `Account` ou `DocumentNumber` e devolve `businessAddress`+`owner[]`; `pixDictClaimListResponse` com default `limitPerPage=10`.
- 2026-05-17 · `gap-homologacao.md` atualizado com referências da doc pública v2 (`developers.celcoin.com.br/reference/`) por endpoint, mapa legado×v2 e inconsistências reais a preservar (cadastraChavePix em PT, keyType Pascal na response DICT, amount string em QR dinâmico, grafias do timestamp, etc.). Sem mexer em código.
- 2026-05-17 · Webhooks alinhados aos logs reais: envelope `{entity, createTime[s|S]tamp (grafia varia por entity), status, body, webhookId}` centralizado em `Cslabs::webhookEnvelope`. `sampleWebhookBody` agora carrega shapes reais para 9 entities (onboarding-create, pix-payment-in/out, internal-transfer-out, spb-transfer-in/out, spb-reversal-in, charge-create/in, billpayment). Bug do `internal-transfer` corrigido (entity `wallet.internal.transfer.completed` → `internal-transfer-out`). 12 callers refatorados.
- 2026-05-17 · Alias `/baas/v2/pix/dict/entry/external/{account}[/]` registrado apontando para o stream `api/key` (mesma consulta DICT BaaS).
- 2026-05-16 · Endpoints sem log implementados com shape inferido do spec: pix reverse (BaaS + legado), wallet entry, DICT listar + claims (5 endpoints), QR estático/dinâmico criar (com EMV gerado), location/base64, DELETE charge, AccountManager business/close/fetch-business. Marcar para revisão quando logs reais chegarem.
- 2026-05-16 · Basic Auth aplicado no `Cslabs::sendJsonRequest`: webhooks despachados usam a credencial da inscrição.
- 2026-05-16 · Disparador de webhooks de saída: `POST /cslabs/webhook/dispatch` + templates por entity em `Cslabs::sampleWebhookBody`. Subscription com flag `active` e DELETE.
- 2026-05-16 · Webhook subscription: GET (lista + filtros), PUT (atualizar), DELETE (por path ou query); rotas com `{entity}`. Trailing-slash padronizado para `check` e `fetch`.
- 2026-05-16 · Wallet + QR/EMV: `GET /baas-walletreports/v1/wallet/balance`, `GET /pix/v1/brcode/static/{id}/base64`, `POST /pix/v1/emv`, `GET /pix/v1/collection/{immediate,duedate}/payload/{url}`.
- 2026-05-16 · SPB transfer (POST + GET status), internal-transfer-status, pix-payment-status (BaaS e legado). Persistência de pix payments em `payment-baas.php` para alimentar status.
- 2026-05-16 · Pix DICT: `POST /entry` (criar EVP/CPF/CNPJ/EMAIL/PHONE com erros CBE175 / CBE345) e `DELETE /entry/{key}`.
- 2026-05-16 · AccountManager PUT: `natural-person` (com erro CBE352 para conta com KYC pendente) e `status` (com erro CBE345). Schedula webhook `account-status`.
- 2026-05-15 · Onboarding PF/PJ create implementados (`/baas-onboarding/v1/account/{natural-person,business}/create`); chave de resposta `onBoardingId` (capital B, conforme log real); validação devolve `CBE014` com nome do campo na mensagem; `account-status` reaproveita índices novos.
- 2026-05-15 · Bloco boletos implementado: `POST /api-integration-baas-webservice/v1/charge`, `POST /baas/v2/billpayment`, `GET /baas/v2/billpayment/status`; resposta de `/v5/transactions/billpayments/authorize` realinhada ao shape real (ISO `dueDate`, `dd/mm/YYYY` `settleDate`, `registerData` expandido). Webhooks `charge-create` e `billpayment` agendados na criação.
- 2026-05-15 · Gap de homologação levantado contra spec do sistema consumidor · `docs/gap-homologacao.md`.
- 2026-05-14 · Estrutura de documentação criada: `CLAUDE.md`, `docs/architecture.md`, `docs/endpoints.md`, `docs/conventions.md`, `WORK.md`.
- 2026-05-06 · Reapply "Consulta boleto" · `dbce65c`.
- 2026-05-04 · Unverified Codex editions · `bb28d59`.
