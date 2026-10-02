<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\MovimentoCaixa;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\SessaoCaixa;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes à folha de caixa que não alteram valores (complementam o ServicoCaixa sem o modificar):
 *
 *   - reabrir uma sessão FECHADA e ainda não contabilizada (lacuna A-11). O legado (js/ui_folha_caixa.js) não tinha
 *     reabertura — eliminava a sessão fechada inteira; aqui a sessão volta a ABERTA mantendo os movimentos, com as
 *     mesmas garantias da abertura: uma sessão ABERTA por conta de caixa (lock consultivo com a mesma chave do
 *     ServicoCaixa::abrir), exercício da abertura ainda aberto e nenhuma sessão posterior na mesma caixa (o saldo de
 *     abertura da seguinte foi sugerido a partir da contagem desta). A contagem e o fecho anteriores ficam na auditoria;
 *
 *   - classificar movimentos (notas às demonstrações e de fluxo de caixa, unidade de negócio e centro de custo), em
 *     massa ou linha a linha, como «Aplicar às linhas seleccionadas» do legado (js/ui_folha_caixa.js:201-208). Só em
 *     movimentos por contabilizar; nunca toca na conta, no valor, no sentido nem na data.
 */
final class ServicoCaixaAjustes
{
    /** Campos de classificação (não financeiros) que podem ser alterados num movimento por contabilizar. */
    public const CAMPOS_CLASSIFICACAO = ['nota_demonstracao_id', 'nota_fluxo_caixa_id', 'unidade_negocio_id', 'centro_custo_id'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    public function reabrir(SessaoCaixa $sessao, string $motivo): SessaoCaixa
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($sessao, $motivo, $empresa) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["caixa:{$empresa}:{$sessao->codigo_conta}"]);
            $sessao = SessaoCaixa::query()->lockForUpdate()->findOrFail($sessao->id);
            if ($sessao->estado === 'CONTABILIZADA') {
                throw new ErroNegocio('A sessão está contabilizada: descontabilize-a primeiro (estorno) e depois reabra-a.', 'SESSAO_CONTABILIZADA', 422);
            }
            if ($sessao->estado !== 'FECHADA') {
                throw new ErroNegocio('Só se reabre uma sessão fechada.', 'SESSAO_NAO_FECHADA', 422);
            }
            if (SessaoCaixa::query()->where('codigo_conta', $sessao->codigo_conta)->where('estado', 'ABERTA')->exists()) {
                throw new ErroNegocio("Já existe uma sessão aberta na caixa {$sessao->codigo_conta}: feche-a antes de reabrir esta.", 'SESSAO_JA_ABERTA', 422);
            }
            $posterior = SessaoCaixa::query()->where('codigo_conta', $sessao->codigo_conta)->where('id', '<>', $sessao->id)
                ->where(fn ($q) => $q->where('data_abertura', '>', $sessao->data_abertura->toDateString())
                    ->orWhere(fn ($q) => $q->where('data_abertura', $sessao->data_abertura->toDateString())->where('id', '>', $sessao->id)))
                ->orderBy('data_abertura')->orderBy('id')->first();
            if ($posterior) {
                throw new ErroNegocio("A caixa {$sessao->codigo_conta} já tem uma sessão posterior (#{$posterior->id}), cujo saldo de abertura partiu desta contagem: "
                    .'registe a correcção na sessão actual.', 'SESSAO_POSTERIOR', 422);
            }
            $this->exercicios->exigirAberto($empresa, $sessao->data_abertura->toDateString());

            $anteriores = ['estado' => $sessao->estado, 'data_fecho' => $sessao->data_fecho?->toDateString(), 'saldo_fecho' => $sessao->saldo_fecho,
                'saldo_fisico' => $sessao->saldo_fisico, 'fechado_por' => $sessao->fechado_por];
            $sessao->update(['estado' => 'ABERTA', 'data_fecho' => null, 'saldo_fecho' => null, 'saldo_fisico' => null, 'fechado_por' => null]);
            $this->auditoria->registar('Tesouraria', 'REABRIR_SESSAO_CAIXA', mb_substr("Sessão de caixa #{$sessao->id} ({$sessao->codigo_conta}) reaberta por "
                .(Auth::user()?->nome_utilizador ?? '—').": {$motivo}", 0, 1000), 'sessoes_caixa', $sessao->id, $anteriores, ['estado' => 'ABERTA', 'motivo' => $motivo]);

            return $sessao;
        });
    }

    /**
     * Aplica os campos de classificação indicados (só as chaves presentes; null limpa) aos movimentos da sessão.
     *
     * @param  list<int>  $ids
     * @param  array<string, int|null>  $campos
     * @return int movimentos alterados
     */
    public function classificar(SessaoCaixa $sessao, array $ids, array $campos): int
    {
        $campos = array_intersect_key($campos, array_flip(self::CAMPOS_CLASSIFICACAO));
        if (! $campos) {
            throw new ErroNegocio('Indique pelo menos um campo a aplicar.', 'SEM_CAMPOS', 422);
        }
        $this->validarNotas($campos);

        return DB::transaction(function () use ($sessao, $ids, $campos) {
            $sessao = SessaoCaixa::query()->lockForUpdate()->findOrFail($sessao->id);
            if ($sessao->estado === 'CONTABILIZADA') {
                throw new ErroNegocio('A sessão está contabilizada: descontabilize-a para alterar a classificação.', 'SESSAO_CONTABILIZADA', 422);
            }
            $movimentos = MovimentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->whereIn('id', $ids)->lockForUpdate()->get();
            if ($movimentos->count() !== count(array_unique($ids))) {
                throw new ErroNegocio('Há movimentos que não pertencem a esta sessão.', 'MOVIMENTO_INEXISTENTE', 422);
            }
            if ($movimentos->contains(fn (MovimentoCaixa $m) => (bool) $m->contabilizado)) {
                throw new ErroNegocio('Há movimentos já contabilizados.', 'MOVIMENTO_CONTABILIZADO', 422);
            }
            foreach ($movimentos as $m) {
                $m->update($campos);   // Auditavel regista antes/depois
            }

            return $movimentos->count();
        });
    }

    /** @param  array<string, int|null>  $campos */
    private function validarNotas(array $campos): void
    {
        if (! empty($campos['nota_demonstracao_id']) && ! NotaDemonstracao::query()->whereKey($campos['nota_demonstracao_id'])->exists()) {
            throw new ErroNegocio('A nota às demonstrações não existe nesta empresa.', 'NOTA_INEXISTENTE', 422);
        }
        if (! empty($campos['nota_fluxo_caixa_id']) && ! NotaFluxoCaixa::query()->whereKey($campos['nota_fluxo_caixa_id'])->exists()) {
            throw new ErroNegocio('A nota de fluxo de caixa não existe nesta empresa.', 'NOTA_INEXISTENTE', 422);
        }
    }
}
