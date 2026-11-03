<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\LogAuditoria;
use App\Services\Estrutura\ServicoImportacaoEntregas;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\CenarioFaseK;
use Tests\TestCase;

/**
 * Fase K (ADR-079) — importação Excel de entregas (origem EXCEL): modelo, validação linha a linha com as regras de gravarExecucao,
 * tudo ou nada, idempotência por origem_chave (DUPLICADA não é erro), permissão (est_editar ou responsável da cadeia) e limites.
 */
final class FkImportacaoEntregasTest extends TestCase
{
    use CenarioFaseK;

    private const CAB = ['Colaborador (nº, NIF ou nome)', 'Data (AAAA-MM-DD)', 'Quantidade', 'Observações', 'Evidência (URL)'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenarioK();
    }

    /** @param  list<list<mixed>>  $linhas */
    private function csv(array $linhas, ?array $cabecalhos = null, string $nome = 'entregas.csv'): UploadedFile
    {
        $f = fopen('php://temp', 'r+');
        fputcsv($f, $cabecalhos ?? self::CAB);
        foreach ($linhas as $l) {
            fputcsv($f, $l);
        }
        rewind($f);

        return UploadedFile::fake()->createWithContent($nome, stream_get_contents($f));
    }

    private function importar(UploadedFile $ficheiro, array $h, ?string $simular = null, ?int $entregavel = null): TestResponse
    {
        return $this->post('/api/rh/estrutura/entregaveis/'.($entregavel ?? $this->ids['entregavel']).'/execucoes/importar', ['ficheiro' => $ficheiro] + ($simular === null ? [] : ['simular' => $simular]),
            $h + ['Accept' => 'application/json']);
    }

    private function execucoes(): int
    {
        return $this->em(fn () => ExecucaoEntregavel::query()->where('origem', 'EXCEL')->count());
    }

    #[Test]
    public function modelo_com_cabecalhos_instrucoes_e_so_para_quem_pode_importar(): void
    {
        $this->get("/api/rh/estrutura/entregaveis/{$this->ids['entregavel']}/execucoes/modelo", $this->s['ana'])->assertForbidden();   // o executor não importa em lote
        $this->get("/api/rh/estrutura/entregaveis/{$this->ids['entregavel']}/execucoes/modelo", $this->s['rui'])->assertForbidden();
        $r = $this->get("/api/rh/estrutura/entregaveis/{$this->ids['entregavel']}/execucoes/modelo", $this->s['chefe'])->assertOk();   // responsável da unidade
        $this->assertStringContainsString('spreadsheetml', (string) $r->headers->get('content-type'));
        $this->get("/api/rh/estrutura/entregaveis/{$this->ids['entregavel']}/execucoes/modelo", $this->s['rh'])->assertOk();   // est_editar
        $this->get('/api/rh/estrutura/entregaveis/999999/execucoes/modelo', $this->s['rh'])->assertNotFound();

        $caminho = tempnam(sys_get_temp_dir(), 'modelo').'.xlsx';
        $this->em(fn () => app(ServicoImportacaoEntregas::class)->modelo(EntregavelTarefaUnidade::query()->findOrFail($this->ids['entregavel']), $caminho));
        $livro = IOFactory::load($caminho);
        $this->assertSame(['Entregas', 'Instruções', 'Colaboradores'], $livro->getSheetNames());
        $this->assertSame(self::CAB, $livro->getSheet(0)->rangeToArray('A1:E1')[0]);
        $this->assertNotNull($livro->getSheet(0)->getCell('A2')->getValue());   // linha de exemplo
        $this->assertStringContainsString('Tudo ou nada', (string) $livro->getSheet(1)->getCell('B7')->getValue());
        unlink($caminho);
    }

    #[Test]
    public function validar_linha_a_linha_com_as_regras_das_entregas_e_nada_se_grava_na_simulacao(): void
    {
        $this->em(function () {
            $org = Colaborador::query()->value('tipo_organizacao_id');
            foreach ([['Maria', '2001'], ['Maria', '2002']] as [$n, $nif]) {
                Colaborador::create(['nome_completo' => $n, 'nif' => $nif, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22]);
            }
            Colaborador::create(['nome_completo' => 'Inactivo', 'nif' => '3001', 'estado' => 'INACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22]);
        });
        $ficheiro = $this->csv([
            ['1001', '2026-03-02', '1,5', 'primeira', 'drive.empresa.ao/a'],   // OK: NIF, vírgula decimal, URL sem esquema
            ['Rui', '03/03/2026', '2', '', ''],                                 // OK: nome e data DD/MM/AAAA
            ['', '2026-03-02', '1', '', ''],                                    // sem colaborador
            ['Ninguém', '2026-03-02', '1', '', ''],                             // não existe
            ['Inactivo', '2026-03-02', '1', '', ''],                            // só activos
            ['Maria', '2026-03-02', '1', '', ''],                               // ambíguo
            ['Ana', '2026-03-11', '1', '', ''],                                 // data futura
            ['Ana', '2026-13-40', '1', '', ''],                                 // data inválida
            ['Ana', '', '1', '', ''],                                           // sem data
            ['Ana', '2026-03-02', '0', '', ''],                                 // quantidade zero
            ['Ana', '2026-03-02', 'abc', '', ''],                               // quantidade inválida
            ['Ana', '2026-03-02', '1', '', 'ftp://x.y/z'],                      // só http(s)
            [],                                                                 // linha vazia: ignorada (mas conta no n.º da linha)
            ['Ana', '2026-03-02', '1', 'x', 'https://u:p@x.y/z'],               // credenciais no endereço
        ]);
        $r = $this->importar($ficheiro, $this->s['chefe'])->assertOk()->json('dados');   // `simular` omitido = simular
        $this->assertTrue($r['simulacao']);
        $this->assertSame(['total' => 13, 'validas' => 2, 'erros' => 11, 'duplicadas' => 0], $r['resumo']);
        $porN = collect($r['linhas'])->keyBy('n');
        $this->assertSame(['OK', 'Ana', '2026-03-02', 1.5], [$porN[2]['estado'], $porN[2]['colaborador']['nome'], $porN[2]['data'], $porN[2]['quantidade']]);
        $this->assertSame(['OK', 'Rui', '2026-03-03'], [$porN[3]['estado'], $porN[3]['colaborador']['nome'], $porN[3]['data']]);
        foreach ([4 => 'Indique o colaborador', 5 => 'não encontrado', 6 => 'não encontrado', 7 => 'ambíguo', 8 => 'futura', 9 => 'não é válida', 10 => 'Indique a data', 11 => 'maior do que zero',
            12 => 'maior do que zero', 13 => 'http://', 15 => 'credenciais'] as $n => $trecho) {
            $this->assertSame('ERRO', $porN[$n]['estado'], "linha {$n}");
            $this->assertStringContainsString($trecho, implode(' ', $porN[$n]['erros']), "linha {$n}");
        }
        $this->assertFalse($porN->has(14));   // a vazia não aparece
        $this->assertSame(0, $this->execucoes());
        // `simular` aceita 1/0 e true/false (multipart): «1» simula, «false» grava
        $this->importar($this->csv([['1001', '2026-03-02', '1', '', '']]), $this->s['chefe'], '1')->assertOk()->assertJsonPath('dados.simulacao', true)->assertJsonPath('dados.resumo.validas', 1);
        $this->assertSame(0, $this->execucoes());
        $this->importar($this->csv([['1001', '2026-03-02', '1', '', '']]), $this->s['chefe'], 'false')->assertOk()->assertJsonPath('dados.simulacao', false)->assertJsonPath('dados.importadas', 1);
        $this->importar($this->csv([['1001', '2026-03-02', '1', '', '']]), $this->s['chefe'], 'true')->assertOk()->assertJsonPath('dados.resumo.duplicadas', 1);
    }

    #[Test]
    public function tudo_ou_nada_idempotencia_e_rasto_da_importacao(): void
    {
        $chefe = $this->s['chefe'];
        $comErro = $this->csv([['Ana', '2026-03-02', '1', 'ok', ''], ['Ninguém', '2026-03-02', '1', '', '']]);
        $this->importar($comErro, $chefe, '0')->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS')->assertJsonPath('erros.resumo.erros', 1)->assertJsonCount(2, 'erros.linhas');
        $this->assertSame(0, $this->execucoes());   // nem a linha válida foi gravada

        $bom = fn () => $this->csv([['Ana', '2026-03-02', '1', 'Relatório de Fevereiro', 'https://drive.empresa.ao/a'], ['Rui', '2026-03-03', '2', '', ''], ['Ana', '2026-03-02', '1', 'Relatório de Fevereiro', '']]);
        // a terceira linha repete a primeira dentro do ficheiro: DUPLICADA (ignorada, não é erro)
        $r = $this->importar($bom(), $chefe, '0')->assertOk()->json('dados');
        $this->assertSame([false, 2, 2, 1, 0], [$r['simulacao'], $r['importadas'], $r['resumo']['validas'], $r['resumo']['duplicadas'], $r['resumo']['erros']]);
        $this->assertSame(['OK', 'OK', 'DUPLICADA'], array_column($r['linhas'], 'estado'));
        $x = $this->em(fn () => ExecucaoEntregavel::query()->where('colaborador_id', $this->ids['ana'])->firstOrFail());
        $this->assertSame(['EXCEL', 'PENDENTE', 'https://drive.empresa.ao/a', 'Relatório de Fevereiro', $this->ids['chefe'], '2026-03-02'], [$x->origem, $x->validacao, $x->url_evidencia, $x->observacoes,
            $x->registado_por_colaborador_id, $x->data->toDateString()]);
        $this->assertMatchesRegularExpression('/^XLS\|'.$this->ids['entregavel'].'\|'.$this->ids['ana'].'\|2026-03-02\|[0-9a-f]{10}$/', (string) $x->origem_chave);
        $this->assertSame($this->ids['dep'], $x->unidade_organica_id);

        // importar o mesmo ficheiro outra vez: tudo DUPLICADA, 200, nada de novo
        $r = $this->importar($bom(), $chefe, '0')->assertOk()->json('dados');
        $this->assertSame([0, 0, 3, 0], [$r['importadas'], $r['resumo']['validas'], $r['resumo']['duplicadas'], $r['resumo']['erros']]);
        $this->assertSame(2, $this->execucoes());
        // outra quantidade é outra entrega
        $this->importar($this->csv([['Ana', '2026-03-02', '2', 'Relatório de Fevereiro', '']]), $chefe, '0')->assertOk()->assertJsonPath('dados.importadas', 1);
        $this->assertSame(3, $this->execucoes());
        $this->assertTrue($this->em(fn () => LogAuditoria::query()->where('acao', 'Importar entregas (Excel)')->where('detalhes', 'like', '%Relatório: 2 entrega(s) importada(s)%')->exists()));

        // as entregas importadas seguem o circuito normal: ficam por validar; nem a Ana (executora) nem o Chefe (que importou) as validam: o Director sim
        $this->postJson("/api/rh/portal/execucoes/{$x->id}/validar", ['decisao' => 'VALIDADA'], $this->s['ana'])->assertForbidden();
        $this->postJson("/api/rh/portal/execucoes/{$x->id}/validar", ['decisao' => 'VALIDADA'], $chefe)->assertForbidden();
        $this->postJson("/api/rh/portal/execucoes/{$x->id}/validar", ['decisao' => 'VALIDADA'], $this->s['director'])->assertOk();
    }

    #[Test]
    public function ficheiro_xlsx_com_datas_do_excel_e_os_limites_do_ficheiro(): void
    {
        $livro = new Spreadsheet;
        $f = $livro->getActiveSheet()->setTitle('Entregas');
        $f->fromArray([self::CAB, ['Ana', null, 3, 'Em Excel', null]]);
        $f->setCellValue('B2', DataExcel::PHPToExcel(new \DateTime('2026-03-04')));
        $f->getStyle('B2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $caminho = tempnam(sys_get_temp_dir(), 'entregas').'.xlsx';
        (new Xlsx($livro))->save($caminho);
        $xlsx = new UploadedFile($caminho, 'entregas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $this->importar($xlsx, $this->s['rh'], '0')->assertOk()->assertJsonPath('dados.importadas', 1)->assertJsonPath('dados.linhas.0.data', '2026-03-04');
        $this->assertSame(1, $this->execucoes());

        // cabeçalhos em falta, extensão, tamanho e limite de linhas
        $this->importar($this->csv([['Ana', '2026-03-02', '1']], ['Nome', 'Dia', 'Valor']), $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'CABECALHOS_EM_FALTA');
        $this->importar(UploadedFile::fake()->createWithContent('entregas.txt', 'x'), $this->s['rh'])->assertStatus(422);
        $this->importar(UploadedFile::fake()->create('grande.csv', 6000), $this->s['rh'])->assertStatus(422);
        $muitas = $this->csv(array_fill(0, 2001, ['Ana', '2026-03-02', '1', '', '']));
        $this->importar($muitas, $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_EXCEDIDO');
        $this->importar($this->csv(array_fill(0, 2000, ['Ana', '2026-03-02', '1', 'mesma', ''])), $this->s['rh'])->assertOk()->assertJsonPath('dados.resumo.total', 2000);
        $this->importar(UploadedFile::fake()->createWithContent('vazio.csv', "Colaborador,Data,Quantidade\n"), $this->s['rh'])->assertStatus(422);

        // sem permissão e entregável inactivo
        $this->importar($this->csv([['Ana', '2026-03-02', '1', '', '']]), $this->s['ana'])->assertForbidden();
        $this->importar($this->csv([['Ana', '2026-03-02', '1', '', '']]), $this->s['rui'])->assertForbidden();
        $this->importar($this->csv([['Ana', '2026-03-02', '1', '', '']]), $this->s['rh'], null, 999999)->assertNotFound();
        $this->em(fn () => EntregavelTarefaUnidade::query()->whereKey($this->ids['entregavel'])->update(['ativo' => false]));
        $this->importar($this->csv([['Ana', '2026-03-02', '1', '', '']]), $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'ENTREGAVEL_INACTIVO');
    }
}
