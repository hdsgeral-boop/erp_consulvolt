<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Services\Orcamento\ServicoControloOrcamental;
use App\Services\Orcamento\ServicoOrcamentos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo do orçamento (avaliarOrcamentos, js/fluxo_gestao.js:33-140). Um processo é um orçamento-raiz (sem pai) de uma chave
 * ano/tipo/UN/CC/projecto (ServicoOrcamentos::chave, ADR-044), na última versão não substituída. Etapas: preparação →
 * contributos (bottom-up) → submissão → aprovação → execução e controlo → excessos e desvios. A execução usa o monitor do
 * módulo (ServicoControloOrcamental::monitor: realizado + compromissos face ao orçado até ao mês, por rubrica de custo ou
 * pagamento) no mês actual, em Dezembro para anos fechados e sem execução para anos futuros.
 */
final class FluxoOrcamento extends AvaliadorFluxo
{
    private const METODOS = ['HISTORICO' => 'Histórico', 'BASE_ZERO' => 'Base zero'];

    private const TIPOS = ['EXPLORACAO' => 'Exploração', 'TESOURARIA' => 'Tesouraria'];

    private const ESTADOS = ['RASCUNHO' => 'Rascunho', 'SUBMETIDO' => 'Submetido', 'APROVADO' => 'Aprovado', 'SUBSTITUIDO' => 'Substituído'];

    public function __construct(ContextoEmpresa $contexto, private readonly ServicoControloOrcamental $controlo)
    {
        parent::__construct($contexto);
    }

    public function temActividade(): bool
    {
        return DB::table('orcamentos_anuais')->where('empresa_id', $this->empresa())->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $todos = DB::table('orcamentos_anuais')->where('empresa_id', $e)->get();
        $totais = DB::table('linhas_orcamento')->where('empresa_id', $e)->groupBy('orcamento_anual_id')
            ->selectRaw("orcamento_anual_id, COUNT(*) AS n, COALESCE(SUM((SELECT COALESCE(SUM(NULLIF(x, '')::numeric), 0) FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(valores) = 'array' THEN valores ELSE '[]'::jsonb END) x)), 0) AS total")
            ->get()->keyBy('orcamento_anual_id');
        $pedidos = DB::table('pedidos_extrapolacao_orcamento')->where('empresa_id', $e)->get()->groupBy('orcamento_anual_id');
        $mesAct = (int) now()->format('n');
        $anoAct = (int) now()->format('Y');
        $monitores = [];
        $processos = [];
        foreach ($todos->whereNull('orcamento_pai_id')->groupBy(fn ($o) => ServicoOrcamentos::chave((array) $o)) as $chave => $versoes) {
            $vivos = $versoes->where('estado', '<>', 'SUBSTITUIDO');
            $o = ($vivos->isNotEmpty() ? $vivos : $versoes)->sortByDesc('versao')->first();
            $idsVersoes = $versoes->pluck('id')->flip();
            $filhos = $todos->filter(fn ($f) => $f->orcamento_pai_id && isset($idsVersoes[$f->orcamento_pai_id]))
                ->groupBy(fn ($f) => ServicoOrcamentos::chave((array) $f))->map(fn ($g) => $g->sortByDesc('versao')->first())->where('estado', '<>', 'SUBSTITUIDO')->values();
            $nLinhas = (int) ($totais[$o->id]->n ?? 0);
            $total = self::dinheiro($totais[$o->id]->total ?? 0);
            $E = [];
            $E['prep'] = $nLinhas
                ? self::feito("{$nLinhas} rubrica(s)", [self::f('Versão', (int) $o->versao, 'num'), self::f('Método', self::METODOS[$o->metodo] ?? '—'), self::f('Rubricas preenchidas', $nLinhas, 'num')], [self::accao('Abrir orçamentos', 'orc_orcamentos')])
                : self::etapa($o->estado === 'RASCUNHO' ? 'curso' : 'concluida', 'Sem valores', [self::f('Versão', (int) $o->versao, 'num')],
                    [self::aviso('O orçamento ainda não tem valores. Preencha as rubricas ou pré-preencha com o histórico.')], [self::accao('Preencher', 'orc_orcamentos', true)]);
            if ($filhos->isEmpty()) {
                $E['contrib'] = self::feito($o->abordagem === 'BOTTOM_UP' ? 'Sem contributos pedidos' : 'Não aplicável (top-down ou directo)');
            } else {
                $prontos = $filhos->whereIn('estado', ['SUBMETIDO', 'APROVADO']);
                $consolidado = (bool) $o->consolidado_em;
                $hoje = self::hoje();
                $prob = $filhos->where('estado', 'RASCUNHO')->map(fn ($f) => ['nivel' => $f->prazo_contributo && $f->prazo_contributo < $hoje ? 'erro' : 'aviso',
                    'texto' => "{$f->nome}: por submeter".($f->responsavel ? " ({$f->responsavel})" : '').($f->prazo_contributo ? ' · prazo '.self::data($f->prazo_contributo) : '').'.'])->values()->all();
                if ($prontos->count() === $filhos->count() && ! $consolidado && $o->estado === 'RASCUNHO') {
                    $prob[] = self::aviso('Todos os contributos foram submetidos: consolide-os no orçamento-pai.');
                }
                $E['contrib'] = self::etapa($prontos->count() === $filhos->count() && ($consolidado || $o->estado !== 'RASCUNHO') ? 'concluida' : 'curso', $prontos->count().' de '.$filhos->count().' submetidos',
                    [self::f('Contributos', $filhos->count(), 'num'), self::f('Submetidos/aprovados', $prontos->count(), 'num'), self::f('Consolidado', $consolidado ? self::data($o->consolidado_em) : 'Não')], $prob,
                    [self::accao('Abrir orçamentos', 'orc_orcamentos', true)]);
            }
            $rej = json_decode((string) $o->rejeicoes, true) ?: [];
            $E['subm'] = $o->estado === 'RASCUNHO'
                ? self::etapa($nLinhas ? 'curso' : 'fazer', $rej ? 'Devolvido para revisão' : 'Em preparação', [],
                    array_map(fn ($r) => self::aviso('Devolvido por '.($r['por'] ?? '—').' em '.self::data($r['em'] ?? null).': '.($r['motivo'] ?? '')), array_slice($rej, -1)), $nLinhas ? [self::accao('Submeter', 'orc_orcamentos', true)] : [])
                : self::feito('Submetido'.($o->submetido_por ? " por {$o->submetido_por}" : ''), [self::f('Submetido em', $o->submetido_em, 'data')]);
            $E['aprov'] = match ($o->estado) {
                'APROVADO' => self::feito('Aprovado'.($o->aprovado_por ? " por {$o->aprovado_por}" : ''), [self::f('Aprovado em', $o->aprovado_em, 'data'), self::f('Total orçado', $total, 'kz')]),
                'SUBMETIDO' => self::etapa('curso', 'Aguarda aprovação', [self::f('Total orçado', $total, 'kz')], [], [self::accao('Aprovar ou devolver', 'orc_orcamentos', true)]),
                default => self::porFazer('Depois de submeter'),
            };
            $doOrc = $pedidos[$o->id] ?? collect();
            if ($o->estado !== 'APROVADO') {
                $E['exec'] = self::porFazer('Depois de aprovar');
                $E['exc'] = self::porFazer('Depois de aprovar');
            } else {
                $ano = (int) $o->ano;
                $mesRef = $ano < $anoAct ? 12 : ($ano > $anoAct ? 0 : $mesAct);
                $mon = [];
                if ($mesRef > 0) {
                    $monitores["{$ano}|{$mesRef}"] ??= $this->controlo->monitor($ano, $mesRef);
                    $mon = array_values(array_filter($monitores["{$ano}|{$mesRef}"], fn ($l) => (int) $l['orcamento_anual_id'] === (int) $o->id));
                }
                $exced = array_filter($mon, fn ($l) => $l['estado'] === 'EXCEDIDO');
                $aviso = array_filter($mon, fn ($l) => $l['estado'] === 'AVISO');
                $orcado = array_sum(array_column($mon, 'orcado'));
                $consumido = array_sum(array_column($mon, 'consumido'));
                $fechado = $ano < $anoAct;
                $E['exec'] = $mesRef === 0 ? self::porFazer("Começa em {$ano}")
                    : self::etapa($fechado ? 'concluida' : 'curso', $orcado ? round($consumido / $orcado * 100).'% executado' : 'Em execução',
                        [self::f('Orçado até ao mês', self::dinheiro($orcado), 'kz'), self::f('Consumido (real + compromissos)', self::dinheiro($consumido), 'kz'),
                            self::f('Rubricas em aviso', count($aviso), 'num'), self::f('Rubricas excedidas', count($exced), 'num')],
                        array_map(fn ($l) => ['nivel' => $l['estado'] === 'EXCEDIDO' ? 'erro' : 'aviso', 'texto' => "{$l['rubrica']}: ".($l['percentagem'] !== null ? number_format((float) $l['percentagem'], 1, ',', '').' %' : 'sem orçado')
                            .' ('.self::kz($l['consumido']).' de '.self::kz($l['orcado']).').'], array_slice(array_merge(array_values($exced), array_values($aviso)), 0, 8)),
                        [self::accao('Controlo orçamental', 'orc_controlo', ! $fechado)]);
                $pend = $doOrc->where('estado', 'PENDENTE');
                $E['exc'] = $pend->isNotEmpty()
                    ? self::etapa('curso', $pend->count().' por decidir', [self::f('Pedidos de excesso', $doOrc->count(), 'num'), self::f('Aprovados', $doOrc->where('estado', 'APROVADO')->count(), 'num'),
                        self::f('Recusados', $doOrc->where('estado', 'REJEITADO')->count(), 'num')], $pend->take(6)->map(fn ($p) => self::aviso(($p->documento ?: $p->origem).': excesso de '.self::kz($p->valor_excesso)
                        .' pedido por '.$p->pedido_por.($p->motivo ? " — {$p->motivo}" : '').'.'))->values()->all(), [self::accao('Decidir excessos', 'orc_alertas', true)])
                    : ($fechado || ! $exced ? self::feito($doOrc->isNotEmpty() ? $doOrc->count().' pedido(s) decidido(s)' : 'Sem excessos pendentes', [self::f('Pedidos de excesso', $doOrc->count(), 'num')], [self::accao('Alertas e aprovações', 'orc_alertas')])
                        : self::etapa('curso', 'Desvios por analisar', [], [self::aviso(count($exced).' rubrica(s) acima do orçado: analise as causas (temporal, pontual ou estrutural) no controlo orçamental.')],
                            [self::accao('Analisar desvios', 'orc_controlo', true)]));
            }
            $processos[] = ['chave' => 'orcamento-'.$o->id, 'titulo' => (string) $o->nome, 'subtitulo' => "{$o->ano} · ".(self::TIPOS[$o->tipo] ?? $o->tipo)." · v{$o->versao}", 'data' => (string) $o->criado_em,
                'valor' => $total, 'valor_pendente' => null, 'etapas' => $E, 'ano' => (int) $o->ano, 'estado' => $o->estado, 'estado_nome' => self::ESTADOS[$o->estado] ?? $o->estado,
                'documentos' => [['tipo' => 'orcamento', 'id' => $o->id, 'numero' => "v{$o->versao}"]]] + self::contagem($E);
        }
        usort($processos, fn ($a, $b) => $b['ano'] <=> $a['ano'] ?: strcmp($b['data'], $a['data']));

        return ['processos' => $processos, 'kpis' => [
            self::kpi('em_preparacao', 'Em preparação', count(array_filter($processos, fn ($p) => $p['estado'] === 'RASCUNHO'))),
            self::kpi('por_aprovar', 'Por aprovar', $n = count(array_filter($processos, fn ($p) => $p['estado'] === 'SUBMETIDO')), 'num', $n > 0),
            self::kpi('aprovados', 'Aprovados', count(array_filter($processos, fn ($p) => $p['estado'] === 'APROVADO'))),
            self::kpi('com_desvios', 'Com desvios ou excessos', $n = count(array_filter($processos, fn ($p) => collect($p['etapas']['exec']['problemas'])->contains('nivel', 'erro') || $p['etapas']['exc']['estado'] === 'curso')),
                'num', $n > 0),
        ]];
    }
}
