<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\EquipaProjeto;
use App\Models\InfotipoSalarial;
use App\Models\ItemCompra;
use App\Models\ItemVenda;
use App\Models\LinhaFolhaSalarial;
use App\Models\LinhaRevisaoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\RevisaoMensalProjeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Compras\ServicoFaturasCompra;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Autos de medição e revisão mensal (showReviewModal / executeReview / viewReview / billReview / confirmAutoBill,
 * js/ui_projects.js:2459-2957, 3104-3290).
 * Mantém do legado:
 *   - mão de obra interna: por colaborador da equipa, horas/dia alocadas (8 por omissão); com recibo processado no mês,
 *     custo = horas/8 × Σ vencimentos do mês; sem recibo, custo teórico do contrato activo em Kz = valor/dia ÷ 8 × horas × 22;
 *     desconta o já imputado ao mesmo colaborador em revisões do mesmo mês (o aditamento só leva o que evoluiu);
 *   - subempreitadas: tarefas atribuídas a um membro externo com valor adjudicado; facturável = % actual − maior %
 *     já medida nessa tarefa; valor = facturável × valor adjudicado;
 *   - equipamentos: movimentos CUSTOS_EQUIPAMENTO do razão no mês;
 *   - executar recusa uma revisão sem diferenciais e pede confirmação se o mês já tem revisão (aditamento);
 *     gera a factura de fornecedor do auto (membro com ficha de terceiro), o movimento de mão de obra no razão e as
 *     linhas (SUBEMPREITADA / MAO_OBRA) com o documento gerado;
 *   - facturar (só projecto externo com encomenda): sugestão = venda sem IVA × execução global − já facturado;
 *     FT, FR ou PF com a nota «Faturação de Auto de Medição (REV-n)».
 * Correcções:
 *   - a linha SUBEMPREITADA grava o TERCEIRO (o legado gravava o id do membro da equipa em third_party_id,
 *     ui_projects.js:2735);
 *   - o «já facturado» impede mesmo facturar o mesmo auto duas vezes: o legado procurava «AUTO-<id>» numa nota que
 *     dizia «REV-<id>» (ui_projects.js:3105 × 3227), pelo que nunca encontrava;
 *   - o custo teórico do contrato soma o valor/dia das remunerações de vencimento (o legado lia um campo
 *     contract.value_per_day que não existe e dava sempre 0 — as linhas a zero nos dados);
 *   - a mão de obra imputada fica datada no mês da revisão (último dia, ou hoje se for antes) e não no dia do
 *     processamento, para os relatórios por período;
 *   - a factura de fornecedor e a de venda passam pelos módulos Compras e Vendas (n.º único por fornecedor, exercício
 *     aberto, controlo orçamental, série AGT, artigo obrigatório no SAF-T); o legado gravava-as directamente, a de venda
 *     sem artigo e com a conta 7211 fixa; o IVA é o do artigo;
 *   - n.º da factura do auto AUTO-<proj>-<AAAAMM>-<membro>-<tarefa>-R<revisão> (o legado repetia o número no
 *     aditamento do mesmo mês);
 *   - tudo numa transacção, com bloqueio por projecto e mês; projecto encerrado não aceita revisões nem facturação.
 */
final class ServicoRevisoesProjetos
{
    public const TIPOS_FATURACAO = ['FT', 'FR', 'PF'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoProjetos $projetos,
        private readonly ServicoAnaliticoProjetos $analitico,
    ) {}

    /** Simulação da revisão do mês (showReviewModal). */
    public function simular(Projeto $p, int $mes, int $ano): array
    {
        self::validarMes($mes, $ano);
        $membros = MembroEquipaProjeto::query()->whereIn('equipa_projeto_id', EquipaProjeto::query()->where('projeto_id', $p->id)->select('id'))->orderBy('id')->get();
        $revisoesMes = RevisaoMensalProjeto::query()->where('projeto_id', $p->id)->where('mes', $mes)->where('ano', $ano)->pluck('id');

        // Mão de obra interna
        $internos = [];
        $colab = $membros->whereNotNull('colaborador_id');
        $vencimentos = InfotipoSalarial::query()->withTrashed()->where('tipo', 'VENCIMENTO')->pluck('id')->flip();
        $periodo = PeriodoProcessamentoSalarial::query()->where('mes_ano', sprintf('%02d/%04d', $mes, $ano))->first();
        $recibos = $periodo ? LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $periodo->id)->whereIn('colaborador_id', $colab->pluck('colaborador_id'))
            ->get()->groupBy('colaborador_id') : collect();
        $contratos = ContratoTrabalho::query()->whereIn('colaborador_id', $colab->pluck('colaborador_id'))->where('estado', 'ACTIVO')
            ->where(fn ($q) => $q->whereNull('codigo_moeda')->orWhere('codigo_moeda', 'AOA'))->orderBy('id')->get()->keyBy('colaborador_id');
        $jaImputado = LinhaRevisaoProjeto::query()->where('tipo', LinhaRevisaoProjeto::MAO_OBRA)->whereIn('revisao_mensal_projeto_id', $revisoesMes)
            ->groupBy('colaborador_id')->selectRaw('colaborador_id, SUM(valor_calculado) AS v')->pluck('v', 'colaborador_id');
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $colab->pluck('colaborador_id'))->pluck('nome_completo', 'id');
        foreach ($colab as $m) {
            $horas = (string) ((float) $m->horas_alocadas ?: 8);
            $linhasRecibo = ($recibos[$m->colaborador_id] ?? collect())->filter(fn ($l) => isset($vencimentos[$l->infotipo_salarial_id]));
            if (($recibos[$m->colaborador_id] ?? collect())->isNotEmpty()) {
                $bruto = $linhasRecibo->reduce(fn ($s, $l) => bcadd($s, (string) $l->valor, 4), '0');
                $custo = bcmul(bcdiv($horas, '8', 8), $bruto, 6);
                $fonte = 'RECIBO';
            } else {
                $custo = bcmul(bcmul(bcdiv(self::valorDiaContrato($contratos[$m->colaborador_id] ?? null, $vencimentos), '8', 8), $horas, 8), '22', 6);
                $fonte = 'CONTRATO';
            }
            $custo = ServicoAnaliticoProjetos::dinheiro($custo);
            $ja = ServicoAnaliticoProjetos::dinheiro($jaImputado[$m->colaborador_id] ?? 0);
            $final = bccomp($custo, $ja, 2) > 0 ? bcsub($custo, $ja, 2) : '0.00';
            if ((float) $final > 0 || (float) $custo > 0) {
                $internos[] = ['membro_id' => $m->id, 'colaborador_id' => $m->colaborador_id, 'nome' => $nomes[$m->colaborador_id] ?? 'Desconhecido',
                    'horas_dia' => (float) $horas, 'fonte' => $fonte, 'custo_mensal' => $custo, 'ja_imputado' => $ja, 'custo' => $final];
            }
        }

        // Subempreitadas
        $externos = [];
        $porId = $membros->keyBy('id');
        $terceiros = Terceiro::query()->withTrashed()->whereIn('id', $membros->pluck('terceiro_id')->filter())->pluck('nome', 'id');
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->whereNotNull('atribuido_a_id')->where('valor_contrato', '>', 0)->orderBy('id')->get();
        $medido = LinhaRevisaoProjeto::query()->whereIn('tarefa_projeto_id', $tarefas->pluck('id'))->groupBy('tarefa_projeto_id')
            ->selectRaw('tarefa_projeto_id, MAX(percentagem_atual) AS m')->pluck('m', 'tarefa_projeto_id');
        foreach ($tarefas as $t) {
            $m = $porId[$t->atribuido_a_id] ?? null;
            if (! $m || $m->colaborador_id) {
                continue;
            }
            $atual = (int) round((float) $t->percentagem_execucao);
            $anterior = (float) ($medido[$t->id] ?? 0);
            $pct = max(0, $atual - $anterior);
            if ($pct <= 0) {
                continue;
            }
            $externos[] = ['membro_id' => $m->id, 'terceiro_id' => $m->terceiro_id, 'nome' => $m->terceiro_id ? ($terceiros[$m->terceiro_id] ?? 'Desconhecido') : $m->nome_externo,
                'tarefa_projeto_id' => $t->id, 'tarefa' => trim("{$t->codigo} {$t->nome}"), 'valor_contrato' => ServicoAnaliticoProjetos::dinheiro($t->valor_contrato),
                'percentagem_anterior' => $anterior, 'percentagem_atual' => $atual, 'percentagem_faturavel' => $pct,
                'valor' => ServicoAnaliticoProjetos::dinheiro(bcmul(bcdiv((string) $pct, '100', 8), (string) $t->valor_contrato, 6))];
        }

        return ['mes' => $mes, 'ano' => $ano, 'revisao_existente' => $revisoesMes->isNotEmpty(), 'internos' => $internos, 'externos' => $externos,
            'equipamentos' => $this->equipamentosDoMes($p, $mes, $ano)];
    }

    /**
     * Executa a revisão (executeReview).
     *
     * @param  array{mes: int, ano: int, confirmar_aditamento?: bool, produto_subempreitada_id?: ?int}  $d
     */
    public function executar(Projeto $p, array $d, ServicoFaturasCompra $faturas): RevisaoMensalProjeto
    {
        $this->projetos->exigirAberto($p);
        $mes = (int) $d['mes'];
        $ano = (int) $d['ano'];

        return DB::transaction(function () use ($p, $d, $mes, $ano, $faturas) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["revisao_projeto:{$p->id}:{$ano}:{$mes}"]);
            $sim = $this->simular($p, $mes, $ano);
            $comDiferencial = collect($sim['externos'])->contains(fn ($e) => (float) $e['valor'] > 0) || collect($sim['internos'])->contains(fn ($i) => (float) $i['custo'] > 0);
            if (! $comDiferencial) {
                throw new ErroNegocio('Não há diferenciais de execução nem custos de mão de obra por processar neste mês: não é necessário um auto vazio.', 'SEM_DIFERENCIAIS', 422);
            }
            if ($sim['revisao_existente'] && empty($d['confirmar_aditamento'])) {
                throw new ErroNegocio('Já existe uma revisão processada para este mês. Confirme para gerar um aditamento ao auto (só com os dados que evoluíram).', 'REVISAO_EXISTENTE', 422);
            }
            $comFatura = collect($sim['externos'])->filter(fn ($e) => $e['terceiro_id'] && (float) $e['valor'] > 0);
            $produto = $comFatura->isNotEmpty() ? ($d['produto_subempreitada_id'] ?? $this->projetos->configuracao()['produto_subempreitada_id'] ?? null) : null;
            if ($comFatura->isNotEmpty() && ! $produto) {
                throw new ErroNegocio('Indique o artigo (serviço) das facturas de subempreitada, no pedido ou na configuração dos projectos.', 'PRODUTO_AUTOS_EM_FALTA', 422);
            }
            $rev = RevisaoMensalProjeto::create(['projeto_id' => $p->id, 'mes' => $mes, 'ano' => $ano, 'estado' => 'PROCESSADO']);
            $hoje = now()->toDateString();
            foreach ($sim['externos'] as $e) {
                $docId = null;
                if ($e['terceiro_id'] && (float) $e['valor'] > 0) {
                    $f = $faturas->registarDireta(['fornecedor_id' => $e['terceiro_id'], 'data' => $hoje, 'projeto_id' => $p->id,
                        'unidade_negocio_id' => $p->unidade_negocio_id, 'centro_custo_id' => $p->centro_custo_id,
                        'numero_fatura' => sprintf('AUTO-%d-%04d%02d-%d-%d-R%d', $p->id, $ano, $mes, $e['membro_id'], $e['tarefa_projeto_id'], $rev->id),
                        'linhas' => [['produto_id' => (int) $produto, 'quantidade' => 1, 'preco_unitario' => $e['valor'],
                            'descricao' => mb_substr("Auto de Medição (Projecto) - Tarefa {$e['tarefa']} (+{$e['percentagem_faturavel']}%)", 0, 500)]]]);
                    ItemCompra::query()->where('fatura_compra_id', $f->id)->update(['tarefa_projeto_id' => $e['tarefa_projeto_id']]);
                    $docId = $f->id;
                }
                LinhaRevisaoProjeto::create(['revisao_mensal_projeto_id' => $rev->id, 'tipo' => LinhaRevisaoProjeto::SUBEMPREITADA, 'tipo_original' => 'SUBCONTRACT',
                    'terceiro_id' => $e['terceiro_id'], 'tarefa_projeto_id' => $e['tarefa_projeto_id'], 'percentagem_anterior' => $e['percentagem_anterior'],
                    'percentagem_atual' => $e['percentagem_atual'], 'valor_calculado' => $e['valor'], 'documento_gerado_id' => $docId ? (string) $docId : null]);
            }
            $data = min(ServicoAnaliticoProjetos::ultimoDia($ano, $mes), $hoje);
            foreach ($sim['internos'] as $i) {
                $docId = null;
                if ((float) $i['custo'] > 0) {
                    $docId = RazaoAnaliticoProjeto::create(['projeto_id' => $p->id, 'rubrica' => 'MAO_DE_OBRA', 'modulo_origem' => 'RH', 'tipo_documento_origem' => 'AUTO_INTERNO',
                        'tipo_documento_origem_original' => 'AUTO_INTERNO', 'natureza' => 'CUSTO_REAL', 'natureza_original' => 'custo_real', 'data' => $data,
                        'valor' => $i['custo'], 'montante' => $i['custo'], 'documento_origem_id' => "REV-{$rev->id}",
                        'descricao' => "Imputação de Mão de Obra - {$i['nome']}"])->id;
                }
                LinhaRevisaoProjeto::create(['revisao_mensal_projeto_id' => $rev->id, 'tipo' => LinhaRevisaoProjeto::MAO_OBRA, 'tipo_original' => 'LABOR',
                    'colaborador_id' => $i['colaborador_id'], 'valor_calculado' => $i['custo'], 'documento_gerado_id' => $docId ? (string) $docId : null]);
            }
            $this->projetos->registar($p->id, 'Revisão mensal', sprintf('REV-%d (%02d/%04d)%s', $rev->id, $mes, $ano, $sim['revisao_existente'] ? ' — aditamento' : ''));

            return $rev->refresh();
        });
    }

    /** Lista das revisões com os totais (loadProjectTab 'revisoes', ui_projects.js:613-649). */
    public function listar(Projeto $p): array
    {
        $revs = RevisaoMensalProjeto::query()->where('projeto_id', $p->id)->orderByDesc('id')->get();
        $linhas = LinhaRevisaoProjeto::query()->whereIn('revisao_mensal_projeto_id', $revs->pluck('id'))->get()->groupBy('revisao_mensal_projeto_id');
        $faturadas = $this->faturasDeAutos($p);

        return $revs->map(fn ($r) => $r->toArray() + [
            'referencia' => "REV-{$r->id}",
            'total_subempreitadas' => self::soma(($linhas[$r->id] ?? collect())->where('tipo', LinhaRevisaoProjeto::SUBEMPREITADA)),
            'total_mao_obra' => self::soma(($linhas[$r->id] ?? collect())->where('tipo', LinhaRevisaoProjeto::MAO_OBRA)),
            'total_equipamentos' => $this->equipamentosDoMes($p, (int) $r->mes, (int) $r->ano)['total'],
            'faturavel' => $p->externo() && (bool) $p->encomenda_venda_id,
            'faturada' => $faturadas[$r->id] ?? null,
        ])->all();
    }

    /** Detalhe impresso do auto (viewReview). */
    public function detalhe(Projeto $p, RevisaoMensalProjeto $r): array
    {
        $linhas = LinhaRevisaoProjeto::query()->where('revisao_mensal_projeto_id', $r->id)->orderBy('id')->get();
        $terc = Terceiro::query()->withTrashed()->whereIn('id', $linhas->pluck('terceiro_id')->filter())->pluck('nome', 'id');
        $colab = Colaborador::query()->withTrashed()->whereIn('id', $linhas->pluck('colaborador_id')->filter())->pluck('nome_completo', 'id');
        $tarefas = TarefaProjeto::query()->whereIn('id', $linhas->pluck('tarefa_projeto_id')->filter())->pluck('nome', 'id');
        $eq = $this->equipamentosDoMes($p, (int) $r->mes, (int) $r->ano);
        $sub = $linhas->where('tipo', LinhaRevisaoProjeto::SUBEMPREITADA);
        $mo = $linhas->where('tipo', LinhaRevisaoProjeto::MAO_OBRA);
        $totSub = self::soma($sub);
        $totMo = self::soma($mo);

        return ['revisao' => $r->toArray() + ['referencia' => "REV-{$r->id}"], 'projeto' => $p->only(['id', 'codigo', 'nome']),
            'cliente' => $p->cliente_id ? Terceiro::query()->withTrashed()->find($p->cliente_id)?->nome : 'Interno',
            'subempreitadas' => $sub->map(fn ($l) => $l->toArray() + ['nome' => $l->terceiro_id ? ($terc[$l->terceiro_id] ?? 'Ext') : 'Empreiteiro',
                'tarefa' => $tarefas[$l->tarefa_projeto_id] ?? null])->values()->all(),
            'mao_obra' => $mo->map(fn ($l) => $l->toArray() + ['nome' => $l->colaborador_id ? ($colab[$l->colaborador_id] ?? 'Int') : 'Colaborador'])->values()->all(),
            'equipamentos' => $eq['linhas'],
            'totais' => ['subempreitadas' => $totSub, 'mao_obra' => $totMo, 'equipamentos' => $eq['total'], 'geral' => bcadd(bcadd($totSub, $totMo, 2), $eq['total'], 2)]];
    }

    // ───────────── Facturação do auto ─────────────

    /** Proposta de facturação (billReview, ui_projects.js:3104-3182). */
    public function propostaFaturacao(Projeto $p, RevisaoMensalProjeto $r): array
    {
        $enc = $this->exigirFaturavel($p);
        $global = ServicoPlaneamentoProjetos::progresso(TarefaProjeto::query()->where('projeto_id', $p->id)->get())['global'];
        $venda = ServicoAnaliticoProjetos::semIva($enc);
        $faturado = ServicoAnaliticoProjetos::totais($this->analitico->vendasDosProjetos(collect([$p->id => $p])))['proveitos'];
        $devido = ServicoAnaliticoProjetos::dinheiro(bcmul($venda, bcdiv((string) $global, '100', 8), 6));
        $taxas = ItemVenda::query()->where('venda_id', $enc->id)->pluck('taxa_imposto')->map(fn ($t) => (float) $t)->filter(fn ($t) => in_array($t, [14.0, 7.0, 5.0, 0.0], true));

        return ['revisao_id' => $r->id, 'execucao_global' => $global, 'venda' => $venda, 'faturado' => $faturado,
            'sugerido' => bccomp($devido, $faturado, 2) > 0 ? bcsub($devido, $faturado, 2) : '0.00',
            'taxa_iva_encomenda' => $taxas->isEmpty() ? 14.0 : (float) $taxas->map(fn ($t) => (string) $t)->countBy()->sortDesc()->keys()->first(),
            'ja_faturado_por' => $this->faturasDeAutos($p)[$r->id] ?? null];
    }

    /**
     * Emite o documento de venda do auto (confirmAutoBill).
     *
     * @param  array{tipo_documento: string, valor: mixed, produto_id?: ?int, data_emissao?: ?string, conta_disponibilidade?: ?string, meio_pagamento?: ?string}  $d
     */
    public function faturar(Projeto $p, RevisaoMensalProjeto $r, array $d, ServicoDocumentosVenda $vendas): Venda
    {
        $this->projetos->exigirAberto($p);
        $enc = $this->exigirFaturavel($p);
        $tipo = $d['tipo_documento'];
        if (! in_array($tipo, self::TIPOS_FATURACAO, true)) {
            throw new ErroNegocio('Tipo de documento inválido (FT, FR ou PF).', 'TIPO_INVALIDO', 422);
        }
        $valor = ServicoAnaliticoProjetos::dinheiro($d['valor'] ?? 0);
        if ((float) $valor <= 0) {
            throw new ErroNegocio('Introduza um valor válido.', 'VALOR_INVALIDO', 422);
        }
        $produto = $d['produto_id'] ?? $this->projetos->configuracao()['produto_faturacao_id'] ?? null;
        if (! $produto) {
            throw new ErroNegocio('Indique o artigo (serviço) da factura do auto, no pedido ou na configuração dos projectos.', 'PRODUTO_FATURACAO_EM_FALTA', 422);
        }

        return DB::transaction(function () use ($p, $r, $d, $enc, $tipo, $valor, $produto, $vendas) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["faturacao_auto:{$r->id}"]);
            if ($tipo !== 'PF' && ($ja = $this->faturasDeAutos($p)[$r->id] ?? null)) {
                throw new ErroNegocio("Este auto já foi facturado ({$ja}). Consulte o módulo de Vendas.", 'AUTO_JA_FATURADO', 422, ['documento' => $ja]);
            }
            $v = $vendas->emitir(['tipo_documento' => $tipo, 'cliente_id' => $enc->cliente_id, 'data_emissao' => $d['data_emissao'] ?? now()->toDateString(),
                'linhas' => [['produto_id' => (int) $produto, 'quantidade' => 1, 'preco_unitario' => $valor, 'descricao' => "Serviços / Auto de Medição (REV-{$r->id}) ref. proj: {$p->codigo}"]],
                'observacoes' => "Faturação de Auto de Medição (REV-{$r->id}). Referência Encomenda: {$enc->numero_documento}",
                'projeto_id' => $p->id, 'conta_disponibilidade' => $d['conta_disponibilidade'] ?? null, 'meio_pagamento' => $d['meio_pagamento'] ?? null]);
            $this->projetos->registar($p->id, 'Facturar auto', "REV-{$r->id} → {$v->numero_documento} ({$valor} sem IVA)");

            return $v;
        });
    }

    // ───────────── Auxiliares ─────────────

    /** Documentos definitivos (não PF, não anulados) já emitidos por auto: revisão id => n.º do documento. */
    private function faturasDeAutos(Projeto $p): array
    {
        $saida = [];
        $vs = Venda::query()->where('tipo_documento', '<>', 'PF')->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->where('observacoes', 'like', 'Faturação de Auto de Medição (REV-%')
            ->where(fn ($q) => $q->where('projeto_id', $p->id)->when($p->encomenda_venda_id, fn ($x) => $x->orWhereIn('id',
                DB::table('vendas_documentos_relacionados')->where('empresa_id', $this->contexto->obrigatorio())->where('venda_relacionada_id', $p->encomenda_venda_id)->select('venda_id'))))
            ->orderBy('id')->get(['id', 'numero_documento', 'observacoes']);
        foreach ($vs as $v) {
            if (preg_match('/\(REV-(\d+)\)/', (string) $v->observacoes, $m)) {
                $saida[(int) $m[1]] ??= $v->numero_documento;
            }
        }

        return $saida;
    }

    private function exigirFaturavel(Projeto $p): Venda
    {
        if (! $p->externo() || ! $p->encomenda_venda_id) {
            throw new ErroNegocio('Só se facturam autos de projectos externos com encomenda.', 'PROJETO_NAO_FATURAVEL', 422);
        }

        return Venda::query()->find($p->encomenda_venda_id) ?? throw new ErroNegocio('A encomenda do projecto já não existe.', 'ENCOMENDA_INEXISTENTE', 422);
    }

    /** Equipamentos do mês (razão CUSTOS_EQUIPAMENTO), agrupados pelo documento (MAQ-<código>). */
    private function equipamentosDoMes(Projeto $p, int $mes, int $ano): array
    {
        $movs = RazaoAnaliticoProjeto::query()->where('projeto_id', $p->id)->where('rubrica', 'CUSTOS_EQUIPAMENTO')
            ->whereBetween('data', [sprintf('%04d-%02d-01', $ano, $mes), ServicoAnaliticoProjetos::ultimoDia($ano, $mes)])->orderBy('id')->get();
        $grupos = [];
        foreach ($movs as $m) {
            $k = (string) $m->documento_origem_id;
            $grupos[$k] ??= ['codigo' => $k, 'descricao' => $m->descricao, 'valor' => '0.00'];
            $grupos[$k]['valor'] = bcadd($grupos[$k]['valor'], ServicoAnaliticoProjetos::dinheiro($m->montante ?? $m->valor), 2);
        }

        return ['linhas' => array_values($grupos), 'total' => array_reduce($grupos, fn ($s, $g) => bcadd($s, $g['valor'], 2), '0.00')];
    }

    /** Valor/dia do contrato: soma das remunerações de vencimento (chaves novas ou do legado). */
    private static function valorDiaContrato(?ContratoTrabalho $c, Collection $vencimentos): string
    {
        $v = '0';
        foreach ($c?->remuneracoes ?? [] as $r) {
            $inf = (int) ($r['infotipo_id'] ?? $r['infotype_id'] ?? 0);
            if (isset($vencimentos[$inf])) {
                $v = bcadd($v, (string) ($r['valor_dia'] ?? $r['value_per_day'] ?? 0), 8);
            }
        }

        return $v;
    }

    private static function soma(Collection $linhas): string
    {
        return $linhas->reduce(fn ($s, $l) => bcadd($s, ServicoAnaliticoProjetos::dinheiro($l->valor_calculado), 2), '0.00');
    }

    private static function validarMes(int $mes, int $ano): void
    {
        if ($mes < 1 || $mes > 12 || $ano < 2000 || $ano > 2100) {
            throw new ErroNegocio('Mês ou ano inválido.', 'PERIODO_INVALIDO', 422);
        }
    }
}
