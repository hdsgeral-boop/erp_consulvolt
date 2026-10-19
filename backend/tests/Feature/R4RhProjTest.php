<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\LogAtividadeProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeNegocio;
use App\Services\RH\ServicoProdutividade;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 4 (r4_rh_proj): produtividade com um só cálculo (métricas e explicação vêm do servidor, também na pré-visualização do
 * formulário do item) e arrastar membros entre posições do organigrama do projecto (mover com validação e «Desfazer»).
 * Dados fictícios.
 */
final class R4RhProjTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
    }

    private function em(\Closure $f, ?Empresa $e = null): mixed
    {
        return app(ContextoEmpresa::class)->executarComo(($e ?? $this->empresa)->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $e = null): array
    {
        $e ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($e->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $e->id];
    }

    // ───────────── Produtividade: um só cálculo ─────────────

    private function itensProdutividade(): void
    {
        $this->em(function () {
            $prod = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de produtividade', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $this->ids['prod'] = $prod;
            $this->ids['obj'] = ItemProdutividadeRH::create(['codigo' => 'OBJ', 'descricao' => 'Objectivo', 'metrica' => 'OBJECTIVO', 'unidade' => '%', 'preco_unitario' => 50000,
                'base_calculo' => 'PREMIO', 'infotipo_salarial_id' => $prod, 'minimo' => 80, 'maximo' => 120, 'ativo' => true])->id;
            $this->ids['pec'] = ItemProdutividadeRH::create(['codigo' => 'PEC', 'descricao' => 'Peças', 'metrica' => 'QUANTIDADE', 'unidade' => 'peças', 'preco_unitario' => 2000,
                'infotipo_salarial_id' => $prod, 'ativo' => true])->id;
        });
        $this->ids['pec_outra'] = $this->em(fn () => ItemProdutividadeRH::create(['codigo' => 'X', 'descricao' => 'Outra', 'metrica' => 'QUANTIDADE', 'preco_unitario' => 1,
            'infotipo_salarial_id' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'S', 'sujeito_inss' => true, 'irt' => 'true'])->id, 'ativo' => true])->id, $this->outra);
    }

    #[Test]
    public function metricas_do_servidor_com_formula_exemplo_e_rotulos_por_base(): void
    {
        $ver = $this->sessao(['rh_produtividade_view']);
        $d = $this->getJson('/api/rh/produtividade/metricas', $ver)->assertOk()->json('dados');
        $this->assertSame(['QUANTIDADE', 'HORAS', 'OBJECTIVO', 'PONTOS', 'TAREFAS'], array_keys($d['metricas']));
        $this->assertSame(95, $d['metricas']['OBJECTIVO']['quantidade_exemplo']);
        $this->assertSame(10, $d['metricas']['QUANTIDADE']['quantidade_exemplo']);
        $this->assertSame('Subsídio = % atingida × valor por ponto percentual', $d['metricas']['OBJECTIVO']['formula_unitario']);
        $this->assertSame(['UNITARIO', 'PREMIO'], array_keys($d['bases']));
        $this->getJson('/api/rh/produtividade/metricas', $this->sessao(['colaboradores_view']))->assertForbidden();
    }

    #[Test]
    public function explicar_simula_o_item_do_formulario_com_o_mesmo_motor(): void
    {
        $this->itensProdutividade();
        $cfg = $this->sessao(['rh_prod_config']);

        // item novo (sem id): OBJECTIVO em prémio, quantidade de exemplo (95 %) → 50 000 × 0,95
        $e = $this->getJson('/api/rh/produtividade/explicar?metrica=OBJECTIVO&base_calculo=PREMIO&preco=50000&unidade=%25', $cfg)->assertOk()->json('dados');
        $this->assertSame(['47500.00', 95.0, 'PREMIO'], [$e['valor'], (float) $e['quantidade'], $e['base_calculo']]);
        $this->assertSame('Subsídio = prémio a 100% × (% atingida ÷ 100)', $e['formula']);
        $this->assertSame('Prémio a 100% do objectivo', $e['rotulo_preco']);
        $this->assertSame('50 000 Kz × (95 ÷ 100) = 47 500,00 Kz', $e['conta']);

        // OBJECTIVO em UNITARIO: a fórmula mostrada é a da conta feita (% × valor por ponto), não a do prémio
        $u = $this->getJson('/api/rh/produtividade/explicar?metrica=OBJECTIVO&base_calculo=UNITARIO&preco=500', $cfg)->assertOk()->json('dados');
        $this->assertSame(['47500.00', 'Subsídio = % atingida × valor por ponto percentual', 'Valor por ponto percentual'], [$u['valor'], $u['formula'], $u['rotulo_preco']]);

        // a base «prémio» só vale no OBJECTIVO (como ao gravar): noutra métrica a conta é quantidade × preço
        $q = $this->getJson('/api/rh/produtividade/explicar?metrica=QUANTIDADE&base_calculo=PREMIO&preco=2000&unidade=pe%C3%A7as&quantidade=40', $cfg)->assertOk()->json('dados');
        $this->assertSame(['80000.00', 'UNITARIO', '40 peças × 2 000 Kz = 80 000,00 Kz'], [$q['valor'], $q['base_calculo'], $q['conta']]);

        // item gravado com os valores ainda por gravar do formulário: tecto 100 em vez de 120
        $g = $this->getJson("/api/rh/produtividade/explicar?item_id={$this->ids['obj']}&quantidade=130&maximo=100", $cfg)->assertOk()->json('dados');
        $this->assertSame('50000.00', $g['valor']);
        $this->assertSame(['acima do tecto de 100 % — conta 100'], $g['cortes']);
        // sem alterações: o item gravado tal como está (tecto 120)
        $this->getJson("/api/rh/produtividade/explicar?item_id={$this->ids['obj']}&quantidade=130", $cfg)->assertOk()->assertJsonPath('dados.valor', '60000.00');
        // a simulação não grava nada
        $this->assertSame('120.000', $this->em(fn () => ItemProdutividadeRH::query()->findOrFail($this->ids['obj'])->maximo));

        // validação, isolamento e permissão
        $this->getJson('/api/rh/produtividade/explicar?preco=10', $cfg)->assertStatus(422)->assertJsonValidationErrors('metrica', 'erros');
        $this->getJson('/api/rh/produtividade/explicar?metrica=QUANTIDADE', $cfg)->assertStatus(422)->assertJsonValidationErrors('preco', 'erros');
        $this->getJson('/api/rh/produtividade/explicar?metrica=OUTRA&preco=1', $cfg)->assertStatus(422)->assertJsonValidationErrors('metrica', 'erros');
        $this->getJson('/api/rh/produtividade/explicar?metrica=QUANTIDADE&preco=-1', $cfg)->assertStatus(422)->assertJsonValidationErrors('preco', 'erros');
        $this->getJson("/api/rh/produtividade/explicar?item_id={$this->ids['pec_outra']}&quantidade=1", $cfg)->assertStatus(422)->assertJsonValidationErrors('item_id', 'erros');
        $this->getJson('/api/rh/produtividade/explicar?metrica=QUANTIDADE&preco=1', $this->sessao(['colaboradores_view']))->assertForbidden();
    }

    #[Test]
    public function a_explicacao_do_periodo_e_o_valor_gravado_saem_do_mesmo_motor(): void
    {
        $this->itensProdutividade();
        $s = $this->sessao(['rh_produtividade_view', 'rh_prod_config', 'rh_prod_registar', 'rh_prod_periodo']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $this->ids['ana'] = Colaborador::create(['nome_completo' => 'Ana Fictícia', 'nif' => '900000001LA001', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org,
                'dias_uteis_mes' => 22, 'data_admissao' => '2020-01-01'])->id;
            ContratoTrabalho::create(['colaborador_id' => $this->ids['ana'], 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2020-01-01',
                'remuneracoes' => [], 'produtividade' => [['item_id' => $this->ids['pec'], 'preco_unitario' => null]]]);
        });
        $p = $this->postJson('/api/rh/produtividade/periodos', ['mes' => '2025-09', 'data_inicio' => '2025-09-01', 'data_fim' => '2025-09-30'], $s)->assertCreated()->json('dados.id');
        foreach (['2025-09-10' => 25, '2025-09-20' => 15] as $data => $q) {
            $this->postJson("/api/rh/produtividade/periodos/{$p}/registos", ['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['pec'], 'quantidade' => $q,
                'data' => $data], $s)->assertCreated();
        }
        $linha = $this->getJson("/api/rh/produtividade/periodos/{$p}/explicacao", $s)->assertOk()->json('dados.0');
        $caso = $this->getJson("/api/rh/produtividade/explicar?item_id={$this->ids['pec']}&quantidade=40", $s)->assertOk()->json('dados');
        $this->assertSame(['80000.00', '80000.00'], [$linha['valor'], $linha['valor_gravado']]);
        $this->assertSame([$caso['conta'], $caso['formula'], $caso['valor']], [$linha['conta'], $linha['formula'], $linha['valor']]);
        $item = $this->em(fn () => ItemProdutividadeRH::query()->findOrFail($this->ids['pec']));
        $this->assertSame(ServicoProdutividade::valor($item, 40, 2000), $linha['valor']);
    }

    // ───────────── Organigrama: mover membros ─────────────

    /** Projecto com 2 posições (Direcção: 1 vaga; Frente: sem limite) e 3 membros (Ana na Direcção e responsável). */
    private function projectoComOrganigrama(array $s, ?Empresa $e = null): array
    {
        $e ??= $this->empresa;
        $k = count($this->ids['projectos'] ?? []) + 1;
        $this->ids['projectos'][] = $k;
        [$un, $cc] = $this->em(fn () => [UnidadeNegocio::create(['codigo' => "UN{$k}", 'nome' => 'Obras'])->id, CentroCusto::create(['codigo' => "CC{$k}", 'descricao' => 'Estaleiro'])->id], $e);
        $p = $this->postJson('/api/projetos', ['nome' => 'Obra fictícia', 'tipo' => 'INTERNO', 'unidade_negocio_id' => $un, 'centro_custo_id' => $cc], $s)->assertCreated()->json('dados.id');
        $m = [];
        foreach (['Ana', 'Rui', 'Eva'] as $n) {
            $m[$n] = $this->postJson("/api/projetos/{$p}/equipa/membros", ['tipo' => 'LIVRE', 'nome_externo' => "{$n} Fictício"], $s)->assertCreated()->json('dados.id');
        }
        $dir = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Direcção', 'vagas' => 1], $s)->assertCreated()->json('dados.id');
        $frente = $this->postJson("/api/projetos/{$p}/organigrama/posicoes", ['titulo' => 'Frente', 'vagas' => 0, 'no_pai_id' => $dir], $s)->assertCreated()->json('dados.id');
        $this->postJson("/api/projetos/{$p}/organigrama/alocar", ['membros' => [$m['Ana']], 'no_organigrama_projeto_id' => $dir], $s)->assertOk();
        $this->putJson("/api/projetos/{$p}/organigrama/posicoes/{$dir}", ['membro_responsavel_id' => $m['Ana']], $s)->assertOk();

        return ['p' => $p, 'dir' => $dir, 'frente' => $frente] + $m;
    }

    #[Test]
    public function mover_membro_entre_posicoes_com_vagas_concorrencia_e_desfazer(): void
    {
        $s = $this->sessao(['projectos_carteira_view', 'proj_gerir']);
        $o = $this->projectoComOrganigrama($s);
        $url = "/api/projetos/{$o['p']}/organigrama/mover";
        $no = fn (int $m) => $this->em(fn () => MembroEquipaProjeto::query()->findOrFail($m)->no_organigrama_projeto_id);
        $resp = fn () => $this->em(fn () => NoOrganigramaProjeto::query()->findOrFail($o['dir'])->membro_responsavel_id);

        // Ana (responsável da Direcção) arrastada para a Frente: sai da Direcção e deixa de ser responsável
        $r = $this->postJson($url, ['membro_id' => $o['Ana'], 'no_destino_id' => $o['frente'], 'no_origem_id' => $o['dir']], $s)->assertOk()->json('dados');
        $this->assertSame(['membro_id' => $o['Ana'], 'de' => $o['dir'], 'para' => $o['frente'], 'era_responsavel' => true, 'alterado' => true], $r);
        $this->assertSame([$o['frente'], null], [$no($o['Ana']), $resp()]);

        // «Desfazer»: volta à Direcção e repõe a responsabilidade
        $this->postJson($url, ['membro_id' => $o['Ana'], 'no_destino_id' => $o['dir'], 'no_origem_id' => $o['frente'], 'confirmar_excesso' => true, 'repor_responsavel' => true], $s)
            ->assertOk()->assertJsonPath('dados.alterado', true);
        $this->assertSame([$o['dir'], $o['Ana']], [$no($o['Ana']), $resp()]);

        // Rui (sem posição) para a Direcção, que tem 1 vaga ocupada: só com confirmação
        $this->postJson($url, ['membro_id' => $o['Rui'], 'no_destino_id' => $o['dir'], 'no_origem_id' => null], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'VAGAS_EXCEDIDAS');
        $this->assertNull($no($o['Rui']));
        $this->postJson($url, ['membro_id' => $o['Rui'], 'no_destino_id' => $o['dir'], 'no_origem_id' => null, 'confirmar_excesso' => true], $s)->assertOk();
        $this->assertSame($o['dir'], $no($o['Rui']));

        // o ecrã viu o Rui sem posição, mas já está na Direcção: recusa (outra pessoa moveu-o)
        $this->postJson($url, ['membro_id' => $o['Rui'], 'no_destino_id' => $o['frente'], 'no_origem_id' => null], $s)
            ->assertStatus(409)->assertJsonPath('codigo', 'MEMBRO_MOVIDO');

        // largar na «Equipa sem posição» retira-o; largar na mesma posição não altera nada
        $this->postJson($url, ['membro_id' => $o['Rui'], 'no_destino_id' => null, 'no_origem_id' => $o['dir']], $s)->assertOk()->assertJsonPath('dados.para', null);
        $this->assertNull($no($o['Rui']));
        $this->postJson($url, ['membro_id' => $o['Eva'], 'no_destino_id' => null], $s)->assertOk()->assertJsonPath('dados.alterado', false);

        // fica registado na actividade do projecto
        $this->assertSame(4, $this->em(fn () => LogAtividadeProjeto::query()->where('projeto_id', $o['p'])->where('acao', 'Mover membro')->count()));
    }

    #[Test]
    public function mover_valida_permissao_projecto_empresa_e_entrada(): void
    {
        $s = $this->sessao(['projectos_carteira_view', 'proj_gerir']);
        $o = $this->projectoComOrganigrama($s);
        $outro = $this->projectoComOrganigrama($s);
        $url = "/api/projetos/{$o['p']}/organigrama/mover";

        // sem proj_gerir
        $this->postJson($url, ['membro_id' => $o['Rui'], 'no_destino_id' => $o['frente']], $this->sessao(['projectos_carteira_view']))->assertForbidden();
        // entrada inválida
        $this->postJson($url, ['membro_id' => 'x', 'no_destino_id' => $o['frente']], $s)->assertStatus(422)->assertJsonValidationErrors('membro_id', 'erros');
        $this->postJson($url, ['membro_id' => $o['Rui']], $s)->assertStatus(422)->assertJsonValidationErrors('no_destino_id', 'erros');
        // membro e posição de outro projecto
        $this->postJson($url, ['membro_id' => $outro['Rui'], 'no_destino_id' => $o['frente']], $s)->assertStatus(422)->assertJsonPath('codigo', 'MEMBRO_INEXISTENTE');
        $this->postJson($url, ['membro_id' => $o['Rui'], 'no_destino_id' => $outro['frente']], $s)->assertStatus(422)->assertJsonPath('codigo', 'POSICAO_INEXISTENTE');
        $this->assertNull($this->em(fn () => MembroEquipaProjeto::query()->findOrFail($o['Rui'])->no_organigrama_projeto_id));

        // projecto de outra empresa: não se vê (404), mesmo com proj_gerir na empresa activa
        $sOutra = $this->sessao(['projectos_carteira_view', 'proj_gerir'], $this->outra);
        $alheio = $this->projectoComOrganigrama($sOutra, $this->outra);
        $this->postJson("/api/projetos/{$alheio['p']}/organigrama/mover", ['membro_id' => $alheio['Rui'], 'no_destino_id' => $alheio['frente']], $s)->assertNotFound();
    }
}
