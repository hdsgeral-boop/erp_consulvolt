<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Services\Imobiliario\ServicoModelosDocumento;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gestão Imobiliária, Onda 1 (modelos e taxas): importação idempotente dos 8 modelos de fábrica, motor de variáveis
 * (`[Rótulo]` e faltas), versões, unicidade, taxas por ano com herança, limites, estado «por validar» e isolamento por empresa.
 */
final class ImobiliarioModelosTaxasTest extends TestCase
{
    private Empresa $empresa;

    private array $editor;

    private array $leitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000456']);
        $this->editor = $this->sessao(['imo_modelos_view', 'imo_modelo_editar', 'imo_taxas_editar']);
        $this->leitor = $this->sessao(['imo_modelos_view']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    // ---------------------------------------------------------------- modelos

    #[Test]
    public function importar_os_modelos_por_omissao_e_idempotente(): void
    {
        $this->postJson('/api/imobiliario/modelos/importar-padrao', [], $this->leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/modelos/importar-padrao', [], $this->editor)->assertOk()->assertJsonPath('dados.importados', 8)->assertJsonPath('mensagem', '8 modelo(s) importado(s).');
        $this->postJson('/api/imobiliario/modelos/importar-padrao', [], $this->editor)->assertOk()->assertJsonPath('dados.importados', 0);
        $lista = $this->getJson('/api/imobiliario/modelos', $this->leitor)->assertOk()->json('dados');
        $this->assertCount(8, $lista);
        $this->assertSame(['ACTUALIZACAO', 'AVISO', 'CONTRATO', 'CONTRATO_SUB', 'DENUNCIA', 'INTERPELACAO', 'RECIBO', 'VISTORIA'], array_column($lista, 'tipo'));
        $this->assertSame([true], array_values(array_unique(array_column($lista, 'sistema'))));
        $this->assertSame(1, $this->getJson('/api/imobiliario/modelos?tipo=CONTRATO', $this->leitor)->json('dados.0.versao'));
        $this->assertStringContainsString('CLÁUSULA 6.ª (Caução)', $this->getJson('/api/imobiliario/modelos?tipo=CONTRATO', $this->leitor)->json('dados.0.corpo'));
        // um modelo apagado volta com a importação; um renomeado não é reposto (dedupe por tipo e nome, como no legado)
        $recibo = collect($lista)->firstWhere('tipo', 'RECIBO')['id'];
        $this->deleteJson("/api/imobiliario/modelos/{$recibo}", [], $this->leitor)->assertForbidden();
        $this->deleteJson("/api/imobiliario/modelos/{$recibo}", [], $this->editor)->assertOk();
        $this->assertSame(1, $this->postJson('/api/imobiliario/modelos/importar-padrao', [], $this->editor)->json('dados.importados'));
        $this->assertSame(8, DB::table('modelos_documento_imo')->where('empresa_id', $this->empresa->id)->count());
    }

    #[Test]
    public function o_catalogo_traz_8_tipos_e_59_variaveis(): void
    {
        $c = $this->getJson('/api/imobiliario/modelos/catalogo', $this->leitor)->assertOk()->json('dados');
        $this->assertCount(8, $c['tipos']);
        $this->assertCount(59, $c['variaveis']);
        $this->assertSame('Data de hoje', $c['variaveis'][0]['rotulo']);
        $this->getJson('/api/imobiliario/modelos/catalogo', $this->sessao(['dashboard_view']))->assertForbidden();
    }

    #[Test]
    public function criar_e_editar_modelos_valida_variaveis_nomes_e_sobe_a_versao(): void
    {
        $corpo = ['tipo' => 'AVISO', 'nome' => 'Aviso curto', 'assunto' => 'Renda de {{periodo}}', 'corpo' => 'Exmo. {{inquilino}}, a renda de {{renda}} vence a {{vencimento}}.'];
        $id = $this->postJson('/api/imobiliario/modelos', $corpo, $this->editor)->assertCreated()->assertJsonPath('dados.sistema', false)->assertJsonPath('dados.versao', 1)->json('dados.id');
        $this->postJson('/api/imobiliario/modelos', $corpo, $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'Já existe um modelo com este nome para este tipo de documento.');
        $this->postJson('/api/imobiliario/modelos', ['nome' => 'AVISO CURTO'] + $corpo, $this->editor)->assertStatus(422)->assertJsonPath('codigo', 'MODELO_DUPLICADO');
        $this->postJson('/api/imobiliario/modelos', ['nome' => 'Outro tipo', 'tipo' => 'RECIBO'] + $corpo, $this->editor)->assertCreated();
        $this->postJson('/api/imobiliario/modelos', ['nome' => 'Mau', 'corpo' => 'Olá {{inquilino}} {{coisa_inexistente}} {{outra}}'] + $corpo, $this->editor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Variáveis que não existem: coisa_inexistente, outra.');
        $this->postJson('/api/imobiliario/modelos', ['nome' => 'M', 'tipo' => 'XPTO'] + $corpo, $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'Escolha o tipo de documento.');
        $this->postJson('/api/imobiliario/modelos', ['nome' => ' '] + $corpo, $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'Dê um nome ao modelo.');
        $this->postJson('/api/imobiliario/modelos', ['corpo' => '  '] + $corpo, $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'O modelo não pode ficar sem texto.');
        $this->postJson('/api/imobiliario/modelos', $corpo, $this->leitor)->assertForbidden();
        // editar o texto sobe a versão; editar só o estado não
        $this->putJson("/api/imobiliario/modelos/{$id}", ['corpo' => 'Novo texto {{renda}}'] + $corpo, $this->editor)->assertOk()->assertJsonPath('dados.versao', 2);
        $this->putJson("/api/imobiliario/modelos/{$id}", ['corpo' => 'Novo texto {{renda}}', 'activo' => false] + $corpo, $this->editor)->assertOk()
            ->assertJsonPath('dados.versao', 2)->assertJsonPath('dados.activo', false);
        $this->putJson("/api/imobiliario/modelos/{$id}", $corpo, $this->leitor)->assertForbidden();
        $this->putJson('/api/imobiliario/modelos/999999', $corpo, $this->editor)->assertNotFound();
    }

    #[Test]
    public function o_motor_de_variaveis_deixa_o_rotulo_entre_parenteses_e_lista_as_faltas(): void
    {
        $s = app(ServicoModelosDocumento::class);
        $r = $s->renderizar('Renda {{renda}} do {{ inquilino }}; renda {{renda}} outra vez; fim {{vencimento}}.', ['renda' => '85 000,00 Kz', 'inquilino' => '']);
        $this->assertSame('Renda 85 000,00 Kz do [Inquilino]; renda 85 000,00 Kz outra vez; fim [Data de vencimento].', $r['texto']);
        $this->assertSame(['Inquilino', 'Data de vencimento'], $r['faltas']);
        $p = $this->postJson('/api/imobiliario/modelos/pre-visualizar', ['assunto' => 'Renda de {{periodo}}', 'corpo' => 'Olá {{inquilino}} {{caucao}}', 'valores' => ['inquilino' => 'Maria']], $this->leitor)
            ->assertOk()->json('dados');
        $this->assertSame('Olá Maria [Caução]', $p['corpo']);
        $this->assertSame('Renda de [Período da renda]', $p['assunto']);
        $this->assertSame(['Caução', 'Período da renda'], $p['faltas']);
        $this->assertSame([], $p['invalidas']);
        $this->postJson('/api/imobiliario/modelos/pre-visualizar', ['corpo' => 'x {{nada}}'], $this->leitor)->assertOk()->assertJsonPath('dados.invalidas', ['nada']);
        $this->postJson('/api/imobiliario/modelos/pre-visualizar', [], $this->leitor)->assertStatus(422)->assertJsonPath('erros.corpo.0', 'O modelo não pode ficar sem texto.');
        $this->postJson('/api/imobiliario/modelos/pre-visualizar', ['corpo' => 'x'], $this->sessao(['dashboard_view']))->assertForbidden();
    }

    #[Test]
    public function os_modelos_de_uma_empresa_nao_aparecem_noutra(): void
    {
        $this->postJson('/api/imobiliario/modelos/importar-padrao', [], $this->editor)->assertOk();
        $id = $this->getJson('/api/imobiliario/modelos', $this->leitor)->json('dados.0.id');
        $outra = $this->criarEmpresa(['nif' => '5417000888']);
        $b = $this->sessao(['imo_modelos_view', 'imo_modelo_editar', 'imo_taxas_editar'], $outra);
        $this->assertSame([], $this->getJson('/api/imobiliario/modelos', $b)->json('dados'));
        $this->putJson("/api/imobiliario/modelos/{$id}", ['tipo' => 'AVISO', 'nome' => 'X', 'corpo' => 'x'], $b)->assertNotFound();
        $this->deleteJson("/api/imobiliario/modelos/{$id}", [], $b)->assertNotFound();
        $this->assertSame(8, $this->postJson('/api/imobiliario/modelos/importar-padrao', [], $b)->json('dados.importados'));
        $this->assertSame(16, DB::table('modelos_documento_imo')->count());
    }

    // ---------------------------------------------------------------- taxas

    #[Test]
    public function sem_registo_o_ano_traz_os_valores_de_arranque_por_validar(): void
    {
        $r = $this->getJson('/api/imobiliario/taxas?ano=2026', $this->leitor)->assertOk()->json('dados');
        $this->assertSame(['BASE', null, 'POR_VALIDAR'], [$r['origem'], $r['herdada_de'], $r['estado_validacao']]);
        $this->assertEquals(25, $r['taxas']['ip_taxa']);
        $this->assertEquals(60, $r['taxas']['ip_base']);
        $this->assertEquals(15, $r['ip_efectivo']);   // 25 % × 60 % da renda
        $this->assertEquals(0.1, $r['taxas']['selo_habitacao']);
        $this->assertEquals(0.4, $r['taxas']['selo_outro']);
        $this->assertEquals(0, $r['taxas']['iva_arrendamento']);
        $this->assertEquals(7, $r['taxas']['iva_alojamento']);
        $this->assertEquals(2000, $r['taxas']['escritura_fixo']);
        $this->assertEquals(5000000, $r['taxas']['det_escalao1_ate']);
        $this->assertEquals(6000000, $r['taxas']['det_escalao2_ate']);
        $this->assertEquals(5000, $r['taxas']['det_escalao2_fixo']);
        $this->assertEquals(12, $r['taxas']['det_agravamento_meses']);
        $this->assertCount(21, $r['taxas']);
        $this->assertStringContainsString('validar pelo contabilista ou jurista', $r['aviso']);
        $this->assertSame([], $r['anos_gravados']);
        $this->assertSame(0, DB::table('taxas_imobiliario')->count());   // consultar nunca grava
        $this->getJson('/api/imobiliario/taxas?ano=1999', $this->leitor)->assertStatus(422);
        $this->getJson('/api/imobiliario/taxas', $this->sessao(['dashboard_view']))->assertForbidden();
    }

    #[Test]
    public function gravar_taxas_herda_o_ano_anterior_valida_limites_e_volta_a_por_validar(): void
    {
        $this->putJson('/api/imobiliario/taxas/2026', ['ip_taxa' => 20], $this->leitor)->assertForbidden();
        $r = $this->putJson('/api/imobiliario/taxas/2026', ['ip_taxa' => 20, 'mora_mensal' => 1.5, 'isencao_ate' => 30000000], $this->editor)->assertOk()
            ->assertJsonPath('mensagem', 'Taxas de 2026 gravadas. As rendas já facturadas não mudam.')->json('dados');
        $this->assertSame(['GRAVADA', 'POR_VALIDAR'], [$r['origem'], $r['estado_validacao']]);
        $this->assertEquals(20, $r['taxas']['ip_taxa']);
        $this->assertEquals(60, $r['taxas']['ip_base']);   // o que não veio mantém o valor de arranque
        $this->assertEquals(12, $r['ip_efectivo']);   // 20 × 60 ÷ 100
        $this->assertEquals(1.5, $r['taxas']['mora_mensal']);
        $this->assertSame([2026], $r['anos_gravados']);
        // 2027 herda de 2026 (o legado voltava aos valores base)
        $h = $this->getJson('/api/imobiliario/taxas?ano=2027', $this->leitor)->json('dados');
        $this->assertSame(['HERDADA', 2026], [$h['origem'], $h['herdada_de']]);
        $this->assertEquals(20, $h['taxas']['ip_taxa']);
        $this->assertEquals(30000000, $h['taxas']['isencao_ate']);
        // 2025 (anterior ao primeiro registo) continua com os valores de arranque
        $this->assertSame('BASE', $this->getJson('/api/imobiliario/taxas?ano=2025', $this->leitor)->json('dados.origem'));
        // limites
        $this->putJson('/api/imobiliario/taxas/2026', ['ip_taxa' => 101], $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'As percentagens do Imposto Predial não podem passar de 100%.');
        $this->putJson('/api/imobiliario/taxas/2026', ['selo_outro' => 150], $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'As percentagens não podem passar de 100%.');
        $this->putJson('/api/imobiliario/taxas/2026', ['escritura_fixo' => -1], $this->editor)->assertStatus(422);
        $this->putJson('/api/imobiliario/taxas/2026', ['escritura_fixo' => 'abc'], $this->editor)->assertStatus(422);
        $this->putJson('/api/imobiliario/taxas/1999', ['ip_taxa' => 1], $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'Indique um ano entre 2000 e 2100.');
        $this->assertSame(1, DB::table('taxas_imobiliario')->where('empresa_id', $this->empresa->id)->count());   // nunca duplica o ano
        $this->putJson('/api/imobiliario/taxas/2026', ['ip_taxa' => 25], $this->editor)->assertOk();
        $this->assertSame(1, DB::table('taxas_imobiliario')->where('empresa_id', $this->empresa->id)->count());
    }

    #[Test]
    public function validar_regista_quem_confirmou_e_qualquer_alteracao_volta_a_por_validar(): void
    {
        $this->postJson('/api/imobiliario/taxas/2026/validar', ['nota' => 'Dra. Ana, contabilista'], $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'Grave primeiro as taxas deste ano.');
        $this->putJson('/api/imobiliario/taxas/2026', ['ip_taxa' => 25], $this->editor)->assertOk();
        $this->postJson('/api/imobiliario/taxas/2026/validar', [], $this->editor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Indique quem confirmou as taxas (nome e função) para ficar registado.');
        $this->postJson('/api/imobiliario/taxas/2026/validar', ['nota' => 'Dra. Ana, contabilista'], $this->leitor)->assertForbidden();
        $v = $this->postJson('/api/imobiliario/taxas/2026/validar', ['nota' => 'Dra. Ana, contabilista'], $this->editor)->assertOk()->json('dados');
        $this->assertSame(['VALIDADA', 'Dra. Ana, contabilista'], [$v['estado_validacao'], $v['validacao_nota']]);
        $this->assertNotNull($v['validada_em']);
        $this->assertNotNull($v['validada_por']);
        // alterar uma taxa tira a validação
        $a = $this->putJson('/api/imobiliario/taxas/2026', ['selo_habitacao' => 0.2], $this->editor)->assertOk()->json('dados');
        $this->assertSame(['POR_VALIDAR', null, null], [$a['estado_validacao'], $a['validacao_nota'], $a['validada_em']]);
    }

    #[Test]
    public function as_taxas_de_uma_empresa_nao_passam_para_outra(): void
    {
        $this->putJson('/api/imobiliario/taxas/2026', ['ip_taxa' => 10], $this->editor)->assertOk();
        $outra = $this->criarEmpresa(['nif' => '5417000999']);
        $b = $this->sessao(['imo_modelos_view', 'imo_taxas_editar'], $outra);
        $r = $this->getJson('/api/imobiliario/taxas?ano=2026', $b)->json('dados');
        $this->assertSame('BASE', $r['origem']);
        $this->assertEquals(25, $r['taxas']['ip_taxa']);
        $this->putJson('/api/imobiliario/taxas/2026', ['ip_taxa' => 30], $b)->assertOk();
        $this->assertEquals(10, $this->getJson('/api/imobiliario/taxas?ano=2026', $this->editor)->json('dados.taxas.ip_taxa'));
        $this->assertSame(2, DB::table('taxas_imobiliario')->count());
    }
}
