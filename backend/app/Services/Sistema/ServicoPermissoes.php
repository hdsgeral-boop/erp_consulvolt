<?php

namespace App\Services\Sistema;

use App\Models\Utilizador;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Avaliação de permissões com a semântica do legado (js/permissoes.js:591-643), sobre o catálogo
 * extraído do próprio legado (CatalogoPermissoes):
 *
 *   total()        = papel SUPER_ADMINISTRADOR  ou  permissoes.all === true
 *   tem(k)         = total() ou permissoes[norm(k)] === true
 *                    ou (k ∈ {rh_portal_view, rh_portal_usar} e utilizador ligado a um colaborador na empresa activa)
 *   pode(tarefa)   = tem(tarefa) ou alguma tarefa agrupada pela chave antiga (LEGADO)
 *   podeVer(ecrã)  = ecrã visível: tem("<ecrã>_view") ou alguma tarefa do ecrã ou algum ecrã-filho visível
 *                    (vistas do legado sem ecrã: LEGADO_VISTA)
 *
 * Holding (empresa de consolidação, empresas.e_consolidacao): só os módulos Contabilidade, Início e Dashboard ficam acessíveis
 * (MODULOS_HOLDING/ECRAS_HOLDING). A regra aplica-se ao menu, às permissões efectivas e ao Gate (autoriza), para todos os
 * utilizadores — incluindo o superadministrador —, pelo que os outros módulos ficam inacessíveis também no servidor
 * (legado: consolBloquearNavegacao/consolAplicarMenu, js/consolidacao.js:289-337, que só escondia o menu no browser).
 *
 * Ecrãs «plataforma_*» (administração do SaaS: clientes, suporte, auditoria, definições globais…) só ficam visíveis a
 * quem passaria também o ExigirPlataforma — super-administrador do tenant da própria plataforma. Um perfil de acesso
 * total («all») é por empresa/cliente (ex.: o administrador de uma empresa recém-registada) e não deve, só por isso,
 * ver ou poder listar-se como tendo acesso à gestão de todos os clientes da plataforma.
 *
 * Ecrãs «suporte_*» (backoffice de suporte, ADR-077: especialização de agentes) seguem a mesma lógica — só ficam
 * visíveis a quem passaria também o ExigirSuporte (super-administrador ou agente de suporte, no tenant da
 * plataforma). Sem isto, o perfil "Acesso Total" (atribuído por omissão ao administrador de uma empresa cliente)
 * fazia aparecer "Backoffice de Suporte" no menu de qualquer empresa — essas chaves existem só para especializar
 * agentes, nunca para um cliente navegar.
 *
 * ECRAS_TODOS_OS_UTILIZADORES: o inverso — ecrãs cuja consulta fica sempre visível a qualquer utilizador autenticado
 * de uma empresa, mesmo sem nenhuma permissão no perfil (hoje só "Licença e plano", cujo estado e pedido de suporte
 * o LicencaController já expõe a todos no backend).
 *
 * Plano do cliente (ModulosPlano): os módulos de negócio que não estejam incluídos no plano em vigor do tenant
 * (planos_licenca_versoes.modulos da versão publicada) ficam bloqueados — no menu (aqui) e no Gate (autoriza),
 * para todos os utilizadores desse tenant, incluindo um perfil de acesso total. Tal como a holding, é bloqueio a
 * mais, nunca a menos: um perfil sem a tarefa continua sem ela mesmo que o módulo esteja incluído no plano.
 *
 * Ecrãs reservados a Super Administradores: nenhuma empresa tem Super Administradores, são sempre do sistema todo.
 * Logs de Navegação nunca fica disponível a uma empresa, mesmo a um perfil de acesso total (menu, permissões
 * efectivas e Gate — tal como a holding e o plano do cliente). Manutenção de dados fica visível no menu a quem tiver
 * a permissão (partilhada com outros ecrãs de empresa só de leitura: Validações de dados, sincronizar notas), mas o
 * fluxo destrutivo de pedido/aprovação em si só é oferecido a Super Administradores dentro do próprio ecrã
 * (Manutencao.tsx) e bloqueia-se no controller (ManutencaoDadosController::exigirSuperAdministrador).
 *
 * Todos os perfis estão no formato v2: os antigos foram convertidos na migração com o converterAntigo do
 * próprio legado (ferramentas/levantamento/converter_perfis_antigos.mjs).
 */
final class ServicoPermissoes
{
    private const PORTAL_AUTOMATICO = ['rh_portal_view', 'rh_portal_usar'];

    /** Prefixo dos ecrãs de administração do SaaS (catálogo, módulo `config`) — ver ExigirPlataforma. */
    private const PREFIXO_PLATAFORMA = 'plataforma_';

    /** Prefixo dos ecrãs do backoffice de suporte (catálogo, módulo `suporte`, ADR-077) — ver ExigirSuporte. */
    private const PREFIXO_SUPORTE = 'suporte_';

    /**
     * Ecrãs cuja consulta ("<ecrã>_view") fica sempre visível a qualquer utilizador autenticado de uma empresa,
     * independentemente do perfil — hoje só `config_licenca` (estado da própria licença e pedido de suporte ao
     * cliente, tal como o LicencaController já expõe `estado()`/`planos()` a qualquer utilizador no backend; só a
     * gestão — pedir renovação, pagamentos — continua a exigir `config_licenca_gerir`).
     */
    private const ECRAS_TODOS_OS_UTILIZADORES = ['config_licenca'];

    /**
     * Ecrãs reservados a Super Administradores em qualquer verificação (tem/autoriza/efectivas/menu) — nunca de uma
     * empresa. Logs de Navegação não tem outro uso partilhado (ao contrário de config_manutencao_view, que
     * Validações de dados também usa, por isso fica de fora desta lista — ver docblock da classe).
     */
    private const ECRAS_SO_SUPER_ADMINISTRADOR = ['config_logs'];

    /** Módulos do catálogo disponíveis numa holding (inteiros). */
    public const MODULOS_HOLDING = ['contab'];

    /** Ecrãs de outros módulos disponíveis numa holding (o Início, vista `welcome`, não é ecrã do catálogo e fica sempre). */
    public const ECRAS_HOLDING = ['dashboard'];

    /** @var array<int, bool> memória por pedido: empresa => é holding */
    private array $holdings = [];

    /** @var array<int, ?list<string>> memória por pedido: tenant_id => módulos do plano em vigor (null = sem restrição) */
    private array $modulosPorTenant = [];

    /** @var array<string, list<array<string, mixed>>> memória: chave => ecrãs a que pertence */
    private static array $ecrasPorChave = [];

    /** @var array<string, bool> memória por pedido: "utilizador|empresa" => ligado a colaborador */
    private array $ligacoes = [];

    public function total(Utilizador $utilizador): bool
    {
        return $utilizador->eSuperAdministrador() || ($this->permissoes($utilizador)['all'] ?? null) === true;
    }

    /**
     * Administrador para efeitos de aprovação (papelAdmin, js/manutencao.js:21): papel SUPER_ADMINISTRADOR ou
     * ADMINISTRADOR, ou perfil de acesso total (no legado, o perfil n.º 1 "Super Administrador").
     */
    public function administrador(Utilizador $utilizador): bool
    {
        return in_array($utilizador->papel, [Utilizador::PAPEL_SUPER_ADMINISTRADOR, Utilizador::PAPEL_ADMINISTRADOR], true) || $this->total($utilizador);
    }

    public function tem(Utilizador $utilizador, string $chave, ?int $empresaId = null): bool
    {
        $k = self::norm($chave);
        if ($this->ePlataforma($k) && ! $this->elegivelPlataforma($utilizador)) {
            return false;
        }
        if ($this->eSuporte($k) && ! $this->elegivelSuporte($utilizador)) {
            return false;
        }
        if (in_array($k, self::chavesTodosOsUtilizadores(), true)) {
            return true;
        }
        if ($this->total($utilizador)) {
            return true;
        }
        if ($this->administrador($utilizador) && in_array($k, ['notificacoes_centro_view', 'notificacoes_centro_gerir'], true)) {
            return true;
        }
        if (($this->permissoes($utilizador)[$k] ?? null) === true) {
            return true;
        }

        return in_array($k, self::PORTAL_AUTOMATICO, true) && $empresaId !== null && $this->ligadoAColaborador($utilizador, $empresaId);
    }

    /** Mesma condição do ExigirPlataforma: só o super-administrador do tenant que opera a plataforma. */
    public function elegivelPlataforma(Utilizador $utilizador): bool
    {
        return $utilizador->eSuperAdministrador() && (int) $utilizador->tenant_id === (int) config('erp.dominio.plataforma_tenant_id', 1);
    }

    /** A chave (consulta de ecrã ou tarefa) pertence a um ecrã «plataforma_*» (administração do SaaS)? */
    private function ePlataforma(string $k): bool
    {
        $ecraId = str_ends_with($k, '_view') ? substr($k, 0, -5) : (CatalogoPermissoes::tarefas()[$k]['ecra'] ?? null);

        return $ecraId !== null && str_starts_with($ecraId, self::PREFIXO_PLATAFORMA);
    }

    /**
     * Mesma condição do ExigirSuporte: só o backoffice de suporte da plataforma (super-administrador ou agente de
     * suporte, no tenant da própria plataforma) — nunca uma empresa cliente, mesmo com um perfil de acesso total
     * («all»). Sem esta blindagem, o módulo `suporte` (ADR-077, usado só para especializar agentes) ficava visível
     * no menu de qualquer empresa cujo administrador tivesse o perfil "Acesso Total" (o criado por omissão).
     */
    public function elegivelSuporte(Utilizador $utilizador): bool
    {
        return (int) $utilizador->tenant_id === (int) config('erp.dominio.plataforma_tenant_id', 1)
            && ($utilizador->eSuperAdministrador() || $utilizador->eAgenteSuporte());
    }

    /** A chave (consulta de ecrã ou tarefa) pertence a um ecrã «suporte_*» (backoffice de suporte)? */
    private function eSuporte(string $k): bool
    {
        $ecraId = str_ends_with($k, '_view') ? substr($k, 0, -5) : (CatalogoPermissoes::tarefas()[$k]['ecra'] ?? null);

        return $ecraId !== null && str_starts_with($ecraId, self::PREFIXO_SUPORTE);
    }

    /** @return list<string> consultas "<ecrã>_view" de ECRAS_TODOS_OS_UTILIZADORES. */
    private static function chavesTodosOsUtilizadores(): array
    {
        return array_map(fn (string $id) => "{$id}_view", self::ECRAS_TODOS_OS_UTILIZADORES);
    }

    /** Tarefa (acção de negócio) — equivalente a window.can / permTem do legado. */
    public function pode(Utilizador $utilizador, string $tarefa, ?int $empresaId = null): bool
    {
        if ($this->tem($utilizador, $tarefa, $empresaId)) {
            return true;
        }
        foreach (CatalogoPermissoes::LEGADO[self::norm($tarefa)] ?? [] as $agrupada) {
            if ($this->tem($utilizador, $agrupada, $empresaId)) {
                return true;
            }
        }

        return false;
    }

    /** Consulta de um ecrã/vista — equivalente a window.hasView (formato v2) do legado. */
    public function podeVer(Utilizador $utilizador, string $vista, ?int $empresaId = null): bool
    {
        if ($vista === 'welcome' || $this->total($utilizador)) {
            return true;
        }
        $k = self::norm($vista);
        $ecra = CatalogoPermissoes::ecraDaVista($k);
        if ($ecra !== null) {
            return $this->ecraVisivel($utilizador, $ecra, $empresaId);
        }
        if (isset(CatalogoPermissoes::LEGADO_VISTA[$k])) {
            foreach (CatalogoPermissoes::LEGADO_VISTA[$k] as $v) {
                if ($this->podeVer($utilizador, $v, $empresaId)) {
                    return true;
                }
            }

            return false;
        }

        return $this->tem($utilizador, $k, $empresaId) || $this->tem($utilizador, "{$k}_view", $empresaId);
    }

    /**
     * Ability do Gate: "<ecrã>_view" é uma consulta de ecrã; qualquer outra chave é uma tarefa.
     */
    public function autoriza(Utilizador $utilizador, string $habilidade, ?int $empresaId = null): bool
    {
        $k = self::norm($habilidade);
        if ($this->eHolding($empresaId) && $this->bloqueadaNaHolding($k)) {
            return false;
        }
        if ($this->bloqueadaPeloPlano($utilizador, $k)) {
            return false;
        }
        if ($this->bloqueadaForaDoSuperAdministrador($utilizador, $k)) {
            return false;
        }
        if (str_ends_with($k, '_view') && CatalogoPermissoes::ecraDaVista(substr($k, 0, -5)) !== null) {
            return $this->podeVer($utilizador, substr($k, 0, -5), $empresaId);
        }

        return $this->pode($utilizador, $k, $empresaId);
    }

    public function formatoV2(Utilizador $utilizador): bool
    {
        return ($this->permissoes($utilizador)['_v2'] ?? null) === true;
    }

    /**
     * Permissões efectivas para o frontend (chaves activas; `*` = acesso total).
     *
     * @return list<string>
     */
    public function efectivas(Utilizador $utilizador, ?int $empresaId = null): array
    {
        if ($this->total($utilizador)) {
            return ['*'];
        }
        $chaves = array_keys(array_filter($this->permissoes($utilizador), fn ($v, $k) => $v === true && $k !== '_v2', ARRAY_FILTER_USE_BOTH));
        if ($empresaId !== null && $this->ligadoAColaborador($utilizador, $empresaId)) {
            $chaves = array_merge($chaves, self::PORTAL_AUTOMATICO);
        }
        $chaves = array_merge($chaves, self::chavesTodosOsUtilizadores());
        $chaves = array_values(array_unique(array_map(fn ($k) => self::norm($k), $chaves)));
        if ($this->eHolding($empresaId)) {
            $chaves = array_values(array_filter($chaves, fn ($k) => ! $this->bloqueadaNaHolding($k)));
        }
        $chaves = array_values(array_filter($chaves, fn ($k) => ! $this->bloqueadaPeloPlano($utilizador, $k)));
        $chaves = array_values(array_filter($chaves, fn ($k) => ! $this->bloqueadaForaDoSuperAdministrador($utilizador, $k)));
        sort($chaves);

        return $chaves;
    }

    /**
     * Menu da aplicação (Fase 5): módulos do catálogo com os ecrãs que o utilizador pode ver na empresa activa
     * (consulta, uma tarefa do ecrã ou um ecrã-filho visível — mesma regra de podeVer). Módulos sem ecrãs visíveis não aparecem.
     *
     * @return list<array{id: string, nome: string, ecras: list<array{id: string, nome: string, vistas: list<string>, pai: ?string, tarefas: list<array{chave: string, rotulo: string}>}>}>
     */
    public function menu(Utilizador $utilizador, ?int $empresaId = null): array
    {
        $menu = [];
        $holding = $this->eHolding($empresaId);
        $modulosPermitidos = $this->modulosDoTenant($utilizador->tenant_id);
        foreach (CatalogoPermissoes::completo()['modulos'] as $m) {
            if ($modulosPermitidos !== null && ! in_array($m['id'], ModulosPlano::SEMPRE_DISPONIVEIS, true) && ! in_array($m['id'], $modulosPermitidos, true)) {
                continue; // módulo inteiro fora do plano do cliente (ModulosPlano)
            }
            $ecras = [];
            foreach ($m['ecras'] as $e) {
                if ($holding && ! self::ecraPermitidoNaHolding($e + ['modulo' => $m['id']])) {
                    continue;
                }
                if (! $utilizador->eSuperAdministrador() && in_array($e['id'], self::ECRAS_SO_SUPER_ADMINISTRADOR, true)) {
                    continue;
                }
                if ($this->ecraVisivel($utilizador, $e, $empresaId)) {
                    // funções (tarefas do catálogo) que o utilizador pode fazer neste ecrã — índice da pesquisa «Procurar»
                    $tarefas = array_values(array_map(fn ($t) => ['chave' => $t['chave'], 'rotulo' => $t['rotulo']],
                        array_filter($e['tarefas'], fn ($t) => $this->tem($utilizador, $t['chave'], $empresaId))));
                    $ecras[] = ['id' => $e['id'], 'nome' => $e['nome'], 'vistas' => $e['vistas'] ?? [], 'pai' => $e['pai'] ?? null, 'tarefas' => $tarefas];
                }
            }
            if ($ecras) {
                $menu[] = ['id' => $m['id'], 'nome' => $m['nome'], 'ecras' => $ecras];
            }
        }

        return $menu;
    }

    /** A empresa é uma holding (empresa de consolidação)? Memória por pedido. */
    public function eHolding(?int $empresaId): bool
    {
        if ($empresaId === null) {
            return false;
        }

        return $this->holdings[$empresaId] ??= (bool) DB::table('empresas')->where('id', $empresaId)->value('e_consolidacao');
    }

    /** @param  array<string, mixed>  $ecra  ecrã do catálogo (com `modulo`) */
    public static function ecraPermitidoNaHolding(array $ecra): bool
    {
        return in_array($ecra['modulo'] ?? null, self::MODULOS_HOLDING, true) || in_array($ecra['id'] ?? null, self::ECRAS_HOLDING, true);
    }

    /**
     * A chave (consulta "<ecrã>_view", tarefa ou chave antiga agrupada) só pertence a ecrãs que a holding não usa.
     * Chaves fora do catálogo (ex.: `welcome`) não são bloqueadas aqui — continuam sujeitas às permissões normais.
     */
    public function bloqueadaNaHolding(string $chave): bool
    {
        $ecras = self::ecrasDaChave(self::norm($chave));

        return $ecras !== [] && array_filter($ecras, fn ($e) => self::ecraPermitidoNaHolding($e)) === [];
    }

    /**
     * A chave só pertence a ecrãs de módulos fora do plano do tenant do utilizador (ModulosPlano) — mesma forma de
     * bloquear que bloqueadaNaHolding, mas por tenant em vez de por empresa. Aplica-se mesmo a um perfil de acesso
     * total («all»): o plano é uma regra de negócio/facturação (ServicoLimitesPlano), não uma permissão de perfil.
     */
    public function bloqueadaPeloPlano(Utilizador $utilizador, string $chave): bool
    {
        $permitidos = $this->modulosDoTenant($utilizador->tenant_id);
        if ($permitidos === null) {
            return false;
        }
        $ecras = self::ecrasDaChave(self::norm($chave));
        $moduloOk = fn (string $m) => in_array($m, ModulosPlano::SEMPRE_DISPONIVEIS, true) || in_array($m, $permitidos, true);

        return $ecras !== [] && array_filter($ecras, fn ($e) => $moduloOk($e['modulo'] ?? '')) === [];
    }

    /**
     * A chave só pertence a ecrãs reservados a Super Administradores (ECRAS_SO_SUPER_ADMINISTRADOR, ex.: Logs de
     * Navegação) — mesma forma de bloquear que bloqueadaNaHolding/bloqueadaPeloPlano, mas por papel: nenhuma
     * empresa tem Super Administradores, por isso um administrador de empresa com perfil de acesso total («all»)
     * continua sem estes ecrãs.
     */
    public function bloqueadaForaDoSuperAdministrador(Utilizador $utilizador, string $chave): bool
    {
        if ($utilizador->eSuperAdministrador()) {
            return false;
        }
        $ecras = self::ecrasDaChave(self::norm($chave));

        return $ecras !== [] && array_filter($ecras, fn ($e) => ! in_array($e['id'] ?? null, self::ECRAS_SO_SUPER_ADMINISTRADOR, true)) === [];
    }

    /**
     * @return ?list<string> módulos do plano em vigor do tenant; null = sem restrição. Memória por pedido: a cache
     * Redis de ModulosPlano::doTenantId() já evita qualquer consulta a `tenants`/`planos_licenca*` (regra 1 do
     * CACHE.md — ParidadeLancamentosTest, que conta consultas por pedido, apanhou a 1.ª versão sem isto).
     */
    private function modulosDoTenant(?int $tenantId): ?array
    {
        if ($tenantId === null) {
            return null;
        }
        if (! array_key_exists($tenantId, $this->modulosPorTenant)) {
            $this->modulosPorTenant[$tenantId] = ModulosPlano::doTenantId($tenantId);
        }

        return $this->modulosPorTenant[$tenantId];
    }

    /** @return list<array<string, mixed>> */
    private static function ecrasDaChave(string $k, int $profundidade = 0): array
    {
        if (isset(self::$ecrasPorChave[$k])) {
            return self::$ecrasPorChave[$k];
        }
        $ecras = [];
        $tarefa = CatalogoPermissoes::tarefas()[$k] ?? null;
        $base = str_ends_with($k, '_view') ? substr($k, 0, -5) : $k;
        if ($tarefa !== null) {
            $ecras[] = CatalogoPermissoes::ecras()[$tarefa['ecra']];
        } elseif (($e = CatalogoPermissoes::ecraDaVista($base)) !== null) {
            $ecras[] = $e;
        } elseif ($profundidade < 3) {
            foreach (array_merge(CatalogoPermissoes::LEGADO[$k] ?? [], CatalogoPermissoes::LEGADO_VISTA[$base] ?? []) as $x) {
                $ecras = array_merge($ecras, self::ecrasDaChave($x, $profundidade + 1));
            }
        }

        return self::$ecrasPorChave[$k] = $ecras;
    }

    public static function norm(string $chave): string
    {
        $decomposta = Normalizer::normalize($chave, Normalizer::FORM_D) ?: $chave;

        return trim(mb_strtolower(preg_replace('/\p{Mn}+/u', '', $decomposta)));
    }

    /** @param  array<string, mixed>  $ecra */
    private function ecraVisivel(Utilizador $utilizador, array $ecra, ?int $empresaId): bool
    {
        if ($this->tem($utilizador, "{$ecra['id']}_view", $empresaId)) {
            return true;
        }
        foreach ($ecra['tarefas'] as $t) {
            if ($this->tem($utilizador, $t['chave'], $empresaId)) {
                return true;
            }
        }
        foreach (CatalogoPermissoes::filhos($ecra['id']) as $filho) {
            if ($this->ecraVisivel($utilizador, $filho, $empresaId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     *
     * ADR-077: `notificacoes_centro_ver` e `notificacoes_view` foram descontinuadas (triplicavam a mesma consulta de
     * "ver notificações" — só `notificacoes_centro_view`, automática, fica). Perfis antigos que ainda tenham a chave
     * velha gravada continuam a funcionar por esta alcunha, até serem voltados a gravar pelo editor.
     */
    private function permissoes(Utilizador $utilizador): array
    {
        $p = $utilizador->perfil?->permissoes ?? [];
        if (($p['notificacoes_centro_ver'] ?? false) === true || ($p['notificacoes_view'] ?? false) === true) {
            $p['notificacoes_centro_view'] ??= true;
        }

        return $p;
    }

    private function ligadoAColaborador(Utilizador $utilizador, int $empresaId): bool
    {
        return $this->ligacoes["{$utilizador->getKey()}|{$empresaId}"] ??= $utilizador->empresas()
            ->where('empresas.id', $empresaId)
            ->whereNotNull('utilizador_empresa.colaborador_id')
            ->exists();
    }
}
