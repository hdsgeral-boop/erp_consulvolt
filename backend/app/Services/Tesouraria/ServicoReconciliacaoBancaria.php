<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\CorrespondenciaReconciliacao;
use App\Models\LancamentoContabil;
use App\Models\LinhaExtratoBancario;
use App\Models\ReconciliacaoBancaria;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use Throwable;

/**
 * Reconciliação bancária (processExternalBankStatement / runReconciliationAlgorithm / confirmBankReconciliation /
 * deleteReconciliation, js/ui_tesouraria.js:3974-6000), corrigida:
 *   - importação XLSX, XLS e CSV (o legado só Excel), com leitura correcta de "1.500,50", datas Excel/dd-mm-aaaa,
 *     e detecção de duplicados (o legado reimportava as mesmas linhas);
 *   - convenção única: o extracto está na óptica do BANCO (C = entrada na conta, D = saída) e corresponde a
 *     lançamentos da conta 43 no sentido oposto (o legado tinha convenções contraditórias);
 *   - sugestões (mesma data e valor → ±N dias → valor único; a referência desempata) que só se aplicam depois de
 *     confirmadas; a confirmação exige Σ extrato = Σ diário, é transaccional e com bloqueio das linhas;
 *   - códigos REC-AAAAMMDD-nnnn sem colisões; anular não apaga: marca ANULADA e repõe as duas pontas;
 *   - lançamentos estornados (e os estornos) não entram na reconciliação.
 */
final class ServicoReconciliacaoBancaria
{
    private const ALIASES = [
        'data' => ['data', 'data movimento', 'data mov', 'date', 'data valor', 'data operacao'],
        'referencia' => ['referencia', 'ref', 'documento', 'n documento', 'num documento'],
        'descricao' => ['descricao', 'historico', 'descritivo', 'movimento', 'detalhe'],
        'debito' => ['debito', 'saida', 'saidas', 'levantamento', 'debitos'],
        'credito' => ['credito', 'entrada', 'entradas', 'deposito', 'creditos'],
        'valor' => ['valor', 'montante', 'importancia'],
        'dc' => ['d/c', 'dc', 'tipo', 'sinal'],
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoNumeracao $numeracao,
    ) {}

    /** @return array{lote: string, importadas: int, duplicadas: int, ignoradas: list<string>} */
    public function importar(string $conta, string $ficheiro): array
    {
        $empresa = $this->contexto->obrigatorio();
        $this->exigirContaBanco($conta);
        try {
            $linhas = IOFactory::load($ficheiro)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable $e) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use XLSX, XLS ou CSV).', 'FICHEIRO_INVALIDO', 422);
        }
        [$colunas, $inicio] = $this->cabecalho($linhas);
        $ignoradas = [];
        $novas = [];
        foreach (array_slice($linhas, $inicio + 1, null, true) as $n => $l) {
            $valorCel = fn (string $campo) => isset($colunas[$campo]) ? ($l[$colunas[$campo]] ?? null) : null;
            if (! array_filter($l, fn ($v) => $v !== null && trim((string) $v) !== '')) {
                continue;   // linha vazia
            }
            $data = self::data($valorCel('data'));
            if (! $data) {
                $ignoradas[] = 'Linha '.($n + 1).': data inválida.';

                continue;
            }
            if (isset($colunas['valor'])) {
                $v = self::numero($valorCel('valor'));
                $dc = strtoupper(trim((string) $valorCel('dc')));
                $tipo = in_array($dc, ['D', 'DEB', 'DEBITO'], true) || ($dc === '' && $v < 0) ? 'D' : 'C';
                $valor = abs($v);
            } else {
                $deb = abs(self::numero($valorCel('debito')));
                $cre = abs(self::numero($valorCel('credito')));
                [$tipo, $valor] = $deb > 0 ? ['D', $deb] : ['C', $cre];
            }
            if ($valor <= 0) {
                $ignoradas[] = 'Linha '.($n + 1).': sem valor.';

                continue;
            }
            $novas[] = ['data' => $data, 'referencia' => mb_substr(trim((string) $valorCel('referencia')), 0, 50) ?: null,
                'descricao' => trim((string) $valorCel('descricao')) ?: null, 'valor' => number_format($valor, 2, '.', ''), 'tipo_dc' => $tipo];
        }
        if (! $novas) {
            throw new ErroNegocio('O ficheiro não tem movimentos válidos.', 'EXTRATO_VAZIO', 422, ['ignoradas' => $ignoradas]);
        }

        return DB::transaction(function () use ($novas, $conta, $empresa, $ignoradas) {
            $dia = now()->format('Ymd');
            $lote = sprintf('IMP-%s-%04d', $dia, $this->numeracao->proximo($empresa, "extrato:lote:{$dia}", fn () => 0));
            $duplicadas = 0;
            $importadas = 0;
            foreach ($novas as $l) {
                $existe = LinhaExtratoBancario::query()->where('codigo_conta', $conta)->whereDate('data', $l['data'])->where('valor', $l['valor'])
                    ->where('tipo_dc', $l['tipo_dc'])->where(fn ($q) => $l['referencia'] === null ? $q->whereNull('referencia') : $q->where('referencia', $l['referencia']))
                    ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))->exists();
                if ($existe) {
                    $duplicadas++;

                    continue;
                }
                LinhaExtratoBancario::create($l + ['codigo_conta' => $conta, 'estado' => 'PENDENTE', 'lote_codigo' => $lote]);
                $importadas++;
            }

            return ['lote' => $lote, 'importadas' => $importadas, 'duplicadas' => $duplicadas, 'ignoradas' => $ignoradas];
        });
    }

    /**
     * Sugestões de correspondência 1:1 (não gravam nada).
     *
     * @return list<array{linha_extrato_id: int, lancamento_id: int, valor: string, criterio: string}>
     */
    public function sugerir(string $conta, ?string $inicio = null, ?string $fim = null, int $toleranciaDias = 1): array
    {
        $extrato = LinhaExtratoBancario::query()->where('codigo_conta', $conta)->where('estado', 'PENDENTE')
            ->when($inicio, fn ($q, $v) => $q->where('data', '>=', $v))->when($fim, fn ($q, $v) => $q->where('data', '<=', $v))->orderBy('data')->orderBy('id')->get();
        $diario = $this->porReconciliar($conta, $inicio ? date('Y-m-d', strtotime("{$inicio} -{$toleranciaDias} days")) : null,
            $fim ? date('Y-m-d', strtotime("{$fim} +{$toleranciaDias} days")) : null)
            ->get(['id', 'data_documento', 'valor', 'tipo_dc', 'numero_documento', 'referencia']);

        // índices por (valor, sentido): o extracto (óptica do banco) casa com o diário no sentido oposto
        $chaveE = fn ($e) => number_format((float) $e->valor, 2, '.', '').'|'.($e->tipo_dc === 'C' ? 'D' : 'C');
        $chaveL = fn ($l) => number_format((float) $l->valor, 2, '.', '').'|'.$l->tipo_dc;
        $porChave = [];
        foreach ($diario as $l) {
            $porChave[$chaveL($l)][] = ['id' => $l->id, 'dia' => $l->data_documento->toDateString(), 'ts' => $l->data_documento->getTimestamp(),
                'numero' => $l->numero_documento, 'referencia' => $l->referencia];
        }
        $extratoPorChave = $extrato->groupBy($chaveE);
        $usadosE = $usadosL = [];
        $pares = [];
        $casar = function (callable $criterio, string $nome) use ($extrato, $chaveE, &$porChave, &$usadosE, &$usadosL, &$pares) {
            foreach ($extrato as $e) {
                if (isset($usadosE[$e->id])) {
                    continue;
                }
                $k = $chaveE($e);
                $candidatos = array_values(array_filter($porChave[$k] ?? [], fn ($c) => ! isset($usadosL[$c['id']]) && $criterio($e, $c, $k)));
                if (! $candidatos) {
                    continue;
                }
                // a referência desempata quando há vários candidatos
                $escolhido = $candidatos[0];
                foreach ($candidatos as $c) {
                    if ($e->referencia && ($c['numero'] === $e->referencia || $c['referencia'] === $e->referencia)) {
                        $escolhido = $c;
                        break;
                    }
                }
                $usadosE[$e->id] = $usadosL[$escolhido['id']] = true;
                $pares[] = ['linha_extrato_id' => $e->id, 'lancamento_id' => $escolhido['id'], 'valor' => (string) $e->valor, 'criterio' => $nome];
            }
        };
        $casar(fn ($e, $c) => $c['dia'] === $e->data->toDateString(), 'MESMA_DATA');
        $casar(fn ($e, $c) => abs($c['ts'] - $e->data->getTimestamp()) <= $toleranciaDias * 86400, 'TOLERANCIA_DATA');
        $casar(function ($e, $c, $k) use ($extratoPorChave, &$porChave, &$usadosE, &$usadosL) {
            $livresE = ($extratoPorChave[$k] ?? collect())->filter(fn ($x) => ! isset($usadosE[$x->id]))->count();
            $livresL = count(array_filter($porChave[$k] ?? [], fn ($x) => ! isset($usadosL[$x['id']])));

            return $livresE === 1 && $livresL === 1;
        }, 'VALOR_UNICO');

        return $pares;
    }

    /**
     * Confirma correspondências. Cada grupo = {extrato: ids, lancamentos: ids} com Σ extrato (C+, D−) = Σ diário (D+, C−).
     *
     * @param  list<array{extrato: list<int>, lancamentos: list<int>}>  $grupos
     */
    public function confirmar(string $conta, array $grupos, string $tipo = 'MANUAL'): ReconciliacaoBancaria
    {
        $empresa = $this->contexto->obrigatorio();
        $this->exigirContaBanco($conta);
        if (! $grupos) {
            throw new ErroNegocio('Indique as correspondências a confirmar.', 'SEM_CORRESPONDENCIAS', 422);
        }

        return DB::transaction(function () use ($conta, $grupos, $tipo, $empresa) {
            $dia = now()->format('Ymd');
            $codigo = sprintf('REC-%s-%04d', $dia, $this->numeracao->proximo($empresa, "reconciliacao:{$dia}", fn () => 0));
            $total = '0.00';
            $pendentes = [];
            foreach ($grupos as $g => $grupo) {
                $extrato = LinhaExtratoBancario::query()->whereIn('id', $grupo['extrato'] ?? [])->lockForUpdate()->get();
                $diario = LancamentoContabil::query()->whereIn('id', $grupo['lancamentos'] ?? [])->lockForUpdate()->get();
                if ($extrato->count() !== count(array_unique($grupo['extrato'] ?? [])) || $diario->count() !== count(array_unique($grupo['lancamentos'] ?? []))
                    || $extrato->isEmpty() || $diario->isEmpty()) {
                    throw new ErroNegocio('Grupo '.($g + 1).': linhas inexistentes ou vazias.', 'CORRESPONDENCIA_INVALIDA', 422);
                }
                if ($extrato->contains(fn ($e) => $e->codigo_conta !== $conta || $e->estado !== 'PENDENTE')
                    || $diario->contains(fn ($l) => $l->codigo_conta !== $conta || $l->reconciliacao_codigo || $l->estorno_de_id || $l->estornado_por_id)) {
                    throw new ErroNegocio('Grupo '.($g + 1).': há linhas de outra conta, já reconciliadas ou estornadas.', 'CORRESPONDENCIA_INVALIDA', 422);
                }
                $somaE = $extrato->reduce(fn ($s, $e) => $e->tipo_dc === 'C' ? bcadd($s, (string) $e->valor, 2) : bcsub($s, (string) $e->valor, 2), '0.00');
                $somaL = $diario->reduce(fn ($s, $l) => $l->tipo_dc === 'D' ? bcadd($s, (string) $l->valor, 2) : bcsub($s, (string) $l->valor, 2), '0.00');
                if (bccomp($somaE, $somaL, 2) !== 0) {
                    throw new ErroNegocio('Grupo '.($g + 1).": o extracto soma {$somaE} e o diário {$somaL}.", 'CORRESPONDENCIA_DESEQUILIBRADA', 422,
                        ['extrato' => $somaE, 'diario' => $somaL]);
                }
                $total = bcadd($total, ltrim($somaE, '-'), 2);
                $pendentes[] = [$extrato, $diario];
            }
            $rec = ReconciliacaoBancaria::create(['reconciliacao_codigo' => $codigo, 'data' => now(), 'valor_total' => $total, 'estado' => 'CONCILIADO_BANCO',
                'detalhes' => json_encode(['conta' => $conta, 'grupos' => $grupos, 'tipo' => $tipo], JSON_UNESCAPED_UNICODE)]);
            foreach ($pendentes as [$extrato, $diario]) {
                $umParaUm = $extrato->count() === 1 && $diario->count() === 1;
                foreach ($diario as $l) {
                    CorrespondenciaReconciliacao::create(['reconciliacao_codigo' => $codigo, 'lancamento_contabil_id' => $l->id,
                        'linha_extrato_bancario_id' => $umParaUm ? $extrato->first()->id : null, 'tipo_correspondencia' => $tipo === 'AUTOMATICA' ? 'AUTOMATICA' : 'MANUAL',
                        'valor' => $l->valor, 'data' => now()]);
                    $l->update(['reconciliacao_codigo' => $codigo]);
                }
                foreach ($extrato as $e) {
                    if (! $umParaUm) {
                        CorrespondenciaReconciliacao::create(['reconciliacao_codigo' => $codigo, 'linha_extrato_bancario_id' => $e->id,
                            'tipo_correspondencia' => $tipo === 'AUTOMATICA' ? 'AUTOMATICA' : 'MANUAL', 'valor' => $e->valor, 'data' => now()]);
                    }
                    $e->update(['estado' => 'CONCILIADO', 'reconciliacao_codigo' => $codigo]);
                }
            }

            return $rec;
        });
    }

    public function anular(string $codigo, string $motivo): ReconciliacaoBancaria
    {
        return DB::transaction(function () use ($codigo, $motivo) {
            $rec = ReconciliacaoBancaria::query()->where('reconciliacao_codigo', $codigo)->lockForUpdate()->firstOrFail();
            if ($rec->estado === 'ANULADA') {
                throw new ErroNegocio('A reconciliação já está anulada.', 'JA_ANULADO', 422);
            }
            LancamentoContabil::query()->where('reconciliacao_codigo', $codigo)->update(['reconciliacao_codigo' => null]);
            LinhaExtratoBancario::query()->where('reconciliacao_codigo', $codigo)->update(['estado' => 'PENDENTE', 'reconciliacao_codigo' => null]);
            $detalhes = json_decode((string) $rec->detalhes, true) ?: [];
            $rec->update(['estado' => 'ANULADA', 'detalhes' => json_encode($detalhes + ['anulacao' => ['motivo' => $motivo, 'em' => now()->toIso8601String()]], JSON_UNESCAPED_UNICODE)]);

            return $rec;
        });
    }

    public function anularLinhaExtrato(LinhaExtratoBancario $linha): LinhaExtratoBancario
    {
        if ($linha->estado !== 'PENDENTE') {
            throw new ErroNegocio('Só linhas de extracto por reconciliar podem ser anuladas (anule primeiro a reconciliação).', 'LINHA_RECONCILIADA', 422);
        }
        $linha->update(['estado' => 'ANULADO']);

        return $linha;
    }

    /** Mapa de reconciliação numa data: saldo do diário, pendentes do diário e do extracto. */
    public function mapa(string $conta, string $data): array
    {
        $this->exigirContaBanco($conta);
        $saldo = (string) LancamentoContabil::query()->where('codigo_conta', $conta)->where('data_documento', '<=', $data)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE -valor END), 0) AS s")->value('s');
        $porReconciliar = $this->porReconciliar($conta, null, $data)->get();
        $extrato = LinhaExtratoBancario::query()->where('codigo_conta', $conta)->where('estado', 'PENDENTE')->where('data', '<=', $data)->orderBy('data')->get();
        $soma = fn (Collection $c, string $positivo) => $c->reduce(fn ($s, $x) => $x->tipo_dc === $positivo ? bcadd($s, (string) $x->valor, 2) : bcsub($s, (string) $x->valor, 2), '0.00');
        $diarioLiq = $soma($porReconciliar, 'D');
        $extratoLiq = $soma($extrato, 'C');

        return [
            'conta' => $conta, 'data' => $data, 'saldo_diario' => number_format((float) $saldo, 2, '.', ''),
            // saldo que o banco deve apresentar = diário − movimentos só no diário + movimentos só no extracto
            'saldo_banco_esperado' => bcadd(bcsub(number_format((float) $saldo, 2, '.', ''), $diarioLiq, 2), $extratoLiq, 2),
            'por_reconciliar_diario' => ['total' => $diarioLiq, 'linhas' => $porReconciliar->map->only(['id', 'data_documento', 'numero_lan', 'numero_documento', 'descricao', 'tipo_dc', 'valor'])->values()],
            'por_reconciliar_extrato' => ['total' => $extratoLiq, 'linhas' => $extrato->map->only(['id', 'data', 'referencia', 'descricao', 'tipo_dc', 'valor'])->values()],
        ];
    }

    private function porReconciliar(string $conta, ?string $inicio, ?string $fim)
    {
        return LancamentoContabil::query()->where('codigo_conta', $conta)->whereNull('reconciliacao_codigo')->whereNull('estorno_de_id')->whereNull('estornado_por_id')
            ->when($inicio, fn ($q, $v) => $q->where('data_documento', '>=', $v))->when($fim, fn ($q, $v) => $q->where('data_documento', '<=', $v))
            ->orderBy('data_documento')->orderBy('id');
    }

    private function exigirContaBanco(string $conta): void
    {
        $this->plano->contaDeMovimento($conta);
        if (! str_starts_with($conta, '43')) {
            throw new ErroNegocio('A reconciliação bancária é feita em contas de depósitos bancários (43).', 'CONTA_NAO_BANCARIA', 422);
        }
    }

    /** @return array{0: array<string, int>, 1: int} colunas por campo e índice da linha de cabeçalho */
    private function cabecalho(array $linhas): array
    {
        $norm = fn ($v) => trim(preg_replace('/[^a-z0-9\/ ]/', '', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $v))));
        foreach (array_slice($linhas, 0, 15, true) as $i => $l) {
            $colunas = [];
            foreach ($l as $c => $v) {
                $t = $norm($v);
                foreach (self::ALIASES as $campo => $nomes) {
                    if (! isset($colunas[$campo]) && in_array($t, $nomes, true)) {
                        $colunas[$campo] = $c;
                    }
                }
            }
            if (isset($colunas['data']) && (isset($colunas['valor']) || isset($colunas['debito']) || isset($colunas['credito']))) {
                return [$colunas, $i];
            }
        }
        throw new ErroNegocio('Cabeçalho não reconhecido: são necessárias as colunas Data e Débito/Crédito (ou Valor e D/C).', 'CABECALHO_INVALIDO', 422);
    }

    /** "1.500,50" / "1,500.50" / "-1500" / número → float */
    public static function numero(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = preg_replace('/[^\d,.\-]/', '', (string) $v);
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif (str_contains($s, ',')) {
            $s = str_replace(',', '.', $s);
        }

        return (float) $s;
    }

    public static function data(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return DataExcel::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        $s = trim(explode(' ', trim((string) $v))[0]);   // ignora a hora
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y', 'Y/m/d', 'd/m/y'] as $f) {
            $d = \DateTime::createFromFormat("!{$f}", $s);
            $erros = \DateTime::getLastErrors();
            if ($d && ($erros === false || ($erros['warning_count'] === 0 && $erros['error_count'] === 0))) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }
}
