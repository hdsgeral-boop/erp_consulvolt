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
            $processos[] = ['chave' => 'projeto-'.$p['id'], 'titulo' => trim("{$p['codigo']} {$p['nome']}"), 'subtitulo' => (string) $p['estado'], 'data' => null, 'valor' => $p['venda'],
                'valor_pendente' => $p['por_receber'], 'etapas' => $E, 'execucao' => $p['execucao'], 'ativo' => $p['ativo'],
                'documentos' => [['tipo' => 'projeto', 'id' => $p['id'], 'numero' => $p['codigo']]]] + self::contagem($E);
        }

        return ['processos' => $processos, 'kpis' => array_merge(self::kpisBase($processos, 7), [
            self::kpi('activos', 'Projectos activos', count(array_filter($processos, fn ($p) => $p['ativo']))),
            self::kpi('por_receber', 'Valor por receber', $v = array_reduce($processos, fn ($s, $p) => bcadd($s, self::dinheiro($p['valor_pendente']), 2), '0.00'), 'kz', bccomp($v, '0.01', 2) > 0),
        ])];
    }
}
