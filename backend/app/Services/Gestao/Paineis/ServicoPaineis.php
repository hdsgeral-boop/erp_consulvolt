<?php

namespace App\Services\Gestao\Paineis;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard por módulos (vista `dashboard`, ui_painel_modulos.js:31-60 e 1012-1076): catálogo dos painéis, permissões,
 * período e filtros, e a montagem da holding.
 *
 * Permissões (paridade com `permitido`, ui_painel_modulos.js:53-59): cada painel exige a consulta de pelo menos um dos ecrãs do
 * seu módulo (a Visão Geral, a Análise Dinâmica e a Comparação das Empresas só exigem o Dashboard). Os indicadores da Visão
 * Geral também passam a respeitar os painéis visíveis (ver PainelGeral).
 *
 * Holding (empresa de consolidação, ui_painel_modulos.js:1021-1027 e 763-829):
 *   - painéis visíveis: Comparação das Empresas + os módulos que não são "só por empresa" (orçamento, CRM, acréscimos e estrutura
 *     ficam de fora, como no legado);
 *   - Contabilidade: o consolidado são os lançamentos da holding e cada empresa as suas linhas de agregação (sem eliminações);
 *   - restantes módulos: cada empresa do grupo com os seus próprios dados e o grupo como soma dos indicadores aditivos e das
 *     séries mensais. Mais o quadro "Comparação por Empresa" e os gráficos dos principais indicadores monetários.
 *   - correcções: só entram as empresas do grupo a que o utilizador tem acesso; as tabelas por empresa (top clientes, etc.) não
 *     são somadas (o legado juntava os dados das empresas num só conjunto).
 *
 * Cache curta (120 s, chave por empresa + painel + período + filtros + acessos): os painéis agregam muitos módulos; `actualizar`
 * força o recálculo (botão "Actualizar" do legado).
 */
final class ServicoPaineis
{
    public const TTL_CACHE = 120;

    /** id => [nome, classe, vistas exigidas (qualquer), só por empresa, só holding] */
    public const CATALOGO = [
        'geral' => ['nome' => 'Visão Geral', 'classe' => PainelGeral::class, 'vistas' => []],
        'rh' => ['nome' => 'Recursos Humanos', 'classe' => PainelRH::class, 'vistas' => ['colaboradores', 'contratos', 'calcular', 'processamento', 'relatorios']],
        'vendas' => ['nome' => 'Vendas', 'classe' => PainelVendas::class, 'vistas' => ['vendas_faturacao', 'vendas_relatorios', 'pos']],
        'compras' => ['nome' => 'Compras', 'classe' => PainelCompras::class, 'vistas' => ['compras', 'compras_encomendas', 'compras_faturacao']],
        'armazem' => ['nome' => 'Armazém', 'classe' => PainelArmazem::class, 'vistas' => ['armazem', 'armazem_stock', 'armazem_rececoes']],
        'tesouraria' => ['nome' => 'Tesouraria', 'classe' => PainelTesouraria::class,
            'vistas' => ['teso_contab_integracao', 'teso_gestao_mapas', 'teso_gestao_pagamentos', 'teso_gestao_recebimentos', 'teso_folha_caixa']],
        'contabilidade' => ['nome' => 'Contabilidade', 'classe' => PainelContabilidade::class, 'vistas' => ['contabilidade', 'lancamentos', 'relatorios_contabeis']],
        'ativos' => ['nome' => 'Activos Fixos', 'classe' => PainelAtivos::class, 'vistas' => ['activos', 'activos_amortizacoes']],
        'projetos' => ['nome' => 'Projectos e Obras', 'classe' => PainelProjetos::class, 'vistas' => ['projectos_carteira', 'projectos_extracto', 'projectos_gantt']],
        'orcamento' => ['nome' => 'Gestão Orçamental', 'classe' => PainelOrcamento::class, 'vistas' => ['orc_orcamentos', 'orc_controlo', 'orc_alertas'], 'so_empresa' => true],
        'crm' => ['nome' => 'CRM', 'classe' => PainelCRM::class, 'vistas' => ['crm_pipeline', 'crm_previsao'], 'so_empresa' => true],
        'acrescimos' => ['nome' => 'Acréscimos e Diferimentos', 'classe' => PainelAcrescimos::class, 'vistas' => ['ad_registos', 'ad_propostas'], 'so_empresa' => true],
        'estrutura' => ['nome' => 'Estrutura Orgânica', 'classe' => PainelEstrutura::class, 'vistas' => ['est_estrutura', 'est_organigrama', 'est_mapa'], 'so_empresa' => true],
        'cubo' => ['nome' => 'Análise Dinâmica', 'classe' => null, 'vistas' => []],
        'grupo' => ['nome' => 'Comparação das Empresas', 'classe' => null, 'vistas' => [], 'so_holding' => true],
    ];

    /** Indicadores que não se somam entre empresas (médias, percentagens e textos). */
    private const NAO_ADITIVOS = ['valor_medio_fatura', 'sessao_caixa', 'ticket_medio'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoComparacaoEmpresas $comparacao,
        private readonly Container $app,
    ) {}

    public function holding(): bool
    {
        return (bool) DB::table('empresas')->where('id', $this->contexto->obrigatorio())->value('e_consolidacao');
    }

    /** Pode ver algum dos ecrãs indicados (vazio = só o Dashboard, já exigido no controlador). */
    public function podeVer(array $vistas): bool
    {
        if (! $vistas) {
            return true;
        }
        $u = $this->utilizador();
        foreach ($vistas as $v) {
            if ($this->permissoes->podeVer($u, $v, $this->contexto->id())) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> painéis visíveis, pela ordem do legado */
    public function visiveis(): array
    {
        $holding = $this->holding();
        $ids = [];
        foreach (self::CATALOGO as $id => $c) {
            $ok = $holding ? ($id === 'grupo' || ($id !== 'geral' && empty($c['so_empresa']) && empty($c['so_holding']))) : empty($c['so_holding']);
            if ($ok && $this->podeVer($c['vistas'])) {
                $ids[] = $id;
            }
        }
        if ($holding) {
            $ids = array_merge(['grupo'], array_values(array_diff($ids, ['grupo'])));
        }

        return $ids;
    }

    /** @return list<array{id: string, nome: string, tipo: string}> */
    public function lista(): array
    {
        return array_map(fn ($id) => ['id' => $id, 'nome' => self::CATALOGO[$id]['nome'], 'tipo' => match ($id) {
            'cubo' => 'CUBO', 'grupo' => 'COMPARACAO', default => 'PAINEL'
        }], $this->visiveis());
    }

    /**
     * @param  array<string, mixed>  $f  ano, mes, unidade_negocio_id, centro_custo_id, iva, actualizar
     * @return array<string, mixed>
     */
    public function painel(string $modulo, array $f): array
    {
        if (! isset(self::CATALOGO[$modulo])) {
            throw new ErroNegocio('Painel desconhecido.', 'PAINEL_INVALIDO', 404);
        }
        if ($modulo === 'cubo') {
            throw new ErroNegocio('A Análise Dinâmica usa os endpoints /gestao/cubo.', 'PAINEL_INVALIDO', 422);
        }
        $visiveis = $this->visiveis();
        if (! in_array($modulo, $visiveis, true)) {
            throw new ErroNegocio('Sem acesso a este painel.', 'SEM_PERMISSAO', 403);
        }
        $p = PeriodoPainel::de(isset($f['ano']) ? (int) $f['ano'] : null, isset($f['mes']) ? (int) $f['mes'] : null);
        $filtros = array_filter(['unidade_negocio_id' => $f['unidade_negocio_id'] ?? null, 'centro_custo_id' => $f['centro_custo_id'] ?? null]) + ['iva' => $f['iva'] ?? 'sem'];
        $internos = ['modulos_visiveis' => $visiveis, 'ver_salarios' => $this->podeVerTarefa('est_ver_salarios')];
        $empresa = $this->contexto->obrigatorio();
        $holding = $this->holding();
        $acessiveis = $holding ? $this->membrosAcessiveis() : [];
        $chave = 'gestao:painel:'.md5(json_encode([$empresa, $modulo, $p->chaveMes, $filtros, $internos, $acessiveis]));
        if (! empty($f['actualizar'])) {
            Cache::forget($chave);
        }

        return Cache::remember($chave, self::TTL_CACHE, function () use ($modulo, $p, $filtros, $internos, $holding, $acessiveis, $empresa) {
            $inicio = microtime(true);
            if ($modulo === 'grupo') {
                $r = $this->comparacao->grupo($p, $filtros);
            } elseif ($holding) {
                $r = $this->consolidado($modulo, $p, $filtros + $internos, $acessiveis);
            } else {
                $r = $this->construir($modulo, $p, $filtros + $internos);
            }
            $classe = self::CATALOGO[$modulo]['classe'];
            $filtraDim = $classe ? $this->app->make($classe)->filtraDimensoes() : true;

            return ['modulo' => ['id' => $modulo, 'nome' => self::CATALOGO[$modulo]['nome']],
                'empresa' => ['id' => $empresa, 'nome' => Empresa::query()->whereKey($empresa)->value('nome'), 'holding' => $holding],
                'periodo' => $p->descrever(), 'filtros' => $filtros,
                'filtros_ignorados' => $filtraDim ? [] : array_values(array_intersect(['unidade_negocio_id', 'centro_custo_id'], array_keys($filtros))),
                'aviso' => $r['aviso'] ?? null, 'kpis' => $r['kpis'] ?? [], 'graficos' => $r['graficos'] ?? [], 'tabelas' => $r['tabelas'] ?? [], 'atalhos' => $r['atalhos'] ?? []]
                + array_diff_key($r, array_flip(['aviso', 'kpis', 'graficos', 'tabelas', 'atalhos']))
                + ['calculado_em' => now()->toIso8601String(), 'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000)];
        });
    }

    /** @return array<string, mixed> */
    private function construir(string $modulo, PeriodoPainel $p, array $f): array
    {
        /** @var Painel $painel */
        $painel = $this->app->make(self::CATALOGO[$modulo]['classe']);

        return $painel->construir($p, $f);
    }

    /**
     * Painel de módulo numa holding: consolidado + comparação por empresa (construirConsolidado, ui_painel_modulos.js:763-829).
     *
     * @param  list<array{id: int, nome: string}>  $membros
     * @return array<string, mixed>
     */
    private function consolidado(string $modulo, PeriodoPainel $p, array $f, array $membros): array
    {
        if (! $membros) {
            return ['aviso' => 'Esta holding não tem empresas do grupo de consolidação a que tenha acesso. Defina-as no menu Consolidação.', 'kpis' => []];
        }
        $contab = $modulo === 'contabilidade';
        $porEmpresa = [];
        foreach ($membros as $m) {
            $porEmpresa[] = ['empresa' => $m, 'r' => $contab ? $this->construir($modulo, $p, $f + ['origem_holding' => $m['id']])
                : $this->contexto->executarComo($m['id'], fn () => $this->construir($modulo, $p, $f))];
        }
        $consolidado = $contab ? $this->construir($modulo, $p, $f) : $this->somar(array_column($porEmpresa, 'r'));
        $kpis = $consolidado['kpis'] ?? [];
        $valor = fn (array $r, string $id) => collect($r['kpis'] ?? [])->firstWhere('id', $id)['valor'] ?? null;
        $quadro = Indicadores::tabela('comparacao_empresas', "Comparação por Empresa — {$p->nomeMes()} {$p->ano}",
            array_merge([['rotulo', 'Indicador']], array_map(fn ($e) => ['empresa_'.$e['empresa']['id'], $e['empresa']['nome'], 'valor'], $porEmpresa), [['total', $contab ? 'Consolidado' : 'Grupo', 'valor']]),
            array_map(fn ($k) => ['id' => $k['id'], 'rotulo' => $k['rotulo'], 'formato' => $k['formato']]
                + array_combine(array_map(fn ($e) => 'empresa_'.$e['empresa']['id'], $porEmpresa), array_map(fn ($e) => $valor($e['r'], $k['id']), $porEmpresa))
                + ['total' => $k['valor']], $kpis));
        $monetarios = array_slice(array_values(array_filter($kpis, fn ($k) => $k['formato'] === 'kz' && $k['valor'] !== null)), 0, 4);
        $nomes = array_map(fn ($e) => $e['empresa']['nome'], $porEmpresa);
        $graficos = [];
        if ($monetarios) {
            $graficos[] = Indicadores::grafico('indicadores_empresa', 'Principais Indicadores por Empresa', 'barras', $nomes,
                array_map(fn ($k) => Indicadores::serie($k['id'], $k['rotulo'], array_map(fn ($e) => $valor($e['r'], $k['id']) ?? '0.00', $porEmpresa)), $monetarios));
            $partes = array_values(array_filter(array_map(fn ($e) => [$e['empresa']['nome'], $valor($e['r'], $monetarios[0]['id'])], $porEmpresa), fn ($x) => (float) $x[1] > 0));
            if ($partes) {
                $graficos[] = Indicadores::grafico('peso_empresa', "Peso de Cada Empresa — {$monetarios[0]['rotulo']}", 'circular', array_column($partes, 0),
                    [Indicadores::serie($monetarios[0]['id'], $monetarios[0]['rotulo'], array_column($partes, 1))]);
            }
        }
        $aviso = $contab ? 'Consolidado a partir dos lançamentos da holding (inclui eliminações intragrupo e conversão). Cada empresa mostra as suas linhas antes das eliminações.'
            : 'Soma das '.count($porEmpresa).' empresas do grupo, em Kz, com os dados de cada módulo lidos directamente das empresas (antes de eliminações intragrupo).';

        return [
            'aviso' => $aviso.(! empty($consolidado['aviso']) ? ' '.$consolidado['aviso'] : ''),
            'kpis' => $kpis,
            'graficos' => array_merge($graficos, $consolidado['graficos'] ?? []),
            'tabelas' => array_merge([$quadro], $consolidado['tabelas'] ?? []),
            'atalhos' => $contab ? ($consolidado['atalhos'] ?? []) : [],
            'empresas' => array_column($porEmpresa, 'empresa'),
            'por_empresa' => array_map(fn ($e) => ['empresa_id' => $e['empresa']['id'], 'kpis' => $e['r']['kpis'] ?? []], $porEmpresa),
        ];
    }

    /**
     * Grupo = soma dos indicadores aditivos e das séries mensais das empresas (gráficos de 12 meses com os mesmos rótulos).
     *
     * @param  list<array<string, mixed>>  $resultados
     * @return array<string, mixed>
     */
    private function somar(array $resultados): array
    {
        $base = $resultados[0];
        $kpis = array_map(function ($k) use ($resultados) {
            if (in_array($k['id'], self::NAO_ADITIVOS, true) || ! in_array($k['formato'], ['kz', 'num'], true)) {
                return ['valor' => null, 'subtitulo' => null] + $k;
            }
            $t = $k['formato'] === 'kz' ? '0.00' : 0;
            foreach ($resultados as $r) {
                $v = collect($r['kpis'] ?? [])->firstWhere('id', $k['id'])['valor'] ?? 0;
                $t = $k['formato'] === 'kz' ? bcadd($t, Indicadores::dinheiro($v), 2) : $t + (is_numeric($v) ? $v + 0 : 0);
            }

            return ['valor' => $t, 'subtitulo' => null] + $k;
        }, $base['kpis'] ?? []);
        $graficos = [];
        foreach ($base['graficos'] ?? [] as $i => $g) {
            if ($g['tipo'] === 'circular' || $g['horizontal']) {
                continue;
            }
            $iguais = array_filter($resultados, fn ($r) => ($r['graficos'][$i]['rotulos'] ?? null) === $g['rotulos']);
            if (count($iguais) !== count($resultados)) {
                continue;
            }
            foreach ($g['series'] as $s => $serie) {
                $g['series'][$s]['valores'] = array_map(function ($n) use ($resultados, $i, $s, $g) {
                    $t = $g['monetario'] ? '0.00' : 0;
                    foreach ($resultados as $r) {
                        $v = $r['graficos'][$i]['series'][$s]['valores'][$n] ?? 0;
                        $t = $g['monetario'] ? bcadd($t, Indicadores::dinheiro($v), 2) : $t + (is_numeric($v) ? $v + 0 : 0);
                    }

                    return $t;
                }, array_keys($serie['valores']));
            }
            $graficos[] = $g;
        }

        return ['kpis' => $kpis, 'graficos' => $graficos, 'tabelas' => []];
    }

    /** @return list<array{id: int, nome: string}> membros do grupo da holding activa a que o utilizador tem acesso */
    public function membrosAcessiveis(): array
    {
        $acessiveis = array_flip($this->empresas->idsAcessiveis($this->utilizador()));

        return DB::table('grupos_consolidacao as g')->join('membros_consolidacao as m', 'm.grupo_consolidacao_id', '=', 'g.id')->join('empresas as e', 'e.id', '=', 'm.empresa_membro_id')
            ->where('g.empresa_holding_id', $this->contexto->obrigatorio())->orderBy('e.nome')->get(['e.id', 'e.nome'])
            ->filter(fn ($e) => isset($acessiveis[(int) $e->id]))->unique('id')->map(fn ($e) => ['id' => (int) $e->id, 'nome' => $e->nome])->values()->all();
    }

    private function podeVerTarefa(string $tarefa): bool
    {
        return $this->permissoes->pode($this->utilizador(), $tarefa, $this->contexto->id());
    }

    private function utilizador(): Utilizador
    {
        /** @var Utilizador */
        return Auth::user();
    }
}
