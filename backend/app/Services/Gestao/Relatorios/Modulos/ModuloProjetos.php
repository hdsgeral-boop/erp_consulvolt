<?php

namespace App\Services\Gestao\Relatorios\Modulos;

use App\Services\Projetos\ServicoAnaliticoProjetos;
use App\Support\Tenancy\ContextoEmpresa;

/**
 * Projectos (relatorios_gestao.js:468-530): proveitos, custos e margem por projecto; execução e desvio orçamental.
 * Os números vêm do módulo de Projectos (ServicoAnaliticoProjetos::rentabilidade, ADR-052), que já reproduz esta secção
 * do legado: razão do projecto + facturas da encomenda sem IVA (NC abatem) + compras imputadas; orçamento, aditamentos
 * aprovados e execução são situação actual.
 */
final class ModuloProjetos extends ModuloGestao
{
    public function __construct(ContextoEmpresa $contexto, private readonly ServicoAnaliticoProjetos $analitico)
    {
        parent::__construct($contexto);
    }

    public function id(): string
    {
        return 'projectos';
    }

    public function nome(): string
    {
        return 'Projectos';
    }

    public function descricao(): string
    {
        return 'Proveitos, custos e margem por projecto; execução e desvio orçamental.';
    }

    public function calcular(array $p): array
    {
        $r = $this->analitico->rentabilidade($p['inicio'], $p['fim']);
        $k = $r['kpis'];
        $linhas = array_map(fn ($l) => ['nome' => $l['nome'], 'estado' => $l['estado'], 'proveitos' => $l['proveitos'], 'custos' => $l['custos'], 'margem' => $l['margem'],
            'orcamento' => $l['orcamento'], 'desvio' => $l['desvio_pct'], 'execucao' => $l['execucao'], 'horas' => $l['horas']], $r['linhas']);

        return [
            'kpis' => [
                self::k('activos', 'Projectos activos', $k['projetos_ativos'], 'num', 'neutro', '', ['actual' => true]),
                self::k('proveitos', 'Proveitos dos projectos', $k['proveitos'], 'kz', 'sobe', 'Razão do projecto + facturas ligadas à encomenda do projecto.'),
                self::k('custos', 'Custos dos projectos', $k['custos'], 'kz', 'desce', 'Razão do projecto + compras imputadas.'),
                self::k('margem', 'Margem dos projectos', $k['margem'], 'kz', 'sobe', 'Proveitos − custos.'),
                self::k('margem_pct', 'Margem %', $k['margem_pct'], 'pct', 'sobe'),
                self::k('horas', 'Horas registadas', $k['horas'], 'horas', 'neutro', 'Folhas de horas no período.'),
                self::k('orcamento', 'Orçamento total', $k['orcamento'], 'kz', 'neutro', '', ['actual' => true]),
                self::k('desvio', 'Desvio orçamental (custo acumulado)', $k['desvio_pct'], 'pct', 'desce', 'Custo acumulado até ao fim ÷ orçamento − 1. Negativo = abaixo do orçamento.'),
                self::k('aditamentos', 'Aditamentos aprovados', $k['aditamentos_aprovados'], 'kz', 'neutro', 'Trabalhos a mais aprovados (valor total).', ['actual' => true]),
                self::k('atrasadas', 'Tarefas em atraso', $k['tarefas_atrasadas'], 'num', 'desce', 'Data de fim prevista anterior ao fim do período e não concluídas.'),
            ],
            'tabelas' => [self::tabela('rentabilidade', 'Rentabilidade por projecto', 'nome', [['nome', 'Projecto'], ['estado', 'Estado'], ['proveitos', 'Proveitos', 'kz'],
                ['custos', 'Custos', 'kz'], ['margem', 'Margem', 'kz'], ['orcamento', 'Orçamento', 'kz'], ['desvio', 'Desvio orç.', 'pct'], ['execucao', 'Execução', 'pct'], ['horas', 'Horas', 'num']], $linhas)],
            'graficos' => [],
            'notas' => ['Estado, orçamento, aditamentos e execução das tarefas são a situação actual (não têm data).'],
        ];
    }
}
