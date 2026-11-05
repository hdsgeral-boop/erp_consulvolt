<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Empresa;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Documentos do projecto: só PDF seguro, tipos por empresa, versões, descarga autenticada e eliminação lógica. Dados fictícios. */
final class DocumentosProjetoTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private int $p;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->empresa = $this->criarEmpresa(['nif' => '5417000071']);
        $ids = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => [UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Obras'])->id, CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Estaleiro'])->id]);
        $this->s = $this->sessao(['projectos_carteira_view', 'proj_gerir', 'proj_execucao', 'proj_eliminar']);
        $this->p = $this->postJson('/api/projetos', ['nome' => 'Edifício', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $ids[0], 'centro_custo_id' => $ids[1]], $this->s)->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function pdf(string $nome = 'contrato.pdf', string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n{$extra}\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    #[Test]
    public function carrega_lista_descarrega_versiona_e_elimina_logicamente(): void
    {
        $tipos = $this->getJson("/api/projetos/{$this->p}/documentos", $this->s)->assertOk()->assertJsonCount(7, 'dados.tipos')->json('dados.tipos');
        $contrato = collect($tipos)->firstWhere('nome', 'Contrato')['id'];

        $d = $this->post("/api/projetos/{$this->p}/documentos", ['ficheiro' => $this->pdf(), 'titulo' => 'Contrato de empreitada', 'tipo_projeto_id' => $contrato], $this->s)
            ->assertCreated()->assertJsonPath('dados.versao', 1)->assertJsonPath('dados.mime', 'application/pdf')->assertJsonMissingPath('dados.caminho')->json('dados');
        $this->assertSame('contrato.pdf', $d['nome_ficheiro']);

        $this->getJson("/api/projetos/{$this->p}/documentos?pesquisa=empreitada", $this->s)->assertJsonCount(1, 'dados.documentos');
        $this->getJson("/api/projetos/{$this->p}/documentos?pesquisa=zzz", $this->s)->assertJsonCount(0, 'dados.documentos');

        $r = $this->get("/api/projetos/{$this->p}/documentos/{$d['id']}/ficheiro", $this->s)->assertOk();
        $this->assertStringContainsString('attachment', $r->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        $v2 = $this->post("/api/projetos/{$this->p}/documentos/{$d['id']}/versoes", ['ficheiro' => $this->pdf('contrato_v2.pdf', '% v2')], $this->s)
            ->assertCreated()->assertJsonPath('dados.versao', 2)->assertJsonPath('dados.titulo', 'Contrato de empreitada')->json('dados');
        $lista = $this->getJson("/api/projetos/{$this->p}/documentos?tipo={$contrato}", $this->s)->assertJsonCount(1, 'dados.documentos')->json('dados.documentos');
        $this->assertSame([2, 2], [$lista[0]['versao'], $lista[0]['n_versoes']]);
        $this->getJson("/api/projetos/{$this->p}/documentos/{$d['id']}/versoes", $this->s)->assertJsonCount(2, 'dados');

        $this->deleteJson("/api/projetos/{$this->p}/documentos/{$v2['id']}", [], $this->s)->assertOk()->assertJsonPath('dados.eliminados', 1);
        $this->assertSame(1, $this->getJson("/api/projetos/{$this->p}/documentos", $this->s)->json('dados.documentos.0.versao'));
        $this->assertDatabaseHas('documentos_projeto', ['id' => $v2['id']]);   // eliminação lógica
        $this->get("/api/projetos/{$this->p}/documentos/{$v2['id']}/ficheiro", $this->s)->assertNotFound();
        $this->assertDatabaseHas('logs_auditoria', ['acao' => 'Documento descarregado']);
        $this->assertDatabaseHas('logs_auditoria', ['acao' => 'Documento eliminado']);
    }

    #[Test]
    public function recusa_ficheiros_que_nao_sao_pdf_seguro(): void
    {
        $url = "/api/projetos/{$this->p}/documentos";
        $this->post($url, ['ficheiro' => UploadedFile::fake()->createWithContent('x.pdf', 'isto não é um pdf')], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_TIPO_INVALIDO');
        $this->post($url, ['ficheiro' => UploadedFile::fake()->createWithContent('x.txt', 'texto')], $this->s)->assertStatus(422);
        $this->post($url, ['ficheiro' => $this->pdf('activo.pdf', '/JS (app.alert(1)) /OpenAction')], $this->s)->assertStatus(422);
        $this->post($url, ['ficheiro' => UploadedFile::fake()->createWithContent('x.php.pdf', "%PDF-1.4\n<?php system('x'); ?>\n%%EOF")], $this->s)->assertStatus(422);
        $this->assertDatabaseCount('documentos_projeto', 0);
    }

    #[Test]
    public function permissoes_e_isolamento_por_empresa_e_tipos_editaveis(): void
    {
        $leitor = $this->sessao(['projectos_carteira_view']);
        $d = $this->post("/api/projetos/{$this->p}/documentos", ['ficheiro' => $this->pdf()], $this->s)->assertCreated()->json('dados');
        $this->getJson("/api/projetos/{$this->p}/documentos", $leitor)->assertOk();
        $this->post("/api/projetos/{$this->p}/documentos", ['ficheiro' => $this->pdf()], $leitor)->assertForbidden();
        $this->deleteJson("/api/projetos/{$this->p}/documentos/{$d['id']}", [], $leitor)->assertForbidden();

        $outra = $this->criarEmpresa(['nif' => '5417000072']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'projectos_carteira_view' => true, 'proj_gerir' => true])->id]);
        $u->empresas()->attach($outra->id);
        $this->get("/api/projetos/{$this->p}/documentos/{$d['id']}/ficheiro", $this->entrar($u) + ['X-Empresa-Id' => $outra->id])->assertNotFound();

        // catálogo editável: criar, duplicado, desactivar quando em uso
        $this->postJson('/api/projetos/documentos/tipos', ['nome' => 'Planta'], $this->s)->assertCreated();
        $this->postJson('/api/projetos/documentos/tipos', ['nome' => 'planta'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'TIPO_DOCUMENTO_DUPLICADO');
        $tipo = collect($this->getJson('/api/projetos/documentos/tipos', $this->s)->json('dados'))->firstWhere('nome', 'Acta')['id'];
        $this->putJson("/api/projetos/{$this->p}/documentos/{$d['id']}", ['tipo_projeto_id' => $tipo], $this->s)->assertOk();
        $this->deleteJson("/api/projetos/documentos/tipos/{$tipo}", [], $this->s)->assertJsonPath('dados.resultado', 'desactivado');
    }
}
