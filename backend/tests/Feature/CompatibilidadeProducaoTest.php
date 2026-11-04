<?php

namespace Tests\Feature;

use App\Services\IA\Agente\ServicoConfiguracaoIA;
use App\Services\Pagamentos\SegredosGateway;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Compatibilidade com a base da produção: a migração 2026_11_21_090000 (fusão dos perfis por cliente e política de leitura
 * da auditoria da plataforma) e a decifra tolerante dos segredos. As condições da produção reproduzem-se no teste: coluna
 * `perfis_utilizador.tenant_id` NOT NULL e a política `auditoria_ler_tenant` (tudo dentro da transacção do teste).
 *
 * Fica por ensaio manual (precisa da base real): o efeito da RLS com a role `erp_app` (o teste corre como dono, que a
 * ignora) e a coluna/trigger `tenant_id` de `logs_auditoria` (a política de teste usa uma condição equivalente, a tabela de teste não tem a coluna) — aqui só se verifica a existência e a definição da política.
 */
final class CompatibilidadeProducaoTest extends TestCase
{
    private const MIGRACAO = __DIR__.'/../../database/migrations/2026_11_21_090000_compatibilidade_producao_perfis_e_auditoria.php';

    private function migracao(): object
    {
        return require self::MIGRACAO;
    }

    private function perfil(string $nome, array $permissoes, int $tenant): int
    {
        return (int) DB::table('perfis_utilizador')->insertGetId([
            'nome' => $nome, 'permissoes' => json_encode($permissoes), 'tenant_id' => $tenant, 'restrito_plataforma' => false,
        ]);
    }

    /** @return array<string, int> ids dos perfis (c*, v*) e dos utilizadores (ua..ud) */
    private function cenarioProducao(): array
    {
        DB::statement('ALTER TABLE perfis_utilizador ADD COLUMN tenant_id bigint NOT NULL DEFAULT 1');
        $ids = [
            // «Contabilista»: 3 cópias idênticas (uma por cliente); «Vendas»: iguais nos clientes 3 e 4, diferente no 5
            'c1' => $this->perfil('Contabilista', ['contabilidade_ver'], 3),
            'c2' => $this->perfil('Contabilista', ['contabilidade_ver'], 4),
            'c3' => $this->perfil('Contabilista', ['contabilidade_ver'], 5),
            'v1' => $this->perfil('Vendas', ['vendas_ver'], 3),
            'v2' => $this->perfil('Vendas', ['vendas_ver'], 4),
            'v3' => $this->perfil('Vendas', ['vendas_ver', 'vendas_editar'], 5),
        ];
        $u = [];
        foreach (['c2' => 'ua', 'c3' => 'ub', 'v2' => 'uc', 'v3' => 'ud'] as $perfil => $nome) {
            $u[$nome] = $this->criarUtilizador(['nome_utilizador' => $nome, 'perfil_utilizador_id' => $ids[$perfil]])->id;
        }

        return $ids + $u;
    }

    private function tenantsDe(string $nome): array
    {
        return DB::table('perfis_utilizador')->where('nome', $nome)->orderBy('tenant_id')->pluck('tenant_id')->map(fn ($t) => (int) $t)->all();
    }

    #[Test]
    public function sem_a_coluna_tenant_id_a_migracao_nao_faz_nada(): void
    {
        $this->criarPerfil(['all' => true], 'Acesso Total');
        $this->criarPerfil(['all' => true], 'Acesso Total 2');
        $this->migracao()->up();

        $this->assertSame(2, DB::table('perfis_utilizador')->count());
        $this->assertFalse(Schema::hasTable('perfis_utilizador_pre_fusao'));
        $this->assertFalse(Schema::hasTable('utilizadores_perfil_pre_fusao'));
        $this->assertSame(0, DB::table('pg_policies')->where('policyname', 'auditoria_ler_plataforma')->count());
        $this->migracao()->down();   // também inofensivo
        $this->assertSame(2, DB::table('perfis_utilizador')->count());
    }

    #[Test]
    public function funde_as_copias_identicas_remapeia_os_utilizadores_e_preserva_as_variantes(): void
    {
        $i = $this->cenarioProducao();
        $this->migracao()->up();

        $perfis = DB::table('perfis_utilizador')->orderBy('id')->get(['id', 'nome', 'tenant_id'])->keyBy('id');
        $this->assertCount(3, $perfis);   // Contabilista, Vendas, Vendas (cliente 5)
        $this->assertSame('Contabilista', $perfis[$i['c1']]->nome);
        $this->assertSame('Vendas', $perfis[$i['v1']]->nome);
        $this->assertSame('Vendas (cliente 5)', $perfis[$i['v3']]->nome);
        $this->assertTrue($perfis->every(fn ($p) => $p->tenant_id === null));

        $perfilDe = fn (string $u) => (int) DB::table('utilizadores')->where('id', $i[$u])->value('perfil_utilizador_id');
        $this->assertSame($i['c1'], $perfilDe('ua'));
        $this->assertSame($i['c1'], $perfilDe('ub'));
        $this->assertSame($i['v1'], $perfilDe('uc'));
        $this->assertSame($i['v3'], $perfilDe('ud'));   // a variante divergente mantém o seu perfil

        // as permissões efectivas dos utilizadores não mudam
        $this->assertSame(['vendas_ver', 'vendas_editar'], json_decode((string) DB::table('perfis_utilizador')->where('id', $i['v3'])->value('permissoes'), true));
        $this->assertSame(['contabilidade_ver'], json_decode((string) DB::table('perfis_utilizador')->where('id', $i['c1'])->value('permissoes'), true));
    }

    #[Test]
    public function guarda_o_estado_original_nas_tabelas_de_salvaguarda(): void
    {
        $i = $this->cenarioProducao();
        $this->migracao()->up();

        $this->assertSame(6, DB::table('perfis_utilizador_pre_fusao')->count());
        $this->assertSame([3, 4, 5], DB::table('perfis_utilizador_pre_fusao')->where('nome', 'Contabilista')->orderBy('tenant_id')->pluck('tenant_id')->map(fn ($t) => (int) $t)->all());
        $mapa = DB::table('utilizadores_perfil_pre_fusao')->pluck('perfil_utilizador_id', 'id')->map(fn ($p) => (int) $p)->all();
        $this->assertEquals([$i['ua'] => $i['c2'], $i['ub'] => $i['c3'], $i['uc'] => $i['v2'], $i['ud'] => $i['v3']], $mapa);   // a ordem das linhas não é garantida sem ORDER BY
    }

    #[Test]
    public function a_coluna_deixa_de_ser_obrigatoria_e_um_perfil_novo_insere_sem_tenant(): void
    {
        $this->cenarioProducao();
        $this->migracao()->up();

        $novo = $this->criarPerfil(['vendas_ver'], 'Perfil novo');
        $this->assertNull(DB::table('perfis_utilizador')->where('id', $novo->id)->value('tenant_id'));
    }

    #[Test]
    public function e_idempotente_e_a_segunda_execucao_nao_altera_nada(): void
    {
        $this->cenarioProducao();
        $this->migracao()->up();
        $estado = fn () => [
            DB::table('perfis_utilizador')->orderBy('id')->get()->map(fn ($p) => (array) $p)->all(),
            DB::table('utilizadores')->orderBy('id')->pluck('perfil_utilizador_id', 'id')->all(),
            DB::table('perfis_utilizador_pre_fusao')->orderBy('id')->get()->map(fn ($p) => (array) $p)->all(),
        ];
        $antes = $estado();

        $this->migracao()->up();

        $this->assertSame($antes, $estado());   // inclui a salvaguarda: não é refeita
    }

    #[Test]
    public function down_repoe_os_perfis_e_os_vinculos_originais(): void
    {
        $i = $this->cenarioProducao();
        $this->migracao()->up();
        $this->migracao()->down();

        $this->assertSame(6, DB::table('perfis_utilizador')->count());
        $this->assertSame([3, 4, 5], $this->tenantsDe('Contabilista'));
        $this->assertSame([3, 4, 5], $this->tenantsDe('Vendas'));
        $perfilDe = fn (string $u) => (int) DB::table('utilizadores')->where('id', $i[$u])->value('perfil_utilizador_id');
        $this->assertSame([$i['c2'], $i['c3'], $i['v2'], $i['v3']], [$perfilDe('ua'), $perfilDe('ub'), $perfilDe('uc'), $perfilDe('ud')]);
        // a sequência continua para a frente: um perfil novo não colide com os repostos
        $this->assertGreaterThan($i['v3'], (int) DB::table('perfis_utilizador')->insertGetId(['nome' => 'Posterior', 'permissoes' => '{}', 'tenant_id' => 3]));
    }

    #[Test]
    public function cria_a_politica_de_leitura_da_plataforma_so_se_existir_a_do_cliente(): void
    {
        $existe = fn (string $nome) => DB::table('pg_policies')->where('tablename', 'logs_auditoria')->where('policyname', $nome)->count();

        $this->migracao()->up();   // sem auditoria_ler_tenant: nada
        $this->assertSame(0, $existe('auditoria_ler_plataforma'));

        DB::statement('CREATE POLICY auditoria_ler_tenant ON logs_auditoria FOR SELECT USING (app_tenant_id() IS NOT NULL)');
        $this->migracao()->up();
        $this->migracao()->up();   // idempotente

        $p = DB::table('pg_policies')->where('tablename', 'logs_auditoria')->where('policyname', 'auditoria_ler_plataforma')->get();
        $this->assertCount(1, $p);
        $this->assertSame('SELECT', $p[0]->cmd);
        $this->assertStringContainsString('app_tenant_id() = '.(int) config('erp.dominio.plataforma_tenant_id'), (string) $p[0]->qual);
        $this->assertSame(1, $existe('auditoria_ler_tenant'));   // a do cliente mantém-se

        $this->migracao()->down();
        $this->assertSame(0, $existe('auditoria_ler_plataforma'));
        $this->assertSame(1, $existe('auditoria_ler_tenant'));
    }

    #[Test]
    public function decifra_o_payload_legado_sem_prefixo_o_novo_com_prefixo_e_nao_rebenta_com_lixo(): void
    {
        $legado = Crypt::encryptString('sk-ant-chave-real');
        $this->assertStringStartsWith('eyJ', $legado);
        $this->assertSame('sk-ant-chave-real', SegredosGateway::decifrar($legado));

        $novo = SegredosGateway::cifrar('sk-ant-chave-real');
        $this->assertStringStartsWith('enc:', $novo);
        $this->assertSame('sk-ant-chave-real', SegredosGateway::decifrar($novo));
        $this->assertSame($novo, SegredosGateway::cifrar($novo));

        // payload com a forma certa mas que não decifra (APP_KEY diferente / MAC errado): vazio, nunca o texto cifrado
        $estragado = base64_encode(json_encode(['iv' => base64_encode(str_repeat('a', 16)), 'value' => base64_encode('xx'), 'mac' => str_repeat('0', 64), 'tag' => '']));
        $this->assertSame('', SegredosGateway::decifrar($estragado));
        $this->assertSame('', SegredosGateway::decifrar('enc:'.$estragado));
        $this->assertSame('', SegredosGateway::decifrar('enc:lixo'));

        // texto simples (incluindo algo que começa por eyJ mas não é payload do Crypt) passa tal como está; vazio e null dão vazio
        $this->assertSame('chave-em-texto', SegredosGateway::decifrar('chave-em-texto'));
        $this->assertSame('eyJhbGciOi.jwt', SegredosGateway::decifrar('eyJhbGciOi.jwt'));
        $this->assertSame('', SegredosGateway::decifrar(null));
        $this->assertSame('', SegredosGateway::decifrar(''));

        // um payload legado entregue a cifrar() só ganha o prefixo (não se cifra duas vezes)
        $this->assertSame('enc:'.$legado, SegredosGateway::cifrar($legado));
    }

    #[Test]
    public function a_chave_de_ia_legada_funciona_e_a_api_nunca_a_devolve(): void
    {
        $servico = app(ServicoConfiguracaoIA::class);
        $servico->gravarFornecedor('anthropic', ['chave' => 'sk-ant-temporaria', 'modelo_padrao' => 'claude-sonnet-4-6']);
        $this->assertStringStartsWith('enc:', (string) DB::table('ia_fornecedores')->where('fornecedor', 'anthropic')->value('chave'));   // ao gravar, com prefixo

        $segredo = 'sk-ant-legada-123456';
        DB::table('ia_fornecedores')->where('fornecedor', 'anthropic')->update(['chave' => Crypt::encryptString($segredo)]);   // como a produção grava

        $this->assertSame($segredo, $servico->chaveDe('anthropic'));
        $f = collect($servico->fornecedores())->firstWhere('fornecedor', 'anthropic');
        $this->assertTrue($f['configurado']);
        $this->assertArrayNotHasKey('chave', $f);
        $this->assertStringNotContainsString($segredo, json_encode($servico->fornecedores()));

        // payload ilegível (APP_KEY mudada): «por configurar», sem excepção e sem expor o texto cifrado
        $estragado = base64_encode(json_encode(['iv' => 'x', 'value' => 'y', 'mac' => 'z']));
        DB::table('ia_fornecedores')->where('fornecedor', 'anthropic')->update(['chave' => $estragado]);
        $this->assertNull($servico->chaveDe('anthropic'));
        $f = collect($servico->fornecedores())->firstWhere('fornecedor', 'anthropic');
        $this->assertFalse($f['configurado']);
        $this->assertStringNotContainsString($estragado, json_encode($servico->fornecedores()));
    }
}
