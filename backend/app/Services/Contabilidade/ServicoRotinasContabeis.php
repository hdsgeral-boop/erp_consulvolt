<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\DiarioContabil;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\Projeto;
use App\Models\ReconciliacaoBancaria;
use App\Models\Terceiro;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rotinas contabilísticas (js/ui_rotinas.js): capitalização de obras internas, compensação automática, transferência de saldos,
 * actualização em massa, histórico e anulação. O Imposto de Selo está em ServicoRotinasContabeisSelo.
 * A limpeza de falsas reconciliações de tesouraria só tem aqui a pré-visualização: a execução é um pedido de Manutenção de dados
 * com aprovação dupla (o legado também a enviava para lá, ui_rotinas.js:220; ADR-015/021).
 *
 * O histórico continua em reconciliacoes_bancarias (como o legado: TRF_RECON_, AUTO_RECON_, UPDATE_BATCH_) e as capitalizações
 * são lidas dos próprios lançamentos CAP-AAAAMM.
 *
 * Correcções face ao legado:
 *   - capitalização: data no último dia do mês (o legado usava sempre o dia 28, ui_rotinas.js:1335); passa a constar do histórico e
 *     a poder ser anulada — o legado gravava em `routine_logs`, tabela inexistente, e a rotina terminava em erro depois de lançar
 *     (ui_rotinas.js:1368);
 *   - cada transferência e cada regularização é um lançamento equilibrado com N.º de lançamento; o legado gravava linhas soltas;
 *   - a regularização automática passa a ter o documento na descrição (o legado gravava o texto literal "${pair.D.doc_number}",
 *     ui_rotinas.js:1053) e o par é revalidado no servidor (mesmo grupo, por compensar, diferença ≤ 1,00);
 *   - anular = estorno dos lançamentos gerados (ADR-016) e libertação dos códigos; o legado apagava as linhas e deixava a linha da
 *     conta 3772 da regularização sem contrapartida (ui_rotinas.js:1199-1209);
 *   - actualização em massa: valida a conta (de movimento), o terceiro, o diário e as notas da empresa, recusa linhas estornadas ou
 *     de exercícios encerrados e anula tudo se uma mudança de diário deixar um lançamento desequilibrado;
 *   - o histórico só mostra e só anula as rotinas (o legado listava e "anulava" também as reconciliações bancárias e as
 *     compensações manuais, ui_rotinas.js:1115-1209);
 *   - as linhas estornadas e os estornos não entram nos saldos a transferir nem nos pares a compensar (no legado um documento
 *     descontabilizado deixava de ter linhas);
 *   - linhas de exercícios encerrados não são alteradas (o hook do legado também o recusava, js/db_v2.js:489-498): o par ou a
 *     linha é recusado com o motivo.
 */
final class ServicoRotinasContabeis
{
    public const TIPO_ORIGEM = 'ROTINAS';

    public const DIARIO_CAPITALIZACAO = 'FO';

    public const NOME_DIARIO_CAPITALIZACAO = 'Folha de Obra / Capitalizações';

    public const DIARIO_REGULARIZACAO = 'OD';

    public const NOME_DIARIO_REGULARIZACAO = 'Operações Diversas';

    public const CONTA_REGULARIZACAO = '3772';

    /** Diferença máxima regularizada automaticamente na compensação (ui_rotinas.js:927). */
    public const DIFERENCA_MAXIMA = '1.00';

    /** Campos da actualização em massa: nome do legado (ui_rotinas.js:490) => coluna. */
    public const CAMPOS_ACTUALIZAVEIS = [
        'description' => 'descricao', 'account_code' => 'codigo_conta', 'third_party_id' => 'terceiro_id', 'journal_id' => 'diario_id',
        'demo_note_id' => 'nota_demonstracao_id', 'cashflow_note_id' => 'nota_fluxo_caixa_id',
    ];

    private const PREFIXOS_HISTORICO = ['TRF_RECON_' => 'TRANSFERENCIA', 'AUTO_RECON_' => 'COMPENSACAO', 'UPDATE_BATCH_' => 'ACTUALIZACAO'];

    private int $sequencia = 0;

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoLancamentos $lancamentos,
        private readonly ServicoPlanoContas $planoContas,
        private readonly ServicoExercicios $exercicios,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Capitalização de obras internas (ui_rotinas.js:1289-1386) ─────────────

    /** Obras internas activas ou em curso (as que o legado oferecia para capitalizar, ui_rotinas.js:54). */
    public function obrasInternas(): array
    {
        return Projeto::query()->where('tipo', 'INTERNO')->whereIn('estado', ['ACTIVO', 'EM_CURSO'])->orderBy('codigo')
            ->get(['id', 'codigo', 'nome', 'estado'])->toArray();
    }

    /**
     * Capitaliza os custos (natureza CUSTO/CUSTO_REAL do razão analítico) das obras no mês: D conta da classe 1 / C conta 65,
     * diário FO, documento CAP-AAAAMM (um por mês, como o legado).
     *
     * @param  list<int>  $projetos
     */
    public function capitalizar(string $mes, array $projetos, string $contaDebito, string $contaCredito): array
    {
        if (! str_starts_with($contaDebito, '1') || ! str_starts_with($contaCredito, '65')) {
            throw new ErroNegocio('A capitalização debita uma conta da classe 1 e credita uma conta 65.', 'CONTAS_CAPITALIZACAO_INVALIDAS', 422);
        }
        $validos = Projeto::query()->where('tipo', 'INTERNO')->whereIn('estado', ['ACTIVO', 'EM_CURSO'])->whereIn('id', $projetos)->pluck('id')->all();
        $invalidos = array_values(array_diff($projetos, $validos));
        if ($invalidos) {
            throw new ErroNegocio('Só se capitalizam obras internas activas ou em curso.', 'OBRA_INVALIDA', 422, ['projetos' => $invalidos]);
        }
        $empresa = $this->contexto->obrigatorio();
        $documento = 'CAP-'.str_replace('-', '', $mes);

        return DB::transaction(function () use ($empresa, $mes, $validos, $contaDebito, $contaCredito, $documento) {
            $this->bloquearEmpresa($empresa);
            if (LancamentoContabil::query()->where('numero_documento', $documento)->whereNull('estorno_de_id')->whereNull('estornado_por_id')->exists()) {
                throw new ErroNegocio("Já existe um lançamento com o documento {$documento}: anule-o se pretender processar de novo.", 'CAPITALIZACAO_DUPLICADA', 422);
            }
            [$inicio, $fim] = $this->limitesMes($mes);
            $custos = DB::table('razao_analitico_projetos')->where('empresa_id', $empresa)->whereIn('projeto_id', $validos)->whereBetween('data', [$inicio, $fim])
                ->whereIn('natureza', ['CUSTO', 'CUSTO_REAL'])
                ->selectRaw('COUNT(*) AS n, COALESCE(SUM(COALESCE(NULLIF(montante, 0), valor, 0)), 0) AS total')->first();
            $total = bcadd((string) $custos->total, '0', 2);
            if ((int) $custos->n === 0 || bccomp($total, '0', 2) <= 0) {
                throw new ErroNegocio("Sem custos registados nas obras internas durante o mês {$mes}.", 'SEM_CUSTOS_A_CAPITALIZAR', 422);
            }
            $diario = $this->localizador->diario(self::DIARIO_CAPITALIZACAO, self::NOME_DIARIO_CAPITALIZACAO);
            $descricao = "Capitalização de Obras Internas - {$mes}";
            $linhas = $this->lancamentos->criar([
                'diario_id' => $diario->id, 'data_documento' => $fim, 'numero_documento' => $documento, 'descricao' => $descricao, 'tipo_origem' => self::TIPO_ORIGEM,
                'linhas' => [['codigo_conta' => $contaDebito, 'tipo_dc' => 'D', 'valor' => $total], ['codigo_conta' => $contaCredito, 'tipo_dc' => 'C', 'valor' => $total]],
            ]);
            LancamentoContabil::query()->whereIn('id', $linhas->pluck('id'))->update(['sistema_origem' => 'PROJETOS', 'valor_imposto' => 0]);
            $this->auditoria->registar('Contabilidade', 'Capitalizou obras internas', "{$documento}: {$total} (obras ".implode(', ', $validos).')', 'lancamentos_contabeis');

            return ['documento' => $documento, 'numero_lan' => $linhas->first()->numero_lan, 'data_documento' => $fim, 'total' => $total, 'movimentos' => (int) $custos->n];
        });
    }

    // ───────────── Compensação automática (ui_rotinas.js:873-1108) ─────────────

    /**
     * Pares débito/crédito por compensar com o mesmo contexto — conta + documento (classe 3 e 48) ou conta + documento + terceiro —
     * primeiro de valor igual, depois com diferença até 1,00 (maiores valores primeiro).
     *
     * @return list<array<string, mixed>>
     */
    public function paresCompensacao(): array
    {
        $chave = $this->chaveCompensacaoSql();
        $grupos = $this->linhasCompensaveis()->selectRaw("{$chave} AS chave")->groupByRaw('1')
            ->havingRaw("BOOL_OR(tipo_dc = 'D') AND BOOL_OR(tipo_dc = 'C')")->pluck('chave');
        if ($grupos->isEmpty()) {
            return [];
        }
        $pares = [];
        foreach ($grupos->chunk(500) as $bloco) {
            $linhas = $this->linhasCompensaveis()->whereRaw("{$chave} IN (".implode(',', array_fill(0, $bloco->count(), '?')).')', $bloco->values()->all())
                ->orderBy('id')->get(['id', 'data_documento', 'numero_documento', 'referencia', 'codigo_conta', 'terceiro_id', 'tipo_dc', 'valor', DB::raw("{$chave} AS chave")]);
            foreach ($linhas->groupBy('chave') as $grupo) {
                array_push($pares, ...$this->emparelhar($grupo));
            }
        }

        return $pares;
    }

    /**
     * Compensa os pares indicados ([{debito_id, credito_id}]). Cada par é revalidado; os inválidos são recusados com o motivo.
     * Diferença > 0,01: regularização no diário OD (conta 3772 × conta do par), compensada com o par.
     */
    public function compensar(array $pares): array
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($empresa, $pares) {
            $this->bloquearEmpresa($empresa);
            $hoje = now()->toDateString();
            $feitos = $recusados = [];
            $regularizacoes = 0;
            foreach ($pares as $i => $p) {
                $linhas = LancamentoContabil::query()->whereIn('id', [(int) $p['debito_id'], (int) $p['credito_id']])->lockForUpdate()->get()->keyBy('id');
                $d = $linhas[(int) $p['debito_id']] ?? null;
                $c = $linhas[(int) $p['credito_id']] ?? null;
                $motivo = $this->motivoParInvalido($d, $c);
                if ($motivo) {
                    $recusados[] = ['indice' => $i, 'debito_id' => (int) $p['debito_id'], 'credito_id' => (int) $p['credito_id'], 'motivo' => $motivo];

                    continue;
                }
                $diferenca = bcsub((string) $d->valor, (string) $c->valor, 2);
                $ids = [$d->id, $c->id];
                $numeroLan = null;
                if (bccomp($this->abs($diferenca), '0.01', 2) > 0) {
                    $diario = $this->localizador->diario(self::DIARIO_REGULARIZACAO, self::NOME_DIARIO_REGULARIZACAO);
                    $positivo = bccomp($diferenca, '0', 2) > 0;
                    $texto = "Regularização Automática (Rotina) - Doc: {$d->numero_documento}";
                    $reg = $this->lancamentos->criar([
                        'diario_id' => $diario->id, 'data_documento' => $hoje, 'numero_documento' => 'AUTO_REG',
                        'referencia' => $d->referencia ?: ($c->referencia ?: 'AUTO-REG-'.now()->getTimestampMs()), 'descricao' => $texto, 'tipo_origem' => self::TIPO_ORIGEM,
                        'linhas' => [
                            ['codigo_conta' => self::CONTA_REGULARIZACAO, 'tipo_dc' => $positivo ? 'D' : 'C', 'valor' => $this->abs($diferenca), 'terceiro_id' => $d->terceiro_id],
                            ['codigo_conta' => $d->codigo_conta, 'tipo_dc' => $positivo ? 'C' : 'D', 'valor' => $this->abs($diferenca), 'terceiro_id' => $d->terceiro_id],
                        ],
                    ]);
                    $ids[] = $reg->firstWhere('codigo_conta', $d->codigo_conta)->id;
                    $numeroLan = $reg->first()->numero_lan;
                    $regularizacoes++;
                }
                $codigo = 'AUTO_RECON_'.$this->marca()."_{$d->id}";
                LancamentoContabil::query()->whereIn('id', $ids)->update(['reconciliacao_codigo' => $codigo]);
                ReconciliacaoBancaria::create(['reconciliacao_codigo' => $codigo, 'data' => $hoje, 'valor_total' => $d->valor]);
                $feitos[] = ['codigo' => $codigo, 'debito_id' => $d->id, 'credito_id' => $c->id, 'diferenca' => $diferenca, 'regularizacao' => $numeroLan];
            }

            return ['compensados' => $feitos, 'regularizacoes' => $regularizacoes, 'recusados' => $recusados];
        });
    }

    // ───────────── Transferência de saldos (ui_rotinas.js:335-665) ─────────────

    /**
     * Saldo do mês por compensar de cada conta de origem (previewAllTransfers).
     *
     * @param  list<string>  $contas
     */
    public function saldosATransferir(string $mes, array $contas): array
    {
        return array_map(function (string $conta) use ($mes) {
            [$saldo, $nota] = $this->saldoOrigem($mes, $conta);

            return ['conta_origem' => $conta, 'saldo' => $saldo, 'nota_demonstracao_id' => $nota,
                'natureza' => bccomp($saldo, '0', 2) > 0 ? 'DEVEDOR' : (bccomp($saldo, '0', 2) < 0 ? 'CREDOR' : 'SEM_SALDO')];
        }, array_values(array_unique($contas)));
    }

    /**
     * Transfere o saldo do mês de cada conta de origem para a conta de destino (processarTransferenciaSaldos): um lançamento por
     * conta no diário indicado, datado do último dia do mês, documento TRF-AAAAMM; a linha da origem e os movimentos originais
     * ficam compensados com o código TRF_RECON_*.
     *
     * @param  list<array{conta_origem: string, conta_destino: string, nota_destino_id?: ?int}>  $linhas
     */
    public function transferirSaldos(int $diarioId, string $mes, array $linhas): array
    {
        $diario = DiarioContabil::query()->findOrFail($diarioId);
        $empresa = $this->contexto->obrigatorio();
        foreach ($linhas as $l) {
            if (! empty($l['nota_destino_id']) && ! NotaDemonstracao::query()->whereKey($l['nota_destino_id'])->exists()) {
                throw new ErroNegocio("A nota de destino {$l['nota_destino_id']} não existe nesta empresa.", 'NOTA_INEXISTENTE', 422);
            }
        }

        return DB::transaction(function () use ($empresa, $diario, $mes, $linhas) {
            $this->bloquearEmpresa($empresa);
            [, $fim] = $this->limitesMes($mes);
            $documento = 'TRF-'.str_replace('-', '', $mes);
            $feitas = [];
            foreach ($linhas as $l) {
                $origem = (string) $l['conta_origem'];
                $destino = (string) $l['conta_destino'];
                $originais = $this->linhasOrigem($mes, $origem)->lockForUpdate()->get(['id', 'tipo_dc', 'valor', 'nota_demonstracao_id']);
                $saldo = '0.00';
                foreach ($originais as $o) {
                    $saldo = $o->tipo_dc === 'D' ? bcadd($saldo, (string) $o->valor, 2) : bcsub($saldo, (string) $o->valor, 2);
                }
                if (bccomp($this->abs($saldo), '0.01', 2) < 0) {
                    continue;
                }
                $devedor = bccomp($saldo, '0', 2) > 0;
                $codigo = 'TRF_RECON_'.$this->marca().'_'.str_replace('.', '', $origem);
                $novas = $this->lancamentos->criar([
                    'diario_id' => $diario->id, 'data_documento' => $fim, 'numero_documento' => $documento, 'referencia' => "TRANSF-{$origem}",
                    'descricao' => "Transferência de Saldo ({$mes}): {$origem} -> {$destino}", 'tipo_origem' => self::TIPO_ORIGEM,
                    'linhas' => [
                        ['codigo_conta' => $origem, 'tipo_dc' => $devedor ? 'C' : 'D', 'valor' => $this->abs($saldo), 'nota_demonstracao_id' => $originais->whereNotNull('nota_demonstracao_id')->first()?->nota_demonstracao_id],
                        ['codigo_conta' => $destino, 'tipo_dc' => $devedor ? 'D' : 'C', 'valor' => $this->abs($saldo), 'nota_demonstracao_id' => $l['nota_destino_id'] ?? null],
                    ],
                ]);
                $linhaOrigem = $novas->first(fn ($x) => $x->codigo_conta === $origem && $x->tipo_dc === ($devedor ? 'C' : 'D'));
                LancamentoContabil::query()->whereIn('id', $originais->pluck('id')->push($linhaOrigem->id))->update(['reconciliacao_codigo' => $codigo]);
                ReconciliacaoBancaria::create(['reconciliacao_codigo' => $codigo, 'data' => $fim, 'valor_total' => $this->abs($saldo)]);
                $feitas[] = ['conta_origem' => $origem, 'conta_destino' => $destino, 'saldo' => $saldo, 'numero_lan' => $novas->first()->numero_lan, 'codigo' => $codigo,
                    'movimentos_compensados' => $originais->count()];
            }

            return ['documento' => $documento, 'data_documento' => $fim, 'transferencias' => $feitas];
        });
    }

    // ───────────── Actualização em massa (ui_rotinas.js:422-553) ─────────────

    /**
     * Altera campos de linhas de lançamento ([{lancamento_id, campo_a_modificar, novo_valor}]). Linhas com erro são recusadas com o
     * motivo (como o legado); as válidas ficam gravadas e registadas no histórico (UPDATE_BATCH_*) para poderem ser anuladas.
     */
    public function actualizarEmMassa(array $linhas): array
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($empresa, $linhas) {
            $this->bloquearEmpresa($empresa);
            $alteracoes = $erros = [];
            $afectados = [];
            foreach ($linhas as $i => $r) {
                $n = $i + 2;
                $campoPedido = trim((string) ($r['campo_a_modificar'] ?? ''));
                $campo = self::CAMPOS_ACTUALIZAVEIS[$campoPedido] ?? (in_array($campoPedido, self::CAMPOS_ACTUALIZAVEIS, true) ? $campoPedido : null);
                $id = (int) ($r['lancamento_id'] ?? 0);
                if (! $id || $campoPedido === '') {
                    $erros[] = ['linha' => $n, 'lancamento_id' => $id ?: null, 'motivo' => 'ID ou campo ausente.'];

                    continue;
                }
                if ($campo === null) {
                    $erros[] = ['linha' => $n, 'lancamento_id' => $id, 'motivo' => "O campo \"{$campoPedido}\" não é editável ou não existe."];

                    continue;
                }
                $linha = LancamentoContabil::query()->lockForUpdate()->find($id);
                if (! $linha) {
                    $erros[] = ['linha' => $n, 'lancamento_id' => $id, 'motivo' => 'Lançamento não encontrado nesta empresa.'];

                    continue;
                }
                try {
                    $novo = $this->valorActualizacao($campo, $r['novo_valor'] ?? null);
                    $this->exigirAlteravel($linha);
                } catch (ErroNegocio $e) {
                    $erros[] = ['linha' => $n, 'lancamento_id' => $id, 'motivo' => $e->getMessage()];

                    continue;
                }
                $anterior = $linha->getAttribute($campo);
                if ($campo === 'diario_id') {
                    $afectados[] = [$linha->diario_id, $linha->chave()];
                    $afectados[] = [$novo, $linha->chave()];
                }
                $linha->update([$campo => $novo]);
                $alteracoes[] = ['id' => $linha->id, 'campo' => $campo, 'anterior' => $anterior, 'novo' => $novo];
            }
            $this->exigirEquilibrados($afectados);
            $codigo = null;
            if ($alteracoes) {
                $codigo = 'UPDATE_BATCH_'.$this->marca();
                ReconciliacaoBancaria::create(['reconciliacao_codigo' => $codigo, 'data' => now()->toDateString(), 'valor_total' => count($alteracoes),
                    'tipo' => 'ATUALIZACAO_LOTE', 'tipo_original' => 'BATCH_UPDATE', 'detalhes' => json_encode($alteracoes, JSON_UNESCAPED_UNICODE)]);
            }

            return ['codigo' => $codigo, 'actualizadas' => count($alteracoes), 'erros' => $erros];
        });
    }

    // ───────────── Histórico e anulação (ui_rotinas.js:1110-1221) ─────────────

    /** Rotinas executadas: transferências, compensações, actualizações em massa e capitalizações (mais recentes primeiro). */
    public function historico(): array
    {
        $rotinas = ReconciliacaoBancaria::query()->where(fn ($q) => collect(array_keys(self::PREFIXOS_HISTORICO))
            ->each(fn ($p) => $q->orWhere('reconciliacao_codigo', 'like', str_replace('_', '\_', $p).'%')))
            ->orderByDesc('data')->orderByDesc('id')->get()
            ->map(fn ($h) => ['codigo' => $h->reconciliacao_codigo, 'tipo' => $this->tipoHistorico($h->reconciliacao_codigo), 'data' => $h->data?->toDateString(),
                'valor_total' => (string) $h->valor_total, 'estado' => $h->estado === 'ANULADA' ? 'ANULADA' : 'EXECUTADA']);
        $capitalizacoes = LancamentoContabil::query()->where('numero_documento', 'like', 'CAP-%')->where('tipo_dc', 'D')->whereNull('estorno_de_id')
            ->orderByDesc('data_documento')->get()
            ->map(fn ($l) => ['codigo' => $l->numero_documento, 'tipo' => 'CAPITALIZACAO', 'data' => $l->data_documento?->toDateString(), 'valor_total' => (string) $l->valor,
                'estado' => $l->estornado_por_id ? 'ANULADA' : 'EXECUTADA']);

        return $rotinas->concat($capitalizacoes)->sortByDesc('data')->values()->all();
    }

    /** Anula uma rotina do histórico. */
    public function anular(string $codigo, string $motivo): array
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($empresa, $codigo, $motivo) {
            $this->bloquearEmpresa($empresa);
            $texto = "Anulação da rotina {$codigo}: {$motivo}";
            if (str_starts_with($codigo, 'CAP-')) {
                $linhas = LancamentoContabil::query()->where('numero_documento', $codigo)->whereNull('estorno_de_id')->whereNull('estornado_por_id')->lockForUpdate()->orderBy('id')->get();
                if ($linhas->isEmpty()) {
                    throw new ErroNegocio("A capitalização {$codigo} não existe ou já foi anulada.", 'ROTINA_INEXISTENTE', 404);
                }
                $estornados = $this->estornarLinhas($linhas, $texto);
                $this->auditoria->registar('Contabilidade', 'Anulou rotina', $texto, 'lancamentos_contabeis');

                return ['codigo' => $codigo, 'tipo' => 'CAPITALIZACAO', 'estornados' => $estornados, 'linhas_libertadas' => 0, 'alteracoes_revertidas' => 0];
            }
            $tipo = $this->tipoHistorico($codigo);
            $registo = $tipo ? ReconciliacaoBancaria::query()->where('reconciliacao_codigo', $codigo)->lockForUpdate()->first() : null;
            if (! $registo || $registo->estado === 'ANULADA') {
                throw new ErroNegocio("A rotina {$codigo} não existe ou já foi anulada.", 'ROTINA_INEXISTENTE', 404);
            }
            $revertidas = $libertadas = 0;
            $estornados = [];
            if ($tipo === 'ACTUALIZACAO') {
                foreach (json_decode((string) $registo->detalhes, true) ?: [] as $a) {
                    $campo = self::CAMPOS_ACTUALIZAVEIS[$a['field'] ?? ''] ?? ($a['campo'] ?? null);
                    $campo = in_array($campo, self::CAMPOS_ACTUALIZAVEIS, true) ? $campo : null;
                    $linha = $campo ? LancamentoContabil::query()->lockForUpdate()->find((int) ($a['id'] ?? 0)) : null;
                    if (! $linha) {
                        continue;
                    }
                    $this->exigirAlteravel($linha);
                    $linha->update([$campo => array_key_exists('anterior', $a) ? $a['anterior'] : ($a['old'] ?? null)]);
                    $revertidas++;
                }
            } else {
                $linhas = LancamentoContabil::query()->where('reconciliacao_codigo', $codigo)->lockForUpdate()->orderBy('id')->get();
                foreach ($linhas as $l) {
                    $this->exigirAlteravel($l, false);
                }
                $geradas = $linhas->filter(fn ($l) => $l->numero_documento === 'AUTO_REG' || str_starts_with((string) $l->numero_documento, 'TRF-')
                    || str_starts_with((string) $l->referencia, 'TRANSF-'));
                $libertadas = LancamentoContabil::query()->where('reconciliacao_codigo', $codigo)->update(['reconciliacao_codigo' => null]);
                $estornados = $this->estornarLinhas($this->comContrapartidas($geradas), $texto);
            }
            $registo->update(['estado' => 'ANULADA']);
            $this->auditoria->registar('Contabilidade', 'Anulou rotina', $texto, 'reconciliacoes_bancarias', $registo->id);

            return ['codigo' => $codigo, 'tipo' => $tipo, 'estornados' => $estornados, 'linhas_libertadas' => $libertadas, 'alteracoes_revertidas' => $revertidas];
        });
    }

    // ───────────── Limpeza de falsas reconciliações (ui_rotinas.js:1225-1277) — só pré-visualização ─────────────

    /** Linhas de bancos (43) e caixa (45) com código de reconciliação que não vem de uma reconciliação bancária oficial. */
    public function previsualizarLimpezaReconciliacoes(): array
    {
        $oficiais = ReconciliacaoBancaria::query()->where('estado', 'CONCILIADO_BANCO')->pluck('reconciliacao_codigo')->filter()->all();
        $linhas = LancamentoContabil::query()->whereNotNull('reconciliacao_codigo')->where('reconciliacao_codigo', '<>', '')
            ->where(fn ($q) => $q->where('codigo_conta', 'like', '43%')->orWhere('codigo_conta', 'like', '45%'))
            ->when($oficiais, fn ($q) => $q->whereNotIn('reconciliacao_codigo', $oficiais))->orderBy('id')
            ->get(['id', 'data_documento', 'numero_lan', 'numero_documento', 'codigo_conta', 'tipo_dc', 'valor', 'reconciliacao_codigo']);

        return ['linhas' => $linhas->map(fn ($l) => ['id' => $l->id, 'data_documento' => $l->data_documento?->toDateString(), 'numero_lan' => $l->numero_lan,
            'numero_documento' => $l->numero_documento, 'codigo_conta' => $l->codigo_conta, 'tipo_dc' => $l->tipo_dc, 'valor' => (string) $l->valor,
            'reconciliacao_codigo' => $l->reconciliacao_codigo])->all(), 'total' => $linhas->count(),
            'execucao' => 'Pedido de Manutenção de dados LIMPAR_RECONCILIACOES (aprovação dupla).'];
    }

    // ───────────── Auxiliares ─────────────

    /** Linhas por compensar elegíveis: sem código, fora da classe 9, fora de pares estorno/estornado. */
    private function linhasCompensaveis()
    {
        return LancamentoContabil::query()->where(fn ($q) => $q->whereNull('reconciliacao_codigo')->orWhere('reconciliacao_codigo', ''))
            ->whereNotNull('codigo_conta')->where('codigo_conta', 'not like', '9%')->whereNull('estorno_de_id')->whereNull('estornado_por_id');
    }

    private function chaveCompensacaoSql(): string
    {
        return "CASE WHEN codigo_conta ~ '^(3|48)' THEN codigo_conta || '|' || COALESCE(numero_documento, '')
            ELSE codigo_conta || '|' || COALESCE(numero_documento, '') || '|' || COALESCE(terceiro_id::text, 'null') END";
    }

    /** Emparelhamento do legado (ui_rotinas.js:900-936) num grupo. */
    private function emparelhar(Collection $grupo): array
    {
        $ordenar = fn (Collection $c) => $c->sort(fn ($a, $b) => bccomp((string) $b->valor, (string) $a->valor, 2) ?: $a->id <=> $b->id)->values();
        $debitos = $ordenar($grupo->where('tipo_dc', 'D'));
        $creditos = $ordenar($grupo->where('tipo_dc', 'C'));
        $usadosD = $usadosC = $pares = [];
        foreach ([fn ($dif) => bccomp($this->abs($dif), '0.01', 2) < 0, fn ($dif) => bccomp($this->abs($dif), self::DIFERENCA_MAXIMA, 2) <= 0] as $criterio) {
            foreach ($debitos as $i => $d) {
                if (isset($usadosD[$i])) {
                    continue;
                }
                foreach ($creditos as $j => $c) {
                    $dif = bcsub((string) $d->valor, (string) $c->valor, 2);
                    if (! isset($usadosC[$j]) && $criterio($dif)) {
                        $pares[] = ['debito' => $this->resumoLinha($d), 'credito' => $this->resumoLinha($c), 'diferenca' => $dif,
                            'exacto' => bccomp($this->abs($dif), '0.01', 2) < 0];
                        $usadosD[$i] = $usadosC[$j] = true;
                        break;
                    }
                }
            }
        }

        return $pares;
    }

    private function resumoLinha(LancamentoContabil $l): array
    {
        return ['id' => $l->id, 'data_documento' => $l->data_documento?->toDateString(), 'numero_documento' => $l->numero_documento, 'codigo_conta' => $l->codigo_conta,
            'terceiro_id' => $l->terceiro_id, 'valor' => (string) $l->valor];
    }

    private function motivoParInvalido(?LancamentoContabil $d, ?LancamentoContabil $c): ?string
    {
        if (! $d || ! $c) {
            return 'Linha inexistente nesta empresa.';
        }
        if ($d->tipo_dc !== 'D' || $c->tipo_dc !== 'C') {
            return 'O par tem de ter uma linha a débito e outra a crédito.';
        }
        if (($d->reconciliacao_codigo ?? '') !== '' || ($c->reconciliacao_codigo ?? '') !== '') {
            return 'Uma das linhas já está compensada.';
        }
        if ($d->eEstorno() || $d->estaEstornado() || $c->eEstorno() || $c->estaEstornado()) {
            return 'Linhas estornadas ou de estorno não se compensam.';
        }
        if (str_starts_with((string) $d->codigo_conta, '9') || $d->codigo_conta !== $c->codigo_conta || (string) $d->numero_documento !== (string) $c->numero_documento
            || (! preg_match('/^(3|48)/', (string) $d->codigo_conta) && $d->terceiro_id !== $c->terceiro_id)) {
            return 'As linhas não têm a mesma conta, documento e terceiro.';
        }
        if (bccomp($this->abs(bcsub((string) $d->valor, (string) $c->valor, 2)), self::DIFERENCA_MAXIMA, 2) > 0) {
            return 'A diferença entre as linhas excede 1,00.';
        }
        foreach ([$d, $c] as $l) {
            if ($this->exercicios->encerrado($this->contexto->obrigatorio(), (int) $l->data_documento?->format('Y'))) {
                return 'Uma das linhas pertence a um exercício encerrado.';
            }
        }

        return null;
    }

    private function linhasOrigem(string $mes, string $conta)
    {
        [$inicio, $fim] = $this->limitesMes($mes);

        return LancamentoContabil::query()->where('codigo_conta', $conta)->whereBetween('data_documento', [$inicio, $fim])
            ->where(fn ($q) => $q->whereNull('reconciliacao_codigo')->orWhere('reconciliacao_codigo', ''))
            ->whereNull('estorno_de_id')->whereNull('estornado_por_id')->orderBy('id');
    }

    /** @return array{0: string, 1: ?int} [saldo D−C, primeira nota de demonstração] */
    private function saldoOrigem(string $mes, string $conta): array
    {
        $r = $this->linhasOrigem($mes, $conta)->reorder()
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS saldo")->first();
        $nota = $this->linhasOrigem($mes, $conta)->whereNotNull('nota_demonstracao_id')->value('nota_demonstracao_id');

        return [bcadd((string) $r->saldo, '0', 2), $nota !== null ? (int) $nota : null];
    }

    private function valorActualizacao(string $campo, mixed $valor): mixed
    {
        $vazio = $valor === null || $valor === '';
        if ($campo === 'descricao') {
            return $vazio ? '' : mb_substr((string) $valor, 0, 1000);
        }
        if ($campo === 'codigo_conta') {
            if ($vazio) {
                throw new ErroNegocio('A conta não pode ficar vazia.', 'CONTA_INEXISTENTE', 422);
            }

            return $this->planoContas->contaDeMovimento(trim((string) $valor))['codigo'];
        }
        if ($vazio) {
            if ($campo === 'diario_id') {
                throw new ErroNegocio('O diário não pode ficar vazio.', 'DIARIO_INEXISTENTE', 422);
            }

            return null;
        }
        $id = (int) $valor;
        $modelo = ['terceiro_id' => Terceiro::class, 'diario_id' => DiarioContabil::class, 'nota_demonstracao_id' => NotaDemonstracao::class,
            'nota_fluxo_caixa_id' => NotaFluxoCaixa::class][$campo];
        if (! $modelo::query()->whereKey($id)->exists()) {
            throw new ErroNegocio("O registo {$id} indicado em {$campo} não existe nesta empresa.", 'REFERENCIA_INEXISTENTE', 422);
        }

        return $id;
    }

    /** Linhas estornadas, de estorno ou de exercícios encerrados não se alteram. */
    private function exigirAlteravel(LancamentoContabil $l, bool $recusarEstornos = true): void
    {
        if ($recusarEstornos && ($l->eEstorno() || $l->estaEstornado())) {
            throw new ErroNegocio("A linha {$l->id} pertence a um lançamento estornado ou de estorno.", 'LINHA_ESTORNADA', 422);
        }
        $ano = (int) $l->data_documento?->format('Y');
        if ($ano && $this->exercicios->encerrado($this->contexto->obrigatorio(), $ano)) {
            throw new ErroNegocio("A linha {$l->id} pertence ao exercício de {$ano}, que está encerrado.", 'EXERCICIO_ENCERRADO', 422, ['ano' => $ano]);
        }
    }

    /** @param list<array{0: ?int, 1: ?string}> $afectados (diário, chave) */
    private function exigirEquilibrados(array $afectados): void
    {
        $desequilibrados = [];
        foreach (collect($afectados)->unique(fn ($a) => $a[0].'|'.$a[1]) as [$diario, $chave]) {
            $r = LancamentoContabil::query()->where('diario_id', $diario)->whereRaw(LancamentoContabil::chaveSql().' = ?', [$chave])
                ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS dif")->first();
            if (bccomp((string) $r->dif, '0', 2) !== 0) {
                $desequilibrados[] = ['diario_id' => $diario, 'lancamento' => $chave, 'diferenca' => bcadd((string) $r->dif, '0', 2)];
            }
        }
        if ($desequilibrados) {
            throw new ErroNegocio('A mudança de diário deixa lançamentos desequilibrados: mude todas as linhas de cada lançamento.', 'LANCAMENTOS_DESEQUILIBRADOS', 422,
                ['lancamentos' => $desequilibrados]);
        }
    }

    /** Junta às linhas geradas as restantes linhas dos mesmos lançamentos (ex.: a linha 3772 da regularização, sem código). */
    private function comContrapartidas(Collection $geradas): Collection
    {
        $todas = collect();
        foreach ($geradas->groupBy(fn ($l) => $this->grupoGerado($l)) as $grupo) {
            $l = $grupo->first();
            $q = LancamentoContabil::query()->where('diario_id', $l->diario_id)->whereNull('estorno_de_id')->whereNull('estornado_por_id');
            $todas = $todas->concat($l->numero_lan
                ? $q->where('numero_lan', $l->numero_lan)->get()
                : $q->whereNull('numero_lan')->where('numero_documento', $l->numero_documento)->where('referencia', $l->referencia)
                    ->whereDate('data_documento', $l->data_documento)->get());
        }

        return $todas->unique('id')->values();
    }

    /**
     * Estorna exactamente as linhas indicadas (ADR-016), agrupadas por lançamento: N.º de lançamento quando existe; nas linhas
     * migradas sem N.º, diário + documento + referência + data — a chave do ADR-025 (referência) juntaria transferências de meses
     * diferentes com a mesma referência "TRANSF-<conta>". Quando o lançamento completo coincide com as linhas, usa
     * ServicoLancamentos::estornar; senão grava o estorno das linhas exactas com as mesmas regras (data do original, ou hoje se o
     * exercício estiver encerrado; ligação estorno_de_id/estornado_por_id).
     *
     * @return list<string>
     */
    private function estornarLinhas(Collection $linhas, string $motivo): array
    {
        $empresa = $this->contexto->obrigatorio();
        $feitos = [];
        foreach ($linhas->groupBy(fn ($l) => $this->grupoGerado($l)) as $grupo) {
            $primeira = $grupo->first();
            $completo = LancamentoContabil::query()->doMesmoLancamento($primeira)->whereNull('estorno_de_id')->whereNull('estornado_por_id')->pluck('id')->sort()->values();
            if ($completo->all() === $grupo->pluck('id')->sort()->values()->all()) {
                $this->lancamentos->estornar($primeira, $motivo);
                $feitos[] = (string) $primeira->chave();

                continue;
            }
            $data = $primeira->data_documento->toDateString();
            $nota = '';
            if ($this->exercicios->encerrado($empresa, (int) substr($data, 0, 4))) {
                $data = now()->toDateString();
                $nota = " (exercício do original encerrado: estorno datado de {$data})";
            }
            $origem = $primeira->numero_lan ?? $primeira->referencia ?? $primeira->numero_documento;
            $novas = $this->lancamentos->criar([
                'diario_id' => $primeira->diario_id, 'data_documento' => $data, 'numero_documento' => $primeira->numero_documento, 'referencia' => $origem,
                'tipo_origem' => LancamentoContabil::ORIGEM_ESTORNO,
                'linhas' => $grupo->map(fn ($o) => ['codigo_conta' => $o->codigo_conta, 'tipo_dc' => $o->tipo_dc === 'D' ? 'C' : 'D', 'valor' => (string) $o->valor,
                    'descricao' => mb_substr("ESTORNO de {$origem}: {$motivo}{$nota}", 0, 1000), 'terceiro_id' => $o->terceiro_id, 'centro_custo_id' => $o->centro_custo_id,
                    'unidade_negocio_id' => $o->unidade_negocio_id, 'projeto_id' => $o->projeto_id, 'nota_demonstracao_id' => $o->nota_demonstracao_id,
                    'nota_fluxo_caixa_id' => $o->nota_fluxo_caixa_id, 'numero_documento' => $o->numero_documento])->values()->all(),
            ]);
            $agora = now();
            foreach ($grupo->values() as $k => $o) {
                $novas[$k]->update(['estorno_de_id' => $o->id]);
                $o->update(['estornado_por_id' => $novas[$k]->id, 'estornado_em' => $agora]);
            }
            $feitos[] = (string) $origem;
        }

        return $feitos;
    }

    private function grupoGerado(LancamentoContabil $l): string
    {
        return $l->numero_lan
            ? "{$l->diario_id}|L|{$l->numero_lan}"
            : "{$l->diario_id}|D|{$l->numero_documento}|{$l->referencia}|".$l->data_documento?->toDateString();
    }

    private function tipoHistorico(string $codigo): ?string
    {
        foreach (self::PREFIXOS_HISTORICO as $p => $t) {
            if (str_starts_with($codigo, $p)) {
                return $t;
            }
        }

        return null;
    }

    /** Marca temporal única no pedido para os códigos das rotinas (formato do legado: milissegundos). */
    private function marca(): string
    {
        return (string) (now()->getTimestampMs() + $this->sequencia++);
    }

    /** @return array{0: string, 1: string} */
    private function limitesMes(string $mes): array
    {
        $inicio = "{$mes}-01";

        return [$inicio, date('Y-m-t', strtotime($inicio))];
    }

    private function bloquearEmpresa(int $empresa): void
    {
        DB::table('empresas')->where('id', $empresa)->lockForUpdate()->first(['id']);
    }

    private function abs(string $v): string
    {
        return ltrim($v, '-');
    }
}
