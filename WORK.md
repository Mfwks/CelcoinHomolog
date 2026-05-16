# Trabalho em andamento

Arquivo vivo, três seções. Cada linha: `YYYY-MM-DD · descrição curta · ref (commit/PR/issue)`. Quando "Concluído" passar de ~20 entradas, mover o excedente para `docs/changelog.md`.

## Em andamento

- _(vazio)_

## Próximo

- 2026-05-16 · Logs necessários para confirmar shape: bulk onboarding (3 variantes), proposal endpoints, KYC fileupload, wallet movement/ConsolidatedStatement/exportfile/income-report, claims (5 endpoints), pix reverse (2 variantes), QR criar (estático/dinâmico), DELETE charge, AccountManager business/close/fetch-business, SPB reversal.
- 2026-05-16 · Adicionar `/baas/v2/pix/dict/entry/external/{conta}/` como alias da consulta DICT BaaS já implementada.

## Concluído recente

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
