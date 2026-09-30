<?php

namespace App\Services\CRM;

use App\Exceptions\ErroNegocio;
use App\Models\AtividadeComercialCRM;
use App\Models\ContaCRM;
use App\Models\ContactoCRM;
use App\Models\FunilVendasCRM;
use App\Models\OportunidadeVendaCRM;
use App\Models\SequenciaCampanhaCRM;
use App\Models\Venda;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Oportunidades, quadro Kanban, saúde do negócio e ligação a Vendas (crm_dados.js:175-273 e 311-327). Paridade:
 *   - oportunidade: título, conta e funil obrigatórios; com linhas de produtos o valor é Σ quantidade × preço; valor ≥ 0;
 *     probabilidade vazia = a da etapa; fecho previsto por omissão hoje + 30 dias; nasce na etapa indicada ou na
 *     primeira ABERTA, com histórico e as tarefas automáticas da etapa (e das sequências activas dessa etapa);
 *   - mudar de etapa: histórico, estado (ABERTA/GANHA/PERDIDA), motivo obrigatório na perda (motivo, concorrente, notas);
 *     ao fechar, as actividades automáticas pendentes são concluídas como «Cancelada: oportunidade ganha/perdida»;
 *     reabrir limpa o fecho;
 *   - saúde: RISCO (sem actividade agendada ou estagnada além dos dias da etapa), ATENCAO (dias sem actividade,
 *     actividades em atraso ou fecho previsto ultrapassado);
 *   - quadro: oportunidades abertas e as fechadas nos últimos 30 dias; totais bruto e ponderado; em risco;
 *   - documento de venda emitido a partir da oportunidade: fica ligado (lista `vendas` e `vendas.oportunidade_crm_id`),
 *     regista uma NOTA e, se for factura ou encomenda de uma oportunidade aberta, marca-a como ganha;
 *   - não se elimina uma oportunidade que já gerou documentos.
 * Correcções: o contacto tem de pertencer à conta; o documento a ligar tem de ser do cliente da conta, não anulado e
 * não ligado a outra oportunidade; a conversão devolve os dados para a API de Vendas (o legado preenchia o
 * formulário no browser e dependia de uma variável global).
 */
final class ServicoOportunidadesCRM
{
    public function __construct(
        private readonly ServicoConfiguracaoCRM $config,
        private readonly ServicoContasCRM $contas,
    ) {}

    public function guardar(array $d, ?OportunidadeVendaCRM $o = null): OportunidadeVendaCRM
    {
        $titulo = trim((string) ($d['titulo'] ?? ''));
        if ($titulo === '') {
            throw new ErroNegocio('Dê um título à oportunidade.', 'TITULO_OBRIGATORIO', 422);
        }
        $conta = empty($d['conta_crm_id']) ? null : ContaCRM::query()->find($d['conta_crm_id']);
        if (! $conta) {
            throw new ErroNegocio('Escolha a conta (prospect ou cliente).', 'CONTA_OBRIGATORIA', 422);
        }
        $this->config->funis();
        $funil = empty($d['funil_vendas_crm_id']) ? null : FunilVendasCRM::query()->find($d['funil_vendas_crm_id']);
        if (! $funil) {
            throw new ErroNegocio('Escolha o funil.', 'FUNIL_OBRIGATORIO', 422);
        }
        if ($o && $funil->id !== $o->funil_vendas_crm_id) {
            throw new ErroNegocio('Uma oportunidade não muda de funil.', 'FUNIL_FIXO', 422);
        }
        if (! empty($d['contacto_crm_id']) && ! ContactoCRM::query()->whereKey($d['contacto_crm_id'])->where('conta_crm_id', $conta->id)->exists()) {
            throw new ErroNegocio('O contacto não pertence à conta.', 'CONTACTO_INVALIDO', 422);
        }
        $itens = array_values(array_map(fn ($i) => ['produto_id' => ! empty($i['produto_id']) ? (int) $i['produto_id'] : null, 'descricao' => trim((string) ($i['descricao'] ?? '')),
            'quantidade' => (float) ($i['quantidade'] ?? 0) ?: 1.0, 'preco' => (float) ($i['preco'] ?? 0), 'taxa' => (float) ($i['taxa'] ?? 0)],
            array_filter($d['itens'] ?? [], fn ($i) => ! empty($i['produto_id']) || trim((string) ($i['descricao'] ?? '')) !== '')));
        $valor = $itens ? array_reduce($itens, fn ($s, $i) => bcadd($s, bcmul(number_format($i['quantidade'], 3, '.', ''), number_format($i['preco'], 2, '.', ''), 4), 4), '0')
            : (string) ($d['valor'] ?? 0);
        $valor = bcadd($valor, bccomp($valor, '0', 4) < 0 ? '-0.005' : '0.005', 2);
        if (bccomp($valor, '0', 2) < 0) {
            throw new ErroNegocio('O valor não pode ser negativo.', 'VALOR_NEGATIVO', 422);
        }
        $prob = ($d['probabilidade'] ?? '') === '' || ! isset($d['probabilidade']) ? null : max(0, min(100, (float) $d['probabilidade']));
        $reg = ['conta_crm_id' => $conta->id, 'contacto_crm_id' => $d['contacto_crm_id'] ?? null, 'titulo' => $titulo, 'valor' => $valor, 'probabilidade' => $prob,
            'data_fecho_prevista' => ($d['data_fecho_prevista'] ?? null) ?: RegrasCRM::somarDias(RegrasCRM::hoje(), 30),
            'responsavel' => ($d['responsavel'] ?? null) ?: ($o?->responsavel ?? Auth::user()?->nome_utilizador), 'notas' => trim((string) ($d['notas'] ?? '')) ?: null,
            'itens' => $itens] + RegrasCRM::origem($d['origem'] ?? null);

        return DB::transaction(function () use ($o, $reg, $funil, $d) {
            if ($o) {
                $o->update($reg);

                return $o;
            }
            // nasce sempre numa etapa em aberto (a indicada, se for ABERTA, ou a primeira)
            $etapa = RegrasCRM::etapa($funil->etapas, $d['etapa_codigo'] ?? null);
            if (! $etapa || $etapa['tipo'] !== 'ABERTA') {
                $etapa = collect($funil->etapas)->firstWhere('tipo', 'ABERTA');
            }
            $agora = now();
            $o = OportunidadeVendaCRM::create($reg + ['funil_vendas_crm_id' => $funil->id, 'etapa_codigo' => $etapa['id'], 'estado' => 'ABERTA',
                'historico' => [['etapa_codigo' => $etapa['id'], 'entrou_em' => $agora->toIso8601String(), 'por' => Auth::user()?->nome_utilizador]],
                'etapa_desde' => $agora, 'vendas' => [], 'criado_por' => Auth::user()?->nome_utilizador]);
            $this->criarTarefasEtapa($o, $etapa, $funil);

            return $o->refresh();
        });
    }

    /** @return array{oportunidade: OportunidadeVendaCRM, tarefas: int} */
    public function moverEtapa(OportunidadeVendaCRM $o, string $etapaCodigo, array $extra = []): array
    {
        return DB::transaction(function () use ($o, $etapaCodigo, $extra) {
            $o = OportunidadeVendaCRM::query()->lockForUpdate()->findOrFail($o->id);
            $funil = FunilVendasCRM::query()->findOrFail($o->funil_vendas_crm_id);
            $et = RegrasCRM::etapa($funil->etapas, $etapaCodigo) ?? throw new ErroNegocio('Etapa inválida.', 'ETAPA_INVALIDA', 422);
            if ($o->etapa_codigo === $et['id']) {
                return ['oportunidade' => $o, 'tarefas' => 0];
            }
            if ($et['tipo'] === 'PERDIDA' && trim((string) ($extra['motivo_perda'] ?? '')) === '') {
                throw new ErroNegocio('Indique o motivo da perda.', 'MOTIVO_OBRIGATORIO', 422);
            }
            $agora = now();
            $upd = ['etapa_codigo' => $et['id'], 'etapa_desde' => $agora, 'estado' => $et['tipo'],
                'historico' => array_merge($o->historico ?? [], [['etapa_codigo' => $et['id'], 'entrou_em' => $agora->toIso8601String(), 'por' => Auth::user()?->nome_utilizador]])];
            $upd += $et['tipo'] === 'ABERTA' ? ['fechado_em' => null, 'motivo_perda' => null, 'concorrente' => null, 'notas_perda' => null]
                : ['fechado_em' => $agora] + ($et['tipo'] === 'PERDIDA' ? ['motivo_perda' => trim((string) $extra['motivo_perda']),
                    'concorrente' => trim((string) ($extra['concorrente'] ?? '')) ?: null, 'notas_perda' => trim((string) ($extra['notas_perda'] ?? '')) ?: null] : ['motivo_perda' => null]);
            $o->update($upd);
            if ($et['tipo'] !== 'ABERTA') {   // ao fechar, as actividades automáticas pendentes deixam de fazer sentido
                AtividadeComercialCRM::query()->where('oportunidade_crm_id', $o->id)->where('automatica', true)->where(fn ($q) => $q->whereNull('concluida')->orWhere('concluida', false))
                    ->update(['concluida' => true, 'concluida_em' => $agora, 'resultado' => $et['tipo'] === 'GANHA' ? 'Cancelada: oportunidade ganha' : 'Cancelada: oportunidade perdida']);
            }

            return ['oportunidade' => $o->refresh(), 'tarefas' => $this->criarTarefasEtapa($o, $et, $funil)];
        });
    }

    /** Tarefas automáticas da etapa e passos das sequências activas que começam nela. */
    private function criarTarefasEtapa(OportunidadeVendaCRM $o, array $et, FunilVendasCRM $funil): int
    {
        $hoje = RegrasCRM::hoje();
        $base = ['oportunidade_crm_id' => $o->id, 'conta_crm_id' => $o->conta_crm_id, 'contacto_crm_id' => $o->contacto_crm_id, 'concluida' => false,
            'responsavel' => $o->responsavel, 'automatica' => true, 'criado_por' => 'automático'];
        $n = 0;
        foreach ($et['tarefas'] ?? [] as $t) {
            AtividadeComercialCRM::create($base + ['tipo' => $t['tipo'], 'titulo' => $t['titulo'], 'descricao' => "Criada automaticamente ao entrar na etapa «{$et['nome']}»",
                'data_prevista' => RegrasCRM::somarDias($hoje, (int) ($t['dias'] ?? 0)), 'modelo_email_crm_id' => $t['modelo_email_crm_id'] ?? null]);
            $n++;
        }
        foreach (SequenciaCampanhaCRM::query()->where('funil_vendas_crm_id', $funil->id)->where('etapa_codigo', $et['id'])->where('ativo', true)->orderBy('id')->get() as $s) {
            foreach ($s->passos ?? [] as $i => $p) {
                AtividadeComercialCRM::create($base + ['tipo' => $p['tipo'] ?? 'EMAIL', 'titulo' => mb_substr("{$s->nome} — passo ".($i + 1), 0, 255), 'descricao' => 'Sequência automática',
                    'data_prevista' => RegrasCRM::somarDias($hoje, (int) ($p['dias'] ?? 0)), 'modelo_email_crm_id' => $p['modelo_email_crm_id'] ?? null, 'sequencia_campanha_id' => $s->id]);
                $n++;
            }
        }

        return $n;
    }

    public function eliminar(OportunidadeVendaCRM $o): void
    {
        DB::transaction(function () use ($o) {
            $o = OportunidadeVendaCRM::query()->lockForUpdate()->findOrFail($o->id);
            if (($o->vendas ?? []) !== [] || Venda::query()->where('oportunidade_crm_id', $o->id)->exists()) {
                throw new ErroNegocio('A oportunidade já gerou documentos comerciais e não pode ser eliminada.', 'OPORTUNIDADE_COM_DOCUMENTOS', 422);
            }
            AtividadeComercialCRM::query()->where('oportunidade_crm_id', $o->id)->delete();
            $o->delete();
        });
    }

    /**
     * Saúde do negócio.
     *
     * @param  Collection<int, AtividadeComercialCRM>  $pendentes  actividades por concluir da oportunidade
     */
    public function saude(OportunidadeVendaCRM $o, ?array $etapa, Collection $pendentes, array $config): array
    {
        if ($o->estado !== 'ABERTA') {
            return ['nivel' => $o->estado === 'GANHA' ? 'GANHA' : 'PERDIDA', 'motivos' => [], 'proxima' => null, 'dias_etapa' => null];
        }
        $hoje = RegrasCRM::hoje();
        $diasEtapa = RegrasCRM::diasEntre(($o->etapa_desde ?? $o->criado_em)?->toDateString(), $hoje);
        $diasSemAct = RegrasCRM::diasEntre(($o->ultima_atividade_em ?? $o->etapa_desde ?? $o->criado_em)?->toDateString(), $hoje);
        $nivel = 'OK';
        $motivos = [];
        $atencao = function (string $m) use (&$nivel, &$motivos) {
            $nivel = $nivel === 'OK' ? 'ATENCAO' : $nivel;
            $motivos[] = $m;
        };
        if ($pendentes->isEmpty()) {
            $nivel = 'RISCO';
            $motivos[] = 'Sem actividade agendada';
        }
        if (! empty($etapa['dias_estagnacao']) && $diasEtapa > $etapa['dias_estagnacao']) {
            $nivel = 'RISCO';
            $motivos[] = "Estagnada há {$diasEtapa} dias em «{$etapa['nome']}» (limite {$etapa['dias_estagnacao']})";
        }
        if ($diasSemAct > $config['dias_sem_atividade'] && $pendentes->every(fn ($a) => $a->data_prevista?->toDateString() > $hoje)) {
            $atencao("{$diasSemAct} dias sem actividade registada");
        }
        $atrasadas = $pendentes->filter(fn ($a) => $a->data_prevista && $a->data_prevista->toDateString() < $hoje)->count();
        if ($atrasadas) {
            $atencao("{$atrasadas} actividade(s) em atraso");
        }
        if ($o->data_fecho_prevista && $o->data_fecho_prevista->toDateString() < $hoje) {
            $atencao('Data de fecho prevista ultrapassada');
        }

        return ['nivel' => $nivel, 'motivos' => $motivos, 'proxima' => $pendentes->sortBy(fn ($a) => $a->data_prevista?->toDateString())->first(), 'dias_etapa' => $diasEtapa];
    }

    /**
     * Quadro Kanban de um funil: etapas com as oportunidades (abertas e fechadas nos últimos 30 dias), saúde, probabilidade
     * efectiva, valor ponderado e aviso de facturas em atraso do cliente.
     *
     * @param  array{responsavel?: ?string, texto?: ?string, so_risco?: bool}  $f
     */
    public function quadro(FunilVendasCRM $funil, array $f): array
    {
        $config = $this->config->obter();
        $limite = now()->subDays(30);
        $opps = OportunidadeVendaCRM::query()->where('funil_vendas_crm_id', $funil->id)
            ->where(fn ($q) => $q->where('estado', 'ABERTA')->orWhere('fechado_em', '>=', $limite->startOfDay()))
            ->when($f['responsavel'] ?? null, fn ($q, $r) => $q->where('responsavel', $r))
            ->with(['contaCrm:id,nome,tipo,terceiro_id', 'contactoCrm:id,nome'])->orderBy('id')->get();
        if ($t = mb_strtolower(trim((string) ($f['texto'] ?? '')))) {
            $opps = $opps->filter(fn ($o) => collect([$o->titulo, $o->contaCrm?->nome, $o->contactoCrm?->nome])->contains(fn ($x) => str_contains(mb_strtolower((string) $x), $t)))->values();
        }
        $pend = AtividadeComercialCRM::query()->whereIn('oportunidade_crm_id', $opps->pluck('id'))->where(fn ($q) => $q->whereNull('concluida')->orWhere('concluida', false))
            ->get()->groupBy('oportunidade_crm_id');
        $atraso = [];
        foreach ($opps->where('estado', 'ABERTA')->pluck('contaCrm.terceiro_id')->filter()->unique() as $tp) {
            $fin = $this->contas->financeiro((int) $tp);
            if (bccomp($fin['em_atraso'], '0', 2) > 0) {
                $atraso[$tp] = ['em_atraso' => $fin['em_atraso'], 'max_dias_atraso' => $fin['max_dias_atraso'], 'n_atrasadas' => $fin['n_atrasadas']];
            }
        }
        $cartoes = $opps->map(function ($o) use ($funil, $pend, $config, $atraso) {
            $et = RegrasCRM::etapa($funil->etapas, $o->etapa_codigo);
            $prob = RegrasCRM::probabilidade($o->probabilidade === null ? null : (string) $o->probabilidade, $et);

            return ['oportunidade' => $o, 'probabilidade_efectiva' => $prob, 'valor_ponderado' => RegrasCRM::ponderado((string) $o->valor, $prob),
                'saude' => $this->saude($o, $et, $pend[$o->id] ?? collect(), $config), 'atraso_financeiro' => $atraso[$o->contaCrm?->terceiro_id ?? 0] ?? null];
        });
        if (! empty($f['so_risco'])) {
            $visiveis = $cartoes->filter(fn ($c) => in_array($c['saude']['nivel'], ['RISCO', 'ATENCAO'], true));
        } else {
            $visiveis = $cartoes;
        }
        $abertas = $cartoes->filter(fn ($c) => $c['oportunidade']->estado === 'ABERTA');

        return [
            'funil' => $funil,
            'etapas' => collect($funil->etapas)->map(function ($e) use ($visiveis) {
                $c = $visiveis->filter(fn ($x) => $x['oportunidade']->etapa_codigo === $e['id'])->values();

                return ['etapa' => $e, 'n' => $c->count(), 'valor' => $c->reduce(fn ($s, $x) => bcadd($s, (string) $x['oportunidade']->valor, 2), '0.00'),
                    'ponderado' => $c->reduce(fn ($s, $x) => bcadd($s, $x['valor_ponderado'], 2), '0.00'), 'cartoes' => $c->all()];
            })->all(),
            'resumo' => ['abertas' => $abertas->count(), 'valor' => $abertas->reduce(fn ($s, $x) => bcadd($s, (string) $x['oportunidade']->valor, 2), '0.00'),
                'ponderado' => $abertas->reduce(fn ($s, $x) => bcadd($s, $x['valor_ponderado'], 2), '0.00'),
                'em_risco' => $abertas->filter(fn ($x) => $x['saude']['nivel'] === 'RISCO')->count(), 'atencao' => $abertas->filter(fn ($x) => $x['saude']['nivel'] === 'ATENCAO')->count()],
        ];
    }

    /**
     * Dados para emitir o documento de venda a partir da oportunidade (POST /api/vendas/documentos): cliente, linhas
     * com produto e a ligação `oportunidade_crm_id`. Um prospect tem de passar primeiro a cliente.
     */
    public function conversao(OportunidadeVendaCRM $o, string $tipo): array
    {
        if (! in_array($tipo, RegrasCRM::DOCUMENTOS_CONVERSAO, true)) {
            throw new ErroNegocio('Tipo de documento inválido para a conversão.', 'TIPO_DOCUMENTO_INVALIDO', 422);
        }
        $conta = ContaCRM::query()->findOrFail($o->conta_crm_id);
        if (! $conta->terceiro_id) {
            throw new ErroNegocio('Para emitir documentos, registe primeiro o prospect como cliente (escolha a conta contabilística).', 'PROSPECT_SEM_CLIENTE', 422,
                ['conta_crm_id' => $conta->id]);
        }
        $fin = $this->contas->financeiro($conta->terceiro_id);

        return ['tipo_documento' => $tipo, 'cliente_id' => $conta->terceiro_id, 'data_emissao' => RegrasCRM::hoje(), 'oportunidade_crm_id' => $o->id,
            'linhas' => array_values(array_map(fn ($i) => ['produto_id' => $i['produto_id'], 'quantidade' => $i['quantidade'], 'preco_unitario' => $i['preco'],
                'descricao' => $i['descricao'] ?: null], array_filter($o->itens ?? [], fn ($i) => ! empty($i['produto_id'])))),
            'aviso_atraso' => bccomp($fin['em_atraso'], '0', 2) > 0 ? ['em_atraso' => $fin['em_atraso'], 'n_atrasadas' => $fin['n_atrasadas'], 'max_dias_atraso' => $fin['max_dias_atraso']] : null];
    }

    /** Liga um documento de venda emitido à oportunidade (ligarVenda, crm_dados.js:313-327). */
    public function ligarVenda(OportunidadeVendaCRM $o, int $vendaId): OportunidadeVendaCRM
    {
        return DB::transaction(function () use ($o, $vendaId) {
            $o = OportunidadeVendaCRM::query()->lockForUpdate()->findOrFail($o->id);
            $v = Venda::query()->lockForUpdate()->findOrFail($vendaId);
            $conta = ContaCRM::query()->findOrFail($o->conta_crm_id);
            if (! $conta->terceiro_id || (int) $v->cliente_id !== (int) $conta->terceiro_id) {
                throw new ErroNegocio('O documento não é do cliente da oportunidade.', 'CLIENTE_DIFERENTE', 422);
            }
            if ($v->estado === 'ANULADO') {
                throw new ErroNegocio('O documento está anulado.', 'DOCUMENTO_ANULADO', 422);
            }
            if ($v->oportunidade_crm_id && (int) $v->oportunidade_crm_id !== $o->id) {
                throw new ErroNegocio('O documento já está ligado a outra oportunidade.', 'DOCUMENTO_LIGADO', 422);
            }
            if (collect($o->vendas ?? [])->contains(fn ($x) => (int) ($x['venda_id'] ?? 0) === $v->id)) {
                return $o;
            }
            $agora = now();
            DB::table('vendas')->where('id', $v->id)->where('empresa_id', $o->empresa_id)->update(['oportunidade_crm_id' => $o->id]);
            $o->update(['vendas' => array_merge($o->vendas ?? [], [['venda_id' => $v->id, 'tipo_documento' => $v->tipo_documento, 'numero_documento' => $v->numero_documento,
                'total' => (string) $v->total_bruto, 'em' => $agora->toIso8601String()]]), 'ultima_atividade_em' => $agora]);
            AtividadeComercialCRM::create(['oportunidade_crm_id' => $o->id, 'conta_crm_id' => $o->conta_crm_id, 'tipo' => 'NOTA',
                'titulo' => mb_substr("{$v->tipo_documento} {$v->numero_documento} emitido", 0, 255),
                'descricao' => 'Documento comercial criado a partir da oportunidade ('.number_format((float) $v->total_bruto, 2, '.', '').')',
                'data_prevista' => RegrasCRM::hoje(), 'concluida' => true, 'concluida_em' => $agora, 'automatica' => true, 'criado_por' => Auth::user()?->nome_utilizador]);
            if ($o->estado === 'ABERTA' && in_array($v->tipo_documento, RegrasCRM::DOCUMENTOS_GANHA, true)) {
                $ganha = collect(FunilVendasCRM::query()->findOrFail($o->funil_vendas_crm_id)->etapas)->firstWhere('tipo', 'GANHA');
                if ($ganha) {
                    $this->moverEtapa($o, $ganha['id']);
                }
            }

            return $o->refresh();
        });
    }
}
