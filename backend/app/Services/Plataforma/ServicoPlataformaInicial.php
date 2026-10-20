<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoUtilizadores;
use App\Support\Tenancy\ContextoTenant;
use App\Support\Tenancy\DominioPedido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Primeiro acesso da plataforma: cria o super-administrador do tenant da plataforma quando ainda não existe nenhum.
 *
 * RISCO (janela inicial): até o primeiro administrador existir, QUEM chegar primeiro ao endereço da plataforma cria a conta.
 * Mitigações: (1) só funciona no host da área `plataforma` (nunca num cliente, no site nem em desenvolvimento);
 * (2) só enquanto não houver super-administrador ativo nesse tenant; (3) a criação é serializada (bloqueio consultivo
 * do PostgreSQL + nova verificação dentro da transação); (4) se `PLATAFORMA_SETUP_TOKEN` estiver definido, o pedido tem de o
 * trazer no cabeçalho `X-Setup-Token`. Recomenda-se definir o segredo e criar o administrador logo após a instalação.
 */
final class ServicoPlataformaInicial
{
    private const CHAVE_BLOQUEIO = 'plataforma_inicial_criar_admin';

    public function __construct(
        private readonly ContextoTenant $tenant,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    public function exigeSegredo(): bool
    {
        return (string) config('erp.dominio.plataforma_setup_token') !== '';
    }

    /** Verdadeiro só no host da plataforma e sem nenhum super-administrador ativo no tenant da plataforma. */
    public function disponivel(Request $pedido): bool
    {
        return DominioPedido::de($pedido)->area === DominioPedido::PLATAFORMA && ! $this->existeAdministrador();
    }

    /**
     * @param  array{nome_utilizador: string, nome_completo: string, email: string, palavra_passe: string}  $d
     */
    public function criar(Request $pedido, array $d): Utilizador
    {
        // Resposta única e genérica para tudo o que não deve ser revelado (host errado, já existe admin, segredo errado).
        $indisponivel = new ErroNegocio('Operação indisponível.', 'PLATAFORMA_INICIAL_INDISPONIVEL', 403);

        if (DominioPedido::de($pedido)->area !== DominioPedido::PLATAFORMA) {
            throw $indisponivel;
        }
        if ($this->exigeSegredo() && ! hash_equals((string) config('erp.dominio.plataforma_setup_token'), (string) $pedido->header('X-Setup-Token'))) {
            throw $indisponivel;
        }

        return DB::transaction(function () use ($d, $indisponivel) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::CHAVE_BLOQUEIO]);
            }

            return $this->tenant->executarComo($this->tenantPlataforma(), function () use ($d, $indisponivel) {
                if ($this->existeAdministrador()) {
                    throw $indisponivel;
                }
                $nome = trim($d['nome_utilizador']);
                if (Utilizador::withTrashed()->where('nome_utilizador', $nome)->exists()) {
                    throw new ErroNegocio('Este nome de utilizador já existe.', 'UTILIZADOR_DUPLICADO', 422);
                }
                if (mb_strlen($d['palavra_passe']) < ServicoUtilizadores::MINIMO_PALAVRA_PASSE) {
                    throw new ErroNegocio('A palavra-passe tem de ter pelo menos '.ServicoUtilizadores::MINIMO_PALAVRA_PASSE.' caracteres.', 'PALAVRA_PASSE_CURTA', 422);
                }

                $admin = new Utilizador([
                    'nome_utilizador' => $nome,
                    'nome_completo' => $d['nome_completo'],
                    'email' => $d['email'],
                    'palavra_passe' => $d['palavra_passe'],
                    'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR,
                    'acesso_todas_empresas' => true,
                    'ativo' => true,
                ]);
                $admin->forceFill(['palavra_passe_alterada_em' => now()])->save();

                $this->auditoria->registar('Plataforma', 'Criar administrador inicial', "Super-administrador '{$admin->nome_utilizador}' da plataforma criado no primeiro acesso.",
                    'utilizadores', $admin->getKey(), utilizadorId: $admin->getKey(), nomeUtilizador: $admin->nome_utilizador);

                return $admin;
            });
        });
    }

    private function tenantPlataforma(): int
    {
        return (int) config('erp.dominio.plataforma_tenant_id');
    }

    private function existeAdministrador(): bool
    {
        return $this->tenant->executarComo($this->tenantPlataforma(), fn () => Utilizador::query()
            ->where('tenant_id', $this->tenantPlataforma())
            ->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)
            ->where('ativo', true)
            ->exists());
    }
}
