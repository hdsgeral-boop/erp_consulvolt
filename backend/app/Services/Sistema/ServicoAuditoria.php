<?php

namespace App\Services\Sistema;

use App\Models\LogAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
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
        $pedido = app()->runningInConsole() ? null : app(Request::class);

        // A escrita do log nunca é bloqueada pelo isolamento (pode não haver empresa activa).
        return $this->contexto->semIsolamento(fn () => LogAuditoria::create([
            'empresa_id' => $empresaId ?? $this->contexto->id(),
            'utilizador_id' => $utilizadorId ?? $utilizador?->getKey(),
            'nome_utilizador' => $nomeUtilizador ?? $utilizador?->nome_utilizador ?? (app()->runningInConsole() ? 'sistema' : null),
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
