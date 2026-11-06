<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guarda: a mensagem de uma excepção genérica (Throwable, Exception, QueryException, ConnectionException…) nunca pode
 * ser posta numa resposta ao cliente. Só `ErroNegocio` tem mensagem escrita para o utilizador. Para o texto de uma
 * excepção qualquer usar `MensagemSegura::de($e, 'texto genérico')`. Ver docs/qualidade/MENSAGENS_ERRO.md.
 */
class GuardaMensagensErroTest extends TestCase
{
    /** Excepções próprias, com mensagem escrita para o utilizador. */
    private const CATCH_SEGUROS = ['ErroNegocio', 'ErroExpressao', 'ErroOrcamental', 'ValidationException'];

    /**
     * Usos conhecidos e revistos em que `getMessage()` de uma excepção genérica NÃO chega à resposta HTTP
     * (registos, estado interno, comandos de consola/migração, relatórios para a equipa técnica). Novas entradas exigem revisão.
     */
    private const PERMITIDOS = [
        'Http/Middleware/AuditarContaRoot.php',
        'Http/Controllers/Api/Autenticacao/RegistoEmpresaController.php', // só procura o nome da restrição para escolher a mensagem
        'Jobs/',
        'Console/',
        'Services/Migracao/ServicoMigracaoLegado.php',
        'Services/Sistema/PublicadorOutbox.php',
        'Services/Pagamentos/Gateway',
        'Services/Vendas/Agt/ClienteHttpAgt.php',         // só para o registo da comunicação
        'Support/POS/IntegridadePOS.php',                 // comando de consola
        'Support/Tenancy/IsolamentoRls.php',              // comando de consola
        'Services/Sistema/ServicoSaude.php',              // sanitizado por MensagemSegura
        'Exceptions/ManipuladorExcecoesApi.php',
        'Support/Api/MensagemSegura.php',
        'Services/Integracoes/IA/Regras/RegraFicheiro.php', // JsonException/ErroExpressao próprios
    ];

    /** @return list<string> ficheiros PHP de app/ */
    private function ficheiros(): array
    {
        $base = dirname(__DIR__, 2).'/app';
        $r = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php') {
                $r[] = str_replace('\\', '/', $f->getPathname());
            }
        }
        sort($r);

        return $r;
    }

    private function relativo(string $ficheiro): string
    {
        return substr($ficheiro, strpos($ficheiro, '/app/') + 5);
    }

    public function test_get_message_de_excepcoes_genericas_nao_vai_para_respostas(): void
    {
        $violacoes = [];
        foreach ($this->ficheiros() as $ficheiro) {
            $rel = $this->relativo($ficheiro);
            foreach (self::PERMITIDOS as $p) {
                if (str_starts_with($rel, $p)) {
                    continue 2;
                }
            }
            $linhas = file($ficheiro, FILE_IGNORE_NEW_LINES) ?: [];
            $catchAtual = null;
            foreach ($linhas as $i => $linha) {
                if (preg_match('/catch\s*\(\s*([^$)]+?)\s*\$\w*\s*\)/', $linha, $m)) {
                    $catchAtual = trim($m[1]);
                } elseif (preg_match('/catch\s*\(\s*([^$)]+?)\s*\)/', $linha, $m)) {
                    $catchAtual = trim($m[1]);
                }
                if (! preg_match('/->getMessage\(\)/', $linha) || preg_match('/Log::|logger\(|report\(/', implode(' ', array_slice($linhas, max(0, $i - 3), 4)))) {
                    continue;
                }
                $tipos = array_map(fn ($t) => ltrim(trim($t), '\\'), explode('|', (string) $catchAtual));
                $todosSeguros = $catchAtual !== null;
                foreach ($tipos as $t) {
                    $curto = substr($t, (int) strrpos('\\'.$t, '\\'));
                    if (! in_array($curto, self::CATCH_SEGUROS, true)) {
                        $todosSeguros = false;
                    }
                }
                if (! $todosSeguros) {
                    $violacoes[] = $rel.':'.($i + 1).' (catch '.($catchAtual ?? '?').') '.trim($linha);
                }
            }
        }

        $this->assertSame([], $violacoes, "getMessage() de excepção genérica numa resposta/resultado ao cliente.\nUse MensagemSegura::de(\$e, 'texto genérico') (ver docs/qualidade/MENSAGENS_ERRO.md):\n".implode("\n", $violacoes));
    }

    public function test_nenhum_erro_negocio_tem_texto_tecnico_literal(): void
    {
        $proibidos = '/new ErroNegocio\(\s*[\'"][^\'"]*(SQLSTATE|\/var\/www|\.php\b|Stack trace|\bSELECT\b|\bINSERT INTO\b|PDOException|QueryException)/';
        $violacoes = [];
        foreach ($this->ficheiros() as $ficheiro) {
            $conteudo = (string) file_get_contents($ficheiro);
            if (preg_match_all($proibidos, $conteudo, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$txt, $pos]) {
                    $violacoes[] = $this->relativo($ficheiro).':'.(substr_count(substr($conteudo, 0, $pos), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $violacoes, "ErroNegocio com SQL/caminho/classe na mensagem:\n".implode("\n", $violacoes));
    }

    public function test_o_manipulador_nunca_devolve_a_mensagem_crua_de_excepcoes_nem_depura_pelo_app_debug(): void
    {
        $fonte = (string) file_get_contents(dirname(__DIR__, 2).'/app/Exceptions/ManipuladorExcecoesApi.php');
        $this->assertStringNotContainsString("config('app.debug')", $fonte);
        $this->assertStringNotContainsString('getFile()', $fonte);
        $this->assertStringContainsString('MensagemSegura::', $fonte);
    }
}
