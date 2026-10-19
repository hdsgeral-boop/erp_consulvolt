<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;

/**
 * Limites do plano do cliente (empresas e utilizadores). Só actua com o SaaS activo; cliente sem plano (por exemplo o
 * operador da plataforma) não tem limites. O limite em vigor é o do plano (a publicação de uma versão copia-o para lá).
 */
final class ServicoLimitesPlano
{
    public function __construct(private readonly ContextoTenant $tenant) {}

    public function plano(Tenant $cliente): ?PlanoLicenca
    {
        return $cliente->plano ? PlanoLicenca::query()->where('codigo', $cliente->plano)->first() : null;
    }

    /** @return array{plano: ?array{codigo: string, nome: string}, empresas: array{usadas: int, limite: ?int}, utilizadores: array{usados: int, limite: ?int}} */
    public function uso(Tenant $cliente): array
    {
        $plano = $this->plano($cliente);

        return [
            'plano' => $plano ? ['codigo' => $plano->codigo, 'nome' => $plano->nome] : null,
            'empresas' => ['usadas' => Empresa::query()->where('tenant_id', $cliente->id)->count(), 'limite' => $plano?->max_empresas],
            'utilizadores' => ['usados' => Utilizador::query()->where('tenant_id', $cliente->id)->count(), 'limite' => $plano?->max_utilizadores],
        ];
    }

    public function exigirLimiteEmpresas(): void
    {
        $this->exigir('empresas', 'usadas', 'LIMITE_PLANO_EMPRESAS', 'empresa(s)');
    }

    public function exigirLimiteUtilizadores(): void
    {
        $this->exigir('utilizadores', 'usados', 'LIMITE_PLANO_UTILIZADORES', 'utilizador(es)');
    }

    private function exigir(string $chave, string $usado, string $codigo, string $rotulo): void
    {
        if (! ContextoTenant::ativo() || $this->tenant->id() === null) {
            return;
        }
        $cliente = Tenant::query()->find($this->tenant->id());
        if ($cliente === null) {
            return;
        }
        $uso = $this->uso($cliente);
        $limite = $uso[$chave]['limite'];

        if ($limite !== null && $uso[$chave][$usado] >= $limite) {
            $nome = $uso['plano']['nome'] ?? '';
            throw new ErroNegocio("O plano {$nome} permite até {$limite} {$rotulo}.", $codigo, 422);
        }
    }
}