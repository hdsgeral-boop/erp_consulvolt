<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\TaxaCambio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Câmbios (Moedas.obterCambio, js/moedas.js:61-77 do legado): a taxa válida numa data é a última registada
 * até essa data; a taxa da empresa prevalece sobre a geral (empresa_id nulo) quando é igual ou mais recente.
 * Kz por 1 unidade da moeda. AOA = 1.
 *
 * Câmbio manual (decisão 9 do utilizador; risco M2 da análise de regras de 2026-10-02): um câmbio indicado à mão em
 * Vendas, Compras ou Tesouraria é comparado com o câmbio do dia (obter). Dentro da tolerância configurável (por omissão
 * ±5 %) é aceite; acima dela só com a permissão `cambio_manual_fora_tolerancia`, e fica registado na auditoria. Sem
 * câmbio de referência registado não há com que comparar: o câmbio é aceite e a auditoria regista-o.
 */
final class ServicoCambios
{
    public const BASE = 'AOA';

    /** Chave de configuracoes_sistema com a tolerância (percentagem) do câmbio manual face ao câmbio do dia. */
    public const CHAVE_TOLERANCIA = 'cambio_manual_tolerancia_pct';

    public const TOLERANCIA_OMISSAO = '5';

    public const PERMISSAO_FORA_TOLERANCIA = 'cambio_manual_fora_tolerancia';

    /** @return array{id: ?int, taxa: string, data: string, exata: bool, ambito: string}|null */
    public function obter(int $empresaId, string $moeda, string $data): ?array
    {
        $dia = substr($data, 0, 10);
        if ($moeda === self::BASE) {
            return ['id' => null, 'taxa' => '1', 'data' => $dia, 'exata' => true, 'ambito' => 'base'];
        }
        $procurar = fn (?int $empresa) => TaxaCambio::query()
            ->where('codigo_moeda', $moeda)->where('data_taxa', '<=', $dia)->where('taxa', '>', 0)
            ->when($empresa, fn ($q) => $q->where('empresa_id', $empresa), fn ($q) => $q->whereNull('empresa_id'))
            ->orderByDesc('data_taxa')->first();

        $daEmpresa = $procurar($empresaId);
        $geral = $procurar(null);
        $r = $daEmpresa && (! $geral || $daEmpresa->data_taxa >= $geral->data_taxa) ? $daEmpresa : $geral;
        if (! $r) {
            return null;
        }

        return ['id' => $r->id, 'taxa' => rtrim(rtrim((string) $r->taxa, '0'), '.'), 'data' => $r->data_taxa->toDateString(),
            'exata' => $r->data_taxa->toDateString() === $dia, 'ambito' => $r->empresa_id ? 'empresa' : 'todas'];
    }

    /** Tolerância (percentagem) do câmbio manual face ao câmbio do dia. */
    public function tolerancia(): string
    {
        $v = DB::table('configuracoes_sistema')->where('chave', self::CHAVE_TOLERANCIA)->value('valor');

        return is_numeric($v) ? self::normalizar((float) $v) : self::TOLERANCIA_OMISSAO;
    }

    /** Define a tolerância (0 a 100 %), com auditoria. */
    public function definirTolerancia(string|float $percentagem): string
    {
        $p = (float) $percentagem;
        if ($p < 0 || $p > 100) {
            throw new ErroNegocio('A tolerância do câmbio manual tem de estar entre 0 e 100 %.', 'TOLERANCIA_INVALIDA', 422);
        }
        $anterior = $this->tolerancia();
        $novo = self::normalizar($p);
        DB::table('configuracoes_sistema')->updateOrInsert(['chave' => self::CHAVE_TOLERANCIA], ['valor' => $novo, 'atualizado_em' => now()]);
        app(ServicoAuditoria::class)->registar('Sistema/Moedas', 'Tolerância do câmbio manual', "Tolerância alterada de {$anterior} % para {$novo} %.",
            'configuracoes_sistema', null, ['tolerancia' => $anterior], ['tolerancia' => $novo]);

        return $novo;
    }

    /**
     * Valida um câmbio manual face ao câmbio do dia. Acima da tolerância exige a permissão PERMISSAO_FORA_TOLERANCIA
     * (sem ela: 422 CAMBIO_FORA_TOLERANCIA, com a referência e o desvio); com ela, regista na auditoria.
     *
     * @param  string  $contexto  documento/operação, para a auditoria (ex.: «Vendas FT»)
     * @return array{referencia: ?string, desvio_pct: ?string, tolerancia_pct: string, fora_tolerancia: bool}
     */
    public function validarManual(int $empresaId, string $moeda, string $data, string|float $taxa, string $contexto): array
    {
        $taxa = number_format((float) $taxa, 6, '.', '');
        $tolerancia = $this->tolerancia();
        if ($moeda === self::BASE) {
            return ['referencia' => '1', 'desvio_pct' => '0', 'tolerancia_pct' => $tolerancia, 'fora_tolerancia' => false];
        }
        $ref = $this->obter($empresaId, $moeda, $data);
        if (! $ref) {
            app(ServicoAuditoria::class)->registar('Sistema/Moedas', 'Câmbio manual sem referência',
                "{$contexto}: câmbio manual de {$moeda} = {$taxa} em {$data}, sem câmbio de referência registado até essa data.", empresaId: $empresaId);

            return ['referencia' => null, 'desvio_pct' => null, 'tolerancia_pct' => $tolerancia, 'fora_tolerancia' => false];
        }
        $desvio = bcmul(bcdiv(bcsub($taxa, $ref['taxa'], 8), $ref['taxa'], 8), '100', 4);
        $fora = bccomp(ltrim($desvio, '-'), $tolerancia, 4) > 0;
        $analise = ['referencia' => $ref['taxa'], 'desvio_pct' => number_format((float) $desvio, 2, '.', ''), 'tolerancia_pct' => $tolerancia, 'fora_tolerancia' => $fora];
        if ($fora) {
            if (! Gate::any([self::PERMISSAO_FORA_TOLERANCIA])) {
                throw new ErroNegocio("O câmbio manual de {$moeda} ({$taxa}) desvia-se {$analise['desvio_pct']} % do câmbio do dia ({$ref['taxa']} em {$ref['data']}), "
                    ."acima da tolerância de {$tolerancia} %. Corrija o câmbio ou peça a quem tenha a permissão «Câmbio manual fora da tolerância».",
                    'CAMBIO_FORA_TOLERANCIA', 422, $analise + ['data_referencia' => $ref['data']]);
            }
            app(ServicoAuditoria::class)->registar('Sistema/Moedas', 'Câmbio manual fora da tolerância',
                "{$contexto}: câmbio manual de {$moeda} = {$taxa} em {$data}; câmbio do dia {$ref['taxa']} ({$ref['data']}); desvio {$analise['desvio_pct']} % "
                ."(tolerância {$tolerancia} %).", null, null, null, $analise, $empresaId);
        }

        return $analise;
    }

    private static function normalizar(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') ?: '0';
    }
}
