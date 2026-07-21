<?php

/*
 * Smoke do encoder QR (App\Core\QrCode).
 *
 * A validação forte deste encoder foi feita contra duas ferramentas externas:
 * `qrencode` (matriz idêntica em 64 casos, versões 1..40, níveis L/M/Q/H) e
 * `zbarimg` (round-trip de 65 payloads). Nenhuma das duas pode virar dependência
 * do teste — o projeto é zero-deps e elas não existem no servidor. Então aqui
 * ficam as invariantes verificáveis em PHP puro, mais um decode PRÓPRIO da
 * matriz (desfaz máscara e lê os codewords de volta), que pega qualquer erro de
 * colocação de bits, máscara ou intercalação sem precisar de ninguém de fora.
 *
 * Se este arquivo mudar, vale repetir a conferência externa:
 *   qrencode -8 -l M -s 1 -m 0 -o ref.png "<dado>"   # comparar matriz
 *   zbarimg --quiet --raw saida.png                   # deve devolver <dado>
 */

define('TMP', __DIR__ . '/tmp_smoke/');
if (!is_dir(TMP)) {
    mkdir(TMP, 0775, true);
}

require __DIR__ . '/../app/Core/QrCode.php';

use App\Core\QrCode;

$fails = 0;
function ok(bool $c, string $m): void
{
    global $fails;
    echo ($c ? "ok: " : "FAIL: ") . "$m\n";
    if (!$c) {
        $fails++;
    }
}

/** Lê os codewords de volta da matriz: desfaz a máscara e percorre o zigue-zague. */
function decodeMatrix(array $modules): string
{
    $size = count($modules);
    $version = ($size - 17) / 4;

    // Remonta o mapa de módulos funcionais do mesmo jeito que o encoder.
    $isFunction = array_fill(0, $size, array_fill(0, $size, false));
    $mark = function (int $x, int $y) use (&$isFunction, $size): void {
        if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
            $isFunction[$y][$x] = true;
        }
    };
    for ($i = 0; $i < $size; $i++) {
        $mark(6, $i);
        $mark($i, 6);
    }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$fx, $fy]) {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $mark($fx + $dx, $fy + $dy);
            }
        }
    }
    $align = [];
    if ($version > 1) {
        $numAlign = intdiv($version, 7) + 2;
        $step = ($version === 32) ? 26 : intdiv($version * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;
        for ($i = 0, $pos = $version * 4 + 10; $i < $numAlign - 1; $i++, $pos -= $step) {
            array_unshift($align, $pos);
        }
        array_unshift($align, 6);
    }
    $n = count($align);
    for ($i = 0; $i < $n; $i++) {
        for ($j = 0; $j < $n; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                continue;
            }
            for ($dy = -2; $dy <= 2; $dy++) {
                for ($dx = -2; $dx <= 2; $dx++) {
                    $mark($align[$i] + $dx, $align[$j] + $dy);
                }
            }
        }
    }
    // Primeira cópia do campo de formato: 9 módulos em cada direção.
    for ($i = 0; $i <= 8; $i++) {
        $mark(8, $i);
        $mark($i, 8);
    }
    // Segunda cópia: só 8 — marcar 9 rouba um módulo de DADO e desalinha a
    // leitura inteira (payload curto escapa por sorte, longo quebra).
    for ($i = 0; $i < 8; $i++) {
        $mark(8, $size - 1 - $i);
        $mark($size - 1 - $i, 8);
    }
    if ($version >= 7) {
        for ($i = 0; $i < 18; $i++) {
            $mark($size - 11 + $i % 3, intdiv($i, 3));
            $mark(intdiv($i, 3), $size - 11 + $i % 3);
        }
    }

    /*
     * Máscara: vem do campo de formato, que tem 15 bits. Os 5 bits de dado
     * (nível + máscara) ficam no TOPO (bits 14..10), então é preciso ler os 15
     * — ler só os 8 primeiros devolve máscara errada.
     */
    $fmt = 0;
    for ($i = 0; $i <= 5; $i++) {
        $fmt |= ($modules[$i][8] ? 1 : 0) << $i;
    }
    $fmt |= ($modules[7][8] ? 1 : 0) << 6;
    $fmt |= ($modules[8][8] ? 1 : 0) << 7;
    $fmt |= ($modules[8][7] ? 1 : 0) << 8;
    for ($i = 9; $i < 15; $i++) {
        $fmt |= ($modules[8][14 - $i] ? 1 : 0) << $i;
    }
    $fmt ^= 0x5412;
    $mask = ($fmt >> 10) & 7;

    $bits = '';
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) {
            $right = 5;
        }
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = ((($right + 1) & 2) === 0);
                $y = $upward ? $size - 1 - $vert : $vert;
                if ($isFunction[$y][$x]) {
                    continue;
                }
                $v = $modules[$y][$x];
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                $bits .= (($invert ? !$v : $v) ? '1' : '0');
            }
        }
    }

    /*
     * Desfaz a intercalação. Sem isto só payload de bloco único volta certo —
     * e o BR Code real (~150-200 bytes) usa vários blocos, que é exatamente o
     * caso que interessa. As duas tabelas abaixo são cópias deliberadas das do
     * encoder: aqui elas servem de conferência cruzada da leitura, e foram
     * validadas contra o `qrencode` por fora.
     */
    $eccPerBlock = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];
    $numBlocksTable = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    // Nível de correção também vem do campo de formato (bits 14..13).
    $eccFormat = ($fmt >> 13) & 3;
    $eccIndex = [1 => 0, 0 => 1, 3 => 2, 2 => 3][$eccFormat];

    $rawModules = (16 * $version + 128) * $version + 64;
    if ($version >= 2) {
        $numAl = intdiv($version, 7) + 2;
        $rawModules -= (25 * $numAl - 10) * $numAl - 55;
        if ($version >= 7) {
            $rawModules -= 36;
        }
    }
    $rawCodewords = intdiv($rawModules, 8);

    $codewords = [];
    for ($i = 0; $i + 8 <= strlen($bits) && count($codewords) < $rawCodewords; $i += 8) {
        $codewords[] = bindec(substr($bits, $i, 8));
    }

    $numBlocks = $numBlocksTable[$eccIndex][$version];
    $eccLen = $eccPerBlock[$eccIndex][$version];
    $numShort = $numBlocks - $rawCodewords % $numBlocks;
    $shortLen = intdiv($rawCodewords, $numBlocks);
    $blockLen = $shortLen + 1;

    $blocks = array_fill(0, $numBlocks, array_fill(0, $blockLen, 0));
    $k = 0;
    for ($i = 0; $i < $blockLen; $i++) {
        for ($j = 0; $j < $numBlocks; $j++) {
            if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                $blocks[$j][$i] = $codewords[$k] ?? 0;
                $k++;
            }
        }
    }

    $data = '';
    for ($j = 0; $j < $numBlocks; $j++) {
        $dataLen = $shortLen - $eccLen + ($j < $numShort ? 0 : 1);
        for ($i = 0; $i < $dataLen; $i++) {
            $data .= str_pad(decbin($blocks[$j][$i]), 8, '0', STR_PAD_LEFT);
        }
    }

    $mode = bindec(substr($data, 0, 4));
    if ($mode !== 0b0100) {
        return '';
    }
    $ccBits = $version <= 9 ? 8 : 16;
    $len = bindec(substr($data, 4, $ccBits));
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $byte = substr($data, 4 + $ccBits + $i * 8, 8);
        if (strlen($byte) < 8) {
            return $out;
        }
        $out .= chr(bindec($byte));
    }

    return $out;
}

# 1) Tamanho da matriz bate com a versão escolhida
$m = QrCode::encode('HELLO WORLD', QrCode::ECC_M);
ok(count($m) === 21, 'v1: matriz 21x21 para payload curto');
ok(count($m[0]) === 21, 'v1: matriz quadrada');

# 2) Padrões de função obrigatórios
$size = count($m);
ok($m[0][0] && $m[6][6] && !$m[5][5], 'finder superior-esquerdo desenhado');
ok($m[0][$size - 1] && $m[6][$size - 7], 'finder superior-direito desenhado');
ok($m[$size - 1][0] && $m[$size - 7][6], 'finder inferior-esquerdo desenhado');
ok($m[6][8] && !$m[6][9], 'timing pattern alterna na linha 6');
ok($m[$size - 8][8] === true, 'módulo escuro fixo em (8, size-8)');

# 3) Round-trip: a matriz volta a ser o payload (pega máscara/zigue-zague/bits)
$casos = [
    ['x', QrCode::ECC_L],
    ['HELLO WORLD', QrCode::ECC_M],
    [str_repeat('A', 100), QrCode::ECC_Q],
    [str_repeat('9', 200), QrCode::ECC_H],
    ['acentuação e símbolos: R$ 1,00 / #@!', QrCode::ECC_M],
    // O BR Code dinâmico real que o mock gera.
    ['00020101021226900014br.gov.bcb.pix2568cslabs.mfwks.com/celcoin/pixqrcode/v2/95b13198c0a712a97e17e8753c2a025204000053039865802BR5912Loja Exemplo6009Sao Pedro62070503***6304FBF4', QrCode::ECC_M],
];
foreach ($casos as [$payload, $ecc]) {
    $lido = decodeMatrix(QrCode::encode($payload, $ecc));
    $rot = strlen($payload) > 28 ? substr($payload, 0, 25) . '...' : $payload;
    ok($lido === $payload, "round-trip ({$rot})");
}

# 4) Round-trip em TODAS as máscaras — se uma estiver errada, aparece aqui
foreach (range(0, 7) as $mask) {
    $lido = decodeMatrix(QrCode::encode('mascara-teste-' . $mask, QrCode::ECC_M, $mask));
    ok($lido === 'mascara-teste-' . $mask, "round-trip com máscara $mask");
}

# 5) Versões grandes (>= 7 tem bloco de versão; >= 10 muda o contador p/ 16 bits)
// Limiares conferidos contra a capacidade real em ECC_M, não chutados.
foreach ([[7, 150], [10, 200], [20, 900]] as [$vmin, $len]) {
    $payload = str_repeat('D', $len);
    $mm = QrCode::encode($payload, QrCode::ECC_M);
    $ver = (count($mm) - 17) / 4;
    ok($ver >= $vmin, "payload de {$len} bytes usa versão >= {$vmin} (usou {$ver})");
    ok(decodeMatrix($mm) === $payload, "round-trip em versão {$ver}");
}

# 6) Níveis de correção diferentes geram símbolos diferentes p/ o mesmo dado
$tamanhos = [];
foreach ([QrCode::ECC_L, QrCode::ECC_M, QrCode::ECC_Q, QrCode::ECC_H] as $ecc) {
    $tamanhos[] = count(QrCode::encode(str_repeat('Z', 120), $ecc));
}
ok($tamanhos === array_values(array_filter($tamanhos, fn($v) => $v > 0)) && $tamanhos[0] <= $tamanhos[3], 'ECC maior exige símbolo >= ao de ECC menor');

# 7) Capacidade: estourar o limite tem que dar erro claro, não QR silenciosamente errado
$estourou = false;
try {
    QrCode::encode(str_repeat('A', 3000), QrCode::ECC_H);
} catch (InvalidArgumentException $e) {
    $estourou = str_contains($e->getMessage(), 'longo demais');
}
ok($estourou, 'payload acima da capacidade lança InvalidArgumentException');

# 8) PNG: assinatura, dimensão e chunks válidos (inclusive CRC)
$png = QrCode::png($m, 4, 4);
ok(str_starts_with($png, "\x89PNG\r\n\x1a\n"), 'PNG: assinatura correta');
$w = unpack('N', substr($png, 16, 4))[1];
ok($w === (21 + 8) * 4, 'PNG: largura = (modulos + quiet zone) * escala');
ok(str_contains($png, 'IHDR') && str_contains($png, 'IDAT') && str_contains($png, 'IEND'), 'PNG: chunks presentes');
// Confere o CRC de cada chunk — um CRC errado torna o arquivo ilegível.
$pos = 8;
$crcOk = true;
while ($pos < strlen($png)) {
    $len = unpack('N', substr($png, $pos, 4))[1];
    $type = substr($png, $pos + 4, 4);
    $data = substr($png, $pos + 8, $len);
    $crc = unpack('N', substr($png, $pos + 8 + $len, 4))[1];
    if ($crc !== crc32($type . $data)) {
        $crcOk = false;
    }
    $pos += 12 + $len;
}
ok($crcOk, 'PNG: CRC de todos os chunks confere');
ok($pos === strlen($png), 'PNG: chunks cobrem o arquivo inteiro, sem sobra');

# 9) SVG bem formado
$svg = QrCode::svg($m, 4, 4);
ok(str_starts_with($svg, '<svg ') && str_ends_with($svg, '</svg>'), 'SVG: elemento raiz fechado');
ok(str_contains($svg, 'width="' . ((21 + 8) * 4) . '"'), 'SVG: dimensão correta');
ok(simplexml_load_string($svg) !== false, 'SVG: XML válido');

if ($fails > 0) {
    echo "\nqrcode smoke: $fails FALHA(S)\n";
    exit(1);
}
echo "\nqrcode smoke: OK\n";
