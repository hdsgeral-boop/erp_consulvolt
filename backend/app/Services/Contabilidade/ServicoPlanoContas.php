<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\PlanoConta;
use App\Support\Cache\ChaveCache;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Plano de contas da empresa activa. Leituras em cache (Redis, TTL 24 h, chave erp:{empresa}:contabilidade:plano_contas),
 * invalidada pelo model PlanoConta em qualquer escrita.
 * Regras do legado mantidas: código único por empresa; só contas de movimento (M) aceitam lançamentos
 * (js/db_v2.js:392-449); só contas da classe 4 podem ter moeda estrangeira (js/db_v2.js:695-698).
 */
final class ServicoPlanoContas
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return array<string, array{id: int, codigo: string, descricao: ?string, tipo: string, codigo_moeda: ?string}> codigo => conta */
    public function todas(): array
    {
        $empresa = $this->contexto->obrigatorio();

        return Cache::remember(ChaveCache::empresa($empresa, 'contabilidade', 'plano_contas'), config('erp.cache.ttl.plano_contas'), fn () => PlanoConta::query()
            ->orderBy('codigo')->get(['id', 'codigo', 'descricao', 'tipo', 'codigo_moeda'])
            ->mapWithKeys(fn (PlanoConta $c) => [$c->codigo => ['id' => $c->id, 'codigo' => $c->codigo, 'descricao' => $c->descricao,
                'tipo' => $c->tipo ?? PlanoConta::TIPO_MOVIMENTO, 'codigo_moeda' => $c->codigo_moeda]])
            ->all());
    }

    /** @return array{id: int, codigo: string, descricao: ?string, tipo: string, codigo_moeda: ?string} */
    public function contaDeMovimento(string $codigo): array
    {
        $conta = $this->todas()[$codigo] ?? null;
        if ($conta === null) {
            throw new ErroNegocio("A conta {$codigo} não existe no plano de contas.", 'CONTA_INEXISTENTE', 422, ['codigo_conta' => $codigo]);
        }
        if ($conta['tipo'] === PlanoConta::TIPO_TOTALIZADORA) {
            throw new ErroNegocio("A conta {$codigo} é totalizadora: só contas de movimento aceitam lançamentos.", 'CONTA_TOTALIZADORA', 422, ['codigo_conta' => $codigo]);
        }

        return $conta;
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(array $dados): PlanoConta
    {
        $this->validarMoeda($dados['codigo'], $dados['codigo_moeda'] ?? null);
        if (PlanoConta::query()->where('codigo', $dados['codigo'])->exists()) {
            throw new ErroNegocio("Já existe a conta {$dados['codigo']}.", 'CONTA_DUPLICADA', 422);
        }

        return PlanoConta::create($dados);
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizar(PlanoConta $conta, array $dados): PlanoConta
    {
        if (isset($dados['codigo']) && $dados['codigo'] !== $conta->codigo) {
            if ($this->temMovimentos($conta->codigo)) {
                throw new ErroNegocio('Não é possível mudar o código de uma conta com movimentos (use "Substituir conta").', 'CONTA_COM_MOVIMENTOS', 422);
            }
            if (PlanoConta::query()->where('codigo', $dados['codigo'])->whereKeyNot($conta->id)->exists()) {
                throw new ErroNegocio("Já existe a conta {$dados['codigo']}.", 'CONTA_DUPLICADA', 422);
            }
        }
        if (($dados['tipo'] ?? $conta->tipo) === PlanoConta::TIPO_TOTALIZADORA && $conta->tipo !== PlanoConta::TIPO_TOTALIZADORA && $this->temMovimentos($conta->codigo)) {
            throw new ErroNegocio('Uma conta com movimentos não pode passar a totalizadora.', 'CONTA_COM_MOVIMENTOS', 422);
        }
        $this->validarMoeda($dados['codigo'] ?? $conta->codigo, array_key_exists('codigo_moeda', $dados) ? $dados['codigo_moeda'] : $conta->codigo_moeda);
        $conta->update($dados);

        return $conta;
    }

    public function eliminar(PlanoConta $conta): void
    {
        if ($this->temMovimentos($conta->codigo)) {
            throw new ErroNegocio('Não é possível eliminar uma conta com movimentos.', 'CONTA_COM_MOVIMENTOS', 422);
        }
        $conta->delete();   // eliminação lógica
    }

    private function temMovimentos(string $codigo): bool
    {
        return DB::table('lancamentos_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo_conta', $codigo)->exists();
    }

    private function validarMoeda(string $codigo, ?string $moeda): void
    {
        if ($moeda !== null && $moeda !== '' && ! str_starts_with($codigo, '4')) {
            throw new ErroNegocio('Só contas da classe 4 (meios monetários) podem ter moeda estrangeira.', 'MOEDA_SO_CLASSE_4', 422);
        }
    }
}
