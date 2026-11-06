<?php

namespace App\Services\POS;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel (.xlsx) dos relatórios do POS (F7): o painel de ServicoRelatoriosPOS::painel em 7 folhas (Resumo, Por meio, Fechos Z,
 * Por produto, Por terminal, Por operador e Diferenças TPA). Os valores monetários e as quantidades seguem como números
 * (somam no Excel) e o texto que começa por =, +, - ou @ é gravado como texto (nunca como fórmula).
 */
final class ServicoExportacaoRelatoriosPOS
{
    /**
     * @param  array<string, mixed>  $p  saída de ServicoRelatoriosPOS::painel
     * @return array{0: string, 1: string} caminho temporário e nome do ficheiro
     */
    public function relatorios(array $p, string $periodo, string $terminal): array
    {
        $k = $p['kpis'];
        $livro = new Spreadsheet;
        $this->folha($livro, 'Resumo', [['Relatórios POS', $periodo], ['Terminal', $terminal], [],
            ['Facturação POS', $this->n($k['facturacao'])], ['Documentos', (int) $k['documentos']], ['Ticket médio', $this->n($k['ticket_medio'])],
            ['Desvios de caixa', $this->n($k['desvios'])], ['Sessões abertas', (int) $k['sessoes_abertas']], ['Desvios por deliberar', (int) $k['desvios_por_deliberar']],
            ['Sessões por integrar', (int) $k['sessoes_por_integrar']], ['Sessões por prestar', (int) $k['sessoes_por_prestar']], ['Diferenças de TPA', (int) $k['diferencas_tpa']]], false);
        $this->folha($livro, 'Por meio', [['Meio', 'Natureza', 'Operações', 'Valor'],
            ...array_map(fn ($x) => [$x['nome'], $x['tipo'] ?? 'Sem detalhe', (int) $x['quantidade'], $this->n($x['valor'])], $p['por_meio'])]);
        $this->folha($livro, 'Fechos Z', [['Z', 'Sessão', 'Terminal', 'Operador', 'Abertura', 'Fecho', 'Vendas', 'Total', 'Esperado', 'Contado', 'Desvio', 'Estado do desvio', 'Integração', 'Prestação'],
            ...array_map(fn ($z) => [$z->numero_z ?? null, $z->codigo_sessao ?? null, $z->codigo_terminal ?? null, $z->nome_operador ?? null, $this->data($z->aberto_em ?? null), $this->data($z->fechado_em ?? null),
                (int) ($z->numero_vendas ?? 0), $this->n($z->total_vendas ?? null), $this->n($z->numerario_esperado ?? null), $this->n($z->numerario_contado ?? null), $this->n($z->desvio ?? null),
                $z->estado_desvio ?? null, $z->estado_contabilizacao ?? null, $z->estado_liquidacao ?? null], $p['zs'])]);
        $this->folha($livro, 'Por produto', [['Código', 'Produto', 'Quantidade', 'Base', 'Total'],
            ...array_map(fn ($x) => [$x['codigo'], $x['nome'], $this->n($x['quantidade']), $this->n($x['total_liquido']), $this->n($x['total_bruto'])], $p['por_produto'])]);
        $this->folha($livro, 'Por terminal', [['Terminal', 'Nome', 'Documentos', 'Total'],
            ...array_map(fn ($x) => [$x['codigo_terminal'], $x['nome_terminal'], (int) $x['documentos'], $this->n($x['total'])], $p['por_terminal'])]);
        $this->folha($livro, 'Por operador', [['Operador', 'Documentos', 'Total'], ...array_map(fn ($x) => [$x['operador'] ?? '—', (int) $x['documentos'], $this->n($x['total'])], $p['por_operador'])]);
        $this->folha($livro, 'Diferenças TPA', [['Z', 'Meio', 'Terminal TPA', 'Lote', 'Valor do sistema', 'Valor do talão', 'Diferença', 'Sem talão'],
            ...array_map(fn ($x) => [$x['numero_z'], $x['nome'], $x['codigo_tpa'], $x['referencia_lote'], $this->n($x['valor_sistema']), $this->n($x['valor_talao']), $this->n($x['diferenca']),
                ! empty($x['sem_talao']) ? 'Sim' : 'Não'], $p['diferencas_tpa'])]);
        $livro->setActiveSheetIndex(0);
        $sufixo = preg_replace('/[^0-9_-]+/', '', str_replace([' a ', '/'], ['_', '-'], $periodo)) ?: 'todos';

        return [$this->guardar($livro, 'relatorios_pos'), 'Relatorios_POS_'.($sufixo !== '' ? $sufixo : 'todos').'.xlsx'];
    }

    private function n(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }

    private function data(mixed $v): ?string
    {
        return $v ? substr((string) $v, 0, 16) : null;
    }

    /** @param  list<list<mixed>>  $linhas */
    private function folha(Spreadsheet $livro, string $titulo, array $linhas, bool $comCabecalho = true): void
    {
        $f = $livro->getSheetCount() === 1 && $livro->getActiveSheet()->getTitle() === 'Worksheet' ? $livro->getActiveSheet()->setTitle($titulo) : $livro->createSheet()->setTitle($titulo);
        foreach ($linhas as $i => $linha) {
            foreach (array_values($linha) as $j => $valor) {
                $celula = Coordinate::stringFromColumnIndex($j + 1).($i + 1);
                if (is_string($valor)) {
                    // texto nunca é fórmula (injecção em folhas de cálculo): =, +, -, @ no início ficam como texto
                    $f->setCellValueExplicit($celula, $valor, DataType::TYPE_STRING);
                } elseif ($valor !== null) {
                    $f->setCellValue($celula, $valor);
                }
            }
        }
        $comCabecalho ? $this->cabecalho($f, count($linhas[0] ?? [])) : $this->colunas($f, 2);
    }

    private function colunas(Worksheet $f, int $n): void
    {
        for ($c = 1; $c <= $n; $c++) {
            $f->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }

    private function cabecalho(Worksheet $f, int $colunas): void
    {
        $this->colunas($f, max(1, $colunas));
        for ($c = 1; $c <= max(1, $colunas); $c++) {
            $letra = Coordinate::stringFromColumnIndex($c);
            $f->getStyle("{$letra}1")->getFont()->setBold(true);
            $f->getStyle("{$letra}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        }
    }

    private function guardar(Spreadsheet $livro, string $prefixo): string
    {
        File::ensureDirectoryExists(storage_path('app/tmp'));
        $caminho = storage_path('app/tmp/'.$prefixo.'_'.Str::random(10).'.xlsx');
        (new Xlsx($livro))->save($caminho);

        return $caminho;
    }
}
