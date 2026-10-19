<?php

namespace App\Services\Sistema;

use App\Models\LogAuditoria;
use App\Models\NotificacaoSistema;
use App\Models\Utilizador;
use App\Services\Gestao\Paineis\ServicoInicio;
use Illuminate\Support\Facades\DB;

/**
 * Motor avançado de notificações e alertas do sistema por empresa com RBAC estrito por módulo.
 * Integra alertas operacionais (processos pendentes), mudanças da empresa e notificações direcionadas.
 */
final class ServicoNotificacoes
{
    /** Mapeamento de nomes amigáveis de módulos */
    public const MODULOS_NOMES = [
        'geral' => 'Geral da Empresa',
        'vendas' => 'Vendas e Facturação',
        'compras' => 'Compras e Armazém',
        'teso' => 'Tesouraria e Caixa',
        'contab' => 'Contabilidade Geral',
        'rh' => 'Recursos Humanos e Pessoal',
        'activos' => 'Activos Fixos',
        'crm' => 'CRM Comercial',
        'orcamento' => 'Gestão Orçamental',
        'sistema' => 'Sistema e Parâmetros',
    ];

    /** Ecrãs de controlo por módulo para validação de RBAC */
    private const MODULOS_ECRAS = [
        'vendas' => ['vendas_faturacao', 'pos', 'vendas_produtos', 'vendas_relatorios'],
        'compras' => ['compras', 'armazem', 'armazem_stock'],
        'teso' => ['teso_contab_integracao', 'teso_gestao_conciliacao', 'teso_gestao_pagamentos', 'teso_gestao_recebimentos'],
        'contab' => ['contabilidade', 'lancamentos', 'ad_propostas', 'relatorios_contabeis'],
        'rh' => ['colaboradores', 'calcular', 'processamento', 'rh_portal', 'est_mapa'],
        'activos' => ['activos', 'activos_amortizacoes', 'activos_manutencao'],
        'crm' => ['crm_agenda', 'crm_pipeline', 'crm_contas'],
        'orcamento' => ['orc_orcamentos', 'orc_alertas', 'orc_controlo'],
        'sistema' => ['config_utilizadores', 'config_perfis', 'config_moedas', 'config_empresas', 'config_geral'],
    ];

    /** @var ServicoPermissoes */
    private $permissoes;

    /** @var ServicoInicio */
    private $servicoInicio;

    public function __construct(
        ServicoPermissoes $permissoes,
        ServicoInicio $servicoInicio
    ) {
        $this->permissoes = $permissoes;
        $this->servicoInicio = $servicoInicio;
    }

    /** Cria uma nova notificação para a empresa. */
    public function criar(
        int $empresaId,
        string $tipo,
        string $categoria,
        string $titulo,
        string $mensagem,
        ?string $link = null,
        ?int $utilizadorId = null,
        ?string $autorNome = null,
    ): NotificacaoSistema {
        return NotificacaoSistema::query()->create([
            'empresa_id' => $empresaId,
            'utilizador_id' => $utilizadorId,
            'autor_nome' => $autorNome,
            'tipo' => $tipo,
            'categoria' => strtolower(trim($categoria)),
            'titulo' => $titulo,
            'mensagem' => $mensagem,
            'link' => $link,
            'lida' => false,
        ]);
    }

    /**
     * Verifica se o utilizador tem permissão para aceder às notificações/alertas do módulo.
     */
    public function podeVerModulo(?Utilizador $utilizador, string $modulo): bool
    {
        if (! $utilizador) {
            return false;
        }

        if ($utilizador->eSuperAdministrador() || $this->permissoes->total($utilizador) || $this->permissoes->administrador($utilizador)) {
            return true;
        }

        $mod = strtolower(trim($modulo));
        if ($mod === 'geral') {
            return true;
        }

        $ecras = self::MODULOS_ECRAS[$mod] ?? [];
        foreach ($ecras as $ecra) {
            if ($this->permissoes->podeVer($utilizador, $ecra)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna a lista de módulos que o utilizador tem autorização para visualizar.
     *
     * @return array<string, string>
     */
    public function modulosAutorizados(?Utilizador $utilizador): array
    {
        $autorizados = [];
        foreach (self::MODULOS_NOMES as $slug => $nome) {
            if ($this->podeVerModulo($utilizador, $slug)) {
                $autorizados[$slug] = $nome;
            }
        }

        return $autorizados;
    }

    /**
     * Motor principal: retorna resumo completo de alertas e notificações com RBAC rigoroso.
     *
     * @param  array{modulo?: ?string, tipo?: ?string, apenas_nao_lidas?: ?bool, origem?: ?string}  $filtros
     */
    public function resumo(int $empresaId, ?Utilizador $utilizador = null, int $limite = 50, array $filtros = []): array
    {
        $modulosPermitidos = $this->modulosAutorizados($utilizador);
        $moduloFiltro = ! empty($filtros['modulo']) && $filtros['modulo'] !== 'todos' ? strtolower(trim($filtros['modulo'])) : null;
        $tipoFiltro = ! empty($filtros['tipo']) && $filtros['tipo'] !== 'todos' ? strtoupper(trim($filtros['tipo'])) : null;
        $apenasNaoLidas = ! empty($filtros['apenas_nao_lidas']) && filter_var($filtros['apenas_nao_lidas'], FILTER_VALIDATE_BOOLEAN);
        $origemFiltro = ! empty($filtros['origem']) && $filtros['origem'] !== 'todos' ? strtoupper(trim($filtros['origem'])) : null;

        $itens = [];

        // 1. Alertas Operacionais Dinâmicos (Processos Pendentes calculados pelo ServicoInicio)
        if (! $origemFiltro || $origemFiltro === 'ALERTA_OPERACIONAL') {
            $alertas = $this->obterAlertasOperacionais($empresaId, $utilizador);
            foreach ($alertas as $alerta) {
                if ($moduloFiltro && $alerta['modulo'] !== $moduloFiltro) {
                    continue;
                }
                if ($tipoFiltro && $alerta['tipo'] !== $tipoFiltro) {
                    continue;
                }
                $itens[] = $alerta;
            }
        }

        // 2. Notificações Persistentes da Empresa (notificacoes_sistema)
        $qNotif = NotificacaoSistema::query()->where('empresa_id', $empresaId);
        if ($apenasNaoLidas) {
            $qNotif->where('lida', false);
        }
        $notificacoesBD = $qNotif->orderByDesc('id')->limit(80)->get();

        foreach ($notificacoesBD as $n) {
            $mod = strtolower((string) $n->categoria) ?: 'geral';

            // RBAC: só exibe se o utilizador puder ver este módulo
            if (! isset($modulosPermitidos[$mod]) && ! $this->podeVerModulo($utilizador, $mod)) {
                continue;
            }

            // Se for destinada a utilizador específico
            if ($n->utilizador_id !== null && $utilizador && $n->utilizador_id !== $utilizador->getKey() && ! $utilizador->eSuperAdministrador()) {
                continue;
            }

            if ($moduloFiltro && $mod !== $moduloFiltro) {
                continue;
            }
            if ($tipoFiltro && $n->tipo !== $tipoFiltro) {
                continue;
            }
            if ($origemFiltro && $origemFiltro !== 'EVENTO_EMPRESA') {
                continue;
            }

            $itens[] = [
                'id' => (int) $n->id,
                'origem' => 'EVENTO_EMPRESA',
                'tipo' => $n->tipo ?: 'INFO',
                'categoria' => $mod,
                'modulo' => $mod,
                'modulo_nome' => self::MODULOS_NOMES[$mod] ?? ucfirst($mod),
                'titulo' => $n->titulo,
                'mensagem' => $n->mensagem,
                'link' => $n->link,
                'autor_nome' => $n->autor_nome ?: 'Utilizador',
                'lida' => (bool) $n->lida,
                'lida_em' => $n->lida_em?->toIso8601String(),
                'criado_em' => $n->criado_em?->toIso8601String() ?: now()->toIso8601String(),
            ];
        }

        // 3. Eventos Operacionais de Auditoria Recentes (ações executadas por colegas na mesma empresa)
        if (count($itens) < 30 && (! $origemFiltro || $origemFiltro === 'EVENTO_EMPRESA') && ! $apenasNaoLidas) {
            $eventosAudit = LogAuditoria::query()
                ->where('empresa_id', $empresaId)
                ->orderByDesc('id')
                ->limit(25)
                ->get();

            foreach ($eventosAudit as $log) {
                $mod = $this->moduloDeAuditoria((string) $log->modulo, (string) $log->tabela);
                if (! isset($modulosPermitidos[$mod]) && ! $this->podeVerModulo($utilizador, $mod)) {
                    continue;
                }
                if ($moduloFiltro && $mod !== $moduloFiltro) {
                    continue;
                }

                $itens[] = [
                    'id' => 9000000 + (int) $log->id,
                    'origem' => 'EVENTO_EMPRESA',
                    'tipo' => 'INFO',
                    'categoria' => $mod,
                    'modulo' => $mod,
                    'modulo_nome' => self::MODULOS_NOMES[$mod] ?? ucfirst($mod),
                    'titulo' => ucfirst((string) $log->acao).' em '.($log->tabela ?: $log->modulo),
                    'mensagem' => ($log->nome_utilizador ? $log->nome_utilizador.': ' : '').($log->detalhes ?: 'Registo atualizado com sucesso.'),
                    'link' => null,
                    'autor_nome' => $log->nome_utilizador ?: 'Sistema',
                    'lida' => true,
                    'lida_em' => null,
                    'criado_em' => $log->ocorrido_em?->toIso8601String() ?: now()->toIso8601String(),
                ];
            }
        }

        // Ordenar: Notificações não lidas da empresa no topo, depois alertas urgentes, depois por data
        usort($itens, function ($a, $b) {
            $ePersistenteA = ($a['origem'] ?? '') === 'EVENTO_EMPRESA';
            $ePersistenteB = ($b['origem'] ?? '') === 'EVENTO_EMPRESA';

            $lidaA = ! empty($a['lida']);
            $lidaB = ! empty($b['lida']);

            // Não lidas primeiro
            if ($lidaA !== $lidaB) {
                return $lidaA ? 1 : -1;
            }

            // Entre as não lidas, eventos da empresa vêm antes
            if (! $lidaA && ! $lidaB && $ePersistenteA !== $ePersistenteB) {
                return $ePersistenteA ? -1 : 1;
            }

            $pesoTipo = ['URGENTE' => 4, 'AVISO' => 3, 'INFO' => 2, 'SUCESSO' => 1];
            $pesoA = $pesoTipo[$a['tipo'] ?? 'INFO'] ?? 0;
            $pesoB = $pesoTipo[$b['tipo'] ?? 'INFO'] ?? 0;

            if ($pesoA !== $pesoB) {
                return $pesoB <=> $pesoA;
            }

            return strcmp((string) ($b['criado_em'] ?? ''), (string) ($a['criado_em'] ?? ''));
        });

        $naoLidasBD = (int) NotificacaoSistema::query()->where('empresa_id', $empresaId)->where('lida', false)->count();
        $alertasNaoLidos = count(array_filter($itens, fn ($i) => ($i['origem'] ?? '') === 'ALERTA_OPERACIONAL' && empty($i['lida'])));
        $totalNaoLidas = $naoLidasBD + $alertasNaoLidos;
        $urgentes = count(array_filter($itens, fn ($i) => ($i['tipo'] ?? '') === 'URGENTE' && empty($i['lida'])));
        $avisos = count(array_filter($itens, fn ($i) => ($i['tipo'] ?? '') === 'AVISO' && empty($i['lida'])));

        // Totalizadores por módulo para os filtros no frontend
        $porModulo = [];
        foreach ($itens as $i) {
            $m = $i['modulo'] ?? 'geral';
            $porModulo[$m] = ($porModulo[$m] ?? 0) + 1;
        }

        $itensLimitados = array_slice($itens, 0, $limite);

        return [
            'total' => count($itens),
            'total_nao_lidas' => $totalNaoLidas,
            'nao_lidas' => $totalNaoLidas, // compatibilidade com frontend e testes
            'nao_lidas_bd' => $naoLidasBD,
            'alertas_pendentes' => $alertasNaoLidos,
            'urgentes' => $urgentes,
            'avisos' => $avisos,
            'por_modulo' => $porModulo,
            'modulos_disponiveis' => $modulosPermitidos,
            'notificacoes' => $itensLimitados,
            'itens' => $itensLimitados, // compatibilidade com NotificacoesSinoHeader
        ];
    }

    /**
     * Gera lista de alertas operacionais pendentes (espelhados dos processos pendentes de Início).
     *
     * @return list<array<string, mixed>>
     */
    private function obterAlertasOperacionais(int $empresaId, ?Utilizador $utilizador): array
    {
        $alertas = [];

        try {
            $pendentes = $this->servicoInicio->pendentes();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $mapaModulos = [
            'teso_docs' => ['teso', 'Documentos de tesouraria por integrar na contabilidade.', '/m/teso/teso_contab_integracao'],
            'teso_conciliacao' => ['teso', 'Linhas de extracto bancário aguardando conciliação.', '/m/teso/teso_gestao_conciliacao'],
            'vendas_por_liquidar' => ['vendas', 'Facturas FT/FR emitidas pendentes de liquidação.', '/m/vendas/vendas_faturacao'],
            'vendas_agt_recusados' => ['vendas', 'Documentos fiscais recusados pela AGT a aguardar correcção.', '/m/vendas/vendas_faturacao'],
            'compras_pedidos' => ['compras', 'Pedidos de compra a aguardar aprovação.', '/m/compras/compras'],
            'stock_critico' => ['compras', 'Artigos de catálogo com stock abaixo do nível de segurança.', '/m/compras/armazem_stock'],
            'stock_reposicao' => ['compras', 'Sugestões automáticas de reposição de armazém.', '/m/compras/armazem_stock'],
            'compras_encomendas' => ['compras', 'Encomendas a fornecedores com recepção pendente.', '/m/compras/compras'],
            'rh_periodos_abertos' => ['rh', 'Períodos de processamento salarial abertos para fecho.', '/m/rh/calcular'],
            'ativos_inventariar' => ['activos', 'Aquisições de activos fixos a aguardar inventariação.', '/m/activos/activos'],
            'ativos_sem_amortizacao' => ['activos', 'Activos fixos activos sem cálculo de amortização.', '/m/activos/activos_amortizacoes'],
            'ativos_manutencoes' => ['activos', 'Manutenções preventivas de activos planeadas.', '/m/activos/activos_manutencao'],
            'orc_por_aprovar' => ['orcamento', 'Orçamentos anuais submetidos a aguardar aprovação.', '/m/orcamento/orc_orcamentos'],
            'orc_excessos' => ['orcamento', 'Alertas de extrapolação e desvio orçamental por decidir.', '/m/orcamento/orc_alertas'],
            'acrescimos_regularizar' => ['contab', 'Propostas mensais de acréscimos/diferimentos a regularizar.', '/m/contab/ad_propostas'],
            'crm_atraso' => ['crm', 'Actividades e contactos comerciais com data ultrapassada.', '/m/crm/crm_agenda'],
            'estrutura_vagas' => ['rh', 'Vagas de quadro de pessoal por preencher na estrutura orgânica.', '/m/rh/est_mapa'],
            'cambios_bai' => ['sistema', 'Câmbios diários obtidos do BAI a aguardar validação.', '/m/config/config_moedas'],
            'portal_pedidos' => ['rh', 'Pedidos de férias/ausências de colaboradores a aguardar decisão.', '/m/rh/rh_portal'],
            'avaliacao_360' => ['rh', 'Acções pendentes de avaliação de desempenho 360º.', '/m/rh/rh_portal'],
        ];

        foreach ($pendentes as $p) {
            $id = (string) $p['id'];
            $q = (int) $p['quantidade'];
            if ($q <= 0) {
                continue;
            }

            [$modulo, $descricao, $link] = $mapaModulos[$id] ?? ['geral', 'Processos pendentes no sistema.', null];

            // Define criticidade
            $tipo = 'AVISO';
            if (in_array($id, ['vendas_agt_recusados', 'stock_critico', 'orc_excessos', 'crm_atraso'], true) || $q >= 30) {
                $tipo = 'URGENTE';
            }

            $alertas[] = [
                'id' => 'alerta_'.crc32($id),
                'origem' => 'ALERTA_OPERACIONAL',
                'tipo' => $tipo,
                'categoria' => $modulo,
                'modulo' => $modulo,
                'modulo_nome' => self::MODULOS_NOMES[$modulo] ?? ucfirst($modulo),
                'titulo' => "{$q} {$p['rotulo']}",
                'mensagem' => $descricao,
                'quantidade' => $q,
                'link' => $link,
                'autor_nome' => 'Motor de Processos',
                'lida' => false,
                'lida_em' => null,
                'criado_em' => now()->toIso8601String(),
            ];
        }

        return $alertas;
    }

    /**
     * Mapeia o módulo do log de auditoria para o slug de módulo correspondente.
     */
    private function moduloDeAuditoria(string $modulo, string $tabela): string
    {
        $m = mb_strtolower($modulo);
        $t = mb_strtolower($tabela);

        if (str_contains($m, 'vendas') || str_contains($t, 'vendas') || str_contains($t, 'factura')) {
            return 'vendas';
        }
        if (str_contains($m, 'compras') || str_contains($m, 'armazem') || str_contains($t, 'compras') || str_contains($t, 'produtos')) {
            return 'compras';
        }
        if (str_contains($m, 'tesouraria') || str_contains($t, 'tesouraria') || str_contains($t, 'banco') || str_contains($t, 'caixa')) {
            return 'teso';
        }
        if (str_contains($m, 'contabilidade') || str_contains($t, 'lancamentos') || str_contains($t, 'diario')) {
            return 'contab';
        }
        if (str_contains($m, 'rh') || str_contains($m, 'recursos') || str_contains($t, 'colaborador') || str_contains($t, 'salarial')) {
            return 'rh';
        }
        if (str_contains($m, 'activo') || str_contains($t, 'activo')) {
            return 'activos';
        }
        if (str_contains($m, 'crm') || str_contains($t, 'crm') || str_contains($t, 'oportunidade')) {
            return 'crm';
        }
        if (str_contains($m, 'orcamento') || str_contains($t, 'orcamento')) {
            return 'orcamento';
        }

        return 'sistema';
    }

    /** Marca uma notificação persistente como lida. */
    public function marcarLida(int $notificacaoId, int $empresaId): bool
    {
        return (bool) NotificacaoSistema::query()
            ->where('empresa_id', $empresaId)
            ->where('id', $notificacaoId)
            ->update([
                'lida' => true,
                'lida_em' => now(),
            ]);
    }

    /** Marca todas as notificações persistentes da empresa como lidas. */
    public function marcarTodasLidas(int $empresaId): int
    {
        return NotificacaoSistema::query()
            ->where('empresa_id', $empresaId)
            ->where('lida', false)
            ->update([
                'lida' => true,
                'lida_em' => now(),
            ]);
    }
}

