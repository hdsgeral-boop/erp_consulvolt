<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\DiarioContabil;
use App\Models\LancamentoContabil;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Lançamentos contabilísticos (partidas dobradas).
 *
 * Criação (paridade com saveJournalEntry do legado, com as melhorias da directiva):
 *   - Σ Débitos = Σ Créditos, calculado em decimal exacto (bcmath) — o legado usava floats;
 *   - só contas de movimento (M) do plano da empresa; valores > 0; exercício aberto (lock de exercício);
 *   - N.º de lançamento <CódigoDiário><Ano><seq 6> (js/app_v2.js:1-24), gerado sem condição de corrida
 *     (lock Redis + sequência transaccional) — o legado calculava max+1 no browser;
 *   - tudo numa única transacção.
 *
 * Estorno (ADR-016): em vez de apagar as linhas (legado), cria o lançamento inverso ligado ao original.
 */
final class ServicoLancamentos
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPlanoContas $planoContas,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoNumeracao $numeracao,
    ) {}

    /**
     * @param  array{diario_id: int, data_documento: string, numero_documento?: ?string, referencia?: ?string, descricao?: ?string,
     *               tipo_origem?: string, linhas: list<array<string, mixed>>}  $dados  (tipo_origem: MANUAL por omissão; VENDAS, RECIBOS… quando gerado por um módulo;
     *               cada linha pode trazer numero_documento próprio)
     * @return Collection<int, LancamentoContabil>
     */
    public function criar(array $dados): Collection
    {
        $empresa = $this->contexto->obrigatorio();
        $diario = DiarioContabil::query()->findOrFail($dados['diario_id']);
        $this->exercicios->exigirAberto($empresa, $dados['data_documento']);

        [$debito, $credito] = $this->totais($dados['linhas']);
        if (bccomp($debito, $credito, 2) !== 0) {
            throw new ErroNegocio('Lançamento desequilibrado: o total a débito tem de ser igual ao total a crédito.', 'LANCAMENTO_DESEQUILIBRADO', 422,
                ['debito' => $debito, 'credito' => $credito, 'diferenca' => bcsub($debito, $credito, 2)]);
        }
        foreach ($dados['linhas'] as $i => $l) {
            try {
                $this->planoContas->contaDeMovimento((string) $l['codigo_conta']);
            } catch (ErroNegocio $e) {
                throw new ErroNegocio('Linha '.($i + 1).': '.$e->getMessage(), $e->codigo, 422, ['linha' => $i + 1] + $e->detalhes);
            }
        }

        return DB::transaction(function () use ($dados, $diario, $empresa) {
            $numeroLan = $this->proximoNumeroLan($empresa, $diario, $dados['data_documento']);
            $comum = [
                'diario_id' => $diario->id, 'data_documento' => $dados['data_documento'], 'data_lancamento' => now(),
                'numero_lan' => $numeroLan, 'numero_documento' => $dados['numero_documento'] ?? $numeroLan,
                'referencia' => $dados['referencia'] ?? null, 'tipo_origem' => $dados['tipo_origem'] ?? LancamentoContabil::ORIGEM_MANUAL,
                'nome_utilizador' => Auth::user()?->nome_utilizador,
            ];
            $criadas = new Collection;
            foreach ($dados['linhas'] as $l) {
                // a linha pode indicar o seu próprio n.º de documento (ex.: tesouraria: a factura que liquida)
                $criadas->push(LancamentoContabil::create(array_merge($comum, array_filter(['numero_documento' => $l['numero_documento'] ?? null])) + [
                    'codigo_conta' => (string) $l['codigo_conta'], 'tipo_dc' => $l['tipo_dc'], 'valor' => $this->dinheiro($l['valor']),
                    'descricao' => $l['descricao'] ?? $dados['descricao'] ?? null,
                    'terceiro_id' => $l['terceiro_id'] ?? null, 'centro_custo_id' => $l['centro_custo_id'] ?? null,
                    'unidade_negocio_id' => $l['unidade_negocio_id'] ?? null, 'projeto_id' => $l['projeto_id'] ?? null,
                    'nota_demonstracao_id' => $l['nota_demonstracao_id'] ?? null, 'nota_fluxo_caixa_id' => $l['nota_fluxo_caixa_id'] ?? null,
                    // moeda do documento (linhas de clientes/fornecedores/bancos em moeda estrangeira): permite o saldo em moeda
                    'codigo_moeda' => $l['codigo_moeda'] ?? null, 'valor_moeda' => isset($l['valor_moeda']) ? $this->dinheiro($l['valor_moeda']) : null,
                    'taxa_cambio' => $l['taxa_cambio'] ?? null,
                ]));
            }

            return $criadas;
        });
    }

    /**
     * Estorna o lançamento (documento) a que a linha pertence. Devolve as linhas de estorno.
     *
     * @return Collection<int, LancamentoContabil>
     */
    public function estornar(LancamentoContabil $linha, string $motivo): Collection
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($linha, $motivo, $empresa) {
            /** @var Collection<int, LancamentoContabil> $originais */
            $originais = LancamentoContabil::query()->doMesmoLancamento($linha)->lockForUpdate()->orderBy('id')->get();

            if ($originais->contains(fn ($l) => $l->eEstorno())) {
                throw new ErroNegocio('Este lançamento já é um estorno: não pode ser estornado.', 'ESTORNO_DE_ESTORNO', 422);
            }
            if ($originais->contains(fn ($l) => $l->estaEstornado())) {
                throw new ErroNegocio('Este lançamento já foi estornado.', 'JA_ESTORNADO', 422);
            }
            $this->exigirSemBloqueios($originais);

            // Data do estorno: a do original; se o exercício estiver encerrado, a data de hoje (ADR-016).
            $data = $originais->first()->data_documento->toDateString();
            $nota = '';
            if ($this->exercicios->encerrado($empresa, (int) substr($data, 0, 4))) {
                $data = now()->toDateString();
                $this->exercicios->exigirAberto($empresa, $data);
                $nota = " (exercício do original encerrado: estorno datado de {$data})";
            }

            $diario = DiarioContabil::query()->findOrFail($linha->diario_id);
            $numeroLan = $this->proximoNumeroLan($empresa, $diario, $data);
            $agora = now();
            $estornos = new Collection;
            foreach ($originais as $o) {
                $estorno = LancamentoContabil::create([
                    'diario_id' => $o->diario_id, 'data_documento' => $data, 'data_lancamento' => $agora, 'numero_lan' => $numeroLan,
                    'numero_documento' => $o->numero_documento, 'referencia' => $o->numero_lan ?? $o->numero_documento,
                    'codigo_conta' => $o->codigo_conta, 'tipo_dc' => $o->tipo_dc === 'D' ? 'C' : 'D', 'valor' => $o->valor,
                    'descricao' => mb_substr("ESTORNO de {$o->numero_lan}: {$motivo}{$nota}", 0, 1000),
                    'terceiro_id' => $o->terceiro_id, 'centro_custo_id' => $o->centro_custo_id, 'unidade_negocio_id' => $o->unidade_negocio_id,
                    'projeto_id' => $o->projeto_id, 'nota_demonstracao_id' => $o->nota_demonstracao_id, 'nota_fluxo_caixa_id' => $o->nota_fluxo_caixa_id,
                    'codigo_moeda' => $o->codigo_moeda, 'valor_moeda' => $o->valor_moeda, 'taxa_cambio' => $o->taxa_cambio,
                    'tipo_origem' => LancamentoContabil::ORIGEM_ESTORNO, 'estorno_de_id' => $o->id,
                    'nome_utilizador' => Auth::user()?->nome_utilizador,
                ]);
                $o->update(['estornado_por_id' => $estorno->id, 'estornado_em' => $agora]);
                $estornos->push($estorno);
            }

            return $estornos;
        });
    }

    /** @return Collection<int, LancamentoContabil> linhas do lançamento (documento) a que a linha pertence */
    public function documento(LancamentoContabil $linha): Collection
    {
        return LancamentoContabil::query()->doMesmoLancamento($linha)->orderBy('id')->get();
    }

    /** Bloqueios do legado à descontabilização (js/ui_lancamentos.js:2959-3078), agora pré-condições do estorno. */
    private function exigirSemBloqueios(Collection $linhas): void
    {
        $empresa = $this->contexto->obrigatorio();
        $comCodigo = $linhas->filter(fn ($l) => $l->reconciliacao_codigo !== null && $l->reconciliacao_codigo !== '');
        // reconciliação BANCÁRIA — linha de meios monetários (classe 4), código de banco (REC-/MAN-/DFT-) ou código com
        // correspondências de extracto: bloqueia (reverte-se primeiro no banco)
        $bancarios = DB::table('correspondencias_reconciliacao')->where('empresa_id', $empresa)
            ->whereIn('reconciliacao_codigo', $comCodigo->pluck('reconciliacao_codigo')->unique()->all())->whereNotNull('linha_extrato_bancario_id')
            ->distinct()->pluck('reconciliacao_codigo')->flip();
        if ($comCodigo->contains(fn ($l) => str_starts_with((string) $l->codigo_conta, '4') || preg_match('/^(REC|MAN|DFT)-/', (string) $l->reconciliacao_codigo)
            || isset($bancarios[$l->reconciliacao_codigo]))) {
            throw new ErroNegocio('O lançamento tem linhas reconciliadas com o banco: reverta primeiro a reconciliação.', 'LANCAMENTO_RECONCILIADO', 422);
        }
        $ids = $linhas->pluck('id')->all();
        if (DB::table('ativos_imobilizados')->where('empresa_id', $empresa)->whereIn('lancamento_contabil_id', $ids)->exists()) {
            throw new ErroNegocio('O lançamento está ligado a activos imobilizados: trate primeiro os activos.', 'LANCAMENTO_COM_ATIVOS', 422);
        }
        // COMPENSAÇÃO de terceiros (factura × pagamento, classe 3): liberta-se nas duas pontas, com rasto
        // (como o legado fazia ao descontabilizar; os códigos REC_/AUTO/TRF_/MATC partilham o mesmo campo)
        $codigos = $comCodigo->pluck('reconciliacao_codigo')->unique()->values()->all();
        if ($codigos) {
            DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->whereIn('reconciliacao_codigo', $codigos)->update(['reconciliacao_codigo' => null]);
            DB::table('reconciliacoes_bancarias')->where('empresa_id', $empresa)->whereIn('reconciliacao_codigo', $codigos)
                ->update(['estado' => 'ANULADA', 'atualizado_em' => now()]);
            app(ServicoAuditoria::class)->registar('Contabilidade', 'Libertou compensações',
                'Estorno do lançamento '.$linhas->first()->numero_lan.': compensações '.implode(', ', $codigos), 'lancamentos_contabeis');
        }
    }

    private function proximoNumeroLan(int $empresa, DiarioContabil $diario, string $data): string
    {
        $ano = substr($data, 0, 4);
        $prefixo = "{$diario->codigo}{$ano}";
        $seq = $this->numeracao->proximo($empresa, "lancamento:diario:{$diario->id}:{$ano}", function () use ($empresa, $diario, $prefixo) {
            // Semente: maior sequência já usada neste diário/ano (numero_lan ou referencia, como o legado)
            $padrao = '^'.preg_quote($prefixo, '/').'[0-9]{6}$';

            return (int) DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->where('diario_id', $diario->id)
                ->selectRaw('MAX(GREATEST(
                    CASE WHEN numero_lan ~ ? THEN substring(numero_lan from ?::int)::int ELSE 0 END,
                    CASE WHEN referencia ~ ? THEN substring(referencia from ?::int)::int ELSE 0 END)) AS m', [$padrao, strlen($prefixo) + 1, $padrao, strlen($prefixo) + 1])
                ->value('m');
        });

        return $prefixo.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    /** @return array{0: string, 1: string} [débito, crédito] em decimal exacto */
    private function totais(array $linhas): array
    {
        $d = $c = '0.00';
        foreach ($linhas as $l) {
            $valor = $this->dinheiro($l['valor']);
            if ($l['tipo_dc'] === 'D') {
                $d = bcadd($d, $valor, 2);
            } else {
                $c = bcadd($c, $valor, 2);
            }
        }

        return [$d, $c];
    }

    private function dinheiro(mixed $v): string
    {
        return number_format(round((float) $v, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }
}
