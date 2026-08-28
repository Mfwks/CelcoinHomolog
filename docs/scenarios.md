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

## 7. Recusas de contrato — o que não depende de cenário nenhum

Tudo acima é **cenário**: o cliente pede o erro (por centavo mágico, por slug, pelo
nome do arquivo) e o mock o entrega. Isso cobre o caminho feliz e os erros que o
cliente já sabe que existem — e não cobre a classe de defeito mais cara, que é a
Celcoin **recusar uma entrada que o mock aceitava calado**.

Um mock mantido só por cenário dá **verde em bug que produção reprova**. Ele foi
escrito para simular o que a Celcoin faz quando dá certo; se não simular também o
que ela faz quando a entrada está errada, a homologação fica cega justamente onde o
teste teria valor. **Toda recusa real medida em produção vira validação aqui** — é o
mesmo laço prod→mock que a sustentação já mantém prod→dev.

### `billpayment/authorize` — erro 822, linha digitável recusada

**A Celcoin quer a linha digitável de 47 dígitos.** Qualquer outra coisa volta como
`HTTP 400` com o corpo **plano** (sem envelope):

```json
{"errorCode":"822","message":"Erro na conversao de Linha Digitavel para Codigo de Barras"}
```

Medido em **9 recusas e 54 sucessos** dos logs reais (`mocks-v2`, confiapay e
homologacao3, 05–08/2026). A regra separa os dois conjuntos sem exceção:

| enviado em `barCode.digitable` | dígitos | DVs | Celcoin |
|---|---|---|---|
| código de barras (44), **mesmo com DV geral correto** | 44 | ok | **822** |
| linha de 47 com DV de campo errado | 47 | ✗ | **822** |
| linha de 47 mascarada com `.` e espaço, DVs errados | 47 | ✗ | **822** |
| lixo (qualquer string curta) | — | — | **822** |
| linha digitável de 47 com todos os DVs corretos | 47 | ok | `errorCode 000` |

Duas armadilhas que só o corpus revela:

- ⚠️ **Não é "44 recusa, 47 aceita".** Dois dos nove 822 tinham 47 dígitos. A Celcoin
  valida os **DVs** (mod10 dos três campos + mod11 do DV geral), não o comprimento.
  Uma implementação que só contasse dígitos aprovaria os dois.
- ⚠️ **Barcode bem-formado também é recusado.** Quatro dos nove são códigos de barras
  de 44 com DV geral **correto** — inclusive um boleto Itaú legítimo de R$ 3.255,34.
  Neste endpoint a Celcoin não converte 44→47; ela quer a linha digitável.

⚠️ **A mensagem varia; o código não.** O corpus tem as duas grafias — `"Erro na
conversão de linha digitável para código de barras"` (7×) e `"Erro na conversao de
Linha Digitavel para Codigo de Barras"` (2×) — e não é o log comendo acento, porque
os dois arquivos têm acento UTF-8 em milhares de outras linhas. **Case pelo `822`,
nunca pela string.** O mock emite a segunda variante, que é a do incidente mais
recente.

**O que passa mesmo sem ser linha de cobrança:**

- **Arrecadação/convênio** (48 dígitos começando com `8`) — estrutura própria, e
  **zero ocorrências no corpus**, nem sucesso nem recusa. Passa, porque inventar
  recusa sem medida é o inverso do erro que esta seção existe para corrigir. Quando
  a sustentação medir uma, a validação entra aqui.
- **Linha inválida que o próprio mock emitiu antes de 13/08/2026**, e só por
  `charges_by_bank_line`, para não inutilizar cobrança já gravada no SQLite de
  homologação. Código de barras de 44 **não** entra por essa porta.

```bash
# recusado (código de barras de 44 — o caso do incidente de 13/08)
curl -sS -X POST "$BASE/v5/transactions/billpayments/authorize" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"barCode":{"type":1,"digitable":"34196153000003255341090002071957140237236000"}}'
# → HTTP 400 {"errorCode":"822","message":"Erro na conversao de Linha Digitavel para Codigo de Barras"}

# aceito (a MESMA cobrança, como linha digitável de 47)
curl -sS -X POST "$BASE/v5/transactions/billpayments/authorize" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"barCode":{"type":1,"digitable":"34191090080207195714202372360004615300000325534"}}'
# → HTTP 200 errorCode 000
```

### `barCode.type` não distingue arrecadação — o prefixo `8` distingue

Medido: o app manda **`type: 1` em 100% dos 84 requests** do corpus, inclusive nos
boletos de cobrança que a Celcoin respondeu com `registerData` completo. Até
13/08/2026 o mock tratava `type === 1` como conta de consumo e devolvia
`registerData: null` — errado para o tráfego real. Quem decide é o **prefixo 8** da
linha de convênio.

Há ainda um `type` fora da faixa: `"NPC"` (string) faz a Celcoin devolver o erro de
model-binding do .NET, não um erro de negócio —
`{"errors":{"barCode.type":["Could not convert string to integer: NPC…"]}}`. Medido
4× em homologacao3; **ainda não reproduzido aqui**.

### A linha digitável emitida pelo mock é válida de verdade

Consequência necessária do acima: se o `authorize` recusa linha inválida, o emissor
não pode gerar linha inválida — senão o mock recusaria o próprio boleto. Até
13/08/2026 a `bankLine` eram 47 dígitos tirados de um `sha256`: comprimento certo,
DVs aleatórios, e o `barCode` saía de uma **semente diferente**, sem relação nenhuma
com a linha da mesma cobrança.

Agora (`App\Core\Boleto`) a linha codifica **valor e vencimento de verdade** e o
`barCode` é a mesma linha reordenada — round-trip 47↔44 exato. O fator de vencimento
usa a base **22/02/2025 = 1000** (o ciclo reiniciou; a base de 1997 esgotou em
21/02/2025), conferida contra três respostas reais: fator `1493` → 30/06/2026 e a
Celcoin responde `dueDateRegister: "2026-06-30T00:00:00"`.

Guardado por `tests/billpayment_822_smoke.php` (34 asserções, todas contra payload
real) e pelos casos 7 e 8 do `tests/charge_smoke.php`. Os três critérios de mutação
do briefing foram exercitados à mão: aceitar sempre derruba 11 asserções, medir só o
comprimento derruba as duas linhas de 47 inválidas, converter 44→47 derruba os
quatro barcodes. ⚠️ Ao repetir isso, **confira que a mutação foi de fato aplicada** —
uma substituição que não casa no arquivo produz "nenhuma falha", que é
indistinguível de teste fraco.

## 8. Charge V1 por `externalId` — e as duas formas de "não encontrei"

`GET` e `DELETE /api-integration-baas-webservice/v1/charge/{externalId}`, o par que
o app usa desde 17/08/2026 e que faltava aqui. Quem apontasse uma instância para o
mock tomava **404 de rota** — que reproduz o mesmo vermelho do sandbox por um motivo
completamente diferente, e é o pior tipo de falso negativo.

O id do path é o `str_pad($boletoId, 10, '0', STR_PAD_LEFT)` mandado na emissão,
**não** o `transactionId` (UUID).

### O contrato medido

Homolog `totalis` contra `sandbox.openfinance.celcoin.dev`, 26/08/2026
(`sustenance/dev/2026/2026-08-26-bol-005-qa-homolog/` e
`sustenance/totalis/2026/08-26-bol-015-.../repro-homolog/`):

| operação | caso | HTTP | corpo |
|---|---|---|---|
| `DELETE` | id inexistente | **400** | `{"version":"1.2.0","status":"ERROR","error":{"errorCode":"CDE001","message":"Não foi encontrado registro para o identificador informado."}}` |
| `GET` | id inexistente | **404** | `{"statusCode":404,"message":"Resource not found"}` |

⚠️ **As duas formas são diferentes, e a diferença é o achado.** O `DELETE` devolve
erro de negócio da Celcoin — família CDE, com `version`, passando pelo gateway até a
aplicação. O `GET` devolve o **404 genérico do gateway**, que é a mesma cara que um
path inexistente tem. Nenhuma das duas foi uniformizada aqui: o mock devolve cada
uma como foi medida.

**Consequência prática, ainda não resolvida:** essa assimetria é evidência de que o
`GET .../v1/charge/{id}` pode **não existir** na Celcoin. Se existisse, o esperado
seria um CDE001 como no `DELETE` do mesmo prefixo. A consulta documentada da família
é a V2 por query string (`GET /baas/v2/charge?ExternalId=`), que é a que o corpus
mostra em uso. **Enquanto isso não for medido contra um id que exista de verdade no
provedor, uma bateria verde contra este mock não prova que o endpoint responde em
produção** — prova só que o app sabe consumir a resposta caso responda.

### O sucesso é inferido, e da família certa

Nenhum sucesso foi observado (o sandbox nunca tinha a cobrança viva). O que sai aqui
segue o envelope da família charge, esse sim medido: `POST /v1/charge` responde
`{"version":"1.1.0","status":"SUCCESS","body":{"transactionId":…}}` (corpus,
confiapay 02/07) e o `GET` V2 responde o mesmo envelope com `body.boleto.bankLine`.

```json
{"version":"1.1.0","status":"SUCCESS","body":{"transactionId":"…","externalId":"0000010474",
 "amount":805.66,"status":"PENDING","boleto":{"bankLine":"…47 dígitos…","barCode":"…"},"…":"…"}}
```

⚠️ **Não achate esse envelope.** `BoletoCelcoin::buscarEPopularLinhaDigitavel` lê
`$chargeDetails->boleto->bankLine`, mas `$chargeDetails` é o corpo inteiro da
resposta — a leitura certa é `->body->boleto->bankLine`, como o próprio
`BoletoCelcoin.php:79` faz com `->body->transactionId` na emissão. Mover `boleto`
para o topo faria o app parar de ver `null` **na homologação e só nela**. É a
inversão exata do que a seção 7 existe para evitar. Duas asserções do
`tests/charge_v1_smoke.php` defendem isso.

O `DELETE` de cobrança viva devolve `PROCESSING` e a cobrança inteira, como a V2 — o
`CANCELED` chega pelo webhook `charge-canceled`, que agora traz também o `externalId`
(93 amostras reais em `mocks-v2/webhooks-raw.log` trazem).

### Cenário deliberado

Sem semear nada, o índice decide: cobrança existe → sucesso; não existe → o erro da
tabela acima. Para o teste negativo com cobrança **viva** — "a política recusa este
cancelamento" — vale a convenção de palavra-chave do `scenarioFromValue` no próprio
`externalId`:

```bash
# recusa por política (cobrança existe, cancelamento negado)
curl -X DELETE "$BASE/api-integration-baas-webservice/v1/charge/qa-teste-blocked-1"
# → HTTP 403 {"status":"ERROR","error":{"errorCode":"CSLAB423",…},"version":"1.2.0"}
```

⚠️ **Palavra-chave, não hífen.** `qa-teste-comum-1` não vira cenário nenhum.

### A armadilha que isto desenterrou

Cobrir esta rota expôs um defeito **pré-existente e mudo**: `scenarioFromValue`
casava por **substring**, e dois needles são numéricos (`404` e `500`). O boleto de
id 10404 vira `externalId` `0000010404`, contém "404" — e a **emissão** era recusada
com `not_found`, antes de qualquer consulta; o de id 5001 tomava erro interno. Não
era exclusividade do `externalId`: `scenarioFromPayload` também recebe
`documentNumber`, `account`, `clientCode` e `transactionId`, todos cadeias de
dígitos em produção — um CPF começado em 404 caía igual.

Corrigido em 28/08: **valor formado só por dígitos casa needle por igualdade**, não
por substring. Quem digita `404` num campo de cenário continua pedindo `not_found`;
quem manda `0000010404` como identificador, não. É a mesma armadilha que obrigou o
magic-cents a valer só abaixo de R$ 1,00 (seção 1) — lá se restringiu o domínio do
match, aqui a forma dele.

### Cobertura

`tests/charge_v1_smoke.php` (36 asserções, **funcional**: sobe `php -S` e faz HTTP de
verdade, porque roteamento, método e status HTTP não existem dentro de um builder).
Quatro mutações conferidas à mão:

| mutação | asserções que caem |
|---|---|
| achatar o envelope do `GET` (acomodar o app) | 1 |
| `GET` inexistente devolvendo o envelope CDE001 | 4 |
| needle numérico voltando a casar por substring | 3 |
| `DELETE` inexistente devolvendo 404 | 1 |

⚠️ Ao repetir, **confirme que o teste rodou**, não só que a substituição casou. Na
primeira passada as três últimas acusaram "nenhuma falha" porque o `php -S` da
mutação anterior ainda segurava a porta e o smoke abortava no arranque — zero `FAIL`
e zero `ok` se parecem muito com teste verde. O par de guardas é: alvo encontrado
**e** saída não vazia.

## 9. Internals

- Catálogo de centavos → cenário: `Cslabs::SCENARIO_BY_CENTS`
- Resolução: `Cslabs::scenarioFromAmount(mixed $amount, string $default = 'success')`
- HTTP status por cenário: `Cslabs::scenarioHttpStatus(string $scenario)`
- Último cenário disparado nesta request: `Cslabs::lastErrorScenario()`
- Catálogo de mensagens: maps em `paymentError`, `billPaymentError`, `chargeError`
  (Cslabs.php).
- Linha digitável e código de barras (validação, conversão, emissão):
  `App\Core\Boleto` — o docblock traz a tabela dos 9 casos medidos.
- Recusa 822: `Cslabs::billPaymentDigitableError()`; motivo da última recusa em
  `Cslabs::lastDigitableRejection()` (para log e teste; a resposta não o expõe,
  porque a Celcoin real também devolve o 822 seco).
- Charge V1 por externalId: `Cslabs::chargeV1FetchResponse()` e
  `Cslabs::chargeV1CancelResponse()` — devolvem `['http' => int, 'body' => array]`,
  porque o status HTTP faz parte do contrato medido. Efeito colateral do
  cancelamento (mutação do registro + webhook, nessa ordem) em
  `Cslabs::applyChargeCancellation()`, compartilhado com a rota V2.

Adicionar um novo cenário:

1. Inclua o slug em `SCENARIO_BY_CENTS` (próximo centavo livre).
2. Acrescente entrada em `paymentError` / `billPaymentError` / `chargeError`
   conforme onde fizer sentido.
3. Mapeie HTTP em `scenarioHttpStatus`.
4. Atualize esta doc.

Adicionar uma **recusa de contrato** (seção 7) é outra coisa, e a diferença importa:
cenário é o cliente *pedindo* o erro; recusa é o mock **impondo** a validação da
Celcoin a quem não pediu nada.

1. Parta do payload real da pasta do caso, na sustentação. **Não invente o payload** —
   o que dá valor à recusa é ela ser idêntica à que produção levou.
2. Reproduza o corpo **e o envelope** medidos. O 822 é plano; outros erros vêm no
   `{status, error, version}`. Copiar o envelope errado ensina um shape falso.
3. Escreva a asserção **negativa** junto: o caso que hoje passa e deveria falhar.
4. Rode a mutação — reintroduza a leniência e confirme que o teste cai. Se não cair,
   o teste não guarda nada.
