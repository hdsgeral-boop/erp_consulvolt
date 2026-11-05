<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Mail\ClienteAdministradorCriadoMail;
use App\Mail\ContaSuspensaMail;
use App\Models\Empresa;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Services\Sistema\ServicoPerfis;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Clientes SaaS (tenants) geridos pela plataforma: criação com o administrador do cliente e, opcionalmente, a primeira
 * empresa; suspensão e reactivação; contagens e metadados das empresas de todos os clientes (funções SECURITY DEFINER).
 * A licença/cobrança é por empresa (ServicoLicenca), não por cliente. Só o tenant da plataforma chama este serviço.
 */
final class ServicoClientes
{
    public function __construct(
        private readonly ContextoTenant $tenant,
        private readonly ServicoGestaoEmpresas $empresas,
        private readonly ServicoPerfis $perfis,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * Pesquisa `q` (nome ou endereço, sem distinguir maiúsculas), `estado` e `plano` (código; `sem-plano` para os
     * clientes sem plano) são opcionais.
     *
     * @param  array{q?: ?string, estado?: ?string, plano?: ?string}  $filtros
     * @return Collection<int, array{tenant: Tenant, empresas: int, utilizadores: int}>
     */
    public function listar(array $filtros = []): Collection
    {
        $empresas = collect(DB::select('SELECT tenant_id, empresas FROM plataforma_empresas_por_tenant()'))->keyBy('tenant_id');
        // utilizadores tem RLS (tenant_id directo): a função SECURITY DEFINER vê todos os tenants, tal como
        // plataforma_empresas_por_tenant() já fazia para empresas (ver migração saas_rls_utilizadores).
        $utilizadores = collect(DB::select('SELECT tenant_id, total FROM plataforma_utilizadores_por_tenant()'))->pluck('total', 'tenant_id');

        $consulta = Tenant::query()->orderBy('id');
        if (filled($filtros['q'] ?? null)) {
            $termo = '%'.mb_strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $filtros['q']))).'%';
            $consulta->where(fn ($q) => $q->whereRaw('lower(nome) LIKE ?', [$termo])->orWhereRaw('lower(slug) LIKE ?', [$termo]));
        }
        if (filled($filtros['estado'] ?? null)) {
            $consulta->where('estado', $filtros['estado']);
        }
        if (filled($filtros['plano'] ?? null)) {
            $filtros['plano'] === 'sem-plano' ? $consulta->whereNull('plano') : $consulta->where('plano', $filtros['plano']);
        }

        return $consulta->get()->map(fn (Tenant $c) => [
            'tenant' => $c,
            'empresas' => (int) ($empresas[$c->id]->empresas ?? 0),
            'utilizadores' => (int) ($utilizadores[$c->id] ?? 0),
        ]);
    }

    /** Empresas de todos os clientes (metadados). */
    public function empresas(): Collection
    {
        $nomes = Tenant::query()->pluck('nome', 'id');

        return collect(DB::select('SELECT id, tenant_id, nome, nif, estado, criado_em, licenca_origem, licenca_fim FROM plataforma_empresas()'))
            ->map(fn ($e) => [
                'id' => (int) $e->id,
                'tenant_id' => (int) $e->tenant_id,
                'cliente' => $nomes[$e->tenant_id] ?? null,
                'nome' => $e->nome,
                'nif' => $e->nif,
                'estado' => $e->estado,
                'criado_em' => $e->criado_em,
                'licenca_origem' => $e->licenca_origem,
                'licenca_fim' => $e->licenca_fim,
            ]);
    }

    /**
     * Cria o cliente, o seu administrador (administrador do próprio cliente, com acesso a todas as suas empresas) e a
     * empresa inicial, se indicada. Tudo numa transacção, os dados do cliente no seu próprio contexto (RLS).
     *
     * Papel ADMINISTRADOR, nunca SUPER_ADMINISTRADOR: esse está reservado a quem controla a plataforma por completo
     * (ExigirPlataforma — tenant da própria Consulvolt), não ao administrador de um cliente, por mais empresas que
     * ele giriras. Era um erro de RBAC corrigido aqui: além de confundir os dois papéis, um SUPER_ADMINISTRADOR fica
     * isento da expiração da licença (ResolverEmpresaAtiva::licenca) — um cliente criado por aqui nunca ficava
     * sujeito à cobrança. O acesso completo aos módulos de negócio vem do perfil de acesso total
     * (ServicoPermissoes::total(), tal como RegistoEmpresaController), ainda capado pelo plano do cliente
     * (ServicoPermissoes::bloqueadaPeloPlano/ModulosPlano).
     *
     * A palavra-passe do administrador nunca é escolhida por quem cria o cliente: é sempre gerada aleatoriamente e
     * enviada por email (se houver endereço), com troca obrigatória no primeiro acesso — o mesmo padrão já usado
     * para administradores da plataforma, agentes de suporte e utilizadores de uma empresa.
     *
     * @param  array{nome: string, slug: string, administrador: array<string, mixed>, empresa?: array<string, mixed>|null}  $d
     * @return array{cliente: Tenant, administrador: Utilizador, empresa: ?Empresa, palavra_passe: string}
     */
    public function criar(array $d): array
    {
        $perfilTotal = $this->perfis->perfilAcessoTotal();
        $palavraPasse = Str::password(14);

        $resultado = DB::transaction(function () use ($d, $perfilTotal, $palavraPasse) {
            $cliente = Tenant::create(['nome' => $d['nome'], 'slug' => $d['slug'], 'estado' => Tenant::ESTADO_ATIVO]);

            return $this->tenant->executarComo((int) $cliente->id, function () use ($d, $cliente, $perfilTotal, $palavraPasse) {
                $administrador = new Utilizador([
                    'nome_utilizador' => $d['administrador']['nome_utilizador'],
                    'nome_completo' => $d['administrador']['nome_completo'] ?? null,
                    'email' => $d['administrador']['email'] ?? null,
                    'palavra_passe' => $palavraPasse,
                    'papel' => Utilizador::PAPEL_ADMINISTRADOR,
                    'perfil_utilizador_id' => $perfilTotal->id,
                    'acesso_todas_empresas' => true,
                    'ativo' => true,
                    'deve_alterar_password' => true,
                ]);
                $administrador->save();

                $empresa = empty($d['empresa']) ? null : $this->empresas->criar($d['empresa'], $administrador);

                return ['cliente' => $cliente, 'administrador' => $administrador, 'empresa' => $empresa];
            });
        });

        if ($resultado['administrador']->email) {
            Mail::to($resultado['administrador']->email)->send(new ClienteAdministradorCriadoMail(
                $resultado['administrador']->nome_completo ?: $resultado['administrador']->nome_utilizador,
                $resultado['administrador']->nome_utilizador, $palavraPasse, $resultado['cliente']->nome,
            ));
        }

        return $resultado + ['palavra_passe' => $palavraPasse];
    }

    /**
     * Utilizadores do cliente. `utilizadores` tem RLS por tenant_id: só se lêem no contexto do próprio cliente
     * (o where explícito é a 2.ª camada).
     *
     * @return list<array<string, mixed>>
     */
    public function utilizadores(Tenant $cliente, ?Utilizador $actor = null): array
    {
        return $this->tenant->executarComo((int) $cliente->id, fn () => Utilizador::query()->visivelPara($actor)->where('tenant_id', $cliente->id)->orderBy('id')->get()
            ->map(fn (Utilizador $u) => $this->resumoUtilizador($u))->values()->all());
    }

    /**
     * Edita nome, e-mail, papel e estado de um utilizador do cliente (parciais). Corre no contexto do cliente (RLS);
     * o e-mail é único dentro do cliente; só os papéis ADMINISTRADOR e UTILIZADOR podem ser atribuídos (o
     * super-administrador pertence à plataforma); nunca se remove nem desactiva o último administrador activo do
     * cliente; desactivar revoga as sessões. Auditado.
     *
     * @param  array{nome_completo?: ?string, email?: ?string, papel?: string, ativo?: bool}  $d
     * @return array<string, mixed>
     */
    public function atualizarUtilizador(Tenant $cliente, int $utilizadorId, array $d): array
    {
        return $this->tenant->executarComo((int) $cliente->id, fn () => DB::transaction(function () use ($cliente, $utilizadorId, $d) {
            $alvo = Utilizador::query()->where('tenant_id', $cliente->id)->lockForUpdate()->findOrFail($utilizadorId);
            $antes = $alvo->only(['nome_completo', 'email', 'papel', 'ativo']);

            if (array_key_exists('email', $d) && filled($d['email'])
                && Utilizador::query()->where('tenant_id', $cliente->id)->whereKeyNot($alvo->id)->whereRaw('lower(email) = ?', [mb_strtolower((string) $d['email'])])->exists()) {
                throw new ErroNegocio('Este e-mail já está em uso por outro utilizador deste cliente.', 'EMAIL_DUPLICADO', 422);
            }
            $papelNovo = $d['papel'] ?? $alvo->papel;
            if ($papelNovo !== $alvo->papel && ! in_array($papelNovo, [Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_UTILIZADOR], true)) {
                throw new ErroNegocio('Papel inválido para um utilizador de cliente.', 'PAPEL_INVALIDO', 422);
            }
            $ativoNovo = array_key_exists('ativo', $d) ? (bool) $d['ativo'] : (bool) $alvo->ativo;

            $papeisAdmin = [Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_SUPER_ADMINISTRADOR];
            $eraAdmin = $alvo->ativo && in_array($alvo->papel, $papeisAdmin, true);
            $continuaAdmin = $ativoNovo && in_array($papelNovo, $papeisAdmin, true);
            if ($eraAdmin && ! $continuaAdmin && ! Utilizador::query()->where('tenant_id', $cliente->id)->whereKeyNot($alvo->id)->where('ativo', true)
                ->whereIn('papel', $papeisAdmin)->exists()) {
                throw new ErroNegocio('É o último administrador activo deste cliente: não pode ser desactivado nem rebaixado.', 'ULTIMO_ADMINISTRADOR', 422);
            }

            foreach (['nome_completo', 'email'] as $campo) {
                if (array_key_exists($campo, $d)) {
                    $alvo->{$campo} = filled($d[$campo]) ? trim((string) $d[$campo]) : null;
                }
            }
            $alvo->papel = $papelNovo;
            $alvo->ativo = $ativoNovo;
            $alvo->save();
            if (! $ativoNovo) {
                $alvo->tokens()->delete();
            }
            $this->auditoria->registar('Plataforma', 'Alterar utilizador de cliente', "Utilizador '{$alvo->nome_utilizador}' do cliente #{$cliente->id} alterado.",
                'utilizadores', $alvo->id, $antes, $alvo->only(['nome_completo', 'email', 'papel', 'ativo']));

            return $this->resumoUtilizador($alvo);
        }));
    }

    /** @return array<string, mixed> */
    private function resumoUtilizador(Utilizador $u): array
    {
        return [
            'id' => $u->id, 'nome_utilizador' => $u->nome_utilizador, 'email' => $u->email,
            'nome_completo' => $u->nome_completo, 'papel' => $u->papel, 'ativo' => (bool) $u->ativo,
        ];
    }

    /** Edita nome e endereço (slug). */
    public function atualizar(Tenant $cliente, array $d): Tenant
    {
        $cliente->update(array_intersect_key($d, array_flip(['nome', 'slug', 'nome_sistema'])));

        return $cliente->refresh();
    }

    /** @return array{cliente: Tenant, empresas: Collection<int, Empresa>, utilizadores: int} */
    public function detalhe(Tenant $cliente): array
    {
        // utilizadores tem RLS por tenant_id (IsolamentoRls::TENANT_DIRETAS): fora do contexto do próprio cliente,
        // a consulta ficaria sempre a zero (como empresas, por isso o mesmo executarComo). O where('tenant_id', …)
        // explícito é a 2.ª camada (regra do projecto: nunca confiar só na RLS) — é também o que mantém a contagem
        // correcta numa ligação que ignora a RLS (dono/testes), onde o executarComo por si só não filtra nada.
        return $this->tenant->executarComo((int) $cliente->id, fn () => [
            'cliente' => $cliente,
            'empresas' => Empresa::query()->where('tenant_id', $cliente->id)->orderBy('id')->get(),
            'utilizadores' => Utilizador::query()->where('tenant_id', $cliente->id)->count(),
        ]);
    }

    /** Edita uma empresa do cliente, no contexto dele, pela mesma regra da aplicação (NIF único, etc.). */
    public function atualizarEmpresa(Tenant $cliente, int $empresaId, array $d, Utilizador $actor): Empresa
    {
        return $this->tenant->executarComo((int) $cliente->id, function () use ($empresaId, $d, $actor) {
            $empresa = Empresa::query()->findOrFail($empresaId);
            $estado = $d['estado'] ?? null;
            unset($d['estado']);
            $empresa = $this->empresas->atualizar($empresa, $d, $actor);

            return $estado === null ? $empresa : $this->empresas->definirEstado($empresa, $estado, $actor);
        });
    }

    public function definirEstado(Tenant $cliente, string $estado): Tenant
    {
        $this->naoMexerNaPlataforma($cliente, $estado === Tenant::ESTADO_SUSPENSO);
        // Outbox (ADR-080): a mudança de estado e o pedido do e-mail de suspensão são atómicos
        DB::transaction(function () use ($cliente, $estado) {
            $cliente->update(['estado' => $estado]);
            if ($estado === Tenant::ESTADO_SUSPENSO) {
                $this->notificarSuspensao($cliente);
            }
        });

        return $cliente->refresh();
    }

    /** utilizadores tem RLS por tenant_id: o administrador do cliente só é visível no contexto dele (ver detalhe()). */
    private function notificarSuspensao(Tenant $cliente): void
    {
        $admin = $this->tenant->executarComo((int) $cliente->id, fn () => Utilizador::query()
            ->whereIn('papel', [Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_SUPER_ADMINISTRADOR])
            ->whereNotNull('email')->orderBy('id')->first());

        if ($admin?->email) {
            Outbox::email(ContaSuspensaMail::class, $admin->email, ['nomeCompleto' => $admin->nome_completo ?: $admin->nome_utilizador, 'nomeCliente' => $cliente->nome],
                "email:conta-suspensa:{$cliente->id}:".Str::uuid());
        }
    }

    /** Arquiva o cliente (bloqueado, dados preservados). */
    public function arquivar(Tenant $cliente): Tenant
    {
        $this->naoMexerNaPlataforma($cliente, true);
        $cliente->update(['estado' => Tenant::ESTADO_ARQUIVADO]);

        return $cliente->refresh();
    }

    private function naoMexerNaPlataforma(Tenant $cliente, bool $bloqueia): void
    {
        if ($bloqueia && (int) $cliente->id === (int) config('erp.dominio.plataforma_tenant_id')) {
            throw new ErroNegocio('Não é possível suspender nem arquivar o cliente que opera a plataforma.', 'PLATAFORMA_NAO_SUSPENSIVEL', 422);
        }
    }
}
