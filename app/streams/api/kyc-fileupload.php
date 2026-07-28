<?php

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;

header('Content-Type: application/json');

$response = Cslabs::kycFileUploadResponse($_POST, $_FILES);

// Cota estourada: shape PLANO, sem envelope, como a Celcoin real devolveu em
// producao. Sai aqui antes de qualquer webhook — envio recusado nao gera analise.
if (isset($response['errorCode'])) {
    http_response_code(400);
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if (($response['status'] ?? null) === 'ERROR') {
    http_response_code(400);
}

if (($response['status'] ?? null) === 'SUCCESS') {
    $webhookUrl = Cslabs::webhookSubscriptionUrl('kyc');

    // O cenario vem do NOME DO ARQUIVO, seguindo a convencao de docs/scenarios.md:
    // um arquivo chamado "rg-verso-rejeitado.jpg" reprova, qualquer outro aprova.
    // Assim a bateria escolhe o desfecho sem precisar de campo extra no multipart,
    // que o app real nao mandaria.
    $cenario = Cslabs::scenarioFromValue($_FILES['front']['name'] ?? '', 'success');
    $veredito = in_array($cenario, ['failed', 'fraud', 'blocked'], true) ? 'REJECTED' : 'APPROVED';

    $corpoBase = [
        'onboardingId' => $response['body']['onboardingId'],
        'documentNumber' => (string) ($_POST['documentnumber'] ?? ''),
        'clientCode' => (string) ($_POST['clientCode'] ?? ''),
        'account' => (string) ($_POST['account'] ?? ''),
        'filetype' => $response['body']['filetype'],
        'fileId' => $response['body']['fileId'],
    ];

    // PENDING primeiro. Isto NAO e detalhe cosmetico: a Celcoin devolve PENDING como
    // aceite de recebimento, ~1s apos o upload, e era esse webhook que fazia o
    // recebimentoKyc do app reenviar os documentos sozinho e queimar a cota
    // (medido no bcbr em 28/07/2026 13:58:54 -> 13:58:55 -> 400 de cota em 13:58:57).
    // Sem emitir PENDING, o mock testa so o caminho feliz e nao prova nada sobre a
    // correcao. Ver sustenance/bcbr/2026/07-28-motivos-reprovacao-kyc-celcoin/.
    Cslabs::scheduleWebhook(
        'kyc',
        Cslabs::webhookEnvelope('kyc', 'PENDING', $corpoBase + ['status' => 'PENDING']),
        1,
        $webhookUrl
    );

    // O veredito da analise vem depois. Reparar que o REJECTED NAO carrega motivo:
    // e assim que a Celcoin real responde, e e por isso que desc_erro_celcoin ficava
    // vazio no app. Se um dia ela passar a mandar rejectedReason, e aqui que muda.
    Cslabs::scheduleWebhook(
        'kyc',
        Cslabs::webhookEnvelope('kyc', $veredito, $corpoBase + ['status' => $veredito]),
        5,
        $webhookUrl
    );
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
