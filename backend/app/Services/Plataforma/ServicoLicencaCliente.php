<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\Tenant;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A licença é do CLIENTE (tenant): um cliente tem uma licença (sem limite, teste ou paga por plano e tempo) e todas as suas
 * empresas valem o mesmo. Cada empresa guarda uma CÓPIA da licença do cliente, mantida aqui, porque é aí que o bloqueio
 * por licença expirada e as funções da plataforma já a lêem. Toda a alteração passa por `definir()`.
 */
final class ServicoLicencaCliente
{
    public function __construct(private readonly ContextoTenant $tenant) {}

    /** Licença actual do cliente, com os dias que faltam (null = sem expiração). */
    public function estado(Tenant $c): array
    {
        $hoje = now()->startOfDay();
        $fim = $c->licenca_fim;
        $expirada = $c->licencaExpirada();

        return [
            'origem' => $c->licenca_origem ?? Tenant::LICENCA_SEM_LIMITE,
            'inicio' => $c->licenca_inicio?->toDateString(),
            'fim' => $fim?->toDateString(),
            'dias_restantes' => ($c->licenca_origem === Tenant::LICENCA_SEM_LIMITE || $fim === null) ? null : ($expirada ? 0 : (int) $hoje->diffInDays($fim->copy()->startOfDay())),
            'expirada' => $expirada,
            'somente_leitura' => $expirada,
        ];
    }

    /**
     * Grava a licença do cliente e copia-a para todas as suas empresas (também as arquivadas).
     *
     * @param  string  $origem  SEM_LIMITE | TESTE | PLANO
     */
    public function definir(int $clienteId, string $origem, ?Carbon $inicio, ?Carbon $fim): Tenant
    {
        if (! in_array($origem, [Tenant::LICENCA_SEM_LIMITE, Tenant::LICENCA_TESTE, Tenant::LICENCA_PLANO], true)) {
            throw new ErroNegocio('Tipo de licença inválido.', 'LICENCA_INVALIDA', 422);
        }
        if ($origem === Tenant::LICENCA_SEM_LIMITE) {
            $inicio = $fim = null;
        } elseif ($inicio !== null && $fim !== null && $fim->lt($inicio)) {
            throw new ErroNegocio('A data de fim da licença não pode ser anterior à de início.', 'LICENCA_PERIODO_INVALIDO', 422);
        }

        return DB::transaction(function () use ($clienteId, $origem, $inicio, $fim) {
            $c = Tenant::query()->lockForUpdate()->find($clienteId) ?? throw new ErroNegocio('Cliente não encontrado.', 'NAO_ENCONTRADO', 404);
            $dados = ['licenca_origem' => $origem, 'licenca_inicio' => $inicio?->toDateString(), 'licenca_fim' => $fim?->toDateString()];
            $c->forceFill($dados)->save();
            // as empresas são do cliente: a cópia grava-se no contexto dele (RLS) e, sem RLS (SaaS desligado), só nas empresas dele
            $this->tenant->executarComo($clienteId, fn () => Empresa::query()->withTrashed()->where('tenant_id', $clienteId)->update($dados));

            return $c->refresh();
        });
    }

    /**
     * Aplica uma acção do administrador da plataforma: revogar (expira já), tornar sem limite, ou definir/estender o período.
     *
     * @param  array{origem: string, inicio?: ?string, fim?: ?string, dias_adicionar?: ?int, revogar?: ?bool}  $d
     */
    public function aplicar(int $clienteId, array $d): Tenant
    {
        $c = Tenant::query()->find($clienteId) ?? throw new ErroNegocio('Cliente não encontrado.', 'NAO_ENCONTRADO', 404);
        $hoje = now()->startOfDay();

        if (! empty($d['revogar'])) {
            $ontem = $hoje->copy()->subDay();
            $inicio = $c->licenca_inicio && Carbon::parse($c->licenca_inicio)->lte($ontem) ? Carbon::parse($c->licenca_inicio) : $ontem;

            return $this->definir($clienteId, Tenant::LICENCA_PLANO, $inicio, $ontem);
        }
        if ($d['origem'] === Tenant::LICENCA_SEM_LIMITE) {
            return $this->definir($clienteId, Tenant::LICENCA_SEM_LIMITE, null, null);
        }

        $inicio = ! empty($d['inicio']) ? Carbon::parse($d['inicio']) : ($c->licenca_inicio ? Carbon::parse($c->licenca_inicio) : $hoje->copy());
        $fim = ! empty($d['fim']) ? Carbon::parse($d['fim']) : null;
        if (! empty($d['dias_adicionar'])) {
            $base = ($c->licenca_fim && $c->licenca_origem !== Tenant::LICENCA_SEM_LIMITE && Carbon::parse($c->licenca_fim)->gte($hoje)) ? Carbon::parse($c->licenca_fim) : $hoje->copy();
            $fim = $base->copy()->addDays((int) $d['dias_adicionar']);
        }
        if ($fim === null && $d['origem'] === Tenant::LICENCA_TESTE) {
            $fim = $hoje->copy()->addDays((int) config('erp.licencas.dias_teste', 7));
        }

        return $this->definir($clienteId, $d['origem'], $inicio, $fim);
    }
}
