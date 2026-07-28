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

- `POST /pix/payment` (`payment-baas`) — PIX out
- `POST /baas-walletbusiness/v1/spb/transfer` (`spb-transfer`) — TED
- `POST /pix-payment/refund` e `/baas-walletbusiness/v1/pix/refund` (`pix-reverse-*`) — estorno PIX
- `POST /baas-billpayment/v1/authorize` (`billpayment-authorize`) — consulta boleto
- `POST /baas-billpayment/v1/billpayment` (`billpayment`) — pagamento boleto
- `POST /charge/v1/charge` (`charge`) — emissão boleto/cobrança
- `POST /baas-walletbusiness/v1/transfer/internal` (`internal-transfer`) — TEF

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

## 4. Internals

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
