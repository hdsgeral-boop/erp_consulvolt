<?php

namespace App\Services\Gestao\Paineis;

use App\Models\Utilizador;
use App\Services\Ativos\ServicoAquisicoesAtivos;
use App\Services\Integracoes\Cambios\ServicoCambiosBAIAutomaticos;
use App\Services\RH\ServicoAvaliacao360;
use App\Services\RH\ServicoEstruturaOrg;
use App\Services\RH\ServicoPortalColaborador;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Página de início (vista `welcome`, renderWelcome — ui_dashboard.js:621-1000): cabeçalho da empresa activa, saudação pela hora,
 * processos pendentes, "Dica do Dia", comunicado da avaliação 360º por confirmar e o acesso aos módulos.
 *
 * Processos pendentes (ui_dashboard.js:637-718), cada contador visível só com a permissão do legado:
 *   documentos de tesouraria por integrar (teso_contab_integracao) · linhas de extracto por reconciliar (teso_gestao_conciliacao)
 *   · facturas FT/FR por liquidar (vendas_faturacao) · pedidos de compra por aprovar e encomendas por receber (compras) · períodos
 *   salariais abertos (calcular) · aquisições por inventariar (activos — ServicoAquisicoesAtivos::pendentes) · activos sem
 *   amortização (activos, activos_amortizacoes) · manutenções planeadas (activos, activos_manutencao) · orçamentos por aprovar
 *   (orc_aprovar) · excessos orçamentais por decidir (orc_aprovar_excesso) · acréscimos/diferimentos a regularizar (ad_propostas)
 *   · actividades CRM em atraso (crm_agenda) · vagas em aberto (est_mapa) · pedidos do portal que o utilizador decide
 *   (ServicoPortalColaborador::pendentesParaMim) · acções pendentes da avaliação 360º do próprio.
 * Cada contador é isolado: uma falha num módulo não impede os restantes (como o legado, `contar`).
 * Correcções: extractos anulados não contam como "por reconciliar"; encomendas e pedidos anulados não contam; as aquisições por
 * inventariar excluem linhas estornadas (regra do módulo, ADR-051).
 */
final class ServicoInicio
{
    public const DICA_DO_DIA = 'Pode usar o atalho CTRL+K para pesquisar rapidamente.';

    /** Módulos da página de início (ui_dashboard.js:720-732) e os ecrãs que os tornam acessíveis (moduleSubmenus, ui_dashboard.js:439-545). */
    private const MODULOS = [
        ['colaboradores', 'Recursos Humanos', 'Gestão de pessoal, contratos e processamento.', ['colaboradores', 'rh_portal', 'rh_portal_gestao', 'contratos', 'funcoes', 'infotipos', 'bancario', 'calcular', 'processamento', 'relatorios']],
        ['teso_contab_integracao', 'Tesouraria', 'Pagamentos, recebimentos e conciliação.', ['teso_contab_integracao', 'teso_gestao_mapas', 'teso_gestao_conciliacao', 'teso_gestao_pagamentos', 'teso_gestao_recebimentos', 'teso_contab_historico']],
        ['pos', 'Ponto de Venda (POS)', 'Facturação rápida, caixa POS e fecho de turno.', ['pos']],
        ['vendas_faturacao', 'Vendas e Clientes', 'Facturação, clientes e contas a receber.', ['vendas_faturacao', 'tabelas_aux', 'vendas_relatorios', 'pos', 'vendas_produtos']],
        ['compras', 'Compras e Stocks', 'Aprovisionamento, fornecedores e armazém.', ['compras', 'tabelas_aux', 'armazem']],
        ['contabilidade', 'Contabilidade Geral', 'Diários, balancetes e demonstrações financeiras.', ['contabilidade', 'tabelas_aux', 'relatorios_contabeis', 'lancamentos', 'contab_rotinas', 'ad_registos', 'ad_propostas', 'ad_recolher']],
        ['activos', 'Activos Fixos', 'Imobilizado, amortizações e manutenção.', ['activos', 'activos_categorias', 'activos_amortizacoes', 'activos_manutencao', 'activos_abates', 'activos_mapa']],
        ['projectos', 'Projectos e Obras', 'Carteira de projectos, custos analíticos e planeamento Gantt.', ['projectos_carteira', 'projectos_gantt', 'projectos_extracto']],
        ['modulo_orcamento', 'Gestão Orçamental', 'Orçamentos, controlo orçado vs realizado, previsões e cenários.', ['orc_rubricas', 'orc_cenarios', 'orc_orcamentos', 'orc_controlo', 'orc_previsoes', 'orc_alertas']],
        ['modulo_crm', 'CRM', 'Pipeline comercial, agenda, previsão de vendas e campanhas.', ['crm_contas', 'crm_config', 'crm_pipeline', 'crm_agenda', 'crm_previsao', 'crm_campanhas']],
        ['modulo_estrutura', 'Estrutura Orgânica', 'Unidades, cargos e vagas, organigrama e mapa de pessoal.', ['est_estrutura', 'funcoes', 'est_organigrama', 'est_mapa']],
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoAquisicoesAtivos $aquisicoes,
        private readonly ServicoEstruturaOrg $estrutura,
        private readonly ServicoPortalColaborador $portal,
    ) {}

    /** @return array<string, mixed> */
    public function inicio(): array
    {
        $empresa = $this->contexto->obrigatorio();
        $u = $this->utilizador();
        $e = DB::table('empresas')->where('id', $empresa)->first(['id', 'nome', 'nif', 'endereco', 'municipio', 'provincia', 'e_consolidacao', 'logotipo']);
        $hora = (int) now()->format('G');
        $pendentes = $this->pendentes();

        return [
            'empresa' => ['id' => $e->id, 'nome' => $e->nome, 'nif' => $e->nif, 'endereco' => $e->endereco ?: 'Angola', 'municipio' => $e->municipio, 'provincia' => $e->provincia,
                'holding' => (bool) $e->e_consolidacao, 'tem_logotipo' => ! empty($e->logotipo)],
            'utilizador' => ['id' => $u->getKey(), 'nome_utilizador' => $u->nome_utilizador],
            'saudacao' => $hora >= 13 && $hora < 20 ? 'Boa tarde' : ($hora >= 20 || $hora < 5 ? 'Boa noite' : 'Bom dia'),
            'data_acesso' => now()->toDateString(),
            'pendentes' => $pendentes,
            'total_pendentes' => array_sum(array_column($pendentes, 'quantidade')),
            'dica_do_dia' => self::DICA_DO_DIA,
            'comunicado_avaliacao' => $this->comunicado(),
            'modulos' => array_map(fn ($m) => ['id' => $m[0], 'nome' => $m[1], 'descricao' => $m[2], 'autorizado' => $this->podeVer(array_merge([$m[0]], $m[3]))], self::MODULOS),
        ];
    }

    /** @return list<array{id: string, rotulo: string, quantidade: int, vista: string}> só os contadores visíveis e maiores que zero */
    public function pendentes(): array
    {
        $empresa = $this->contexto->obrigatorio();
        $hoje = now()->toDateString();
        $n = fn (string $sql, array $p = []) => (int) DB::selectOne($sql, array_merge([$empresa], $p))->n;
        $definicoes = [
            ['teso_docs', 'Docs p/ Integrar', 'teso_contab_integracao', fn () => $this->podeVer(['teso_contab_integracao']),
                fn () => $n("SELECT COUNT(*) AS n FROM documentos_tesouraria WHERE empresa_id = ? AND estado = 'PENDENTE' AND anulado_em IS NULL")],
            ['teso_conciliacao', 'Itens por reconciliar', 'teso_gestao_conciliacao', fn () => $this->podeVer(['teso_gestao_conciliacao']),
                fn () => $n("SELECT COUNT(*) AS n FROM linhas_extrato_bancario WHERE empresa_id = ? AND COALESCE(estado, '') NOT IN ('CONCILIADO', 'ANULADO')")],
            ['vendas_por_liquidar', 'Facturas por liquidar', 'vendas_faturacao', fn () => $this->podeVer(['vendas_faturacao']),
                fn () => $n("SELECT COUNT(*) AS n FROM vendas WHERE empresa_id = ? AND tipo_documento IN ('FT', 'FR') AND COALESCE(estado, '') NOT IN ('PAGO', 'CANCELADO', 'ANULADO')")],
            ['compras_pedidos', 'Pedidos por aprovar', 'compras', fn () => $this->podeVer(['compras']),
                fn () => $n("SELECT COUNT(*) AS n FROM pedidos_compra WHERE empresa_id = ? AND estado = 'PENDENTE' AND anulado_em IS NULL")],
            ['compras_encomendas', 'Encomendas por receber', 'compras', fn () => $this->podeVer(['compras']),
                fn () => $n("SELECT COUNT(*) AS n FROM encomendas_compra WHERE empresa_id = ? AND anulado_em IS NULL AND COALESCE(estado, '') NOT IN ('RECEBIDO', 'ANULADO', 'ANULADA')")],
            ['rh_periodos_abertos', 'Períodos RH abertos', 'calcular', fn () => $this->podeVer(['calcular']),
                fn () => $n("SELECT COUNT(*) AS n FROM periodos_processamento_salarial WHERE empresa_id = ? AND estado = 'ABERTO'")],
            ['ativos_inventariar', 'Doc. por inventariar', 'activos_pendentes', fn () => $this->podeVer(['activos']),
                fn () => count($this->aquisicoes->pendentes()['linhas'])],
            ['ativos_sem_amortizacao', 'Activos s/ amortização', 'activos_amortizacoes', fn () => $this->podeVer(['activos', 'activos_amortizacoes']),
                fn () => $n("SELECT COUNT(*) AS n FROM ativos_imobilizados a WHERE a.empresa_id = ? AND a.eliminado_em IS NULL AND a.estado = 'ACTIVO'
                    AND NOT EXISTS (SELECT 1 FROM amortizacoes_ativos q WHERE q.ativo_imobilizado_id = a.id)")],
            ['ativos_manutencoes', 'Manutenções planeadas', 'activos_manutencao', fn () => $this->podeVer(['activos', 'activos_manutencao']),
                fn () => $n("SELECT COUNT(*) AS n FROM registos_manutencao_ativos WHERE empresa_id = ? AND COALESCE(estado, 'PLANEADA') = 'PLANEADA'")],
            ['orc_por_aprovar', 'Orçamentos por aprovar', 'orc_orcamentos', fn () => $this->podeTarefa('orc_aprovar'),
                fn () => $n("SELECT COUNT(*) AS n FROM orcamentos_anuais WHERE empresa_id = ? AND estado = 'SUBMETIDO'")],
            ['orc_excessos', 'Excessos orçamentais por decidir', 'orc_alertas', fn () => $this->podeTarefa('orc_aprovar_excesso'),
                fn () => $n("SELECT COUNT(*) AS n FROM pedidos_extrapolacao_orcamento WHERE empresa_id = ? AND estado = 'PENDENTE'")],
            ['acrescimos_regularizar', 'Acréscimos/diferimentos a regularizar', 'ad_propostas', fn () => $this->podeVer(['ad_propostas']),
                fn () => $n("SELECT COUNT(*) AS n FROM itens_acrescimos_diferimentos WHERE empresa_id = ? AND estado IN ('A_REGULARIZAR', 'A_TERMINAR')")],
            ['crm_atraso', 'Actividades CRM em atraso', 'crm_agenda', fn () => $this->podeVer(['crm_agenda']),
                fn () => $n('SELECT COUNT(*) AS n FROM atividades_comerciais_crm WHERE empresa_id = ? AND NOT COALESCE(concluida, false) AND data_prevista < ?', [$hoje])],
            ['estrutura_vagas', 'Vagas em aberto', 'est_mapa', fn () => $this->podeVer(['est_mapa']),
                fn () => (int) collect($this->estrutura->arvore()['unidades'])->flatMap(fn ($x) => $x['postos'])->sum('livres')],
            // ronda 2: câmbios obtidos automaticamente do BAI à espera de validação (nada é gravado sem validar)
            ['cambios_bai', 'Câmbios do BAI por validar', 'config_moedas', fn () => $this->podeTarefa('config_moedas_gerir'),
                fn () => (int) DB::table('cambios_bai_pendentes')->where('estado', ServicoCambiosBAIAutomaticos::PENDENTE)->count()],
            ['portal_pedidos', 'Pedidos de colaboradores por decidir', 'rh_portal', fn () => true, fn () => count($this->portal->pendentesParaMim())],
            ['avaliacao_360', 'Avaliação de desempenho: acções pendentes', 'rh_portal', fn () => true, fn () => $this->pendentes360()],
        ];
        $saida = [];
        foreach ($definicoes as [$id, $rotulo, $vista, $pode, $contar]) {
            try {
                $q = $pode() ? (int) $contar() : 0;
            } catch (Throwable $e) {
                report($e);
                $q = 0;
            }
            if ($q > 0) {
                $saida[] = ['id' => $id, 'rotulo' => $rotulo, 'quantidade' => $q, 'vista' => $vista];
            }
        }

        return $saida;
    }

    /**
     * Acções 360º do próprio (ui_dashboard.js:693; aval360_dados.js:377, 531): comunicado do ciclo aberto por confirmar, respostas
     * 360 por fazer, a sua avaliação a aguardar tomada de conhecimento e contestações que lhe cabe decidir.
     */
    private function pendentes360(): int
    {
        $eu = $this->portal->colaboradorDe();
        $c = DB::table('ciclos_avaliacao_360')->where('empresa_id', $this->contexto->obrigatorio())->where('estado', 'ABERTO')->orderByDesc('id')->first();
        $n = 0;
        if ($eu && $c) {
            if ($this->participa($c, $eu) && ! DB::table('confirmacoes_avaliacao_rh')->where('ciclo_avaliacao_id', $c->id)->where('colaborador_id', $eu)->exists()) {
                $n++;
            }
            $n += count(app(ServicoAvaliacao360::class)->minhasTarefas());
            $n += DB::table('avaliacoes_desempenho_rh')->where('empresa_id', $this->contexto->obrigatorio())->where('colaborador_id', $eu)->where('ano', $c->ano)
                ->where('periodo', $c->periodo)->where('estado', 'CONCLUIDA')->whereNull('conhecimento')->count();
        }
        $rh = $this->podeTarefa('rh_aval_parecer');
        $n += DB::table('avaliacoes_desempenho_rh')->where('empresa_id', $this->contexto->obrigatorio())->whereNotNull('contestacao')->get(['colaborador_id', 'contestacao'])
            ->filter(function ($a) use ($eu, $rh) {
                $ct = json_decode((string) $a->contestacao, true) ?: [];
                if (! empty($ct['decisao']) || ($eu && in_array($eu, [(int) $a->colaborador_id, (int) ($ct['chefia_colaborador_id'] ?? 0)], true))) {
                    return false;
                }

                return ! empty($ct['decisor_colaborador_id']) ? $eu === (int) $ct['decisor_colaborador_id'] : $rh;
            })->count();

        return $n;
    }

    /** Comunicado do ciclo 360º aberto que o próprio ainda não confirmou (leituraPendente, aval360_dados.js:531-537). */
    private function comunicado(): ?array
    {
        try {
            $eu = $this->portal->colaboradorDe();
            $c = $eu ? DB::table('ciclos_avaliacao_360')->where('empresa_id', $this->contexto->obrigatorio())->where('estado', 'ABERTO')->orderByDesc('id')->first() : null;
            if (! $c || ! $this->participa($c, $eu) || DB::table('confirmacoes_avaliacao_rh')->where('ciclo_avaliacao_id', $c->id)->where('colaborador_id', $eu)->exists()) {
                return null;
            }

            return ['ciclo_avaliacao_id' => $c->id, 'nome' => $c->nome, 'ano' => $c->ano, 'periodo' => $c->periodo, 'comunicado' => json_decode((string) $c->comunicado, true) ?? $c->comunicado];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function participa(object $ciclo, int $colaborador): bool
    {
        return collect(json_decode((string) $ciclo->participantes, true) ?: [])
            ->contains(fn ($p) => (int) ($p['colaborador_id'] ?? $p['employee_id'] ?? 0) === $colaborador);
    }

    private function podeVer(array $vistas): bool
    {
        foreach ($vistas as $v) {
            if ($this->permissoes->podeVer($this->utilizador(), $v, $this->contexto->id())) {
                return true;
            }
        }

        return false;
    }

    private function podeTarefa(string $tarefa): bool
    {
        return $this->permissoes->pode($this->utilizador(), $tarefa, $this->contexto->id());
    }

    private function utilizador(): Utilizador
    {
        /** @var Utilizador */
        return Auth::user();
    }
}
