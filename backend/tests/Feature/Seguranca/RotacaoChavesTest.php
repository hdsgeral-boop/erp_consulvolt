<?php

namespace Tests\Feature\Seguranca;

use App\Models\LigacaoAgtEmpresa;
use App\Models\Utilizador;
use App\Services\Pagamentos\SegredosGateway;
use App\Services\Seguranca\ServicoRotacaoChaves;
use App\Support\Seguranca\EncrypterCompativel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-085 — rotação de chaves e de cifra dos segredos reversíveis (MFA, AGT, gateways, IA): `APP_KEY` + `APP_PREVIOUS_KEYS`,
 * `erp:seguranca:rodar-chave`, compatibilidade com o payload legado (sem prefixo) e leitura das duas cifras (CBC/GCM).
 */
final class RotacaoChavesTest extends TestCase
{
    private string $chaveOriginal;

    private string $cifraOriginal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chaveOriginal = (string) config('app.key');
        $this->cifraOriginal = (string) config('app.cipher');
        $this->usar($this->novaChave(), [], 'AES-256-CBC');
    }

    protected function tearDown(): void
    {
        $this->usar($this->chaveOriginal, [], $this->cifraOriginal);
        parent::tearDown();
    }

    private function novaChave(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /** @param  list<string>  $anteriores */
    private function usar(string $chave, array $anteriores, string $cifra): void
    {
        config(['app.key' => $chave, 'app.previous_keys' => $anteriores, 'app.cipher' => $cifra]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function semear(): array
    {
        $u = $this->criarUtilizador();
        $u->forceFill(['mfa_segredo' => 'JBSWY3DPEHPK3PXP', 'mfa_ativo' => true])->save();
        $empresa = $this->criarEmpresa();
        DB::table('agt_ligacoes_empresa')->insert(['empresa_id' => $empresa->id, 'palavra_passe' => Crypt::encryptString('segredo-agt'), 'webhook_segredo' => Crypt::encryptString('hmac-webhook')]);
        SegredosGateway::gravar('ref_chave_api', 'chave-gateway');
        DB::table('ia_fornecedores')->insert(['fornecedor' => 'openai', 'nome' => 'OpenAI', 'chave' => SegredosGateway::cifrar('sk-ia-123')]);
        // legado da produção: payload do Crypt SEM o prefixo `enc:`
        DB::table('ia_fornecedores')->insert(['fornecedor' => 'gemini', 'nome' => 'Gemini', 'chave' => Crypt::encryptString('gm-legado')]);

        return [$u, $empresa];
    }

    private function lerTudo(Utilizador $u, int $empresaId): array
    {
        return [
            'mfa' => $u->fresh()->mfa_segredo,
            'agt' => Crypt::decryptString(DB::table('agt_ligacoes_empresa')->where('empresa_id', $empresaId)->value('palavra_passe')),
            'webhook' => Crypt::decryptString(DB::table('agt_ligacoes_empresa')->where('empresa_id', $empresaId)->value('webhook_segredo')),
            'gateway' => SegredosGateway::configuracoes()['ref_chave_api'],
            'ia' => SegredosGateway::decifrar(DB::table('ia_fornecedores')->where('fornecedor', 'openai')->value('chave')),
            'ia_legado' => SegredosGateway::decifrar(DB::table('ia_fornecedores')->where('fornecedor', 'gemini')->value('chave')),
        ];
    }

    private const ESPERADO = ['mfa' => 'JBSWY3DPEHPK3PXP', 'agt' => 'segredo-agt', 'webhook' => 'hmac-webhook', 'gateway' => 'chave-gateway', 'ia' => 'sk-ia-123', 'ia_legado' => 'gm-legado'];

    #[Test]
    public function com_a_chave_antiga_em_previous_keys_tudo_continua_legivel_e_a_rotacao_recifra_com_a_nova(): void
    {
        $antiga = (string) config('app.key');
        [$u, $empresa] = $this->semear();
        $this->assertSame(self::ESPERADO, $this->lerTudo($u, $empresa->id));

        $nova = $this->novaChave();
        $this->usar($nova, [$antiga], 'AES-256-CBC');
        $this->assertSame(self::ESPERADO, $this->lerTudo($u, $empresa->id), 'a chave antiga em APP_PREVIOUS_KEYS ainda decifra');

        $servico = app(ServicoRotacaoChaves::class);
        $antes = $servico->estado();
        $this->assertSame(6, $antes['total']['a_rodar']);
        $this->assertSame(0, $antes['total']['ilegivel']);

        // simulação não grava
        $this->assertSame(6, $servico->rodar(simular: true)['rodados']);
        $this->assertSame(6, $servico->estado()['total']['a_rodar']);

        $r = $servico->rodar();
        $this->assertSame(['rodados' => 6, 'ja_actuais' => 0, 'texto_simples_cifrado' => 0, 'ilegiveis' => 0], $r);
        $this->assertSame(0, $servico->estado()['total']['a_rodar']);
        $this->assertSame(['rodados' => 0, 'ja_actuais' => 6, 'texto_simples_cifrado' => 0, 'ilegiveis' => 0], $servico->rodar(), 'idempotente');

        // retirada a chave antiga, só a nova decifra — e decifra tudo (incluindo o legado sem prefixo, agora normalizado)
        $this->usar($nova, [], 'AES-256-CBC');
        $this->assertSame(self::ESPERADO, $this->lerTudo($u, $empresa->id));
        $this->assertStringStartsWith('enc:', DB::table('ia_fornecedores')->where('fornecedor', 'gemini')->value('chave'));
    }

    #[Test]
    public function sem_a_chave_antiga_os_segredos_ficam_ilegiveis_e_o_comando_recusa_retirar_a_chave(): void
    {
        $this->semear();
        $this->usar($this->novaChave(), [], 'AES-256-CBC');   // chave trocada SEM pôr a antiga em previous_keys

        $e = app(ServicoRotacaoChaves::class)->estado();
        $this->assertSame(6, $e['total']['ilegivel']);
        $this->assertSame(1, Artisan::call('erp:seguranca:rodar-chave', ['--estado' => true]));      // FAILURE: há ilegíveis
        $this->assertSame(1, Artisan::call('erp:seguranca:rodar-chave'));      // FAILURE: há ilegíveis
        $this->assertStringContainsString('NÃO retire a chave antiga', Artisan::output());
        // nada foi alterado nem apagado
        $this->assertSame(6, app(ServicoRotacaoChaves::class)->estado()['total']['ilegivel']);
    }

    #[Test]
    public function migra_de_cbc_para_gcm_e_o_encrypter_le_as_duas_cifras(): void
    {
        [$u, $empresa] = $this->semear();
        $payloadCbc = Crypt::encryptString('abc');
        $chave = (string) config('app.key');

        $this->usar($chave, [], 'AES-256-GCM');
        $this->assertInstanceOf(EncrypterCompativel::class, Crypt::getFacadeRoot());
        $this->assertSame('abc', Crypt::decryptString($payloadCbc), 'um segredo gravado em CBC continua a decifrar-se com a cifra GCM configurada');
        $this->assertSame(self::ESPERADO, $this->lerTudo($u, $empresa->id));

        $payloadGcm = Crypt::encryptString('xyz');
        $this->assertArrayHasKey('tag', json_decode((string) base64_decode($payloadGcm), true), 'novos segredos usam GCM (AEAD)');
        $this->assertSame(6, app(ServicoRotacaoChaves::class)->estado()['total']['a_rodar']);

        $this->assertSame(6, app(ServicoRotacaoChaves::class)->rodar()['rodados']);
        $this->assertSame(0, app(ServicoRotacaoChaves::class)->estado()['total']['a_rodar']);
        $this->assertSame(self::ESPERADO, $this->lerTudo($u, $empresa->id));
        foreach (DB::table('agt_ligacoes_empresa')->pluck('palavra_passe') as $p) {
            $this->assertArrayHasKey('tag', json_decode((string) base64_decode($p), true));
        }

        // e volta atrás sem perder nada
        $this->usar($chave, [], 'AES-256-CBC');
        $this->assertSame(self::ESPERADO, $this->lerTudo($u, $empresa->id));
    }

    #[Test]
    public function o_texto_simples_legado_das_colunas_de_gateway_e_ia_e_cifrado_e_nada_se_perde(): void
    {
        DB::table('configuracoes_gateway')->updateOrInsert(['chave' => 'mcx_chave_api'], ['valor' => 'em-texto-simples', 'atualizado_em' => now()]);
        DB::table('ia_fornecedores')->insert(['fornecedor' => 'anthropic', 'nome' => 'Claude', 'chave' => 'sk-legado-texto']);

        $s = app(ServicoRotacaoChaves::class);
        $this->assertSame(2, $s->estado()['total']['texto_simples']);
        $this->assertSame(2, $s->rodar()['texto_simples_cifrado']);

        $this->assertSame('em-texto-simples', SegredosGateway::configuracoes()['mcx_chave_api']);
        $this->assertSame('sk-legado-texto', SegredosGateway::decifrar(DB::table('ia_fornecedores')->where('fornecedor', 'anthropic')->value('chave')));
        $this->assertStringNotContainsString('texto-simples', (string) DB::table('configuracoes_gateway')->where('chave', 'mcx_chave_api')->value('valor'));
    }

    #[Test]
    public function a_versao_da_chave_muda_com_a_chave_e_nao_a_revela(): void
    {
        $s = app(ServicoRotacaoChaves::class);
        $v1 = $s->versaoDaChave();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $v1);
        $chave = (string) config('app.key');
        $this->assertStringNotContainsString($v1, $chave);

        $this->usar($this->novaChave(), [$chave], 'AES-256-CBC');
        $this->assertNotSame($v1, app(ServicoRotacaoChaves::class)->versaoDaChave());
    }

    #[Test]
    public function o_inventario_cobre_todas_as_colunas_cifradas_do_codigo(): void
    {
        $inventario = collect(ServicoRotacaoChaves::INVENTARIO)->map(fn ($i) => $i['tabela'].'.'.$i['coluna'])->all();
        // as colunas dos casts `encrypted` dos modelos têm de estar no inventário (senão a rotação deixava segredos para trás)
        foreach ([Utilizador::class => 'utilizadores', LigacaoAgtEmpresa::class => 'agt_ligacoes_empresa'] as $classe => $tabela) {
            foreach ((new $classe)->getCasts() as $coluna => $cast) {
                if ($cast === 'encrypted') {
                    $this->assertContains("{$tabela}.{$coluna}", $inventario, "{$classe}::{$coluna} é cifrada mas não está em ServicoRotacaoChaves::INVENTARIO");
                }
            }
        }
        $this->assertContains('ia_fornecedores.chave', $inventario);
        $this->assertContains('configuracoes_gateway.valor', $inventario);
    }
}
