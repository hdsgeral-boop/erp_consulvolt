<?php

namespace Tests\Feature;

use App\Models\AusenciaFaltaColaborador;
use App\Models\AvaliacaoDesempenhoRH;
use App\Models\CargoFuncao;
use App\Models\CentroCusto;
use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\DependenteColaborador;
use App\Models\Empresa;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PostoTrabalho;
use App\Models\ResultadoFolhaSalarial;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeNegocio;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Afinação da Fase 5 — RH e Estrutura (ADR-064): nomes na folha e na ficha, utilizadores da empresa, o portal do
 * próprio (ausências, dependentes, avaliação) e o mapa de pessoal com massa salarial.
 */
final class AfinacaoRHTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Teste', 'nif' => '5000999998']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $tec = CargoFuncao::create(['nome' => 'Técnica'])->id;
            $che = CargoFuncao::create(['nome' => 'Chefia'])->id;
            $un = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Sede'])->id;
            $cc = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Administração'])->id;
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'ativo' => true])->id;
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir, 'ativo' => true])->id;
            $pChefe = PostoTrabalho::create(['unidade_organica_id' => $dir, 'cargo_funcao_id' => $che, 'titulo' => 'Director', 'vagas' => 1, 'chefia' => true])->id;
            $pTec = PostoTrabalho::create(['unidade_organica_id' => $dep, 'cargo_funcao_id' => $tec, 'titulo' => 'Técnico', 'vagas' => 3])->id;
            $mk = fn (string $n, array $x = []) => Colaborador::create($x + ['nome_completo' => $n, 'nif' => "NIF{$n}", 'numero_inss' => "INSS{$n}", 'estado' => 'ACTIVO',
                'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => '2020-03-02'])->id;
            $chefe = $mk('Chefe', ['unidade_organica_id' => $dir, 'posto_trabalho_id' => $pChefe, 'cargo_funcao_id' => $che]);
            $ana = $mk('Ana', ['unidade_organica_id' => $dep, 'posto_trabalho_id' => $pTec, 'cargo_funcao_id' => $tec, 'unidade_negocio_id' => $un, 'centro_custo_id' => $cc,
                'colaborador_gestor_id' => $chefe]);
            $rui = $mk('Rui', ['unidade_organica_id' => $dep, 'cargo_funcao_id' => $tec]);   // sem posto
            UnidadeOrganica::query()->whereKey($dir)->update(['colaborador_responsavel_id' => $chefe]);
            $this->ids = compact('org', 'tec', 'che', 'un', 'cc', 'dir', 'dep', 'chefe', 'ana', 'rui');
        });
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function folha_fotografada_e_ficha_trazem_os_nomes(): void
    {
        $per = $this->em(function () {
            $p = PeriodoProcessamentoSalarial::create(['mes_ano' => '08/2026', 'estado' => 'FECHADO']);
            foreach ([[$this->ids['ana'], '300000.00'], [$this->ids['chefe'], '500000.00']] as [$c, $bruto]) {
                ResultadoFolhaSalarial::create(['periodo_processamento_salarial_id' => $p->id, 'colaborador_id' => $c, 'bruto' => $bruto, 'inss_trabalhador' => '0', 'inss_patronal' => '0',
                    'irt' => '0', 'descontos' => '0', 'liquido' => $bruto]);
            }

            return $p->id;
        });
        $s = $this->sessao(['processamento_view', 'colaboradores_view']);
        $res = collect($this->getJson("/api/rh/salarios/periodos/{$per}", $s)->assertOk()->assertJsonPath('dados.fotografia', true)->json('dados.resultados'))->keyBy('colaborador_id');
        $this->assertSame(['Ana', 'NIFAna', 'INSSAna'], [$res[$this->ids['ana']]['nome'], $res[$this->ids['ana']]['nif'], $res[$this->ids['ana']]['numero_inss']]);
        $this->assertSame('Chefe', $res[$this->ids['chefe']]['nome']);

        $f = $this->getJson("/api/rh/colaboradores/{$this->ids['ana']}", $s)->assertOk()->json('dados');
        $this->assertSame(['id' => $this->ids['dep'], 'codigo' => 'DEP', 'nome' => 'Departamento'], $f['unidade_organica']);
        $this->assertSame(['id' => $this->ids['un'], 'codigo' => 'UN1', 'nome' => 'Sede'], $f['unidade_negocio']);
        $this->assertSame(['id' => $this->ids['cc'], 'codigo' => 'CC1', 'nome' => 'Administração'], $f['centro_custo']);
        $this->assertNull($this->getJson("/api/rh/colaboradores/{$this->ids['rui']}", $s)->json('dados.centro_custo'));
    }

    #[Test]
    public function utilizadores_da_empresa_para_ligar_ao_portal(): void
    {
        $this->sessao(['rh_portal_usar'], $this->ids['ana']);
        $gestao = $this->sessao(['rh_portal_gestao_view']);
        $lista = collect($this->getJson('/api/rh/portal/utilizadores', $gestao)->assertOk()->json('dados'));
        $this->assertCount(2, $lista);
        $ligado = $lista->firstWhere('colaborador_id', $this->ids['ana']);
        $this->assertSame('Ana', $ligado['colaborador_nome']);
        $this->assertSame(['id', 'nome_utilizador', 'nome_completo', 'ativo', 'colaborador_id', 'colaborador_nome'], array_keys($ligado));

        $this->getJson('/api/rh/portal/utilizadores', $this->sessao(['rh_portal_usar']))->assertForbidden();
        $this->getJson('/api/rh/portal/utilizadores', $this->sessao(['rh_portal_aprovar']))->assertOk();
    }

    #[Test]
    public function portal_ausencias_dependentes_e_a_minha_avaliacao(): void
    {
        $this->em(function () {
            AusenciaFaltaColaborador::create(['colaborador_id' => $this->ids['ana'], 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-01', 'estado' => 'POR_JUSTIFICAR', 'detectada' => true, 'mes' => '2026-09']);
            AusenciaFaltaColaborador::create(['colaborador_id' => $this->ids['ana'], 'tipo' => 'DOENCA', 'data_inicio' => '2026-08-03', 'data_fim' => '2026-08-03', 'estado' => 'APROVADO', 'mes' => '2026-08']);
            AusenciaFaltaColaborador::create(['colaborador_id' => $this->ids['rui'], 'data_inicio' => '2026-09-02', 'data_fim' => '2026-09-02', 'estado' => 'POR_JUSTIFICAR', 'mes' => '2026-09']);
            DependenteColaborador::create(['colaborador_id' => $this->ids['ana'], 'ordem' => 1, 'nome' => 'Filho da Ana', 'parentesco' => 'FILHO']);
            DependenteColaborador::create(['colaborador_id' => $this->ids['rui'], 'ordem' => 1, 'nome' => 'Filha do Rui', 'parentesco' => 'FILHO']);
            $c = CicloAvaliacao360::create(['nome' => 'Anual 2026', 'ano' => 2026, 'periodo' => 'ANUAL', 'estado' => 'ABERTO', 'criterios' => [['chave' => 'qualidade', 'nome' => 'Qualidade', 'peso' => 1]],
                'prazos' => ['dias_contestacao' => 10], 'participantes' => []]);
            AvaliacaoDesempenhoRH::create(['colaborador_id' => $this->ids['ana'], 'ano' => 2025, 'periodo' => 'ANUAL', 'estado' => 'CONCLUIDA', 'pontuacao' => 4.2, 'classificacao' => 'Muito Bom']);
            AvaliacaoDesempenhoRH::create(['colaborador_id' => $this->ids['ana'], 'ano' => 2026, 'periodo' => 'ANUAL', 'ciclo_avaliacao_id' => $c->id, 'estado' => 'RASCUNHO']);   // não se vê
            AvaliacaoDesempenhoRH::create(['colaborador_id' => $this->ids['rui'], 'ano' => 2025, 'periodo' => 'ANUAL', 'estado' => 'CONCLUIDA', 'pontuacao' => 3]);
        });
        $ana = $this->sessao(['rh_portal_usar'], $this->ids['ana']);

        $aus = $this->getJson('/api/rh/portal/ausencias', $ana)->assertOk()->json('dados');
        $this->assertCount(1, $aus);
        $this->assertSame(['POR_JUSTIFICAR', true], [$aus[0]['estado'], $aus[0]['pode_justificar']]);
        $this->assertCount(2, $this->getJson('/api/rh/portal/ausencias?estado=TODOS', $ana)->json('dados'));
        $this->getJson('/api/rh/portal/ausencias?estado=XPTO', $ana)->assertStatus(422);

        $this->assertSame(['Filho da Ana'], array_column($this->getJson('/api/rh/portal/dependentes', $ana)->assertOk()->json('dados'), 'nome'));

        $av = $this->getJson('/api/rh/portal/avaliacoes', $ana)->assertOk()->json('dados');
        $this->assertCount(1, $av['avaliacoes']);
        $this->assertSame(['AGUARDA_CONHECIMENTO', true, false, 4.2], [$av['avaliacoes'][0]['fase'], $av['avaliacoes'][0]['pode_tomar_conhecimento'],
            $av['avaliacoes'][0]['pode_contestar'], $av['avaliacoes'][0]['nota_final']]);
        $this->assertSame([2026, 'ANUAL', false], [$av['ciclo_aberto']['ano'], $av['ciclo_aberto']['periodo'], $av['ciclo_aberto']['comunicado_confirmado']]);
        $this->assertSame($this->ids['chefe'], $av['ascendente']['chefia_colaborador_id']);
        $this->assertCount(8, $av['ascendente']['questoes']);
        $this->assertFalse($av['ascendente']['respondida']);

        // tomar conhecimento pelo portal → passa a prazo de contestação
        $id = $av['avaliacoes'][0]['id'];
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$id}/conhecimento", ['comentario' => 'Li.'], $ana)->assertOk();
        $this->assertSame(['PRAZO_CONTESTACAO', true], array_values(array_intersect_key($this->getJson('/api/rh/portal/avaliacoes', $ana)->json('dados.avaliacoes.0'),
            array_flip(['fase', 'pode_contestar']))));

        // sem colaborador ligado: o portal recusa
        $this->getJson('/api/rh/portal/ausencias', $this->sessao(['rh_portal_usar']))->assertForbidden();
    }

    #[Test]
    public function mapa_de_pessoal_por_unidade_e_cargo_com_massa_so_com_permissao(): void
    {
        $this->em(function () {
            $antigo = PeriodoProcessamentoSalarial::create(['mes_ano' => '12/2025', 'estado' => 'VALIDADO']);
            $ultimo = PeriodoProcessamentoSalarial::create(['mes_ano' => '08/2026', 'estado' => 'FECHADO']);
            PeriodoProcessamentoSalarial::create(['mes_ano' => '09/2026', 'estado' => 'ABERTO']);   // aberto: não conta
            $r = fn ($p, $c, $b) => ResultadoFolhaSalarial::create(['periodo_processamento_salarial_id' => $p, 'colaborador_id' => $c, 'bruto' => $b, 'liquido' => $b]);
            $r($antigo->id, $this->ids['ana'], '999999.99');
            $r($ultimo->id, $this->ids['ana'], '300000.10');
            $r($ultimo->id, $this->ids['rui'], '200000.20');
            $r($ultimo->id, $this->ids['chefe'], '500000.00');
        });
        $sem = $this->getJson('/api/rh/estrutura/mapa', $this->sessao(['est_mapa_view']))->assertOk()->json('dados');
        $this->assertFalse($sem['ver_salarios']);
        $this->assertNull($sem['periodo_salarial']);
        $this->assertArrayNotHasKey('massa_salarial', $sem['totais']);
        $dep = collect($sem['por_unidade'])->firstWhere('codigo', 'DEP');
        $this->assertSame([3, 1, 2, 0, 2], [$dep['previstos'], $dep['ocupados'], $dep['em_aberto'], $dep['acima'], $dep['colaboradores']]);
        $this->assertSame([4, 2, 2, 0, 3], [$sem['totais']['previstos'], $sem['totais']['ocupados'], $sem['totais']['em_aberto'], $sem['totais']['acima'], $sem['totais']['colaboradores']]);
        $tec = collect($sem['por_cargo'])->firstWhere('nome', 'Técnica');
        $this->assertSame([3, 1, 2], [$tec['previstos'], $tec['ocupados'], $tec['colaboradores']]);

        $com = $this->getJson('/api/rh/estrutura/mapa', $this->sessao(['est_mapa_view', 'est_ver_salarios']))->assertOk()->json('dados');
        $this->assertSame(['08/2026', '1000000.30'], [$com['periodo_salarial'], $com['totais']['massa_salarial']]);
        $this->assertSame('500000.30', collect($com['por_unidade'])->firstWhere('codigo', 'DEP')['massa_salarial']);
        $this->assertSame('500000.00', collect($com['por_cargo'])->firstWhere('nome', 'Chefia')['massa_salarial']);

        $this->getJson('/api/rh/estrutura/mapa', $this->sessao(['rh_portal_usar']))->assertForbidden();
    }
}
