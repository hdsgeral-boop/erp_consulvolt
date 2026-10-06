<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\CentroCusto;
use App\Models\DiarioContabil;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\Projeto;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * M-09 — transferir um lançamento para outra empresa (legado: transferJournalEntry/confirmTransferJournalEntry,
 * js/ui_lancamentos.js:1022-1126).
 *
 * O legado mudava o company_id das linhas (o lançamento desaparecia da origem sem rasto). Aqui, numa só transacção:
 *   1. cria o lançamento na empresa destino (mesmas contas, valores, datas e moeda), mapeando o diário pelo código,
 *      o terceiro pelo NIF e as dimensões (CC, UN, notas, projecto) pelo código — as que não existirem no destino
 *      ficam em branco e são devolvidas como avisos;
 *   2. estorna o original na origem (ADR-016), com o número do lançamento criado no destino no motivo;
 *   3. liga as linhas do destino às originais (linha_origem_id) e audita nas duas empresas.
 * Exige acesso às duas empresas; o destino valida o plano de contas e o exercício aberto como um lançamento normal.
 */
final class ServicoTransferenciaLancamentos
{
    public const ORIGEM = 'TRANSFERENCIA';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoLancamentos $lancamentos,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * @return array{destino: array{empresa_id: int, empresa: string, numero_lan: string}, estorno: array{numero_lan: string, primeira_linha_id: int}, avisos: list<string>}
     */
    public function transferir(LancamentoContabil $linha, int $destino, string $motivo, Utilizador $actor): array
    {
        $origem = $this->contexto->obrigatorio();
        if ($destino === $origem) {
            throw new ErroNegocio('Escolha uma empresa diferente da empresa activa.', 'TRANSFERENCIA_MESMA_EMPRESA', 422);
        }
        if (! $this->empresas->podeAceder($actor, $destino)) {
            throw new ErroNegocio('Não tem acesso à empresa de destino.', 'EMPRESA_SEM_ACESSO', 403);
        }
        $nomeDestino = (string) DB::table('empresas')->where('id', $destino)->value('nome');
        $nomeOrigem = (string) DB::table('empresas')->where('id', $origem)->value('nome');

        return DB::transaction(function () use ($linha, $origem, $destino, $motivo, $nomeDestino, $nomeOrigem) {
            $originais = LancamentoContabil::query()->doMesmoLancamento($linha)->lockForUpdate()->orderBy('id')->get();
            if ($originais->contains(fn ($l) => $l->eEstorno())) {
                throw new ErroNegocio('Um estorno não pode ser transferido.', 'ESTORNO_DE_ESTORNO', 422);
            }
            if ($originais->contains(fn ($l) => $l->estaEstornado())) {
                throw new ErroNegocio('Este lançamento já foi estornado: não pode ser transferido.', 'JA_ESTORNADO', 422);
            }
            // como o legado (guardClosedYear na origem e no destino): o destino é validado pelo próprio lançamento
            app(ServicoExercicios::class)->exigirAberto($origem, $originais->first()->data_documento->toDateString());
            $diarioOrigem = DiarioContabil::query()->findOrFail($linha->diario_id);
            $codigos = fn (string $modelo, string $coluna, array $ids) => $ids ? $modelo::query()->whereKey($ids)->pluck($coluna, 'id')->all() : [];
            $col = fn (string $c) => $originais->pluck($c)->filter()->unique()->values()->all();
            $nifs = $col('terceiro_id') ? Terceiro::query()->withTrashed()->whereKey($col('terceiro_id'))->get(['id', 'nif', 'nome'])->keyBy('id') : collect();
            $refs = [
                'centro_custo_id' => [CentroCusto::class, $codigos(CentroCusto::class, 'codigo', $col('centro_custo_id'))],
                'unidade_negocio_id' => [UnidadeNegocio::class, $codigos(UnidadeNegocio::class, 'codigo', $col('unidade_negocio_id'))],
                'nota_demonstracao_id' => [NotaDemonstracao::class, $codigos(NotaDemonstracao::class, 'codigo', $col('nota_demonstracao_id'))],
                'nota_fluxo_caixa_id' => [NotaFluxoCaixa::class, $codigos(NotaFluxoCaixa::class, 'codigo', $col('nota_fluxo_caixa_id'))],
                'projeto_id' => [Projeto::class, $codigos(Projeto::class, 'codigo', $col('projeto_id'))],
            ];
            $numeroOrigem = (string) $originais->first()->numero_lan;
            $avisos = [];

            // 1) lançamento na empresa destino
            $criadas = $this->contexto->executarComo($destino, function () use ($originais, $diarioOrigem, $nifs, $refs, $numeroOrigem, $nomeOrigem, &$avisos) {
                $diario = DiarioContabil::query()->where('codigo', $diarioOrigem->codigo)->first()
                    ?? throw new ErroNegocio("O diário {$diarioOrigem->codigo} não existe na empresa de destino.", 'DIARIO_EM_FALTA_DESTINO', 422);
                $terceiros = [];
                foreach ($nifs as $id => $t) {
                    $nif = trim((string) $t->nif);
                    $terceiros[$id] = $nif !== '' ? Terceiro::query()->where('nif', $nif)->value('id') : null;
                    if ($terceiros[$id] === null) {
                        throw new ErroNegocio("O terceiro {$t->nome} (NIF ".($nif !== '' ? $nif : 'em falta').') não existe na empresa de destino: crie-o primeiro.',
                            'TERCEIRO_EM_FALTA_DESTINO', 422, ['terceiro_id' => $id]);
                    }
                }
                $mapa = [];
                foreach ($refs as $campo => [$modelo, $porId]) {
                    $noDestino = $porId ? $modelo::query()->whereIn('codigo', array_values($porId))->pluck('id', 'codigo')->all() : [];
                    foreach ($porId as $id => $codigo) {
                        $mapa[$campo][$id] = $noDestino[$codigo] ?? null;
                        if ($mapa[$campo][$id] === null) {
                            $avisos[] = "{$campo} «{$codigo}» não existe no destino: a linha fica sem este valor.";
                        }
                    }
                }
                $l0 = $originais->first();

                return $this->lancamentos->criar([
                    'diario_id' => $diario->id, 'data_documento' => $l0->data_documento->toDateString(),
                    'numero_documento' => $l0->numero_documento, 'referencia' => mb_substr("TRF {$numeroOrigem} ({$nomeOrigem})", 0, 100),
                    'tipo_origem' => self::ORIGEM, 'sistema_origem' => self::ORIGEM,
                    'linhas' => $originais->map(fn (LancamentoContabil $o) => [
                        'codigo_conta' => $o->codigo_conta, 'tipo_dc' => $o->tipo_dc, 'valor' => (string) $o->valor, 'descricao' => $o->descricao,
                        'numero_documento' => $o->numero_documento, 'terceiro_id' => $o->terceiro_id ? $terceiros[$o->terceiro_id] : null,
                        'centro_custo_id' => $o->centro_custo_id ? $mapa['centro_custo_id'][$o->centro_custo_id] ?? null : null,
                        'unidade_negocio_id' => $o->unidade_negocio_id ? $mapa['unidade_negocio_id'][$o->unidade_negocio_id] ?? null : null,
                        'nota_demonstracao_id' => $o->nota_demonstracao_id ? $mapa['nota_demonstracao_id'][$o->nota_demonstracao_id] ?? null : null,
                        'nota_fluxo_caixa_id' => $o->nota_fluxo_caixa_id ? $mapa['nota_fluxo_caixa_id'][$o->nota_fluxo_caixa_id] ?? null : null,
                        'projeto_id' => $o->projeto_id ? $mapa['projeto_id'][$o->projeto_id] ?? null : null,
                        'codigo_moeda' => $o->codigo_moeda, 'valor_moeda' => $o->valor_moeda !== null ? (string) $o->valor_moeda : null, 'taxa_cambio' => $o->taxa_cambio,
                    ])->all(),
                ]);
            });
            $numeroDestino = (string) $criadas->first()->numero_lan;
            foreach ($criadas->values() as $i => $c) {
                DB::table('lancamentos_contabeis')->where('id', $c->id)->update(['linha_origem_id' => $originais->values()[$i]->id]);
            }

            // 2) estorno na origem, com o rasto do destino
            $estorno = $this->lancamentos->estornar($linha, mb_substr("Transferido para {$nomeDestino} (lançamento {$numeroDestino}). {$motivo}", 0, 500));

            // 3) auditoria nas duas empresas
            $this->auditoria->registar('Contabilidade', 'Transferiu lançamento para outra empresa',
                "Lançamento {$numeroOrigem} transferido para a empresa {$destino} ({$numeroDestino}); estorno {$estorno->first()->numero_lan}. Motivo: {$motivo}",
                'lancamentos_contabeis', $linha->id);
            $this->auditoria->registar('Contabilidade', 'Recebeu lançamento de outra empresa',
                "Lançamento {$numeroDestino} criado por transferência do {$numeroOrigem} da empresa {$origem}.", 'lancamentos_contabeis', $criadas->first()->id, empresaId: $destino);

            return [
                'destino' => ['empresa_id' => $destino, 'empresa' => $nomeDestino, 'numero_lan' => $numeroDestino],
                'estorno' => ['numero_lan' => (string) $estorno->first()->numero_lan, 'primeira_linha_id' => (int) $estorno->first()->id],
                'avisos' => array_values(array_unique($avisos)),
            ];
        });
    }
}
