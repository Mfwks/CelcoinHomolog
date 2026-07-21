<?php

namespace App\Core;

/**
 * Encoder de QR Code em PHP puro (ISO/IEC 18004).
 *
 * Existe porque o projeto é deliberadamente zero-deps (ver CLAUDE.md) e os
 * endpoints `.../base64` devolviam um PNG de 1x1 pixel — respondiam 200 com um
 * `base64image` plausível e imagem vazia, que engana sem dar erro.
 *
 * Só modo BYTE: o BR Code é ASCII e o ganho do modo numérico não paga a
 * complexidade. Versões 1..40, níveis de correção L/M/Q/H.
 *
 * O PNG é escrito na mão (sem GD): hospedagem compartilhada nem sempre tem a
 * extensão, e a saída aqui é preto-e-branco, onde um PNG mínimo é trivial.
 * Usa `gzcompress` quando o zlib existe e cai para blocos deflate "stored"
 * quando não — assim não há dependência alguma.
 *
 * Conferido contra o `qrencode` (matriz idêntica) e lido de volta pelo
 * `zbarimg` — ver tests/qrcode_smoke.php.
 */
class QrCode
{
    public const ECC_L = 0;
    public const ECC_M = 1;
    public const ECC_Q = 2;
    public const ECC_H = 3;

    /** Codewords de correção por bloco, indexado [nivel][versao]. */
    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    /** Quantidade de blocos de correção, indexado [nivel][versao]. */
    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    /** Bits de nível no campo de formato: L=01, M=00, Q=11, H=10. */
    private const ECC_FORMAT_BITS = [1, 0, 3, 2];

    /**
     * Gera a matriz do QR. Retorna array de linhas, cada uma array de bool
     * (true = módulo escuro).
     *
     * `$forceMask` (0..7) fixa a máscara em vez de escolher pela penalidade —
     * existe para o teste conseguir comparar a matriz com a de outro encoder
     * sem que a escolha de máscara mascare (sic) uma diferença real.
     *
     * @throws \InvalidArgumentException se o dado não couber em nenhuma versão
     */
    public static function encode(string $data, int $ecc = self::ECC_M, ?int $forceMask = null): array
    {
        $version = self::chooseVersion($data, $ecc);
        $dataCodewords = self::buildDataCodewords($data, $version, $ecc);
        $allCodewords = self::addEccAndInterleave($dataCodewords, $version, $ecc);

        $size = $version * 4 + 17;
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        self::drawFunctionPatterns($modules, $isFunction, $version, $ecc);
        self::drawCodewords($modules, $isFunction, $allCodewords, $size);

        // Escolhe a máscara pela menor penalidade, como manda a norma.
        $bestMask = $forceMask ?? 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $forceMask === null && $mask < 8; $mask++) {
            $trial = $modules;
            self::applyMask($trial, $isFunction, $mask, $size);
            self::drawFormatBits($trial, $ecc, $mask, $size);
            $penalty = self::getPenaltyScore($trial, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
        }

        self::applyMask($modules, $isFunction, $bestMask, $size);
        self::drawFormatBits($modules, $ecc, $bestMask, $size);

        return $modules;
    }

    /** PNG preto-e-branco da matriz. `$scale` = pixels por módulo. */
    public static function png(array $modules, int $scale = 6, int $quiet = 4): string
    {
        $size = count($modules);
        $dim = ($size + $quiet * 2) * $scale;

        // Bitmap em tons de cinza 8 bits, 1 byte de filtro (0) por linha.
        $raw = '';
        for ($y = 0; $y < $dim; $y++) {
            $raw .= "\x00";
            $my = intdiv($y, $scale) - $quiet;
            $row = ($my >= 0 && $my < $size) ? $modules[$my] : null;
            $line = '';
            for ($x = 0; $x < $dim; $x++) {
                $mx = intdiv($x, $scale) - $quiet;
                $dark = $row !== null && $mx >= 0 && $mx < $size && $row[$mx];
                $line .= $dark ? "\x00" : "\xff";
            }
            $raw .= $line;
        }

        $ihdr = pack('NN', $dim, $dim) . "\x08\x00\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', $ihdr)
            . self::pngChunk('IDAT', self::deflate($raw))
            . self::pngChunk('IEND', '');
    }

    /** SVG da matriz — escala sem perda, útil em página HTML. */
    public static function svg(array $modules, int $scale = 6, int $quiet = 4): string
    {
        $size = count($modules);
        $dim = ($size + $quiet * 2) * $scale;

        $path = '';
        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . (($x + $quiet) * $scale) . ',' . (($y + $quiet) * $scale)
                        . 'h' . $scale . 'v' . $scale . 'h-' . $scale . 'z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" '
            . 'viewBox="0 0 ' . $dim . ' ' . $dim . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . '<path d="' . $path . '" fill="#000"/>'
            . '</svg>';
    }

    // ── Dados ────────────────────────────────────────────────────────────────

    private static function chooseVersion(string $data, int $ecc): int
    {
        $len = strlen($data);

        for ($version = 1; $version <= 40; $version++) {
            $capacityBits = self::getNumDataCodewords($version, $ecc) * 8;
            $charCountBits = $version <= 9 ? 8 : 16;
            if (4 + $charCountBits + $len * 8 <= $capacityBits) {
                return $version;
            }
        }

        throw new \InvalidArgumentException("Dado longo demais para QR Code: {$len} bytes.");
    }

    private static function buildDataCodewords(string $data, int $version, int $ecc): array
    {
        $bits = [];
        $append = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $append(0b0100, 4);                              // modo byte
        $append(strlen($data), $version <= 9 ? 8 : 16);  // contador de caracteres
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $append(ord($data[$i]), 8);
        }

        $capacityBits = self::getNumDataCodewords($version, $ecc) * 8;

        // Terminador (até 4 bits) e alinhamento a byte.
        $append(0, min(4, $capacityBits - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);

        // Preenchimento alternado 0xEC/0x11 até fechar a capacidade.
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }

        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) {
                $byte = ($byte << 1) | $bits[$i + $b];
            }
            $codewords[] = $byte;
        }

        return $codewords;
    }

    /** Divide em blocos, calcula a correção e intercala, como manda a norma. */
    private static function addEccAndInterleave(array $data, int $version, int $ecc): array
    {
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$ecc][$version];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$ecc][$version];
        $rawCodewords = intdiv(self::getNumRawDataModules($version), 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);

        $blocks = [];
        $divisor = self::reedSolomonComputeDivisor($blockEccLen);

        /*
         * Todos os blocos são alocados com o MESMO tamanho (o do bloco longo) e
         * a correção fica encostada no fim. Os blocos curtos ficam com um
         * "buraco" numa posição fixa da região de dados, que a intercalação
         * pula. Sem isso os blocos teriam tamanhos diferentes e a posição da
         * correção variaria — a intercalação sairia embaralhada.
         */
        $blockLen = $shortBlockLen + 1;

        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $datLen = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $dat = array_slice($data, $k, $datLen);
            $k += $datLen;

            $block = array_fill(0, $blockLen, 0);
            foreach ($dat as $idx => $byte) {
                $block[$idx] = $byte;
            }
            foreach (self::reedSolomonComputeRemainder($dat, $divisor) as $idx => $byte) {
                $block[$blockLen - $blockEccLen + $idx] = $byte;
            }
            $blocks[] = $block;
        }

        // Intercalação: um codeword de cada bloco, em rodadas.
        $result = [];
        for ($i = 0; $i < $blockLen; $i++) {
            for ($j = 0; $j < $numBlocks; $j++) {
                if ($i !== $shortBlockLen - $blockEccLen || $j >= $numShortBlocks) {
                    $result[] = $blocks[$j][$i];
                }
            }
        }

        return $result;
    }

    private static function reedSolomonComputeDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;

        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 0x02);
        }

        return $result;
    }

    private static function reedSolomonComputeRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $d) {
                $result[$i] ^= self::gfMultiply($d, $factor);
            }
        }

        return $result;
    }

    /** Multiplicação em GF(2^8) com polinômio 0x11D. */
    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    // ── Geometria ────────────────────────────────────────────────────────────

    private static function getNumRawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function getNumDataCodewords(int $version, int $ecc): int
    {
        return intdiv(self::getNumRawDataModules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ecc][$version]
            * self::NUM_ERROR_CORRECTION_BLOCKS[$ecc][$version];
    }

    private static function getAlignmentPatternPositions(int $version): array
    {
        if ($version === 1) {
            return [];
        }

        $numAlign = intdiv($version, 7) + 2;
        $step = ($version === 32)
            ? 26
            : intdiv($version * 4 + $numAlign * 2 + 1, $numAlign * 2 - 2) * 2;

        $result = [];
        for ($i = 0, $pos = $version * 4 + 10; $i < $numAlign - 1; $i++, $pos -= $step) {
            array_unshift($result, $pos);
        }
        array_unshift($result, 6);

        return $result;
    }

    private static function drawFunctionPatterns(array &$modules, array &$isFunction, int $version, int $ecc): void
    {
        $size = $version * 4 + 17;

        // Timing patterns.
        for ($i = 0; $i < $size; $i++) {
            self::setFunctionModule($modules, $isFunction, 6, $i, $i % 2 === 0);
            self::setFunctionModule($modules, $isFunction, $i, 6, $i % 2 === 0);
        }

        // Finders (com separador) nos três cantos.
        self::drawFinderPattern($modules, $isFunction, 3, 3, $size);
        self::drawFinderPattern($modules, $isFunction, $size - 4, 3, $size);
        self::drawFinderPattern($modules, $isFunction, 3, $size - 4, $size);

        // Alignment patterns, exceto onde colidiriam com os finders.
        $align = self::getAlignmentPatternPositions($version);
        $n = count($align);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                self::drawAlignmentPattern($modules, $isFunction, $align[$i], $align[$j]);
            }
        }

        // Reserva as áreas de formato/versão (conteúdo é escrito depois).
        self::drawFormatBits($modules, $ecc, 0, $size, $isFunction);
        self::drawVersion($modules, $isFunction, $version, $size);
    }

    private static function drawFinderPattern(array &$modules, array &$isFunction, int $x, int $y, int $size): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $size && $yy >= 0 && $yy < $size) {
                    self::setFunctionModule($modules, $isFunction, $xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private static function drawAlignmentPattern(array &$modules, array &$isFunction, int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                self::setFunctionModule($modules, $isFunction, $x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /**
     * Campo de formato: 5 bits (nível + máscara) protegidos por BCH(15,5) e
     * mascarados com 0x5412. Vai em dois lugares, por redundância.
     * Com `$isFunction` passado, só reserva a área (bits ainda não definidos).
     */
    private static function drawFormatBits(array &$modules, int $ecc, int $mask, int $size, ?array &$isFunction = null): void
    {
        $data = (self::ECC_FORMAT_BITS[$ecc] << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        $set = static function (int $x, int $y, bool $dark) use (&$modules, &$isFunction): void {
            if ($isFunction !== null) {
                $isFunction[$y][$x] = true;
            }
            $modules[$y][$x] = $dark;
        };

        // Cópia junto ao finder superior-esquerdo.
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, (($bits >> $i) & 1) !== 0);
        }
        $set(8, 7, (($bits >> 6) & 1) !== 0);
        $set(8, 8, (($bits >> 7) & 1) !== 0);
        $set(7, 8, (($bits >> 8) & 1) !== 0);
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, (($bits >> $i) & 1) !== 0);
        }

        // Segunda cópia, dividida entre os outros dois finders.
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, (($bits >> $i) & 1) !== 0);
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, (($bits >> $i) & 1) !== 0);
        }
        $set(8, $size - 8, true); // módulo escuro fixo
    }

    /** Versão 7+ carrega o número da versão em BCH(18,6), em dois blocos 3x6. */
    private static function drawVersion(array &$modules, array &$isFunction, int $version, int $size): void
    {
        if ($version < 7) {
            return;
        }

        $rem = $version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = ($version << 12) | $rem;

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            self::setFunctionModule($modules, $isFunction, $a, $b, $dark);
            self::setFunctionModule($modules, $isFunction, $b, $a, $dark);
        }
    }

    private static function setFunctionModule(array &$modules, array &$isFunction, int $x, int $y, bool $dark): void
    {
        $modules[$y][$x] = $dark;
        $isFunction[$y][$x] = true;
    }

    /** Percorre em zigue-zague de baixo para cima, pulando a coluna 6 (timing). */
    private static function drawCodewords(array &$modules, array $isFunction, array $codewords, int $size): void
    {
        $i = 0; // índice do bit
        $total = count($codewords) * 8;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = ((($right + 1) & 2) === 0);
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$isFunction[$y][$x] && $i < $total) {
                        $modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                    // Os bits restantes (remainder) ficam claros — já é o default.
                }
            }
        }
    }

    private static function applyMask(array &$modules, array $isFunction, int $mask, int $size): void
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($isFunction[$y][$x]) {
                    continue;
                }
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
                if ($invert) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }
    }

    /** Penalidades N1..N4 da norma, usadas para escolher a máscara. */
    private static function getPenaltyScore(array $modules, int $size): int
    {
        $result = 0;

        // N1: 5+ módulos iguais em sequência (linhas e colunas).
        for ($y = 0; $y < $size; $y++) {
            $runColor = false;
            $runX = 0;
            for ($x = 0; $x < $size; $x++) {
                if ($modules[$y][$x] === $runColor) {
                    $runX++;
                    if ($runX === 5) {
                        $result += 3;
                    } elseif ($runX > 5) {
                        $result++;
                    }
                } else {
                    $runColor = $modules[$y][$x];
                    $runX = 1;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $runColor = false;
            $runY = 0;
            for ($y = 0; $y < $size; $y++) {
                if ($modules[$y][$x] === $runColor) {
                    $runY++;
                    if ($runY === 5) {
                        $result += 3;
                    } elseif ($runY > 5) {
                        $result++;
                    }
                } else {
                    $runColor = $modules[$y][$x];
                    $runY = 1;
                }
            }
        }

        // N2: blocos 2x2 de cor uniforme.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $modules[$y][$x];
                if ($c === $modules[$y][$x + 1] && $c === $modules[$y + 1][$x] && $c === $modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }

        /*
         * N3: sequência 1:1:3:1:1 com 4 módulos claros de um dos lados — imita
         * um finder e confunde o leitor.
         *
         * A norma é ambígua sobre a borda do símbolo. Aqui a área FORA do
         * símbolo conta como clara (mesma leitura da implementação de
         * referência do Nayuki). Testei a alternativa — exigir janelas de 11
         * módulos inteiras dentro do símbolo — e a concordância de máscara com
         * o qrencode caiu de 51 para 45 em 64 casos, então esta ficou.
         * Em qualquer das duas o símbolo é válido: a máscara só muda a
         * distribuição visual, não o conteúdo.
         */
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $size - 7; $x++) {
                if (self::matchFinderLike($modules, $y, $x, true, $size)) {
                    $result += 40;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y <= $size - 7; $y++) {
                if (self::matchFinderLike($modules, $y, $x, false, $size)) {
                    $result += 40;
                }
            }
        }

        // N4: desvio da proporção 50% de módulos escuros.
        $dark = 0;
        foreach ($modules as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }
        $total = $size * $size;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
        $result += $k * 10;

        return $result;
    }

    /** Núcleo 1:1:3:1:1 com 4 claros antes OU depois; fora do símbolo = claro. */
    private static function matchFinderLike(array $modules, int $y, int $x, bool $horizontal, int $size): bool
    {
        $get = static function (int $i) use ($modules, $y, $x, $horizontal, $size): ?bool {
            $yy = $horizontal ? $y : $y + $i;
            $xx = $horizontal ? $x + $i : $x;
            if ($yy < 0 || $yy >= $size || $xx < 0 || $xx >= $size) {
                return null;
            }
            return $modules[$yy][$xx];
        };

        $pattern = [true, false, true, true, true, false, true];
        for ($i = 0; $i < 7; $i++) {
            if ($get($i) !== $pattern[$i]) {
                return false;
            }
        }

        $before = true;
        for ($i = -4; $i < 0; $i++) {
            if ($get($i) === true) {
                $before = false;
                break;
            }
        }
        $after = true;
        for ($i = 7; $i < 11; $i++) {
            if ($get($i) === true) {
                $after = false;
                break;
            }
        }

        return $before || $after;
    }

    // ── PNG ──────────────────────────────────────────────────────────────────

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /**
     * Fluxo zlib. Usa gzcompress quando disponível; senão monta blocos deflate
     * "stored" (sem compressão), que qualquer decodificador PNG aceita — assim
     * a classe não depende de extensão nenhuma.
     */
    private static function deflate(string $raw): string
    {
        if (function_exists('gzcompress')) {
            return gzcompress($raw, 9);
        }

        $out = "\x78\x01";
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i += 65535) {
            $chunk = substr($raw, $i, 65535);
            $n = strlen($chunk);
            $final = ($i + 65535 >= $len) ? "\x01" : "\x00";
            $out .= $final . pack('v', $n) . pack('v', ~$n & 0xFFFF) . $chunk;
        }

        // Adler-32 do conteúdo original fecha o fluxo zlib.
        $a = 1;
        $b = 0;
        for ($i = 0; $i < $len; $i++) {
            $a = ($a + ord($raw[$i])) % 65521;
            $b = ($b + $a) % 65521;
        }

        return $out . pack('N', ($b << 16) | $a);
    }
}
