<?php

namespace Tests\Feature;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigAssiduidade;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Support\Seguranca\GuardaUrlSaida;
use App\Support\Seguranca\ValorSemFormulas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * Correcções da auditoria OWASP Top 10 (docs/arquitetura/SEGURANCA_OWASP.md). O varrimento de controlo de acesso
 * (A01) está em OwaspControloAcessoTest.
 */
final class OwaspSegurancaTest extends TestCase
{
    /** @return array<string, array{string, bool}> url => aceite? */
    public static function urlsSaida(): array
    {
        return [
            'metadados da nuvem' => ['http://169.254.169.254/latest/meta-data/', false],
            'decimal = 169.254.169.254' => ['http://2852039166/', false],
            'hexadecimal = 127.0.0.1' => ['http://0x7f.0.0.1/', false],
            'octal = 127.0.0.1' => ['http://0177.0.0.1/', false],
            'loopback' => ['http://127.0.0.1:8080/x', false],
            'localhost' => ['http://localhost/x', false],
            'IPv6 loopback' => ['http://[::1]/x', false],
            'IPv4 mapeado em IPv6' => ['http://[::ffff:169.254.169.254]/', false],
            'IPv6 link-local' => ['http://[fe80::1]/', false],
            'serviço interno redis' => ['http://redis/', false],
            'porta do PostgreSQL' => ['http://192.168.1.50:5432/', false],
            'php-fpm' => ['http://10.0.0.5:9000/', false],
            '0.0.0.0' => ['http://0.0.0.0/', false],
            'esquema file' => ['file:///etc/passwd', false],
            'anfitrião que não resolve' => ['http://nao-existe.invalid/x', false],
            'esquema gopher' => ['gopher://192.168.1.5/', false],
            'credenciais no URL' => ['http://u:p@192.168.1.50/x', false],
            'relógio na LAN' => ['http://192.168.1.50:8080/export', true],
            'relógio na rede 10' => ['https://10.20.30.40/registos.csv', true],
            'relógio na rede 172.16' => ['http://172.16.5.9/x', true],
        ];
    }

    #[Test]
    #[DataProvider('urlsSaida')]
    public function a10_guarda_de_url_de_saida(string $url, bool $aceite): void
    {
        if (! $aceite) {
            $this->expectException(ErroNegocio::class);
        }
        $r = GuardaUrlSaida::validar($url);
        $this->assertNotEmpty($r['ips']);
    }

    #[Test]
    public function a10_relogio_com_endereco_de_metadados_e_recusado_pela_api(): void
    {
        $empresa = $this->criarEmpresa(['nif' => '5417006511']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'rh_assid_registar' => true])->id]);
        $u->empresas()->attach($empresa->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
        app(ContextoEmpresa::class)->executarComo($empresa->id,
            fn () => ConfigAssiduidade::query()->create(['relogio' => ['url' => 'http://2852039166/latest/meta-data/', 'formato' => 'JSON']]));
        Http::preventStrayRequests();

        $this->postJson('/api/rh/assiduidade/registos/importar-relogio', [], $s)->assertStatus(422)->assertJsonPath('codigo', 'URL_RELOGIO_INVALIDA');
    }

    #[Test]
    public function a03_textos_comecados_por_igual_nao_viram_formulas_no_excel(): void
    {
        $folha = (new Spreadsheet)->getActiveSheet();
        $folha->fromArray([['=HYPERLINK("http://malicioso.example","clique")', '+1+cmd|\' /C calc\'!A0', '@SUM(1)', '-5', -7.5, 'Texto normal', '12']]);

        $this->assertSame(DataType::TYPE_STRING, $folha->getCell('A1')->getDataType());
        $this->assertSame('=HYPERLINK("http://malicioso.example","clique")', $folha->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $folha->getCell('B1')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $folha->getCell('C1')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $folha->getCell('D1')->getDataType(), 'os negativos continuam números');
        $this->assertSame(DataType::TYPE_NUMERIC, $folha->getCell('E1')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $folha->getCell('F1')->getDataType());
        $this->assertSame("'=1+2", ValorSemFormulas::paraCsv('=1+2'));
    }

    #[Test]
    public function a05_cors_nao_autoriza_origens_externas_e_saude_anonima_sem_versoes(): void
    {
        $r = $this->withHeaders(['Origin' => 'https://malicioso.example'])->getJson('/api/saude');
        $this->assertFalse($r->headers->has('Access-Control-Allow-Origin'));
        $preflight = $this->call('OPTIONS', '/api/autenticacao/entrar', [], [], [], ['HTTP_ORIGIN' => 'https://malicioso.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));

        config(['app.debug' => false]);
        $saude = $this->getJson('/api/saude')->json();
        $this->assertSame('PostgreSQL', $saude['dados']['componentes']['base_dados']['detalhe'] ?? $saude['erros']['base_dados']['detalhe']);
    }

    #[Test]
    public function a07_login_com_utilizador_inexistente_custa_um_argon2id_como_o_real(): void
    {
        $hash = (new ReflectionClassConstant(ServicoAutenticacao::class, 'HASH_FICTICIO'))->getValue();
        $info = password_get_info($hash);
        $this->assertSame('argon2id', $info['algoName']);
        // os mesmos parâmetros que o Laravel usa em produção (config/hashing por omissão)
        $this->assertSame(['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1], $info['options']);

        $inicio = hrtime(true);
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'nao_existe_'.uniqid(), 'palavra_passe' => 'Errada#2026'])
            ->assertStatus(401)->assertJsonPath('codigo', 'CREDENCIAIS_INVALIDAS');
        $this->assertGreaterThan(15, (hrtime(true) - $inicio) / 1e6, 'sem utilizador também se calcula um Argon2id');
    }

    #[Test]
    public function a04_limites_proprios_nas_rotas_externas_e_pesadas(): void
    {
        $limite = fn (string $nome) => collect(Route::getRoutes()->getByName($nome)?->gatherMiddleware() ?? [])->first(fn ($m) => str_starts_with((string) $m, 'throttle:'));
        $this->assertSame('throttle:externo', $limite('sistema.cambios.bai.automatico.obter'));
        $this->assertSame('throttle:externo', $limite('rh.assiduidade.registos.relogio'));
        $this->assertSame('throttle:pesado', $limite('rh.salarios.recibos.zip'));
        $this->assertSame('throttle:pesado', $limite('rh.importacoes.colaboradores'));
        $this->assertSame('throttle:pesado', $limite('tesouraria.documentos.importar'));
        $this->assertSame('throttle:20,1', $limite('contabilidade.assistente.propor'));
    }
}
