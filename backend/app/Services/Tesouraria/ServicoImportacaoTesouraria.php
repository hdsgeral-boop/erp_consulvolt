<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\CentroCusto;
use App\Models\FaturaCompra;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Importação de documentos de tesouraria por Excel (A-12; processtreasuryImport, js/ui_tesouraria.js:2566, e o modelo
 * downloadtreasuryTemplate, js/app_v2.js:10259 — «Template_Importação_Tesouraria»). No legado, 5 781 documentos foram
 * carregados assim. Mantém-se o mesmo modelo (colunas e sinónimos) e o agrupamento das linhas por
 * Data + Tipo + Referência + Conta de disponibilidades, com as correcções:
 *   - cada documento passa por ServicoDocumentosTesouraria::gravar (contas de movimento 43/45, sentido, exercício aberto,
 *     saldo em aberto dos documentos liquidados) — o legado gravava sem validar e só falhava na integração;
 *   - simulação por omissão (nada é gravado): cada documento é gravado numa transacção desfeita e os erros vêm por linha;
 *   - os documentos ficam PENDENTES (a integração continua a ser um acto próprio, segregado — teso_integrar);
 *   - ligação opcional à factura pelo n.º do documento de origem: venda do cliente ou factura do fornecedor com esse
 *     número e esse terceiro (NIF), quando não há dúvida;
 *   - NIF, notas, UN e CC desconhecidos são erros da linha (o legado gravava a linha sem terceiro e sem aviso).
 */
final class ServicoImportacaoTesouraria
{
    /** Colunas do modelo do legado (ordem do Template) e os sinónimos aceites na leitura. */
    public const COLUNAS = [
        'data' => ['Data Movimento', 'Data', 'Data Mov', 'Date'],
        'tipo' => ['Tipo Doc', 'Tipo', 'Tipo Documento', 'Type'],
        'conta_financeira' => ['Conta Disponibilidades', 'Conta Fin', 'Conta Banco', 'Conta Caixa', 'Disponibilidades'],
        'referencia' => ['Referência Doc', 'Referencia', 'Ref Doc', 'Reference'],
        'descricao' => ['Descrição Geral', 'Descr Geral', 'Desc Geral', 'Histórico Geral'],
        'conta' => ['Conta Contrapartida', 'Contrapartida', 'Conta Destino', 'Contra-partida'],
        'nif' => ['NIF Terceiro', 'NIF', 'Contribuinte', 'NIF Entidade'],
        'numero_documento' => ['Nº Documento Origem', 'N Documento Origem', 'No Documento Origem', 'Doc Origem', 'Documento Origem', 'Factura', 'Doc Número'],
        'data_origem' => ['Data Documento Origem', 'Data Origem', 'Data Doc', 'Data Factura'],
        'nota_demonstracao' => ['Nota Demonstração', 'Nota Demo', 'Nota 1', 'Demonstração'],
        'nota_fluxo' => ['Nota Fluxo Caixa', 'Nota Fluxo', 'Nota 2', 'Fluxo'],
        'unidade_negocio' => ['Unidade Negocio', 'Unidade de Negocio', 'Business Unit', 'BU', 'UN'],
        'centro_custo' => ['Centro Custo', 'Centro de Custo', 'Cost Center', 'CC'],
        'descricao_linha' => ['Descrição Linha', 'Descrição Item', 'Historico', 'Descricao', 'Description', 'Memo', 'Obs', 'Observacoes'],
        'valor' => ['Valor', 'Montante', 'Quantia', 'Value', 'Total'],
        'url' => ['URL', 'URL Doc', 'Link', 'Link Doc', 'URL Documento', 'Anexo'],
        'dc' => ['DÉBITO/CRÉDITO', 'Debito/Credito', 'D?BITO/CRÉDITO', 'D/C', 'DC', 'Tipo Lancamento'],
    ];

    public function __construct(private readonly ServicoDocumentosTesouraria $documentos) {}

    /** Modelo .xlsx (folhas «Template» e «Instruções»), com o exemplo do legado. */
    public function modelo(): string
    {
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle('Template');
        $cabecalho = array_map(fn ($sinonimos) => $sinonimos[0], self::COLUNAS);
        $folha->fromArray([array_values($cabecalho), ['09/05/2026', 'PAGAMENTO', '43.1.1', 'CH-123456', 'Pagamento de Serviços - Exemplo', '61.2.1', '5000123456',
            'FAT-2026-001', '01/05/2026', '10', '2.1', 'LIS', 'MKT', 'Pagamento Factura de Energia', '50000.00', '', 'D']]);
        $folha->getStyle('A1:Q1')->getFont()->setBold(true);
        foreach (range('A', 'Q') as $c) {
            $folha->getColumnDimension($c)->setAutoSize(true);
        }
        $instr = $livro->createSheet()->setTitle('Instruções');
        $instr->fromArray([['Campo', 'Instrução'],
            ['Data Movimento', 'Obrigatório. Formato dd/mm/aaaa.'],
            ['Tipo Doc', "Obrigatório. 'PAGAMENTO' ou 'RECEBIMENTO'."],
            ['Conta Disponibilidades', 'Obrigatório. Conta de movimento de bancos (43) ou caixa (45).'],
            ['Referência Doc', 'Obrigatório. N.º do cheque, transferência ou borderô. As linhas com a mesma data, tipo, referência e conta formam um documento.'],
            ['Descrição Geral', 'Obrigatório. Descrição do documento (cabeçalho).'],
            ['Conta Contrapartida', 'Obrigatório. Conta de movimento da contrapartida (ex.: 31, 32, 62, 75).'],
            ['NIF Terceiro', 'Opcional. NIF do terceiro (tem de estar registado). Obrigatório para liquidar uma factura.'],
            ['Nº Documento Origem', 'Opcional. N.º da factura que a linha liquida (a ligação à factura é feita quando o número e o terceiro coincidem).'],
            ['Data Documento Origem', 'Opcional. Data da factura (dd/mm/aaaa).'],
            ['Nota Demonstração', 'Opcional. Código da nota às demonstrações.'],
            ['Nota Fluxo Caixa', 'Opcional. Código da nota de fluxo de caixa.'],
            ['Unidade Negocio', 'Opcional. Código da unidade de negócio.'],
            ['Centro Custo', 'Opcional. Código do centro de custo.'],
            ['Descrição Linha', 'Opcional. Descrição da linha (por omissão, a descrição geral).'],
            ['Valor', 'Obrigatório. Valor positivo (ponto ou vírgula para decimais).'],
            ['URL', 'Opcional. Endereço do documento de suporte (fica na descrição da linha).'],
            ['DÉBITO/CRÉDITO', "Opcional. 'D' ou 'C' (por omissão: D nos pagamentos, C nos recebimentos)."],
        ]);
        $instr->getStyle('A1:B1')->getFont()->setBold(true);
        $instr->getColumnDimension('A')->setAutoSize(true);
        $instr->getColumnDimension('B')->setWidth(110);
        $livro->setActiveSheetIndex(0);
        $caminho = tempnam(sys_get_temp_dir(), 'modelo_tes').'.xlsx';
        (new Xlsx($livro))->save($caminho);

        return $caminho;
    }

    /**
     * @return array{simulacao: bool, linhas_lidas: int, documentos: int, gravados: list<array<string, mixed>>, erros: list<array{linhas: string, referencia: ?string, mensagem: string}>}
     */
    public function importar(string $ficheiro, bool $simular = true): array
    {
        try {
            $linhas = IOFactory::load($ficheiro)->getSheet(0)->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use o modelo .xlsx, ou XLS/CSV com as mesmas colunas).', 'FICHEIRO_INVALIDO', 422);
        }
        if (count($linhas) < 2) {
            throw new ErroNegocio('O ficheiro está vazio.', 'FICHEIRO_VAZIO', 422);
        }
        $colunas = $this->colunas($linhas[0]);
        foreach (['data', 'tipo', 'conta_financeira', 'referencia', 'conta', 'valor'] as $obrigatoria) {
            if (! isset($colunas[$obrigatoria])) {
                throw new ErroNegocio('Coluna obrigatória em falta: «'.self::COLUNAS[$obrigatoria][0].'». Use o modelo de importação.', 'CABECALHO_INVALIDO', 422);
            }
        }
        [$documentos, $erros, $lidas] = $this->agrupar(array_slice($linhas, 1, null, true), $colunas);

        $gravados = [];
        foreach ($documentos as $doc) {
            $rotulo = implode(', ', $doc['linhas_ficheiro']);
            try {
                $gravar = function () use ($doc) {
                    $d = $this->documentos->gravar(['tipo' => $doc['tipo'], 'data_documento' => $doc['data'], 'conta_financeira' => $doc['conta_financeira'],
                        'descricao' => $doc['descricao'], 'referencia' => $doc['referencia'], 'linhas' => $doc['itens']]);
                    $d->forceFill(['importado' => true, 'url_documento' => $doc['url_documento']])->save();

                    return $d;
                };
                if ($simular) {
                    DB::beginTransaction();
                    try {
                        $d = $gravar();
                        $gravados[] = ['linhas' => $rotulo, 'referencia' => $doc['referencia'], 'tipo' => $doc['tipo'], 'data' => $doc['data'], 'valor_total' => (string) $d->valor_total];
                    } finally {
                        DB::rollBack();
                    }
                } else {
                    $d = DB::transaction($gravar);
                    $gravados[] = ['id' => $d->id, 'numero_documento' => $d->numero_documento, 'linhas' => $rotulo, 'referencia' => $doc['referencia'],
                        'tipo' => $doc['tipo'], 'data' => $doc['data'], 'valor_total' => (string) $d->valor_total];
                }
            } catch (ErroNegocio $e) {
                $erros[] = ['linhas' => $rotulo, 'referencia' => $doc['referencia'], 'mensagem' => $e->getMessage()];
            }
        }
        if (! $simular && $gravados) {
            app(ServicoAuditoria::class)->registar('Tesouraria', 'Importação de documentos',
                count($gravados).' documento(s) importado(s) em PENDENTE; '.count($erros).' com erro.', 'documentos_tesouraria');
        }

        return ['simulacao' => $simular, 'linhas_lidas' => $lidas, 'documentos' => count($documentos), 'gravados' => $gravados, 'erros' => $erros];
    }

    /** @return array<string, int> campo => índice da coluna */
    private function colunas(array $cabecalho): array
    {
        $norm = fn ($v) => trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\/ ]/', '', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $v)))));
        $porNome = [];
        foreach ($cabecalho as $i => $v) {
            $porNome[$norm($v)] ??= $i;
        }
        $r = [];
        foreach (self::COLUNAS as $campo => $sinonimos) {
            foreach ($sinonimos as $s) {
                if (isset($porNome[$norm($s)])) {
                    $r[$campo] = $porNome[$norm($s)];
                    break;
                }
            }
        }

        return $r;
    }

    /**
     * Agrupa as linhas em documentos (Data + Tipo + Referência + Conta) e resolve os códigos.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: int}
     */
    private function agrupar(array $linhas, array $colunas): array
    {
        $terceiros = Terceiro::query()->whereNotNull('nif')->get(['id', 'nif', 'tipo'])->groupBy(fn ($t) => preg_replace('/\s+/', '', (string) $t->nif));
        $codigo = fn ($c) => ltrim(trim((string) $c), '0');
        $notas = NotaDemonstracao::query()->get(['id', 'codigo'])->mapWithKeys(fn ($n) => [$codigo($n->codigo) => $n->id]);
        $fluxos = NotaFluxoCaixa::query()->get(['id', 'codigo'])->mapWithKeys(fn ($n) => [trim((string) $n->codigo) => $n->id]);
        $uns = UnidadeNegocio::query()->get(['id', 'codigo'])->mapWithKeys(fn ($u) => [mb_strtolower(trim((string) $u->codigo)) => $u->id]);
        $ccs = CentroCusto::query()->get(['id', 'codigo'])->mapWithKeys(fn ($c) => [mb_strtolower(trim((string) $c->codigo)) => $c->id]);
        $docs = [];
        $erros = [];
        $lidas = 0;
        foreach ($linhas as $i => $l) {
            if (! array_filter($l, fn ($v) => $v !== null && trim((string) $v) !== '')) {
                continue;
            }
            $lidas++;
            $n = $i + 1;   // n.º da linha no Excel (o cabeçalho é a linha 1)
            $v = fn (string $campo) => isset($colunas[$campo]) ? (is_string($l[$colunas[$campo]] ?? null) ? trim($l[$colunas[$campo]]) : ($l[$colunas[$campo]] ?? null)) : null;
            // chave do documento a partir dos valores em bruto: uma linha com erro invalida o documento inteiro
            $chaveBruta = implode('|', [ServicoReconciliacaoBancaria::data($v('data')) ?? (string) $v('data'), strtoupper((string) $v('tipo')),
                (string) $v('referencia'), str_replace(['.', ' '], '', (string) $v('conta_financeira'))]);
            try {
                $data = ServicoReconciliacaoBancaria::data($v('data')) ?? throw new ErroNegocio('data do movimento inválida ou em falta.', 'LINHA_INVALIDA', 422);
                $tipo = strtoupper((string) $v('tipo'));
                if (! in_array($tipo, ServicoDocumentosTesouraria::TIPOS, true)) {
                    throw new ErroNegocio("tipo «{$tipo}» inválido (PAGAMENTO ou RECEBIMENTO).", 'LINHA_INVALIDA', 422);
                }
                $ref = (string) $v('referencia');
                $contaFin = str_replace(['.', ' '], '', (string) $v('conta_financeira'));
                $conta = str_replace(['.', ' '], '', (string) $v('conta'));
                if ($ref === '' || $contaFin === '' || $conta === '') {
                    throw new ErroNegocio('referência, conta de disponibilidades e conta de contrapartida são obrigatórias.', 'LINHA_INVALIDA', 422);
                }
                $valor = abs(ServicoReconciliacaoBancaria::numero($v('valor')));
                if ($valor <= 0) {
                    throw new ErroNegocio('valor inválido.', 'LINHA_INVALIDA', 422);
                }
                $dc = strtoupper(substr((string) $v('dc'), 0, 1)) ?: ($tipo === 'PAGAMENTO' ? 'D' : 'C');
                if (! in_array($dc, ['D', 'C'], true)) {
                    throw new ErroNegocio('Débito/Crédito tem de ser D ou C.', 'LINHA_INVALIDA', 422);
                }
                $item = ['codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => number_format($valor, 2, '.', '')];
                $nif = preg_replace('/\s+/', '', (string) $v('nif'));
                $terceiro = null;
                if ($nif !== '') {
                    $c = $terceiros[$nif] ?? collect();
                    if ($c->count() !== 1) {
                        throw new ErroNegocio($c->isEmpty() ? "NIF {$nif} não registado." : "NIF {$nif} repetido em vários terceiros.", 'LINHA_INVALIDA', 422);
                    }
                    $terceiro = $c->first();
                    $item['terceiro_id'] = $terceiro->id;
                }
                $numeroOrigem = trim((string) $v('numero_documento'));
                if ($numeroOrigem !== '') {
                    $item['numero_documento'] = $numeroOrigem;
                    if ($terceiro) {   // ligação à factura quando o número e o terceiro não deixam dúvida
                        $vendas = Venda::query()->where('cliente_id', $terceiro->id)->where('numero_documento', $numeroOrigem)->whereIn('tipo_documento', ['FT', 'FR', 'ND'])->pluck('id');
                        $faturas = FaturaCompra::query()->where('fornecedor_id', $terceiro->id)->where('numero_fatura', $numeroOrigem)->pluck('id');
                        if ($vendas->count() === 1 && $faturas->isEmpty()) {
                            $item['venda_id'] = $vendas->first();
                        } elseif ($faturas->count() === 1 && $vendas->isEmpty()) {
                            $item['fatura_compra_id'] = $faturas->first();
                        }
                    }
                }
                if ($o = ServicoReconciliacaoBancaria::data($v('data_origem'))) {
                    $item['data_documento_original'] = $o;
                }
                foreach ([['nota_demonstracao', $notas, 'nota_demonstracao_id', 'Nota às demonstrações', $codigo], ['nota_fluxo', $fluxos, 'nota_fluxo_caixa_id', 'Nota de fluxo de caixa', fn ($x) => trim((string) $x)],
                    ['unidade_negocio', $uns, 'unidade_negocio_id', 'Unidade de negócio', fn ($x) => mb_strtolower(trim((string) $x))],
                    ['centro_custo', $ccs, 'centro_custo_id', 'Centro de custo', fn ($x) => mb_strtolower(trim((string) $x))]] as [$campo, $mapa, $destino, $rotulo, $chave]) {
                    $bruto = $v($campo);
                    if ($bruto !== null && trim((string) $bruto) !== '') {
                        $item[$destino] = $mapa[$chave($bruto)] ?? throw new ErroNegocio("{$rotulo} «{$bruto}» inexistente.", 'LINHA_INVALIDA', 422);
                    }
                }
                $descricao = (string) ($v('descricao') ?: "{$tipo} - {$ref}");
                $url = trim((string) $v('url'));
                $item['descricao'] = mb_substr((string) ($v('descricao_linha') ?: $descricao), 0, 1000);
                $docs[$chaveBruta] ??= ['data' => $data, 'tipo' => $tipo, 'referencia' => mb_substr($ref, 0, 100), 'conta_financeira' => $contaFin,
                    'descricao' => mb_substr($descricao, 0, 1000), 'url_documento' => null, 'itens' => [], 'linhas_ficheiro' => []];
                $docs[$chaveBruta]['url_documento'] ??= ($url !== '' ? mb_substr($url, 0, 150) : null);
                $docs[$chaveBruta]['itens'][] = $item;
                $docs[$chaveBruta]['linhas_ficheiro'][] = $n;
            } catch (ErroNegocio $e) {
                $falhados[$chaveBruta][] = $n;
                $erros[] = ['linhas' => (string) $n, 'referencia' => $v('referencia') !== null ? (string) $v('referencia') : null, 'mensagem' => "Linha {$n}: {$e->getMessage()}"];
            }
        }
        foreach ($falhados ?? [] as $chave => $nums) {   // documento com linhas inválidas: não se grava só uma parte
            if (isset($docs[$chave])) {
                $erros[] = ['linhas' => implode(', ', $docs[$chave]['linhas_ficheiro']), 'referencia' => $docs[$chave]['referencia'],
                    'mensagem' => 'Documento não importado: tem linhas com erro ('.implode(', ', $nums).').'];
                unset($docs[$chave]);
            }
        }

        return [array_values($docs), $erros, $lidas];
    }
}
