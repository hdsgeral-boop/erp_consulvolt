<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigContabilCompra;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\DB;

/**
 * Contas de compras por omissão. Substituem as contas FIXAS do legado (21, 26, 328, 321, 7621, 6621 e o '72'
 * para serviços — no PGC angolano a 72 é de custos com pessoal): cada conta vem do produto/fornecedor e, na falta,
 * daqui; se faltar, a operação é recusada com indicação do que configurar.
 */
final class ServicoConfigCompras
{
    public const CHAVES = [
        'compras_mercadorias' => 'Compras (classe 2.1) — produtos de stock sem conta de compra própria',
        'inventario_mercadorias' => 'Mercadorias/matérias (classe 2.6) — produtos de stock sem conta de inventário própria',
        'transitoria_compras' => 'Conta transitória de compras (3.2.8) — fornecedores sem conta transitória própria',
        'custos_servicos' => 'Fornecimentos e serviços de terceiros (7.5) — serviços sem conta de custo própria',
        'imobilizado' => 'Imobilizado (classe 1) — activos sem conta própria',
        'iva_dedutivel' => 'IVA dedutível (3.4.5) — produtos sem conta de IVA dedutível própria',
        'diferencas_cambio_desfavoraveis' => 'Diferenças de câmbio desfavoráveis (custo)',
        'diferencas_cambio_favoraveis' => 'Diferenças de câmbio favoráveis (proveito)',
    ];

    public function __construct(private readonly ServicoPlanoContas $plano) {}

    public function conta(string $chave): ?string
    {
        return ConfigContabilCompra::query()->where('chave', $chave)->value('codigo_conta') ?: null;
    }

    public function exigir(string $chave, string $motivo): string
    {
        return $this->conta($chave) ?? throw new ErroNegocio("{$motivo} Configure a conta \"".self::CHAVES[$chave].'" nas contas de compras.',
            'CONFIG_COMPRAS_EM_FALTA', 422, ['chave' => $chave]);
    }

    /** @return array<string, array{descricao: string, codigo_conta: ?string}> */
    public function todas(): array
    {
        $valores = ConfigContabilCompra::query()->pluck('codigo_conta', 'chave');

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
                ConfigContabilCompra::query()->updateOrCreate(['chave' => $chave], ['codigo_conta' => $codigo ?: null]);
            }
        });
    }
}
