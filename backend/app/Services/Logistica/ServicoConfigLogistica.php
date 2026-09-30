<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigContabilLogistica;
use App\Models\Produto;
use App\Services\Compras\ServicoConfigCompras;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\DB;

/**
 * Contas da logística por omissão (inventário permanente, ADR-042). No legado não havia configuração: cada ecrã
 * reescrevia o prefixo da conta do produto (resolveMatérialAccount), o que produzia contas inexistentes, e os campos
 * «conta de custo» e «conta de compras» do produto eram gravados mas nunca lidos. A conta do produto prevalece; na
 * falta dela usa-se a daqui (o inventário usa a das Compras, já configurada).
 */
final class ServicoConfigLogistica
{
    public const CHAVES = [
        'custo_mercadorias_vendidas' => 'Custo das mercadorias vendidas e matérias consumidas (classe 7.1)',
        'sobras_inventario' => 'Sobras de inventário (proveito)',
        'quebras_inventario' => 'Quebras de inventário (custo)',
    ];

    public function __construct(
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoConfigCompras $compras,
    ) {}

    public function todas(): array
    {
        $v = ConfigContabilLogistica::query()->pluck('codigo_conta', 'chave');

        return collect(self::CHAVES)->map(fn ($d, $k) => ['descricao' => $d, 'codigo_conta' => $v[$k] ?? null])->all();
    }

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
                ConfigContabilLogistica::query()->updateOrCreate(['chave' => $chave], ['codigo_conta' => $codigo ?: null]);
            }
        });
    }

    public function exigir(string $chave, string $motivo): string
    {
        return ConfigContabilLogistica::query()->where('chave', $chave)->value('codigo_conta')
            ?: throw new ErroNegocio("{$motivo} Configure a conta \"".self::CHAVES[$chave].'" nas contas da logística.', 'CONFIG_LOGISTICA_EM_FALTA', 422, ['chave' => $chave]);
    }

    public function contaInventario(Produto $p): string
    {
        return $p->conta_inventario ?: $this->compras->exigir('inventario_mercadorias', "O produto {$p->codigo} não tem conta de inventário.");
    }

    public function contaCusto(Produto $p): string
    {
        return $p->conta_custo ?: $this->exigir('custo_mercadorias_vendidas', "O produto {$p->codigo} não tem conta de custo (CMV).");
    }

    public function contaSobras(Produto $p): string
    {
        return $p->conta_sobra ?: $this->exigir('sobras_inventario', "O produto {$p->codigo} não tem conta de sobras.");
    }

    public function contaQuebras(Produto $p): string
    {
        return $p->conta_quebra ?: $this->exigir('quebras_inventario', "O produto {$p->codigo} não tem conta de quebras.");
    }
}
