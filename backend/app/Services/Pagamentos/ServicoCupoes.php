<?php

namespace App\Services\Pagamentos;

use App\Models\CupomDesconto;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\UsoCupom;
use Illuminate\Support\Facades\DB;

/**
 * Cupões e campanhas promocionais da subscrição SaaS (motor de precificação, Módulo B). Um cupão inválido/expirado/
 * esgotado NUNCA interrompe o checkout — devolve sempre um resultado com `valido=false` e uma mensagem amigável; o
 * desconto incide sobre o valor já após o desconto do ciclo (V_desconto_cupom = V_apos_ciclo × D_cupom% / 100).
 */
final class ServicoCupoes
{
    /** Pré-visualização para o botão "Aplicar" do checkout — sem efeitos secundários, sem lock. */
    public function preVisualizar(?string $codigo, int $planoId, string $cicloCodigo, float $valorAposCiclo, int $tenantId): array
    {
        if (! $codigo) {
            return $this->semCupom($valorAposCiclo);
        }

        $cupom = CupomDesconto::query()->whereRaw('upper(codigo) = ?', [mb_strtoupper(trim($codigo))])->first();
        $erro = $this->validar($cupom, $planoId, $cicloCodigo, $tenantId);
        if ($erro !== null) {
            return $this->semCupom($valorAposCiclo, $erro);
        }

        return $this->comCupom($cupom, $valorAposCiclo);
    }

    /**
     * Chamado dentro da transacção de ServicoLicenca::pedirRenovacao(), já com a subscrição criada. Revalida tudo
     * com lock (nunca confia na pré-visualização) e incrementa usos_atuais atomicamente, sem exceder limite_usos
     * mesmo com pedidos concorrentes. Nunca lança por cupão inválido — devolve snapshot vazio + aviso.
     */
    public function aplicarNaTransacao(?string $codigo, PlanoLicenca $plano, string $cicloCodigo, float $valorAposCiclo, int $tenantId, int $empresaId, int $subscricaoId): array
    {
        if (! $codigo) {
            return $this->snapshotVazio();
        }

        $cupom = CupomDesconto::query()->whereRaw('upper(codigo) = ?', [mb_strtoupper(trim($codigo))])->lockForUpdate()->first();
        $erro = $this->validar($cupom, $plano->id, $cicloCodigo, $tenantId);
        if ($erro !== null) {
            return $this->snapshotVazio($erro);
        }

        // Condição de limite no próprio UPDATE: nunca excede limite_usos mesmo com dois pedidos em simultâneo.
        $atualizadas = DB::table('cupons_desconto')->where('id', $cupom->id)
            ->where(fn ($q) => $q->whereNull('limite_usos')->orWhereColumn('usos_atuais', '<', 'limite_usos'))
            ->update(['usos_atuais' => DB::raw('usos_atuais + 1'), 'atualizado_em' => now()]);
        if ($atualizadas === 0) {
            return $this->snapshotVazio("O cupão {$cupom->codigo} expirou ou atingiu o limite de usos.");
        }

        $desconto = $this->calcularDesconto($cupom, $valorAposCiclo);

        UsoCupom::query()->create([
            'cupom_id' => $cupom->id, 'tenant_id' => $tenantId, 'empresa_id' => $empresaId,
            'subscricao_id' => $subscricaoId, 'valor_desconto_aplicado' => $desconto,
        ]);

        return [
            'cupom_id' => $cupom->id, 'cupom_codigo' => $cupom->codigo, 'desconto_cupom_tipo' => $cupom->tipo_desconto,
            'desconto_cupom_valor' => (float) $cupom->valor_desconto, 'valor_desconto_cupom' => $desconto, 'aviso' => null,
        ];
    }

    /** Chamado por ServicoLicenca::cancelar(): liberta o uso do cupão de um pedido cancelado. */
    public function libertar(SubscricaoEmpresa $subscricao): void
    {
        $uso = UsoCupom::query()->where('subscricao_id', $subscricao->id)->first();
        if ($uso === null) {
            return;
        }
        DB::table('cupons_desconto')->where('id', $uso->cupom_id)->decrement('usos_atuais');
        $uso->delete();
    }

    /** @return string|null mensagem de erro amigável, ou null se válido */
    private function validar(?CupomDesconto $cupom, int $planoId, string $cicloCodigo, int $tenantId): ?string
    {
        if ($cupom === null) {
            return 'Cupão não encontrado.';
        }
        if (! $cupom->ativo) {
            return "O cupão {$cupom->codigo} não está activo.";
        }
        $hoje = now()->toDateString();
        if ($hoje < $cupom->data_inicio->toDateString() || $hoje > $cupom->data_fim->toDateString()) {
            return "O cupão {$cupom->codigo} expirou ou atingiu o limite de usos.";
        }
        if ($cupom->limite_usos !== null && $cupom->usos_atuais >= $cupom->limite_usos) {
            return "O cupão {$cupom->codigo} expirou ou atingiu o limite de usos.";
        }
        if ($cupom->planos_aplicaveis && ! in_array($planoId, $cupom->planos_aplicaveis, true)) {
            return "O cupão {$cupom->codigo} não é válido para este plano.";
        }
        if ($cupom->ciclos_aplicaveis && ! in_array($cicloCodigo, $cupom->ciclos_aplicaveis, true)) {
            return "O cupão {$cupom->codigo} não é válido para este ciclo de facturação.";
        }
        $usosDoTenant = UsoCupom::query()->where('cupom_id', $cupom->id)->where('tenant_id', $tenantId)->count();
        if ($usosDoTenant >= $cupom->limite_por_cliente) {
            return "O cupão {$cupom->codigo} já foi usado o número máximo de vezes permitido pela sua conta.";
        }

        return null;
    }

    private function calcularDesconto(CupomDesconto $cupom, float $valorAposCiclo): float
    {
        $desconto = $cupom->tipo_desconto === CupomDesconto::PERCENTAGEM
            ? bcdiv(bcmul((string) $valorAposCiclo, (string) $cupom->valor_desconto, 4), '100', 2)
            : min((float) $cupom->valor_desconto, $valorAposCiclo);

        return (float) $desconto;
    }

    /** @return array<string, mixed> */
    private function comCupom(CupomDesconto $cupom, float $valorAposCiclo): array
    {
        $desconto = $this->calcularDesconto($cupom, $valorAposCiclo);

        return [
            'valido' => true, 'mensagem' => null,
            'cupom' => ['codigo' => $cupom->codigo, 'tipo_desconto' => $cupom->tipo_desconto, 'valor_desconto' => (float) $cupom->valor_desconto],
            'valor_desconto_cupom' => $desconto, 'valor_final' => round($valorAposCiclo - $desconto, 2),
        ];
    }

    /** @return array<string, mixed> */
    private function semCupom(float $valorAposCiclo, ?string $mensagem = null): array
    {
        return ['valido' => false, 'mensagem' => $mensagem, 'cupom' => null, 'valor_desconto_cupom' => 0.0, 'valor_final' => $valorAposCiclo];
    }

    /** @return array<string, mixed> */
    private function snapshotVazio(?string $aviso = null): array
    {
        return ['cupom_id' => null, 'cupom_codigo' => null, 'desconto_cupom_tipo' => null, 'desconto_cupom_valor' => null,
            'valor_desconto_cupom' => 0.0, 'aviso' => $aviso];
    }
}
