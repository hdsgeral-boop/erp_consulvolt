<?php

namespace App\Services\POS\Lavandaria;

use App\Exceptions\ErroNegocio;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Ficheiros Excel da lavandaria (legado: lavRelatorioExcel e lavModeloImportacao, js/lavandaria.js:2565-2660):
 *   - relatório do período em 7 folhas (Resumo, Documentos, Meios de pagamento, Serviços, Peças, Terminais, Diário);
 *   - modelos .xlsx de importação (peças, serviços, preços por peça e serviço) com as colunas nomeadas do legado e uma folha «Como_Preencher».
 * Os valores monetários e as quantidades seguem como números (não texto) para somar no Excel.
 */
final class ServicoExportacaoLavandaria
{
    /** Modelos: ficheiro, folha, colunas e linhas de exemplo (MODELOS, lavandaria.js:2606-2627, com a opção de preço da F5). */
    private const MODELOS = [
        'pecas' => ['ficheiro' => 'Modelo_Pecas_Lavandaria.xlsx', 'folha' => 'Peças',
            'colunas' => ['Código (vazio = novo)', 'Designação *', 'Tipo de tecido', 'Cor', 'Venda por (Peça/Kg) *', 'Preço IVA incl. (por peça ou kg) *', 'Activa (Sim/Não)'],
            'exemplos' => [['', 'Camisa', 'Algodão', 'Branco', 'Peça', 1500, 'Sim'], ['', 'Roupa diversa', 'Misto', 'Várias', 'Kg', 800, 'Sim']],
            'ajuda' => [['Código', 'Vazio = peça nova (código PC#### automático); com código actualiza essa peça'], ['Designação', 'Obrigatória'],
                ['Venda por', 'Peça ou Kg: define a 1.ª opção de preço da peça'], ['Preço', 'Preço COM IVA da 1.ª opção'],
                ['Outras opções', 'Os tamanhos e as restantes opções acrescentam-se no editor da peça («+ Opção») ou, para o preço por serviço, no modelo de preços (coluna Opção)']]],
        'servicos' => ['ficheiro' => 'Modelo_Servicos_Lavandaria.xlsx', 'folha' => 'Serviços',
            'colunas' => ['Código (vazio = novo)', 'Designação *', 'Grupo (Lavandaria/Alfaiataria) *', 'Conta de proveitos (62) *', 'IVA (%)', 'Prazo (dias)', 'Exige orçamento (Sim/Não)', 'Activo (Sim/Não)'],
            'exemplos' => [['', 'Lavar e engomar', 'Lavandaria', '6221', 14, 2, 'Não', 'Sim'], ['', 'Bainha de calças', 'Alfaiataria', '6221', 14, 3, 'Não', 'Sim']],
            'ajuda' => [['Código', 'Vazio = serviço novo (código SV#### automático)'], ['Conta de proveitos', 'Conta de movimento da classe 62 do plano de contas'],
                ['IVA', '0, 5, 7 ou 14'], ['Prazo', 'Dias para a conclusão']]],
        'precos' => ['ficheiro' => 'Modelo_Precos_Peca_Servico.xlsx', 'folha' => 'Preços',
            'colunas' => ['Código da peça *', 'Código do serviço *', 'Preço IVA incl. (0 = usar o preço base)', 'Opção (Peça/Kg/tamanho — vazio = a 1.ª)'],
            'exemplos' => [['PC0001', 'SV0001', 1500, ''], ['PC0001', 'SV0001', 1800, 'M'], ['PC0001', 'SV0002', 2500, 'Kg']],
            'ajuda' => [['Preço', '0 remove o preço específico: a peça usa o preço da própria opção'],
                ['Opção', 'Peça, Kg, «Tamanho M» ou só o nome do tamanho (M, Casal…); vazia = a 1.ª opção da peça']]],
    ];

    /** Caminho temporário do modelo .xlsx do tipo (apagado depois do envio). */
    public function modelo(string $tipo): array
    {
        $m = self::MODELOS[$tipo] ?? throw new ErroNegocio('Tipo de modelo inválido (pecas, servicos ou precos).', 'VALIDACAO', 422);
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle($m['folha']);
        $folha->fromArray([$m['colunas'], ...$m['exemplos']]);
        $this->cabecalho($folha, count($m['colunas']));
        $ajuda = $livro->createSheet()->setTitle('Como_Preencher');
        $ajuda->fromArray([['Campo', 'O que preencher'], ...$m['ajuda']]);
        $this->cabecalho($ajuda, 2);
        $livro->setActiveSheetIndex(0);

        return [$this->guardar($livro, 'modelo_lavandaria'), $m['ficheiro']];
    }

    /**
     * Relatório do período (a saída de ServicoRelatorioLavandaria::gerar) em 7 folhas.
     *
     * @param  array<string, mixed>  $r
     * @param  string  $terminal  designação do terminal filtrado («Todos»)
     */
    public function relatorio(array $r, string $terminal = 'Todos'): array
    {
        $s = $r['resumo'];
        $n = fn ($v) => $v === null || $v === '' ? null : (float) $v;
        $livro = new Spreadsheet;
        $this->folha($livro, 'Resumo', [['Relatório da lavandaria', "{$r['periodo']['de']} a {$r['periodo']['ate']}"], ['Terminal', $terminal], [],
            ['Ordens recebidas', $s['recebidas']], ['Peças recebidas', $s['etiquetas']], ['Valor das ordens recebidas', $n($s['valor_recebidas'])], ['Ordens entregues', $s['entregues']],
            ['Peças entregues', $s['pecas_entregues']], ['Facturas (n.º)', $s['faturas_numero']], ['Facturas (valor)', $n($s['faturas_valor'])],
            ['Facturas-Recibo (n.º)', $s['faturas_recibo_numero']], ['Facturas-Recibo (valor)', $n($s['faturas_recibo_valor'])], ['Recibos (n.º)', $s['recibos_numero']],
            ['Recibos (valor)', $n($s['recibos_valor'])], ['Base tributável', $n($s['base'])], ['IVA', $n($s['iva'])], ['Total facturado', $n($s['facturado'])],
            ['Total recebido', $n($s['recebido'])], ['Documentos anulados', $s['anulados']], ['Concluídas no período', $s['concluidas']],
            ['% no prazo', $s['percentagem_no_prazo']], ['Danos registados', $s['danos']], ['Saldo em aberto (actual)', $n($s['saldo_aberto'])],
            ['Por facturar (actual)', $n($s['por_facturar'])]], false);
        $this->folha($livro, 'Documentos', [['Data', 'Documento', 'Tipo', 'Ordem', 'Cliente', 'Terminal', 'Base', 'IVA', 'Total', 'Estado'],
            ...array_map(fn ($x) => [$x['data'], $x['numero'], $x['tipo'], $x['ordem'], $x['cliente'], $x['terminal'], $n($x['base']), $n($x['iva']), $n($x['total']), $x['estado']], $this->lista($r['documentos']))]);
        $this->folha($livro, 'Meios de pagamento', [['Meio', 'N.º', 'Valor'], ...array_map(fn ($x) => [$x['meio'], $x['numero'], $n($x['total'])], $this->lista($r['por_meio']))]);
        $this->folha($livro, 'Serviços', [['Serviço', 'Grupo', 'Quantidade', 'Total'], ...array_map(fn ($x) => [$x['servico'], $x['grupo'], $n($x['quantidade']), $n($x['total'])], $this->lista($r['por_servico']))]);
        $this->folha($livro, 'Peças', [['Peça', 'Unidade', 'Quantidade', 'Etiquetas', 'Valor'], ...array_map(fn ($x) => [$x['peca'], $x['unidade'], $n($x['quantidade']), $x['etiquetas'], $n($x['valor'])], $this->lista($r['por_peca']))]);
        $this->folha($livro, 'Terminais', [['Terminal', 'OS recebidas', 'Documentos', 'Facturado', 'Recebido'],
            ...array_map(fn ($x) => [$x['terminal'], $x['ordens'], $x['documentos'], $n($x['facturado']), $n($x['recebido'])], $this->lista($r['por_terminal']))]);
        $this->folha($livro, 'Diário', [['Dia', 'OS recebidas', 'Peças recebidas', 'Peças entregues', 'Documentos', 'Facturado', 'Recebido'],
            ...array_map(fn ($x) => [$x['dia'], $x['recebidas'], $x['etiquetas'], $x['entregues'], $x['documentos'], $n($x['facturado']), $n($x['recebido'])], $this->lista($r['por_dia']))]);
        $livro->setActiveSheetIndex(0);

        return [$this->guardar($livro, 'relatorio_lavandaria'), "Relatorio_Lavandaria_{$r['periodo']['de']}_{$r['periodo']['ate']}.xlsx"];
    }

    /** @return list<array<string, mixed>> */
    private function lista(mixed $l): array
    {
        return array_values(is_array($l) ? $l : (method_exists($l, 'all') ? $l->all() : []));
    }

    private function folha(Spreadsheet $livro, string $titulo, array $linhas, bool $comCabecalho = true): void
    {
        $f = $livro->getSheetCount() === 1 && $livro->getActiveSheet()->getTitle() === 'Worksheet' ? $livro->getActiveSheet()->setTitle($titulo) : $livro->createSheet()->setTitle($titulo);
        $f->fromArray($linhas, null, 'A1', true);
        $comCabecalho ? $this->cabecalho($f, count($linhas[0] ?? [])) : $f->getColumnDimension('A')->setAutoSize(true);
        if (! $comCabecalho) {
            $f->getColumnDimension('B')->setAutoSize(true);
        }
    }

    private function cabecalho(Worksheet $f, int $colunas): void
    {
        for ($c = 1; $c <= max(1, $colunas); $c++) {
            $letra = Coordinate::stringFromColumnIndex($c);
            $f->getColumnDimension($letra)->setAutoSize(true);
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
