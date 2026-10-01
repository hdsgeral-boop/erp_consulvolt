<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoEstornado;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Reciclagem de lançamentos (js/ui_aux.js:281-326 e 1336-1434): arquivo das linhas que o legado apagava ao
 * descontabilizar (recycled_journal_lines → lancamentos_estornados). Com o ADR-016 já não entram linhas novas (o estorno
 * fica no próprio diário); o arquivo do legado mantém-se consultável, restaurável e eliminável.
 *
 * Um "documento" da reciclagem = diário + chave do lançamento (ADR-025: n.º de lançamento → referência → n.º de documento).
 * Correcções face ao legado:
 *   - o legado agrupava e restaurava só pelo n.º de documento e SEM filtrar a empresa (restoreRecycledJournal procurava o
 *     doc_number em todas as empresas) — agora é sempre a empresa activa e o lançamento exacto;
 *   - restaurar passa pelo ServicoLancamentos (equilíbrio, contas de movimento, exercício aberto) e recebe um n.º de
 *     lançamento novo; o legado reinseria as linhas sem validação, com o n.º antigo.
 */
final class ServicoReciclagemLancamentos
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoLancamentos $lancamentos,
    ) {}

    public function listar(): array
    {
        $chave = $this->chave();
        $grupos = DB::select("SELECT r.diario_id, d.codigo AS diario, {$chave} AS chave, MIN(r.numero_documento) AS numero_documento,
                MIN(r.data_documento) AS data_documento, MAX(r.eliminado_em) AS eliminado_em, COUNT(*) AS linhas,
                SUM(CASE WHEN r.tipo_dc = 'D' THEN r.valor ELSE 0 END) AS debito, SUM(CASE WHEN r.tipo_dc = 'C' THEN r.valor ELSE 0 END) AS credito
            FROM lancamentos_estornados r LEFT JOIN diarios_contabeis d ON d.id = r.diario_id
            WHERE r.empresa_id = ?
            GROUP BY r.diario_id, d.codigo, {$chave}
            ORDER BY MAX(r.eliminado_em) DESC NULLS LAST, 3", [$this->contexto->obrigatorio()]);
        foreach ($grupos as $g) {
            $g->debito = FiltroMapas::dinheiro($g->debito);
            $g->credito = FiltroMapas::dinheiro($g->credito);
            $g->equilibrado = bccomp($g->debito, $g->credito, 2) === 0;
        }

        return $grupos;
    }

    /**
     * Restaura documentos da reciclagem como lançamentos novos e retira-os do arquivo.
     *
     * @param  list<array{diario_id: int, chave: string}>  $grupos
     * @return list<array{chave: string, numero_lan: string}>
     */
    public function restaurar(array $grupos): array
    {
        return DB::transaction(function () use ($grupos) {
            $feitos = [];
            foreach ($grupos as $g) {
                $linhas = $this->linhas($g);
                $l0 = $linhas->first();
                $novas = $this->lancamentos->criar([
                    'diario_id' => $l0->diario_id, 'data_documento' => $l0->data_documento->toDateString(),
                    'numero_documento' => $l0->numero_documento, 'referencia' => $l0->referencia,
                    'linhas' => $linhas->map(fn ($l) => [
                        'codigo_conta' => (string) $l->codigo_conta, 'tipo_dc' => $l->tipo_dc, 'valor' => (string) $l->valor, 'descricao' => $l->descricao,
                        'numero_documento' => $l->numero_documento, 'terceiro_id' => $l->terceiro_id, 'centro_custo_id' => $l->centro_custo_id,
                        'unidade_negocio_id' => $l->unidade_negocio_id, 'projeto_id' => $l->projeto_id,
                        'nota_demonstracao_id' => $l->nota_demonstracao_id, 'nota_fluxo_caixa_id' => $l->nota_fluxo_caixa_id,
                    ])->all(),
                ]);
                LancamentoEstornado::query()->whereIn('id', $linhas->pluck('id'))->delete();
                app(ServicoAuditoria::class)->registar('Contabilidade', 'Restauro', "Restaurado da reciclagem: {$g['chave']} → {$novas->first()->numero_lan}", 'lancamentos_estornados');
                $feitos[] = ['chave' => $g['chave'], 'numero_lan' => $novas->first()->numero_lan];
            }

            return $feitos;
        });
    }

    /** @param  list<array{diario_id: int, chave: string}>  $grupos */
    public function eliminar(array $grupos): int
    {
        return DB::transaction(function () use ($grupos) {
            $n = 0;
            foreach ($grupos as $g) {
                $linhas = $this->linhas($g);
                $n += LancamentoEstornado::query()->whereIn('id', $linhas->pluck('id'))->delete();
                app(ServicoAuditoria::class)->registar('Contabilidade', 'Eliminação definitiva', "Eliminado da reciclagem: {$g['chave']} ({$linhas->count()} linhas)", 'lancamentos_estornados');
            }

            return $n;
        });
    }

    public function esvaziar(): int
    {
        return DB::transaction(function () {
            $n = LancamentoEstornado::query()->delete();
            app(ServicoAuditoria::class)->registar('Contabilidade', 'Esvaziar reciclagem', "{$n} linhas eliminadas definitivamente.", 'lancamentos_estornados');

            return $n;
        });
    }

    private function linhas(array $g)
    {
        $linhas = LancamentoEstornado::query()->where('diario_id', (int) $g['diario_id'])->whereRaw($this->chave('').' = ?', [(string) $g['chave']])
            ->lockForUpdate()->orderBy('id')->get();
        if ($linhas->isEmpty()) {
            throw new ErroNegocio("O documento {$g['chave']} não está na reciclagem.", 'NAO_ENCONTRADO', 404);
        }

        return $linhas;
    }

    private function chave(string $alias = 'r'): string
    {
        $a = $alias !== '' ? "{$alias}." : '';

        return "COALESCE(NULLIF({$a}numero_lan, ''), NULLIF({$a}referencia, ''), {$a}numero_documento)";
    }
}
