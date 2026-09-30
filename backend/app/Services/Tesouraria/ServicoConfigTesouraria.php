<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigContabilTesouraria;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\DB;

/**
 * Contas de tesouraria por omissão. No legado estavam fixas e TROCADAS face ao PGC angolano (6 = proveitos,
 * 7 = custos): sobras de caixa iam para uma conta 78 (custo) e quebras para 68 (proveito); as diferenças de câmbio
 * usavam 6621/7621 invertidas. Aqui são configuráveis e validadas como contas de movimento.
 */
final class ServicoConfigTesouraria
{
    public const CHAVES = [
        'caixa_sobras' => 'Sobras de caixa (proveito, classe 6)',
        'caixa_quebras' => 'Quebras de caixa (custo, classe 7)',
        'diferencas_cambio_favoraveis' => 'Diferenças de câmbio favoráveis (proveito)',
        'diferencas_cambio_desfavoraveis' => 'Diferenças de câmbio desfavoráveis (custo)',
    ];

    public function __construct(private readonly ServicoPlanoContas $plano) {}

    public function exigir(string $chave, string $motivo): string
    {
        return ConfigContabilTesouraria::query()->where('chave', $chave)->value('codigo_conta')
            ?: throw new ErroNegocio("{$motivo} Configure a conta \"".self::CHAVES[$chave].'" nas contas de tesouraria.', 'CONFIG_TESOURARIA_EM_FALTA', 422, ['chave' => $chave]);
    }

    public function todas(): array
    {
        $v = ConfigContabilTesouraria::query()->pluck('codigo_conta', 'chave');

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
                ConfigContabilTesouraria::query()->updateOrCreate(['chave' => $chave], ['codigo_conta' => $codigo ?: null]);
            }
        });
    }
}
