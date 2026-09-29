<?php

namespace Tests;

use App\Models\Empresa;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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

    /** Inicia sessão pela API e devolve os cabeçalhos Authorization prontos a usar. */
    protected function entrar(Utilizador $utilizador, string $palavraPasse = 'Palavra#Passe2026'): array
    {
        $token = $this->postJson('/api/autenticacao/entrar', [
            'nome_utilizador' => $utilizador->nome_utilizador,
            'palavra_passe' => $palavraPasse,
        ])->assertOk()->json('dados.token');

        return ['Authorization' => "Bearer {$token}"];
    }

    /** Estrutura comum a todas as respostas da API. */
    protected function envelope(bool $sucesso = true): array
    {
        return $sucesso
            ? ['sucesso', 'mensagem', 'dados', 'metadados' => ['empresa_id', 'executado_em']]
            : ['sucesso', 'mensagem', 'codigo', 'dados', 'metadados' => ['empresa_id', 'executado_em']];
    }
}
