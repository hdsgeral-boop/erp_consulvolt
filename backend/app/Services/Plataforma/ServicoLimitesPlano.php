<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Tenant;
use App\Models\Utilizador;

/**
 * Limites do plano do cliente (empresas e utilizadores).
 *
 * Enquanto a(s) empresa(s) do cliente ainda estiverem em período de demonstração (nenhuma com uma subscrição PAGA —
 * `licenca_origem` ainda `TESTE`), o limite é sempre 1 empresa e 1 utilizador, **independentemente do plano
 * escolhido no registo**: os limites do plano em si (`planos_licenca.max_empresas`/`max_utilizadores`) só passam a
 * valer depois de uma licença confirmada (ServicoLicenca::confirmarPagamento, `licenca_origem = PLANO`). Cliente sem
 * plano nenhum associado (ex.: a conta interna da própria plataforma) não tem limites.
 *
 * Resolve sempre o tenant a partir do utilizador autenticado (nunca do ContextoTenant/RLS, que só fica populado com
 * o SaaS global activo — `erp.saas.ativo`): os limites são uma regra de negócio/faturação, não uma funcionalidade de
 * isolamento multi-tenant, por isso aplicam-se mesmo com esse interruptor desligado.
 */
final class ServicoLimitesPlano
{
    public function plano(Tenant $cliente): ?PlanoLicenca
    {
        return $cliente->plano ? PlanoLicenca::query()->where('codigo', $cliente->plano)->first() : null;
    }

    /** @return array{plano: ?array{codigo: string, nome: string}, em_demonstracao: bool, empresas: array{usadas: int, limite: ?int}, utilizadores: array{usados: int, limite: ?int}} */
    public function uso(Tenant $cliente): array
    {
        $plano = $this->plano($cliente);
        // Ainda em demonstração: tem um plano atribuído (self-registo ou atribuído pela plataforma) e nenhuma das
        // suas empresas tem licença paga nem vitalícia (inclui o caso de ainda não ter nenhuma empresa — a primeira
        // a criar-se também fica sujeita ao limite de 1). Sem plano nenhum (ex.: conta interna da plataforma,
        // acesso concedido directamente pela equipa) não há período de demonstração a limitar.
        $emDemonstracao = $plano !== null
            && ! Empresa::query()->where('tenant_id', $cliente->id)->where('licenca_origem', '!=', Empresa::LICENCA_TESTE)->exists();

        return [
            'plano' => $plano ? ['codigo' => $plano->codigo, 'nome' => $plano->nome] : null,
            'em_demonstracao' => $emDemonstracao,
            'empresas' => ['usadas' => Empresa::query()->where('tenant_id', $cliente->id)->count(), 'limite' => $emDemonstracao ? 1 : $plano?->max_empresas],
            'utilizadores' => ['usados' => Utilizador::query()->where('tenant_id', $cliente->id)->count(), 'limite' => $emDemonstracao ? 1 : $plano?->max_utilizadores],
        ];
    }

    public function exigirLimiteEmpresas(int $tenantId): void
    {
        $this->exigir($tenantId, 'empresas', 'usadas', 'LIMITE_PLANO_EMPRESAS', 'empresa(s)');
    }

    public function exigirLimiteUtilizadores(int $tenantId): void
    {
        $this->exigir($tenantId, 'utilizadores', 'usados', 'LIMITE_PLANO_UTILIZADORES', 'utilizador(es)');
    }

    private function exigir(int $tenantId, string $chave, string $usado, string $codigo, string $rotulo): void
    {
        $cliente = Tenant::query()->find($tenantId);
        if ($cliente === null) {
            return;
        }
        $uso = $this->uso($cliente);
        $limite = $uso[$chave]['limite'];

        if ($limite !== null && $uso[$chave][$usado] >= $limite) {
            $mensagem = $uso['em_demonstracao']
                ? "O período de demonstração permite até {$limite} {$rotulo}. Subscreva um plano para aumentar este limite."
                : 'O plano '.($uso['plano']['nome'] ?? '')." permite até {$limite} {$rotulo}.";
            throw new ErroNegocio($mensagem, $codigo, 422);
        }
    }
}