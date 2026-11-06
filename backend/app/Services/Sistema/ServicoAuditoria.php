<?php

namespace App\Services\Sistema;

use App\Models\LogAuditoria;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Escrita centralizada de logs de auditoria (tabela logs_auditoria, imutável).
 */
final class ServicoAuditoria
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * @param  array<string,mixed>|null  $anteriores
     * @param  array<string,mixed>|null  $novos
     */
    public function registar(
        string $modulo,
        string $acao,
        ?string $detalhes = null,
        ?string $tabela = null,
        string|int|null $registoId = null,
        ?array $anteriores = null,
        ?array $novos = null,
        ?int $empresaId = null,
        ?int $utilizadorId = null,
        ?string $nomeUtilizador = null,
    ): LogAuditoria {
        $utilizador = Auth::user();
        // Conta ROOT global (ADR-084): toda a alteração auditada por ela leva a marca root e a empresa alvo.
        if ($utilizador instanceof Utilizador && $utilizador->eRoot() && ! ($novos['root'] ?? false)) {
            $novos = ($novos ?? []) + ['root' => true, 'empresa_alvo_id' => $empresaId ?? $this->contexto->id()];
        }
        $pedido = app()->runningInConsole() ? null : app(Request::class);

        $empresa = $empresaId ?? $this->contexto->id();
        $utilizadorFinal = $utilizadorId ?? $utilizador?->getKey();

        // Auditoria SEM contexto de tenant (tarefas agendadas como os câmbios do BAI; falhas de autenticação, que acontecem
        // ANTES de haver sessão): na produção a tabela tem RLS e o `INSERT … RETURNING id` só passa se a linha nova for
        // legível — sem tenant activo a política de leitura recusava-a («new row violates row-level security policy») e
        // um login falhado dava 500 em vez de «credenciais inválidas». O trigger `erp_logs_auditoria_tenant` (SECURITY
        // DEFINER) preenche o `tenant_id` pela empresa/utilizador da linha; o contexto da plataforma só serve para a linha
        // ser legível durante o INSERT e é reposto logo a seguir.
        $tenant = app(ContextoTenant::class);
        $anteriorTenant = $tenant->id();
        $comoPlataforma = ContextoTenant::ativo() && $anteriorTenant === null;
        if ($comoPlataforma) {
            $tenant->definir((int) config('erp.dominio.plataforma_tenant_id', 1));
        }

        try {
            // A escrita do log nunca é bloqueada pelo isolamento (pode não haver empresa activa).
            return $this->contexto->semIsolamento(fn () => LogAuditoria::create([
                'empresa_id' => $empresa,
                'utilizador_id' => $utilizadorFinal,
                'nome_utilizador' => ($nomeUtilizador !== null ? mb_substr($nomeUtilizador, 0, 100) : null) ?? $utilizador?->nome_utilizador ?? (app()->runningInConsole() ? 'sistema' : null),
                'ocorrido_em' => now(),
                'modulo' => $modulo,
                'acao' => $acao,
                'tabela' => $tabela,
                'registo_id' => $registoId !== null ? (string) $registoId : null,
                'detalhes' => $detalhes,
                'dados_anteriores' => $anteriores,
                'dados_novos' => $novos,
                'endereco_ip' => $pedido?->ip(),
                'agente_utilizador' => $pedido ? mb_substr((string) $pedido->userAgent(), 0, 500) : null,
            ]));
        } finally {
            if ($comoPlataforma) {
                $tenant->definir($anteriorTenant);
            }
        }
    }

    /**
     * Usado pelo trait Auditavel. A empresa lê-se dos atributos em bruto: os models globais (utilizadores, perfis,
     * moedas…) não têm empresa_id e, com o modo estrito, getAttribute() lançava MissingAttributeException ao
     * alterar/eliminar um registo existente.
     */
    public function registarModelo(Model $modelo, string $acao, ?array $anteriores, ?array $novos): void
    {
        $tabela = $modelo->getTable();
        $empresaId = $modelo->getAttributes()['empresa_id'] ?? ($tabela === 'empresas' ? $modelo->getKey() : null);

        $this->registar(
            modulo: method_exists($modelo, 'moduloAuditoria') ? $modelo->moduloAuditoria() : 'Sistema',
            acao: $acao,
            detalhes: "{$acao}: {$tabela}",
            tabela: $tabela,
            registoId: $modelo->getKey(),
            anteriores: $anteriores,
            novos: $novos,
            empresaId: $empresaId !== null ? (int) $empresaId : null,
        );
    }
}
