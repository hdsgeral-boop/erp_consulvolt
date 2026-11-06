<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Procedimento;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manual de Procedimentos, Onda 1: dados de fábrica (19 narrativas, 109 etapas, 84 cruzamentos), edição por empresa sem tocar o
 * Fluxo de Processos, processos próprios, esconder/repor (B-02), chave única com escondidos (B-06), exclusões em vez de
 * inclusões (B-03), permissões no servidor (B-04), isolamento por empresa, cobertura e idempotência da migração.
 */
final class ProcedimentosManualTest extends TestCase
{
    private Empresa $empresa;

    private array $leitor;

    private array $editor;

    private array $semAcesso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000123']);
        $this->leitor = $this->sessao(['proc_manual_view']);
        $this->editor = $this->sessao(['proc_manual_view', 'proc_editar']);
        $this->semAcesso = $this->sessao(['dashboard_view']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function processo(string $chave, array $sessao): array
    {
        $r = $this->getJson('/api/procedimentos', $sessao)->assertOk()->json('dados.processos');

        return collect($r)->firstWhere('chave', $chave) ?? [];
    }

    private function corpoMinimo(string $titulo = 'Salários adaptados'): array
    {
        return ['titulo' => $titulo, 'objetivo' => 'Pagar a tempo.', 'intervenientes' => ['Tesoureiro'], 'etapas' => [
            ['nome' => 'Preparar', 'quem' => 'RH', 'descricao' => 'Reúne os dados.', 'controlos' => 'Conferência.', 'resultado' => 'Mapa pronto.'],
            ['nome' => 'Pagar', 'quem' => 'Tesoureiro', 'descricao' => '', 'controlos' => '', 'resultado' => ''],
        ], 'cruza' => [['com' => 'ferias', 'onde' => 'Preparar', 'o_que' => 'Subsídio']]];
    }

    #[Test]
    public function os_dados_de_fabrica_tem_as_contagens_do_legado(): void
    {
        $f = json_decode((string) file_get_contents(resource_path('procedimentos/narrativas.json')), true);
        $this->assertCount(19, $f);
        $this->assertSame(109, array_sum(array_map(fn ($n) => count($n['etapas']), $f)));
        $this->assertSame(84, array_sum(array_map(fn ($n) => count($n['cruza']), $f)));
        $externos = ['estrutura_manual', 'estrategia_plano'];
        foreach ($f as $chave => $n) {
            foreach ($n['cruza'] as $c) {
                $this->assertTrue(isset($f[$c['com']]) || in_array($c['com'], $externos, true), "{$chave} cruza com {$c['com']}");
            }
        }
        // o ficheiro do Fluxo de Processos continua com as suas 14 narrativas
        $fluxo = json_decode((string) file_get_contents(app_path('Services/Gestao/Fluxos/narrativas.json')), true);
        $this->assertCount(14, $fluxo);
    }

    #[Test]
    public function lista_a_fabrica_completa_sem_gravar_nada(): void
    {
        $r = $this->getJson('/api/procedimentos', $this->leitor)->assertOk();
        $ps = $r->json('dados.processos');
        $this->assertCount(19, $ps);
        $this->assertSame(109, array_sum(array_map(fn ($p) => count($p['etapas']), $ps)));
        $this->assertSame(84, array_sum(array_map(fn ($p) => count($p['cruza']), $ps)));
        $this->assertSame(['FABRICA'], array_values(array_unique(array_column($ps, 'origem'))));
        $this->assertSame([], $r->json('dados.ocultos'));
        $this->assertTrue($this->processo('rh', $this->leitor)['tem_painel']);
        $this->assertFalse($this->processo('stock', $this->leitor)['tem_painel']);
        $this->assertSame(0, DB::table('procedimentos')->count());
    }

    #[Test]
    public function sem_permissao_nao_ve_nem_escreve(): void
    {
        $this->getJson('/api/procedimentos', $this->semAcesso)->assertForbidden();
        $this->getJson('/api/procedimentos/cobertura', $this->semAcesso)->assertForbidden();
        $this->getJson('/api/manuais/procedimentos', $this->semAcesso)->assertForbidden();
        $this->postJson('/api/manuais/procedimentos/recolha', [], $this->semAcesso)->assertForbidden();
        // B-04: quem só consulta não escreve, mesmo que o menu lhe mostrasse os botões
        $this->putJson('/api/procedimentos/rh', $this->corpoMinimo(), $this->leitor)->assertForbidden();
        $this->postJson('/api/procedimentos', ['titulo' => 'Novo'], $this->leitor)->assertForbidden();
        $this->deleteJson('/api/procedimentos/rh', [], $this->leitor)->assertForbidden();
        $this->postJson('/api/procedimentos/rh/ocultar', [], $this->leitor)->assertForbidden();
        $this->postJson('/api/procedimentos/rh/mostrar', [], $this->leitor)->assertForbidden();
        $this->putJson('/api/manuais/procedimentos', ['titulo' => 'X'], $this->leitor)->assertForbidden();
        $this->assertSame(0, DB::table('procedimentos')->count());
    }

    #[Test]
    public function editar_uma_narrativa_substitui_etapas_e_cruzamentos_e_marca_como_adaptado(): void
    {
        $r = $this->putJson('/api/procedimentos/rh', $this->corpoMinimo(), $this->editor)->assertOk();
        $r->assertJsonPath('dados.origem', 'EDITADO')->assertJsonPath('dados.titulo', 'Salários adaptados');
        $p = $this->processo('rh', $this->leitor);
        $this->assertSame('EDITADO', $p['origem']);
        $this->assertTrue($p['editado']);
        $this->assertTrue($p['tem_painel']);
        $this->assertSame(['Preparar', 'Pagar'], array_column($p['etapas'], 'nome'));
        $this->assertSame([['com' => 'ferias', 'onde' => 'Preparar', 'o_que' => 'Subsídio']], $p['cruza']);
        // gravar outra vez substitui o conjunto (sem duplicar etapas)
        $c = $this->corpoMinimo('Outra vez');
        $c['etapas'] = [$c['etapas'][1]];
        $c['cruza'] = [];
        $this->putJson('/api/procedimentos/rh', $c, $this->editor)->assertOk();
        $p = $this->processo('rh', $this->leitor);
        $this->assertSame(['Pagar'], array_column($p['etapas'], 'nome'));
        $this->assertSame([], $p['cruza']);
        $this->assertSame(1, DB::table('procedimentos_etapas')->count());
        // as outras narrativas de fábrica ficam como estavam (e o Fluxo de Processos não é tocado)
        $this->assertSame('FABRICA', $this->processo('ferias', $this->leitor)['origem']);
    }

    #[Test]
    public function validacoes_de_negocio_com_a_mensagem_do_legado(): void
    {
        $semTitulo = $this->corpoMinimo();
        $semTitulo['titulo'] = '';
        $this->putJson('/api/procedimentos/rh', $semTitulo, $this->editor)->assertStatus(422)->assertJsonPath('mensagem', 'Dê um título ao processo.')->assertJsonPath('codigo', 'PROCEDIMENTO_SEM_TITULO');
        $semEtapas = $this->corpoMinimo();
        $semEtapas['etapas'] = [['nome' => '  ', 'quem' => 'x']];
        $this->putJson('/api/procedimentos/rh', $semEtapas, $this->editor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Um procedimento sem etapas não descreve nada. Acrescente pelo menos uma.');
        $cruzInvalido = $this->corpoMinimo();
        $cruzInvalido['cruza'] = [['com' => 'nao_existe', 'onde' => '', 'o_que' => '']];
        $this->putJson('/api/procedimentos/rh', $cruzInvalido, $this->editor)->assertStatus(422)->assertJsonPath('codigo', 'PROCEDIMENTO_CRUZAMENTO_INVALIDO');
        $consigo = $this->corpoMinimo();
        $consigo['cruza'] = [['com' => 'rh', 'onde' => '', 'o_que' => '']];
        $this->putJson('/api/procedimentos/rh', $consigo, $this->editor)->assertStatus(422);
        $this->putJson('/api/procedimentos/nao_existe', $this->corpoMinimo(), $this->editor)->assertStatus(404)->assertJsonPath('codigo', 'PROCEDIMENTO_NAO_ENCONTRADO');
        $this->assertSame(0, DB::table('procedimentos')->count());
        // as 109 etapas cobrem as 8 etapas de «rh» do legado; uma etapa sem «quem» grava-se mas fica avisada no documento
        $this->putJson('/api/procedimentos/rh', $this->corpoMinimo(), $this->editor)->assertOk();
    }

    #[Test]
    public function esconder_nao_copia_o_texto_de_fabrica_e_voltar_a_incluir_devolve_a_fabrica(): void
    {
        $this->postJson('/api/procedimentos/compras/ocultar', [], $this->editor)->assertOk();
        $linha = DB::table('procedimentos')->where('chave', 'compras')->first();
        // B-02: linha-bandeira sem texto, não «personalizada»
        $this->assertTrue((bool) $linha->oculto);
        $this->assertFalse((bool) $linha->personalizado);
        $this->assertNull($linha->titulo);
        $this->assertSame(0, DB::table('procedimentos_etapas')->count());
        $r = $this->getJson('/api/procedimentos', $this->leitor)->assertOk();
        $this->assertCount(18, $r->json('dados.processos'));
        $this->assertSame([['chave' => 'compras', 'titulo' => 'Processo de compra']], $r->json('dados.ocultos'));

        $this->postJson('/api/procedimentos/compras/mostrar', [], $this->editor)->assertOk();
        $this->assertSame(0, DB::table('procedimentos')->count());
        $p = $this->processo('compras', $this->leitor);
        $this->assertSame('FABRICA', $p['origem']);
        $this->assertFalse($p['editado']);
        // esconder um processo adaptado conserva o texto da empresa e voltar a incluir não o perde
        $this->putJson('/api/procedimentos/rh', $this->corpoMinimo(), $this->editor)->assertOk();
        $this->postJson('/api/procedimentos/rh/ocultar', [], $this->editor)->assertOk();
        $this->assertSame([], $this->processo('rh', $this->leitor));
        $this->postJson('/api/procedimentos/rh/mostrar', [], $this->editor)->assertOk();
        $this->assertSame('Salários adaptados', $this->processo('rh', $this->leitor)['titulo']);
        // esconder o que não existe
        $this->postJson('/api/procedimentos/nao_existe/ocultar', [], $this->editor)->assertStatus(404);
    }

    #[Test]
    public function repor_devolve_o_texto_do_erp_e_apaga_o_processo_proprio(): void
    {
        $this->putJson('/api/procedimentos/rh', $this->corpoMinimo(), $this->editor)->assertOk();
        $this->deleteJson('/api/procedimentos/rh', [], $this->editor)->assertOk();
        $this->assertSame('FABRICA', $this->processo('rh', $this->leitor)['origem']);
        $this->assertSame(0, DB::table('procedimentos_etapas')->count());
        $this->deleteJson('/api/procedimentos/rh', [], $this->editor)->assertStatus(422)->assertJsonPath('codigo', 'PROCEDIMENTO_SEM_EDICAO');

        $novo = $this->postJson('/api/procedimentos', ['titulo' => 'Recrutamento e selecção'], $this->editor)->assertCreated();
        $novo->assertJsonPath('dados.chave', 'recrutamento_e_seleccao')->assertJsonPath('dados.origem', 'PROPRIO')->assertJsonPath('dados.tem_painel', false);
        $this->assertSame(['Primeira etapa'], array_column($novo->json('dados.etapas'), 'nome'));
        $this->deleteJson('/api/procedimentos/recrutamento_e_seleccao', [], $this->editor)->assertOk();
        $this->assertSame([], $this->processo('recrutamento_e_seleccao', $this->leitor));
        $this->assertSame(0, DB::table('procedimentos')->count());
    }

    #[Test]
    public function chave_unica_por_empresa_incluindo_escondidos_e_chaves_de_fabrica(): void
    {
        $this->postJson('/api/procedimentos', ['titulo' => 'Recrutamento'], $this->editor)->assertCreated();
        $this->postJson('/api/procedimentos', ['titulo' => 'recrutamento'], $this->editor)->assertStatus(422)->assertJsonPath('codigo', 'PROCEDIMENTO_JA_EXISTE')
            ->assertJsonPath('mensagem', 'Já existe um processo com esse nome.');
        // B-06: um processo próprio escondido não é reaproveitado nem sobrescrito
        $this->postJson('/api/procedimentos/recrutamento/ocultar', [], $this->editor)->assertOk();
        $this->postJson('/api/procedimentos', ['titulo' => 'Recrutamento'], $this->editor)->assertStatus(422)->assertJsonPath('codigo', 'PROCEDIMENTO_JA_EXISTE');
        // chaves de fábrica e dos documentos externos são reservadas
        $this->postJson('/api/procedimentos', ['titulo' => 'Compras'], $this->editor)->assertStatus(422)->assertJsonPath('codigo', 'PROCEDIMENTO_JA_EXISTE');
        $this->postJson('/api/procedimentos', ['titulo' => 'Estrutura manual'], $this->editor)->assertStatus(422);
        $this->postJson('/api/procedimentos', ['titulo' => '???'], $this->editor)->assertStatus(422)->assertJsonPath('codigo', 'PROCEDIMENTO_CHAVE_INVALIDA');
        $this->postJson('/api/procedimentos', ['titulo' => ''], $this->editor)->assertStatus(422);
        // a BD também o garante
        $this->expectException(QueryException::class);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Procedimento::query()->create(['chave' => 'recrutamento', 'origem' => 'PROPRIO', 'personalizado' => true, 'titulo' => 'X']));
    }

    #[Test]
    public function um_processo_proprio_pode_ser_cruzado_e_entra_na_matriz(): void
    {
        $this->postJson('/api/procedimentos', ['titulo' => 'Recrutamento'], $this->editor)->assertCreated();
        $c = $this->corpoMinimo('Recrutamento');
        $c['cruza'] = [['com' => 'rh', 'onde' => 'Preparar', 'o_que' => 'Contrato'], ['com' => 'estrutura_manual', 'onde' => 'Pagar', 'o_que' => 'Posto']];
        $this->putJson('/api/procedimentos/recrutamento', $c, $this->editor)->assertOk();
        $d = $this->postJson('/api/manuais/procedimentos/recolha', [], $this->editor)->assertOk()->json('dados');
        $this->assertSame(20, $d['totais']['processos']);
        $this->assertSame(1, $d['totais']['proprios']);
        $this->assertSame('recrutamento', collect($d['processos'])->last()['chave']);
        $this->assertContains('recrutamento', array_merge(...array_map(fn ($l) => [$l['a'], $l['b']], $d['cruzamentos']['ligacoes'])));
        $this->assertSame('Estrutura Orgânica — Manual da Organização', collect($d['cruzamentos']['externos'])->firstWhere('de', 'recrutamento')['para']);
    }

    #[Test]
    public function definicoes_guardam_as_exclusoes_e_a_recolha_calcula_totais_e_avisos(): void
    {
        $r = $this->getJson('/api/manuais/procedimentos', $this->leitor)->assertOk();
        $r->assertJsonPath('dados.titulo', 'Manual de Procedimentos')->assertJsonPath('dados.versao', '1.0')->assertJsonPath('dados.guardado', false)
            ->assertJsonPath('dados.incluir_cobertura', false)->assertJsonPath('dados.processos_excluidos', []);

        $this->putJson('/api/manuais/procedimentos', ['titulo' => 'Manual ACME', 'versao' => '2.1', 'data_documento' => '2026-11-06', 'elaborado_por' => 'Ana',
            'incluir_cobertura' => true, 'incluir_diagrama' => false, 'processos_excluidos' => ['rh', 'pos'], 'apresentacao' => "  Olá\n\nMundo  ", 'texto_inexistente' => 'x'], $this->editor)
            ->assertOk()->assertJsonPath('dados.titulo', 'Manual ACME')->assertJsonPath('dados.versao', '2.1')->assertJsonPath('dados.apresentacao', "Olá\n\nMundo")
            ->assertJsonPath('dados.processos_excluidos', ['rh', 'pos'])->assertJsonPath('dados.incluir_diagrama', false)->assertJsonPath('dados.guardado', true);
        $this->assertSame(1, DB::table('manuais')->count());
        // B-03: o que se guarda são as exclusões; um processo de fábrica novo entraria por omissão
        $this->putJson('/api/manuais/procedimentos', ['titulo' => '', 'incluir_cobertura' => true, 'processos_excluidos' => ['rh', 'pos']], $this->editor)
            ->assertOk()->assertJsonPath('dados.titulo', 'Manual de Procedimentos');
        $this->assertSame(1, DB::table('manuais')->count());

        $d = $this->postJson('/api/manuais/procedimentos/recolha', [], $this->leitor)->assertOk()->json('dados');
        $this->assertSame(17, $d['totais']['processos']);
        $this->assertNotContains('rh', array_column($d['processos'], 'chave'));
        $this->assertNotNull($d['cobertura']);
        $this->assertSame(13, $d['cobertura']['total']['modulos']);
        // o formulário por gravar sobrepõe-se ao gravado, sem gravar
        $d2 = $this->postJson('/api/manuais/procedimentos/recolha', ['processos_excluidos' => [], 'incluir_cobertura' => false], $this->leitor)->assertOk()->json('dados');
        $this->assertSame(19, $d2['totais']['processos']);
        $this->assertNull($d2['cobertura']);
        $this->assertSame(109, $d2['totais']['etapas']);
        $this->assertSame([], $d2['cruzamentos']['isolados']);
        $this->assertSame(0, count(array_filter($d2['avisos'], fn ($a) => str_contains($a, 'sem nenhum cruzamento'))));
        // validações de forma
        $this->putJson('/api/manuais/procedimentos', ['data_documento' => '06/11/2026'], $this->editor)->assertStatus(422);
        $this->putJson('/api/manuais/procedimentos', ['apresentacao' => str_repeat('a', 20001)], $this->editor)->assertStatus(422);
    }

    #[Test]
    public function os_avisos_assinalam_etapas_sem_responsavel_e_processos_sem_controlos(): void
    {
        $c = ['titulo' => 'Vazio', 'objetivo' => '', 'etapas' => [['nome' => 'Só nome']], 'cruza' => []];
        $this->postJson('/api/procedimentos', ['titulo' => 'Vazio'], $this->editor)->assertCreated();
        $this->putJson('/api/procedimentos/vazio', $c, $this->editor)->assertOk();
        $d = $this->postJson('/api/manuais/procedimentos/recolha', [], $this->leitor)->assertOk()->json('dados');
        $avisos = implode(' | ', $d['avisos']);
        $this->assertStringContainsString('1 processo(s) sem objectivo: Vazio', $avisos);
        $this->assertStringContainsString('1 etapa(s) sem responsável', $avisos);
        $this->assertStringContainsString('processo(s) sem nenhum controlo definido: Vazio', $avisos);
        $this->assertStringContainsString('1 etapa(s) sem resultado', $avisos);
        $this->assertStringContainsString('1 processo(s) sem nenhum cruzamento declarado', $avisos);
    }

    #[Test]
    public function a_cobertura_mostra_paineis_sem_narrativa_e_modulos_por_cobrir(): void
    {
        $r = $this->getJson('/api/procedimentos/cobertura', $this->leitor)->assertOk();
        $this->assertSame([], $r->json('dados.painel_sem_narrativa'));
        $this->assertSame(['entregaveis', 'fecho', 'imobiliario', 'manutencao', 'stock'], collect($r->json('dados.narrativa_sem_painel'))->pluck('chave')->sort()->values()->all());
        $this->assertSame(['estrategia'], collect($r->json('dados.modulos_sem_processo'))->pluck('id')->all());
        $this->postJson('/api/procedimentos/caixa/ocultar', [], $this->editor)->assertOk();
        $this->postJson('/api/procedimentos/stock/ocultar', [], $this->editor)->assertOk();
        $r = $this->getJson('/api/procedimentos/cobertura', $this->leitor)->assertOk();
        $this->assertSame(['caixa'], $r->json('dados.painel_sem_narrativa'));
        $this->assertContains('stock', collect($r->json('dados.modulos'))->firstWhere('id', 'stock')['em_falta']);
        $this->assertCount(3, $r->json('dados.modulos_sem_processo'));
    }

    #[Test]
    public function isolamento_por_empresa(): void
    {
        $outra = $this->criarEmpresa(['nif' => '5417000456']);
        $editorB = $this->sessao(['proc_manual_view', 'proc_editar'], $outra);
        $this->putJson('/api/procedimentos/rh', $this->corpoMinimo(), $this->editor)->assertOk();
        $this->postJson('/api/procedimentos', ['titulo' => 'Recrutamento'], $this->editor)->assertCreated();
        $this->putJson('/api/manuais/procedimentos', ['titulo' => 'Manual A'], $this->editor)->assertOk();

        $this->assertSame('FABRICA', $this->processo('rh', $editorB)['origem']);
        $this->assertSame([], $this->processo('recrutamento', $editorB));
        $this->getJson('/api/manuais/procedimentos', $editorB)->assertJsonPath('dados.titulo', 'Manual de Procedimentos')->assertJsonPath('dados.guardado', false);
        // a empresa B pode ter o mesmo nome de processo próprio
        $this->postJson('/api/procedimentos', ['titulo' => 'Recrutamento'], $editorB)->assertCreated();
        // e não consegue mexer no que é da A: o PUT cria a sua própria edição, sem tocar na da A
        $this->putJson('/api/procedimentos/rh', $this->corpoMinimo('Salários da B'), $editorB)->assertOk();
        $this->assertSame('Salários adaptados', $this->processo('rh', $this->editor)['titulo']);
        $this->assertSame('Salários da B', $this->processo('rh', $editorB)['titulo']);
        $this->assertSame(2, DB::table('procedimentos')->where('chave', 'rh')->count());
    }

    #[Test]
    public function a_migracao_e_idempotente_e_nao_perde_dados(): void
    {
        $this->putJson('/api/procedimentos/rh', $this->corpoMinimo(), $this->editor)->assertOk();
        $this->putJson('/api/manuais/procedimentos', ['titulo' => 'Manual A'], $this->editor)->assertOk();
        $migracao = require database_path('migrations/2026_12_14_090000_manual_procedimentos_onda1.php');
        $migracao->up();
        $migracao->up();
        $this->assertSame(1, DB::table('procedimentos')->count());
        $this->assertSame(1, DB::table('manuais')->count());
        $this->assertSame(2, DB::table('procedimentos_etapas')->count());
        foreach (['manuais', 'procedimentos', 'procedimentos_etapas', 'procedimentos_cruzamentos'] as $t) {
            $this->assertTrue(DB::selectOne('SELECT relrowsecurity AS rls FROM pg_class WHERE relname = ?', [$t])->rls, "RLS em {$t}");
        }
    }
}
