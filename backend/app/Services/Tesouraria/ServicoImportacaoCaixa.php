<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\SessaoCaixa;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Folha de Caixa › Importar movimentos por Excel (ronda 3, r3_teso) — showImportFolhaCaixaModal / downloadFolhaCaixaTemplate /
 * handleFolhaCaixaImport do legado (js/ui_folha_caixa.js:1625-1830): o mesmo modelo «Template_Importacao_Caixa.xlsx» (folha
 * «Movimentos Caixa»: Data Doc, Tipo REC/PAG, Nº Doc, Referência, Descrição, Valor, Conta Contrapartida, NIF Terceiro, Nota
 * Demonstração, Nota Fluxo Caixa, URL) e os mesmos movimentos na sessão ABERTA. Correcções face ao legado:
 *   - o legado gravava linha a linha e ignorava as linhas com erro (importação parcial, «Erros: N linhas ignoradas»), e
 *     gravava sem terceiro/nota quando o código não existia; aqui cada linha é validada (data, tipo, valor, conta de
 *     contrapartida de movimento e não de caixa, NIF registado e único, notas existentes, URL http/https) e há
 *     pré-visualização (simulação — nada gravado);
 *   - a importação é TUDO OU NADA numa só transacção, com o lock da sessão e as regras de ServicoCaixa::registarMovimento
 *     (sessão aberta, exercício aberto, data ≥ abertura, saldo em aberto do documento): se alguma linha falhar, nada fica.
 */
final class ServicoImportacaoCaixa
{
    public const LIMITE_LINHAS = 2000;

    /** Colunas do modelo do legado e os sinónimos aceites na leitura (o cabeçalho manda; a posição pode variar). */
    public const COLUNAS = [
        'data' => ['Data Doc (YYYY-MM-DD)', 'Data Doc', 'Data', 'Data Documento', 'Data Movimento'],
        'tipo' => ['Tipo (REC/PAG)', 'Tipo', 'Entrada/Saída', 'Entrada/Saida'],
        'numero_documento' => ['Nº Doc', 'N Doc', 'No Doc', 'Número Documento', 'Numero Documento', 'Nº Documento', 'Documento'],
        'referencia' => ['Referência', 'Referencia', 'Ref'],
        'descricao' => ['Descrição', 'Descricao', 'Histórico'],
        'valor' => ['Valor', 'Montante', 'Valor (Kz)'],
        'conta' => ['Conta Contrapartida', 'Contrapartida', 'Conta'],
        'nif' => ['NIF Terceiro', 'NIF', 'Contribuinte'],
        'nota_demonstracao' => ['Nota Demonstração', 'Nota Demonstracao', 'Nota DEMO', 'Nota às demonstrações'],
        'nota_fluxo' => ['Nota Fluxo Caixa', 'Nota Fluxo', 'Nota de fluxo de caixa'],
        'url' => ['URL', 'Link', 'Ligação', 'Anexo'],
    ];

    public function __construct(
        private readonly ServicoCaixa $caixa,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /** Template_Importacao_Caixa.xlsx (folha «Movimentos Caixa» com os exemplos do legado e folha «Instruções»). */
    public function modelo(): string
    {
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle('Movimentos Caixa');
        $cab = array_map(fn ($s) => $s[0], array_values(self::COLUNAS));
        $folha->fromArray([$cab,
            ['2026-09-05', 'REC', 'FT 1/123', '', 'Recebimento Venda', 150000, '31111', '5000000000', '19', '112', 'https://exemplo.ao/documento'],
            ['2026-09-05', 'PAG', 'VD 456', '', 'Compra de Material', 25000, '62221', '5000000001', '19', '142', '']], null, 'A1', true);
        // códigos de contas, NIF e notas como texto (o Excel não converte «4.10» em 4.1 nem usa notação científica)
        foreach (['G', 'H', 'I', 'J'] as $c) {
            $folha->getStyle("{$c}2:{$c}".self::LIMITE_LINHAS)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            foreach ([2, 3] as $l) {
                $folha->setCellValueExplicit("{$c}{$l}", (string) $folha->getCell("{$c}{$l}")->getValue(), DataType::TYPE_STRING);
            }
        }
        $folha->getStyle('A1:K1')->getFont()->setBold(true);
        foreach (['A' => 22, 'B' => 14, 'C' => 15, 'D' => 15, 'E' => 32, 'F' => 14, 'G' => 20, 'H' => 16, 'I' => 18, 'J' => 18, 'K' => 40] as $c => $w) {
            $folha->getColumnDimension($c)->setWidth($w);
        }
        $instr = $livro->createSheet()->setTitle('Instruções');
        $instr->fromArray([['Campo', 'Instrução'],
            ['Data Doc', 'Obrigatório. AAAA-MM-DD ou DD/MM/AAAA; não anterior à abertura da sessão.'],
            ['Tipo', 'Obrigatório. REC (entrada) ou PAG (saída).'],
            ['Nº Doc / Referência / Descrição', 'Textos livres (a descrição, se vazia, fica «Recebimento/Pagamento importado»).'],
            ['Valor', 'Obrigatório. Positivo; ponto ou vírgula para as casas decimais.'],
            ['Conta Contrapartida', 'Obrigatório. Conta de movimento que não seja de caixa (45). Ex.: 62221 para despesas, 31111 para clientes.'],
            ['NIF Terceiro', 'Opcional. NIF de um terceiro já registado.'],
            ['Nota Demonstração / Nota Fluxo Caixa', 'Opcionais. Código da nota nas Tabelas Auxiliares (formate a coluna como Texto).'],
            ['URL', 'Opcional. Ligação http(s) para o documento digitalizado.'],
            ['Importação', 'Primeiro valide (simulação: nada é gravado). A importação é tudo ou nada: se alguma linha tiver erro, nenhuma é gravada.'],
        ]);
        $instr->getStyle('A1:B1')->getFont()->setBold(true);
        $instr->getColumnDimension('A')->setWidth(36);
        $instr->getColumnDimension('B')->setWidth(110);
        $livro->setActiveSheetIndex(0);
        $caminho = tempnam(sys_get_temp_dir(), 'modelo_caixa').'.xlsx';
        (new Xlsx($livro))->save($caminho);

        return $caminho;
    }

    /**
     * @return array{simulacao: bool, importado: bool, sessao_id: int, linhas_lidas: int, validas: list<array<string, mixed>>,
     *               erros: list<array{linha: int, mensagem: string}>, total_entradas: string, total_saidas: string}
     */
    public function importar(SessaoCaixa $sessao, string $ficheiro, bool $simular = true): array
    {
        if ($sessao->estado !== 'ABERTA') {
            throw new ErroNegocio('Só se importam movimentos para uma sessão de caixa aberta.', 'SESSAO_NAO_ABERTA', 422);
        }
        try {
            $linhas = IOFactory::load($ficheiro)->getSheet(0)->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use o modelo .xlsx, ou XLS/CSV com as mesmas colunas).', 'FICHEIRO_INVALIDO', 422);
        }
        if (count($linhas) < 2) {
            throw new ErroNegocio('O ficheiro não contém dados.', 'FICHEIRO_VAZIO', 422);
        }
        if (count($linhas) - 1 > self::LIMITE_LINHAS) {
            throw new ErroNegocio('O ficheiro tem mais de '.self::LIMITE_LINHAS.' linhas: divida-o.', 'FICHEIRO_GRANDE', 422);
        }
        $colunas = $this->colunas($linhas[0]);
        foreach (['data', 'tipo', 'valor', 'conta'] as $obrigatoria) {
            if (! isset($colunas[$obrigatoria])) {
                throw new ErroNegocio('Coluna obrigatória em falta: «'.self::COLUNAS[$obrigatoria][0].'». Use o modelo de importação.', 'CABECALHO_INVALIDO', 422);
            }
        }
        [$movimentos, $erros, $lidas] = $this->validar(array_slice($linhas, 1, null, true), $colunas);

        $validas = [];
        $entradas = $saidas = '0.00';
        DB::beginTransaction();
        try {
            foreach ($movimentos as $m) {
                try {
                    // savepoint por linha: um erro de uma linha não deixa a transacção inválida para a validação das seguintes
                    $mov = DB::transaction(function () use ($sessao, $m) {
                        $mov = $this->caixa->registarMovimento($sessao, $m['dados']);
                        $extra = array_filter(['nota_demonstracao_id' => $m['nota_demonstracao_id'], 'nota_fluxo_caixa_id' => $m['nota_fluxo_caixa_id'],
                            'url_documento' => $m['url_documento']], fn ($v) => $v !== null);
                        $extra += ['tipo_origem' => 'IMPORTACAO'];
                        $mov->forceFill($extra)->save();

                        return $mov;
                    });
                    $validas[] = ['linha' => $m['linha'], 'id' => $simular ? null : $mov->id, 'data' => $m['dados']['data_documento'], 'tipo' => $m['dados']['tipo'],
                        'numero_documento' => $m['dados']['numero_documento'], 'descricao' => $m['dados']['descricao'], 'conta_contrapartida' => $m['dados']['conta_contrapartida'],
                        'valor' => $m['dados']['valor']];
                    $m['dados']['tipo'] === 'REC' ? $entradas = bcadd($entradas, $m['dados']['valor'], 2) : $saidas = bcadd($saidas, $m['dados']['valor'], 2);
                } catch (ErroNegocio $e) {
                    $erros[] = ['linha' => $m['linha'], 'mensagem' => "Linha {$m['linha']}: {$e->getMessage()}"];
                }
            }
            $importado = ! $simular && ! $erros && $validas;
            if ($importado) {
                DB::commit();
                app(ServicoAuditoria::class)->registar('Tesouraria', 'Importação de movimentos de caixa',
                    count($validas)." movimento(s) importado(s) na sessão {$sessao->id} ({$sessao->codigo_conta}).", 'movimentos_caixa');
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        usort($erros, fn ($a, $b) => $a['linha'] <=> $b['linha']);

        return ['simulacao' => $simular, 'importado' => $importado, 'sessao_id' => $sessao->id, 'linhas_lidas' => $lidas,
            'validas' => $importado ? $validas : array_map(fn ($v) => ['id' => null] + $v, $validas),
            'erros' => $erros, 'total_entradas' => $entradas, 'total_saidas' => $saidas];
    }

    /** @return array<string, int> campo => índice da coluna */
    private function colunas(array $cabecalho): array
    {
        $norm = fn ($v) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $v)));
        $porNome = [];
        foreach ($cabecalho as $i => $v) {
            $porNome[$norm($v)] ??= $i;
        }
        $r = [];
        foreach (self::COLUNAS as $campo => $sinonimos) {
            foreach ($sinonimos as $s) {
                if (isset($porNome[$norm($s)]) && ! in_array($porNome[$norm($s)], $r, true)) {
                    $r[$campo] = $porNome[$norm($s)];
                    break;
                }
            }
        }

        return $r;
    }

    /**
     * Validação de cada linha (sem gravar): devolve os movimentos prontos para ServicoCaixa::registarMovimento e os erros.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{linha: int, mensagem: string}>, 2: int}
     */
    private function validar(array $linhas, array $colunas): array
    {
        $terceiros = Terceiro::query()->whereNotNull('nif')->get(['id', 'nif'])->groupBy(fn ($t) => mb_strtoupper(preg_replace('/\s+/', '', (string) $t->nif)));
        $codigo = fn ($c) => mb_strtolower(trim((string) $c));
        $notas = NotaDemonstracao::query()->get(['id', 'codigo'])->groupBy(fn ($n) => $codigo($n->codigo));
        $fluxos = NotaFluxoCaixa::query()->get(['id', 'codigo'])->groupBy(fn ($n) => $codigo($n->codigo));
        $movimentos = [];
        $erros = [];
        $lidas = 0;
        foreach ($linhas as $i => $l) {
            if (! array_filter($l, fn ($v) => $v !== null && trim((string) $v) !== '')) {
                continue;
            }
            $lidas++;
            $n = $i + 1;   // n.º da linha no Excel (o cabeçalho é a linha 1)
            $v = fn (string $campo) => isset($colunas[$campo]) ? trim((string) ($l[$colunas[$campo]] ?? '')) : '';
            try {
                $data = ServicoReconciliacaoBancaria::data($l[$colunas['data']] ?? null) ?? throw new ErroNegocio('data inválida ou em falta.', 'LINHA_INVALIDA', 422);
                $tipo = mb_strtoupper($v('tipo'));
                $tipo = ['ENTRADA' => 'REC', 'SAIDA' => 'PAG', 'SAÍDA' => 'PAG'][$tipo] ?? $tipo;
                if (! in_array($tipo, ['REC', 'PAG'], true)) {
                    throw new ErroNegocio('tipo inválido (use REC ou PAG).', 'LINHA_INVALIDA', 422);
                }
                $valor = ServicoReconciliacaoBancaria::numero($l[$colunas['valor']] ?? null);
                if ($valor <= 0) {
                    throw new ErroNegocio('valor inválido (tem de ser positivo).', 'LINHA_INVALIDA', 422);
                }
                $conta = str_replace([' '], '', $v('conta'));
                if ($conta === '') {
                    throw new ErroNegocio('conta de contrapartida em falta.', 'LINHA_INVALIDA', 422);
                }
                if (str_starts_with($conta, '45')) {
                    throw new ErroNegocio("a contrapartida {$conta} é uma conta de caixa (45): use a conta do movimento (cliente, fornecedor, custo, proveito…).", 'LINHA_INVALIDA', 422);
                }
                $this->plano->contaDeMovimento($conta);
                $terceiro = null;
                if (($nif = mb_strtoupper(preg_replace('/\s+/', '', $v('nif')))) !== '') {
                    $t = $terceiros[$nif] ?? collect();
                    if ($t->count() !== 1) {
                        throw new ErroNegocio($t->isEmpty() ? "NIF {$nif} não registado em Terceiros." : "NIF {$nif} repetido em vários terceiros.", 'LINHA_INVALIDA', 422);
                    }
                    $terceiro = (int) $t->first()->id;
                }
                $nota = fn (string $campo, $mapa, string $rotulo) => ($c = $v($campo)) === '' ? null
                    : (($mapa[$codigo($c)] ?? collect())->count() === 1 ? (int) $mapa[$codigo($c)]->first()->id : throw new ErroNegocio("{$rotulo} «{$c}» inexistente.", 'LINHA_INVALIDA', 422));
                $url = $v('url');
                if ($url !== '' && ! preg_match('#^https?://[^\s<>"\']+$#i', $url)) {
                    throw new ErroNegocio('o URL tem de começar por http:// ou https://.', 'LINHA_INVALIDA', 422);
                }
                $descricao = $v('descricao') ?: ($tipo === 'REC' ? 'Recebimento importado' : 'Pagamento importado');
                $movimentos[] = ['linha' => $n, 'nota_demonstracao_id' => $nota('nota_demonstracao', $notas, 'Nota às demonstrações'),
                    'nota_fluxo_caixa_id' => $nota('nota_fluxo', $fluxos, 'Nota de fluxo de caixa'), 'url_documento' => $url !== '' ? mb_substr($url, 0, 150) : null,
                    'dados' => ['tipo' => $tipo, 'data_documento' => $data, 'conta_contrapartida' => $conta, 'valor' => number_format($valor, 2, '.', ''),
                        'descricao' => mb_substr(mb_strlen($descricao) < 3 ? "{$descricao} (importado)" : $descricao, 0, 1000),
                        'numero_documento' => $v('numero_documento') !== '' ? mb_substr($v('numero_documento'), 0, 100) : null,
                        'referencia' => $v('referencia') !== '' ? mb_substr($v('referencia'), 0, 100) : null, 'terceiro_id' => $terceiro]];
            } catch (ErroNegocio $e) {
                $erros[] = ['linha' => $n, 'mensagem' => "Linha {$n}: {$e->getMessage()}"];
            }
        }

        return [$movimentos, $erros, $lidas];
    }
}
