<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Services\Ativos\ServicoRelatoriosAtivos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo do imobilizado (js/fluxo_imobilizado.js): adaptador do fluxo já implementado no módulo de Activos
 * (ServicoRelatoriosAtivos::fluxo, ADR-051 — da aquisição à integração das amortizações). Só converte para o formato comum:
 * chave segura para URL, problemas como {nivel, texto} (erro nas etapas bloqueadas) e os meses por calcular/integrar como
 * indicadores. As facturas de fornecedor por contabilizar com artigos de imobilizado continuam fora (decisão do ADR-051).
 */
final class FluxoImobilizado extends AvaliadorFluxo
{
    public function __construct(ContextoEmpresa $contexto, private readonly ServicoRelatoriosAtivos $ativos)
    {
        parent::__construct($contexto);
    }

    public function temActividade(): bool
    {
        $e = $this->empresa();

        return DB::table('ativos_imobilizados')->where('empresa_id', $e)->exists()
            || DB::table('lancamentos_contabeis')->where('empresa_id', $e)->where('tipo_dc', 'D')->whereRaw("(TRIM(codigo_conta) LIKE '11%' OR TRIM(codigo_conta) LIKE '12%')")->exists();
    }

    public function avaliar(): array
    {
        $r = $this->ativos->fluxo();
        $processos = [];
        foreach ($r['aquisicoes'] as $a) {
            $E = [];
            foreach ($a['etapas'] as $id => $x) {
                $nivel = $x['estado'] === 'bloqueada' ? 'erro' : 'aviso';
                $factos = [];
                if (isset($x['por_inventariar'])) {
                    $factos[] = self::f('Por inventariar', $x['por_inventariar'], 'kz');
                }
                if (! empty($x['periodos'])) {
                    $factos[] = self::f('Meses', self::lista($x['periodos']));
                }
                $E[$id] = self::etapa($x['estado'], (string) $x['resumo'], $factos, array_map(fn ($t) => ['nivel' => $nivel, 'texto' => (string) $t], $x['problemas'] ?? []),
                    $x['estado'] === 'concluida' ? [] : [self::accao('Abrir Activos', match ($id) {
                        'calc', 'integ' => 'activos_amortizacoes', 'invent' => 'activos_pendentes', default => 'activos'
                    }, true)]);
            }
            $processos[] = ['chave' => 'aquisicao-'.preg_replace('/[^A-Za-z0-9_.:\-]/', '_', str_replace('|', ':', (string) $a['chave'])), 'titulo' => (string) $a['titulo'],
                'subtitulo' => implode(' · ', array_filter([$a['fornecedor'] ?? null, $a['contas'] ? implode(', ', $a['contas']) : null])), 'data' => $a['data'], 'valor' => $a['valor'],
                'valor_pendente' => $E['invent']['factos'][0]['valor'] ?? '0.00', 'etapas' => $E, 'ativos' => $a['ativos'],
                'documentos' => array_map(fn ($c) => ['tipo' => 'ativo', 'numero' => $c], $a['ativos'])] + self::contagem($E);
        }
        $k = $r['kpis'];

        return ['processos' => $processos, 'kpis' => [
            self::kpi('em_curso', 'Aquisições em curso', $k['em_curso']),
            self::kpi('bloqueados', 'Bloqueadas', $k['bloqueadas'], 'num', $k['bloqueadas'] > 0),
            self::kpi('concluidos', 'Concluídas', $k['concluidas']),
            self::kpi('meses_por_calcular', 'Meses por calcular', $k['meses_por_calcular'], 'num', $k['meses_por_calcular'] > 0),
            self::kpi('meses_por_integrar', 'Meses por integrar', $k['meses_por_integrar'], 'num', $k['meses_por_integrar'] > 0),
        ], 'extra' => ['meses_por_calcular' => $r['meses_por_calcular'], 'meses_por_integrar' => $r['meses_por_integrar']]];
    }
}
