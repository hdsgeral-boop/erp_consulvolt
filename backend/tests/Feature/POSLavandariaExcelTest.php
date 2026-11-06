<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PecaLavandaria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/** F5: modelos .xlsx de importação e relatório da lavandaria em Excel de 7 folhas. */
final class POSLavandariaExcelTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->s = $this->com(['lav_tabelas' => true, 'pos_lavandaria_view' => true]);
    }

    private function com(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + $permissoes)->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function livro(BinaryFileResponse $r): Spreadsheet
    {
        return IOFactory::load($r->getFile()->getPathname());
    }

    #[Test]
    public function os_modelos_xlsx_trazem_as_colunas_do_legado_e_voltam_a_ser_importaveis(): void
    {
        $r = $this->get('/api/pos/lavandaria/modelos/pecas', $this->s)->assertOk();
        $this->assertStringContainsString('Modelo_Pecas_Lavandaria.xlsx', (string) $r->headers->get('content-disposition'));
        $livro = $this->livro($r->baseResponse);
        $this->assertSame(['Peças', 'Como_Preencher'], $livro->getSheetNames());
        $this->assertSame('Designação *', $livro->getSheet(0)->getCell('B1')->getValue());
        $this->assertSame(['Venda por (Peça/Kg) *', 'Roupa diversa'], [$livro->getSheet(0)->getCell('E1')->getValue(), $livro->getSheet(0)->getCell('B3')->getValue()]);

        $precos = $this->livro($this->get('/api/pos/lavandaria/modelos/precos', $this->s)->assertOk()->baseResponse);
        $this->assertSame('Opção (Peça/Kg/tamanho — vazio = a 1.ª)', $precos->getSheet(0)->getCell('D1')->getValue());
        $this->get('/api/pos/lavandaria/modelos/outro', $this->s)->assertNotFound();
        $this->get('/api/pos/lavandaria/modelos/pecas', $this->com(['pos_lavandaria_view' => true]))->assertForbidden();

        // o próprio modelo (cabeçalhos com indicações) importa-se: as 2 linhas de exemplo são válidas
        $ficheiro = new UploadedFile($r->baseResponse->getFile()->getPathname(), 'Modelo_Pecas_Lavandaria.xlsx', null, null, true);
        $sim = $this->post('/api/pos/lavandaria/importar', ['tipo' => 'pecas', 'simular' => '1', 'ficheiro' => $ficheiro], $this->s + ['Accept' => 'application/json'])->assertOk()->json('dados');
        $this->assertSame([2, 2, 0], [$sim['lidas'], $sim['validas'], $sim['ignoradas']]);
        $this->assertSame(['CRIAR', 'CRIAR'], array_column($sim['linhas'], 'accao'));
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PecaLavandaria::query()->count()));
    }

    #[Test]
    public function o_relatorio_exporta_sete_folhas_e_exige_as_permissoes_do_relatorio(): void
    {
        $r = $this->get('/api/pos/lavandaria/relatorio/excel?de=2026-03-01&ate=2026-03-03', $this->s)->assertOk();
        $this->assertStringContainsString('Relatorio_Lavandaria_2026-03-01_2026-03-03.xlsx', (string) $r->headers->get('content-disposition'));
        $livro = $this->livro($r->baseResponse);
        $this->assertSame(['Resumo', 'Documentos', 'Meios de pagamento', 'Serviços', 'Peças', 'Terminais', 'Diário'], $livro->getSheetNames());
        $this->assertSame(['Ordens recebidas', 0], [$livro->getSheetByName('Resumo')->getCell('A4')->getValue(), $livro->getSheetByName('Resumo')->getCell('B4')->getValue()]);
        // diário: cabeçalho + 3 dias (1 a 3 de Março)
        $this->assertSame(4, $livro->getSheetByName('Diário')->getHighestRow());
        $this->get('/api/pos/lavandaria/relatorio/excel?de=2026-03-01', $this->s)->assertStatus(422);
        $this->get('/api/pos/lavandaria/relatorio/excel?de=2026-03-01&ate=2026-03-03', $this->com(['pos_view' => true]))->assertForbidden();
    }
}
