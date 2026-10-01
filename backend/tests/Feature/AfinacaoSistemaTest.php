<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Moeda;
use App\Models\TaxaCambio;
use App\Services\Sistema\ServicoMigracaoDados;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Afinação da Fase 5 (ADR-064): importações a partir do ficheiro .xlsx (multipart) e moeda funcional da empresa activa. */
final class AfinacaoSistemaTest extends TestCase
{
    private Empresa $empresa;

    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000888']);
        foreach ([['AOA', 'Kwanza', 'Kz'], ['USD', 'Dólar dos EUA', 'US$'], ['EUR', 'Euro', '€']] as [$c, $n, $s]) {
            Moeda::create(['codigo' => $c, 'nome' => $n, 'simbolo' => $s, 'casas_decimais' => 2, 'ativo' => true]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function temporario(string $extensao): string
    {
        $f = tempnam(sys_get_temp_dir(), 'afc').'.'.$extensao;

        return $this->temporarios[] = $f;
    }

    private function ficheiro(string $caminho, string $nome): UploadedFile
    {
        return new UploadedFile($caminho, $nome, null, null, true);
    }

    #[Test]
    public function importa_o_proprio_modelo_xlsx_preenchido_com_a_numeracao_das_linhas_do_excel(): void
    {
        $s = $this->sessao(['config_migracao_view', 'contab_plano_gerir']);
        // o modelo descarregável (folha Template: cabeçalhos + linha de exemplo) preenchido no Excel
        $modelo = $this->temporario('xlsx');
        app(ServicoMigracaoDados::class)->modeloExcel('plano_contas', $modelo);
        $livro = IOFactory::load($modelo);
        $folha = $livro->getSheetByName('Template');
        $folha->fromArray([['31', 'Clientes', 'T'], ['311', 'Clientes c/c', 'T']], null, 'A3');
        $folha->fromArray([['3112', '', 'M']], null, 'A6');   // linha 5 vazia; linha 6 sem descrição
        $livro->setActiveSheetIndexByName('Como_Preencher');   // a folha Template é escolhida mesmo não sendo a activa
        (new Xlsx($livro))->save($modelo);

        $sim = $this->post('/api/sistema/migracao/importar/plano_contas', ['ficheiro' => $this->ficheiro($modelo, 'Template_plano_contas.xlsx'), 'simular' => '1'],
            $s + ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([2, 1, 3], [$sim->json('dados.novos'), $sim->json('dados.linhas_exemplo_ignoradas'), $sim->json('dados.linhas_lidas')]);
        $this->assertSame([['linha' => 6, 'motivo' => 'falta Descrição']], $sim->json('dados.rejeitadas'));
        $this->assertSame(0, DB::table('plano_contas')->where('empresa_id', $this->empresa->id)->count());

        $this->post('/api/sistema/migracao/importar/plano_contas', ['ficheiro' => $this->ficheiro($modelo, 'Template_plano_contas.xlsx')], $s + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('dados.criados', 2);
        $this->assertSame(['31', '311'], DB::table('plano_contas')->where('empresa_id', $this->empresa->id)->orderBy('codigo')->pluck('codigo')->all());

        // o formato JSON `linhas` mantém-se
        $this->postJson('/api/sistema/migracao/importar/plano_contas', ['linhas' => [['Conta' => '3111', 'Descrição' => 'Nacionais']]], $s)->assertOk()->assertJsonPath('dados.criados', 1);
    }

    #[Test]
    public function recusa_ficheiro_ilegivel_extensao_errada_pedido_vazio_e_sem_permissao(): void
    {
        $s = $this->sessao(['config_migracao_view', 'contab_plano_gerir']);
        $lixo = $this->temporario('xlsx');
        file_put_contents($lixo, 'isto não é um livro de Excel');
        $this->post('/api/sistema/migracao/importar/plano_contas', ['ficheiro' => $this->ficheiro($lixo, 'x.xlsx')], $s + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('codigo', 'FICHEIRO_INVALIDO');
        $pdf = $this->temporario('pdf');
        file_put_contents($pdf, '%PDF-1.4');
        $this->post('/api/sistema/migracao/importar/plano_contas', ['ficheiro' => $this->ficheiro($pdf, 'x.pdf')], $s + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('ficheiro', 'erros');
        $this->postJson('/api/sistema/migracao/importar/plano_contas', [], $s)->assertStatus(422);

        $vazio = $this->temporario('xlsx');
        (new Xlsx(new Spreadsheet))->save($vazio);
        $this->post('/api/sistema/migracao/importar/plano_contas', ['ficheiro' => $this->ficheiro($vazio, 'v.xlsx')], $s + ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('codigo', 'FICHEIRO_SEM_CABECALHOS');

        $this->post('/api/sistema/migracao/importar/cargos', ['ficheiro' => $this->ficheiro($lixo, 'x.xlsx')], $s + ['Accept' => 'application/json'])->assertForbidden();
    }

    #[Test]
    public function importa_cambios_de_xlsx_com_datas_do_excel_e_de_csv(): void
    {
        $s = $this->sessao(['config_moedas_view', 'config_moedas_gerir']);
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet();
        $folha->fromArray([['Data', 'Moeda', 'Taxa', 'Origem', 'Âmbito'], [null, 'USD', 912.5, 'BNA', 'TODAS'], ['02/09/2026', 'EUR', '1.050,25', 'BNA', 'Empresa']]);
        $folha->setCellValue('A2', DataExcel::PHPToExcel(new \DateTimeImmutable('2026-09-01')));
        $folha->getStyle('A2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $xlsx = $this->temporario('xlsx');
        (new Xlsx($livro))->save($xlsx);

        $this->post('/api/sistema/cambios/importar', ['ficheiro' => $this->ficheiro($xlsx, 'cambios.xlsx'), 'simular' => '1'], $s + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('dados.novos', 2)->assertJsonPath('dados.rejeitadas', []);
        $this->post('/api/sistema/cambios/importar', ['ficheiro' => $this->ficheiro($xlsx, 'cambios.xlsx')], $s + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('dados.importados', 2);
        $this->assertSame('912.500000', (string) TaxaCambio::query()->where('codigo_moeda', 'USD')->whereDate('data_taxa', '2026-09-01')->value('taxa'));
        $this->assertSame($this->empresa->id, (int) TaxaCambio::query()->where('codigo_moeda', 'EUR')->value('empresa_id'));

        $csv = $this->temporario('csv');
        file_put_contents($csv, "Data,Moeda,Taxa\n2026-09-03,USD,915\n");
        $this->post('/api/sistema/cambios/importar', ['ficheiro' => $this->ficheiro($csv, 'cambios.csv')], $s + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('dados.importados', 1);

        $this->post('/api/sistema/cambios/importar', ['ficheiro' => $this->ficheiro($csv, 'cambios.csv')], $this->sessao(['config_moedas_view']) + ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    #[Test]
    public function moeda_funcional_da_empresa_activa_para_quem_consulta_moedas(): void
    {
        $this->getJson('/api/sistema/moedas/funcional', $this->sessao(['config_moedas_view']))->assertOk()
            ->assertJsonPath('dados', ['codigo_moeda' => 'AOA', 'nome' => 'Kwanza', 'simbolo' => 'Kz', 'casas_decimais' => 2, 'base' => true]);
        $this->empresa->update(['moeda_funcional' => 'USD']);
        $this->getJson('/api/sistema/moedas/funcional', $this->sessao(['config_moedas_view']))->assertOk()
            ->assertJsonPath('dados.codigo_moeda', 'USD')->assertJsonPath('dados.base', false);
        $this->getJson('/api/sistema/moedas', $this->sessao([]))->assertOk()->assertJsonPath('metadados.moeda_funcional', 'USD');
        $this->getJson('/api/sistema/moedas/funcional', $this->sessao(['vendas_faturacao_view']))->assertForbidden();
    }
}
