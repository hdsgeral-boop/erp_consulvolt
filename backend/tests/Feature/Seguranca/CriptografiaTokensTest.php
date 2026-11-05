<?php

namespace Tests\Feature\Seguranca;

use App\Services\Autenticacao\ServicoTotp;
use App\Services\Integracoes\PowerBI\ServicoTokensBI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * ADR-085 — Implementar.txt §5: palavras-passe com Argon2id (ou bcrypt >= 12) e rehash transparente; tokens guardados só
 * como hash; sem MD5/SHA-1 nas credenciais; segredos MFA cifrados; comparações em tempo constante.
 */
final class CriptografiaTokensTest extends TestCase
{
    #[Test]
    public function as_palavras_passe_usam_argon2id_ou_bcrypt_com_custo_12_ou_mais(): void
    {
        $info = Hash::info(Hash::make('Palavra#Passe2026'));
        $this->assertContains($info['algoName'], ['argon2id', 'bcrypt']);
        if ($info['algoName'] === 'bcrypt') {
            $this->assertGreaterThanOrEqual(12, $info['options']['cost']);
        }
        $u = $this->criarUtilizador();
        $this->assertStringStartsWith('$argon2id$', $u->fresh()->palavra_passe, 'omissão do projecto (HASH_DRIVER=argon2id)');
    }

    #[Test]
    public function um_hash_fraco_antigo_e_substituido_no_login_sem_o_utilizador_notar(): void
    {
        $u = $this->criarUtilizador();
        DB::table('utilizadores')->where('id', $u->id)->update(['palavra_passe' => password_hash('Palavra#Passe2026', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->assertTrue(Hash::needsRehash((string) DB::table('utilizadores')->where('id', $u->id)->value('palavra_passe')));

        $this->entrar($u);   // o login com a palavra-passe certa funciona

        $novo = (string) DB::table('utilizadores')->where('id', $u->id)->value('palavra_passe');
        $this->assertFalse(Hash::needsRehash($novo));
        $this->assertTrue(Hash::check('Palavra#Passe2026', $novo));
    }

    #[Test]
    public function o_segredo_mfa_fica_cifrado_e_os_codigos_de_recuperacao_so_como_hash(): void
    {
        $u = $this->criarUtilizador();
        $s = $this->entrar($u);
        $segredo = $this->postJson('/api/autenticacao/mfa/iniciar', [], $s)->assertOk()->json('dados.segredo');
        $m = new \ReflectionMethod(app(ServicoTotp::class), 'gerarCodigo');
        $codigos = $this->postJson('/api/autenticacao/mfa/confirmar', ['codigo' => $m->invoke(app(ServicoTotp::class), $segredo, intdiv(time(), 30))], $s)->assertOk()->json('dados.codigos_recuperacao');

        $linha = DB::table('utilizadores')->where('id', $u->id)->first();
        $this->assertStringNotContainsString($segredo, (string) $linha->mfa_segredo, 'o segredo TOTP não está em claro na base');
        $this->assertStringStartsWith('eyJ', (string) $linha->mfa_segredo);   // payload do encrypter do Laravel
        foreach ($codigos as $c) {
            $this->assertStringNotContainsString($c, (string) $linha->mfa_codigos_recuperacao);
        }
        $this->assertStringNotContainsString($segredo, json_encode($this->getJson('/api/autenticacao/eu', $s)->json()));
    }

    #[Test]
    public function as_chaves_de_integracao_e_as_sessoes_so_existem_na_base_como_sha256(): void
    {
        $empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador();
        $sessao = $this->entrar($u);
        $token = explode('|', substr($sessao['Authorization'], 7), 2)[1];
        $this->assertSame(hash('sha256', $token), DB::table('tokens_acesso')->value('token'));

        $bi = app(ServicoTokensBI::class)->criar($empresa->id, 'Power BI', null, null);
        $linha = DB::table('tokens_bi')->first();
        $this->assertSame(hash('sha256', $bi['token']), $linha->hash_token);
        $this->assertStringNotContainsString($bi['token'], json_encode($linha));
        $this->assertStringNotContainsString($bi['token'], json_encode($bi['registo']), 'a listagem nunca devolve o valor do token');
    }

    #[Test]
    public function nenhum_codigo_de_credenciais_usa_md5_ou_sha1(): void
    {
        $alvos = ['app/Services/Autenticacao', 'app/Http/Controllers/Api/Autenticacao', 'app/Support/Autenticacao', 'app/Models/TokenAcesso.php',
            'app/Services/Integracoes/PowerBI/ServicoTokensBI.php', 'app/Services/Seguranca', 'app/Support/Seguranca', 'app/Services/Vendas/Agt/ServicoLigacaoAgt.php',
            'app/Services/Pagamentos/SegredosGateway.php', 'app/Http/Middleware/VerificarCsrfDuploSubmit.php'];
        $ocorrencias = [];
        foreach ($alvos as $alvo) {
            $caminho = base_path($alvo);
            $ficheiros = is_dir($caminho) ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($caminho, RecursiveDirectoryIterator::SKIP_DOTS))) : [new \SplFileInfo($caminho)];
            foreach ($ficheiros as $f) {
                if ($f->getExtension() !== 'php' || $f->getFilename() === 'ServicoTotp.php') {
                    continue;   // TOTP (RFC 6238) é HMAC-SHA1 por norma, para funcionar com Google Authenticator & Cª; não é hash de credencial
                }
                $codigo = (string) file_get_contents($f->getPathname());
                $codigo = (string) preg_replace('~/\*.*?\*/~s', '', $codigo);                       // comentários de bloco
                $codigo = (string) preg_replace('~(?<![:])//[^\n]*~', '', $codigo);                // de linha (// mas não http://)
                $codigo = (string) preg_replace('~^\s*\#[^\n]*~m', '', $codigo);                   // de linha (#)
                if (preg_match('~\b(md5|sha1)\s*\(|[\'"](md5|sha1)[\'"]\s*,~i', $codigo)) {
                    $ocorrencias[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $f->getPathname());
                }
            }
        }
        $this->assertSame([], $ocorrencias, 'MD5/SHA-1 não se usam em credenciais, tokens nem assinaturas: '.implode(', ', $ocorrencias));
    }

    #[Test]
    public function as_comparacoes_de_segredos_sao_em_tempo_constante(): void
    {
        foreach (['app/Models/TokenAcesso.php', 'app/Services/Autenticacao/ServicoTotp.php', 'app/Services/Autenticacao/ServicoSessoes.php'] as $f) {
            $this->assertStringContainsString('hash_equals(', (string) file_get_contents(base_path($f)), "{$f} deve comparar com hash_equals");
        }
    }
}
