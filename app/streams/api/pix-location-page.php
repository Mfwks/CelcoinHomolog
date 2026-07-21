<?php

# GET /pixqrcode/v2/{locationId}/ver — página com o QR, o valor e o status.
#
# Não existe equivalente na Celcoin: é ferramenta de teste do mock, para abrir
# no navegador, apontar a câmera e acompanhar a cobrança virar CONCLUIDA.
# O QR vai embutido como SVG (escala sem perder nitidez, sem segunda request).

include_once __DIR__ . '/api-stream.php';

use App\Core\Cslabs;
use App\Core\QrCode;

$locationId = (string) ($web->args->locationId ?? '');
$emv = Cslabs::brcodeEmvForLocation($locationId);

header('Content-Type: text/html; charset=utf-8');

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($emv === null) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>QR não encontrado</title>'
        . '<body style="font:16px system-ui;padding:2rem"><h1>QR Code não encontrado</h1>'
        . '<p>Nenhuma cobrança para a location <code>' . $h($locationId) . '</code>.</p>';
    return;
}

$cob = Cslabs::cobPayloadForLocation($locationId);
$pago = ($cob['status'] ?? '') === 'CONCLUIDA';
$svg = QrCode::svg(QrCode::encode($emv), 6, 4);
$pix = $cob['pix'][0] ?? null;

?><!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>QR Pix — <?= $h($cob['txid'] ?? '') ?></title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.5 system-ui, sans-serif; margin: 0; padding: 2rem 1rem;
         display: flex; flex-direction: column; align-items: center; gap: 1rem; }
  .qr { background: #fff; padding: 12px; border-radius: 12px; line-height: 0;
        box-shadow: 0 2px 12px rgba(0,0,0,.15); }
  .qr svg { width: min(320px, 80vw); height: auto; }
  .valor { font-size: 2rem; font-weight: 600; }
  .status { padding: .25rem .75rem; border-radius: 999px; font-size: .875rem; font-weight: 600; }
  .ativa { background: #fef3c7; color: #92400e; }
  .concluida { background: #d1fae5; color: #065f46; }
  dl { display: grid; grid-template-columns: auto 1fr; gap: .25rem 1rem;
       font-size: .875rem; max-width: 100%; }
  dt { color: #6b7280; }
  dd { margin: 0; font-family: ui-monospace, monospace; overflow-wrap: anywhere; }
  textarea { width: min(560px, 90vw); height: 5.5rem; font-family: ui-monospace, monospace;
             font-size: .75rem; padding: .5rem; }
  .aviso { font-size: .8125rem; color: #6b7280; max-width: 46ch; text-align: center; }
</style>

<div class="qr"><?= $svg ?></div>

<div class="valor">R$ <?= $h(number_format((float) ($cob['valor']['original'] ?? 0), 2, ',', '.')) ?></div>
<span class="status <?= $pago ? 'concluida' : 'ativa' ?>"><?= $h($cob['status'] ?? '') ?></span>

<dl>
  <dt>txid</dt><dd><?= $h($cob['txid'] ?? '') ?></dd>
  <dt>chave</dt><dd><?= $h($cob['chave'] ?? '') ?></dd>
  <dt>expiração</dt><dd><?= $h($cob['calendario']['expiracao'] ?? '') ?>s</dd>
  <?php if ($pix !== null): ?>
    <dt>pago em</dt><dd><?= $h($pix['horario'] ?? '') ?></dd>
    <dt>endToEndId</dt><dd><?= $h($pix['endToEndId'] ?? '') ?></dd>
  <?php endif; ?>
</dl>

<label style="width:min(560px,90vw)">Pix copia e cola
  <textarea readonly onclick="this.select()"><?= $h($emv) ?></textarea>
</label>

<p class="aviso">
  Ambiente de simulação. Um app de banco real lê este QR, mas recusa o pagamento:
  o Pix real espera um JWS assinado na URL da cobrança, e aqui ela devolve JSON puro.
</p>

<?php if (!$pago): ?>
  <script>
    // Recarrega enquanto está ATIVA, para o status virar CONCLUIDA sozinho
    // assim que o pagamento entrar.
    setTimeout(() => location.reload(), 5000);
  </script>
<?php endif; ?>
