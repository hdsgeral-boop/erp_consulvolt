<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigContabilVenda;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\DB;

/**
 * Configuração contabilística de vendas (sales_accounting_config do legado: uma linha por chave).
 * Substitui as contas FIXAS no código do legado (31.1, 34.5.3, 72, 43.1/45.1 — algumas invertidas):
 * cada conta usada vem do produto/cliente e, na falta, desta configuração; se faltar, a operação
 * é recusada com indicação do que configurar (nunca se lança numa conta inventada).
 */
final class ServicoConfigVendas
{
    /** Chaves suportadas => descrição. */
    public const CHAVES = [
        'clientes_default' => 'Conta de clientes por omissão (clientes sem conta própria)',
        'proveitos_mercadorias' => 'Proveitos de vendas de mercadorias (produtos que movimentam stock, sem conta própria)',
        'proveitos_servicos' => 'Proveitos de prestações de serviços (sem conta própria)',
        'iva_vendas' => 'IVA liquidado (produtos sem conta de IVA própria)',
    ];

    public function __construct(private readonly ServicoPlanoContas $plano) {}

    public function conta(string $chave): ?string
    {
        return ConfigContabilVenda::query()->where('chave', $chave)->value('codigo_conta') ?: null;
    }

    public function exigir(string $chave, string $motivo): string
    {
        return $this->conta($chave) ?? throw new ErroNegocio("{$motivo} Configure a conta \"".self::CHAVES[$chave].'" nas contas de vendas.',
            'CONFIG_VENDAS_EM_FALTA', 422, ['chave' => $chave]);
    }

    /** @return array<string, array{descricao: string, codigo_conta: ?string}> */
    public function todas(): array
    {
        $valores = ConfigContabilVenda::query()->pluck('codigo_conta', 'chave');

        return collect(self::CHAVES)->map(fn ($d, $k) => ['descricao' => $d, 'codigo_conta' => $valores[$k] ?? null])->all();
    }

    /** @param  array<string, ?string>  $contas */
    public function definir(array $contas): void
    {
        DB::transaction(function () use ($contas) {
            foreach ($contas as $chave => $codigo) {
                if (! isset(self::CHAVES[$chave])) {
                    throw new ErroNegocio("Chave de configuração desconhecida: {$chave}", 'CONFIG_DESCONHECIDA', 422);
                }
                if ($codigo) {
                    $this->plano->contaDeMovimento($codigo);
                }
                ConfigContabilVenda::query()->updateOrCreate(['chave' => $chave], ['codigo_conta' => $codigo ?: null]);
            }
        });
    }
}
