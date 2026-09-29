<?php

namespace Tests\Unit;

use App\Services\Autenticacao\VerificadorPasswordLegado;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vectores gerados com o mesmo algoritmo do legado (js/data/senhas.js — Web Crypto PBKDF2):
 *   node -e "crypto.pbkdf2Sync(pw, salt, iteracoes, 32, 'sha256').toString('base64')"
 */
final class VerificadorPasswordLegadoTest extends TestCase
{
    private const SALT = 'ABEiM0RVZneImaq7zN3u/w==';

    private const HASH_120000 = 'osuPYeOnI5bTXe+Ib78Bc3Lto7iOlxpsOeEcyEuD79U=';

    private VerificadorPasswordLegado $verificador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verificador = new VerificadorPasswordLegado;
    }

    #[Test]
    public function aceita_a_palavra_passe_correcta_com_120000_iteracoes(): void
    {
        $this->assertTrue($this->verificador->verificar('Senha@Legado2026', self::HASH_120000, self::SALT, 'PBKDF2-SHA256-120000'));
    }

    #[Test]
    public function recusa_palavra_passe_errada(): void
    {
        $this->assertFalse($this->verificador->verificar('senha@legado2026', self::HASH_120000, self::SALT, 'PBKDF2-SHA256-120000'));
    }

    #[Test]
    public function le_as_iteracoes_do_sufixo_do_algoritmo_e_suporta_utf8(): void
    {
        $this->assertTrue($this->verificador->verificar(
            'Ação-çãõ€ 123', 'bRWc9/vO8Ls8YdWu9gdpR9LR4SdYHZQBdbLvlWNCbNg=', '/+7dzLuqmYh3ZlVEMyIRAA==', 'PBKDF2-SHA256-1000'));
    }

    #[Test]
    public function algoritmo_vazio_assume_120000_iteracoes(): void
    {
        $this->assertTrue($this->verificador->verificar('Senha@Legado2026', self::HASH_120000, self::SALT, null));
    }

    #[Test]
    public function recusa_algoritmo_desconhecido_e_dados_corrompidos(): void
    {
        $this->assertFalse($this->verificador->verificar('Senha@Legado2026', self::HASH_120000, self::SALT, 'MD5-1'));
        $this->assertFalse($this->verificador->verificar('Senha@Legado2026', self::HASH_120000, self::SALT, 'PBKDF2-SHA256-abc'));
        $this->assertFalse($this->verificador->verificar('Senha@Legado2026', '***', self::SALT, 'PBKDF2-SHA256-120000'));
        $this->assertFalse($this->verificador->verificar('Senha@Legado2026', self::HASH_120000, '***', 'PBKDF2-SHA256-120000'));
    }
}
