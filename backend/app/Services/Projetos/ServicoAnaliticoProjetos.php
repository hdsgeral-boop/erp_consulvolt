<?php

namespace App\Services\Projetos;

use App\Models\AditamentoAlteracaoProjeto;
use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\EncomendaCompra;
use App\Models\EquipaProjeto;
use App\Models\FaturaCompra;
use App\Models\FolhaHorasProjeto;
use App\Models\ItemCompra;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\LinhaRevisaoProjeto;
use App\Models\MarcoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\RequisicaoMaterialProjeto;
use App\Models\RevisaoMensalProjeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Razão analítico de projectos: custos, proveitos e compromissos por projecto e tarefa, com UMA regra para todos os
 * ecrãs (extracto, resumo, organigrama, fluxo e rentabilidade). Fontes, sempre sem IVA:
 *   - razao_analitico_projetos: mão de obra dos autos e do processamento salarial, equipamentos, compromissos;
 *   - vendas: FT/FR não anuladas ligadas à encomenda do projecto (vendas_documentos_relacionados) ou com projeto_id,
 *     menos as NC que as rectificam (projFacturacaoSemIva, ui_projects.js:18-26);
 *   - compras: linhas de facturas de fornecedor não anuladas com projeto_id na linha ou no cabeçalho (a subempreitada
 *     facturada pelos autos entra por aqui, pela sua factura);
 *   - compromissos: linhas de encomendas de compra não anuladas, pela parte ainda por facturar (regra do ADR-045);
 *   - autos de subempreitada SEM documento (membro externo sem ficha de terceiro): o valor da linha do auto.
 * Os proveitos do razão gravados na facturação de um auto (origem VENDAS) referem-se às mesmas facturas e não se
 * contam duas vezes (ui_projects.js:94).
 * Correcções face ao legado (os ecrãs somavam as mesmas despesas de formas diferentes):
 *   - o resumo e o fluxo somavam as linhas dos autos E as facturas que esses autos geravam (subempreitada em
 *     duplicado) e contavam também as linhas de pedidos e encomendas de compra (projectos_dashboard.js:177-183,
 *     fluxo_projectos.js:151-153); o organigrama somava auto + factura por tarefa (projectos_organigrama.js:167-173);
 *   - o extracto lia as linhas de compra pelo id do documento-pai sem ver o tipo, misturando ids de pedidos e
 *     encomendas com ids de facturas (ui_projects.js:114-116): contam só linhas de FACTURA; a encomenda por facturar é
 *     compromisso (o cartão «Total Compromissos» do extracto nunca era alimentado);
 *   - facturas anuladas e documentos de venda anulados deixam de contar;
 *   - uma venda com projeto_id conta para o projecto (no legado o projecto escolhido nos documentos nunca chegava
 *     ao projecto: ProjectAPI.postLedgerEntry não era chamado por nenhum módulo).
 */
final class ServicoAnaliticoProjetos
{
    public const RUBRICAS = ['MATERIAIS', 'MAO_DE_OBRA', 'SUBCONTRATOS', 'EQUIPAMENTOS', 'DIVERSOS'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
    ) {}

    // ───────────── Movimentos ─────────────

    /**
     * @param  list<int>|null  $ids  projectos (null = todos)
     * @return list<array<string, mixed>>
     */
    public function movimentos(?array $ids = null, ?string $inicio = null, ?string $fim = null): array
    {
        $projetos = Projeto::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get()->keyBy('id');
        if ($projetos->isEmpty()) {
            return [];
        }
        $pids = $projetos->keys()->all();
        $saida = [];

        // 1. Vendas (proveitos sem IVA)
        $vendas = $this->vendasDosProjetos($projetos);
        $numerosVenda = [];
        foreach ($vendas as $v) {
            $numerosVenda[$v['projeto_id']][(string) $v['documento']] = true;
            $saida[] = $v;
        }

        // 2. Razão analítico
        foreach (RazaoAnaliticoProjeto::query()->whereIn('projeto_id', $pids)->orderBy('id')->get() as $r) {
            $natureza = self::natureza($r->natureza, $r->natureza_original);
            if ($natureza === 'PROVEITO' && strtoupper((string) $r->modulo_origem) === 'VENDAS' && isset($numerosVenda[$r->projeto_id][(string) $r->documento_origem_id])) {
                continue;
            }
            $saida[] = ['data' => $r->data?->toDateString(), 'projeto_id' => $r->projeto_id, 'tarefa_projeto_id' => $r->tarefa_projeto_id, 'natureza' => $natureza,
                'rubrica' => $r->rubrica ?: 'GERAL', 'categoria' => $natureza === 'CUSTO' ? self::categoriaRazao($r->rubrica) : null,
                'origem' => $natureza === 'CUSTO' ? self::origemRazao($r) : null, 'modulo' => $r->modulo_origem,
                'tipo_documento' => $r->tipo_documento_origem_original ?: $r->tipo_documento_origem, 'documento' => $r->documento_origem_id, 'descricao' => $r->descricao,
                'valor' => self::dinheiro($r->montante ?? $r->valor), 'fonte' => 'RAZAO', 'fonte_id' => $r->id];
        }

        // 3. Compras: linhas de facturas de fornecedor
        $faturasAutos = LinhaRevisaoProjeto::query()->where('tipo', LinhaRevisaoProjeto::SUBEMPREITADA)->whereNotNull('documento_gerado_id')
            ->join('revisoes_mensais_projeto as r', 'r.id', '=', 'linhas_revisao_projeto.revisao_mensal_projeto_id')->whereIn('r.projeto_id', $pids)
            ->get(['r.projeto_id', 'linhas_revisao_projeto.documento_gerado_id'])->map(fn ($x) => $x->projeto_id.':'.$x->documento_gerado_id)->flip();
        $itens = ItemCompra::query()->where('tipo_documento_origem', 'FATURA')->whereNotNull('fatura_compra_id')
            ->where(fn ($q) => $q->whereIn('projeto_id', $pids)->orWhere(fn ($x) => $x->whereNull('projeto_id')
                ->whereIn('fatura_compra_id', FaturaCompra::query()->whereIn('projeto_id', $pids)->select('id'))))->orderBy('id')->get();
        $faturas = FaturaCompra::query()->whereIn('id', $itens->pluck('fatura_compra_id')->unique())->get()->keyBy('id');
        foreach ($itens as $i) {
            $f = $faturas[$i->fatura_compra_id] ?? null;
            if (! $f || $f->estado === 'ANULADA') {
                continue;
            }
            $pid = $i->projeto_id ?? $f->projeto_id;
            $auto = isset($faturasAutos[$pid.':'.$f->id]);
            $saida[] = ['data' => $f->data?->toDateString(), 'projeto_id' => $pid, 'tarefa_projeto_id' => $i->tarefa_projeto_id, 'natureza' => 'CUSTO',
                'rubrica' => $auto ? 'SUBEMPREITADAS' : 'COMPRAS_MATERIAIS', 'categoria' => $auto ? 'SUBCONTRATOS' : 'MATERIAIS',
                'origem' => $auto ? 'AUTOS_SUBEMPREITADAS' : 'COMPRAS', 'modulo' => 'COMPRAS', 'tipo_documento' => 'Factura de fornecedor',
                'documento' => $f->numero_fatura, 'descricao' => $i->descricao ?: 'Alocação de material ao projecto',
                'valor' => self::dinheiro($i->total_kz ?? $i->total ?? bcmul((string) ($i->quantidade ?? 0), (string) ($i->preco_unitario ?? 0), 4)),
                'fonte' => 'COMPRA', 'fonte_id' => $i->id];
        }

        // 4. Compromissos: encomendas de compra pela parte ainda por facturar (regra do ADR-045)
        $linhasEnc = ItemCompra::query()->where('tipo_documento_origem', 'ENCOMENDA')->whereNotNull('encomenda_compra_id')
            ->where(fn ($q) => $q->whereIn('projeto_id', $pids)->orWhere(fn ($x) => $x->whereNull('projeto_id')
                ->whereIn('encomenda_compra_id', EncomendaCompra::query()->whereIn('projeto_id', $pids)->select('id'))))->orderBy('id')->get();
        $encomendas = EncomendaCompra::query()->whereIn('id', $linhasEnc->pluck('encomenda_compra_id')->unique())->get()->keyBy('id');
        foreach ($linhasEnc as $i) {
            $e = $encomendas[$i->encomenda_compra_id] ?? null;
            $q = (float) $i->quantidade;
            if (! $e || in_array($e->estado, ['ANULADA', 'CANCELADA'], true) || $q <= 0) {
                continue;
            }
            $total = $i->total_kz ?? $i->total ?? bcmul((string) $i->quantidade, (string) ($i->preco_unitario ?? 0), 4);
            $valor = self::dinheiro(bcmul((string) $total, sprintf('%.8F', max(0, $q - (float) $i->quantidade_faturada) / $q), 8));
            if ((float) $valor > 0) {
                $saida[] = ['data' => $e->data?->toDateString(), 'projeto_id' => $i->projeto_id ?? $e->projeto_id, 'tarefa_projeto_id' => $i->tarefa_projeto_id,
                    'natureza' => 'COMPROMISSO', 'rubrica' => 'ENCOMENDAS_COMPRA', 'categoria' => null, 'origem' => null, 'modulo' => 'COMPRAS',
                    'tipo_documento' => 'Encomenda a fornecedor', 'documento' => $e->numero_encomenda, 'descricao' => $i->descricao ?: 'Encomenda por facturar',
                    'valor' => $valor, 'fonte' => 'ENCOMENDA', 'fonte_id' => $i->id];
            }
        }

        // 5. Autos de subempreitada sem documento
        $semDoc = LinhaRevisaoProjeto::query()->where('tipo', LinhaRevisaoProjeto::SUBEMPREITADA)->whereNull('documento_gerado_id')
            ->join('revisoes_mensais_projeto as r', 'r.id', '=', 'linhas_revisao_projeto.revisao_mensal_projeto_id')->whereIn('r.projeto_id', $pids)
            ->get(['linhas_revisao_projeto.*', 'r.projeto_id', 'r.mes', 'r.ano']);
        foreach ($semDoc as $l) {
            $saida[] = ['data' => self::ultimoDia((int) $l->ano, (int) $l->mes), 'projeto_id' => $l->projeto_id, 'tarefa_projeto_id' => $l->tarefa_projeto_id,
                'natureza' => 'CUSTO', 'rubrica' => 'SUBEMPREITADAS', 'categoria' => 'SUBCONTRATOS', 'origem' => 'AUTOS_SUBEMPREITADAS', 'modulo' => 'PROJECTOS',
                'tipo_documento' => 'Auto de medição', 'documento' => "REV-{$l->revisao_mensal_projeto_id}", 'descricao' => 'Auto de subempreitada (sem documento)',
                'valor' => self::dinheiro($l->valor_calculado), 'fonte' => 'AUTO', 'fonte_id' => $l->id];
        }

        return array_values(array_filter($saida, fn ($m) => (! $inicio || ($m['data'] && $m['data'] >= $inicio)) && (! $fim || ($m['data'] && $m['data'] <= $fim))));
    }

    /**
     * Facturação de venda dos projectos (FT/FR ligadas à encomenda ou com projeto_id, menos NC).
     *
     * @param  Collection<int, Projeto>  $projetos
     * @return list<array<string, mixed>>
     */
    public function vendasDosProjetos(Collection $projetos): array
    {
        $empresa = $this->contexto->obrigatorio();
        $porEncomenda = $projetos->filter(fn ($p) => $p->encomenda_venda_id)->mapWithKeys(fn ($p) => [$p->encomenda_venda_id => $p->id]);
        $rel = DB::table('vendas_documentos_relacionados')->where('empresa_id', $empresa)->whereIn('venda_relacionada_id', $porEncomenda->keys())->get();
        $viaEncomenda = $rel->mapWithKeys(fn ($r) => [$r->venda_id => $porEncomenda[$r->venda_relacionada_id]]);
        $pids = $projetos->keys()->all();
        $validas = fn ($q) => $q->where(fn ($x) => $x->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'));
        $fts = Venda::query()->whereIn('tipo_documento', ['FT', 'FR'])->where($validas)
            ->where(fn ($q) => $q->whereIn('id', $viaEncomenda->keys())->orWhereIn('projeto_id', $pids))->orderBy('id')->get();
        $projetoDe = [];
        foreach ($fts as $v) {
            $projetoDe[$v->id] = in_array($v->projeto_id, $pids, true) ? $v->projeto_id : $viaEncomenda[$v->id];
        }
        $relNc = $fts->isEmpty() ? collect() : DB::table('vendas_documentos_relacionados')->where('empresa_id', $empresa)->whereIn('venda_relacionada_id', $fts->pluck('id'))->get()
            ->mapWithKeys(fn ($r) => [$r->venda_id => $r->venda_relacionada_id]);
        $ncs = Venda::query()->where('tipo_documento', 'NC')->where($validas)
            ->where(fn ($q) => $q->whereIn('id', $relNc->keys())->orWhereIn('projeto_id', $pids))->orderBy('id')->get();
        $saida = [];
        foreach ($fts->concat($ncs) as $v) {
            $nc = $v->tipo_documento === 'NC';
            $pid = $nc ? (in_array($v->projeto_id, $pids, true) ? $v->projeto_id : ($projetoDe[$relNc[$v->id] ?? 0] ?? null)) : $projetoDe[$v->id];
            if (! $pid) {
                continue;
            }
            $valor = self::semIva($v);
            $saida[] = ['data' => $v->data_emissao?->toDateString(), 'projeto_id' => $pid, 'tarefa_projeto_id' => null, 'natureza' => 'PROVEITO',
                'rubrica' => 'PROVEITOS_FATURACAO', 'categoria' => null, 'origem' => null, 'modulo' => 'VENDAS', 'tipo_documento' => $v->tipo_documento,
                'documento' => $v->numero_documento, 'descricao' => $v->observacoes ?: ($nc ? 'Nota de crédito ao cliente' : 'Factura de venda ao cliente'),
                'valor' => $nc ? '-'.ltrim($valor, '-') : $valor, 'fonte' => 'VENDA', 'fonte_id' => $v->id,
                'valor_pendente' => $nc ? '0.00' : self::dinheiro($v->valor_pendente ?? 0), 'data_vencimento' => $v->data_vencimento?->toDateString(),
                'total_bruto' => self::dinheiro($v->total_bruto ?? 0), 'contabilizado' => (bool) $v->contabilizado];
        }

        return $saida;
    }

    /** Valor sem IVA de um documento de venda (projValorSemIva, ui_projects.js:10-16). */
    public static function semIva(?Venda $v): string
    {
        if (! $v) {
            return '0.00';
        }
        if ($v->total_liquido !== null) {
            return self::dinheiro($v->total_liquido);
        }

        return bcsub(self::dinheiro($v->total_bruto ?? $v->montante_total ?? 0), self::dinheiro($v->total_imposto ?? 0), 2);
    }

    /** @return array{custos: string, proveitos: string, compromissos: string} */
    public static function totais(array $movimentos): array
    {
        $t = ['custos' => '0.00', 'proveitos' => '0.00', 'compromissos' => '0.00'];
        foreach ($movimentos as $m) {
            $k = ['CUSTO' => 'custos', 'PROVEITO' => 'proveitos', 'COMPROMISSO' => 'compromissos'][$m['natureza']] ?? null;
            if ($k) {
                $t[$k] = bcadd($t[$k], $m['valor'], 2);
            }
        }

        return $t;
    }

    /** Extracto analítico (loadProjectExtract, ui_projects.js:65-208): movimentos do mais recente para o mais antigo e totais. */
    public function extracto(?int $projetoId, ?string $inicio = null, ?string $fim = null): array
    {
        $movs = $this->movimentos($projetoId ? [$projetoId] : null, $inicio, $fim);
        $tarefas = TarefaProjeto::query()->whereIn('id', array_filter(array_column($movs, 'tarefa_projeto_id')))->get()->keyBy('id');
        $marcos = MarcoProjeto::query()->whereIn('id', $tarefas->pluck('marco_projeto_id')->filter())->pluck('nome', 'id');
        $codigos = Projeto::query()->whereIn('id', array_unique(array_column($movs, 'projeto_id')))->pluck('codigo', 'id');
        foreach ($movs as &$m) {
            $t = $m['tarefa_projeto_id'] ? ($tarefas[$m['tarefa_projeto_id']] ?? null) : null;
            $m['codigo_projeto'] = $codigos[$m['projeto_id']] ?? null;
            $m['tarefa'] = $t ? trim("{$t->codigo} {$t->nome}") : null;
            $m['marco'] = $t && $t->marco_projeto_id ? ($marcos[$t->marco_projeto_id] ?? null) : null;
        }
        unset($m);
        usort($movs, fn ($a, $b) => strcmp((string) $b['data'], (string) $a['data']) ?: ($b['fonte_id'] <=> $a['fonte_id']));

        return ['totais' => self::totais($movs), 'movimentos' => $movs];
    }

    /**
     * Custos, orçamento, horas e requisições por tarefa (custosPorTarefa, projectos_organigrama.js:153-178), com os
     * custos da regra única.
     *
     * @return array<int, array{orcamento: string, executado: string, horas: float, requisicoes: int, orc_por_rubrica: array, exec_por_rubrica: array}>
     */
    public function porTarefa(Projeto $p, ?array $movimentos = null): array
    {
        $movimentos ??= $this->movimentos([$p->id]);
        $saida = [];
        $de = function (int $id) use (&$saida) {
            return $saida[$id] ??= ['orcamento' => '0.00', 'executado' => '0.00', 'horas' => 0.0, 'requisicoes' => 0, 'orc_por_rubrica' => [], 'exec_por_rubrica' => []];
        };
        foreach (LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->whereNotNull('tarefa_projeto_id')->get() as $l) {
            $de($l->tarefa_projeto_id);
            $saida[$l->tarefa_projeto_id]['orcamento'] = bcadd($saida[$l->tarefa_projeto_id]['orcamento'], self::dinheiro($l->montante), 2);
            $r = $l->rubrica ?: 'DIVERSOS';
            $saida[$l->tarefa_projeto_id]['orc_por_rubrica'][$r] = bcadd($saida[$l->tarefa_projeto_id]['orc_por_rubrica'][$r] ?? '0', self::dinheiro($l->montante), 2);
        }
        foreach ($movimentos as $m) {
            if ($m['natureza'] !== 'CUSTO' || ! $m['tarefa_projeto_id']) {
                continue;
            }
            $id = (int) $m['tarefa_projeto_id'];
            $de($id);
            $saida[$id]['executado'] = bcadd($saida[$id]['executado'], $m['valor'], 2);
            $saida[$id]['exec_por_rubrica'][$m['categoria']] = bcadd($saida[$id]['exec_por_rubrica'][$m['categoria']] ?? '0', $m['valor'], 2);
        }
        foreach (FolhaHorasProjeto::query()->where('projeto_id', $p->id)->whereNotNull('tarefa_projeto_id')->get() as $h) {
            $de($h->tarefa_projeto_id);
            $saida[$h->tarefa_projeto_id]['horas'] += (float) $h->horas;
        }
        $reqs = DB::table('linhas_requisicao_projeto as l')->join('requisicoes_material_projeto as r', 'r.id', '=', 'l.requisicao_material_projeto_id')
            ->where('r.projeto_id', $p->id)->whereNotNull('l.tarefa_projeto_id')->groupBy('l.tarefa_projeto_id')->selectRaw('l.tarefa_projeto_id AS t, COUNT(*) AS n')->get();
        foreach ($reqs as $r) {
            $de((int) $r->t);
            $saida[(int) $r->t]['requisicoes'] = (int) $r->n;
        }

        return $saida;
    }

    // ───────────── Resumo (painel do projecto) ─────────────

    /** Painel de resumo (projectos_dashboard.js:78-652): indicadores, curva S, gráficos, alertas e ficha. */
    public function resumo(Projeto $p): array
    {
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->get();
        $prog = ServicoPlaneamentoProjetos::progresso($tarefas);
        $orcamento = LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->get();
        $movs = $this->movimentos([$p->id]);
        $tot = self::totais($movs);
        $hoje = now()->toDateString();

        // progresso ponderado pelo orçamento das tarefas (percentagem própria da tarefa)
        $orcTarefa = [];
        foreach ($orcamento->whereNotNull('tarefa_projeto_id') as $l) {
            $orcTarefa[$l->tarefa_projeto_id] = bcadd($orcTarefa[$l->tarefa_projeto_id] ?? '0', self::dinheiro($l->montante), 2);
        }
        $comOrc = $tarefas->filter(fn ($t) => isset($orcTarefa[$t->id]));
        $somaOrcT = $comOrc->sum(fn ($t) => (float) $orcTarefa[$t->id]);
        $ponderado = $somaOrcT > 0 ? (int) round($comOrc->sum(fn ($t) => (float) $orcTarefa[$t->id] * (float) $t->percentagem_execucao) / $somaOrcT) : null;

        $estados = ['PENDENTE' => 0, 'EM_CURSO' => 0, 'CONCLUIDA' => 0, 'BLOQUEADA' => 0];
        foreach ($tarefas as $t) {
            $e = $t->estadoEfetivo() === 'CONCLUIDA' || (float) $t->percentagem_execucao == 100 ? 'CONCLUIDA' : $t->estadoEfetivo();
            $estados[isset($estados[$e]) ? $e : 'PENDENTE']++;
        }

        $prazo = self::prazo($tarefas, $prog['global'], $hoje);

        $orcTotal = self::dinheiro($orcamento->sum(fn ($l) => (float) $l->montante));
        $orcPorRubrica = [];
        foreach ($orcamento as $l) {
            $r = $l->rubrica ?: 'DIVERSOS';
            $orcPorRubrica[$r] = bcadd($orcPorRubrica[$r] ?? '0', self::dinheiro($l->montante), 2);
        }
        $realPorRubrica = array_fill_keys(self::RUBRICAS, '0.00');
        $origem = array_fill_keys(['AUTOS_SUBEMPREITADAS', 'AUTOS_MAO_OBRA', 'COMPRAS', 'EQUIPAMENTOS', 'MAO_OBRA_ANALITICA', 'OUTROS'], '0.00');
        foreach ($movs as $m) {
            if ($m['natureza'] === 'CUSTO') {
                $realPorRubrica[$m['categoria']] = bcadd($realPorRubrica[$m['categoria']] ?? '0', $m['valor'], 2);
                $origem[$m['origem']] = bcadd($origem[$m['origem']], $m['valor'], 2);
            }
        }
        $custo = $tot['custos'];

        $venda = $p->encomenda_venda_id ? self::semIva(Venda::query()->find($p->encomenda_venda_id)) : '0.00';
        $aditamentos = AditamentoAlteracaoProjeto::query()->where('projeto_id', $p->id)->get();
        $extras = self::dinheiro($aditamentos->where('estado', 'APROVADO')->sum(fn ($a) => (float) $a->montante));
        $vendaGlobal = bcadd($venda, $extras, 2);
        $facturado = $tot['proveitos'];
        $pct = fn ($a, $b) => (float) $b > 0 ? round((float) $a / (float) $b * 100, 2) : 0.0;
        $consumo = $pct($custo, $orcTotal);
        $factPct = $pct($facturado, $vendaGlobal);

        // equipa, horas e organigrama
        $membros = MembroEquipaProjeto::query()->whereIn('equipa_projeto_id', EquipaProjeto::query()->where('projeto_id', $p->id)->select('id'))->get();
        $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->get();
        $horas = FolhaHorasProjeto::query()->where('projeto_id', $p->id)->whereNotNull('colaborador_id')->groupBy('colaborador_id')->selectRaw('colaborador_id, SUM(horas) AS h')->pluck('h', 'colaborador_id');
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $horas->keys())->pluck('nome_completo', 'id');
        $papeis = $membros->groupBy(fn ($m) => trim((string) $m->papel) ?: 'Membro')->map->count();
        $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->orderBy('data')->orderBy('id')->get();
        $porMarco = $marcos->filter(fn ($m) => $tarefas->where('marco_projeto_id', $m->id)->isNotEmpty())->map(fn ($m) => ['marco' => $m->nome, 'execucao' => $prog['por_marco'][$m->id] ?? 0,
            'tarefas' => $tarefas->where('marco_projeto_id', $m->id)->count()])->values()->all();
        if ($tarefas->whereNull('marco_projeto_id')->isNotEmpty()) {
            $porMarco[] = ['marco' => 'Sem milestone', 'execucao' => $prog['por_marco'][0] ?? 0, 'tarefas' => $tarefas->whereNull('marco_projeto_id')->count()];
        }
        $requisicoes = RequisicaoMaterialProjeto::query()->where('projeto_id', $p->id)->get();
        $revisoes = RevisaoMensalProjeto::query()->where('projeto_id', $p->id)->count();

        $indicadores = ['execucao' => $prog['global'], 'execucao_ponderada' => $ponderado, 'estados' => $estados, 'tarefas' => $tarefas->count(), 'prazo' => $prazo,
            'orcamento' => $orcTotal, 'custo' => $custo, 'disponivel' => bcsub($orcTotal, $custo, 2), 'consumo_pct' => $consumo,
            'desvio_orcamental_pct' => (float) $orcTotal > 0 ? round(((float) $custo / (float) $orcTotal - 1) * 100, 2) : null,
            'compromissos' => $tot['compromissos']];
        if ($p->externo()) {
            $margemPrev = bcsub($vendaGlobal, $orcTotal, 2);
            $margemReal = bcsub($facturado, $custo, 2);
            $indicadores += ['venda' => $venda, 'aditamentos_aprovados' => $extras, 'venda_global' => $vendaGlobal, 'faturado' => $facturado, 'faturado_pct' => $factPct,
                'margem_prevista' => $margemPrev, 'margem_prevista_pct' => $pct($margemPrev, $vendaGlobal), 'margem_real' => $margemReal, 'margem_real_pct' => $pct($margemReal, $facturado)];
        }

        return [
            'indicadores' => $indicadores,
            'curva_s' => $this->curvaS($p, $tarefas, $orcamento, $movs, $prazo, $hoje),
            'orcado_realizado' => array_values(array_map(fn ($r) => ['rubrica' => $r, 'orcado' => $orcPorRubrica[$r] ?? '0.00', 'realizado' => $realPorRubrica[$r] ?? '0.00'],
                array_values(array_unique(array_merge(array_keys($orcPorRubrica), array_keys(array_filter($realPorRubrica, fn ($v) => (float) $v > 0))))))),
            // mapas rubrica → valor: sempre objecto JSON, também quando vazios (ADR-064)
            'origem_custos' => (object) array_filter($origem, fn ($v) => (float) $v != 0),
            'orcamento_por_rubrica' => (object) $orcPorRubrica,
            'execucao_por_marco' => $porMarco,
            'horas_por_colaborador' => $horas->map(fn ($h, $id) => ['colaborador_id' => $id, 'nome' => $nomes[$id] ?? "#{$id}", 'horas' => (float) $h])->sortByDesc('horas')->take(10)->values()->all(),
            'equipa_por_papel' => $papeis->all(),
            'alertas' => $this->alertas($p, $tarefas, $prog, $prazo, $orcamento, $orcTarefa, $orcPorRubrica, $realPorRubrica, $consumo, $requisicoes, $aditamentos, $membros, $nos,
                (float) $vendaGlobal, $factPct, $hoje),
            'ficha' => $this->ficha($p, $membros, $marcos->count(), $revisoes, $nos->count(), (float) $horas->sum()),
            'contabilidade' => $this->contabilidade($p),
        ];
    }

    /** Prazo a partir das datas das tarefas (projectos_dashboard.js:156-169). */
    public static function prazo(Collection $tarefas, int $progresso, string $hoje): array
    {
        $ini = $tarefas->pluck('data_inicio')->filter()->min()?->toDateString();
        $fim = $tarefas->pluck('data_fim')->filter()->max()?->toDateString();
        $dias = fn ($a, $b) => (int) round((strtotime($b) - strtotime($a)) / 86400);
        $duracao = $ini && $fim ? $dias($ini, $fim) + 1 : 0;
        $decorridos = $ini ? min($duracao, max(0, $dias($ini, $hoje) + 1)) : 0;
        $tempoPct = $duracao ? min(100, $decorridos / $duracao * 100) : 0;

        return ['inicio' => $ini, 'fim' => $fim, 'duracao_dias' => $duracao, 'decorridos_dias' => $decorridos, 'restantes_dias' => $fim ? $dias($hoje, $fim) : null,
            'tempo_pct' => round($tempoPct, 2), 'desempenho' => $tempoPct > 0 ? round($progresso / $tempoPct, 4) : null];
    }

    /** Curva S mensal: orçamento previsto (repartido pelos dias das tarefas), custo realizado e facturação, acumulados (curvaS). */
    private function curvaS(Projeto $p, Collection $tarefas, Collection $orcamento, array $movs, array $prazo, string $hoje): ?array
    {
        $inicio = $prazo['inicio'] ?? $hoje;
        $fim = max($prazo['fim'] ?? $hoje, $hoje);
        $meses = [];
        for ($d = substr($inicio, 0, 7).'-01', $n = 0; substr($d, 0, 7) <= substr($fim, 0, 7) && $n < 240; $d = date('Y-m-01', strtotime("{$d} +1 month")), $n++) {
            $meses[] = substr($d, 0, 7);
        }
        if (! $meses) {
            return null;
        }
        $ultimo = end($meses);
        $prev = $real = $fact = array_fill_keys($meses, 0.0);
        $porId = $tarefas->keyBy('id');
        foreach ($orcamento as $l) {
            $t = $l->tarefa_projeto_id ? ($porId[$l->tarefa_projeto_id] ?? null) : null;
            self::repartir((float) $l->montante, $t?->data_inicio?->toDateString() ?? $inicio, $t?->data_fim?->toDateString() ?? $fim, $meses, $prev);
        }
        foreach ($movs as $m) {
            $mes = $m['data'] && isset($real[substr($m['data'], 0, 7)]) ? substr($m['data'], 0, 7) : $ultimo;
            if ($m['natureza'] === 'CUSTO') {
                $real[$mes] += (float) $m['valor'];
            } elseif ($m['natureza'] === 'PROVEITO' && $m['fonte'] === 'VENDA') {
                $fact[$mes] += (float) $m['valor'];
            }
        }
        $corte = array_search(substr($hoje, 0, 7), $meses, true);
        $ate = $corte === false ? count($meses) - 1 : $corte;
        $acum = function (array $v, bool $cortar) use ($meses, $ate) {
            $s = 0.0;
            $out = [];
            foreach ($meses as $i => $m) {
                $s += $v[$m];
                $out[] = $cortar && $i > $ate ? null : round($s, 2);
            }

            return $out;
        };

        return ['meses' => $meses, 'previsto' => $acum($prev, false), 'realizado' => $acum($real, true), 'faturado' => $p->externo() ? $acum($fact, true) : null];
    }

    /** Reparte um valor pelos meses na proporção dos dias de sobreposição (repartir, projectos_dashboard.js:57-74). */
    private static function repartir(float $valor, ?string $ini, ?string $fim, array $meses, array &$acc): void
    {
        $ultimo = end($meses);
        if (! $ini || ! $fim || $fim < $ini) {
            $acc[$ultimo] += $valor;

            return;
        }
        $dia = 86400;
        $total = max(1, (int) round((strtotime($fim) - strtotime($ini)) / $dia) + 1);
        $sobra = $valor;
        $usados = 0;
        foreach ($meses as $m) {
            $a = max($ini, "{$m}-01");
            $b = min($fim, date('Y-m-t', strtotime("{$m}-01")));
            if ($b < $a) {
                continue;
            }
            $dias = (int) round((strtotime($b) - strtotime($a)) / $dia) + 1;
            $parte = $valor * $dias / $total;
            $acc[$m] += $parte;
            $sobra -= $parte;
            $usados += $dias;
        }
        if ($usados === 0) {
            $acc[$ultimo] += $valor;
        } elseif (abs($sobra) > 0.01) {
            $acc[$ultimo] += $sobra;
        }
    }

    /** Alertas e pontos de atenção (projectos_dashboard.js:347-369). */
    private function alertas(Projeto $p, Collection $tarefas, array $prog, array $prazo, Collection $orcamento, array $orcTarefa, array $orcPorRubrica, array $realPorRubrica,
        float $consumo, Collection $requisicoes, Collection $aditamentos, Collection $membros, Collection $nos, float $vendaGlobal, float $factPct, string $hoje): array
    {
        $a = [];
        $al = function (string $codigo, string $nivel, string $titulo, string $texto, string $separador) use (&$a) {
            $a[] = compact('codigo', 'nivel', 'titulo', 'texto', 'separador');
        };
        $atrasadas = $tarefas->filter(fn ($t) => $t->data_fim && $t->data_fim->toDateString() < $hoje && $t->estadoEfetivo() !== 'CONCLUIDA' && (float) $t->percentagem_execucao < 100);
        if ($atrasadas->isNotEmpty()) {
            $al('TAREFAS_ATRASADAS', 'ERRO', "{$atrasadas->count()} tarefa(s) em atraso", $atrasadas->take(3)->pluck('nome')->implode(' · ').($atrasadas->count() > 3 ? ' …' : ''), 'planeamento');
        }
        if ($prazo['desempenho'] !== null && $prazo['desempenho'] < 0.9 && $tarefas->isNotEmpty()) {
            $al('EXECUCAO_ATRASADA', 'AVISO', 'Execução abaixo do tempo decorrido', "{$prog['global']}% executado para ".round($prazo['tempo_pct']).'% do prazo.', 'planeamento');
        }
        if ($consumo > 100) {
            $al('ORCAMENTO_ULTRAPASSADO', 'ERRO', 'Orçamento ultrapassado', "Custo realizado {$consumo}% do orçamento base.", 'orcamento');
        } elseif ($consumo >= 85 && $prog['global'] < 85) {
            $al('ORCAMENTO_QUASE_ESGOTADO', 'AVISO', 'Orçamento quase esgotado', "{$consumo}% consumido com {$prog['global']}% de execução física.", 'orcamento');
        }
        $excedidas = array_keys(array_filter($orcPorRubrica, fn ($v, $r) => (float) $v > 0 && (float) ($realPorRubrica[$r] ?? 0) > (float) $v, ARRAY_FILTER_USE_BOTH));
        if ($excedidas) {
            $al('RUBRICAS_EXCEDIDAS', 'AVISO', count($excedidas).' rubrica(s) acima do orçado', implode(', ', $excedidas), 'orcamento');
        }
        $comFilhos = $tarefas->pluck('tarefa_pai_id')->filter()->flip();
        $semOrc = $tarefas->filter(fn ($t) => ! isset($comFilhos[$t->id]) && ! isset($orcTarefa[$t->id]));
        if ($semOrc->isNotEmpty()) {
            $al('TAREFAS_SEM_ORCAMENTO', 'INFO', "{$semOrc->count()} tarefa(s) sem orçamento", 'As tarefas sem rubrica não entram no controlo de custos.', 'orcamento');
        }
        $semResp = $orcamento->filter(fn ($l) => ! $l->no_organigrama_projeto_id && ! $l->membro_equipa_projeto_id);
        if ($semResp->isNotEmpty()) {
            $al('ORCAMENTO_SEM_RESPONSAVEL', 'INFO', "{$semResp->count()} linha(s) de orçamento sem responsável", 'Mapeie o orçamento no organigrama (posição e membro).', 'organigrama');
        }
        if (($n = $requisicoes->where('estado', 'PENDENTE')->count()) > 0) {
            $al('REQUISICOES_PENDENTES', 'INFO', "{$n} requisição(ões) por aprovar", 'Pendentes de decisão nas compras.', 'requisicoes');
        }
        if (($n = $aditamentos->where('estado', '<>', 'APROVADO')->count()) > 0) {
            $al('ADITAMENTOS_PENDENTES', 'INFO', "{$n} aditamento(s) por aprovar", 'Só os aprovados somam à venda global.', 'aditamentos');
        }
        if ($nos->isNotEmpty() && ($n = $membros->whereNull('no_organigrama_projeto_id')->count()) > 0) {
            $al('MEMBROS_SEM_POSICAO', 'INFO', "{$n} membro(s) sem posição", 'Aloque-os no organigrama do projecto.', 'organigrama');
        }
        if ($p->externo() && $vendaGlobal > 0 && $factPct < 100 && $prog['global'] > $factPct + 15) {
            $al('FATURACAO_ATRASADA', 'AVISO', 'Facturação atrás da execução', "{$factPct}% facturado para {$prog['global']}% executado.", 'revisoes');
        }

        return $a;
    }

    private function ficha(Projeto $p, Collection $membros, int $marcos, int $revisoes, int $nos, float $horasLancadas): array
    {
        $enc = $p->encomenda_venda_id ? Venda::query()->find($p->encomenda_venda_id) : null;

        return $p->only(['id', 'codigo', 'nome', 'estado', 'tipo']) + [
            'cliente' => $p->cliente_id ? Terceiro::query()->withTrashed()->find($p->cliente_id)?->nome : null,
            'encomenda' => $enc?->numero_documento,
            'unidade_negocio' => $p->unidade_negocio_id ? UnidadeNegocio::query()->find($p->unidade_negocio_id)?->only(['codigo', 'nome']) : null,
            'centro_custo' => $p->centro_custo_id ? CentroCusto::query()->find($p->centro_custo_id)?->only(['codigo', 'descricao']) : null,
            'membros' => $membros->count(), 'internos' => $membros->whereNotNull('colaborador_id')->count(),
            'horas_dia_alocadas' => round((float) $membros->sum(fn ($m) => (float) $m->horas_alocadas), 3), 'horas_lancadas' => round($horasLancadas, 3),
            'marcos' => $marcos, 'revisoes' => $revisoes, 'posicoes_organigrama' => $nos,
        ];
    }

    /**
     * Confronto com a contabilidade: proveitos (classe 6, C − D) e custos (classe 7, D − C) lançados no Diário com o
     * projecto, sem os apuramentos (AP-*) — mesma base do ServicoExecucaoOrcamental (ADR-044).
     */
    public function contabilidade(Projeto $p, ?string $inicio = null, ?string $fim = null): array
    {
        $r = DB::table('lancamentos_contabeis as l')->leftJoin('diarios_contabeis as d', 'd.id', '=', 'l.diario_id')
            ->where('l.empresa_id', $this->contexto->obrigatorio())->where('l.projeto_id', $p->id)
            ->where(fn ($q) => $q->whereNull('d.codigo')->orWhere('d.codigo', 'not like', 'AP-%'))
            ->when($inicio, fn ($q) => $q->where('l.data_documento', '>=', $inicio))->when($fim, fn ($q) => $q->where('l.data_documento', '<=', $fim))
            ->selectRaw("COALESCE(SUM(CASE WHEN l.codigo_conta LIKE '6%' THEN (CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END) ELSE 0 END), 0) AS proveitos,
                COALESCE(SUM(CASE WHEN l.codigo_conta LIKE '7%' THEN (CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) ELSE 0 END), 0) AS custos, COUNT(*) AS linhas")->first();

        return ['proveitos' => self::dinheiro($r->proveitos), 'custos' => self::dinheiro($r->custos), 'linhas' => (int) $r->linhas];
    }

    // ───────────── Fluxo e rentabilidade ─────────────

    /** Fluxo de processos do projecto (fluxo_projectos.js:84-231): as 7 etapas por projecto, só consulta. */
    public function fluxo(): array
    {
        $projetos = Projeto::query()->orderBy('codigo')->get();
        $movs = collect($this->movimentos())->groupBy('projeto_id');
        $tarefas = TarefaProjeto::query()->get()->groupBy('projeto_id');
        $marcos = MarcoProjeto::query()->get()->groupBy('projeto_id');
        $orc = LinhaOrcamentoProjeto::query()->get()->groupBy('projeto_id');
        $equipas = EquipaProjeto::query()->get()->groupBy('projeto_id');
        $membros = MembroEquipaProjeto::query()->get()->groupBy('equipa_projeto_id');
        $nos = NoOrganigramaProjeto::query()->get()->groupBy('projeto_id');
        $reqs = RequisicaoMaterialProjeto::query()->where('estado', 'PENDENTE')->get()->groupBy('projeto_id');
        $adit = AditamentoAlteracaoProjeto::query()->where('estado', 'APROVADO')->get()->groupBy('projeto_id');
        $horas = FolhaHorasProjeto::query()->groupBy('projeto_id')->selectRaw('projeto_id, SUM(horas) AS h')->pluck('h', 'projeto_id');
        $hoje = now()->toDateString();
        $saida = [];
        foreach ($projetos as $p) {
            $ts = $tarefas[$p->id] ?? collect();
            $pct = ServicoPlaneamentoProjetos::progresso($ts)['global'];
            $mv = ($movs[$p->id] ?? collect())->all();
            $tot = self::totais($mv);
            $orcamento = self::dinheiro(($orc[$p->id] ?? collect())->sum(fn ($l) => (float) $l->montante));
            $eq = ($equipas[$p->id] ?? collect())->flatMap(fn ($e) => $membros[$e->id] ?? collect());
            $enc = $p->externo() && $p->encomenda_venda_id ? Venda::query()->find($p->encomenda_venda_id) : null;
            $venda = bcadd($enc && $enc->estado !== 'ANULADO' ? self::semIva($enc) : '0.00', self::dinheiro(($adit[$p->id] ?? collect())->sum(fn ($a) => (float) $a->montante)), 2);
            $fts = collect($mv)->where('fonte', 'VENDA')->where('valor', '>', 0);
            $porReceber = self::dinheiro($fts->where('tipo_documento', 'FT')->sum(fn ($v) => (float) $v['valor_pendente']));
            $cancelado = $p->estado === 'CANCELADO';
            $encerrado = $p->estado === 'ENCERRADO';
            $atrasadas = $ts->filter(fn ($t) => $t->data_fim && $t->data_fim->toDateString() < $hoje && (float) $t->percentagem_execucao < 100
                && $t->estadoEfetivo() !== 'CONCLUIDA' && ! $ts->contains('tarefa_pai_id', $t->id))->count();
            $e = [];
            $e['abertura'] = $p->externo() && (! $p->encomenda_venda_id || ! $enc || $enc->estado === 'ANULADO') ? 'BLOQUEADA' : 'CONCLUIDA';
            $e['planeamento'] = $ts->isEmpty() ? ($cancelado ? 'CONCLUIDA' : 'EM_CURSO') : 'CONCLUIDA';
            $e['equipa_orcamento'] = (float) $orcamento == 0 && $eq->isEmpty() ? ($cancelado ? 'CONCLUIDA' : ($ts->isEmpty() ? 'POR_FAZER' : 'EM_CURSO'))
                : ((float) $orcamento == 0 || $eq->isEmpty() ? 'EM_CURSO' : 'CONCLUIDA');
            $e['execucao'] = $ts->isEmpty() ? 'POR_FAZER' : ($pct >= 100 || $cancelado ? 'CONCLUIDA' : 'EM_CURSO');
            if (! $p->externo()) {
                $e['faturacao'] = 'NAO_APLICAVEL';
                $e['recebimento'] = 'NAO_APLICAVEL';
            } else {
                $devido = (float) $venda * $pct / 100;
                $e['faturacao'] = ! $enc ? 'BLOQUEADA' : ((float) $venda > 0 && (float) $tot['proveitos'] >= (float) $venda - 0.01 ? 'CONCLUIDA'
                    : ($devido - (float) $tot['proveitos'] > 0.01 ? 'EM_CURSO' : ($fts->isEmpty() ? 'POR_FAZER' : 'EM_CURSO')));
                $e['recebimento'] = $fts->isEmpty() ? 'POR_FAZER' : ((float) $porReceber > 0.01 ? 'EM_CURSO' : 'CONCLUIDA');
            }
            $pronto = $pct >= 100 && (! $p->externo() || ($e['faturacao'] === 'CONCLUIDA' && $e['recebimento'] === 'CONCLUIDA'));
            $e['encerramento'] = $encerrado || $cancelado ? 'CONCLUIDA' : ($pronto ? 'EM_CURSO' : 'POR_FAZER');
            $saida[] = ['id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'tipo' => $p->tipo, 'estado' => $p->estado, 'etapas' => $e,
                'etapa_atual' => collect($e)->search(fn ($x) => ! in_array($x, ['CONCLUIDA', 'NAO_APLICAVEL'], true)) ?: null,
                'execucao' => $pct, 'venda' => $venda, 'custo' => $tot['custos'], 'orcamento' => $orcamento, 'faturado' => $tot['proveitos'], 'por_receber' => $porReceber,
                'horas' => (float) ($horas[$p->id] ?? 0), 'tarefas_atrasadas' => $atrasadas, 'requisicoes_pendentes' => ($reqs[$p->id] ?? collect())->count(),
                'marcos' => ($marcos[$p->id] ?? collect())->count(), 'membros' => $eq->count(), 'posicoes' => ($nos[$p->id] ?? collect())->count(),
                'acima_orcamento' => (float) $orcamento > 0 && (float) $tot['custos'] > (float) $orcamento, 'ativo' => ! $encerrado && ! $cancelado];
        }

        return $saida;
    }

    /** Rentabilidade por projecto num período (relatorios_gestao.js:469-534, secção «Projectos»). */
    public function rentabilidade(string $inicio, string $fim): array
    {
        $projetos = Projeto::query()->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'CANCELADO'))->orderBy('codigo')->get();
        $todos = collect($this->movimentos($projetos->pluck('id')->all()))->groupBy('projeto_id');
        $tarefas = TarefaProjeto::query()->whereIn('projeto_id', $projetos->pluck('id'))->get()->groupBy('projeto_id');
        $orc = LinhaOrcamentoProjeto::query()->whereIn('projeto_id', $projetos->pluck('id'))->get()->groupBy('projeto_id');
        $horas = FolhaHorasProjeto::query()->whereBetween('data', [$inicio, $fim])->groupBy('projeto_id')->selectRaw('projeto_id, SUM(horas) AS h')->pluck('h', 'projeto_id');
        $linhas = [];
        foreach ($projetos as $p) {
            $mv = ($todos[$p->id] ?? collect());
            $periodo = self::totais($mv->filter(fn ($m) => $m['data'] >= $inicio && $m['data'] <= $fim)->all());
            $custoAcum = self::totais($mv->filter(fn ($m) => $m['data'] && $m['data'] <= $fim)->all())['custos'];
            $orcamento = self::dinheiro(($orc[$p->id] ?? collect())->sum(fn ($l) => (float) $l->montante));
            $ts = $tarefas[$p->id] ?? collect();
            $linhas[] = ['projeto_id' => $p->id, 'nome' => trim("{$p->codigo} {$p->nome}"), 'estado' => $p->estado, 'proveitos' => $periodo['proveitos'], 'custos' => $periodo['custos'],
                'margem' => bcsub($periodo['proveitos'], $periodo['custos'], 2), 'orcamento' => $orcamento, 'custo_acumulado' => $custoAcum,
                'desvio_pct' => (float) $orcamento > 0 ? round(((float) $custoAcum / (float) $orcamento - 1) * 100, 2) : null,
                'execucao' => $ts->isEmpty() ? null : ServicoPlaneamentoProjetos::progresso($ts)['global'], 'horas' => (float) ($horas[$p->id] ?? 0)];
        }
        $soma = fn ($k) => self::dinheiro(collect($linhas)->sum(fn ($l) => (float) $l[$k]));
        $prov = $soma('proveitos');
        $cust = $soma('custos');
        $orcT = $soma('orcamento');
        $acum = $soma('custo_acumulado');
        $hoje = min($fim, now()->toDateString());
        usort($linhas, fn ($a, $b) => (float) $b['proveitos'] <=> (float) $a['proveitos']);

        return ['kpis' => ['projetos_ativos' => $projetos->filter->ativo()->count(), 'proveitos' => $prov, 'custos' => $cust, 'margem' => bcsub($prov, $cust, 2),
            'margem_pct' => (float) $prov > 0 ? round(((float) $prov - (float) $cust) / (float) $prov * 100, 2) : null, 'horas' => round((float) $horas->sum(), 3),
            'orcamento' => $orcT, 'desvio_pct' => (float) $orcT > 0 ? round(((float) $acum / (float) $orcT - 1) * 100, 2) : null,
            'aditamentos_aprovados' => self::dinheiro(AditamentoAlteracaoProjeto::query()->where('estado', 'APROVADO')->whereIn('projeto_id', $projetos->pluck('id'))->sum('montante')),
            'tarefas_atrasadas' => $tarefas->flatten(1)->filter(fn ($t) => $t->data_fim && $t->data_fim->toDateString() < $hoje && $t->estadoEfetivo() !== 'CONCLUIDA')->count()],
            'linhas' => $linhas];
    }

    // ───────────── Auxiliares ─────────────

    /** Natureza do movimento do razão: CUSTO, PROVEITO, COMPROMISSO ou OUTRO (loadProjectExtract, ui_projects.js:149-151). */
    public static function natureza(?string $codigo, ?string $original): string
    {
        if (in_array($codigo, ['CUSTO', 'CUSTO_REAL'], true)) {
            return 'CUSTO';
        }
        if ($codigo === 'PROVEITO') {
            return 'PROVEITO';
        }

        return match (strtolower(trim((string) $original))) {
            'c', 'custo', 'custo_real' => 'CUSTO', 'p', 'proveito' => 'PROVEITO', 'compromisso' => 'COMPROMISSO', default => 'OUTRO'
        };
    }

    private static function categoriaRazao(?string $rubrica): string
    {
        return match ($rubrica) {
            'MAO_DE_OBRA' => 'MAO_DE_OBRA', 'CUSTOS_EQUIPAMENTO', 'EQUIPAMENTOS' => 'EQUIPAMENTOS', 'MATERIAIS', 'COMPRAS_MATERIAIS' => 'MATERIAIS',
            'SUBCONTRATOS', 'SUBEMPREITADA', 'SUBEMPREITADAS' => 'SUBCONTRATOS', default => 'DIVERSOS'
        };
    }

    private static function origemRazao(RazaoAnaliticoProjeto $r): string
    {
        if ($r->rubrica === 'CUSTOS_EQUIPAMENTO') {
            return 'EQUIPAMENTOS';
        }
        if ($r->rubrica === 'MAO_DE_OBRA') {
            return ($r->tipo_documento_origem ?? $r->tipo_documento_origem_original) === 'AUTO_INTERNO' ? 'AUTOS_MAO_OBRA' : 'MAO_OBRA_ANALITICA';
        }

        return 'OUTROS';
    }

    /** Valor monetário com 2 casas (ADR-022), arredondado a meio para longe de zero, em bcmath. */
    public static function dinheiro(mixed $v): string
    {
        $s = is_float($v) ? sprintf('%.6F', $v) : trim((string) ($v ?? '0'));
        if ($s === '' || ! is_numeric($s)) {
            $s = '0';
        }
        $r = bcadd($s, str_starts_with($s, '-') ? '-0.005' : '0.005', 2);

        return $r === '-0.00' ? '0.00' : $r;
    }

    public static function ultimoDia(int $ano, int $mes): string
    {
        return date('Y-m-t', mktime(0, 0, 0, max(1, min(12, $mes)), 1, $ano));
    }
}
