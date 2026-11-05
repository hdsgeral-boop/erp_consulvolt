<?php

namespace Tests\Feature\Seguranca;

use App\Exceptions\ErroNegocio;
use App\Support\Seguranca\ValidadorUploadSeguro;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/** ADR-083: ValidadorUploadSeguro — um teste por tipo malicioso, mais os ficheiros bons que têm de passar. */
final class ValidadorUploadSeguroTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $t) {
            @unlink($t);
        }
        parent::tearDown();
    }

    private function ficheiro(string $nome, string $conteudo): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($caminho, $conteudo);
        $this->temporarios[] = $caminho;

        return new UploadedFile($caminho, $nome, 'application/octet-stream', null, true);
    }

    private function xlsx(array $extra = [], string $conteudoTipos = '<Types/>'): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'zip');
        $z = new ZipArchive;
        $z->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $z->addFromString('[Content_Types].xml', $conteudoTipos);
        $z->addFromString('xl/workbook.xml', '<workbook/>');
        foreach ($extra as $n => $c) {
            $z->addFromString($n, $c);
        }
        $z->close();
        $b = file_get_contents($caminho);
        @unlink($caminho);

        return $b;
    }

    private function recusa(UploadedFile $f, string $perfil, string|array $codigo): void
    {
        try {
            ValidadorUploadSeguro::validar($f, $perfil);
            $this->fail("devia recusar ({$codigo}): ".$f->getClientOriginalName());
        } catch (ErroNegocio $e) {
            $this->assertContains($e->codigo, (array) $codigo, 'código: '.$e->codigo);
            $this->assertSame(422, $e->estadoHttp);
        }
    }

    #[Test]
    public function aceita_ficheiros_legitimos(): void
    {
        $png = base64_decode(self::PNG);
        foreach ([
            [$this->ficheiro('foto.png', $png), 'imagem'],
            [$this->ficheiro('Foto Final.PNG', $png), 'imagem'],
            [$this->ficheiro('dados.csv', "nome;valor\n=1+1;5\nAna;3\n"), 'folha'],
            [$this->ficheiro('lista.txt', "linha\n"), 'folha'],
            [$this->ficheiro('livro.xlsx', $this->xlsx()), 'folha'],
            [$this->ficheiro('doc.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), 'documento'],
            [$this->ficheiro('copia.json', '{"a":1}'), 'copia'],
            [$this->ficheiro('chave.pem', "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n"), 'chave'],
        ] as [$f, $perfil]) {
            ValidadorUploadSeguro::validar($f, $perfil);
        }
        $this->assertTrue(true);
    }

    public static function nomesPerigosos(): array
    {
        return [
            'php directo' => ['shell.php', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'dupla php.jpg' => ['shell.php.jpg', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'dupla jpg.php' => ['foto.jpg.php', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'phtml' => ['x.phtml', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'phar' => ['x.phar', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'maiusculas' => ['x.PhP', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'html' => ['pagina.html', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'html.png' => ['pagina.html.png', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'svg' => ['logo.svg', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'js' => ['a.js', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'exe' => ['a.exe', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'bat' => ['a.bat', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'sh' => ['a.sh', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'htaccess' => ['x.htaccess', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'macro xlsm' => ['a.xlsm', 'FICHEIRO_EXTENSAO_PROIBIDA'],
            'nulo' => ["x.php\0.png", 'FICHEIRO_NOME_INVALIDO'],
            'nulo codificado' => ['x.php%00.png', 'FICHEIRO_NOME_INVALIDO'],
            'sem extensao' => ['semextensao', 'FICHEIRO_SEM_EXTENSAO'],
            'extensao desconhecida' => ['a.docx', 'FICHEIRO_EXTENSAO_INVALIDA'],
        ];
    }

    #[Test]
    public function validar_nome_recusa_traversal_e_separadores(): void
    {
        // o Symfony já reduz o nome do cliente ao basename no UploadedFile; o validador recusa na mesma, em profundidade
        foreach (['../../x.png', '..\x.png', 'a/b.png', 'a\b.png', '%2e%2e%2fx.png', '....//x.png'] as $nome) {
            try {
                ValidadorUploadSeguro::validarNome($nome, ['png']);
                $this->fail("devia recusar o nome {$nome}");
            } catch (ErroNegocio $e) {
                $this->assertSame('FICHEIRO_NOME_INVALIDO', $e->codigo);
            }
        }
        $this->assertSame('png', ValidadorUploadSeguro::validarNome('Foto Final (1).PNG', ['png']));
    }

    #[Test]
    #[DataProvider('nomesPerigosos')]
    public function recusa_nomes_perigosos(string $nome, string $codigo): void
    {
        $this->recusa($this->ficheiro($nome, base64_decode(self::PNG)), 'geral', $codigo);
    }

    #[Test]
    public function recusa_conteudo_que_nao_corresponde_a_extensao(): void
    {
        $this->recusa($this->ficheiro('foto.png', "<?php system(\$_GET['c']); ?>"), 'imagem', 'FICHEIRO_TIPO_INVALIDO');
        $this->recusa($this->ficheiro('foto.jpg', base64_decode(self::PNG)), 'imagem', 'FICHEIRO_TIPO_INVALIDO');   // PNG com extensão jpg
        $this->recusa($this->ficheiro('doc.pdf', 'isto não é um pdf'), 'documento', 'FICHEIRO_TIPO_INVALIDO');
        $this->recusa($this->ficheiro('livro.xlsx', 'texto simples'), 'folha', 'FICHEIRO_TIPO_INVALIDO');
        $this->recusa($this->ficheiro('livro.xls', 'texto simples'), 'folha', 'FICHEIRO_TIPO_INVALIDO');
        $this->recusa($this->ficheiro('x.png', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF"), 'imagem', 'FICHEIRO_TIPO_INVALIDO');   // executável Windows
        $this->recusa($this->ficheiro('x.csv', "\x7FELF\x02\x01\x01\x00"), 'folha', ['FICHEIRO_TIPO_INVALIDO', 'FICHEIRO_BINARIO']);   // ELF
    }

    #[Test]
    public function recusa_polyglots_imagem_com_codigo_embutido(): void
    {
        $png = base64_decode(self::PNG);
        $this->recusa($this->ficheiro('x.png', $png."\n<?php system(\$_GET['c']); ?>"), 'imagem', 'FICHEIRO_SCRIPT_EMBUTIDO');
        $this->recusa($this->ficheiro('x.png', $png.'<script>alert(1)</script>'), 'imagem', 'FICHEIRO_SCRIPT_EMBUTIDO');
        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
        $this->recusa($this->ficheiro('x.gif', $gif.'<?php echo 1;'), 'imagem_gif', 'FICHEIRO_SCRIPT_EMBUTIDO');
        $this->recusa($this->ficheiro('x.gif', $gif), 'imagem', 'FICHEIRO_EXTENSAO_INVALIDA');   // gif só no perfil que o permite
    }

    #[Test]
    public function recusa_pdf_com_javascript_e_texto_com_scripts_ou_binarios(): void
    {
        $this->recusa($this->ficheiro('x.pdf', "%PDF-1.4\n1 0 obj<</S/JavaScript/JS(app.alert(1))>>endobj"), 'documento', 'FICHEIRO_PDF_ACTIVO');
        $this->recusa($this->ficheiro('x.pdf', "%PDF-1.4\n1 0 obj<</Type/Action/S/Launch>>endobj"), 'documento', 'FICHEIRO_PDF_ACTIVO');
        $this->recusa($this->ficheiro('x.csv', "a;b\n<?php system('id'); ?>;1"), 'folha', 'FICHEIRO_SCRIPT_EMBUTIDO');
        $this->recusa($this->ficheiro('x.csv', "a;b\n<script>alert(1)</script>;1"), 'folha', ['FICHEIRO_SCRIPT_EMBUTIDO', 'FICHEIRO_TIPO_INVALIDO']);
        $this->recusa($this->ficheiro('x.xml', '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><d>&x;</d>'), 'texto', 'FICHEIRO_XML_PERIGOSO');
    }

    #[Test]
    public function recusa_xlsx_com_macros_traversal_xxe_e_bombas_de_descompressao(): void
    {
        $this->recusa($this->ficheiro('a.xlsx', $this->xlsx(['xl/vbaProject.bin' => 'macro'])), 'folha', 'FICHEIRO_ZIP_SUSPEITO');
        $this->recusa($this->ficheiro('a.xlsx', $this->xlsx(['../../evil.php' => '<?php ?>'])), 'folha', 'FICHEIRO_ZIP_SUSPEITO');
        $this->recusa($this->ficheiro('a.xlsx', $this->xlsx(['xl/shell.php' => 'x'])), 'folha', 'FICHEIRO_ZIP_SUSPEITO');
        $this->recusa($this->ficheiro('a.xlsx', $this->xlsx(['xl/sharedStrings.xml' => '<!DOCTYPE s [<!ENTITY x SYSTEM "file:///etc/passwd">]><s>&x;</s>'])), 'folha', 'FICHEIRO_XML_PERIGOSO');
        // bomba: 60 MB de zeros comprimem para ~60 KB (rácio > 150)
        $this->recusa($this->ficheiro('a.xlsx', $this->xlsx(['xl/worksheets/sheet1.xml' => str_repeat('0', 60 * 1024 * 1024)])), 'folha', 'FICHEIRO_ZIP_SUSPEITO');
    }

    #[Test]
    public function recusa_vazio_e_demasiado_grande_e_gera_nomes_aleatorios(): void
    {
        $this->recusa($this->ficheiro('a.png', ''), 'imagem', 'FICHEIRO_VAZIO');
        $this->recusa($this->ficheiro('a.txt', str_repeat('a;b'."\n", 30_000)), 'chave', 'FICHEIRO_GRANDE');
        $a = ValidadorUploadSeguro::nomeAleatorio('PNG');
        $b = ValidadorUploadSeguro::nomeAleatorio('png');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', ValidadorUploadSeguro::nomeAleatorio('p/../ng'));
    }

    #[Test]
    public function cabecalhos_de_download_forcam_descarga_sem_interpretacao(): void
    {
        $h = ValidadorUploadSeguro::cabecalhosDownload('../../a b"c.pdf', 'application/pdf');
        $this->assertSame('attachment; filename=".._.._a_b_c.pdf"', $h['Content-Disposition']);
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertStringContainsString('sandbox', $h['Content-Security-Policy']);
    }

    #[Test]
    public function o_middleware_global_recusa_ficheiros_perigosos_em_qualquer_rota_da_api(): void
    {
        // rota pública (o middleware corre antes de qualquer autenticação): o upload malicioso nem chega ao controlador
        $mau = UploadedFile::fake()->createWithContent('shell.php.png', "<?php system('id');");
        $this->post('/api/autenticacao/recuperar-password', ['email' => 'a@b.ao', 'ficheiro' => $mau], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('codigo', 'FICHEIRO_EXTENSAO_PROIBIDA');
        $mau2 = UploadedFile::fake()->createWithContent('x.png', "<?php system('id');");
        $this->post('/api/autenticacao/recuperar-password', ['email' => 'a@b.ao', 'anexos' => [$mau2]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('codigo', 'FICHEIRO_SCRIPT_EMBUTIDO');
    }

    #[Test]
    public function validar_ou_converte_erros_moles_no_codigo_do_endpoint_e_mantem_os_de_seguranca(): void
    {
        try {
            ValidadorUploadSeguro::validarOu($this->ficheiro('x.pdf', ''), 'documento', 'ANEXO_TIPO_INVALIDO', 'Tipo não aceite.');
            $this->fail('devia recusar');
        } catch (ErroNegocio $e) {
            $this->assertSame('ANEXO_TIPO_INVALIDO', $e->codigo);
            $this->assertSame('Tipo não aceite.', $e->getMessage());
        }
        try {
            ValidadorUploadSeguro::validarOu($this->ficheiro('x.pdf', "%PDF-1.4\n/JavaScript"), 'documento', 'ANEXO_TIPO_INVALIDO', 'Tipo não aceite.');
            $this->fail('devia recusar');
        } catch (ErroNegocio $e) {
            $this->assertSame('FICHEIRO_PDF_ACTIVO', $e->codigo);
        }
    }

    #[Test]
    public function minimo_do_middleware_nao_impoe_tipo_mas_recusa_executaveis_e_codigo_embutido(): void
    {
        ValidadorUploadSeguro::validarMinimo($this->ficheiro('qualquer.docx', 'conteúdo qualquer'));   // o tipo é do endpoint
        ValidadorUploadSeguro::validarMinimo($this->ficheiro('vazio.png', ''));
        foreach ([['a.php.png', 'x', 'FICHEIRO_EXTENSAO_PROIBIDA'], ['a.svg', 'x', 'FICHEIRO_EXTENSAO_PROIBIDA'], ['b.png', '<?php echo 1;', 'FICHEIRO_SCRIPT_EMBUTIDO'], ['c.csv', '<script>x</script>', 'FICHEIRO_SCRIPT_EMBUTIDO']] as [$nome, $c, $codigo]) {
            try {
                ValidadorUploadSeguro::validarMinimo($this->ficheiro($nome, $c));
                $this->fail("devia recusar {$nome}");
            } catch (ErroNegocio $e) {
                $this->assertSame($codigo, $e->codigo);
            }
        }
    }
}
