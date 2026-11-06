<?php

namespace Tests\Feature\Sistema;

use App\Exceptions\ErroNegocio;
use App\Support\Api\MensagemSegura;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use TypeError;

/**
 * Camada central de mensagens de erro (docs/qualidade/MENSAGENS_ERRO.md): nenhuma resposta de erro da API pode expor
 * SQL, caminhos, classes, traços ou mensagens cruas; os 5xx trazem uma `referencia` e o estado HTTP correcto.
 */
class MensagensErroSegurasTest extends TestCase
{
    /** Fragmentos que nunca podem aparecer numa resposta de erro. */
    private const PROIBIDOS = [
        'SQLSTATE', 'relation "', 'violates', 'uq_empresas', 'tabela_secreta', 'senha_secreta', '/var/www', '.php', 'Stack trace',
        'Illuminate\\', 'App\\', 'PDOException', 'QueryException', 'TypeError', 'RuntimeException', 'SELECT ', 'INSERT INTO', 'redis',
        '10.0.0.5', 'Connection refused', 'vendor/',
    ];

    /** @return array<string, array{0: \Throwable, 1: int}> */
    public static function excepcoes(): array
    {
        $pdo = new \PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "uq_empresas_tabela_secreta"');

        return [
            'QueryException' => [new QueryException('pgsql', 'SELECT senha_secreta FROM tabela_secreta WHERE id = ?', [1], $pdo), 500],
            'PDOException' => [new \PDOException('SQLSTATE[08006] could not connect to server: Connection refused (10.0.0.5:5432)'), 500],
            'TypeError' => [new TypeError('App\\Services\\Coisa::fazer(): Argument #1 must be of type int, string given, called in /var/www/html/app/X.php on line 12'), 500],
            'RuntimeException com caminho' => [new RuntimeException('Falha em /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation.php:44 Stack trace: #0'), 500],
            'Redis' => [new RuntimeException('redis: Connection refused [tcp://10.0.0.5:6379]'), 500],
            'Http 503 cru' => [new HttpException(503, 'Service Unavailable: redis down at /var/www/html'), 503],
            'Http 502' => [new HttpException(502, 'Bad Gateway SQLSTATE'), 502],
            'Http 504' => [new HttpException(504), 504],
            'CSRF' => [new TokenMismatchException('CSRF token mismatch.'), 419],
            'Pedido grande' => [new PostTooLargeException('The POST data is too large in /var/www/html/x.php'), 413],
            'Autorizacao' => [new AuthorizationException('This action is unauthorized. App\\Policies\\X'), 403],
        ];
    }

    #[DataProvider('excepcoes')]
    public function test_nenhuma_excepcao_vaza_detalhes_na_resposta(\Throwable $e, int $estado): void
    {
        Route::middleware('api')->get('api/_teste_erros', fn () => throw $e);

        $r = $this->getJson('/api/_teste_erros');

        $r->assertStatus($estado);
        $corpo = $r->getContent();
        foreach (self::PROIBIDOS as $p) {
            $this->assertStringNotContainsString($p, $corpo, "a resposta expõe «{$p}»");
        }
        $this->assertFalse($r->json('sucesso'));
        $this->assertNotEmpty($r->json('codigo'));
        $this->assertNotEmpty($r->json('mensagem'));
        $this->assertArrayNotHasKey('depuracao', $r->json('metadados'));
        if ($estado >= 500) {
            $this->assertMatchesRegularExpression('/^E-[2-9A-HJ-NP-Z]{8}$/', (string) $r->json('referencia'), 'os 5xx trazem referência');
            $this->assertSame($r->json('referencia'), $r->headers->get('X-Referencia-Erro'));
        }
    }

    public function test_o_500_nao_mostra_a_causa_mesmo_com_app_debug_e_regista_a_referencia_no_log(): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('api/_teste_erros', fn () => throw new RuntimeException('segredo interno /var/www/x.php'));
        Log::spy();

        $r = $this->getJson('/api/_teste_erros');

        $r->assertStatus(500)->assertJsonPath('codigo', 'ERRO_INTERNO');
        $this->assertStringNotContainsString('segredo', $r->getContent());
        $referencia = (string) $r->json('referencia');
        Log::shouldHaveReceived('log')->withArgs(fn ($nivel, $msg, $ctx) => $nivel === 'error' && str_contains($msg, $referencia)
            && ($ctx['referencia'] ?? null) === $referencia && $ctx['excecao'] instanceof RuntimeException)->once();
    }

    public function test_erro_de_negocio_mantem_a_mensagem_propria_mas_uma_insegura_e_neutralizada(): void
    {
        Route::middleware('api')->get('api/_teste_a', fn () => throw new ErroNegocio('O documento já está anulado.', 'DOC_ANULADO', 422));
        Route::middleware('api')->get('api/_teste_b', fn () => throw new ErroNegocio('Falhou: SQLSTATE[23503] insert into tabela_secreta violates foreign key', 'X', 422, ['campo' => 'erro em /var/www/x.php']));

        $this->getJson('/api/_teste_a')->assertStatus(422)->assertJsonPath('mensagem', 'O documento já está anulado.')->assertJsonPath('codigo', 'DOC_ANULADO');
        $b = $this->getJson('/api/_teste_b')->assertStatus(422);
        $this->assertStringNotContainsString('tabela_secreta', $b->getContent());
        $this->assertStringNotContainsString('/var/www', $b->getContent());
        $this->assertSame(MensagemSegura::generica(422), $b->json('mensagem'));
    }

    public function test_erros_de_negocio_5xx_trazem_referencia_e_validacao_mantem_os_campos(): void
    {
        Route::middleware('api')->get('api/_teste_c', fn () => throw new ErroNegocio('Não foi possível contactar a AGT.', 'AGT_INDISPONIVEL', 502));
        Route::middleware('api')->get('api/_teste_d', fn () => throw ValidationException::withMessages(['nif' => ['O NIF é obrigatório.']]));

        $this->getJson('/api/_teste_c')->assertStatus(502)->assertJsonStructure(['referencia']);
        $this->getJson('/api/_teste_d')->assertStatus(422)->assertJsonPath('codigo', 'VALIDACAO')->assertJsonPath('erros.nif.0', 'O NIF é obrigatório.')->assertJsonMissingPath('referencia');
    }

    public function test_categorias_401_e_404_tem_mensagem_propria(): void
    {
        $this->getJson('/api/rota_que_nao_existe_xyz')->assertStatus(404)->assertJsonPath('codigo', 'NAO_ENCONTRADO');
        $this->getJson('/api/autenticacao/eu')->assertStatus(401)->assertJsonPath('mensagem', 'A sua sessão terminou. Entre novamente.');
    }

    public function test_sanitizador_reconhece_texto_tecnico_e_deixa_passar_texto_de_utilizador(): void
    {
        foreach (['SQLSTATE[HY000]', 'relation "x" does not exist', 'Call to undefined method App\\Foo::bar()', 'em /var/www/html/app/A.php', 'Undefined array key "x"', 'cURL error 6: Could not resolve host', 'Stack trace: #0', 'ligação a 192.168.1.10:5432'] as $m) {
            $this->assertFalse(MensagemSegura::eSegura($m), $m);
        }
        foreach (['O NIF já existe.', 'A conta 31.1.1 não está disponível no plano de contas.', 'Saldo insuficiente: faltam 1.250,00 Kz.', 'O documento FT 2026/15 foi anulado em 03.11.2026.', 'O ficheiro excede 10 MB.'] as $m) {
            $this->assertTrue(MensagemSegura::eSegura($m), $m);
        }
    }
}
