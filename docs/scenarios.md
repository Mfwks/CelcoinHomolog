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
- **Verificar no deploy** se algum timeout de servidor mata a request antes dos 35s
  (`request_terminate_timeout` do FPM, `ProxyTimeout`). O efeito no app ainda seria uma
  exceção por volta dos 30s, mas por conexão fechada e não por timeout de leitura.
- `CSLABS_HANG_SECONDS` (0..120) encurta o hang — existe **para o smoke**, que não pode
  esperar 35s. Não é para uso em ambiente compartilhado.

## 5. Internals

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
