<?php

namespace Tests;

use App\Models\Empresa;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Support\Autenticacao\CookiesSessao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Protecção: RefreshDatabase apaga a base. Nunca correr fora de uma base "*_testes"
     * (ex.: se o ambiente do contentor sobrepuser DB_DATABASE ao phpunit.xml).
     */
    protected function beforeRefreshingDatabase(): void
    {
        $base = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($base, '_testes')) {
            fwrite(STDERR, "\nTESTES ABORTADOS: a base activa é '{$base}', não uma base de testes (*_testes).\n");
            exit(1);
        }
    }

    protected function criarEmpresa(array $atributos = []): Empresa
    {
        static $sequencia = 0;
        $sequencia++;

        return Empresa::create(array_merge([
            'nome' => "Empresa de Teste {$sequencia}, Lda",
            'nif' => sprintf('5000%06d', $sequencia),
            'estado' => Empresa::ESTADO_ATIVO,
        ], $atributos));
    }

    protected function criarPerfil(array $permissoes, string $nome = 'Perfil de teste'): PerfilUtilizador
    {
        return PerfilUtilizador::create(['nome' => $nome, 'permissoes' => $permissoes]);
    }

    protected function criarUtilizador(array $atributos = [], string $palavraPasse = 'Palavra#Passe2026'): Utilizador
    {
        static $sequencia = 0;
        $sequencia++;

        return Utilizador::create(array_merge([
            'nome_utilizador' => "utilizador{$sequencia}",
            'palavra_passe' => $palavraPasse,
            'papel' => Utilizador::PAPEL_UTILIZADOR,
            'ativo' => true,
        ], $atributos));
    }

    /**
     * Cada pedido de teste autentica de novo a partir do seu próprio token (o Laravel memoriza o utilizador
     * no guard durante o teste; sem isto, um segundo token seria ignorado).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * As passwords passam a ser sempre geradas pelo sistema (nunca escolhidas no pedido) e só existem em texto
     * simples no Mailable enviado — lê-a por reflexão (propriedade `palavraPasse`), para os testes que precisam de
     * autenticar com a password mesmo gerada sem duplicar o algoritmo de geração.
     */
    protected function palavraPasseDoEmail(object $mailable): string
    {
        $propriedade = new \ReflectionProperty($mailable, 'palavraPasse');

        return (string) $propriedade->getValue($mailable);
    }

    /**
     * Inicia sessão pela API e devolve os cabeçalhos Authorization prontos a usar. Fase D: um novo login do MESMO
     * dispositivo substitui a sessão completa anterior desse dispositivo (ServicoAutenticacao::emitirTokenCompleto)
     * — para simular dois dispositivos distintos (duas sessões a coexistir), indicar um `$dispositivo` diferente em
     * cada chamada; sem indicar, ambas caem no omisso 'api' e a segunda chamada substitui a primeira.
     */
    protected function entrar(Utilizador $utilizador, string $palavraPasse = 'Palavra#Passe2026', ?string $dispositivo = null): array
    {
        // ADR-082: o dispositivo já não vem do corpo — é o cookie HttpOnly assinado que o servidor emite. Para simular
        // vários dispositivos, apresenta-se o cookie de cada um (o omisso 'api' é sempre o mesmo dispositivo).
        $resposta = $this->comDispositivo($dispositivo ?? 'api')->postJson('/api/autenticacao/entrar', [
            'nome_utilizador' => $utilizador->nome_utilizador,
            'palavra_passe' => $palavraPasse,
        ])->assertOk();

        return ['Authorization' => 'Bearer '.$this->tokenDoCookie($resposta)];
    }

    /** Cookie de dispositivo assinado pelo servidor para o nome de dispositivo de teste indicado (sempre o mesmo id para o mesmo nome). */
    protected function comDispositivo(string $nome): static
    {
        $id = substr(CookiesSessao::b64(hash('sha256', $nome, true)), 0, 22);

        return $this->withCredentials()->withUnencryptedCookie(CookiesSessao::nome('dispositivo'), CookiesSessao::assinarDispositivo($id));
    }

    /**
     * Fase D: o token de uma sessão completa já não vai no corpo da resposta, só no cookie HttpOnly (CookiesSessao).
     * Os testes continuam a autenticar por Authorization: Bearer (também aceite — AppServiceProvider::configurarSanctum
     * tenta o cabeçalho primeiro, só cai para o cookie se faltar), para não reescrever toda a suite.
     */
    protected function tokenDoCookie(TestResponse $resposta): ?string
    {
        return $resposta->getCookie(config('erp.sessao.cookie_token'), false)?->getValue();
    }

    /** Estrutura comum a todas as respostas da API. */
    protected function envelope(bool $sucesso = true): array
    {
        return $sucesso
            ? ['sucesso', 'mensagem', 'dados', 'metadados' => ['empresa_id', 'executado_em']]
            : ['sucesso', 'mensagem', 'codigo', 'dados', 'metadados' => ['empresa_id', 'executado_em']];
    }
}
