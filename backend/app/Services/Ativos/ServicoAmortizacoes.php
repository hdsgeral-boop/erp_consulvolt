<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AmortizacaoAtivo;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Amortizações mensais (js/ui_assets.js:1269-2380, 3087-3273): cálculo em rascunho, quota manual, meses pendentes,
 * integração no diário AM e reabertura. Regra de cálculo em CalculadoraAmortizacoes.
 * Lançamento de um período (executeAmortizationPosting, ui_assets.js:1472-1586): diário AM «Amortizações de Imobilizados»,
 * documento "AM-MM-AAAA" datado do último dia do mês; linhas agrupadas por conta, unidade de negócio e centro de custo do
 * activo: D conta de gasto (73, nota 29) / C amortização acumulada (18, nota 4) da categoria.
 * Correcções:
 *   - reabrir um período integrado = estorno dos lançamentos (ADR-016); o legado apagava as linhas do diário;
 *   - a integração individual de um activo (executeSingleAmortizationPosting) gerava o débito e o crédito com números de
 *     lançamento diferentes (dois lançamentos desequilibrados por mês, ui_assets.js:1690-1721); agora um lançamento
 *     equilibrado por período;
 *   - no cálculo de vários períodos seguidos o legado limitava cada quota pelo acumulado CONTABILIZADO (os rascunhos dos meses
 *     anteriores da mesma execução não contavam) e podia amortizar acima da base; agora conta todas as outras quotas;
 *   - sem contas na categoria o legado lançava em «73.1»/«18.1» (inexistentes); agora recusa com a categoria em falta;
 *   - quota manual limitada ao valor por amortizar e ao período de vida útil; editar uma quota JÁ INTEGRADA (no mapa ou no
 *     «recálculo global», ui_assets.js:3087-3229) alterava as linhas do diário no lugar: agora exige reabrir o período, e o
 *     recálculo global passa a verificação só de leitura (ADR-015);
 *   - rascunhos que deixaram de ser devidos (quota esgotada, fora da vida útil) são retirados ao recalcular — o legado
 *     deixava-os ficar e integrava-os;
 *   - meses pendentes: um activo já totalmente amortizado deixa de aparecer como pendente para sempre (checkMissingAmortizations
 *     não via o valor por amortizar, ui_assets.js:1932-1973);
 *   - quotas ao cêntimo com arredondamento exacto half-up (ADR-022): o legado multiplicava por 1/vida em vírgula flutuante e
 *     arredondava para baixo as quotas com meio cêntimo exacto (9 dos 1 448 registos migrados, 1 cêntimo cada; ADR-051);
 *   - tudo numa transacção, com bloqueio dos activos (sem duplicados por activo e período).
 */
final class ServicoAmortizacoes
{
    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoAtivos $ativos,
    ) {}

    // ───────────── Consulta ─────────────

    /** Vista de um período (renderAmortizationView): activos elegíveis, quota (rascunho ou calculada) e estado. */
    public function periodo(string $periodo): array
    {
        [$ano, $mes] = $this->exigirPeriodo($periodo);
        $registos = AmortizacaoAtivo::query()->get()->groupBy('ativo_imobilizado_id');
        $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');
        $linhas = [];
        foreach (AtivoImobilizado::query()->with('centroCusto:id,codigo', 'unidadeNegocio:id,codigo')->orderBy('codigo')->get() as $a) {
            $doAtivo = $registos[$a->id] ?? collect();
            $reg = $doAtivo->firstWhere('periodo_codigo', $periodo);
            $acumulado = $this->acumuladoSem($a, $doAtivo, $periodo);
            $calculada = $a->estado === AtivoImobilizado::ESTADO_ATIVO ? CalculadoraAmortizacoes::devida($a, $cats[$a->categoria_ativo_id] ?? null, $ano, $mes, $acumulado) : null;
            if (! $reg && $calculada === null) {
                continue;
            }
            $linhas[] = [
                'ativo_imobilizado_id' => $a->id, 'codigo' => $a->codigo, 'descricao' => $a->descricao, 'categoria' => $cats[$a->categoria_ativo_id]->nome ?? null,
                'centro_custo' => $a->centroCusto?->codigo, 'unidade_negocio' => $a->unidadeNegocio?->codigo, 'valor_aquisicao' => CalculadoraAmortizacoes::d($a->valor_aquisicao),
                'acumulado_anterior' => $acumulado, 'quota' => $reg ? CalculadoraAmortizacoes::d($reg->valor) : $calculada, 'quota_calculada' => $calculada,
                'estado' => $reg ? ($reg->contabilizado ? 'INTEGRADO' : 'RASCUNHO') : 'POR_CALCULAR', 'amortizacao_id' => $reg?->id,
            ];
        }
        $estados = array_count_values(array_column($linhas, 'estado'));

        return ['periodo' => $periodo, 'data' => $this->ultimoDia($ano, $mes), 'estado' => $this->estadoPeriodo($estados),
            'totais' => ['integrado' => $this->soma($linhas, 'INTEGRADO'), 'rascunho' => $this->soma($linhas, 'RASCUNHO'), 'por_calcular' => $this->soma($linhas, 'POR_CALCULAR')],
            'contagens' => $estados, 'linhas' => $linhas];
    }

    /**
     * Meses pendentes (checkMissingAmortizations / showBulkAmortizationProcessor / showBulkIntegrationPreview): meses fechados
     * (até ao mês anterior a $referencia) sem quota calculada para activos em uso, e períodos com rascunhos por integrar.
     */
    public function pendentes(?string $referencia = null): array
    {
        $ultimo = (int) date('Y', strtotime($referencia ?? 'now')) * 12 + (int) date('n', strtotime($referencia ?? 'now')) - 2;
        $porCalcular = [];
        foreach ($this->mesesPorCalcular($ultimo) as $x) {
            $porCalcular[$x['periodo']] ??= ['periodo' => $x['periodo'], 'ativos' => 0, 'valor' => '0.00'];
            $porCalcular[$x['periodo']]['ativos']++;
            $porCalcular[$x['periodo']]['valor'] = bcadd($porCalcular[$x['periodo']]['valor'], $x['quota'], 2);
        }
        $rascunhos = AmortizacaoAtivo::query()->where('contabilizado', false)->get()->groupBy('periodo_codigo')
            ->map(fn ($g, $p) => ['periodo' => $p, 'ativos' => $g->count(), 'valor' => $this->somaValores($g)]);

        return [
            'por_calcular' => collect($porCalcular)->sortBy(fn ($x) => CalculadoraAmortizacoes::ordem($x['periodo']))->values()->all(),
            'por_integrar' => $rascunhos->sortBy(fn ($x) => CalculadoraAmortizacoes::ordem($x['periodo']))->values()->all(),
        ];
    }

    /**
     * Meses devidos sem quota calculada até ao mês de ordem $ultimo (ano × 12 + mês − 1), com a quota que seria calculada
     * (o acumulado avança mês a mês, para não listar meses para lá da base).
     *
     * @return list<array{ativo_imobilizado_id: int, codigo: string, periodo: string, quota: string}>
     */
    public function mesesPorCalcular(int $ultimo, ?Collection $ativos = null): array
    {
        $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');
        $ativos ??= AtivoImobilizado::query()->where('estado', AtivoImobilizado::ESTADO_ATIVO)->orderBy('codigo')->get();
        $registos = AmortizacaoAtivo::query()->whereIn('ativo_imobilizado_id', $ativos->pluck('id'))->get()->groupBy('ativo_imobilizado_id');
        $saida = [];
        foreach ($ativos as $a) {
            if (! $a->data_aquisicao) {
                continue;
            }
            $doAtivo = ($registos[$a->id] ?? collect())->keyBy('periodo_codigo');
            $acumulado = bcadd(CalculadoraAmortizacoes::d($a->amortizacao_acumulada_inicial), $this->somaValores($doAtivo), 2);
            $inicio = (int) $a->data_aquisicao->format('Y') * 12 + (int) $a->data_aquisicao->format('n') - 1;
            for ($n = $inicio; $n <= $ultimo; $n++) {
                $p = CalculadoraAmortizacoes::codigo(intdiv($n, 12), $n % 12 + 1);
                if (isset($doAtivo[$p])) {
                    continue;
                }
                $q = CalculadoraAmortizacoes::devida($a, $cats[$a->categoria_ativo_id] ?? null, intdiv($n, 12), $n % 12 + 1, $acumulado);
                if ($q === null) {
                    continue;
                }
                $acumulado = bcadd($acumulado, $q, 2);
                $saida[] = ['ativo_imobilizado_id' => $a->id, 'codigo' => $a->codigo, 'periodo' => $p, 'quota' => $q];
            }
        }

        return $saida;
    }

    /**
     * Gancho para o fecho do exercício (ui_closing.js:1436-1461): activos em uso com meses do ano devidos e não integrados
     * (sem quota ou em rascunho). O legado nunca detectava nada: filtrava o estado 'ACTIVE' (os activos têm 'ACTIVO') e
     * procurava períodos "AAAA-MM" (os períodos são "MM-AAAA").
     *
     * @return list<array{ativo_imobilizado_id: int, codigo: string, periodos: list<string>}>
     */
    public function porIntegrarNoAno(int $ano): array
    {
        $saida = [];
        foreach ($this->mesesPorCalcular($ano * 12 + 11) as $x) {
            if (str_ends_with($x['periodo'], "-{$ano}")) {
                $saida[$x['ativo_imobilizado_id']] ??= ['ativo_imobilizado_id' => $x['ativo_imobilizado_id'], 'codigo' => $x['codigo'], 'periodos' => []];
                $saida[$x['ativo_imobilizado_id']]['periodos'][] = $x['periodo'];
            }
        }
        foreach (AmortizacaoAtivo::query()->where('contabilizado', false)->where('periodo_codigo', 'like', "%-{$ano}")->with('ativoImobilizado:id,codigo')->get() as $r) {
            $saida[$r->ativo_imobilizado_id] ??= ['ativo_imobilizado_id' => $r->ativo_imobilizado_id, 'codigo' => $r->ativoImobilizado?->codigo, 'periodos' => []];
            $saida[$r->ativo_imobilizado_id]['periodos'][] = $r->periodo_codigo;
        }

        return array_values($saida);
    }

    /** Pré-visualização da integração de um período (previewAmortizationIntegration): movimentos e linhas agrupadas. */
    public function previsualizar(string $periodo): array
    {
        $this->exigirPeriodo($periodo);
        $rascunhos = AmortizacaoAtivo::query()->where('periodo_codigo', $periodo)->where('contabilizado', false)->with('ativoImobilizado')->orderBy('id')->get();
        if ($rascunhos->isEmpty()) {
            throw new ErroNegocio("Não existem cálculos pendentes para integrar em {$periodo}.", 'SEM_RASCUNHOS', 422);
        }
        [$movimentos, $linhas] = $this->linhas($rascunhos);

        return ['periodo' => $periodo, 'numero_documento' => AmortizacaoAtivo::numeroDocumento($periodo), 'movimentos' => $movimentos, 'linhas' => $linhas,
            'total' => $this->somaValores($rascunhos)];
    }

    // ───────────── Cálculo ─────────────

    /**
     * Calcula (ou recalcula) em rascunho os períodos indicados, por ordem cronológica. Os rascunhos existentes mantêm o valor
     * (podem ser quotas manuais, como no legado), limitado ao valor por amortizar; as quotas integradas não mudam.
     *
     * @return list<array{periodo: string, criados: int, actualizados: int, retirados: int, total: string}>
     */
    public function calcular(array $periodos): array
    {
        $periodos = $this->ordenar($periodos);

        return DB::transaction(function () use ($periodos) {
            $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');
            $ativos = AtivoImobilizado::query()->where('estado', AtivoImobilizado::ESTADO_ATIVO)->orderBy('id')->lockForUpdate()->get();
            $registos = AmortizacaoAtivo::query()->whereIn('ativo_imobilizado_id', $ativos->pluck('id'))->get()->groupBy('ativo_imobilizado_id')
                ->map(fn ($g) => $g->keyBy('periodo_codigo'));
            $saida = [];
            foreach ($periodos as $periodo) {
                [$ano, $mes] = CalculadoraAmortizacoes::periodo($periodo);
                $r = ['periodo' => $periodo, 'criados' => 0, 'actualizados' => 0, 'retirados' => 0, 'total' => '0.00'];
                foreach ($ativos as $a) {
                    $doAtivo = $registos[$a->id] ?? collect();
                    $reg = $doAtivo[$periodo] ?? null;
                    if ($reg?->contabilizado) {
                        continue;
                    }
                    $q = CalculadoraAmortizacoes::devida($a, $cats[$a->categoria_ativo_id] ?? null, $ano, $mes, $this->acumuladoSem($a, $doAtivo, $periodo),
                        $reg ? CalculadoraAmortizacoes::d($reg->valor) : null);
                    if ($q === null) {
                        if ($reg) {
                            $reg->delete();
                            $doAtivo->forget($periodo);
                            $r['retirados']++;
                        }

                        continue;
                    }
                    if ($reg) {
                        if (bccomp(CalculadoraAmortizacoes::d($reg->valor), $q, 2) !== 0) {
                            $reg->update(['valor' => $q]);
                        }
                        $r['actualizados']++;
                    } else {
                        $reg = AmortizacaoAtivo::create(['ativo_imobilizado_id' => $a->id, 'periodo_codigo' => $periodo, 'contabilizado' => false, 'valor' => $q,
                            'data' => $this->ultimoDia($ano, $mes)]);
                        $r['criados']++;
                    }
                    $doAtivo[$periodo] = $reg;
                    $registos[$a->id] = $doAtivo;
                    $r['total'] = bcadd($r['total'], $q, 2);
                }
                $saida[] = $r;
            }

            return $saida;
        });
    }

    /** Quota manual em rascunho (setManualAmortizationValue / promptEditAmortization em quotas não integradas); 0 retira o rascunho. */
    public function definirQuota(AtivoImobilizado $a, string $periodo, string $valor): ?AmortizacaoAtivo
    {
        [$ano, $mes] = $this->exigirPeriodo($periodo);
        $valor = CalculadoraAmortizacoes::d($valor);
        if (bccomp($valor, '0', 2) < 0) {
            throw new ErroNegocio('Valor inválido.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($a, $periodo, $ano, $mes, $valor) {
            $a = AtivoImobilizado::query()->lockForUpdate()->findOrFail($a->id);
            $doAtivo = AmortizacaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->get()->keyBy('periodo_codigo');
            $reg = $doAtivo[$periodo] ?? null;
            if ($reg?->contabilizado) {
                throw new ErroNegocio("A quota de {$periodo} já está integrada na contabilidade: reabra o período para a alterar.", 'QUOTA_INTEGRADA', 422);
            }
            if (bccomp($valor, '0', 2) === 0) {
                $reg?->delete();

                return null;
            }
            if ($a->estado !== AtivoImobilizado::ESTADO_ATIVO) {
                throw new ErroNegocio("O activo {$a->codigo} não está activo.", 'ATIVO_NAO_ATIVO', 422);
            }
            if (! CalculadoraAmortizacoes::noPeriodoDeVida($a, $ano, $mes)) {
                throw new ErroNegocio("{$periodo} está fora do período de amortização do activo {$a->codigo} (aquisição, vida útil ou amortização inicial).", 'FORA_DO_PERIODO', 422);
            }
            $restante = bcsub(CalculadoraAmortizacoes::base($a), $this->acumuladoSem($a, $doAtivo, $periodo), 2);
            if (bccomp($valor, $restante, 2) > 0) {
                throw new ErroNegocio("A quota excede o valor por amortizar do activo ({$restante}).", 'QUOTA_EXCEDE_BASE', 422, ['restante' => $restante]);
            }
            if ($reg) {
                $reg->update(['valor' => $valor]);

                return $reg->refresh();
            }

            return AmortizacaoAtivo::create(['ativo_imobilizado_id' => $a->id, 'periodo_codigo' => $periodo, 'contabilizado' => false, 'valor' => $valor,
                'data' => $this->ultimoDia($ano, $mes)]);
        });
    }

    // ───────────── Integração ─────────────

    /**
     * Integra na contabilidade os rascunhos dos períodos (executeAmortizationPosting / executeBulkIntegration): um lançamento
     * por período, tudo ou nada.
     *
     * @return list<array{periodo: string, numero_lan: string, ativos: int, total: string}>
     */
    public function integrar(array $periodos): array
    {
        $periodos = $this->ordenar($periodos);

        return DB::transaction(function () use ($periodos) {
            $saida = [];
            foreach ($periodos as $periodo) {
                $rascunhos = AmortizacaoAtivo::query()->where('periodo_codigo', $periodo)->where('contabilizado', false)->lockForUpdate()->with('ativoImobilizado')->orderBy('id')->get();
                if ($rascunhos->isEmpty()) {
                    throw new ErroNegocio("Não existem cálculos pendentes para integrar em {$periodo}.", 'SEM_RASCUNHOS', 422, ['periodo' => $periodo]);
                }
                $saida[] = $this->lancarPeriodo($periodo, $rascunhos, "Amortizações do período {$periodo}");
            }

            return $saida;
        });
    }

    /**
     * Integra todos os meses devidos e não integrados de um activo até $ate (executeSingleAmortizationPosting): usa os rascunhos
     * existentes ou calcula; um lançamento equilibrado por período.
     *
     * @return list<array{periodo: string, numero_lan: string, ativos: int, total: string}>
     */
    public function integrarAtivo(AtivoImobilizado $a, string $ate): array
    {
        [$anoAte, $mesAte] = $this->exigirPeriodo($ate);

        return DB::transaction(function () use ($a, $anoAte, $mesAte, $ate) {
            $a = AtivoImobilizado::query()->lockForUpdate()->findOrFail($a->id);
            if ($a->estado !== AtivoImobilizado::ESTADO_ATIVO) {
                throw new ErroNegocio("O activo {$a->codigo} não está activo.", 'ATIVO_NAO_ATIVO', 422);
            }
            foreach ($this->mesesPorCalcular($anoAte * 12 + $mesAte - 1, collect([$a])) as $x) {
                $this->calcularAtivo($a, $x['periodo']);
            }
            $rascunhos = AmortizacaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->where('contabilizado', false)->lockForUpdate()->with('ativoImobilizado')->get()
                ->filter(fn ($r) => CalculadoraAmortizacoes::ordem($r->periodo_codigo) <= CalculadoraAmortizacoes::ordem($ate))
                ->sortBy(fn ($r) => CalculadoraAmortizacoes::ordem($r->periodo_codigo));
            if ($rascunhos->isEmpty()) {
                throw new ErroNegocio("Não há períodos pendentes para integrar até {$ate}.", 'SEM_RASCUNHOS', 422);
            }

            return $rascunhos->groupBy('periodo_codigo')->map(fn ($g, $p) => $this->lancarPeriodo($p, $g, "Amortização {$a->codigo} do período {$p}"))->values()->all();
        });
    }

    /**
     * Reabre (anula) períodos (cancelAmortizationPeriod / executeBulkReopenAmortizations): estorna os lançamentos do período e
     * retira as quotas (rascunhos e integradas), que podem ser recalculadas.
     *
     * @return list<array{periodo: string, retirados: int, estornos: list<string>}>
     */
    public function reabrir(array $periodos, ?string $motivo = null): array
    {
        $periodos = $this->ordenar($periodos);

        return DB::transaction(function () use ($periodos, $motivo) {
            $saida = [];
            foreach (array_reverse($periodos) as $periodo) {
                $regs = AmortizacaoAtivo::query()->where('periodo_codigo', $periodo)->lockForUpdate()->with('ativoImobilizado')->get();
                if ($regs->isEmpty()) {
                    throw new ErroNegocio("O período {$periodo} não tem amortizações.", 'PERIODO_SEM_AMORTIZACOES', 422, ['periodo' => $periodo]);
                }
                $abatidos = $regs->filter(fn ($r) => $r->contabilizado && $r->ativoImobilizado?->estado === AtivoImobilizado::ESTADO_ABATIDO);
                if ($abatidos->isNotEmpty()) {
                    throw new ErroNegocio("Há activos abatidos com quotas integradas em {$periodo}: anule primeiro o abate.", 'ATIVO_ABATIDO', 422,
                        ['ativos' => $abatidos->map(fn ($r) => $r->ativoImobilizado->codigo)->values()->all()]);
                }
                $estornos = [];
                if ($regs->contains('contabilizado', true)) {
                    foreach ($this->lancamentosDoPeriodo($periodo) as $linha) {
                        $estornos[] = $this->lancamentos->estornar($linha, $motivo ?: "Reabertura das amortizações de {$periodo}")->first()->numero_lan;
                    }
                }
                AmortizacaoAtivo::query()->whereIn('id', $regs->pluck('id'))->delete();
                foreach ($regs->pluck('ativoImobilizado')->filter()->unique('id') as $a) {
                    $this->ativos->recalcularAcumulado($a);
                }
                $saida[] = ['periodo' => $periodo, 'retirados' => $regs->count(), 'estornos' => $estornos];
            }

            return $saida;
        });
    }

    /**
     * Verificação do recálculo global (recalculateAllAmortizations, ui_assets.js:3150-3229), só de leitura: quotas registadas que
     * diferem da regra de cálculo (acumulado corrido desde a amortização inicial, por ordem dos períodos) e acumulados da ficha
     * que diferem de inicial + quotas integradas. O legado corrigia no lugar os registos e as linhas do diário.
     */
    public function verificar(): array
    {
        $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');
        $registos = AmortizacaoAtivo::query()->get()->groupBy('ativo_imobilizado_id');
        $quotas = $acumulados = [];
        $n = 0;
        foreach (AtivoImobilizado::query()->orderBy('codigo')->get() as $a) {
            $acumulado = CalculadoraAmortizacoes::d($a->amortizacao_acumulada_inicial);
            $integrado = $acumulado;
            foreach (($registos[$a->id] ?? collect())->sortBy(fn ($r) => CalculadoraAmortizacoes::ordem($r->periodo_codigo)) as $r) {
                $n++;
                [$ano, $mes] = CalculadoraAmortizacoes::periodo($r->periodo_codigo) ?? [0, 1];
                $esperada = CalculadoraAmortizacoes::devida($a, $cats[$a->categoria_ativo_id] ?? null, $ano, $mes, $acumulado) ?? '0.00';
                $valor = CalculadoraAmortizacoes::d($r->valor);
                if (bccomp($esperada, $valor, 2) !== 0) {
                    $quotas[] = ['ativo_imobilizado_id' => $a->id, 'codigo' => $a->codigo, 'periodo' => $r->periodo_codigo, 'registado' => $valor, 'esperado' => $esperada,
                        'diferenca' => bcsub($valor, $esperada, 2), 'contabilizado' => (bool) $r->contabilizado];
                }
                $acumulado = bcadd($acumulado, $valor, 2);
                if ($r->contabilizado) {
                    $integrado = bcadd($integrado, $valor, 2);
                }
            }
            if (bccomp($integrado, CalculadoraAmortizacoes::d($a->amortizacao_acumulada), 2) !== 0) {
                $acumulados[] = ['ativo_imobilizado_id' => $a->id, 'codigo' => $a->codigo, 'ficha' => CalculadoraAmortizacoes::d($a->amortizacao_acumulada), 'derivado' => $integrado];
            }
        }

        return ['registos' => $n, 'quotas_divergentes' => $quotas, 'acumulados_divergentes' => $acumulados];
    }

    // ───────────── Auxiliares ─────────────

    /** Cria (ou mantém) o rascunho de um activo num período, pela regra de cálculo. */
    private function calcularAtivo(AtivoImobilizado $a, string $periodo): void
    {
        [$ano, $mes] = CalculadoraAmortizacoes::periodo($periodo);
        $doAtivo = AmortizacaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->get()->keyBy('periodo_codigo');
        if (isset($doAtivo[$periodo])) {
            return;
        }
        $q = CalculadoraAmortizacoes::devida($a, CategoriaAtivo::withTrashed()->find($a->categoria_ativo_id), $ano, $mes, $this->acumuladoSem($a, $doAtivo, $periodo));
        if ($q !== null) {
            AmortizacaoAtivo::create(['ativo_imobilizado_id' => $a->id, 'periodo_codigo' => $periodo, 'contabilizado' => false, 'valor' => $q, 'data' => $this->ultimoDia($ano, $mes)]);
        }
    }

    /** Lança um conjunto de rascunhos do mesmo período e marca-os integrados. */
    private function lancarPeriodo(string $periodo, Collection $rascunhos, string $descricao): array
    {
        [$ano, $mes] = CalculadoraAmortizacoes::periodo($periodo);
        $data = $this->ultimoDia($ano, $mes);
        [, $linhas] = $this->linhas($rascunhos);
        $diario = $this->localizador->diario(AmortizacaoAtivo::DIARIO, AmortizacaoAtivo::NOME_DIARIO);
        $notas = NotaDemonstracao::query()->whereIn('codigo', ['4', '29'])->pluck('id', 'codigo');
        foreach ($linhas as &$l) {
            $l['nota_demonstracao_id'] = str_starts_with($l['codigo_conta'], '73') ? ($notas['29'] ?? null) : (str_starts_with($l['codigo_conta'], '18') ? ($notas['4'] ?? null) : null);
            $l['descricao'] = "{$descricao} (".($l['tipo_dc'] === 'D' ? 'Gasto' : 'Acumulada').')';
        }
        unset($l);
        $numeroLan = $this->lancamentos->criar([
            'diario_id' => $diario->id, 'data_documento' => $data, 'numero_documento' => AmortizacaoAtivo::numeroDocumento($periodo),
            'tipo_origem' => AmortizacaoAtivo::TIPO_ORIGEM, 'descricao' => $descricao, 'linhas' => $linhas,
        ])->first()->numero_lan;
        AmortizacaoAtivo::query()->whereIn('id', $rascunhos->pluck('id'))->update(['contabilizado' => true, 'data' => $data, 'atualizado_em' => now()]);
        foreach ($rascunhos->pluck('ativoImobilizado')->filter()->unique('id') as $a) {
            $this->ativos->recalcularAcumulado($a);
        }

        return ['periodo' => $periodo, 'numero_lan' => $numeroLan, 'ativos' => $rascunhos->count(), 'total' => $this->somaValores($rascunhos)];
    }

    /** @return array{0: list<array>, 1: list<array>} [movimentos por activo, linhas agrupadas por conta/UN/CC] */
    private function linhas(Collection $rascunhos): array
    {
        $cats = CategoriaAtivo::withTrashed()->get()->keyBy('id');
        $movimentos = $agrupadas = [];
        foreach ($rascunhos as $r) {
            $a = $r->ativoImobilizado;
            [$gasto, $acumulada] = ServicoCategoriasAtivos::exigirContasAmortizacao($cats[$a->categoria_ativo_id] ?? null, (string) $a->codigo);
            $valor = CalculadoraAmortizacoes::d($r->valor);
            $movimentos[] = ['ativo_imobilizado_id' => $a->id, 'codigo' => $a->codigo, 'descricao' => $a->descricao, 'conta_debito' => $gasto, 'conta_credito' => $acumulada, 'valor' => $valor];
            $dim = ['unidade_negocio_id' => $a->unidade_negocio_id, 'centro_custo_id' => $a->centro_custo_id];
            foreach ([[$gasto, 'D'], [$acumulada, 'C']] as [$conta, $dc]) {
                $k = "{$conta}|{$dc}|".implode('|', $dim);
                $agrupadas[$k] ??= ['codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => '0.00'] + $dim;
                $agrupadas[$k]['valor'] = bcadd($agrupadas[$k]['valor'], $valor, 2);
            }
        }
        $agrupadas = array_values($agrupadas);
        usort($agrupadas, fn ($x, $y) => [$x['tipo_dc'] === 'C', $x['codigo_conta']] <=> [$y['tipo_dc'] === 'C', $y['codigo_conta']]);

        return [$movimentos, $agrupadas];
    }

    /** Uma linha de cada lançamento activo (não estornado) do período no diário AM (inclui os migrados e os individuais). */
    private function lancamentosDoPeriodo(string $periodo): Collection
    {
        $diario = $this->localizador->diario(AmortizacaoAtivo::DIARIO, AmortizacaoAtivo::NOME_DIARIO);

        return LancamentoContabil::query()->where('diario_id', $diario->id)->where('numero_documento', AmortizacaoAtivo::numeroDocumento($periodo))
            ->whereNull('estorno_de_id')->whereNull('estornado_por_id')->orderBy('id')->get()->unique(fn ($l) => $l->chave())->values();
    }

    /** Acumulado de um activo sem a quota do período indicado: inicial + todas as outras quotas (rascunho ou integradas). */
    private function acumuladoSem(AtivoImobilizado $a, Collection $registos, string $periodo): string
    {
        return bcadd(CalculadoraAmortizacoes::d($a->amortizacao_acumulada_inicial), $this->somaValores($registos->where('periodo_codigo', '!=', $periodo)), 2);
    }

    private function somaValores(Collection $registos): string
    {
        return $registos->reduce(fn ($s, $r) => bcadd($s, CalculadoraAmortizacoes::d($r->valor), 2), '0.00');
    }

    private function soma(array $linhas, string $estado): string
    {
        return array_reduce(array_filter($linhas, fn ($l) => $l['estado'] === $estado), fn ($s, $l) => bcadd($s, $l['quota'], 2), '0.00');
    }

    private function estadoPeriodo(array $contagens): string
    {
        $integrado = ($contagens['INTEGRADO'] ?? 0) > 0;
        $pendente = ($contagens['RASCUNHO'] ?? 0) + ($contagens['POR_CALCULAR'] ?? 0) > 0;

        return match (true) {
            $integrado && ! $pendente => 'INTEGRADO',
            $integrado => 'PARCIAL',
            ($contagens['RASCUNHO'] ?? 0) > 0 => 'CALCULADO',
            default => 'ABERTO',
        };
    }

    /** @return list<string> períodos válidos, únicos e por ordem cronológica */
    private function ordenar(array $periodos): array
    {
        if (! $periodos) {
            throw new ErroNegocio('Seleccione pelo menos um período.', 'DADOS_INVALIDOS', 422);
        }
        foreach ($periodos as $p) {
            $this->exigirPeriodo((string) $p);
        }
        $periodos = array_values(array_unique(array_map('strval', $periodos)));
        usort($periodos, fn ($x, $y) => CalculadoraAmortizacoes::ordem($x) <=> CalculadoraAmortizacoes::ordem($y));

        return $periodos;
    }

    private function exigirPeriodo(string $periodo): array
    {
        return CalculadoraAmortizacoes::periodo($periodo) ?? throw new ErroNegocio("Período inválido: {$periodo} (formato MM-AAAA).", 'PERIODO_INVALIDO', 422);
    }

    private function ultimoDia(int $ano, int $mes): string
    {
        return date('Y-m-t', mktime(0, 0, 0, $mes, 1, $ano));
    }
}
