<?php

namespace App\Services\Imobiliario;

use App\Models\ContratoArrendamento;
use App\Models\Imovel;
use App\Services\Sistema\ServicoNumeracao;

/**
 * Numeração atómica do módulo imobiliário, sobre ServicoNumeracao (bloqueio distribuído + FOR UPDATE em
 * `sequencias_documentos`, uma sequência por empresa e série). Corrige a corrida do legado, que lia o maior código
 * existente sem bloqueio e podia dar o mesmo `PR0001` a dois pedidos em simultâneo. As séries das ondas seguintes
 * (AR, SB, AT, CP, VD, AP, OB) acrescentam-se aqui sem mudar o mecanismo.
 */
final class ServicoNumeracaoImobiliario
{
    public const SERIE_IMOVEL = 'PR';

    public function __construct(private readonly ServicoNumeracao $numeracao) {}

    /** Próximo código livre de imóvel (`PR0001`…): salta os códigos que o utilizador já escreveu à mão. */
    public function proximoCodigoImovel(int $empresaId): string
    {
        for ($i = 0; $i < 50; $i++) {
            $codigo = $this->formatar(self::SERIE_IMOVEL, $this->numeracao->proximo($empresaId, 'imo:'.self::SERIE_IMOVEL, fn () => $this->maiorImovel()));
            if (! Imovel::query()->whereRaw('lower(codigo) = ?', [mb_strtolower($codigo)])->exists()) {
                return $codigo;
            }
        }

        return $this->formatar(self::SERIE_IMOVEL, $this->numeracao->proximo($empresaId, 'imo:'.self::SERIE_IMOVEL, fn () => $this->maiorImovel()));
    }

    /** Prefixos dos contratos por tipo (AR arrendamento, SB subarrendamento, AT tomado). */
    public const PREFIXOS_CONTRATO = ['ARRENDAMENTO' => 'AR', 'SUBARRENDAMENTO' => 'SB', 'TOMADA' => 'AT'];

    /** Próximo número de contrato do ano (`AR 2026/0001`): uma sequência por empresa, prefixo e ano, sem a corrida do legado. */
    public function proximoNumeroContrato(int $empresaId, string $tipo, ?int $ano = null): string
    {
        $pref = self::PREFIXOS_CONTRATO[$tipo] ?? 'AR';
        $ano ??= (int) now()->format('Y');
        $n = $this->numeracao->proximo($empresaId, "imo:{$pref}:{$ano}", function () use ($pref, $ano) {
            $maior = 0;
            foreach (ContratoArrendamento::query()->where('numero', '~*', "^{$pref} {$ano}/[0-9]+$")->pluck('numero') as $num) {
                $maior = max($maior, (int) substr((string) $num, strlen("{$pref} {$ano}/")));
            }

            return $maior;
        });

        return sprintf('%s %d/%04d', $pref, $ano, $n);
    }

    public function formatar(string $serie, int $numero): string
    {
        return $serie.str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
    }

    /** Maior número dos códigos no padrão `PR<dígitos>` (códigos fora do padrão contam 0). */
    private function maiorImovel(): int
    {
        $maior = 0;
        foreach (Imovel::query()->where('codigo', '~*', '^PR[0-9]+$')->pluck('codigo') as $c) {
            $maior = max($maior, (int) substr((string) $c, 2));
        }

        return $maior;
    }
}
