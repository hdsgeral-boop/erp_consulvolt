<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
    ) {}

    /** @return Collection<int, array{tenant: Tenant, empresas: int, utilizadores: int}> */
    public function listar(): Collection
    {
        $empresas = collect(DB::select('SELECT tenant_id, empresas FROM plataforma_empresas_por_tenant()'))->keyBy('tenant_id');
        $utilizadores = DB::table('utilizadores')->select('tenant_id', DB::raw('count(*) as total'))->groupBy('tenant_id')->pluck('total', 'tenant_id');

        return Tenant::query()->orderBy('id')->get()->map(fn (Tenant $c) => [
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
     * Cria o cliente, o seu administrador (super-administrador do próprio cliente, com acesso a todas as suas empresas)
     * e a empresa inicial, se indicada. Tudo numa transacção, os dados do cliente no seu próprio contexto (RLS).
     *
     * @param  array{nome: string, slug: string, administrador: array<string, mixed>, empresa?: array<string, mixed>|null}  $d
     * @return array{cliente: Tenant, administrador: Utilizador, empresa: ?Empresa}
     */
    public function criar(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $cliente = Tenant::create(['nome' => $d['nome'], 'slug' => $d['slug'], 'estado' => Tenant::ESTADO_ATIVO]);

            return $this->tenant->executarComo((int) $cliente->id, function () use ($d, $cliente) {
                $administrador = new Utilizador([
                    'nome_utilizador' => $d['administrador']['nome_utilizador'],
                    'nome_completo' => $d['administrador']['nome_completo'] ?? null,
                    'palavra_passe' => $d['administrador']['palavra_passe'],
                    'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR,
                    'acesso_todas_empresas' => true,
                    'ativo' => true,
                ]);
                $administrador->save();

                $empresa = empty($d['empresa']) ? null : $this->empresas->criar($d['empresa'], $administrador);

                return ['cliente' => $cliente, 'administrador' => $administrador, 'empresa' => $empresa];
            });
        });
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
        return [
            'cliente' => $cliente,
            'empresas' => $this->tenant->executarComo((int) $cliente->id, fn () => Empresa::query()->orderBy('id')->get()),
            'utilizadores' => Utilizador::query()->where('tenant_id', $cliente->id)->count(),
        ];
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
        $cliente->update(['estado' => $estado]);

        return $cliente->refresh();
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