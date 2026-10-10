<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Services\Projetos\ServicoAnaliticoProjetos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo de projectos e obras (js/fluxo_projectos.js): adaptador do fluxo já implementado no módulo de Projectos
 * (ServicoAnaliticoProjetos::fluxo, ADR-052 — sete etapas da abertura ao encerramento). Converte os estados do módulo
 * (CONCLUIDA, EM_CURSO, POR_FAZER, BLOQUEADA, NAO_APLICAVEL) para o formato comum e acrescenta o resumo e os factos de cada
 * etapa a partir dos números que o módulo devolve (execução, venda, facturado, por receber, orçamento, custo).
 */
final class FluxoProjetos extends AvaliadorFluxo
{
    private const ESTADOS_PROJECTO = ['PREPARACAO' => 'Em preparação', 'EM_CURSO' => 'Em curso', 'ACTIVO' => 'Em curso', 'ENCERRADO' => 'Encerrado', 'CANCELADO' => 'Cancelado'];

    private const ESTADOS = ['CONCLUIDA' => 'concluida', 'NAO_APLICAVEL' => 'concluida', 'EM_CURSO' => 'curso', 'POR_FAZER' => 'fazer', 'BLOQUEADA' => 'bloqueada'];

    public function __construct(ContextoEmpresa $contexto, private readonly ServicoAnaliticoProjetos $analitico)
    {
        parent::__construct($contexto);
    }

    public function temActividade(): bool
    {
        return DB::table('projetos')->where('empresa_id', $this->empresa())->whereNull('eliminado_em')->exists();
    }

    public function avaliar(): array
    {
        $processos = [];
        // cliente (customer_id) e data de abertura (created_at) de cada projecto, como no legado (fluxo_projectos.js:90, 238)
        $extra = DB::table('projetos as p')->leftJoin('terceiros as t', fn ($j) => $j->on('t.id', '=', 'p.cliente_id')->where('t.empresa_id', $this->empresa()))
            ->where('p.empresa_id', $this->empresa())->get(['p.id', 'p.criado_em', 't.nome as cliente'])->keyBy('id');
        foreach ($this->analitico->fluxo() as $p) {
            $et = $p['etapas'];
            $E = [];
            $resumos = [
                'abertura' => [$et['abertura'] === 'BLOQUEADA' ? 'Projecto externo sem encomenda válida' : 'Aberto', [self::f('Tipo', $p['tipo'] ?: '—'), self::f('Estado', $p['estado'] ?: '—')],
                    $et['abertura'] === 'BLOQUEADA' ? [self::erro('Projecto externo sem encomenda de venda válida: ligue a encomenda do cliente ao projecto.')] : []],
                'planeamento' => [$et['planeamento'] === 'CONCLUIDA' ? 'WBS definida' : 'Sem tarefas na WBS', [self::f('Marcos', $p['marcos'], 'num')], []],
                'equipa_orcamento' => [($p['membros'] ? $p['membros'].' membro(s)' : 'Sem equipa').' · '.(bccomp((string) $p['orcamento'], '0', 2) > 0 ? 'orçamento '.self::kz($p['orcamento']) : 'sem orçamento'),
                    [self::f('Membros', $p['membros'], 'num'), self::f('Orçamento', $p['orcamento'], 'kz'), self::f('Posições no organigrama', $p['posicoes'], 'num')],
                    $p['acima_orcamento'] ? [self::aviso('Custo realizado acima do orçamento.')] : []],
                'execucao' => [round((float) $p['execucao']).'% executado', [self::f('Execução', round((float) $p['execucao'], 1).'%'), self::f('Custo realizado', $p['custo'], 'kz'), self::f('Horas', $p['horas'], 'num'),
                    self::f('Requisições pendentes', $p['requisicoes_pendentes'], 'num')], $p['tarefas_atrasadas'] ? [self::aviso($p['tarefas_atrasadas'].' tarefa(s) em atraso.')] : []],
                'faturacao' => [$et['faturacao'] === 'NAO_APLICAVEL' ? 'Não aplicável (projecto interno)' : 'Facturado '.self::kz($p['faturado']).' de '.self::kz($p['venda']),
                    [self::f('Venda (sem IVA)', $p['venda'], 'kz'), self::f('Facturado (sem IVA)', $p['faturado'], 'kz')], []],
                'recebimento' => [$et['recebimento'] === 'NAO_APLICAVEL' ? 'Não aplicável (projecto interno)' : (bccomp((string) $p['por_receber'], '0.01', 2) >= 0 ? 'Por receber '.self::kz($p['por_receber']) : 'Recebido'),
                    [self::f('Por receber', $p['por_receber'], 'kz')], []],
                'encerramento' => [match ($et['encerramento']) {
                    'CONCLUIDA' => $p['estado'] === 'CANCELADO' ? 'Cancelado' : 'Encerrado', 'EM_CURSO' => 'Pronto a encerrar', default => 'Depois de executado e recebido'
                }, [], []],
            ];
            foreach ($resumos as $id => [$resumo, $factos, $problemas]) {
                $estado = self::ESTADOS[$et[$id]] ?? 'fazer';
                $E[$id] = self::etapa($estado, $resumo, $factos, $problemas, $estado === 'concluida' ? [] : [self::accao('Abrir o projecto', 'projectos_carteira', true)]);
            }
            $x = $extra[$p['id']] ?? null;
            $externo = $p['tipo'] === 'EXTERNO';
            $cliente = $externo ? ($x?->cliente ?: null) : 'Interno';
            $processos[] = ['chave' => 'projeto-'.$p['id'], 'titulo' => trim("{$p['codigo']} {$p['nome']}") ?: "Projecto #{$p['id']}",
                'subtitulo' => implode(' · ', array_filter([$externo ? ($cliente ?: 'Externo') : 'Interno', self::ESTADOS_PROJECTO[$p['estado']] ?? $p['estado']])),
                'data' => $x?->criado_em ? (string) $x->criado_em : null, 'valor' => $p['venda'], 'valor_pendente' => $p['por_receber'], 'etapas' => $E,
                'cliente' => $cliente ?: '—', 'execucao' => $p['execucao'], 'pct' => (int) round((float) $p['execucao']), 'custo' => self::dinheiro($p['custo']),
                'faturado' => self::dinheiro($p['faturado']), 'ativo' => $p['ativo'], 'atrasado' => $p['tarefas_atrasadas'] > 0, 'desvio' => (bool) $p['acima_orcamento'],
                'documentos' => [['tipo' => 'projeto', 'id' => $p['id'], 'numero' => $p['codigo']]]] + self::contagem($E);
        }
        $activos = array_filter($processos, fn ($p) => $p['ativo']);
        $porReceber = self::somar($processos, fn ($p) => $p['valor_pendente']);

        // indicadores do legado (renderFluxoProjectos, fluxo_projectos.js:293-299)
        return ['processos' => $processos, 'kpis' => [
            self::kpi('activos', 'Projectos activos', count($activos)),
            self::kpi('atrasados', 'Com tarefas atrasadas', $n = count(array_filter($activos, fn ($p) => $p['atrasado'])), 'num', $n > 0),
            self::kpi('desvio', 'Acima do orçamento', $n = count(array_filter($activos, fn ($p) => $p['desvio'])), 'num', $n > 0),
            self::kpi('carteira', 'Carteira activa (sem IVA)', self::somar($activos, fn ($p) => $p['valor']), 'kz'),
            self::kpi('por_receber', 'Valor por receber', $porReceber, 'kz', bccomp($porReceber, '0.01', 2) > 0),
        ]];
    }

    /** Vista de volume do legado (fluxo_projectos.js:247-285). */
    public function vista(): array
    {
        $venda = fn (array $g) => self::somar($g, fn ($p) => $p['valor']);

        return [
            'nome_itens' => 'projectos', 'placeholder' => 'Pesquisar projecto ou cliente',
            'funil' => [
                ['id' => 'abertura', 'rotulo' => 'Abertura incompleta'],
                ['id' => 'planeamento', 'rotulo' => 'Por planear', 'valor' => $venda],
                ['id' => 'equipa_orcamento', 'rotulo' => 'Sem orçamento/equipa', 'valor' => $venda],
                ['id' => 'execucao', 'rotulo' => 'Em execução', 'valor' => $venda],
                // por facturar = venda × % executada − facturado (sem IVA)
                ['id' => 'faturacao', 'rotulo' => 'Por facturar', 'valor' => fn (array $g) => self::somar($g, fn ($p) => max(0, (float) $p['valor'] * $p['pct'] / 100 - (float) $p['faturado']))],
                ['id' => 'recebimento', 'rotulo' => 'Por receber', 'valor' => fn (array $g) => self::somar($g, fn ($p) => $p['valor_pendente'])],
                ['id' => 'encerramento', 'rotulo' => 'Por encerrar'],
                ['id' => 'concluidos', 'rotulo' => 'Encerrados'],
            ],
            'filtros' => [
                ['id' => 'curso', 'rotulo' => 'Activos', 'fn' => fn ($p) => $p['ativo']],
                ['id' => 'alerta', 'rotulo' => 'Com pendências', 'fn' => fn ($p) => $p['n_erros'] + $p['n_avisos'] > 0],
                ['id' => 'atrasados', 'rotulo' => 'Com tarefas atrasadas', 'fn' => fn ($p) => $p['atrasado']],
                ['id' => 'desvio', 'rotulo' => 'Acima do orçamento', 'fn' => fn ($p) => $p['desvio']],
                ['id' => 'receber', 'rotulo' => 'Com valor por receber', 'fn' => fn ($p) => bccomp(self::dinheiro($p['valor_pendente']), '0.01', 2) > 0],
                ['id' => 'todas', 'rotulo' => 'Todos', 'fn' => fn () => true],
            ],
            'colunas' => [
                ['tipo' => 'titulo', 'rotulo' => 'Projecto'],
                ['tipo' => 'texto', 'rotulo' => 'Cliente', 'campo' => 'cliente'],
                ['tipo' => 'pct', 'rotulo' => 'Executado', 'campo' => 'pct'],
                ['tipo' => 'etapa', 'rotulo' => 'Etapa actual'],
                ['tipo' => 'progresso', 'rotulo' => 'Progresso'],
                ['tipo' => 'pendencias', 'rotulo' => 'Pendências', 'marca' => ['campo' => 'desvio', 'texto' => 'Desvio']],
                ['tipo' => 'kz', 'rotulo' => 'Venda s/ IVA', 'campo' => 'valor', 'modo' => 'opcional'],
                ['tipo' => 'kz', 'rotulo' => 'Custo', 'campo' => 'custo'],
                ['tipo' => 'kz', 'rotulo' => 'Por receber', 'campo' => 'valor_pendente', 'modo' => 'destaque'],
            ],
            'vazio' => ['icone' => 'hard-hat', 'titulo' => 'Ainda não há projectos', 'texto' => 'Abra o primeiro projecto na Carteira de Projectos; o fluxo começa na abertura.',
                'botao' => 'Abrir a Carteira de Projectos', 'acao' => 'projectos_carteira'],
        ];
    }
}
