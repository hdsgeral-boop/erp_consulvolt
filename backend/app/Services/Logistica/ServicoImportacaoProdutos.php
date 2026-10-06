<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\CategoriaProduto;
use App\Models\Produto;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Importação de produtos e categorias por Excel (M-07; openImportModal('produtos'|'categorias'), js/ui_sales.js:5428-5570,
 * modelos Template_Produtos.xlsx — 12 colunas — e Template_Categorias.xlsx). Correcções face ao legado:
 *   - cada produto passa por ServicoProdutos::guardar (código único, contas de movimento, dados AGT por omissão);
 *   - taxa de IVA só das legais (0, 5, 7, 14 — config erp.fiscal.taxas_iva);
 *   - simulação por omissão (nada é gravado) e erros por linha; os códigos já existentes são actualizados só a pedido;
 *   - a coluna Stock_Atual NÃO altera o stock (o stock só muda por movimentos com custo — Armazém › Ajustes); fica um aviso;
 *   - a categoria indicada é criada se não existir (como o legado), sem duplicar por maiúsculas/minúsculas.
 */
final class ServicoImportacaoProdutos
{
    public const PRODUTOS = ['Codigo', 'Nome', 'Preco_Unitario', 'Taxa_IVA', 'Categoria', 'Stock_Atual', 'Gerir_Stock',
        'Conta_Venda_Receita', 'Conta_Custo', 'Conta_IVA', 'Conta_Compras', 'Conta_Existencias'];

    private const INSTRUCOES_PRODUTOS = [
        ['Codigo', 'Código de referência do produto (único na empresa)', 'Ex: P001'],
        ['Nome', 'Designação do produto ou serviço (obrigatório)', 'Ex: Água Mineral 1.5L'],
        ['Preco_Unitario', 'Preço de venda base (sem IVA)', 'Ex: 500.00'],
        ['Taxa_IVA', 'Percentagem do IVA (0, 5, 7, 14)', 'Ex: 14'],
        ['Categoria', 'Nome da categoria (criada se não existir)', 'Ex: Bebidas'],
        ['Stock_Atual', 'Informativo: o stock inicial regista-se em Armazém › Ajustes, com o custo', 'Ex: 100'],
        ['Gerir_Stock', 'Controlo de stock (SIM/NAO)', 'Ex: SIM'],
        ['Conta_Venda_Receita', 'Conta de vendas/proveitos (classe 61 ou 62)', 'Ex: 6111'],
        ['Conta_Custo', 'Conta de custo (classe 71 ou 75)', 'Ex: 7111'],
        ['Conta_IVA', 'Conta de IVA liquidado (classe 34)', 'Ex: 3453'],
        ['Conta_Compras', 'Conta de compras (classe 21, para stock)', 'Ex: 2111'],
        ['Conta_Existencias', 'Conta de existências/inventário (classe 26, para stock)', 'Ex: 2611'],
    ];

    public function __construct(private readonly ServicoProdutos $produtos) {}

    /** Modelo .xlsx (folha de dados + instruções), como createTemplateWithInstructions do legado. */
    public function modelo(string $entidade): string
    {
        $livro = new Spreadsheet;
        $dados = $livro->getActiveSheet()->setTitle($entidade === 'categorias' ? 'Categorias' : 'Produtos');
        if ($entidade === 'categorias') {
            $dados->fromArray([['Nome'], ['Bebidas'], ['Serviços']]);
            $instrucoes = [['Nome', 'Nome da categoria de produtos', 'Ex: Bebidas, Limpeza, Serviços']];
        } else {
            $dados->fromArray([self::PRODUTOS, ['P001', 'Água Mineral 1.5L', '500.00', '14', 'Bebidas', '0', 'SIM', '6111', '7111', '3453', '2111', '2611']]);
            $instrucoes = self::INSTRUCOES_PRODUTOS;
        }
        $dados->getStyle('A1:L1')->getFont()->setBold(true);
        $instr = $livro->createSheet()->setTitle('Instruções');
        $instr->fromArray([['Coluna', 'Descrição', 'Exemplo'], ...$instrucoes]);
        $instr->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (['A', 'B', 'C'] as $c) {
            $instr->getColumnDimension($c)->setAutoSize(true);
        }
        $livro->setActiveSheetIndex(0);
        $caminho = tempnam(sys_get_temp_dir(), 'modelo_prod').'.xlsx';
        (new Xlsx($livro))->save($caminho);

        return $caminho;
    }

    /**
     * @return array{simulacao: bool, linhas_lidas: int, criados: int, actualizados: int, erros: list<array{linha: int, mensagem: string}>, avisos: list<string>}
     */
    public function importar(string $entidade, string $ficheiro, bool $simular = true, bool $actualizarExistentes = false): array
    {
        try {
            $linhas = IOFactory::load($ficheiro)->getSheet(0)->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use o modelo .xlsx).', 'FICHEIRO_INVALIDO', 422);
        }
        if (count($linhas) < 2) {
            throw new ErroNegocio('O ficheiro está vazio.', 'FICHEIRO_VAZIO', 422);
        }
        $norm = fn ($v) => mb_strtolower(trim(str_replace([' ', '-'], '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $v))));
        $col = [];
        foreach ($linhas[0] as $i => $v) {
            $col[$norm($v)] ??= $i;
        }
        if (! isset($col['nome'])) {
            throw new ErroNegocio('Coluna obrigatória em falta: «Nome». Use o modelo de importação.', 'CABECALHO_INVALIDO', 422);
        }
        $res = ['simulacao' => $simular, 'linhas_lidas' => 0, 'criados' => 0, 'actualizados' => 0, 'erros' => [], 'avisos' => []];

        DB::beginTransaction();
        try {
            foreach (array_slice($linhas, 1, null, true) as $i => $l) {
                if (! array_filter($l, fn ($v) => $v !== null && trim((string) $v) !== '')) {
                    continue;
                }
                $res['linhas_lidas']++;
                $n = $i + 1;
                $v = fn (string $nome) => isset($col[$nome]) ? trim((string) ($l[$col[$nome]] ?? '')) : '';
                try {
                    DB::transaction(function () use ($entidade, $v, $n, $actualizarExistentes, &$res) {
                        $entidade === 'categorias' ? $this->categoria($v('nome'), $res) : $this->produto($v, $n, $actualizarExistentes, $res);
                    });
                } catch (ErroNegocio $e) {
                    $res['erros'][] = ['linha' => $n, 'mensagem' => "Linha {$n}: {$e->getMessage()}"];
                }
            }
            if ($simular) {
                DB::rollBack();
            } else {
                DB::commit();
                app(ServicoAuditoria::class)->registar('Vendas/Produtos', $entidade === 'categorias' ? 'Importação de categorias' : 'Importação de produtos',
                    "{$res['criados']} criado(s), {$res['actualizados']} actualizado(s), ".count($res['erros']).' com erro.', $entidade === 'categorias' ? 'categorias_produtos' : 'produtos');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $res;
    }

    private function categoria(string $nome, array &$res): CategoriaProduto
    {
        if ($nome === '') {
            throw new ErroNegocio('o nome da categoria é obrigatório.', 'LINHA_INVALIDA', 422);
        }
        $existente = CategoriaProduto::query()->whereRaw('lower(nome) = lower(?)', [$nome])->first();
        if ($existente) {
            return $existente;
        }
        $res['criados']++;

        return $this->produtos->guardarCategoria(['nome' => $nome]);
    }

    private function produto(callable $v, int $n, bool $actualizar, array &$res): void
    {
        $nome = $v('nome');
        if ($nome === '') {
            throw new ErroNegocio('o nome é obrigatório.', 'LINHA_INVALIDA', 422);
        }
        $codigo = $v('codigo');
        $taxa = $v('taxa_iva') === '' ? null : (float) str_replace(',', '.', $v('taxa_iva'));
        $legais = array_map('floatval', (array) config('erp.fiscal.taxas_iva', [0, 5, 7, 14]));
        if ($taxa !== null && ! in_array(round($taxa, 4), $legais, true)) {
            throw new ErroNegocio("taxa de IVA {$v('taxa_iva')} inválida (taxas legais: ".implode(', ', $legais).').', 'LINHA_INVALIDA', 422);
        }
        $preco = $v('preco_unitario') === '' ? null : (float) str_replace(',', '.', $v('preco_unitario'));
        if ($preco !== null && $preco < 0) {
            throw new ErroNegocio('o preço não pode ser negativo.', 'LINHA_INVALIDA', 422);
        }
        $stock = in_array(mb_strtoupper($v('gerir_stock')), ['SIM', 'S', '1', 'TRUE', 'YES'], true);
        $conta = fn (string $c) => ($x = str_replace(['.', ' '], '', $v($c))) !== '' ? $x : null;
        $dados = array_filter([
            'codigo' => $codigo ?: null, 'nome' => mb_substr($nome, 0, 255), 'preco_unitario' => $preco, 'taxa_imposto' => $taxa,
            'categoria_produto_id' => $v('categoria') !== '' ? $this->categoria($v('categoria'), $res)->id : null,
            'codigo_conta' => $conta('conta_venda_receita'), 'conta_custo' => $conta('conta_custo'), 'conta_iva_liquidado' => $conta('conta_iva'),
            'conta_compra' => $conta('conta_compras'), 'conta_inventario' => $conta('conta_existencias'),
        ], fn ($x) => $x !== null) + ['movimenta_stock' => $stock];
        if ((float) str_replace(',', '.', $v('stock_atual') ?: '0') > 0) {
            $res['avisos'][] = "Linha {$n}: Stock_Atual ignorado — registe o stock inicial em Armazém › Ajustes, com o custo.";
        }
        $existente = $codigo !== '' ? Produto::query()->where('codigo', $codigo)->first() : null;
        if ($existente && ! $actualizar) {
            throw new ErroNegocio("já existe o produto com o código {$codigo} (escolha «Actualizar existentes» para o alterar).", 'PRODUTO_DUPLICADO', 422);
        }
        $this->produtos->guardar($dados, $existente);
        $existente ? $res['actualizados']++ : $res['criados']++;
    }
}
