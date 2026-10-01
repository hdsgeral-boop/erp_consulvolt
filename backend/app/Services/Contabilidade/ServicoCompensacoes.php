<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\DiarioContabil;
use App\Models\LancamentoContabil;
use App\Models\ReconciliacaoBancaria;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compensação de movimentos no extracto de conta corrente (js/ui_reports.js:2444-2864): marca as linhas com um código
 * comum (reconciliacao_codigo) e regista o auto na tabela reconciliacoes_bancarias (legado: reconciliations).
 *
 * Regra (decisão do utilizador de 2026-09-15, js/ui_reports.js:2469-2474 e 2690-2701): pelo menos dois movimentos, todos
 * da mesma conta, não compensados, com Σ(D) = Σ(C); fora da classe 3 e das contas 48 exige-se o mesmo terceiro.
 * Regularização (js/ui_reports.js:2587-2680): gera um lançamento no diário OD (ou o indicado) com a diferença na conta
 * escolhida e na própria conta, e compensa tudo de uma vez.
 *
 * Correcções face ao legado: código sem colisões (MATCH-AAAAMMDD-nnnn por numeração transaccional; o legado usava
 * Date.now()+aleatório), tudo numa transacção com bloqueio das linhas, valores em decimal exacto, regularização pelo
 * ServicoLancamentos (n.º de lançamento, conta de movimento, exercício aberto — o legado gravava as duas linhas sem
 * número nem validações) e reverter não apaga o auto: marca-o ANULADA (como a reconciliação bancária).
 */
final class ServicoCompensacoes
{
    public const ESTADO = 'COMPENSADO';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
        private readonly ServicoLancamentos $lancamentos,
    ) {}

    /** @param  list<int>  $ids */
    public function compensar(array $ids): array
    {
        return DB::transaction(function () use ($ids) {
            $linhas = $this->bloquear($ids);
            $this->validar($linhas, true);

            return $this->marcar($linhas, 'MATCH');
        });
    }

    /**
     * @param  list<int>  $ids
     * @param  array{codigo_conta: string, data: string, diario_id?: ?int}  $dados
     */
    public function regularizar(array $ids, array $dados): array
    {
        return DB::transaction(function () use ($ids, $dados) {
            $linhas = $this->bloquear($ids);
            $this->validar($linhas, false);
            $diferenca = $this->saldo($linhas);
            if (bccomp($diferenca, '0', 2) === 0) {
                throw new ErroNegocio('Não existe diferença para regularizar: compense directamente.', 'SEM_DIFERENCA', 422);
            }
            $diario = ! empty($dados['diario_id']) ? DiarioContabil::query()->findOrFail($dados['diario_id'])
                : (DiarioContabil::query()->where('codigo', 'OD')->first() ?? DiarioContabil::query()->orderBy('id')->first());
            if (! $diario) {
                throw new ErroNegocio('Não existe nenhum diário para o lançamento de regularização.', 'SEM_DIARIO', 422);
            }
            $original = $linhas->first();
            $valor = ltrim($diferenca, '-');
            $positivo = bccomp($diferenca, '0', 2) > 0;
            $descricao = 'Regularização de saldo - Doc: '.($original->numero_documento ?: $original->referencia);
            $novas = $this->lancamentos->criar([
                'diario_id' => $diario->id, 'data_documento' => $dados['data'], 'numero_documento' => 'AUTO_REG',
                'referencia' => $original->referencia ?: 'REG-'.now()->format('YmdHis'), 'descricao' => $descricao,
                'linhas' => [
                    ['codigo_conta' => $dados['codigo_conta'], 'tipo_dc' => $positivo ? 'D' : 'C', 'valor' => $valor, 'terceiro_id' => $original->terceiro_id],
                    ['codigo_conta' => $original->codigo_conta, 'tipo_dc' => $positivo ? 'C' : 'D', 'valor' => $valor, 'terceiro_id' => $original->terceiro_id],
                ],
            ]);
            $equilibrio = $novas->firstWhere('codigo_conta', $original->codigo_conta);

            return $this->marcar($linhas->push($equilibrio), 'MATCH') + ['lancamento_regularizacao' => $novas->first()->numero_lan];
        });
    }

    /** Reverte uma compensação (deleteAccountingReconciliation): liberta as linhas e marca o auto como ANULADA. */
    public function anular(string $codigo): array
    {
        return DB::transaction(function () use ($codigo) {
            $auto = ReconciliacaoBancaria::query()->where('reconciliacao_codigo', $codigo)->lockForUpdate()->first();
            $linhas = LancamentoContabil::query()->where('reconciliacao_codigo', $codigo)->lockForUpdate()->get();
            if (! $auto && $linhas->isEmpty()) {
                throw new ErroNegocio("Compensação {$codigo} não encontrada.", 'NAO_ENCONTRADO', 404);
            }
            if ($this->bancaria($codigo)) {
                throw new ErroNegocio('Esta é uma reconciliação bancária: reverta-a na Tesouraria.', 'RECONCILIACAO_BANCARIA', 422);
            }
            LancamentoContabil::query()->whereIn('id', $linhas->pluck('id'))->update(['reconciliacao_codigo' => null]);
            $auto?->update(['estado' => 'ANULADA']);

            return ['reconciliacao_codigo' => $codigo, 'linhas_libertadas' => $linhas->count()];
        });
    }

    /** Auto de compensação (showReconciliationModal). */
    public function mostrar(string $codigo): array
    {
        $auto = ReconciliacaoBancaria::query()->where('reconciliacao_codigo', $codigo)->first();
        $linhas = LancamentoContabil::query()->where('reconciliacao_codigo', $codigo)->orderBy('data_documento')->orderBy('id')
            ->get(['id', 'data_documento', 'codigo_conta', 'numero_lan', 'numero_documento', 'descricao', 'tipo_dc', 'valor', 'terceiro_id']);
        if (! $auto && $linhas->isEmpty()) {
            throw new ErroNegocio("Compensação {$codigo} não encontrada.", 'NAO_ENCONTRADO', 404);
        }

        return ['reconciliacao_codigo' => $codigo, 'data' => $auto?->data?->toDateString(), 'estado' => $auto?->estado,
            'valor_total' => $auto ? FiltroMapas::dinheiro($auto->valor_total) : null, 'linhas' => $linhas];
    }

    /** @return Collection<int, LancamentoContabil> */
    private function bloquear(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $linhas = LancamentoContabil::query()->whereIn('id', $ids)->lockForUpdate()->orderBy('id')->get();
        if ($linhas->count() !== count($ids)) {
            throw new ErroNegocio('Há movimentos seleccionados que não existem nesta empresa.', 'LINHAS_INEXISTENTES', 422);
        }

        return $linhas;
    }

    /** @param  Collection<int, LancamentoContabil>  $linhas */
    private function validar(Collection $linhas, bool $exigirZero): void
    {
        if ($linhas->count() < ($exigirZero ? 2 : 1)) {
            throw new ErroNegocio('Seleccione pelo menos dois movimentos para compensar.', 'COMPENSACAO_INVALIDA', 422);
        }
        if ($linhas->contains(fn ($l) => $l->reconciliacao_codigo !== null && $l->reconciliacao_codigo !== '')) {
            throw new ErroNegocio('Há movimentos seleccionados que já estão compensados. Retire-os da selecção.', 'JA_COMPENSADO', 422);
        }
        $contas = $linhas->pluck('codigo_conta')->map(fn ($c) => trim((string) $c))->unique();
        if ($contas->count() !== 1) {
            throw new ErroNegocio('Só é possível compensar movimentos da mesma conta.', 'COMPENSACAO_CONTAS_DIFERENTES', 422);
        }
        $conta = $contas->first();
        if (! preg_match('/^(3|48)/', $conta) && $linhas->pluck('terceiro_id')->map(fn ($t) => (string) $t)->unique()->count() !== 1) {
            throw new ErroNegocio("A conta {$conta} não é da classe 3 nem 48: a compensação exige o mesmo terceiro em todos os movimentos.", 'COMPENSACAO_TERCEIROS_DIFERENTES', 422);
        }
        if ($exigirZero && bccomp($this->saldo($linhas), '0', 2) !== 0) {
            $d = ltrim($this->saldo($linhas), '-');
            throw new ErroNegocio("A soma dos débitos e créditos tem de ser zero. Diferença: {$d}.", 'COMPENSACAO_DESEQUILIBRADA', 422, ['diferenca' => $d]);
        }
    }

    /** @param  Collection<int, LancamentoContabil>  $linhas */
    private function saldo(Collection $linhas): string
    {
        return $linhas->reduce(fn ($s, $l) => $l->tipo_dc === 'D' ? bcadd($s, (string) $l->valor, 2) : bcsub($s, (string) $l->valor, 2), '0.00');
    }

    /** @param  Collection<int, LancamentoContabil>  $linhas */
    private function marcar(Collection $linhas, string $prefixo): array
    {
        $empresa = $this->contexto->obrigatorio();
        $dia = now()->format('Ymd');
        $codigo = sprintf('%s-%s-%04d', $prefixo, $dia, $this->numeracao->proximo($empresa, "compensacao:{$dia}", fn () => 0));
        $total = $linhas->where('tipo_dc', 'D')->reduce(fn ($s, $l) => bcadd($s, (string) $l->valor, 2), '0.00');
        LancamentoContabil::query()->whereIn('id', $linhas->pluck('id'))->update(['reconciliacao_codigo' => $codigo]);
        ReconciliacaoBancaria::create(['reconciliacao_codigo' => $codigo, 'data' => now(), 'valor_total' => $total, 'estado' => self::ESTADO,
            'detalhes' => json_encode(['tipo' => 'COMPENSACAO', 'conta' => $linhas->first()->codigo_conta, 'linhas' => $linhas->pluck('id')->values()], JSON_UNESCAPED_UNICODE)]);

        return ['reconciliacao_codigo' => $codigo, 'valor_total' => $total, 'linhas' => $linhas->count()];
    }

    /** Bancária = código REC-/MAN-/DFT- ou com correspondências no extracto bancário (critério de ServicoLancamentos). */
    private function bancaria(string $codigo): bool
    {
        return preg_match('/^(REC|MAN|DFT)-/', $codigo) === 1
            || DB::table('correspondencias_reconciliacao')->where('empresa_id', $this->contexto->obrigatorio())
                ->where('reconciliacao_codigo', $codigo)->whereNotNull('linha_extrato_bancario_id')->exists();
    }
}
