<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conta ROOT global (ADR-084): localizar, definir e revogar. Só o comando `erp:root:definir` usa isto — nenhuma rota.
 * A pesquisa atravessa os tenants (a RLS de `utilizadores` esconderia tudo sem contexto) executando-a em cada um.
 */
final class ServicoContaRoot
{
    public function __construct(
        private readonly ContextoTenant $tenant,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * Contas que correspondem ao nome de utilizador (sem distinguir maiúsculas: «Admin» e «admin» contam como ambíguo) em TODOS os tenants.
     *
     * @return Collection<int, Utilizador>
     */
    public function porNome(string $nome): Collection
    {
        return $this->emTodosOsTenants(fn () => Utilizador::query()->whereRaw('lower(nome_utilizador) = ?', [mb_strtolower($nome)])->get());
    }

    public function porId(int $id): ?Utilizador
    {
        return $this->emTodosOsTenants(fn () => Utilizador::query()->whereKey($id)->get())->first();
    }

    /** @return Collection<int, Utilizador> contas root em vigor (activas, não eliminadas). */
    public function raizes(): Collection
    {
        return $this->emTodosOsTenants(fn () => Utilizador::query()->where('conta_root', true)->where('ativo', true)->get());
    }

    /** Marca a conta como root. Recusa contas que não sejam super-administradores activos. */
    public function definir(Utilizador $u, string $operador): void
    {
        if (! $u->ativo || $u->trashed()) {
            throw new ErroNegocio('Só uma conta activa e não eliminada pode ser root.', 'ROOT_CONTA_INVALIDA', 422);
        }
        if (! $u->eSuperAdministrador()) {
            throw new ErroNegocio('Só um SUPER_ADMINISTRADOR pode ser conta root.', 'ROOT_CONTA_INVALIDA', 422);
        }
        $this->gravar($u, true, 'Definiu conta root', $operador);
    }

    /** Retira a marca root. Recusa a última conta root, salvo `$permitirUltima`. */
    public function revogar(Utilizador $u, string $operador, bool $permitirUltima = false): void
    {
        $outras = $this->raizes()->reject(fn (Utilizador $r) => $r->is($u))->count();
        if ($outras === 0 && ! $permitirUltima) {
            throw new ErroNegocio('Esta é a última conta root; use --ultima para a revogar mesmo assim.', 'ROOT_ULTIMA', 422);
        }
        $this->gravar($u, false, 'Revogou conta root', $operador);
    }

    private function gravar(Utilizador $u, bool $valor, string $acao, string $operador): void
    {
        $this->tenant->executarComo((int) $u->tenant_id, function () use ($u, $valor, $acao, $operador) {
            Utilizador::$autorizarAlteracaoRoot = true;
            try {
                DB::transaction(function () use ($u, $valor, $acao, $operador) {
                    $u->forceFill(['conta_root' => $valor])->save();
                    $this->auditoria->registar('Root', $acao, "{$acao}: '{$u->nome_utilizador}' (id {$u->getKey()}), operador do sistema: {$operador}.",
                        'utilizadores', $u->getKey(), novos: ['root' => true, 'conta_root' => $valor, 'operador' => $operador]);
                });
            } finally {
                Utilizador::$autorizarAlteracaoRoot = false;
            }
        });
    }

    /**
     * @param  callable(): Collection<int, Utilizador>  $consulta
     * @return Collection<int, Utilizador>
     */
    private function emTodosOsTenants(callable $consulta): Collection
    {
        $todas = collect();
        $tenants = DB::table('tenants')->orderBy('id')->pluck('id');
        foreach ($tenants as $id) {
            $todas = $todas->merge($this->tenant->executarComo((int) $id, $consulta));
        }

        return $todas->unique(fn (Utilizador $u) => $u->getKey())->values();
    }
}
