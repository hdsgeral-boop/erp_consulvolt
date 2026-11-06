<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Utilizador;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Matriz de campos da ENTRADA no sistema (docs/qualidade/ENTRADA_LOGIN_REGISTO.md): registo de uma empresa nova e login.
 * Para cada campo: válido, limite, vazio, só espaços, demasiado longo/curto, caracteres inválidos, Unicode, duplicado,
 * maiúsculas/minúsculas e injecção. O servidor é a autoridade (as regras do formulário são um espelho).
 */
final class EntradaNoSistemaMatrizTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /** @return array<string, mixed> */
    private function dados(array $alteracoes = []): array
    {
        static $n = 0;
        $n++;

        return array_merge([
            'nome_comercial' => "Empresa Matriz {$n}, Lda",
            'nif' => sprintf('541%07d', $n),
            'telefone' => '923000111',
            'provincia' => 'Luanda',
            'nome_completo' => 'António Manuel',
            'nome_utilizador' => "matriz.user{$n}",
            'email' => "matriz{$n}@empresa.co.ao",
            'palavra_passe' => 'Barcaça#2026x',
        ], $alteracoes);
    }

    private function registar(array $alteracoes = [])
    {
        return $this->postJson('/api/autenticacao/registar-empresa', $this->dados($alteracoes));
    }

    // ---------- registo: campo a campo ----------

    /** @return array<string, array{string, mixed, string}> campo, valor, parte da mensagem esperada */
    public static function invalidos(): array
    {
        return [
            'nome_comercial vazio' => ['nome_comercial', '', 'obrigatório'],
            'nome_comercial só espaços' => ['nome_comercial', '     ', 'obrigatório'],
            'nome_comercial 1 carácter' => ['nome_comercial', 'A', 'pelo menos 2'],
            'nome_comercial 256 caracteres' => ['nome_comercial', str_repeat('A', 256), '255'],
            'nome_comercial com < >' => ['nome_comercial', '<b>Loja</b>', 'não são permitidos'],
            'nome_comercial só símbolos' => ['nome_comercial', '!!!---', 'letras nem algarismos'],
            'nif vazio' => ['nif', '', 'obrigatório'],
            'nif curto' => ['nif', '12345678', 'NIF'],
            'nif 11 dígitos' => ['nif', '54170123456', 'NIF'],
            'nif com letras' => ['nif', '54A7012345', 'NIF'],
            'nif todos zeros' => ['nif', '0000000000', 'NIF'],
            'nif consumidor final' => ['nif', '999999999', 'NIF'],
            'nif injecção' => ['nif', "5417012345'; DROP TABLE empresas;--", 'NIF'],
            'nif como lista' => ['nif', ['5417012345'], 'NIF'],
            'telefone vazio' => ['telefone', '', 'obrigatório'],
            'telefone curto' => ['telefone', '92300', 'telefone'],
            'telefone com letras' => ['telefone', '92300011a', 'telefone'],
            'telefone começa por 1' => ['telefone', '123456789', 'telefone'],
            'provincia vazia' => ['provincia', '', 'obrigatória'],
            'provincia inexistente' => ['provincia', 'Lisboa', 'províncias'],
            'provincia injecção' => ['provincia', "Luanda'; --", 'províncias'],
            'nome_completo vazio' => ['nome_completo', '', 'obrigatório'],
            'nome_completo curto' => ['nome_completo', 'Jo', 'pelo menos 3'],
            'nome_completo com algarismos' => ['nome_completo', 'João 123', 'letras'],
            'nome_completo com emoji' => ['nome_completo', "João \u{1F600}", 'letras'],
            'nome_completo 201 caracteres' => ['nome_completo', str_repeat('a', 201), '200'],
            'nome_utilizador vazio' => ['nome_utilizador', '', 'obrigatório'],
            'nome_utilizador curto' => ['nome_utilizador', 'ab', 'entre 3 e 50'],
            'nome_utilizador longo' => ['nome_utilizador', str_repeat('a', 51), 'entre 3 e 50'],
            'nome_utilizador com espaço' => ['nome_utilizador', 'joao silva', 'letras sem acentos'],
            'nome_utilizador com @' => ['nome_utilizador', 'joao@empresa.ao', 'letras sem acentos'],
            'nome_utilizador com acento' => ['nome_utilizador', 'joão', 'letras sem acentos'],
            'nome_utilizador reservado' => ['nome_utilizador', 'admin', 'reservado'],
            'nome_utilizador reservado maiúsculas' => ['nome_utilizador', 'SUPORTE', 'reservado'],
            'email vazio' => ['email', '', 'obrigatório'],
            'email sem arroba' => ['email', 'semarroba.co.ao', 'não é válido'],
            'email com espaço' => ['email', 'joao silva@empresa.ao', 'não é válido'],
            'email 151 caracteres' => ['email', str_repeat('a', 140).'@empresa.ao', '150'],
            'palavra_passe vazia' => ['palavra_passe', '', 'obrigatória'],
            'palavra_passe 9 caracteres' => ['palavra_passe', 'Abcdef#12', 'pelo menos 10'],
            'palavra_passe sem maiúscula' => ['palavra_passe', 'abcdefgh#12', 'fraca'],
            'palavra_passe sem algarismo' => ['palavra_passe', 'Abcdefgh#xy', 'fraca'],
            'palavra_passe trivial' => ['palavra_passe', 'Password123', 'comum'],
            'palavra_passe 129 caracteres' => ['palavra_passe', 'Aa1'.str_repeat('x', 126), '128'],
            'plano inexistente' => ['plano', 'plano-que-nao-existe', 'plano'],
        ];
    }

    #[Test]
    #[DataProvider('invalidos')]
    public function registo_recusa_valores_invalidos_com_mensagem_em_portugues_no_campo(string $campo, mixed $valor, string $mensagem): void
    {
        $antes = Empresa::query()->count();
        $r = $this->registar([$campo => $valor]);

        $r->assertStatus(422);
        $this->assertArrayHasKey($campo, $r->json('erros') ?? [], json_encode($r->json()));
        $this->assertStringContainsStringIgnoringCase($mensagem, implode(' ', (array) $r->json("erros.{$campo}")));
        $this->assertSame($antes, Empresa::query()->count(), 'nada foi criado');
        $this->assertStringNotContainsString('SQLSTATE', $r->getContent());
    }

    #[Test]
    public function registo_aceita_nifs_de_empresa_e_de_pessoa_singular_e_grava_normalizado(): void
    {
        $this->registar(['nif' => ' 5417 012 345 ', 'nome_utilizador' => 'emp.dez'])->assertCreated();
        $this->assertDatabaseHas('empresas', ['nif' => '5417012345']);

        $this->registar(['nif' => '000755848la037', 'nome_utilizador' => 'pessoa.bi'])->assertCreated();
        $this->assertDatabaseHas('empresas', ['nif' => '000755848LA037']);
    }

    #[Test]
    public function registo_normaliza_telefone_provincia_email_e_utilizador(): void
    {
        $this->registar([
            'telefone' => '+244 923-000-111', 'provincia' => ' huila ', 'email' => '  Maria.Santos@Empresa.CO.AO ',
            'nome_utilizador' => '  Maria.Santos ', 'nome_completo' => "  Maria   da   Conceição  d'Ávila-Neto ",
            'nome_comercial' => '  João  &   Filhos,   Lda ',
        ])->assertCreated();

        $e = Empresa::query()->where('nome', 'João & Filhos, Lda')->firstOrFail();
        $this->assertSame('923000111', $e->telefone);
        $this->assertSame('Huíla', $e->provincia);
        $this->assertSame('maria.santos@empresa.co.ao', $e->email);
        $this->assertDatabaseHas('utilizadores', ['nome_utilizador' => 'maria.santos', 'nome_completo' => "Maria da Conceição d'Ávila-Neto"]);
    }

    /** @return array<string, array{string}> nomes legítimos que um filtro de injecção mal feito recusaria */
    public static function nomesLegitimos(): array
    {
        return [
            "O'Brien" => ["O'Brien & Filhos, Lda"], "D'Avila" => ["D'Avila Comércio Geral"], 'João & Filhos' => ['João & Filhos, Lda'],
            'select' => ['Select Consultores'], 'union' => ['Union Angola, S.A.'], 'drop' => ['Drop Express'], 'or and' => ['Pedro and Maria or Sons'],
            'traço duplo' => ['Fatura -- Janeiro Serviços'], 'acentos' => ['Ação & Reação — Comércio'], 'números' => ['3M Angola 2000'],
        ];
    }

    #[Test]
    #[DataProvider('nomesLegitimos')]
    public function registo_nao_da_falsos_positivos_em_nomes_comerciais_legitimos(string $nome): void
    {
        $this->registar(['nome_comercial' => $nome])->assertCreated();
        $this->assertDatabaseHas('empresas', ['nome' => $nome]);
    }

    #[Test]
    public function registo_com_nome_de_utilizador_repetido_responde_422_no_campo_sem_dados_de_terceiros(): void
    {
        $this->registar(['nome_utilizador' => 'dup.utilizador', 'email' => 'primeiro@empresa.co.ao'])->assertCreated();

        $r = $this->registar(['nome_utilizador' => 'Dup.Utilizador', 'email' => 'segundo@empresa.co.ao']);   // maiúsculas: a mesma conta
        $r->assertStatus(422);
        $this->assertArrayHasKey('nome_utilizador', $r->json('erros'));
        $this->assertStringNotContainsString('primeiro@empresa.co.ao', $r->getContent());
        $this->assertStringNotContainsString('Empresa Matriz', $r->getContent());
    }

    #[Test]
    public function registo_com_email_ou_nif_repetidos_responde_422_no_campo(): void
    {
        $this->registar(['nif' => '5417000001', 'email' => 'unico@empresa.co.ao', 'nome_utilizador' => 'primeiro.reg'])->assertCreated();

        $r = $this->registar(['nif' => '5417000002', 'email' => 'UNICO@empresa.co.ao', 'nome_utilizador' => 'segundo.reg']);
        $r->assertStatus(422);
        $this->assertArrayHasKey('email', $r->json('erros'));

        $r = $this->registar(['nif' => ' 5417 000 001', 'nome_utilizador' => 'terceiro.reg']);
        $r->assertStatus(422);
        $this->assertArrayHasKey('nif', $r->json('erros'));
        $this->assertStringNotContainsString('Empresa Matriz', $r->getContent(), 'não revela o nome da empresa dona do NIF');
    }

    #[Test]
    public function registo_recusa_palavra_passe_com_o_utilizador_ou_o_email(): void
    {
        $r = $this->registar(['nome_utilizador' => 'joana.neto', 'palavra_passe' => 'Joana.Neto#2026']);
        $r->assertStatus(422);
        $this->assertArrayHasKey('palavra_passe', $r->json('erros'));

        $r = $this->registar(['email' => 'rodrigo.alves@x.ao', 'palavra_passe' => 'Rodrigo.Alves9']);
        $r->assertStatus(422);
        $this->assertArrayHasKey('palavra_passe', $r->json('erros'));
    }

    #[Test]
    public function registo_com_corpo_sem_campos_devolve_todos_os_erros_de_uma_vez(): void
    {
        $r = $this->postJson('/api/autenticacao/registar-empresa', []);
        $r->assertStatus(422);
        foreach (['nome_comercial', 'nif', 'telefone', 'provincia', 'nome_completo', 'nome_utilizador', 'email', 'palavra_passe'] as $c) {
            $this->assertArrayHasKey($c, $r->json('erros'), $c);
        }
    }

    #[Test]
    public function registo_aceita_frase_com_espacos_no_meio_e_o_login_trata_as_pontas_da_mesma_forma(): void
    {
        // o TrimStrings do Laravel tira os espaços das pontas em TODOS os pedidos (registo e login igual): nunca há desencontro
        $this->registar(['nome_utilizador' => 'entra.depois', 'palavra_passe' => ' Frase Longa #2026 '])->assertCreated();

        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'entra.depois', 'palavra_passe' => 'Frase Longa #2026'])->assertOk();
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'entra.depois', 'palavra_passe' => 'Frase  Longa #2026'])->assertStatus(401);
    }

    // ---------- login ----------

    private function conta(string $nome = 'login.teste', array $extra = []): Utilizador
    {
        return $this->criarUtilizador(array_merge(['nome_utilizador' => $nome, 'email' => $nome.'@empresa.co.ao'], $extra));
    }

    #[Test]
    public function login_ignora_espacos_e_invisiveis_nas_pontas_e_aceita_o_email_sem_distinguir_maiusculas(): void
    {
        $this->conta('joao.login');

        foreach (['joao.login', '  joao.login  ', "joao.login\u{200B}", "\u{00A0}joao.login"] as $nome) {
            $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $nome, 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();
        }
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'JOAO.LOGIN@EMPRESA.CO.AO', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();
        // o nome de utilizador continua a comparar-se de forma exacta (paridade com o legado: AutenticacaoTest)
        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'Joao.Login', 'palavra_passe' => 'Palavra#Passe2026'])->assertStatus(401);
    }

    #[Test]
    public function login_com_conta_antiga_em_maiusculas_continua_a_entrar_pela_forma_exacta(): void
    {
        $this->conta('Antigo.Maiusculas');

        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'Antigo.Maiusculas', 'palavra_passe' => 'Palavra#Passe2026'])->assertOk();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function loginsInvalidos(): array
    {
        return [
            'sem utilizador' => [['palavra_passe' => 'x'], 'nome_utilizador'],
            'utilizador vazio' => [['nome_utilizador' => '', 'palavra_passe' => 'x'], 'nome_utilizador'],
            'utilizador só espaços' => [['nome_utilizador' => '    ', 'palavra_passe' => 'x'], 'nome_utilizador'],
            'utilizador 151 caracteres' => [['nome_utilizador' => str_repeat('a', 151), 'palavra_passe' => 'x'], 'nome_utilizador'],
            'utilizador como lista' => [['nome_utilizador' => ['a'], 'palavra_passe' => 'x'], 'nome_utilizador'],
            'sem palavra-passe' => [['nome_utilizador' => 'abc'], 'palavra_passe'],
            'palavra-passe vazia' => [['nome_utilizador' => 'abc', 'palavra_passe' => ''], 'palavra_passe'],
            'palavra-passe 256 caracteres' => [['nome_utilizador' => 'abc', 'palavra_passe' => str_repeat('a', 256)], 'palavra_passe'],
            'palavra-passe como objecto' => [['nome_utilizador' => 'abc', 'palavra_passe' => ['x' => 1]], 'palavra_passe'],
        ];
    }

    /** @param array<string, mixed> $corpo */
    #[Test]
    #[DataProvider('loginsInvalidos')]
    public function login_valida_os_campos_em_portugues(array $corpo, string $campo): void
    {
        $r = $this->postJson('/api/autenticacao/entrar', $corpo);

        $r->assertStatus(422);
        $this->assertArrayHasKey($campo, $r->json('erros'));
    }

    #[Test]
    public function login_nao_rebenta_com_caracteres_de_controlo_nem_injecao_e_responde_o_mesmo_que_conta_inexistente(): void
    {
        $this->conta('existe.mesmo');

        $inexistente = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'nao.existe', 'palavra_passe' => 'Errada#2026x']);
        $errada = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'existe.mesmo', 'palavra_passe' => 'Errada#2026x']);
        $inexistente->assertStatus(401);
        $errada->assertStatus(401);
        $this->assertSame($inexistente->json('mensagem'), $errada->json('mensagem'));
        $this->assertSame($inexistente->json('codigo'), $errada->json('codigo'));

        foreach (["x' OR '1'='1", "admin'--", "abc\x00def", str_repeat('é', 150), "\u{1F600}\u{1F600}", '<script>alert(1)</script>'] as $nome) {
            $r = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => $nome, 'palavra_passe' => "p\x00ass"]);
            $this->assertContains($r->getStatusCode(), [401, 422], 'nunca 500: '.json_encode($nome));
        }
    }

    #[Test]
    public function login_de_conta_inactiva_responde_como_credenciais_invalidas(): void
    {
        $this->conta('inactivo.x', ['ativo' => false]);

        $r = $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'inactivo.x', 'palavra_passe' => 'Palavra#Passe2026']);
        $r->assertStatus(401);
        $this->assertSame('CREDENCIAIS_INVALIDAS', $r->json('codigo'));
    }

    // ---------- recuperação ----------

    #[Test]
    public function recuperacao_responde_igual_exista_ou_nao_a_conta_e_aceita_maiusculas(): void
    {
        $this->conta('recupera.me');

        $existe = $this->postJson('/api/autenticacao/recuperar-password', ['identificador' => 'Recupera.Me@Empresa.co.ao']);
        $naoExiste = $this->postJson('/api/autenticacao/recuperar-password', ['identificador' => 'ninguem@sitio.ao']);

        $existe->assertOk();
        $naoExiste->assertOk();
        $this->assertSame($existe->json('dados.mensagem'), $naoExiste->json('dados.mensagem'));
        $this->assertSame($existe->json('mensagem'), $naoExiste->json('mensagem'));
    }

    #[Test]
    public function recuperacao_valida_o_campo(): void
    {
        $this->postJson('/api/autenticacao/recuperar-password', [])->assertStatus(422);
        $r = $this->postJson('/api/autenticacao/recuperar-password', ['identificador' => str_repeat('a', 256)]);
        $r->assertStatus(422);
        $this->assertArrayHasKey('identificador', $r->json('erros'));
        $this->postJson('/api/autenticacao/recuperar-password', ['identificador' => "a\x00b' OR 1=1"])->assertOk();
    }
}
