<?php

namespace App\Core;

/**
 * Linha digitável e código de barras de boleto — validação, conversão e emissão.
 *
 * Existe por causa de um furo de fidelidade medido em 13/08/2026: o
 * `billpayment/authorize` do mock aceitava QUALQUER string de dígitos e devolvia
 * `errorCode 000`, enquanto a Celcoin real recusa com **HTTP 400 / erro 822**
 * tudo que não seja uma linha digitável de cobrança bem-formada. Um mock que só
 * conhece o caminho feliz dá verde em bug que produção reprova — a homologação
 * ficava cega a essa classe inteira de defeito.
 *
 * ## O que foi medido (corpus `mocks-v2/`, confiapay + homologacao3)
 *
 * **9 recusas 822 reais** e **54 sucessos `000`**, e a regra separa os dois
 * conjuntos sem uma exceção:
 *
 * | `digitable` enviado                             | dígitos | DVs      | Celcoin |
 * |-------------------------------------------------|---------|----------|---------|
 * | `34196153000003255341090002071957140237236000`  | 44      | DVG ok   | **822** |
 * | `50991145200000050000000000003000000000336875`  | 44      | DVG ok   | **822** |
 * | `12345678901234567890123456789012345678901234`  | 44      | DVG ok   | **822** |
 * | `50997150300000100000000000003000000000355234`  | 44      | DVG ok   | **822** |
 * | `12345123451234512121212345121212812345678901112` | 47    | inválidos| **822** |
 * | `12345.67890 12345.678901 ...` (mascarado)      | 47      | inválidos| **822** |
 * | `5@rW(*0KJCAkI<C&^^jt'R=;:b=GT\/g!` (lixo)       | 2       | —        | **822** |
 * | `20890050091000000274083050530506314930000143106` | 47    | válidos  | `000`   |
 * | `50990000010000000000000111976007714930000001000` | 47    | válidos  | `000`   |
 * | `50990000010000300000700003613619414900000010000` | 47    | válidos  | `000`   |
 *
 * Duas leituras que só o corpus dá, e que a spec original não tinha:
 *
 * 1. **Não é só contar 44 vs 47.** Dois dos nove 822 tinham 47 dígitos — a
 *    Celcoin valida os DVs, não o comprimento. Uma implementação que só medisse
 *    o tamanho aprovaria os dois.
 * 2. **Barcode bem-formado também é recusado.** Quatro dos 822 são códigos de
 *    barras de 44 com DV geral CORRETO. A Celcoin não os converte: neste
 *    endpoint ela quer a linha digitável, ponto.
 *
 * ⚠️ Um caso não se decide com o corpus: o mascarado (`12345.67890 …`) tinha
 * DVs inválidos **também**, então não dá para saber se a máscara sozinha
 * derruba. n=0 para "47 válidos COM máscara" — por isso {@see validarCobranca()}
 * aceita máscara, que é a hipótese conservadora (recusar seria inventar recusa).
 *
 * ## Fator de vencimento: base 22/02/2025 = 1000
 *
 * A base clássica (07/10/1997) esgotou o intervalo 1000–9999 em 21/02/2025 e
 * reiniciou. Isto **não** foi assumido — foi conferido contra três respostas
 * reais: fator `1493` → 30/06/2026 e a resposta traz `dueDateRegister:
 * "2026-06-30T00:00:00"`; fator `1490` → 27/06/2026 e a resposta traz
 * `"2026-06-27T00:00:00"`. Os valores decodificados também batem (R$ 1.431,06,
 * R$ 10,00, R$ 100,00 contra os `originalValue` das mesmas respostas).
 */
final class Boleto
{
    /** Fator de vencimento 1000 = 22/02/2025 (reinício do ciclo). */
    public const BASE_FATOR = '2025-02-22';

    public static function digitos(string $valor): string
    {
        return (string) preg_replace('/\D+/', '', $valor);
    }

    /** DV de campo da linha digitável — módulo 10, pesos 2,1 da direita para a esquerda. */
    public static function dvMod10(string $campo): int
    {
        $soma = 0;
        $peso = 2;

        for ($i = strlen($campo) - 1; $i >= 0; $i--) {
            $parcela = (int) $campo[$i] * $peso;
            if ($parcela > 9) {
                $parcela -= 9;
            }
            $soma += $parcela;
            $peso = $peso === 2 ? 1 : 2;
        }

        $resto = $soma % 10;

        return $resto === 0 ? 0 : 10 - $resto;
    }

    /**
     * DV geral do código de barras — módulo 11, pesos 2..9 ciclando da direita.
     *
     * Recebe os 43 dígitos do barcode SEM o DV (posições 1-4 + 6-44).
     * Resultado 0, 10 ou 11 vira 1, como manda a FEBRABAN.
     */
    public static function dvMod11(string $barcodeSemDv): int
    {
        $pesos = [2, 3, 4, 5, 6, 7, 8, 9];
        $soma = 0;
        $i = 0;

        for ($p = strlen($barcodeSemDv) - 1; $p >= 0; $p--) {
            $soma += (int) $barcodeSemDv[$p] * $pesos[$i % 8];
            $i++;
        }

        $dv = 11 - ($soma % 11);

        return ($dv === 0 || $dv === 10 || $dv === 11) ? 1 : $dv;
    }

    /**
     * Linha digitável de cobrança (47) → código de barras (44).
     *
     * Round-trip conferido contra os três digitáveis que a Celcoin aceitou no
     * corpus: os 44 gerados aqui voltam aos 47 originais dígito a dígito.
     */
    public static function barcodeDeLinha(string $linha47): string
    {
        return substr($linha47, 0, 4)          // banco + moeda
            . $linha47[32]                      // DV geral
            . substr($linha47, 33, 14)          // fator de vencimento + valor
            . substr($linha47, 4, 5)            // campo livre, 1ª parte
            . substr($linha47, 10, 10)          // campo livre, 2ª parte
            . substr($linha47, 21, 10);         // campo livre, 3ª parte
    }

    /** Código de barras (44) → linha digitável de cobrança (47), com os DVs calculados. */
    public static function linhaDeBarcode(string $barcode44): string
    {
        $campo1 = substr($barcode44, 0, 4) . substr($barcode44, 19, 5);
        $campo2 = substr($barcode44, 24, 10);
        $campo3 = substr($barcode44, 34, 10);

        return $campo1 . self::dvMod10($campo1)
            . $campo2 . self::dvMod10($campo2)
            . $campo3 . self::dvMod10($campo3)
            . $barcode44[4]
            . substr($barcode44, 5, 14);
    }

    public static function fatorVencimento(string $vencimentoYmd): int
    {
        $base = strtotime(self::BASE_FATOR . ' 00:00:00');
        $venc = strtotime(substr($vencimentoYmd, 0, 10) . ' 00:00:00');

        if ($venc === false) {
            return 1000;
        }

        $fator = 1000 + (int) round(($venc - $base) / 86400);

        // Fora do intervalo FEBRABAN o campo não existe: boleto sem vencimento
        // usa 0000, e é isso que a Celcoin devolve como `dueDate: null`.
        return ($fator < 1000 || $fator > 9999) ? 0 : $fator;
    }

    public static function vencimentoDeFator(int $fator): ?string
    {
        if ($fator < 1000 || $fator > 9999) {
            return null;
        }

        return date('Y-m-d', strtotime(self::BASE_FATOR . ' +' . ($fator - 1000) . ' days'));
    }

    /**
     * Emite uma linha digitável de cobrança VÁLIDA que codifica valor e vencimento.
     *
     * O campo livre (25 dígitos) é derivado da semente, então a linha é estável
     * para a mesma cobrança — mas os DVs são calculados, não sorteados. Antes de
     * 13/08/2026 o mock preenchia os 47 com dígitos de um `sha256`: passava no
     * teste de comprimento e não passaria em banco nenhum.
     */
    public static function emitirLinha(string $semente, float $valor, string $vencimentoYmd, string $banco = '341'): string
    {
        $centavos = (int) round($valor * 100);
        $campoLivre = self::campoLivreDaSemente($semente);

        $barcodeSemDv = substr($banco, 0, 3) . '9'
            . str_pad((string) self::fatorVencimento($vencimentoYmd), 4, '0', STR_PAD_LEFT)
            . str_pad((string) min($centavos, 9999999999), 10, '0', STR_PAD_LEFT)
            . $campoLivre;

        $barcode = substr($barcodeSemDv, 0, 4)
            . self::dvMod11($barcodeSemDv)
            . substr($barcodeSemDv, 4);

        return self::linhaDeBarcode($barcode);
    }

    private static function campoLivreDaSemente(string $semente): string
    {
        $hash = hash('sha256', $semente);
        $digitos = '';

        for ($i = 0, $len = strlen($hash); $i < $len && strlen($digitos) < 25; $i++) {
            $char = $hash[$i];
            $digitos .= ctype_digit($char) ? $char : (string) (ord($char) % 10);
        }

        return str_pad($digitos, 25, '0');
    }

    /**
     * Diagnóstico de uma linha digitável de cobrança.
     *
     * Devolve sempre o mesmo shape, com `valida` dizendo se a Celcoin aceitaria.
     * `motivo` é para log e teste — a resposta HTTP nunca o expõe, porque a
     * Celcoin real também não: ela devolve o 822 seco, sem dizer qual DV caiu.
     *
     * @return array{valida:bool, motivo:string, digitos:string, mascarado:bool, arrecadacao:bool}
     */
    public static function validarCobranca(string $bruto): array
    {
        $digitos = self::digitos($bruto);
        $base = [
            'digitos' => $digitos,
            'mascarado' => $digitos !== trim($bruto),
            'arrecadacao' => str_starts_with($digitos, '8'),
        ];

        // Arrecadação/convênio (48 dígitos, começa com 8) tem estrutura própria e
        // ZERO ocorrências no corpus — nem sucesso nem recusa. Não se inventa
        // recusa sem medida: passa, e a lacuna está registrada em docs/scenarios.md.
        if ($base['arrecadacao']) {
            return $base + ['valida' => true, 'motivo' => 'arrecadacao_nao_medida'];
        }

        if (strlen($digitos) === 44) {
            return $base + ['valida' => false, 'motivo' => 'codigo_de_barras_44'];
        }

        if (strlen($digitos) !== 47) {
            return $base + ['valida' => false, 'motivo' => 'comprimento_' . strlen($digitos)];
        }

        $campo1 = substr($digitos, 0, 9);
        $campo2 = substr($digitos, 10, 10);
        $campo3 = substr($digitos, 21, 10);

        foreach ([[$campo1, 9, 1], [$campo2, 20, 2], [$campo3, 31, 3]] as [$campo, $posDv, $n]) {
            if ((int) $digitos[$posDv] !== self::dvMod10($campo)) {
                return $base + ['valida' => false, 'motivo' => 'dv_campo_' . $n];
            }
        }

        $barcode = self::barcodeDeLinha($digitos);
        $semDv = substr($barcode, 0, 4) . substr($barcode, 5);

        if ((int) $barcode[4] !== self::dvMod11($semDv)) {
            return $base + ['valida' => false, 'motivo' => 'dv_geral'];
        }

        return $base + ['valida' => true, 'motivo' => 'ok'];
    }
}
