<?php

namespace Tests\Feature;

use App\Models\AtivoImobilizado;
use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase F (Versão Actual) — Edifícios e Locais e folhas de inventário físico (js/activos_edificios.js): tipo do activo e
 * localização na ficha, andares, portas em série, arrastar bens, planta, inventário (gerar, contar, extras, comparar,
 * aplicar, fechar, anular), permissões e isolamento por empresa. Dados fictícios.
 */
final class FfActivosLocaisTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417100011']);
        $this->h = $this->sessao(['activos_view', 'activos_gerir', 'activos_cat_gerir', 'activos_edificios_view', 'activos_locais_gerir', 'activos_inventario_fisico']);
        $this->cat = $this->postJson('/api/ativos/categorias', ['nome' => 'Mobiliário', 'taxa_anual' => 10, 'vida_util_padrao' => 120], $this->h)->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function bem(array $d): int
    {
        return $this->postJson('/api/ativos/bens', $d + ['categoria_ativo_id' => $this->cat, 'valor_aquisicao' => 1000], $this->h)->assertCreated()->json('dados.id');
    }

    /** Edifício com o rés-do-chão e as portas A1..A3; devolve [edifício, andar, portas]. */
    private function edificio(): array
    {
        $e = $this->bem(['descricao' => 'Sede fictícia', 'tipo_ativo' => 'EDIFICIO', 'valor_aquisicao' => 500000]);
        $andar = $this->postJson("/api/ativos/edificios/{$e}/andares", ['numero' => '0'], $this->h)->assertCreated()->assertJsonPath('dados.nome', 'Rés-do-chão')->json('dados.id');
        $this->postJson("/api/ativos/edificios/{$e}/portas", ['andar_id' => $andar, 'prefixo' => 'A', 'de' => 1, 'ate' => 3, 'tipo' => 'Gabinete'], $this->h)
            ->assertCreated()->assertJsonPath('dados.criadas', 3);
        $portas = collect($this->getJson("/api/ativos/edificios/{$e}/planta", $this->h)->assertOk()->json('dados.andares.0.portas'))->pluck('id', 'numero')->all();

        return [$e, $andar, $portas];
    }

    #[Test]
    public function ficha_tipo_e_localizacao_com_regras_do_legado(): void
    {
        [$e, $andar, $p] = $this->edificio();
        $this->getJson("/api/ativos/bens/{$e}", $this->h)->assertJsonPath('dados.tipo_ativo', 'EDIFICIO')->assertJsonPath('dados.porta_id', null);

        // a porta manda: edifício e andar derivam dela
        $b = $this->bem(['descricao' => 'Secretária', 'porta_id' => $p['A2']]);
        $this->getJson("/api/ativos/bens/{$b}", $this->h)->assertJsonPath('dados.tipo_ativo', 'BEM')->assertJsonPath('dados.edificio_id', $e)
            ->assertJsonPath('dados.andar_id', $andar)->assertJsonPath('dados.porta.numero', 'A2');

        // edifício e andar sem porta é recusado (ui_assets.js:475); porta de outro andar também
        $this->postJson('/api/ativos/bens', ['descricao' => 'X', 'categoria_ativo_id' => $this->cat, 'edificio_id' => $e, 'andar_id' => $andar], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'PORTA_EM_FALTA');
        $outroAndar = $this->postJson("/api/ativos/edificios/{$e}/andares", ['numero' => '1'], $this->h)->assertCreated()->assertJsonPath('dados.nome', '1.º andar')->json('dados.id');
        $this->putJson("/api/ativos/bens/{$b}", ['andar_id' => $outroAndar, 'porta_id' => $p['A1']], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'LOCAL_INCOERENTE');

        // o tipo não muda depois de criado; um edifício não fica numa porta
        $this->putJson("/api/ativos/bens/{$b}", ['tipo_ativo' => 'EDIFICIO'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'TIPO_ATIVO_FIXO');
        $this->putJson("/api/ativos/bens/{$e}", ['porta_id' => $p['A1']], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'EDIFICIO_NAO_LOCALIZAVEL');

        // retirar a localização na ficha
        $this->putJson("/api/ativos/bens/{$b}", ['porta_id' => null], $this->h)->assertOk()->assertJsonPath('dados.edificio_id', null);
        $this->getJson('/api/ativos/bens?tipo_ativo=EDIFICIO', $this->h)->assertJsonCount(1, 'dados');
        $this->getJson('/api/ativos/locais/opcoes', $this->sessao(['activos_view']))->assertOk()->assertJsonCount(1, 'dados.edificios')->assertJsonCount(3, 'dados.portas');
    }

    #[Test]
    public function andares_portas_arrasto_planta_e_eliminacao_que_liberta_os_bens(): void
    {
        [$e, $andar, $p] = $this->edificio();
        $a = $this->bem(['descricao' => 'Cadeira', 'valor_aquisicao' => 150.50]);
        $b = $this->bem(['descricao' => 'Armário', 'valor_aquisicao' => 300]);

        // portas em série: as repetidas são ignoradas; máximo 200; intervalo válido
        $this->postJson("/api/ativos/edificios/{$e}/portas", ['andar_id' => $andar, 'prefixo' => 'A', 'de' => 3, 'ate' => 4], $this->h)
            ->assertCreated()->assertJsonPath('dados.criadas', 1)->assertJsonPath('dados.ignoradas', ['A3']);
        $this->postJson("/api/ativos/edificios/{$e}/portas", ['andar_id' => $andar, 'prefixo' => 'A', 'de' => 1, 'ate' => 2], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'PORTAS_EXISTENTES');
        $this->postJson("/api/ativos/edificios/{$e}/portas", ['andar_id' => $andar, 'de' => 1, 'ate' => 201], $this->h)->assertStatus(422);
        $this->postJson("/api/ativos/edificios/{$a}/andares", ['numero' => '0'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'NAO_E_EDIFICIO');
        $this->postJson("/api/ativos/edificios/{$e}/andares", ['numero' => '0'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'ANDAR_DUPLICADO');

        // arrastar: para uma porta, entre portas, e um edifício não entra numa porta
        $this->postJson("/api/ativos/bens/{$a}/localizacao", ['porta_id' => $p['A1']], $this->h)->assertOk()->assertJsonPath('dados.edificio_id', $e);
        $this->postJson("/api/ativos/bens/{$b}/localizacao", ['porta_id' => $p['A1']], $this->h)->assertOk();
        $this->postJson("/api/ativos/bens/{$b}/localizacao", ['porta_id' => $p['A2']], $this->h)->assertOk()->assertJsonPath('dados.porta_id', $p['A2']);
        $this->postJson("/api/ativos/bens/{$e}/localizacao", ['porta_id' => $p['A1']], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'EDIFICIO_NAO_LOCALIZAVEL');

        $planta = $this->getJson("/api/ativos/edificios/{$e}/planta", $this->h)->assertOk();
        $planta->assertJsonPath('dados.totais.bens', 2)->assertJsonPath('dados.totais.valor', '450.50')->assertJsonPath('dados.andares.0.n_bens', 2);
        $this->getJson('/api/ativos/edificios', $this->h)->assertOk()->assertJsonPath('dados.edificios.0.portas', 4)->assertJsonPath('dados.edificios.0.bens', 2)
            ->assertJsonPath('dados.edificios.0.valor_bens', '450.50')->assertJsonPath('dados.sem_local', 0);

        // editar porta (número único no andar)
        $this->putJson("/api/ativos/portas/{$p['A1']}", ['numero' => 'A2'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'PORTA_DUPLICADA');
        $this->putJson("/api/ativos/portas/{$p['A1']}", ['nome' => 'Recepção', 'capacidade' => 4], $this->h)->assertOk()->assertJsonPath('dados.nome', 'Recepção');

        // eliminar porta / andar: os bens ficam sem localização, nunca são apagados
        $this->deleteJson("/api/ativos/portas/{$p['A2']}", [], $this->h)->assertOk()->assertJsonPath('dados.bens_libertados', 1);
        $this->getJson("/api/ativos/bens/{$b}", $this->h)->assertOk()->assertJsonPath('dados.porta_id', null)->assertJsonPath('dados.edificio_id', null);
        $this->deleteJson("/api/ativos/andares/{$andar}", [], $this->h)->assertOk()->assertJsonPath('dados.bens_libertados', 1)->assertJsonPath('dados.portas', 3);
        $this->getJson("/api/ativos/bens/{$a}", $this->h)->assertOk()->assertJsonPath('dados.porta_id', null);
        $this->getJson('/api/ativos/edificios', $this->h)->assertJsonPath('dados.sem_local', 2)->assertJsonPath('dados.edificios.0.andares', 0);
        $this->assertTrue(app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LogAuditoria::query()->where('acao', 'Eliminar andar')->exists()));
    }

    #[Test]
    public function inventario_fisico_gerar_contar_comparar_aplicar_fechar(): void
    {
        [$e, $andar, $p] = $this->edificio();
        $a = $this->bem(['descricao' => 'Mesa', 'porta_id' => $p['A1'], 'valor_aquisicao' => 100]);
        $b = $this->bem(['descricao' => 'Monitor', 'porta_id' => $p['A1'], 'valor_aquisicao' => 200]);
        $c = $this->bem(['descricao' => 'Impressora', 'porta_id' => $p['A2'], 'valor_aquisicao' => 300]);
        $x = $this->bem(['descricao' => 'Projector sem local', 'valor_aquisicao' => 50]);

        // âmbito porta sem bens: pede confirmação
        $this->postJson("/api/ativos/edificios/{$e}/inventarios-fisicos", ['ambito' => 'PORTA', 'porta_id' => $p['A3']], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'SEM_BENS_ESPERADOS');
        $inv = $this->postJson("/api/ativos/edificios/{$e}/inventarios-fisicos", ['ambito' => 'EDIFICIO', 'responsavel' => 'Contador fictício', 'data' => '2026-10-14'], $this->h)
            ->assertCreated()->assertJsonPath('dados.esperados', 3)->assertJsonPath('dados.valor_esperado', '600.00')->json('dados.id');

        $folha = $this->getJson("/api/ativos/inventarios-fisicos/{$inv}", $this->h)->assertOk()->assertJsonCount(3, 'dados.linhas')
            ->assertJsonPath('dados.inventario.ambito_texto', 'Todo o edifício')->assertJsonCount(3, 'dados.portas');
        $linhas = collect($folha->json('dados.linhas'))->keyBy('ativo_imobilizado_id');
        $this->assertSame('Rés-do-chão · Porta A1', $linhas[$a]['local_esperado']);

        // contagem: encontrado, em falta, noutro local (porta deste edifício)
        $this->putJson("/api/ativos/inventarios-fisicos/{$inv}/contagem", ['linhas' => [
            ['id' => $linhas[$a]['id'], 'resultado' => 'ENCONTRADO'], ['id' => $linhas[$b]['id'], 'resultado' => 'EM_FALTA', 'notas' => 'Não estava'],
            ['id' => $linhas[$c]['id'], 'resultado' => 'DESLOCADO', 'porta_encontrada_id' => $p['A3']],
        ]], $this->h)->assertOk()->assertJsonPath('dados.contados', 3);
        // extra: bem não previsto encontrado na A1; não entra duas vezes
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/extras", ['ativo_imobilizado_id' => $x, 'porta_encontrada_id' => $p['A1']], $this->h)->assertCreated();
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/extras", ['ativo_imobilizado_id' => $x, 'porta_encontrada_id' => $p['A1']], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'BEM_NA_FOLHA');
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/extras", ['ativo_imobilizado_id' => $a, 'porta_encontrada_id' => $p['A1']], $this->h)->assertStatus(422);

        $cmp = $this->getJson("/api/ativos/inventarios-fisicos/{$inv}/comparacao", $this->h)->assertOk();
        $cmp->assertJsonPath('dados.resumo.encontrados.n', 1)->assertJsonPath('dados.resumo.em_falta.valor', '200.00')->assertJsonPath('dados.resumo.deslocados.n', 1)
            ->assertJsonPath('dados.resumo.extras.n', 1)->assertJsonPath('dados.resumo.a_aplicar', 2)->assertJsonPath('dados.resumo.por_contar', 0);
        $this->assertEquals(33.3, $cmp->json('dados.resumo.conformidade'));
        $this->getJson('/api/ativos/inventarios-fisicos', $this->h)->assertOk()->assertJsonPath('dados.0.numero', "INV-{$inv}")->assertJsonPath('dados.0.diferencas', 3)
            ->assertJsonPath('dados.0.previstos', 3);

        // aplicar: o deslocado e o não previsto passam para a porta onde foram encontrados
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/aplicar", [], $this->h)->assertOk()->assertJsonPath('dados.relocalizados', 2);
        $this->getJson("/api/ativos/bens/{$c}", $this->h)->assertJsonPath('dados.porta_id', $p['A3']);
        $this->getJson("/api/ativos/bens/{$x}", $this->h)->assertJsonPath('dados.porta_id', $p['A1'])->assertJsonPath('dados.edificio_id', $e);

        // com folha aberta não se elimina a porta; fechar → já não se altera nem se anula
        $this->deleteJson("/api/ativos/portas/{$p['A3']}", [], $this->h)->assertOk();   // a folha é do edifício, não da porta
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/fechar", [], $this->h)->assertOk()->assertJsonPath('dados.estado', 'FECHADO');
        $this->putJson("/api/ativos/inventarios-fisicos/{$inv}/contagem", ['linhas' => [['id' => $linhas[$a]['id'], 'resultado' => 'EM_FALTA']]], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'INVENTARIO_NAO_ABERTO');
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/anular", ['motivo' => 'teste'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'INVENTARIO_FECHADO');
    }

    #[Test]
    public function folha_de_porta_anula_se_e_bloqueia_eliminar_a_porta(): void
    {
        [$e, , $p] = $this->edificio();
        $a = $this->bem(['descricao' => 'Cofre', 'porta_id' => $p['A1']]);
        $inv = $this->postJson("/api/ativos/edificios/{$e}/inventarios-fisicos", ['ambito' => 'PORTA', 'porta_id' => $p['A1']], $this->h)
            ->assertCreated()->assertJsonPath('dados.esperados', 1)->json('dados.id');
        $this->deleteJson("/api/ativos/portas/{$p['A1']}", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'INVENTARIO_ABERTO');
        // linhas previstas não se retiram; só os não previstos
        $linha = $this->getJson("/api/ativos/inventarios-fisicos/{$inv}", $this->h)->json('dados.linhas.0.id');
        $this->deleteJson("/api/ativos/inventarios-fisicos/linhas/{$linha}", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'LINHA_PREVISTA');
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/aplicar", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'NADA_A_APLICAR');
        // anular (nunca apagar): fica com motivo e sai da lista por omissão
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/anular", [], $this->h)->assertStatus(422);
        $this->postJson("/api/ativos/inventarios-fisicos/{$inv}/anular", ['motivo' => 'Folha gerada por engano'], $this->h)->assertOk()->assertJsonPath('dados.estado', 'ANULADO');
        $this->getJson('/api/ativos/inventarios-fisicos', $this->h)->assertJsonCount(0, 'dados');
        $this->getJson('/api/ativos/inventarios-fisicos?estado=ANULADO', $this->h)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.motivo_anulacao', 'Folha gerada por engano');
        $this->deleteJson("/api/ativos/portas/{$p['A1']}", [], $this->h)->assertOk();
        $this->getJson("/api/ativos/bens/{$a}", $this->h)->assertJsonPath('dados.porta_id', null);
    }

    #[Test]
    public function permissoes_e_isolamento_por_empresa(): void
    {
        [$e, $andar, $p] = $this->edificio();
        $bem = $this->bem(['descricao' => 'Sofá']);
        $so = $this->sessao(['activos_edificios_view']);
        $this->getJson('/api/ativos/edificios', $so)->assertOk();
        $this->getJson("/api/ativos/edificios/{$e}/planta", $so)->assertOk();
        $this->postJson("/api/ativos/bens/{$bem}/localizacao", ['porta_id' => $p['A1']], $so)->assertForbidden();
        $this->postJson("/api/ativos/edificios/{$e}/inventarios-fisicos", ['ambito' => 'EDIFICIO', 'confirmar_vazia' => true], $so)->assertForbidden();
        $gestor = $this->sessao(['activos_locais_gerir']);
        $this->postJson("/api/ativos/edificios/{$e}/inventarios-fisicos", ['ambito' => 'EDIFICIO', 'confirmar_vazia' => true], $gestor)->assertForbidden();
        $this->getJson('/api/ativos/edificios', $this->sessao(['activos_view']))->assertForbidden();

        // outra empresa não vê nem altera
        $outra = $this->criarEmpresa(['nif' => '5417100012']);
        $h2 = $this->sessao(['activos_edificios_view', 'activos_locais_gerir', 'activos_inventario_fisico'], $outra);
        $this->getJson("/api/ativos/edificios/{$e}/planta", $h2)->assertNotFound();
        $this->putJson("/api/ativos/portas/{$p['A1']}", ['nome' => 'X'], $h2)->assertNotFound();
        $this->deleteJson("/api/ativos/andares/{$andar}", [], $h2)->assertNotFound();
        $this->getJson('/api/ativos/edificios', $h2)->assertOk()->assertJsonCount(0, 'dados.edificios');
        $this->assertSame(1, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => AtivoImobilizado::query()->where('tipo_ativo', 'EDIFICIO')->count()));
    }
}
