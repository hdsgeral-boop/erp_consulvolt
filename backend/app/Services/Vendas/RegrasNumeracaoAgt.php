<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\SerieFaturacaoEletronica;

/**
 * Regras do n.º de documento e do código de série que evitam conflitos com a AGT (r3_vendas, análise das séries internas).
 *
 * SAF-T (AO) — InvoiceNo / DocumentNumber / PaymentRefNo: «<tipo> <série>/<n.º>», até 60 caracteres, com o padrão
 * `[^ ]+ [^/^ ]+/[0-9]+` (um espaço entre o tipo e a série; a série sem espaços nem «/»; n.º só com algarismos).
 * Facturação electrónica (AGT) — o documentNo enviado e o do QR são o mesmo texto; o seriesCode devolvido pela AGT é
 * alfanumérico (ServicoEnvioAgt::pedirSerie valida `^[A-Za-z0-9]{1,30}$`).
 *
 * Como o n.º do documento NÃO leva o ano em separado, o código da série tem de ser único por tipo de documento em todos
 * os anos (antes só o era por tipo+ano: uma série «X» de 2026 e outra «X» de 2027 davam ambas «FT X/1»).
 */
final class RegrasNumeracaoAgt
{
    /** Código de série: só letras e algarismos, 1 a 30 (igual à validação dos ecrãs e do seriesCode da AGT). */
    public const PADRAO_CODIGO = '/^[A-Za-z0-9]{1,30}$/';

    /** Tipo de documento: 2 a 4 letras maiúsculas (FT, FR, NC, RE, GR… e os internos PC, RCP, PAG, REC). */
    public const PADRAO_TIPO = '/^[A-Z]{2,4}$/';

    /** Padrão SAF-T (AO) do n.º de documento. */
    public const PADRAO_NUMERO = '/^[^ ]+ [^\/ ]+\/[0-9]+$/';

    public const MAX_CODIGO = 30;

    public const MAX_NUMERO = 60;

    public static function codigoValido(string $codigo): bool
    {
        return (bool) preg_match(self::PADRAO_CODIGO, $codigo);
    }

    public static function numeroValido(string $numero): bool
    {
        return strlen($numero) <= self::MAX_NUMERO && (bool) preg_match(self::PADRAO_NUMERO, $numero);
    }

    public static function numero(string $tipo, string $codigo, int $n): string
    {
        return "{$tipo} {$codigo}/{$n}";
    }

    /** Pára se o n.º não cumprir o formato SAF-T/AGT (defesa final antes de consumir o número). */
    public static function exigirNumero(string $tipo, string $codigo, int $n): string
    {
        $numero = self::numero($tipo, $codigo, $n);
        if (! preg_match(self::PADRAO_TIPO, $tipo) || ! self::codigoValido($codigo) || ! self::numeroValido($numero)) {
            throw new ErroNegocio("O n.º «{$numero}» não cumpre o formato do SAF-T/AGT (tipo, espaço, série só com letras e algarismos até 30, «/», n.º; até 60 caracteres). Feche a série e crie outra com um código válido.",
                'NUMERO_DOCUMENTO_INVALIDO', 422, ['numero' => $numero]);
        }

        return $numero;
    }

    /** Já existe outra série do mesmo tipo com este código (em qualquer ano)? */
    public static function codigoUsado(string $tipo, string $codigo, ?int $excepto = null): ?SerieFaturacaoEletronica
    {
        return SerieFaturacaoEletronica::query()->where('tipo', $tipo)->whereRaw('UPPER(codigo) = ?', [strtoupper($codigo)])
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))->orderBy('id')->first();
    }

    /**
     * Código automático de uma série nova: «A<ano>» (geral) ou «<terminal só com letras e algarismos><ano>» (POS),
     * cortado para caber em 30 caracteres; se já existir no tipo (em qualquer ano), acrescenta uma letra (B, C, … Z) e,
     * esgotadas as letras, um n.º — nunca um algarismo logo a seguir ao ano (A20262 lia-se como o ano 20262).
     */
    public static function codigoAutomatico(string $tipo, int $ano, string $origem): string
    {
        $prefixo = $origem === 'GERAL' ? 'A' : preg_replace('/[^A-Za-z0-9]/', '', $origem);
        $prefixo = $prefixo === '' ? 'T' : $prefixo;
        $base = substr($prefixo, 0, self::MAX_CODIGO - 4 - 3).$ano;   // reserva até 3 caracteres para o sufixo
        if (! self::codigoUsado($tipo, $base)) {
            return $base;
        }
        foreach (range('B', 'Z') as $letra) {
            if (! self::codigoUsado($tipo, $base.$letra)) {
                return $base.$letra;
            }
        }
        for ($i = 1; $i < 100; $i++) {
            if (! self::codigoUsado($tipo, "{$base}Z{$i}")) {
                return "{$base}Z{$i}";
            }
        }

        throw new ErroNegocio("Não foi possível gerar um código livre para a série {$tipo} {$ano}. Crie a série em Vendas › Séries.", 'SERIE_SEM_CODIGO', 422);
    }
}
