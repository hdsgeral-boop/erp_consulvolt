<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\CriterioReparticaoUN;
use App\Models\Empresa;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use App\Services\Reparticao\ServicoReparticao;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase C — critérios de repartição por unidade de negócio (R1) e alocação no objecto (R2): CRUD com permissões antes da
 * validação, isolamento por empresa, as 6 formas de obter o peso, divisão exacta ao cêntimo (a última parcela absorve o
 * arredondamento) e a alocação gravada no colaborador. Dados fictícios.
 */
final class FcReparticaoCriteriosTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    /** @var array<string, int> */
    private array $un = [];

    private int $unOutra;

    private array $gestor;

    private array $consulta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['UN1' => 'Comercial', 'UN2' => 'Industrial', 'UN3' => 'Serviços'] as $c => $n) {
                $this->un[$c] = UnidadeNegocio::create(['codigo' => $c, 'nome' => $n, 'estado' => 'ATIVO'])->id;
            }
        });
        $this->unOutra = app(ContextoEmpresa::class)->executarComo($this->outra->id, fn () => UnidadeNegocio::create(['codigo' => 'X', 'nome' => 'Alheia', 'estado' => 'ATIVO'])->id);
        $this->gestor = $this->sessao(['tabelas_aux_view', 'aux_gerir', 'aux_eliminar', 'colaboradores_view', 'colaboradores_detail']);
        $this->consulta = $this->sessao(['tabelas_aux_view']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function criterio(string $codigo, string $forma, array $extra = []): int
    {
        return $this->postJson('/api/reparticao/criterios', ['codigo' => $codigo, 'nome' => "Critério {$codigo}", 'criterio' => $forma] + $extra, $this->gestor)
            ->assertCreated()->json('dados.id');
    }

    /** @param  array<string, string|int|null>  $pesos  código da UN => peso */
    private function alocacao(int $criterio, array $pesos): array
    {
        return ['criterio_id' => $criterio, 'unidades' => array_map(fn ($c, $p) => ['unidade_negocio_id' => $this->un[$c], 'peso' => $p], array_keys($pesos), $pesos)];
    }

    #[Test]
    public function crud_dos_criterios_com_permissoes_antes_da_validacao_e_codigo_unico(): void
    {
        // sem aux_gerir: 403 mesmo com corpo inválido (a permissão é verificada antes da validação)
        $this->postJson('/api/reparticao/criterios', [], $this->consulta)->assertForbidden();
        $this->postJson('/api/reparticao/criterios', ['codigo' => 'AREA', 'nome' => 'Área', 'criterio' => 'AREA'], $this->consulta)->assertForbidden();

        $id = $this->criterio('area.1', 'AREA', ['notas' => 'Renda da sede']);
        $this->getJson('/api/reparticao/criterios', $this->consulta)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.codigo', 'AREA.1');
        $this->postJson('/api/reparticao/criterios', ['codigo' => 'Area.1', 'nome' => 'Outro', 'criterio' => 'PERCENTAGEM'], $this->gestor)
            ->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');
        $this->postJson('/api/reparticao/criterios', ['codigo' => 'COM ESPAÇO', 'nome' => 'X', 'criterio' => 'PERCENTAGEM'], $this->gestor)->assertStatus(422);
        $this->postJson('/api/reparticao/criterios', ['codigo' => 'X1', 'nome' => 'X', 'criterio' => 'INVENTADO'], $this->gestor)->assertStatus(422);
        $this->postJson('/api/reparticao/criterios', ['codigo' => 'VN', 'nome' => 'Volume', 'criterio' => 'VOLUME_NEGOCIOS', 'periodo_de' => '2026-12-31', 'periodo_ate' => '2026-01-01'], $this->gestor)
            ->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');

        // indicador só no personalizado; período só no volume de negócios
        $p = $this->criterio('HM', 'PERSONALIZADO', ['indicador' => 'horas-máquina', 'periodo_de' => '2026-01-01']);
        $this->getJson("/api/reparticao/criterios/{$p}", $this->consulta)->assertJsonPath('dados.indicador', 'horas-máquina')->assertJsonPath('dados.periodo_de', null);

        $this->putJson("/api/reparticao/criterios/{$id}", ['nome' => 'Área ocupada', 'ativo' => false], $this->consulta)->assertForbidden();
        $this->putJson("/api/reparticao/criterios/{$id}", ['nome' => 'Área ocupada', 'ativo' => false], $this->gestor)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->getJson('/api/reparticao/criterios?activos=1', $this->consulta)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.codigo', 'HM');
        $this->getJson('/api/reparticao/formas', $this->consulta)->assertOk()->assertJsonCount(6, 'dados');

        $this->deleteJson("/api/reparticao/criterios/{$id}", [], $this->sessao(['aux_gerir']))->assertForbidden();
        $this->deleteJson("/api/reparticao/criterios/{$id}", [], $this->gestor)->assertOk();
        $this->assertSame(1, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => CriterioReparticaoUN::query()->count()));
    }

    #[Test]
    public function isolamento_por_empresa_dos_criterios_e_das_unidades(): void
    {
        $id = $this->criterio('PCT', 'PERCENTAGEM');
        $alheio = $this->sessao(['tabelas_aux_view', 'aux_gerir', 'aux_eliminar'], $this->outra);
        $this->getJson('/api/reparticao/criterios', $alheio)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson("/api/reparticao/criterios/{$id}", $alheio)->assertNotFound();
        $this->putJson("/api/reparticao/criterios/{$id}", ['nome' => 'Roubado'], $alheio)->assertNotFound();
        $this->deleteJson("/api/reparticao/criterios/{$id}", [], $alheio)->assertNotFound();

        // critério de outra empresa e unidade de outra empresa: recusados na validação
        $this->postJson('/api/reparticao/simular', ['unidades_alocadas' => $this->alocacao($id, ['UN1' => 50, 'UN2' => 50])], $alheio)->assertStatus(422);
        $this->postJson('/api/reparticao/simular', ['unidades_alocadas' => ['criterio_id' => $id, 'unidades' => [
            ['unidade_negocio_id' => $this->un['UN1'], 'peso' => 50], ['unidade_negocio_id' => $this->unOutra, 'peso' => 50]]]], $this->gestor)
            ->assertStatus(422)->assertJsonValidationErrors(['unidades_alocadas.unidades.1.unidade_negocio_id'], 'erros');
    }

    #[Test]
    public function validacao_da_alocacao_como_o_legado(): void
    {
        $pct = $this->criterio('PCT', 'PERCENTAGEM');
        $simular = fn (array $a) => $this->postJson('/api/reparticao/simular', ['valor' => 100, 'unidades_alocadas' => $a], $this->gestor);

        $simular($this->alocacao($pct, ['UN1' => 100]))->assertStatus(422)->assertJsonPath('codigo', 'ALOCACAO_INVALIDA');
        $simular(['criterio_id' => $pct, 'unidades' => [['unidade_negocio_id' => $this->un['UN1'], 'peso' => 1], ['unidade_negocio_id' => $this->un['UN1'], 'peso' => 1]]])
            ->assertStatus(422)->assertJsonPath('codigo', 'ALOCACAO_INVALIDA');
        $simular($this->alocacao($pct, ['UN1' => 0, 'UN2' => 0]))->assertStatus(422)->assertJsonPath('codigo', 'ALOCACAO_INVALIDA');
        $simular($this->alocacao($pct, ['UN1' => -1, 'UN2' => 5]))->assertStatus(422);
        $simular(['criterio_id' => null, 'unidades' => []])->assertStatus(422)->assertJsonPath('codigo', 'ALOCACAO_VAZIA');

        $this->putJson("/api/reparticao/criterios/{$pct}", ['ativo' => false], $this->gestor)->assertOk();
        $simular($this->alocacao($pct, ['UN1' => 1, 'UN2' => 1]))->assertStatus(422)->assertJsonPath('codigo', 'CRITERIO_INACTIVO');
    }

    #[Test]
    public function as_seis_formas_normalizam_a_100_e_a_ultima_parcela_absorve_o_arredondamento(): void
    {
        // percentagem / área / personalizado: pesos escritos, normalizados qualquer que seja a escala
        foreach (['PERCENTAGEM', 'AREA', 'PERSONALIZADO'] as $n => $forma) {
            $c = $this->criterio("M{$n}", $forma);
            $this->postJson('/api/reparticao/simular', ['valor' => 1000, 'unidades_alocadas' => $this->alocacao($c, ['UN1' => 600, 'UN2' => 400])], $this->gestor)->assertOk()
                ->assertJsonPath('dados.linhas.0.percentagem', '60.0000')->assertJsonPath('dados.linhas.0.valor', '600.00')
                ->assertJsonPath('dados.linhas.1.valor', '400.00')->assertJsonPath('dados.total', '1000.00');
        }

        // partes iguais: 100,00 por 3 → 33,33 + 33,33 + 33,34 (soma exacta); os pesos escritos não se guardam
        $iguais = $this->criterio('IGUAIS', 'PARTES_IGUAIS');
        $r = $this->postJson('/api/reparticao/simular', ['valor' => 100, 'unidades_alocadas' => $this->alocacao($iguais, ['UN1' => 9, 'UN2' => null, 'UN3' => null])], $this->gestor)
            ->assertOk()->json('dados');
        $this->assertSame(['33.33', '33.33', '33.34'], array_column($r['linhas'], 'valor'));
        $this->assertSame('100.00', $r['total']);
        $this->assertNull($r['alocacao']['unidades'][0]['peso']);
        $this->assertSame('33.3333', $r['linhas'][0]['percentagem']);

        // colaboradores: activos (e suspensos) por unidade, lidos das fichas; os inactivos não contam
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['A', 'ACTIVO', 'UN1'], ['B', 'SUSPENSO', 'UN1'], ['C', 'INACTIVO', 'UN1'], ['D', 'ACTIVO', 'UN2']] as [$nome, $estado, $un]) {
                Colaborador::create(['nome_completo' => "Colaborador {$nome}", 'estado' => $estado, 'unidade_negocio_id' => $this->un[$un], 'dias_uteis_mes' => 22]);
            }
        });
        $col = $this->criterio('COL', 'COLABORADORES');
        $this->postJson('/api/reparticao/simular', ['valor' => 0.10, 'unidades_alocadas' => $this->alocacao($col, ['UN1' => null, 'UN2' => null])], $this->gestor)->assertOk()
            ->assertJsonPath('dados.linhas.0.indutor', '2.0000')->assertJsonPath('dados.linhas.0.percentagem', '66.6667')
            ->assertJsonPath('dados.linhas.0.valor', '0.07')->assertJsonPath('dados.linhas.1.valor', '0.03')->assertJsonPath('dados.total', '0.10');

        // volume de negócios: FT/FR − NC não anuladas da unidade no período do critério
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $cli = Terceiro::create(['nome' => 'Cliente Fictício', 'nif' => '5000000031', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            foreach ([['FT', 'UN1', 3000, '2026-03-10', 'PENDENTE'], ['NC', 'UN1', 500, '2026-03-11', 'CONCLUIDO'], ['FT', 'UN2', 1500, '2026-03-12', 'PENDENTE'],
                ['FT', 'UN2', 9999, '2026-03-13', 'ANULADO'], ['FT', 'UN1', 7777, '2025-12-31', 'PENDENTE'], ['OR', 'UN2', 8888, '2026-03-14', 'PENDENTE']] as $n => [$tipo, $un, $v, $data, $estado]) {
                Venda::create(['cliente_id' => $cli->id, 'tipo_documento' => $tipo, 'numero_documento' => "{$tipo} T/{$n}", 'data_emissao' => "{$data} 10:00:00",
                    'total_liquido' => $v, 'total_imposto' => 0, 'total_bruto' => $v, 'estado' => $estado, 'unidade_negocio_id' => $this->un[$un]]);
            }
        });
        $vn = $this->criterio('VN', 'VOLUME_NEGOCIOS', ['periodo_de' => '2026-01-01', 'periodo_ate' => '2026-12-31']);
        $this->postJson('/api/reparticao/simular', ['valor' => 4000, 'unidades_alocadas' => $this->alocacao($vn, ['UN1' => null, 'UN2' => null])], $this->gestor)->assertOk()
            ->assertJsonPath('dados.linhas.0.indutor', '2500.0000')->assertJsonPath('dados.linhas.1.indutor', '1500.0000')
            ->assertJsonPath('dados.linhas.0.valor', '2500.00')->assertJsonPath('dados.linhas.1.valor', '1500.00');

        // sem valor: só as percentagens (pré-visualização do componente)
        $this->postJson('/api/reparticao/simular', ['unidades_alocadas' => $this->alocacao($vn, ['UN1' => null, 'UN2' => null])], $this->gestor)->assertOk()
            ->assertJsonPath('dados.total', null)->assertJsonPath('dados.linhas.1.percentagem', '37.5000');
    }

    #[Test]
    public function divisao_exacta_ao_centimo_incluindo_valores_negativos_e_pequenos(): void
    {
        foreach ([['1000.00', ['1', '1', '1']], ['-0.05', ['1', '1', '1']], ['0.01', ['1', '1', '1']], ['123456.79', ['33.3333', '33.3333', '33.3334']],
            ['999.99', ['7', '13', '0.5', '79.5']]] as [$valor, $indutores]) {
            $partes = ServicoReparticao::dividir($valor, $indutores);
            $this->assertCount(count($indutores), $partes);
            $this->assertSame($valor, array_reduce($partes, fn ($s, $p) => bcadd($s, $p, 2), '0.00'), "soma de {$valor}");
        }
        $this->assertSame(['333.33', '333.33', '333.34'], ServicoReparticao::dividir('1000', ['1', '1', '1']));
        $this->assertSame(['-0.02', '-0.02', '-0.01'], ServicoReparticao::dividir('-0.05', ['1', '1', '1']));
    }

    #[Test]
    public function alocacao_gravada_no_colaborador_e_criterio_em_uso_nao_se_elimina(): void
    {
        $pct = $this->criterio('PCT', 'PERCENTAGEM');
        $colId = app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => Colaborador::create(['nome_completo' => 'Colaboradora Fictícia', 'estado' => 'ACTIVO', 'unidade_negocio_id' => $this->un['UN1'], 'dias_uteis_mes' => 22])->id);

        $this->putJson("/api/reparticao/alocacoes/colaborador/{$colId}", ['unidades_alocadas' => $this->alocacao($pct, ['UN1' => 25, 'UN2' => 75])], $this->consulta)->assertForbidden();
        $this->putJson("/api/reparticao/alocacoes/colaborador/{$colId}", ['unidades_alocadas' => $this->alocacao($pct, ['UN1' => 25, 'UN2' => 75])], $this->gestor)->assertOk();
        $this->getJson("/api/reparticao/alocacoes/colaborador/{$colId}", $this->gestor)->assertOk()
            ->assertJsonPath('dados.unidades_alocadas.criterio_id', $pct)->assertJsonPath('dados.unidades_alocadas.unidades.1.peso', '75.0000')
            ->assertJsonPath('dados.unidade_negocio_id', $this->un['UN1']);
        // isolamento: outra empresa não vê a ficha
        $this->getJson("/api/reparticao/alocacoes/colaborador/{$colId}", $this->sessao(['colaboradores_view'], $this->outra))->assertNotFound();

        $this->deleteJson("/api/reparticao/criterios/{$pct}", [], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'CRITERIO_EM_USO')
            ->assertJsonPath('erros.usos.colaboradores', 1);

        // retirar a repartição (sem critério): volta à unidade da ficha
        $this->putJson("/api/reparticao/alocacoes/colaborador/{$colId}", ['unidades_alocadas' => null], $this->gestor)->assertOk()->assertJsonPath('dados.unidades_alocadas', null);
        $this->getJson("/api/reparticao/alocacoes/colaborador/{$colId}", $this->gestor)->assertJsonPath('dados.unidades_alocadas', null);
        $this->deleteJson("/api/reparticao/criterios/{$pct}", [], $this->gestor)->assertOk();
    }

    #[Test]
    public function leitura_aceita_a_chave_do_legado_business_unit_id(): void
    {
        $a = ServicoReparticao::ler('{"criterio_id": 4, "unidades": [{"business_unit_id": 7, "peso": 60}, {"business_unit_id": "8", "peso": ""}, {"peso": 3}]}');
        $this->assertSame(['criterio_id' => 4, 'unidades' => [['unidade_negocio_id' => 7, 'peso' => '60.0000'], ['unidade_negocio_id' => 8, 'peso' => null]]], $a);
        $this->assertTrue(ServicoReparticao::vazia(null));
        $this->assertTrue(ServicoReparticao::vazia('{"criterio_id": null, "unidades": [{"business_unit_id": 1}]}'));
        $this->assertTrue(ServicoReparticao::vazia('texto inválido'));
    }
}
