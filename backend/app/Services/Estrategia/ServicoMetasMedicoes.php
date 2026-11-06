<?php

namespace App\Services\Estrategia;

use App\Exceptions\ErroNegocio;
use App\Models\IndicadorEstrategico;
use App\Models\MedicaoIndicador;
use App\Models\MetaIndicador;
use App\Models\PlanoEstrategico;
use App\Models\UnidadeOrganica;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Metas por período e medição manual (F2a), com o semáforo calculado em decimal (CalculadoraDesempenho).
 *  - Metas da EMPRESA (as das unidades são do desdobramento, onda seguinte): uma por indicador e período, o período tem de ser da
 *    frequência do indicador e estar dentro do ciclo do plano; valor vazio apaga a meta. Estrutura congelada num plano aprovado.
 *  - Medição manual: só em indicadores MANUAL e activos, em períodos já iniciados; entregar de novo SUBSTITUI o valor (a
 *    auditoria guarda o anterior); não há apagar. Livre até ao encerramento do plano.
 */
final class ServicoMetasMedicoes
{
    public function __construct(private readonly ServicoPlanosEstrategicos $planos) {}

    /**
     * Metas gravadas + a grelha de períodos de cada ano do ciclo, para o ecrã.
     *
     * @return array<string, mixed>
     */
    public function metas(int $indicadorId): array
    {
        $i = IndicadorEstrategico::query()->findOrFail($indicadorId);
        $plano = $this->planos->obter($i->plano_estrategico_id);
        $oculto = PoliticaFinanceira::oculta($i->formato, $i->origem);
        $metas = MetaIndicador::query()->where('indicador_id', $i->id)->orderBy('periodo')->get()
            ->map(fn (MetaIndicador $m) => ['periodo' => $m->periodo, 'valor' => $oculto ? null : $m->valor, 'valor_minimo' => $oculto ? null : $m->valor_minimo, 'nota' => $m->nota])->all();
        $anos = [];
        for ($a = $plano->ano_inicio; $a <= $plano->ano_fim; $a++) {
            $anos[] = ['ano' => $a, 'periodos' => array_map(fn ($p) => ['chave' => $p, 'rotulo' => Periodos::rotulo($p)], Periodos::periodosDe($i->frequencia, $a))];
        }

        return ['indicador_id' => $i->id, 'frequencia' => $i->frequencia, 'polaridade' => $i->polaridade, 'formato' => $i->formato, 'oculto' => $oculto, 'anos' => $anos, 'metas' => $metas];
    }

    /**
     * @param  list<array<string, mixed>>  $metas  {periodo, valor|null, valor_minimo?, nota?}
     * @return array<string, mixed>
     */
    public function gravarMetas(int $indicadorId, array $metas, ?string $motivo): array
    {
        DB::transaction(function () use ($indicadorId, $metas, $motivo) {
            $i = IndicadorEstrategico::query()->findOrFail($indicadorId);
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($i->plano_estrategico_id);
            $this->planos->exigirEditavel($plano, $motivo, "Metas do indicador «{$i->nome}» alteradas.");
            foreach ($metas as $m) {
                $periodo = (string) ($m['periodo'] ?? '');
                $this->validarPeriodo($i, $plano, $periodo);
                $valor = CalculadoraDesempenho::numero($m['valor'] ?? null);
                $existente = MetaIndicador::query()->where('indicador_id', $i->id)->where('periodo', $periodo)->first();
                if ($valor === null) {
                    $existente?->delete();

                    continue;
                }
                $minimo = CalculadoraDesempenho::numero($m['valor_minimo'] ?? null);
                $nota = ($t = trim((string) ($m['nota'] ?? ''))) === '' ? null : mb_substr($t, 0, 300);
                if ($existente === null) {
                    MetaIndicador::query()->create(['indicador_id' => $i->id, 'periodo' => $periodo, 'valor' => $valor, 'valor_minimo' => $minimo, 'nota' => $nota, 'criado_por' => $this->operador()]);
                } else {
                    $existente->fill(['valor' => $valor, 'valor_minimo' => $minimo, 'nota' => $nota, 'atualizado_por' => $this->operador()])->save();
                }
            }
            $this->planos->tocar($plano);
        });

        return $this->metas($indicadorId);
    }

    /**
     * Entrega do valor de um indicador MANUAL num período. Devolve a linha da série (valor, meta, semáforo).
     *
     * @return array<string, mixed>
     */
    public function entregar(int $indicadorId, string $periodo, mixed $valor, ?string $nota, ?int $unidadeId): array
    {
        return DB::transaction(function () use ($indicadorId, $periodo, $valor, $nota, $unidadeId) {
            $i = IndicadorEstrategico::query()->findOrFail($indicadorId);
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($i->plano_estrategico_id);
            $this->planos->exigirNaoEncerrado($plano);
            if ($i->origem !== 'MANUAL') {
                throw new ErroNegocio('Este indicador mede-se sozinho: o valor não se escreve à mão.', 'INDICADOR_NAO_MANUAL');
            }
            if (! $i->ativo) {
                throw new ErroNegocio('O indicador está inactivo: reactive-o para lhe entregar valores.', 'INDICADOR_INACTIVO');
            }
            $this->validarPeriodo($i, $plano, $periodo);
            if (Periodos::intervalo($periodo)[0] > now()->toDateString()) {
                throw new ErroNegocio('Esse período ainda não começou: só se entregam valores de períodos já iniciados.', 'PERIODO_FUTURO');
            }
            $v = CalculadoraDesempenho::numero($valor);
            if ($v === null) {
                throw new ErroNegocio('Indique o valor medido (um número).', 'VALOR_INVALIDO');
            }
            if ($unidadeId !== null && ! UnidadeOrganica::query()->whereKey($unidadeId)->exists()) {
                throw new ErroNegocio('A unidade escolhida não existe nesta empresa.', 'UNIDADE_INVALIDA');
            }
            $nota = ($t = trim((string) $nota)) === '' ? null : mb_substr($t, 0, 300);
            $med = MedicaoIndicador::query()->where('indicador_id', $i->id)->where('periodo', $periodo)
                ->when($unidadeId === null, fn ($q) => $q->whereNull('unidade_organica_id'), fn ($q) => $q->where('unidade_organica_id', $unidadeId))->lockForUpdate()->first();
            $dados = ['valor' => $v, 'origem' => 'MANUAL', 'nota' => $nota, 'detalhe' => null, 'medido_em' => now(), 'medido_por' => $this->operador()];
            if ($med === null) {
                MedicaoIndicador::query()->create($dados + ['indicador_id' => $i->id, 'periodo' => $periodo, 'unidade_organica_id' => $unidadeId]);
            } else {
                $med->fill($dados)->save();
            }

            return $this->linha($i, $periodo, $unidadeId);
        });
    }

    /**
     * A série do indicador num ano do ciclo: um registo por período da frequência (meta, valor, semáforo, cumprimento).
     *
     * @return array<string, mixed>
     */
    public function serie(int $indicadorId, ?int $ano): array
    {
        $i = IndicadorEstrategico::query()->findOrFail($indicadorId);
        $plano = $this->planos->obter($i->plano_estrategico_id);
        $ano ??= max($plano->ano_inicio, min($plano->ano_fim, (int) now()->format('Y')));
        if ($ano < $plano->ano_inicio || $ano > $plano->ano_fim) {
            throw new ErroNegocio("O ano tem de estar dentro do ciclo do plano ({$plano->ano_inicio}-{$plano->ano_fim}).", 'ANO_FORA_DO_CICLO');
        }
        $periodos = Periodos::periodosDe($i->frequencia, $ano);
        $metas = MetaIndicador::query()->where('indicador_id', $i->id)->whereIn('periodo', $periodos)->get()->keyBy('periodo');
        $medicoes = MedicaoIndicador::query()->where('indicador_id', $i->id)->whereNull('unidade_organica_id')->whereIn('periodo', $periodos)->get()->keyBy('periodo');
        $oculto = PoliticaFinanceira::oculta($i->formato, $i->origem);
        $linhas = array_map(fn (string $p) => $this->montarLinha($i, $p, $metas->get($p), $medicoes->get($p), $oculto), $periodos);

        return ['indicador_id' => $i->id, 'ano' => $ano, 'frequencia' => $i->frequencia, 'formato' => $i->formato, 'polaridade' => $i->polaridade, 'oculto' => $oculto, 'periodos' => $linhas,
            'resumo' => CalculadoraDesempenho::resumo(array_column($linhas, 'semaforo'))];
    }

    /** @return array<string, mixed> */
    private function linha(IndicadorEstrategico $i, string $periodo, ?int $unidadeId): array
    {
        $meta = MetaIndicador::query()->where('indicador_id', $i->id)->where('periodo', $periodo)->first();
        $med = MedicaoIndicador::query()->where('indicador_id', $i->id)->where('periodo', $periodo)
            ->when($unidadeId === null, fn ($q) => $q->whereNull('unidade_organica_id'), fn ($q) => $q->where('unidade_organica_id', $unidadeId))->first();

        return $this->montarLinha($i, $periodo, $meta, $med, PoliticaFinanceira::oculta($i->formato, $i->origem));
    }

    /** @return array<string, mixed> */
    private function montarLinha(IndicadorEstrategico $i, string $periodo, ?MetaIndicador $meta, ?MedicaoIndicador $med, bool $oculto): array
    {
        $semaforo = CalculadoraDesempenho::semaforo($i->polaridade, $med?->valor, $meta?->valor, $i->tolerancia_verde, $i->tolerancia_amarela);
        $cumprimento = $meta !== null && $med !== null ? CalculadoraDesempenho::cumprimento($i->polaridade, $med->valor, $meta->valor) : null;

        return [
            'periodo' => $periodo, 'rotulo' => Periodos::rotulo($periodo), 'meta' => $oculto ? null : $meta?->valor, 'valor' => $oculto ? null : $med?->valor,
            'cumprimento' => $oculto ? null : $cumprimento, 'semaforo' => $semaforo, 'semaforo_rotulo' => Vocabulario::SEMAFOROS[$semaforo], 'oculto' => $oculto,
            'nota' => $med?->nota, 'medido_por' => $med?->medido_por, 'medido_em' => $med?->medido_em?->toIso8601String(),
        ];
    }

    private function validarPeriodo(IndicadorEstrategico $i, PlanoEstrategico $plano, string $periodo): void
    {
        if (! Periodos::valido($periodo) || Periodos::frequenciaDe($periodo) !== $i->frequencia) {
            throw new ErroNegocio('O período «'.$periodo.'» não corresponde à frequência do indicador ('.Vocabulario::FREQUENCIAS[$i->frequencia].').', 'PERIODO_INVALIDO');
        }
        if (! Periodos::noCiclo($periodo, $plano->ano_inicio, $plano->ano_fim)) {
            throw new ErroNegocio("O período «{$periodo}» está fora do ciclo do plano ({$plano->ano_inicio}-{$plano->ano_fim}).", 'PERIODO_FORA_DO_CICLO');
        }
    }

    private function operador(): ?string
    {
        return Auth::user()?->nome_utilizador;
    }
}
