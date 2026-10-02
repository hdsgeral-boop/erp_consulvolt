<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\SaldoHistorico;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Saldos históricos de um exercício sem lançamentos detalhados ("Importar histórico", js/ui_lancamentos.js:1186-1459):
 * valores por nota DEMO (Balanço/DR) e FLUXO, usados como comparativo do ano seguinte (ServicoDemonstracoesFinanceiras).
 * Regras (paridade): gravar substitui todo o histórico do ano; o resultado líquido (res_liq) é calculado
 * ((Σ22..26 − Σ27..30) + Σ31..34 − 35) e gravado; exercício encerrado = só leitura; códigos de fluxo permitidos
 * (js/ui_lancamentos.js:1206); importação Excel com colunas TIPO, CÓDIGO, VALOR (só preenche — não grava).
 * Correcções: os códigos DEMO têm de existir nas notas da empresa (o legado gravava qualquer código vindo do formulário);
 * valores em decimal exacto; tudo numa transacção.
 */
final class ServicoSaldosHistoricos
{
    public const CODIGOS_FLUXO = ['111', '112', '113', '12', '13', '141', '142', '151', '152', '211', '212', '213', '214', '215', '216', '221', '222',
        '311', '312', '313', '314', '321', '322', '323', '324', '325', '326', '327', '328', 'tot2', 'caixainicio'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
    ) {}

    public function obter(int $ano): array
    {
        $registos = SaldoHistorico::query()->where('ano', $ano)->orderBy('id')->get();
        $valores = ['demo' => [], 'fluxo' => []];
        foreach ($registos as $r) {
            $valores[$r->tipo === 'FLUXO_CAIXA' ? 'fluxo' : 'demo'][trim((string) $r->codigo)] = FiltroMapas::dinheiro($r->valor);
        }
        $demo = NotaDemonstracao::query()->orderBy('codigo')->get(['codigo', 'descricao'])->unique(fn ($n) => trim((string) $n->codigo))
            ->map(fn ($n) => ['codigo' => trim((string) $n->codigo), 'descricao' => $n->descricao, 'valor' => $valores['demo'][trim((string) $n->codigo)] ?? null])->values()->all();
        $fluxo = NotaFluxoCaixa::query()->orderBy('codigo')->get(['codigo', 'descricao'])
            ->filter(fn ($n) => in_array(mb_strtolower(trim((string) $n->codigo)), self::CODIGOS_FLUXO, true))->unique(fn ($n) => trim((string) $n->codigo))
            ->map(fn ($n) => ['codigo' => trim((string) $n->codigo), 'descricao' => $n->descricao, 'valor' => $valores['fluxo'][trim((string) $n->codigo)] ?? null])->values()->all();

        return ['ano' => $ano, 'encerrado' => $this->exercicios->encerrado($this->contexto->obrigatorio(), $ano), 'demo' => $demo, 'fluxo' => $fluxo,
            'res_liq' => $valores['demo']['res_liq'] ?? null, 'registos' => $valores];
    }

    /**
     * @param  array<string, numeric-string|float|int|null>  $demo  código => valor
     * @param  array<string, numeric-string|float|int|null>  $fluxo
     */
    public function gravar(int $ano, array $demo, array $fluxo): array
    {
        $empresa = $this->contexto->obrigatorio();
        if ($this->exercicios->encerrado($empresa, $ano)) {
            throw new ErroNegocio("O exercício de {$ano} está encerrado: não é possível alterar o histórico.", 'EXERCICIO_ENCERRADO', 422);
        }
        $notas = NotaDemonstracao::query()->pluck('codigo')->map(fn ($c) => trim((string) $c))->flip();
        $linhas = [];
        $dr = [];
        foreach ($demo as $codigo => $valor) {
            $codigo = trim((string) $codigo);
            if ($codigo === 'res_liq' || $valor === null || $valor === '') {
                continue;
            }
            if (! isset($notas[$codigo])) {
                throw new ErroNegocio("A nota {$codigo} não existe nas notas às demonstrações desta empresa.", 'NOTA_INEXISTENTE', 422, ['codigo' => $codigo]);
            }
            $v = $this->valor($valor, $codigo);
            $linhas[] = ['tipo' => 'DEMONSTRACAO_RESULTADOS', 'tipo_original' => 'DEMO', 'codigo' => $codigo, 'valor' => $v];
            $dr[$codigo] = $v;
        }
        foreach ($fluxo as $codigo => $valor) {
            $codigo = trim((string) $codigo);
            if ($valor === null || $valor === '') {
                continue;
            }
            if (! in_array(mb_strtolower($codigo), self::CODIGOS_FLUXO, true)) {
                throw new ErroNegocio("O código de fluxo {$codigo} não é permitido no histórico.", 'CODIGO_FLUXO_INVALIDO', 422, ['codigo' => $codigo]);
            }
            $linhas[] = ['tipo' => 'FLUXO_CAIXA', 'tipo_original' => 'FLUXO', 'codigo' => $codigo, 'valor' => $this->valor($valor, $codigo)];
        }
        $resLiq = null;
        if ($dr) {
            $soma = fn (array $ks) => array_reduce($ks, fn ($s, $k) => bcadd($s, $dr[$k] ?? '0.00', 2), '0.00');
            // js/ui_lancamentos.js:1260-1266 (o 34 entra com os financeiros/extraordinários)
            $resLiq = bcsub(bcadd(bcsub($soma(['22', '23', '24', '25', '26']), $soma(['27', '28', '29', '30']), 2), $soma(['31', '32', '33', '34']), 2), $dr['35'] ?? '0.00', 2);
            $linhas[] = ['tipo' => 'DEMONSTRACAO_RESULTADOS', 'tipo_original' => 'DEMO', 'codigo' => 'res_liq', 'valor' => $resLiq];
        }
        DB::transaction(function () use ($ano, $linhas) {
            SaldoHistorico::query()->where('ano', $ano)->lockForUpdate()->get()->each->delete();
            foreach ($linhas as $l) {
                SaldoHistorico::create($l + ['ano' => $ano]);
            }
        });

        return ['ano' => $ano, 'registos' => count($linhas), 'res_liq' => $resLiq];
    }

    /** Lê TIPO, CÓDIGO, VALOR (handleHistoryExcel, js/ui_lancamentos.js:1371-1407) — devolve os valores, não grava. */
    public function lerFicheiro(string $ficheiro): array
    {
        try {
            $folha = IOFactory::load($ficheiro)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use XLSX, XLS ou CSV).', 'FICHEIRO_INVALIDO', 422);
        }
        $cab = array_map([ServicoTabelasAuxiliares::class, 'chave'], array_shift($folha) ?? []);
        [$iTipo, $iCodigo, $iValor] = [array_search('tipo', $cab, true), array_search('codigo', $cab, true), array_search('valor', $cab, true)];
        if ($iTipo === false || $iCodigo === false || $iValor === false) {
            throw new ErroNegocio('O ficheiro tem de ter as colunas TIPO, CÓDIGO e VALOR.', 'FICHEIRO_INVALIDO', 422);
        }
        $r = ['demo' => [], 'fluxo' => [], 'ignoradas' => 0];
        foreach ($folha as $row) {
            $tipo = mb_strtoupper(trim((string) ($row[$iTipo] ?? '')));
            $codigo = trim((string) ($row[$iCodigo] ?? ''));
            if ($codigo === '' || ! in_array($tipo, ['DEMO', 'FLUXO'], true)) {
                $r['ignoradas']++;

                continue;
            }
            $v = $row[$iValor] ?? null;
            $r[$tipo === 'DEMO' ? 'demo' : 'fluxo'][$codigo] = is_numeric($v) ? number_format(round((float) $v, 2), 2, '.', '') : '0.00';
        }

        return $r;
    }

    /**
     * Modelo Excel (downloadHistoryTemplate, js/ui_lancamentos.js:1356-1369): TIPO, CÓDIGO, VALOR com uma linha por nota
     * DEMO e por nota de fluxo permitida, já com os valores gravados do ano (serve também de exportação para rever e
     * reimportar). A coluna DESCRIÇÃO é só informativa: lerFicheiro() procura as colunas pelo cabeçalho e ignora-a.
     * O resultado líquido (res_liq) não entra: é sempre calculado ao gravar.
     */
    public function modeloExcel(int $ano, string $caminho): void
    {
        $dados = $this->obter($ano);
        $linhas = [['TIPO', 'CÓDIGO', 'DESCRIÇÃO', 'VALOR']];
        foreach ([['DEMO', $dados['demo']], ['FLUXO', $dados['fluxo']]] as [$tipo, $notas]) {
            foreach ($notas as $n) {
                if ($n['codigo'] !== 'res_liq') {
                    $linhas[] = [$tipo, $n['codigo'], (string) $n['descricao'], $n['valor'] !== null ? (float) $n['valor'] : null];
                }
            }
        }
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle('Historico');
        // códigos como texto (ex.: «12» e «012» são notas diferentes) e valores numéricos
        foreach ($linhas as $i => $l) {
            foreach ($l as $j => $v) {
                $celula = [$j + 1, $i + 1];
                $j === 1 && $i > 0 ? $folha->setCellValueExplicit($celula, (string) $v, DataType::TYPE_STRING) : $folha->setCellValue($celula, $v);
            }
        }
        $folha->getStyle('D2:D'.count($linhas))->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (['A' => 10, 'B' => 12, 'C' => 60, 'D' => 18] as $col => $largura) {
            $folha->getColumnDimension($col)->setWidth($largura);
        }
        (new Xlsx($livro))->save($caminho);
    }

    private function valor(mixed $v, string $codigo): string
    {
        if (! is_numeric($v)) {
            throw new ErroNegocio("Valor inválido no código {$codigo}.", 'VALOR_INVALIDO', 422);
        }

        return number_format(round((float) $v, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }
}
