<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * No deploy corre `php artisan optimize` (config em cache): com a config em cache, env() fora de config/ devolve sempre
 * vazio. Todas as variáveis do servidor/contentor têm de ser lidas em config/*.php e usadas por config('…').
 */
final class SemEnvForaDaConfigTest extends TestCase
{
    #[Test]
    public function o_codigo_nao_le_variaveis_de_ambiente_directamente(): void
    {
        $raiz = dirname(__DIR__, 2);
        $infractores = [];
        foreach (['app', 'routes', 'bootstrap/app.php', 'database'] as $caminho) {
            $alvo = $raiz.'/'.$caminho;
            $ficheiros = is_file($alvo) ? [$alvo] : new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($alvo, \FilesystemIterator::SKIP_DOTS));
            foreach ($ficheiros as $f) {
                $f = is_string($f) ? $f : $f->getPathname();
                if (! str_ends_with($f, '.php')) {
                    continue;
                }
                foreach (file($f) as $n => $linha) {
                    if (preg_match('/(?<![\w>:$])env\(|\bgetenv\(|\$_ENV\[|\$_SERVER\[\'[A-Z_]+\'\]/', $linha) && ! str_contains($linha, 'REMOTE_ADDR')) {
                        $infractores[] = substr($f, strlen($raiz) + 1).':'.($n + 1);
                    }
                }
            }
        }

        $this->assertSame([], $infractores, 'Ler variáveis de ambiente só em config/*.php (config em cache em produção).');
    }
}
