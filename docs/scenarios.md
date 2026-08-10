# Cenários de teste (magic values)

Para validar como o app consumidor reage a cada retorno da Celcoin, o mock
expõe duas convenções universais. Não há feature flag — está sempre ativo
no ambiente local.

## 1. Magic amount (universal — todos os endpoints transacionais)

Mande o `amount` em valores **abaixo de R$ 1,00** para disparar o cenário
correspondente. Valores ≥ R$ 1,00 seguem o fluxo normal de sucesso.

| amount  | cenário               | errorCode | HTTP | mensagem (paymentError)                                                |
|---------|-----------------------|-----------|------|------------------------------------------------------------------------|
| 0,01    | `insufficient_funds`  | CBE301    | 422  | Saldo insuficiente para concluir a operação.                          |
| 0,02    | `key_not_found`       | CBE189    | 404  | Chave Pix não encontrada no DICT.                                      |
| 0,03    | `fraud`               | CBE171    | 422  | Transação bloqueada por suspeita de fraude.                            |
| 0,04    | `limit_exceeded`      | CBE410    | 422  | Valor excede o limite por transação configurado para a conta.          |
| 0,05    | `blocked`             | CBE172    | 403  | Transação bloqueada para a conta informada.                            |
| 0,06    | `timeout`             | CBE504    | 504  | Tempo de resposta do SPI excedido. Tente novamente em instantes.       |
| 0,07    | `bank_unavailable`    | CBE503    | 503  | Instituição financeira destinatária temporariamente indisponível.      |
| 0,08    | `duplicate`           | CBE100    | 409  | Existe um lançamento idêntico pendente. Aguarde para evitar duplicidade.|
| 0,09    | `invalid_document`    | CBE007    | 422  | CPF/CNPJ informado é inválido.                                         |
| 0,10    | `daily_limit`         | CBE411    | 422  | Limite diário de transações Pix excedido.                              |
| 0,11    | `receiver_not_found`  | CBE405    | 404  | Conta destinatária não localizada na instituição informada.            |
| 0,12    | `invalid_key`         | CBE190    | 422  | Chave Pix inválida ou em formato não suportado.                        |
| 0,13    | `kyc_pending`         | CBE401    | 403  | Cliente possui processo KYC pendente. Operação indisponível.           |
| 0,14    | `rate_limit`          | CBE429    | 429  | Limite de requisições excedido.                                        |
| 0,15    | `error`               | CBE500    | 500  | Erro interno ao processar a transação.                                 |
| 0,16    | `not_found`           | CBE404    | 404  | Transação ou recurso não encontrado.                                   |
| 0,17    | `failed`              | CBE400    | 400  | Transação rejeitada pela instituição recebedora.                       |
| 0,18    | `accept_then_timeout` | —         | 201  | **Aceita e pendura** — só no `spb/transfer`. Ver §4.                    |

Mensagens e errorCodes variam um pouco entre **paymentError** (PIX),
**billPaymentError** (pagamento de boleto) e **chargeError** (emissão de
boleto/cobrança) — cada um traz o texto/código mais adequado ao domínio.
O HTTP status (coluna acima) é o mesmo nos três.

> **Importante:** valores **≥ R$ 1,00 sempre sucedem**, independentemente
> dos dígitos. Um `amount` como `1500`, `2500` ou `5000` **não** dispara o
> cenário `error` — o catálogo de palavras-chave (§2, que inclui `500`)
> só se aplica à **chave PIX / campos textuais**, nunca ao `amount`. Para
> forçar CBE500 de forma controlada, use a magic-amount `0,15`.

### Endpoints cobertos

Magic amount é resolvido automaticamente por `Cslabs::scenarioFromPayload`
em todos os streams que já consultam o payload por cenário:

> Os paths abaixo foram reconferidos contra `app/web.php` em **04/08/2026**: os oito
> que esta lista trazia antes **não existiam em rota nenhuma** (`/pix/payment`,
> `/baas-walletbusiness/...`, `/charge/v1/charge`, …). Quem seguisse a doc mandava
> requisição para 404 e concluía que o cenário não funcionava.

- `POST /pix/v1/payment`, `/baas-wallet-transactions-webservice/v1/pix/payment` e
  `/baas/v2/pix/payment` (`payment-baas`) — PIX out
- `POST /baas-wallet-transactions-webservice/v1/spb/transfer` (`spb-transfer`) — TED
- `POST /baas-wallet-transactions-webservice/v1/pix/reverse` e `/baas/v2/pix/reverse`
  (`pix-reverse-baas`) — estorno PIX
- `POST /v5/transactions/billpayments/authorize` (`billpayment-authorize`) — consulta boleto
- `POST /baas/v2/billpayment` (`billpayment`) — pagamento boleto
- `POST /api-integration-baas-webservice/v1/charge` (`charge`) — emissão boleto/cobrança
- `POST /baas-wallet-transactions-webservice/v1/wallet/internal/transfer` e
  `/baas/v2/wallet/internal/transfer` (`internal-transfer`) — TEF

## 2. Convenção da chave PIX (endpoints sem `amount`)

Para endpoints que não recebem `amount` (consulta DICT, criação de chave,
onboarding, account/check), mantém-se a convenção antiga: a **chave Pix**
contendo palavra-chave dispara o cenário.

| trecho na chave                                      | cenário      |
|------------------------------------------------------|--------------|
| `erro`, `error`, `500`, `outroerro`                  | `error`      |
| `falha`, `fail`, `failed`, `rejeitado`, `rejected`   | `failed`     |
| `fraude`, `fraud`, `suspeita`, `restrito`            | `fraud`      |
| `404`, `notfound`, `inexistente`, `naoencontrado`    | `not_found`  |
| `bloqueio`, `bloqueado`, `blocked`                   | `blocked`    |

Exemplo: `erro@pix.com`, `fraude@pix.com`, `+5511999990404`.

## 3. Convenção do nome do arquivo (KYC fileupload)

`POST /celcoinkyc/document/v1/fileupload` não tem `amount` nem chave Pix — o
cenário vem do **nome do arquivo enviado** em `front`, pela mesma tabela de
palavras-chave da §2.

| nome do arquivo                | webhook de veredito |
|--------------------------------|---------------------|
| `rg-verso-rejeitado.jpg`       | `REJECTED`          |
| `doc-fraude.png`               | `REJECTED`          |
| `rg-verso.jpg` (qualquer outro)| `APPROVED`          |

Escolher pelo nome do arquivo, e não por um campo extra no multipart, é
deliberado: **o app real não mandaria um campo de cenário**, então qualquer
convenção que dependesse disso testaria um caminho que produção não percorre.

### Sequência de webhooks

Um upload aceito dispara **dois** webhooks `kyc`, não um:

```
+1s   {"entity":"kyc","status":"PENDING",  …}   ← aceite de recebimento
+5s   {"entity":"kyc","status":"APPROVED"|"REJECTED", …}
```

O `PENDING` existe porque a Celcoin real o envia, e porque foi ele que expôs um
defeito no consumidor: o `recebimentoKyc` do banco digital reenviava os
documentos ao receber `PENDING`, dobrando cada envio e queimando a cota do
provedor. Um mock que só emitisse o veredito final testaria o caminho feliz e
não pegaria isso.

O `REJECTED` **não carrega motivo** — sem `rejectedReason`, sem `errorCode` —
porque é assim que a Celcoin real responde.

### `onboardingId` é opcional

O consumidor **não precisa** mandar `onboardingId` no multipart — quem identifica o
titular aqui é o `documentnumber`. Nenhum dos seis call sites do banco digital envia
esse campo, e a Celcoin real aceita assim.

Quando o campo vem, vale. Quando não vem, o mock resolve nesta ordem:

1. o onboarding já registrado para aquele documento (`onboardings_by_document`);
2. na falta dele, um id **estável** derivado do documento — para que o `PENDING` e o
   webhook de veredito cheguem com o mesmo identificador.

> Até 28/07/2026 o mock exigia o campo e devolvia `CBE014`. Errado: todo envio do app
> morria antes do contador de cota, e o mock não servia para reproduzir justamente o
> caso que existe para reproduzir.

### Cota de envio por documento

O mock recusa a partir do 4º envio do mesmo `filetype` para o mesmo
`documentnumber` (`Cslabs::KYC_LIMITE_ENVIOS_POR_DOCUMENTO`), devolvendo
**HTTP 400 com shape plano**, diferente do envelope de erro do resto da API:

```json
{"errorCode":400,"errorMessage":"Você atingiu o limite máximo de envios para RG, por favor entre em contato com suporte"}
```

Esse shape é literal de produção. Consumidor que procure `error.errorCode` não
encontra nada — foi exatamente o que aconteceu no caso real.

> O limite real da Celcoin **não nos foi informado**; 3 é um número escolhido
> para a bateria ser curta, não uma afirmação sobre o comportamento dela.

Cota é contada por `(documentnumber, filetype)`: trocar de RG para CNH libera
uma cota nova. Envio recusado por validação (`CBE014`) não consome cota.

## 4. `accept_then_timeout` — aceitar e só então pendurar (TED)

`amount 0,18` no **`POST /baas-wallet-transactions-webservice/v1/spb/transfer`**. É o
único cenário do catálogo que **não** é um erro: o mock aceita a TED normalmente e o
desvio está no **transporte**.

O que acontece, nesta ordem:

1. persiste `spb_transfers` + alias por `clientCode` (dentro do `Db::transaction` de sempre);
2. agenda o webhook `spb-transfer-out CONFIRMED`;
3. **só então** `sleep(35s)` e responde `201 PROCESSING` — para uma conexão que a essa
   altura já morreu.

**Por que 35s:** o Guzzle do app corta em 30 (`CelcoinV2HttpClient.php:22`). O POST do
app estoura **depois** de a Celcoin já ter aceitado.

**Por que a ordem é o cenário.** Dormir antes de persistir faria o `CONFIRMED` não
representar "aceito", e o teste perderia o sentido. É o que `tests/spb_accept_then_timeout_smoke.php`
defende — e a asserção foi falsificada (invertendo o stream de propósito, ela falha;
a de duração, sozinha, não pega a inversão).

**O webhook chega em `35 + 5 = 40s`, não nos 3s de sempre.** Se o `CONFIRMED` chegasse
antes do timeout do app, o `processTedOut` acharia a transferência ainda PENDENTE e a
conciliaria normalmente. O par que o QA precisa observar é o inverso: **estorno cego
primeiro** (o app estorna sem checar se a Celcoin aceitou), **`CONFIRMED` engolido
depois** — `TedService::processTedOut:230` vê a transferência já `ESTORNADA` e a
descarta com um `Yii::info`, mascarando a divergência. É o defeito **LGR-011**.

### Por que magic-cents, e por que 0,18

O gatilho tem que chegar no **corpo que o app envia**. Medido em `TedService:124-141`,
o corpo tem exatamente: `amount`, `clientCode`, `debitParty`, `creditParty`,
`clientFinality`, `description`. **Não há campo de mock** — um `mock_scenario` só
funcionaria se o app o encaminhasse, e ele não conhece campos de mock.

Sobram três canais reais, e os três funcionam (`scenarioFromPayload` lê `description`
e `clientCode` além do `amount`): `description: "aceita-e-pendura"` e um `clientCode`
contendo `accept_then_timeout` disparam o mesmo cenário. **Magic-cents é o recomendado**
por ser o canal já comprovado.

⚠️ **`0,07` não serve** — está ocupado por `bank_unavailable` desde antes. O briefing de
04/08 sugeriu esse valor; o primeiro centavo livre era o **18**.

### Fora do `spb/transfer` o slug é uso indevido, e diz isso

Só o `spb-transfer` implementa o modo. `amount 0,18` em qualquer outro stream
transacional devolve **HTTP 501 `CSLAB501`** nomeando o que houve. Sem essa entrada
cairia no fallback `error` e responderia CBE500 — erro plausível de causa invisível.

### Cuidados operacionais

- **Sob `php -S` o servidor é single-threaded**: enquanto a request está pendurada, ele
  não atende mais ninguém. Em produção o mock roda sob Apache (multiprocesso), onde o
  hang prende **um worker**, não o serviço.
- **Nenhum timeout de servidor corta a request** — verificado no deploy em 05/08/2026:
  pela borda, o POST pendura **35,34s** e devolve 201 (a dúvida era `request_terminate_timeout`
  do FPM / `ProxyTimeout`).
- `CSLABS_HANG_SECONDS` (0..120) encurta o hang — existe **para o smoke**, que não pode
  esperar 35s. Não é para uso em ambiente compartilhado.

### ⚠️ Pré-requisito: sem inscrição, o webhook não sai

O `40s` acima **pressupõe uma inscrição ativa de `spb-transfer-out` para o client que
fez o POST**. Sem ela, `webhookSubscriptionUrl()` devolve `null`, o `scheduleWebhook`
desiste antes de agendar, e o cenário entrega só a **primeira metade** do LGR-011 (o
estorno cego) — o `CONFIRMED` engolido nunca acontece.

Foi o que travou a bateria de 05/08/2026: a homologacao2 não tinha essa inscrição.

**A inscrição é por `client_id`**, e o `client_id` é derivado das credenciais usadas no
`/v5/token` (`sha256(client_id|client_secret)`, 24 chars). Não dá para semear "para o
app" de fora: quem registra tem que usar **o mesmo bearer** que faz as chamadas. Do lado
do banco digital existe comando pronto para isso:

```
./yii celcoin/cadastrar-webhook spb-transfer-out 'https://<host>/index.php?r=celcoinv2/webhook/celcoin/handle'
```

**Como saber que foi isso.** Desde 05/08 o mock não desiste mais em silêncio: o webhook
que não saiu vira linha em `webhook_dispatches` com `status = skipped` e aparece no
`webhooks[]` do shot, com `reason` (a causa) e `fix` (a rota de inscrição). Nada disso
entra na resposta HTTP — diagnóstico de mock não é contrato de API.
Guardado por `tests/webhook_visibilidade_smoke.php`.

## 5. Gatilhos de liquidação — o que a Celcoin faz sozinha e aqui ninguém faz

Dois eventos de ENTRADA de dinheiro não têm quem os provoque no mock: na Celcoin real
quem paga é alguém de fora (o pagador do Pix, o sacado do boleto no banco dele), e aqui
esse alguém não existe. Sem um gatilho, o ciclo para na emissão e a metade "o dinheiro
caiu" nunca é testável.

| gatilho | webhook que emite | ferramenta |
| --- | --- | --- |
| `POST /pixqrcode/v2/{locationId}/pagar` | `pix-payment-in` | botão "Simular pagamento" na página `.../ver` |
| `POST /baas/v2/charge/{txid}/pagar` | `charge-in` | — (curl; a cobrança não tem página) |

**Nenhum dos dois existe na Celcoin.** São ferramentas do mock, e por isso podem devolver
o que ajuda a conferir (o `/charge/.../pagar` devolve o `creditParty.account` na resposta).

No lugar do `{txid}` serve **qualquer referência que um humano tenha na mão**:
`transactionId`, `externalId`, linha digitável ou código de barras — pontuação é ignorada.
Body opcional: `{"valorPago": 354.99, "tipoPagamento": "Boleto"}`; o default é o valor
integral da cobrança.

```bash
curl -X POST 'https://cslabs.mfwks.com/celcoin/baas/v2/charge/<txid>/pagar'
```

**Idempotente**: cobrança já paga responde 200 sem reemitir o webhook. É deliberado — o
app sai cedo quando o boleto já está PAGO, então uma duplicata passaria batida lá e
mascararia um defeito de idempotência do consumidor. Cobrança cancelada devolve 409.

### Devolução Pix recebida (`pix-reversal-in`) não tem gatilho — e não é esquecimento

Perguntado pela sessão A em 10/08/2026, quando o LGR-004 passou a tratar a devolução
recebida: **o mock não emite `pix-reversal-in`, e não vai emitir.** Os dois gatilhos da
tabela acima existem porque o evento correspondente é *provocável* — alguém paga. Uma
devolução RECEBIDA não é: quem a inicia é a contraparte, e **nem a Celcoin real tem
endpoint nosso que a cause** — o `POST /baas/v2/pix/reverse` produz o `pix-reversal-out`.
Inventar um `/pagar` para ela seria inventar uma operação que não existe do outro lado.

O caminho legítimo, aqui e lá, é o evento **chegar de fora**. No mock isso é o dispatch:

```bash
# Sem "body": sai o template inteiro, com a conta de exemplo do mock.
curl -X POST 'https://cslabs.mfwks.com/celcoin/cslabs/webhook/dispatch' \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"entity":"pix-reversal-in","status":"CONFIRMED"}'
```

⚠️ **`body` SUBSTITUI o template inteiro — não mescla.** Para apontar outra conta, mande
o payload completo, e em especial **não esqueça o `returnIdentification`**: sem ele o
consumidor não tem chave de idempotência e descarta o evento em silêncio, que é
exatamente o modo de falha que se está tentando reproduzir.

```bash
curl -X POST 'https://cslabs.mfwks.com/celcoin/cslabs/webhook/dispatch' \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"entity":"pix-reversal-in","status":"CONFIRMED","body":{
        "id":"aaaaaaaa-0000-4000-8000-000000000001",
        "returnIdentification":"D13935893202608101200teste000001",
        "originalId":"bbbbbbbb-0000-4000-8000-000000000002",
        "originalClientCode":"0030172",
        "originalEndToEndId":"E13935893202608101159teste000001",
        "originalEntoEndId":"E13935893202608101159teste000001",
        "reason":"MD06","amount":23000.00,
        "debitParty":{"taxId":"62519201000157","accountType":"TRAN","name":"EMPRESA DEBITO","branch":"0001","account":"447959768","bank":"13935893"},
        "creditParty":{"taxId":"49966300000119","accountType":"TRAN","name":"EMPRESA HOMOLOG","branch":"0001","account":"<account_number da conta que recebe de volta>","bank":"13935893"},
        "oldBalance":95.50}}'
```

O que o template dá de graça (medido no corpus de logs reais, 13/05/2026) é justamente
o que decide o teste:

- a chave da devolução vem em **`returnIdentification`**, e **não existe `endToEndId`**
  neste payload. Consumidor que lê `body.endToEndId` acha `null` e descarta o evento —
  foi o que prendeu R$23.000 no evento #5353. O template **não** manda o campo de
  presente: mandá-lo faria o mock aprovar um consumidor que a Celcoin real reprova;
- vem o **typo da Celcoin**: `originalEndToEndId` **e** `originalEntoEndId`, os dois,
  com o mesmo valor;
- só `oldBalance`, sem `currentBalance` (o `-out` tem os dois);
- a conta nossa é a do **`creditParty`** (o dinheiro volta). No `-out` é a do
  **`debitParty`** — os dois lados **não** são espelho um do outro.

⚠️ **Não é gêmeo do `-out`.** O `-out` usa `originalPaymentId` (igual ao `id`) e
`clientCode`; o `-in` usa `originalId`/`originalClientCode`. Trocar um pelo outro num
teste dá verde no mock e vermelho em produção.

⚠️ **Colisão de chave, medida — n=1.** No único par real do corpus (13/05 16:31, on-us),
o `-in` e o `-out` da mesma devolução vieram com o **mesmo `returnIdentification`** e o
mesmo `originalEndToEndId`, diferindo só no `body.id`. Quem usa `returnIdentification`
como chave de idempotência precisa escopá-la por conta e por tipo, senão a segunda perna
é descartada como duplicata. Dá para reproduzir de propósito: disparar as duas entities
sobrescrevendo o mesmo `returnIdentification`.

Guardado por `tests/pix_reversal_smoke.php` (28 asserções, incluindo as negativas).

### O escopo do dono vale para a inscrição, não só para a gravação

Quem emite é o app, com bearer; quem dispara a liquidação é um curl ou o navegador, sem
token. Como toda entidade é escopada por `client_id`, os gatilhos resolvem o **dono** da
cobrança e agem no escopo dele — inclusive para **procurar a inscrição de webhook**, que
vive no `client_id` do app. Ler a inscrição no escopo de quem disparou devolveria "sem
inscrição" e o webhook sairia como `skipped` (§4 acima). Vale para os dois gatilhos.

Continua valendo o pré-requisito: **sem `./yii celcoin/cadastrar-webhook charge-in '<url>'`
no app, não há inscrição e o webhook não sai** — agora com o `skipped` visível dizendo isso.

### O shape do `charge-in` é medido, e não é o do `charge-create`

Conferido num webhook real de produção (06/08/2026): é bem mais enxuto — não repete
`boleto`, `debtor` nem `receiver`, **não manda `amount`** (o valor vem em `valorPago`) e
carrega `creditParty`, que é **a conta Celcoin onde o dinheiro assentou**. O
`creditParty.account` sai do `receiver.account` da cobrança armazenada, nunca de constante:
comparado com o `boleto.bankAccount` do `charge-create` da mesma cobrança, é ele que
responde se emissão e liquidação apontam para a mesma conta — o defeito **BOL-011**.

O app não lê esse campo (credita pelo `conta_id` local do boleto), e é justamente por isso
que ele serve de prova: os dois lados são independentes.

Guardado por `tests/charge_in_smoke.php`.

## 6. Entrega do webhook — agendar não é entregar

Emitir o webhook e **entregá-lo** são problemas diferentes, e o segundo depende do host.
Medido pelo QA em **07/08/2026**: no cslabs hospedado, todo webhook agendado respondia
`"Webhook agendado"` e **nunca saía** — zero requests inbound do IP de egresso do mock,
do lado do app. O 200 dava a impressão de que tinha disparado.

Os três caminhos de entrega, do mais realista ao mais determinístico:

| modo | quem entrega | quando serve |
| --- | --- | --- |
| `worker` | processo destacado (`exec(php bin/webhook-worker.php … &)`) | host com CLI e `exec` — é o realista, com delay de verdade |
| `shutdown` | a própria request, depois do `finish_request` | fallback; bloqueia a resposta em SAPI sem `finish_request` |
| `sync` | a própria request, na hora | **teste**: entrega e devolve o desfecho |

### Disparar e saber o que aconteceu

`POST /cslabs/webhook/dispatch` é **síncrono por padrão** e responde o desfecho real:

```bash
curl -sX POST 'https://cslabs.mfwks.com/celcoin/cslabs/webhook/dispatch' \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"entity":"charge-in","body":{"transactionId":"…","valorPago":25}}'
```

```json
"delivery": { "mode": "sync", "outcome": "delivered", "responseCode": 200, "error": null }
```

**Destino que recusa vira HTTP 502**, não 200 — quem testa por curl vê a falha sem ler o
corpo. `{"async": true}` volta ao caminho agendado, para exercitar o realista; nesse caso
a resposta **diz que não sabe** o desfecho, em vez de sugerir sucesso.

### Drenar o que ficou agendado

O fluxo realista (`charge-create`, o gatilho `/pagar` do §5) agenda com delay. Num host
onde o background não roda, isso fica parado para sempre. O flush entrega na hora e
reporta cada um:

```bash
curl -sX POST '…/cslabs/webhook/flush' -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"entity":"charge-in"}'
```

⚠️ **Use o mesmo bearer do dono da entidade.** A fila é escopada por `client_id`, e o
despacho de um `/pagar` pertence ao **app** que emitiu a cobrança, não a quem clicou em
pagar (§5). Bearer errado drena a fila do anônimo e volta vazia. Há `{"clientId": "…"}`
para forçar o escopo.

**Ninguém entrega duas vezes.** Worker e flush disputam o mesmo despacho; quem chega
primeiro na hora de mandar passa a linha de `scheduled` para `delivering`, e o outro
desiste. Sem isso o app receberia o mesmo evento duas vezes — e a idempotência dele
esconderia o defeito.

⚠️ **A fila guarda o atraso.** Tudo que ficou `scheduled` desde antes de 07/08/2026 —
quando nada era entregue — continua lá. O **primeiro** flush num escopo entrega esse
backlog inteiro; estreite com `{"entity": "…"}` se isso não for o que se quer.

### Diagnosticar o host, em vez de adivinhar

Por que o background não roda é **config de PHP do servidor**, e isso não se mede de
fora. `GET /cslabs/webhook/diagnostico` responde do próprio host:

```bash
curl -s '…/cslabs/webhook/diagnostico' | php -r '…'
```

Reporta o SAPI web, o candidato a binário de CLI **executado de verdade** (para ver se
ele é mesmo CLI), `disable_functions`, `fastcgi_finish_request` **e**
`litespeed_finish_request`, a pasta de dispatches, e a lista de `impedimentos` do worker
— vazia significa que ele deveria funcionar.

**Foi assim que a causa saiu de suspeita para medição.** No cslabs o `sapi_web` é
`litespeed` e o `PHP_BINARY` — que o spawn usava — é `/opt/alt/php83/usr/bin/lsphp`, o
binário da SAPI do LiteSpeed. O `bin/webhook-worker.php` abre com
`if (PHP_SAPI !== 'cli') exit('worker only')`: o worker morria na primeira linha, com
stderr em `/dev/null`. A lápide fica em disco — o worker sai **antes** do `unlink`, então
cada envelope não consumido em `app/tmp/cslabs/dispatches` é um webhook que não saiu (o
diagnóstico conta quantos). `disable_functions` está vazio: `exec` nunca foi o problema.

Agora o binário é procurado (`PHP_BINDIR`, `/usr/bin/php`, layout cPanel) e conferido; se
nenhum serve, o modo cai para `shutdown` **com o motivo registrado no despacho**.

### Inscrição com URL quebrada não passa mais calada

A inscrição de `charge-create` do cslabs estava em
`https://.e-bancos.com.br/…?r=r=boleto/…` — host com rótulo vazio (não resolve em DNS) e
parâmetro dobrado. Agora a inscrição **recusa (422) dizendo qual é o defeito** quando a
URL é inentregável, e **avisa** (`urlWarnings` na resposta) quando ela entrega mas cheira
a copia-e-cola. O `filter_var(FILTER_VALIDATE_URL)` continua ali como rede: ele recusa,
mas calado — e o veredito dele varia com a versão do PHP.

Guardado por `tests/webhook_entrega_smoke.php`.

## 7. Internals

- Catálogo de centavos → cenário: `Cslabs::SCENARIO_BY_CENTS`
- Resolução: `Cslabs::scenarioFromAmount(mixed $amount, string $default = 'success')`
- HTTP status por cenário: `Cslabs::scenarioHttpStatus(string $scenario)`
- Último cenário disparado nesta request: `Cslabs::lastErrorScenario()`
- Catálogo de mensagens: maps em `paymentError`, `billPaymentError`, `chargeError`
  (Cslabs.php).

Adicionar um novo cenário:

1. Inclua o slug em `SCENARIO_BY_CENTS` (próximo centavo livre).
2. Acrescente entrada em `paymentError` / `billPaymentError` / `chargeError`
   conforme onde fizer sentido.
3. Mapeie HTTP em `scenarioHttpStatus`.
4. Atualize esta doc.
