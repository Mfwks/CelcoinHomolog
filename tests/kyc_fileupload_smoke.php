<?php

# Smoke test: POST /celcoinkyc/document/v1/fileupload (api/kyc-fileupload).
#
# Cobre o que o mock passou a reproduzir em 28/07/2026: a COTA de envio por
# documento, que na Celcoin real trava a conta e foi o que prendeu a conta 3098 do
# bcbr. Antes disso o mock so conhecia o caminho feliz — aceitava upload sem limite
# e emitia um unico webhook CONFIRMED — e por isso nao servia para exercitar a
# correcao do laco de reenvio do app.
#
# O que NAO da para cobrir aqui: a sequencia de webhooks (PENDING -> APPROVED/
# REJECTED) mora no stream, nao no builder, e depende de assinatura de webhook
# registrada. Isso e teste funcional, feito pela bateria em homologacao — ver
# sustenance/bcbr/2026/07-28-motivos-reprovacao-kyc-celcoin/.

define('TMP', __DIR__ . '/tmp_smoke/');
if (!is_dir(TMP)) {
    mkdir(TMP, 0775, true);
}
$dbFile = TMP . 'cslabs/cslabs.sqlite';
foreach ([$dbFile, $dbFile . '-wal', $dbFile . '-shm'] as $f) {
    if (is_file($f)) {
        @unlink($f);
    }
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, 4));
    $file = __DIR__ . '/../app/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

defined('SITE') or define('SITE', 'https://cslabs.mfwks.com');
defined('BASE') or define('BASE', '/');
defined('DEV') or define('DEV', false);

require __DIR__ . '/../app/functions.php';

$_SERVER['REQUEST_URI'] = '/smoke/kyc-fileupload';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

use App\Core\Cslabs;

function expect(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
    echo "ok: $msg\n";
}

Cslabs::boot();

$arquivo = static fn(string $nome = 'rg-frente.jpg'): array => [
    'name' => $nome,
    'size' => 12345,
    'type' => 'image/jpeg',
];

$form = static fn(string $doc, string $tipo = 'RG'): array => [
    'onboardingId' => 'a1622cbd-139a-4d61-a740-e14262a53c4d',
    'documentnumber' => $doc,
    'filetype' => $tipo,
];

# 1) Envio dentro da cota e aceito, e devolve o envelope normal.
$doc = '28886100353';
for ($i = 1; $i <= Cslabs::KYC_LIMITE_ENVIOS_POR_DOCUMENTO; $i++) {
    $r = Cslabs::kycFileUploadResponse($form($doc), ['front' => $arquivo()]);
    expect(($r['status'] ?? '') === 'SUCCESS', "envio $i dentro da cota -> SUCCESS");
    expect(!empty($r['body']['fileId']), "envio $i devolve fileId");
}

# 2) Estourada a cota, a resposta muda de SHAPE: plano, com errorCode/errorMessage
#    no topo, exatamente como a Celcoin devolveu em producao. Quem consumir isso
#    procurando por ['error']['errorCode'] nao acha nada — foi o que aconteceu.
$r = Cslabs::kycFileUploadResponse($form($doc), ['front' => $arquivo()]);
expect(($r['errorCode'] ?? null) === 400, 'cota estourada -> errorCode 400 no TOPO');
expect(!isset($r['status']), 'cota estourada -> sem chave "status" (shape plano)');
expect(!isset($r['error']), 'cota estourada -> sem envelope "error"');
expect(
    str_contains($r['errorMessage'] ?? '', 'limite máximo de envios para RG'),
    'cota estourada -> mensagem igual a de producao'
);

# 3) A cota e por (documento, tipo de documento): trocar o tipo libera de novo.
$r = Cslabs::kycFileUploadResponse($form($doc, 'CNH'), ['front' => $arquivo('cnh.jpg')]);
expect(($r['status'] ?? '') === 'SUCCESS', 'mesmo documento com filetype diferente -> SUCCESS');

# 4) E por documento: outro CPF nao herda a cota do primeiro.
$r = Cslabs::kycFileUploadResponse($form('12345678909'), ['front' => $arquivo()]);
expect(($r['status'] ?? '') === 'SUCCESS', 'outro documentNumber -> cota propria');

# 5) O nome do arquivo escolhe o desfecho da analise (docs/scenarios.md). Aqui so
#    da para conferir a resolucao do cenario; quem emite o webhook e o stream.
expect(
    Cslabs::scenarioFromValue('rg-verso-rejeitado.jpg', 'success') === 'failed',
    'arquivo "…rejeitado…" resolve cenario failed -> webhook REJECTED'
);
expect(
    Cslabs::scenarioFromValue('rg-verso.jpg', 'success') === 'success',
    'arquivo comum resolve cenario success -> webhook APPROVED'
);

# 6) onboardingId e OPCIONAL — quem identifica o titular aqui e o documento.
#    Nenhum dos seis call sites do banco digital manda esse campo, e a Celcoin real
#    aceita assim (em producao chegou a devolver o 400 de COTA para a mesma requisicao,
#    ou seja, contou o envio). Enquanto o mock exigia o campo, TODO envio do app morria
#    em CBE014 antes do contador — e o mock nao servia para o caso que existe para
#    reproduzir. Ver sustenance/dev/2026/07-28-bateria-qa-kyc-sms/.
$semOnboarding = ['documentnumber' => '55544433322', 'filetype' => 'RG'];

$r1 = Cslabs::kycFileUploadResponse($semOnboarding, ['front' => $arquivo()]);
expect(($r1['status'] ?? '') === 'SUCCESS', 'sem onboardingId -> SUCCESS (nao e obrigatorio)');
expect(!empty($r1['body']['onboardingId']), 'sem onboardingId -> mock deriva um a partir do documento');

# O id derivado tem de ser ESTAVEL: senao o webhook PENDING e o de veredito chegariam
# com ids diferentes e o consumidor nao conseguiria parear.
$r2 = Cslabs::kycFileUploadResponse($semOnboarding, ['front' => $arquivo()]);
expect(
    $r1['body']['onboardingId'] === $r2['body']['onboardingId'],
    'id derivado do documento e estavel entre envios'
);

# Documento diferente -> id diferente.
$r3 = Cslabs::kycFileUploadResponse(
    ['documentnumber' => '66655544433', 'filetype' => 'RG'],
    ['front' => $arquivo()]
);
expect($r3['body']['onboardingId'] !== $r1['body']['onboardingId'], 'outro documento -> outro id derivado');

# 7) Validacoes que ja existiam seguem de pe (nao foram atropeladas pela cota).
$r = Cslabs::kycFileUploadResponse($form('99988877766', 'CARTEIRINHA'), ['front' => $arquivo()]);
expect(($r['error']['errorCode'] ?? '') === 'CBE014', 'filetype invalido -> CBE014');

$r = Cslabs::kycFileUploadResponse($form('99988877766'), []);
expect(($r['error']['errorCode'] ?? '') === 'CBE014', 'sem arquivo front -> CBE014');

# 7) Envio recusado por validacao NAO consome cota.
$doc2 = '11122233344';
Cslabs::kycFileUploadResponse($form($doc2, 'CARTEIRINHA'), ['front' => $arquivo()]);
for ($i = 1; $i <= Cslabs::KYC_LIMITE_ENVIOS_POR_DOCUMENTO; $i++) {
    $r = Cslabs::kycFileUploadResponse($form($doc2), ['front' => $arquivo()]);
    expect(($r['status'] ?? '') === 'SUCCESS', "envio $i apos recusa por validacao -> cota intacta");
}

echo "\nkyc_fileupload_smoke: OK\n";
