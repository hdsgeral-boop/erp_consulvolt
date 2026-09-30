<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\MapeamentoContabilRH;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\ResultadoFolhaSalarial;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Processamento salarial (js/app_v2.js:4981-6521 do legado), com as correcções (ADR-036):
 *   - ciclo com regras no SERVIDOR: lançamentos só em ABERTO; ABERTO → FECHADO (encerrar) → VALIDADO → contabilizado;
 *     reabrir só sem contabilização (o legado bloqueava apenas na interface e validava a partir de qualquer estado);
 *   - FOTOGRAFIA dos resultados ao encerrar (resultados_folha_salarial): recibos, mapas e contabilização usam-na e
 *     não mudam quando se alteram contratos ou rubricas (no legado tudo era recalculado ao vivo — 11 de 42 meses já
 *     não batiam com o diário);
 *   - contabilização numa transacção via ServicoLancamentos (diário SAL), equilibrada ao cêntimo, a partir da
 *     fotografia; rubricas OUTROS não são lançadas; descontabilizar = estorno (o legado apagava por period_id,
 *     campo partilhado com Acréscimos e Amortizações);
 *   - importação dos contratos só de colaboradores ACTIVOS com contrato válido no mês; um lançamento por
 *     (colaborador, rubrica) — o legado tinha 15 duplicados.
 */
final class ServicoFolhaSalarial
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
    ) {}

    // ───────────── Períodos ─────────────

    public function abrir(string $mesAno): PeriodoProcessamentoSalarial
    {
        if (! preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $mesAno)) {
            throw new ErroNegocio('Mês inválido (formato MM/AAAA).', 'MES_INVALIDO', 422);
        }
        $this->exercicios->exigirAberto($this->contexto->obrigatorio(), $this->ultimoDia($mesAno));

        return DB::transaction(function () use ($mesAno) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["salarios:{$this->contexto->obrigatorio()}:{$mesAno}"]);
            if (PeriodoProcessamentoSalarial::query()->where('mes_ano', $mesAno)->exists()) {
                throw new ErroNegocio("Já existe o processamento de {$mesAno}.", 'PERIODO_EXISTENTE', 422);
            }

            return PeriodoProcessamentoSalarial::create(['mes_ano' => $mesAno, 'estado' => 'ABERTO', 'contabilizado' => false, 'modo_calculo' => 'ATUAL']);
        });
    }

    public function encerrar(PeriodoProcessamentoSalarial $p): PeriodoProcessamentoSalarial
    {
        return DB::transaction(function () use ($p) {
            $p = $this->bloquear($p);
            $this->exigirEstado($p, ['ABERTO']);
            if (! LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->exists()) {
                throw new ErroNegocio('O período não tem lançamentos.', 'PERIODO_VAZIO', 422);
            }
            $this->gravarResultados($p, 'ATUAL');
            $p->update(['estado' => 'FECHADO', 'fechado_em' => now(), 'fechado_por' => Auth::user()?->nome_utilizador, 'modo_calculo' => 'ATUAL']);

            return $p;
        });
    }

    public function validar(PeriodoProcessamentoSalarial $p): PeriodoProcessamentoSalarial
    {
        return DB::transaction(function () use ($p) {
            $p = $this->bloquear($p);
            $this->exigirEstado($p, ['FECHADO']);
            $p->update(['estado' => 'VALIDADO', 'validado_em' => now(), 'validado_por' => Auth::user()?->nome_utilizador]);

            return $p;
        });
    }

    public function reabrir(PeriodoProcessamentoSalarial $p): PeriodoProcessamentoSalarial
    {
        return DB::transaction(function () use ($p) {
            $p = $this->bloquear($p);
            $this->exigirEstado($p, ['FECHADO', 'VALIDADO']);
            if ($p->contabilizado) {
                throw new ErroNegocio('O período está contabilizado: descontabilize-o primeiro (estorno).', 'PERIODO_CONTABILIZADO', 422);
            }
            ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->delete();
            $p->update(['estado' => 'ABERTO', 'fechado_em' => null, 'fechado_por' => null, 'validado_em' => null, 'validado_por' => null, 'modo_calculo' => 'ATUAL']);

            return $p;
        });
    }

    // ───────────── Lançamentos ─────────────

    /** Um lançamento por (colaborador, rubrica): grava ou substitui. */
    public function gravarLancamento(PeriodoProcessamentoSalarial $p, array $d): LinhaFolhaSalarial
    {
        return DB::transaction(function () use ($p, $d) {
            $p = $this->bloquear($p);
            $this->exigirEstado($p, ['ABERTO']);
            $c = Colaborador::query()->findOrFail($d['colaborador_id']);
            $i = InfotipoSalarial::query()->findOrFail($d['infotipo_salarial_id']);
            $horas = isset($d['horas']) && $d['horas'] !== null && $d['horas'] !== '' ? (string) $d['horas'] : null;
            if ($horas !== null && ! MotorSalarial::tipoHoras($this->infotipoArray($i))) {
                throw new ErroNegocio("A rubrica {$i->nome} não é calculada por horas.", 'RUBRICA_SEM_HORAS', 422);
            }
            $valor = number_format((float) ($d['valor'] ?? 0), 2, '.', '');
            if ($horas === null && (float) $valor < 0) {
                throw new ErroNegocio('O valor não pode ser negativo.', 'VALOR_INVALIDO', 422);
            }
            $dados = ['valor' => $valor, 'dias_trabalhados' => $d['dias_trabalhados'] ?? null, 'horas' => $horas];

            return LinhaFolhaSalarial::query()->updateOrCreate(
                ['periodo_processamento_salarial_id' => $p->id, 'colaborador_id' => $c->id, 'infotipo_salarial_id' => $i->id], $dados + ['origem' => $d['origem'] ?? 'MANUAL']);
        });
    }

    public function removerLancamento(LinhaFolhaSalarial $l): void
    {
        DB::transaction(function () use ($l) {
            $this->exigirEstado($this->bloquear(PeriodoProcessamentoSalarial::query()->findOrFail($l->periodo_processamento_salarial_id)), ['ABERTO']);
            $l->delete();
        });
    }

    /** Lançamentos a partir dos contratos (importFromContracts, app_v2.js:5163-5222), só colaboradores activos com contrato válido. */
    public function importarContratos(PeriodoProcessamentoSalarial $p): array
    {
        return DB::transaction(function () use ($p) {
            $p = $this->bloquear($p);
            $this->exigirEstado($p, ['ABERTO']);
            $criados = $existentes = 0;
            $ignorados = [];
            $contratos = ContratoTrabalho::query()->get()->groupBy('colaborador_id');
            foreach (Colaborador::query()->where('estado', 'ACTIVO')->orderBy('id')->get() as $c) {
                $contrato = $this->contratoDoMes($contratos[$c->id] ?? collect(), $p->mes_ano, 'ATUAL');
                if (! $contrato) {
                    $ignorados[] = "{$c->nome_completo}: sem contrato activo em {$p->mes_ano}.";

                    continue;
                }
                if ($contrato->codigo_moeda && $contrato->codigo_moeda !== 'AOA') {
                    $ignorados[] = "{$c->nome_completo}: contrato em {$contrato->codigo_moeda} (processamento só em Kz).";

                    continue;
                }
                $ctr = $this->contratoArray($contrato);
                foreach ($ctr['remuneracoes'] as $rem) {
                    $existe = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->where('colaborador_id', $c->id)
                        ->where('infotipo_salarial_id', $rem['infotipo_id'])->exists();
                    if ($existe) {
                        $existentes++;

                        continue;
                    }
                    LinhaFolhaSalarial::create(['periodo_processamento_salarial_id' => $p->id, 'colaborador_id' => $c->id, 'infotipo_salarial_id' => $rem['infotipo_id'],
                        'valor' => MotorSalarial::arred(MotorSalarial::mensal($rem, $ctr)), 'dias_trabalhados' => (float) MotorSalarial::diasContrato($ctr), 'origem' => 'CONTRATO']);
                    $criados++;
                }
            }

            return ['criados' => $criados, 'ja_existentes' => $existentes, 'ignorados' => $ignorados];
        });
    }

    // ───────────── Cálculo ─────────────

    /** Resultado do período: a fotografia gravada (se encerrado) ou o cálculo ao vivo (se aberto). */
    public function resultados(PeriodoProcessamentoSalarial $p): array
    {
        if ($p->estado !== 'ABERTO') {
            return ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->orderBy('colaborador_id')->get()->toArray();
        }

        return $this->calcular($p, 'ATUAL');
    }

    /** @return list<array<string, mixed>> */
    public function calcular(PeriodoProcessamentoSalarial $p, string $modo, string $divisor = 'CONTRATO'): array
    {
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $infotipos = InfotipoSalarial::query()->withTrashed()->get()->mapWithKeys(fn ($i) => [$i->id => $this->infotipoArray($i)])->all();
        $colaboradores = Colaborador::query()->withTrashed()->get()->keyBy('id');
        $contratos = ContratoTrabalho::query()->get()->groupBy('colaborador_id');
        $cfg = ['modo' => $modo, 'inss_trabalhador' => $modo === 'ATUAL' && $empresa->taxa_inss_trabalhador !== null ? (string) $empresa->taxa_inss_trabalhador : '3',
            'inss_patronal' => $modo === 'ATUAL' && $empresa->taxa_inss_patronal !== null ? (string) $empresa->taxa_inss_patronal : '8',
            'he' => ['p1' => $empresa->he_percentagem_1 ?? 50, 'limite' => $empresa->he_limite_horas ?? 30, 'p2' => $empresa->he_percentagem_2 ?? 75]];
        $saida = [];
        foreach (LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->orderBy('id')->get()->groupBy('colaborador_id') as $cid => $linhas) {
            $c = $colaboradores[$cid] ?? null;
            if (! $c) {
                continue;
            }
            $contrato = $this->contratoDoMes($contratos[$cid] ?? collect(), $p->mes_ano, $modo);
            $ctr = $contrato ? $this->contratoArray($contrato) : null;
            // divisor dos dias: dias do contrato (regra desde 2026-09-16); antes, o legado usava os dias úteis do colaborador
            $diasContrato = $divisor === 'COLABORADOR' ? ($c->dias_uteis_mes ?: 0)
                : (($contrato && (float) $contrato->dias_contrato_mes > 0) ? $contrato->dias_contrato_mes : ($c->dias_uteis_mes ?: 0));
            $r = MotorSalarial::calcular([
                'id' => $c->id, 'avencado' => (bool) $c->avencado, 'reformado' => (bool) $c->reformado, 'dias_contrato' => $diasContrato, 'contrato' => $ctr,
                'lancamentos' => $linhas->map(fn ($l) => ['infotipo_id' => $l->infotipo_salarial_id, 'valor' => $l->valor, 'dias_trabalhados' => $l->dias_trabalhados, 'horas' => $l->horas])->all(),
            ], $infotipos, $cfg);
            $saida[] = $r + ['tipo_organizacao_id' => $c->tipo_organizacao_id, 'unidade_negocio_id' => $c->unidade_negocio_id, 'centro_custo_id' => $c->centro_custo_id,
                'nome' => $c->nome_completo];
        }

        return $saida;
    }

    /** Fotografia do cálculo (encerrar; e, na migração, períodos validados do legado em modo LEGADO). */
    public function gravarResultados(PeriodoProcessamentoSalarial $p, string $modo, string $divisor = 'CONTRATO'): int
    {
        ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->delete();
        $n = 0;
        foreach ($this->calcular($p, $modo, $divisor) as $r) {
            ResultadoFolhaSalarial::create(array_intersect_key($r, array_flip(['colaborador_id', 'tipo_organizacao_id', 'unidade_negocio_id', 'centro_custo_id', 'avencado',
                'reformado', 'dias_contrato', 'dias_trabalhados', 'bruto', 'base_inss', 'inss_trabalhador', 'inss_patronal', 'isencoes', 'base_irt', 'irt', 'descontos',
                'liquido', 'rubricas', 'avisos', 'modo_calculo'])) + ['periodo_processamento_salarial_id' => $p->id]);
            $n++;
        }

        return $n;
    }

    /**
     * Migração: fotografa os períodos encerrados do legado em modo LEGADO. Como o legado recalculava tudo ao vivo e mudou
     * o divisor dos dias em 2026-09-16, reconstrói-se com a variante (dias do contrato ou do colaborador) que reproduz o
     * que foi efectivamente lançado no diário; períodos não contabilizados usam o divisor actual.
     *
     * @return array{periodos: int, confere: int, difere: int, variantes: array<string, int>}
     */
    public function fotografarLegado(): array
    {
        $res = ['periodos' => 0, 'confere' => 0, 'difere' => 0, 'variantes' => []];
        foreach (PeriodoProcessamentoSalarial::query()->whereIn('estado', ['FECHADO', 'VALIDADO'])->orderBy('id')->get() as $p) {
            if (ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->exists()) {
                continue;
            }
            $melhor = null;
            foreach ($p->contabilizado ? ['CONTRATO', 'COLABORADOR'] : ['CONTRATO'] as $divisor) {
                $this->gravarResultados($p, 'LEGADO', $divisor);
                $v = collect($this->verificarContraDiario())->firstWhere('periodo_id', $p->id);
                $dif = $v ? abs((float) $v['diferenca']) : 0;
                if ($melhor === null || $dif < $melhor[1]) {
                    $melhor = [$divisor, $dif, $v];
                }
            }
            $this->gravarResultados($p, 'LEGADO', $melhor[0]);
            $p->update(['modo_calculo' => 'LEGADO']);
            $res['periodos']++;
            $res['variantes'][$melhor[0]] = ($res['variantes'][$melhor[0]] ?? 0) + 1;
            if ($p->contabilizado) {
                ($melhor[2]['confere'] ?? false) ? $res['confere']++ : $res['difere']++;
            }
        }

        return $res;
    }

    // ───────────── Contabilização ─────────────

    public function contabilizar(PeriodoProcessamentoSalarial $p): PeriodoProcessamentoSalarial
    {
        return DB::transaction(function () use ($p) {
            $p = $this->bloquear($p);
            $this->exigirEstado($p, ['VALIDADO']);
            if ($p->contabilizado) {
                throw new ErroNegocio('O período já está contabilizado.', 'JA_CONTABILIZADO', 422);
            }
            $mapas = MapeamentoContabilRH::query()->get();
            $sistema = MapeamentoContabilSistemaRH::query()->get();
            $linhas = [];
            $faltam = [];
            $somar = function (?string $conta, string $dc, string $valor, $r, string $falta) use (&$linhas, &$faltam) {
                if (bccomp($valor, '0', 2) === 0) {
                    return;
                }
                if (! $conta) {
                    $faltam[$falta] = true;

                    return;
                }
                $k = "{$conta}|{$dc}|{$r->unidade_negocio_id}|{$r->centro_custo_id}";
                $linhas[$k] ??= ['codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => '0.00', 'unidade_negocio_id' => $r->unidade_negocio_id, 'centro_custo_id' => $r->centro_custo_id];
                $linhas[$k]['valor'] = bcadd($linhas[$k]['valor'], $valor, 2);
            };
            $nomes = InfotipoSalarial::query()->withTrashed()->pluck('nome', 'id');
            foreach (ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->get() as $r) {
                $org = $r->tipo_organizacao_id;
                foreach ($r->rubricas ?? [] as $rub) {
                    if (! empty($rub['informativa']) || ! in_array($rub['tipo'], ['VENCIMENTO', 'DESCONTO'], true)) {
                        continue;
                    }
                    $conta = $this->contaRubrica($mapas, (int) $rub['infotipo_id'], $org, (bool) $r->avencado);
                    $somar($conta, $rub['tipo'] === 'VENCIMENTO' ? 'D' : 'C', number_format((float) $rub['valor'], 2, '.', ''), $r,
                        'Rubrica '.($nomes[$rub['infotipo_id']] ?? $rub['infotipo_id']).($r->avencado ? ' (avençado)' : " (tipo de organização {$org})"));
                }
                $somar($this->contaSistema($sistema, 'NET_PAY_CREDIT', $org, (bool) $r->avencado), 'C', (string) $r->liquido, $r, 'NET_PAY_CREDIT');
                $somar($this->contaSistema($sistema, $r->avencado ? 'IRT_AVENCADO_CREDIT' : 'IRT_CREDIT', $org, false), 'C', (string) $r->irt, $r, $r->avencado ? 'IRT_AVENCADO_CREDIT' : 'IRT_CREDIT');
                $somar($this->contaSistema($sistema, 'INSS_FUNC_CREDIT', $org, false), 'C', (string) $r->inss_trabalhador, $r, 'INSS_FUNC_CREDIT');
                $somar($this->contaSistema($sistema, 'INSS_EMP_DEBIT', $org, false), 'D', (string) $r->inss_patronal, $r, 'INSS_EMP_DEBIT');
                $somar($this->contaSistema($sistema, 'INSS_EMP_CREDIT', $org, false), 'C', (string) $r->inss_patronal, $r, 'INSS_EMP_CREDIT');
            }
            if ($faltam) {
                throw new ErroNegocio('Faltam mapeamentos contabilísticos: '.implode('; ', array_keys($faltam)).'.', 'MAPEAMENTO_EM_FALTA', 422, ['em_falta' => array_keys($faltam)]);
            }
            [$mes, $ano] = explode('/', $p->mes_ano);
            $criadas = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('SAL', 'Salários')->id, 'data_documento' => $this->ultimoDia($p->mes_ano),
                'numero_documento' => "SAL{$mes}{$ano}", 'referencia' => $p->mes_ano, 'descricao' => "Processamento salarial {$p->mes_ano}", 'tipo_origem' => 'SALARIOS',
                'linhas' => array_values($linhas)]);
            $p->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $criadas->first()->numero_lan]);

            return $p;
        });
    }

    public function descontabilizar(PeriodoProcessamentoSalarial $p, string $motivo): PeriodoProcessamentoSalarial
    {
        return DB::transaction(function () use ($p, $motivo) {
            $p = $this->bloquear($p);
            if (! $p->contabilizado) {
                throw new ErroNegocio('O período não está contabilizado.', 'NAO_CONTABILIZADO', 422);
            }
            [$mes, $ano] = explode('/', $p->mes_ano);
            // legado: doc_number 'SAL'+MMAAAA no diário SAL (sem n.º de lançamento guardado)
            $this->lancamentos->estornar($this->localizador->localizar($p->numero_lan_contabilizacao, "SAL{$mes}{$ano}", null, 'D', $p->numero_lan_contabilizacao ? null : 'SAL'), $motivo);
            $p->update(['contabilizado' => false, 'numero_lan_contabilizacao' => null]);

            return $p;
        });
    }

    /**
     * Verificação de não-regressão (ADR-017): para cada período contabilizado, compara a fotografia (Σ vencimentos
     * + INSS patronal = débitos) com os débitos do lançamento SAL no diário.
     *
     * @return list<array<string, mixed>>
     */
    public function verificarContraDiario(float $tolerancia = 10.0): array
    {
        $saida = [];
        $salIds = DB::table('diarios_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo', 'SAL')->pluck('id');
        foreach (PeriodoProcessamentoSalarial::query()->where('contabilizado', true)->orderBy('mes_ano')->orderBy('id')->get() as $p) {
            [$mes, $ano] = explode('/', $p->mes_ano);
            $res = ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->get();
            $debitos = '0.00';
            foreach ($res as $r) {
                foreach ($r->rubricas ?? [] as $rub) {
                    if (($rub['tipo'] ?? null) === 'VENCIMENTO' && empty($rub['informativa'])) {
                        $debitos = bcadd($debitos, number_format((float) $rub['valor'], 2, '.', ''), 2);
                    }
                }
                $debitos = bcadd($debitos, (string) $r->inss_patronal, 2);
            }
            $diario = number_format((float) DB::table('lancamentos_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->whereIn('diario_id', $salIds)
                ->where('numero_documento', "SAL{$mes}{$ano}")->where('tipo_dc', 'D')->whereNull('estorno_de_id')->sum('valor'), 2, '.', '');
            $dif = bcsub($debitos, $diario, 2);
            $saida[] = ['periodo_id' => $p->id, 'mes_ano' => $p->mes_ano, 'colaboradores' => $res->count(), 'debitos_calculados' => $debitos, 'debitos_diario' => $diario,
                'diferenca' => $dif, 'confere' => abs((float) $dif) <= $tolerancia, 'sem_lancamento' => bccomp($diario, '0', 2) === 0, 'modo_calculo' => $p->modo_calculo];
        }

        return $saida;
    }

    // ───────────── Auxiliares ─────────────

    private function contaRubrica($mapas, int $infotipo, ?int $org, bool $avencado): ?string
    {
        $doInfotipo = $mapas->where('infotipo_salarial_id', $infotipo);
        if ($avencado && ($m = $doInfotipo->first(fn ($x) => $x->avencado))) {
            return $m->numero_conta;
        }

        return $doInfotipo->first(fn ($x) => ! $x->avencado && (int) $x->tipo_organizacao_id === (int) $org)?->numero_conta;
    }

    private function contaSistema($sistema, string $codigo, ?int $org, bool $avencado): ?string
    {
        $doCodigo = $sistema->where('codigo', $codigo);
        if ($avencado && ($m = $doCodigo->first(fn ($x) => $x->avencado))) {
            return $m->numero_conta;
        }

        return $doCodigo->first(fn ($x) => ! $x->avencado && (int) $x->tipo_organizacao_id === (int) $org)?->numero_conta
            ?? ($codigo === 'IRT_AVENCADO_CREDIT' ? $doCodigo->first()?->numero_conta : null);
    }

    /**
     * Contrato do mês. ATUAL: ACTIVO e válido no mês (o legado comparava 'MM/AAAA-01' com datas ISO e nunca filtrava);
     * LEGADO: o comportamento efectivo antigo (primeiro ACTIVO).
     */
    private function contratoDoMes($contratos, string $mesAno, string $modo): ?ContratoTrabalho
    {
        $contratos = $contratos->sortBy('id')->values();
        if ($modo === 'LEGADO') {
            return $contratos->first(fn ($c) => $c->estado === 'ACTIVO') ?? $contratos->first();
        }
        [$mes, $ano] = explode('/', $mesAno);
        $inicio = "{$ano}-{$mes}-01";
        $fim = $this->ultimoDia($mesAno);
        $validos = $contratos->filter(fn ($c) => (! $c->data_inicio || $c->data_inicio->toDateString() <= $fim) && (! $c->data_fim || $c->data_fim->toDateString() >= $inicio));

        return $validos->first(fn ($c) => $c->estado === 'ACTIVO');
    }

    private function contratoArray(ContratoTrabalho $c): array
    {
        return ['dias' => $c->dias_contrato_mes, 'horas' => $c->horas_por_dia, 'remuneracoes' => array_values(array_filter(array_map(fn ($r) => [
            'infotipo_id' => (int) ($r['infotype_id'] ?? $r['infotipo_id'] ?? 0), 'valor_mes' => $r['value_month'] ?? $r['valor_mes'] ?? null,
            'valor_dia' => $r['value_per_day'] ?? $r['valor_dia'] ?? null], is_array($c->remuneracoes) ? $c->remuneracoes : (json_decode((string) $c->remuneracoes, true) ?: [])),
            fn ($r) => $r['infotipo_id'] > 0))];
    }

    private function infotipoArray(InfotipoSalarial $i): array
    {
        return ['id' => $i->id, 'tipo' => $i->tipo, 'nome' => (string) $i->nome, 'inss' => (bool) $i->sujeito_inss, 'irt' => $i->irt,
            'base_horaria' => (bool) $i->base_horaria, 'calculo_horas' => $i->calculo_horas];
    }

    private function bloquear(PeriodoProcessamentoSalarial $p): PeriodoProcessamentoSalarial
    {
        return PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($p->id);
    }

    private function exigirEstado(PeriodoProcessamentoSalarial $p, array $estados): void
    {
        if (! in_array($p->estado, $estados, true)) {
            throw new ErroNegocio("O período {$p->mes_ano} está {$p->estado}: operação não permitida (exige ".implode(' ou ', $estados).').', 'PERIODO_ESTADO_INVALIDO', 422);
        }
    }

    public function ultimoDia(string $mesAno): string
    {
        [$mes, $ano] = explode('/', $mesAno);

        return date('Y-m-t', strtotime("{$ano}-{$mes}-01"));
    }
}
