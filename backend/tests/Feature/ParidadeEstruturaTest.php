<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\ResultadoFolhaSalarial;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Estrutura orgânica — paridade com o sistema anterior (secção 10 da verificação visual de 2026-10-08):
 * salários dos colaboradores afectos (só com est_ver_salarios, só activos, só da empresa activa) e disposição das
 * subunidades no organigrama (est_editar; unidades de outra empresa recusadas).
 */
final class ParidadeEstruturaTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Teste', 'nif' => '5000999911']);
        $this->outra = $this->criarEmpresa(['nome' => 'Outra Empresa', 'nif' => '5000999922']);
        $this->ids = $this->em($this->empresa, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n, string $estado = 'ACTIVO') => Colaborador::create(['nome_completo' => $n, 'nif' => 'NIF'.$n, 'estado' => $estado, 'tipo_organizacao_id' => $org,
                'dias_uteis_mes' => 22, 'data_admissao' => '2020-03-02'])->id;
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'ativo' => true]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'ativo' => true]);
            $ids = ['ana' => $mk('Ana'), 'rui' => $mk('Rui'), 'inactivo' => $mk('Velho', 'INACTIVO'), 'dir' => $dir->id, 'dep' => $dep->id];
            $antigo = PeriodoProcessamentoSalarial::create(['mes_ano' => '12/2025', 'estado' => 'VALIDADO']);
            $ultimo = PeriodoProcessamentoSalarial::create(['mes_ano' => '08/2026', 'estado' => 'FECHADO']);
            PeriodoProcessamentoSalarial::create(['mes_ano' => '09/2026', 'estado' => 'ABERTO']);
            $r = fn ($p, $c, $b) => ResultadoFolhaSalarial::create(['periodo_processamento_salarial_id' => $p, 'colaborador_id' => $c, 'bruto' => $b, 'liquido' => $b]);
            $r($antigo->id, $ids['ana'], '999999.99');
            $r($ultimo->id, $ids['ana'], '200000.15');
            $r($ultimo->id, $ids['rui'], '200000.00');
            $r($ultimo->id, $ids['inactivo'], '700000.00');   // não activo: não aparece

            return $ids;
        });
        $this->ids['alheia'] = $this->em($this->outra, fn () => UnidadeOrganica::create(['nome' => 'Alheia', 'codigo' => 'ALH', 'ativo' => true])->id);
    }

    private function em(Empresa $e, \Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($e->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function salarios_dos_activos_do_ultimo_processamento_fechado_so_com_permissao(): void
    {
        $this->getJson('/api/estrutura/salarios', $this->sessao(['est_estrutura_view']))->assertForbidden();
        $this->getJson('/api/estrutura/salarios', $this->sessao(['est_mapa_view', 'colaboradores_view']))->assertForbidden();

        $d = $this->getJson('/api/estrutura/salarios', $this->sessao(['est_estrutura_view', 'est_ver_salarios']))->assertOk()->json('dados');
        $this->assertSame('08/2026', $d['periodo_salarial']);
        $this->assertSame([
            ['colaborador_id' => $this->ids['ana'], 'valor' => '200000.15'],
            ['colaborador_id' => $this->ids['rui'], 'valor' => '200000.00'],
        ], $d['por_colaborador']);
    }

    #[Test]
    public function salarios_vazios_sem_processamento_fechado_e_isolados_por_empresa(): void
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'est_estrutura_view' => true, 'est_ver_salarios' => true])->id]);
        $u->empresas()->attach($this->outra->id);
        $d = $this->getJson('/api/estrutura/salarios', $this->entrar($u) + ['X-Empresa-Id' => $this->outra->id])->assertOk()->json('dados');
        $this->assertSame(['periodo_salarial' => null, 'por_colaborador' => []], $d);
    }

    #[Test]
    public function disposicao_das_subunidades_com_validacao_e_isolamento(): void
    {
        $sem = $this->sessao(['est_estrutura_view']);
        $this->postJson('/api/estrutura/disposicao', ['ids' => [$this->ids['dir']], 'disposicao' => 'COLUNA'], $sem)->assertForbidden();

        $ed = $this->sessao(['est_estrutura_view', 'est_editar']);
        $this->postJson('/api/estrutura/disposicao', ['ids' => [$this->ids['dir'], $this->ids['dep']], 'disposicao' => 'COLUNA'], $ed)
            ->assertOk()->assertJsonPath('dados.unidades', 2)->assertJsonPath('dados.disposicao', 'COLUNA');
        $this->assertSame(['COLUNA', 'COLUNA'], $this->em($this->empresa, fn () => UnidadeOrganica::query()->whereKey([$this->ids['dir'], $this->ids['dep']])->orderBy('id')->pluck('disposicao')->all()));

        // a árvore devolve a disposição (o organigrama lê-a de GET /rh/estrutura)
        $arv = $this->getJson('/api/rh/estrutura', $ed)->assertOk()->json('dados.unidades');
        $this->assertSame('COLUNA', collect($arv)->firstWhere('id', $this->ids['dir'])['disposicao']);

        $this->postJson('/api/estrutura/disposicao', ['ids' => [$this->ids['dir']], 'disposicao' => null], $ed)->assertOk()->assertJsonPath('dados.disposicao', null);
        $this->assertNull($this->em($this->empresa, fn () => UnidadeOrganica::query()->find($this->ids['dir'])->disposicao));

        // unidade de outra empresa, valor desconhecido, lista vazia → 422 e nada muda
        $this->postJson('/api/estrutura/disposicao', ['ids' => [$this->ids['alheia']], 'disposicao' => 'COLUNA'], $ed)->assertStatus(422);
        $this->assertNull($this->em($this->outra, fn () => UnidadeOrganica::query()->find($this->ids['alheia'])->disposicao));
        $this->postJson('/api/estrutura/disposicao', ['ids' => [$this->ids['dep']], 'disposicao' => 'LINHA; DROP TABLE x'], $ed)->assertStatus(422);
        $this->postJson('/api/estrutura/disposicao', ['ids' => [], 'disposicao' => 'COLUNA'], $ed)->assertStatus(422);
        $this->assertSame('COLUNA', $this->em($this->empresa, fn () => UnidadeOrganica::query()->find($this->ids['dep'])->disposicao));
    }
}
