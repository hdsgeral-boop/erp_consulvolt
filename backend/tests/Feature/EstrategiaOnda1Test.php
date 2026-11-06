<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\TarefaUnidadeOrganica;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Planeamento Estratégico, Onda 1 (F0 + F1 + F2a): plano, perspectivas, objectivos, indicadores, metas e medição manual.
 * Estados e congelamento, segregação (quem elabora não aprova), ciclo no pai, valores financeiros ocultos, semáforo em decimal,
 * fonte «módulo» por lista fechada, isolamento por empresa, permissões no servidor e idempotência da migração.
 */
final class EstrategiaOnda1Test extends TestCase
{
    private Empresa $empresa;

    private array $gestor;

    private array $aprovador;

    private array $financeiro;

    private array $leitor;

    private array $semAcesso;

    private const GESTOR = ['est_plano_view', 'est_plano_editar', 'est_indicadores_view', 'est_ind_editar', 'est_metas_editar', 'est_entregar', 'est_painel_view'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000901']);
        $this->gestor = $this->sessao(self::GESTOR);
        $this->aprovador = $this->sessao(['est_plano_view', 'est_indicadores_view', 'est_plano_aprovar']);
        $this->financeiro = $this->sessao([...self::GESTOR, 'est_ver_financeiros']);
        $this->leitor = $this->sessao(['est_plano_view', 'est_indicadores_view']);
        $this->semAcesso = $this->sessao(['dashboard_view']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function plano(array $extra = [], ?array $sessao = null): int
    {
        return (int) $this->postJson('/api/estrategia/planos', $extra + ['nome' => 'Plano 2026-2028', 'ano_inicio' => 2026, 'ano_fim' => 2028], $sessao ?? $this->gestor)->assertCreated()->json('dados.id');
    }

    private function perspectivas(int $plano): array
    {
        return collect($this->getJson("/api/estrategia/planos/{$plano}", $this->gestor)->assertOk()->json('dados.perspectivas'))->pluck('id', 'nome')->all();
    }

    private function objectivo(int $plano, array $extra = []): int
    {
        $persp = array_values($this->perspectivas($plano))[0];

        return (int) $this->postJson("/api/estrategia/planos/{$plano}/objectivos", $extra + ['nome' => 'Crescer a margem', 'perspectiva_id' => $persp], $this->gestor)->assertCreated()->json('dados.id');
    }

    private function indicador(int $plano, int $objectivo, array $extra = []): int
    {
        return (int) $this->postJson("/api/estrategia/planos/{$plano}/indicadores", $extra + ['nome' => 'Taxa de cumprimento', 'objectivo_id' => $objectivo, 'origem' => 'MANUAL', 'dono_externo' => 'Ana Dono', 'frequencia' => 'MENSAL'], $this->gestor)->assertCreated()->json('dados.id');
    }

    private function metas(int $indicador, array $metas, ?array $sessao = null)
    {
        return $this->putJson("/api/estrategia/indicadores/{$indicador}/metas", ['metas' => $metas], $sessao ?? $this->gestor);
    }

    /** Plano completo e aprovável: 1 objectivo, 1 indicador manual, 1 meta. @return array{0: int, 1: int, 2: int} */
    private function planoAprovavel(): array
    {
        $p = $this->plano();
        $o = $this->objectivo($p);
        $i = $this->indicador($p, $o);
        $this->metas($i, [['periodo' => '2026-01', 'valor' => 10]])->assertOk();

        return [$p, $o, $i];
    }

    private function em(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    // ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function sem_permissao_nao_ve_nem_escreve_e_aprovar_e_so_para_quem_tem_a_tarefa_sensivel(): void
    {
        $this->getJson('/api/estrategia/planos', $this->semAcesso)->assertForbidden();
        $this->getJson('/api/estrategia/catalogos', $this->semAcesso)->assertForbidden();
        $this->postJson('/api/estrategia/planos', [], $this->semAcesso)->assertForbidden();   // 403 antes de validar
        $this->postJson('/api/estrategia/planos', ['nome' => 'X', 'ano_inicio' => 2026, 'ano_fim' => 2028], $this->leitor)->assertForbidden();
        $this->getJson('/api/estrategia/planos', $this->leitor)->assertOk();
        $p = $this->plano();
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->gestor)->assertForbidden();
        $this->putJson('/api/estrategia/indicadores/1/metas', ['metas' => [['periodo' => '2026', 'valor' => 1]]], $this->leitor)->assertForbidden();
        $this->postJson('/api/estrategia/indicadores/1/medicoes', ['periodo' => '2026-01', 'valor' => 1], $this->leitor)->assertForbidden();
        $this->deleteJson("/api/estrategia/planos/{$p}", [], $this->leitor)->assertForbidden();
    }

    #[Test]
    public function um_plano_nasce_com_as_quatro_perspectivas_e_valida_o_ciclo(): void
    {
        $p = $this->plano(['missao' => '  Servir  ']);
        $r = $this->getJson("/api/estrategia/planos/{$p}", $this->gestor)->assertOk();
        $this->assertSame(['Financeira', 'Clientes', 'Processos internos', 'Pessoas e aprendizagem'], array_column($r->json('dados.perspectivas'), 'nome'));
        $this->assertSame(['fa-coins', 'fa-handshake', 'fa-gears', 'fa-graduation-cap'], array_column($r->json('dados.perspectivas'), 'icone'));
        $r->assertJsonPath('dados.plano.estado', 'RASCUNHO')->assertJsonPath('dados.plano.missao', 'Servir')->assertJsonPath('dados.plano.congelado', false);

        $this->postJson('/api/estrategia/planos', ['nome' => 'Longo', 'ano_inicio' => 2026, 'ano_fim' => 2041], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Um plano estratégico cobre no máximo 15 anos.');
        $this->postJson('/api/estrategia/planos', ['nome' => 'Curto', 'ano_inicio' => 2028, 'ano_fim' => 2026], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Indique o ano de início e um ano de fim igual ou posterior.');
        $this->postJson('/api/estrategia/planos', ['nome' => 'Quinze', 'ano_inicio' => 2026, 'ano_fim' => 2040], $this->gestor)->assertCreated();
        $this->postJson('/api/estrategia/planos', ['ano_inicio' => 2026, 'ano_fim' => 2028], $this->gestor)->assertStatus(422);
        $this->postJson('/api/estrategia/planos', ['nome' => 'Plano 2026-2028', 'ano_inicio' => 2026, 'ano_fim' => 2028], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Já existe um plano com este nome. Escolha outro.');
    }

    #[Test]
    public function perspectivas_nome_unico_icone_preservado_e_apagar_com_bloqueio(): void
    {
        $p = $this->plano();
        $persp = $this->perspectivas($p);
        $fin = $persp['Financeira'];
        $this->putJson("/api/estrategia/planos/{$p}/perspectivas/{$fin}", ['nome' => 'Financeira e valor', 'cor' => '#112233'], $this->gestor)->assertOk()
            ->assertJsonPath('dados.icone', 'fa-coins')->assertJsonPath('dados.cor', '#112233');
        $this->postJson("/api/estrategia/planos/{$p}/perspectivas", ['nome' => 'Clientes'], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Já existe uma perspectiva com este nome neste plano.');
        $this->postJson("/api/estrategia/planos/{$p}/perspectivas", ['nome' => 'Sustentabilidade', 'cor' => 'verde'], $this->gestor)->assertStatus(422);
        $nova = (int) $this->postJson("/api/estrategia/planos/{$p}/perspectivas", ['nome' => 'Sustentabilidade'], $this->gestor)->assertCreated()->json('dados.id');
        $this->objectivo($p, ['perspectiva_id' => $nova]);
        $this->deleteJson("/api/estrategia/planos/{$p}/perspectivas/{$nova}", [], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'A perspectiva tem 1 objectivo(s). Mude-os de perspectiva antes de a apagar.');
        $vazia = $persp['Clientes'];
        $this->deleteJson("/api/estrategia/planos/{$p}/perspectivas/{$vazia}", [], $this->gestor)->assertOk();
    }

    #[Test]
    public function objectivos_pai_sem_ciclos_okr_com_trimestre_e_apagar_com_bloqueios(): void
    {
        $p = $this->plano();
        $a = $this->objectivo($p, ['nome' => 'A']);
        $b = $this->objectivo($p, ['nome' => 'B', 'objectivo_pai_id' => $a]);
        $c = $this->objectivo($p, ['nome' => 'C', 'objectivo_pai_id' => $b]);
        $this->putJson("/api/estrategia/planos/{$p}/objectivos/{$a}", ['objectivo_pai_id' => $a], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Um objectivo não pode depender de si próprio.');
        $this->putJson("/api/estrategia/planos/{$p}/objectivos/{$a}", ['objectivo_pai_id' => $c], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'OBJECTIVO_PAI_CICLO');
        $outro = $this->plano(['nome' => 'Outro plano']);
        $estranho = $this->objectivo($outro);
        $this->putJson("/api/estrategia/planos/{$p}/objectivos/{$a}", ['objectivo_pai_id' => $estranho], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'OBJECTIVO_PAI_INVALIDO');

        $this->postJson("/api/estrategia/planos/{$p}/objectivos", ['nome' => 'OKR', 'perspectiva_id' => array_values($this->perspectivas($p))[0], 'horizonte' => 'TRIMESTRE'], $this->gestor)
            ->assertStatus(422)->assertJsonPath('mensagem', 'Um OKR tem de dizer a que trimestre pertence.');
        $this->postJson("/api/estrategia/planos/{$p}/objectivos", ['nome' => 'OKR', 'perspectiva_id' => array_values($this->perspectivas($p))[0], 'horizonte' => 'TRIMESTRE', 'periodo' => '2035-T1'], $this->gestor)
            ->assertStatus(422)->assertJsonPath('codigo', 'OBJECTIVO_TRIMESTRE_FORA_DO_CICLO');
        $okr = (int) $this->postJson("/api/estrategia/planos/{$p}/objectivos", ['nome' => 'OKR', 'perspectiva_id' => array_values($this->perspectivas($p))[0], 'horizonte' => 'TRIMESTRE', 'periodo' => '2026-T2', 'objectivo_pai_id' => $a], $this->gestor)
            ->assertCreated()->assertJsonPath('dados.periodo_rotulo', '2.º trimestre de 2026')->json('dados.id');
        $this->putJson("/api/estrategia/planos/{$p}/objectivos/{$okr}", ['horizonte' => 'PLANO'], $this->gestor)->assertOk()->assertJsonPath('dados.periodo', null);

        $this->deleteJson("/api/estrategia/planos/{$p}/objectivos/{$a}", [], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'OBJECTIVO_COM_DEPENDENTES');
        $this->deleteJson("/api/estrategia/planos/{$p}/objectivos/{$c}", [], $this->gestor)->assertOk();
        $this->indicador($p, $b);
        $this->deleteJson("/api/estrategia/planos/{$p}/objectivos/{$b}", [], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'O objectivo tem 1 indicador(es). Apague-os ou mude-os de objectivo primeiro.');
    }

    #[Test]
    public function indicadores_validam_origem_dono_tolerancias_e_a_fonte_do_modulo(): void
    {
        $p = $this->plano();
        $o = $this->objectivo($p);
        $url = "/api/estrategia/planos/{$p}/indicadores";
        $base = ['nome' => 'Indicador', 'objectivo_id' => $o, 'origem' => 'MANUAL', 'dono_externo' => 'Ana'];
        $this->postJson($url, ['objectivo_id' => $o, 'origem' => 'MANUAL', 'dono_externo' => 'Ana'], $this->gestor)->assertStatus(422)->assertJsonPath('erros.nome.0', 'Dê um nome ao indicador.');
        $this->postJson($url, ['nome' => 'X', 'origem' => 'MANUAL', 'dono_externo' => 'Ana'], $this->gestor)->assertStatus(422)->assertJsonPath('erros.objectivo_id.0', 'Um indicador tem de servir um objectivo.');
        $this->postJson($url, ['nome' => 'X', 'objectivo_id' => $o], $this->gestor)->assertStatus(422)->assertJsonPath('erros.origem.0', 'Escolha de onde vem o valor do indicador.');
        $this->postJson($url, ['nome' => 'X', 'objectivo_id' => $o, 'origem' => 'KPI_GESTAO'], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'ORIGEM_INDISPONIVEL');
        $this->postJson($url, ['nome' => 'X', 'objectivo_id' => $o, 'origem' => 'LIXO'], $this->gestor)->assertStatus(422);
        $this->postJson($url, ['nome' => 'X', 'objectivo_id' => $o, 'origem' => 'MANUAL'], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Um indicador de recolha manual precisa de dono: quem o entrega.');
        $this->postJson($url, $base + ['tolerancia_verde' => 80, 'tolerancia_amarela' => 90], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'As tolerâncias têm de ser positivas e a amarela menor ou igual à verde.');
        $this->postJson($url, $base + ['tolerancia_amarela' => 0], $this->gestor)->assertStatus(422);
        $this->postJson($url, ['objectivo_id' => 999999] + $base, $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'INDICADOR_SEM_OBJECTIVO');

        // agregação por omissão: percentagens e notas fazem média, o resto soma
        $this->postJson($url, $base + ['formato' => 'pct'], $this->gestor)->assertCreated()->assertJsonPath('dados.agregacao', 'MEDIA');
        $this->postJson($url, ['nome' => 'Quantidade'] + $base + ['formato' => 'num'], $this->gestor)->assertCreated()->assertJsonPath('dados.agregacao', 'SOMA');

        $fonte = ['modulo' => 'entregaveis', 'operacao' => 'SOMA', 'campo' => 'quantidade', 'filtros' => [['campo' => 'validacao', 'operador' => 'IGUAL', 'valor' => 'VALIDADA']]];
        $this->postJson($url, ['nome' => 'Entregas', 'objectivo_id' => $o, 'origem' => 'MODULO', 'fonte' => $fonte], $this->gestor)->assertCreated()
            ->assertJsonPath('dados.fonte.modulo', 'entregaveis')->assertJsonPath('dados.fonte.operacao', 'SOMA');
        foreach ([
            ['modulo' => 'users', 'operacao' => 'CONTAGEM'],                                                                      // tabela que não está na lista fechada
            ['modulo' => 'entregaveis', 'operacao' => 'SOMA'],                                                                    // soma sem campo
            ['modulo' => 'entregaveis', 'operacao' => 'SOMA', 'campo' => 'password'],                                             // campo fora da lista
            ['modulo' => 'colaboradores', 'operacao' => 'SOMA', 'campo' => 'quantidade'],                                         // fonte sem campos numéricos
            ['modulo' => 'entregaveis', 'operacao' => 'CONTAGEM', 'filtros' => [['campo' => 'senha', 'operador' => 'IGUAL', 'valor' => 'x']]],
            ['modulo' => 'entregaveis', 'operacao' => 'CONTAGEM', 'filtros' => [['campo' => 'colaborador_id', 'operador' => 'IGUAL', 'valor' => '1; DROP TABLE x']]],
            ['modulo' => 'entregaveis', 'operacao' => 'CONTAGEM', 'filtros' => [['campo' => 'validacao', 'operador' => 'IGUAL']]],
        ] as $mau) {
            $this->postJson($url, ['nome' => 'Mau', 'objectivo_id' => $o, 'origem' => 'MODULO', 'fonte' => $mau], $this->gestor)->assertStatus(422);
        }
        $this->assertSame(0, DB::table('indicadores_estrategicos')->where('nome', 'Mau')->count());

        $c = $this->getJson('/api/estrategia/catalogos', $this->gestor)->assertOk()->json('dados');
        $this->assertSame(['entregaveis', 'colaboradores'], array_column($c['fontes_modulo'], 'id'));
        $this->assertStringNotContainsString('execucoes_entregavel', json_encode($c), 'o catálogo nunca expõe nomes de tabela');
        $this->assertSame([false, false, false, true, true], array_column($c['origens'], 'disponivel'));
    }

    #[Test]
    public function metas_por_periodo_frequencia_ciclo_e_apagar_com_valor_vazio(): void
    {
        $p = $this->plano();
        $i = $this->indicador($p, $this->objectivo($p), ['frequencia' => 'TRIMESTRAL']);
        $this->metas($i, [['periodo' => '2026-03', 'valor' => 1]])->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');
        $this->metas($i, [['periodo' => '2030-T1', 'valor' => 1]])->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_FORA_DO_CICLO');
        $this->metas($i, [['periodo' => '2026-T1', 'valor' => 'abc']])->assertStatus(422);
        $r = $this->metas($i, [['periodo' => '2026-T1', 'valor' => '100,5'.''], ['periodo' => '2026-T2', 'valor' => 120, 'nota' => 'Meta de verão']])->assertStatus(422);
        $r = $this->metas($i, [['periodo' => '2026-T1', 'valor' => 100.5], ['periodo' => '2026-T2', 'valor' => 120, 'nota' => 'Meta de verão']])->assertOk();
        $this->assertSame(['2026-T1', '2026-T2'], array_column($r->json('dados.metas'), 'periodo'));
        $r->assertJsonPath('dados.metas.0.valor', '100.5000')->assertJsonPath('dados.anos.0.ano', 2026)->assertJsonCount(3, 'dados.anos');
        $this->assertCount(4, $r->json('dados.anos.0.periodos'));
        // valor vazio apaga; repetir não duplica
        $this->metas($i, [['periodo' => '2026-T1', 'valor' => null], ['periodo' => '2026-T2', 'valor' => 125]])->assertOk()->assertJsonCount(1, 'dados.metas');
        $this->metas($i, [['periodo' => '2026-T2', 'valor' => 125]])->assertOk();
        $this->assertSame(1, DB::table('metas_indicadores')->count());
        $this->assertSame('125.0000', DB::table('metas_indicadores')->value('valor'));
        $this->putJson("/api/estrategia/planos/{$p}/indicadores/{$i}", ['frequencia' => 'ANUAL'], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'INDICADOR_FREQUENCIA_BLOQUEADA');
    }

    #[Test]
    public function medicao_manual_com_semaforo_decimal_substitui_e_nao_se_apaga(): void
    {
        $p = $this->plano();
        $i = $this->indicador($p, $this->objectivo($p), ['formato' => 'num']);
        $this->metas($i, [['periodo' => '2026-03', 'valor' => 9], ['periodo' => '2026-04', 'valor' => 9], ['periodo' => '2026-05', 'valor' => 9], ['periodo' => '2026-06', 'valor' => 9]])->assertOk();

        // 8,1 ÷ 9 × 100 = 90 exactamente: AMARELO (o legado, em vírgula flutuante, dava VERMELHO)
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-03', 'valor' => 8.1], $this->gestor)->assertCreated()
            ->assertJsonPath('dados.semaforo', 'AMARELO')->assertJsonPath('dados.cumprimento', '90.0000')->assertJsonPath('dados.valor', '8.1000');
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-04', 'valor' => 9], $this->gestor)->assertCreated()->assertJsonPath('dados.semaforo', 'VERDE');
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-05', 'valor' => 8.09], $this->gestor)->assertCreated()->assertJsonPath('dados.semaforo', 'VERMELHO');
        // entregar de novo substitui
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-05', 'valor' => 9.5, 'nota' => 'Corrigido'], $this->gestor)->assertCreated()->assertJsonPath('dados.semaforo', 'VERDE');
        $this->assertSame(3, DB::table('medicoes_indicadores')->count());

        $s = $this->getJson("/api/estrategia/indicadores/{$i}/serie?ano=2026", $this->gestor)->assertOk();
        $this->assertCount(12, $s->json('dados.periodos'));
        $this->assertSame(['AMARELO', 'VERDE', 'VERDE', 'SEM_MEDIDA'], array_slice(array_column($s->json('dados.periodos'), 'semaforo'), 2, 4));
        $this->assertSame('SEM_META', $s->json('dados.periodos.0.semaforo'));
        $s->assertJsonPath('dados.resumo.VERDE', 2)->assertJsonPath('dados.resumo.cumpridos_pct', '66.7');
        $this->getJson("/api/estrategia/indicadores/{$i}/serie?ano=2035", $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'ANO_FORA_DO_CICLO');

        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2028-12', 'valor' => 1], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_FUTURO');
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-T1', 'valor' => 1], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-03', 'valor' => 'x'], $this->gestor)->assertStatus(422);
        // não há como apagar uma medição, e um indicador com medições não se apaga
        $this->deleteJson("/api/estrategia/indicadores/{$i}/medicoes", [], $this->gestor)->assertStatus(405);
        $this->deleteJson("/api/estrategia/planos/{$p}/indicadores/{$i}", [], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'O indicador já tem 3 medição(ões) gravadas. Desactive-o em vez de o apagar, para não perder o histórico.');
        $this->putJson("/api/estrategia/planos/{$p}/indicadores/{$i}", ['ativo' => false], $this->gestor)->assertOk()->assertJsonPath('dados.ativo', false);
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-03', 'valor' => 1], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'INDICADOR_INACTIVO');
    }

    #[Test]
    public function a_medicao_so_vale_para_indicadores_manuais_e_o_teste_le_o_modulo_sem_gravar(): void
    {
        $p = $this->plano();
        $o = $this->objectivo($p);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $col = Colaborador::create(['nome_completo' => 'Ana', 'nif' => 'ANA', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22])->id;
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'ativo' => true]);
            $t = TarefaUnidadeOrganica::create(['unidade_organica_id' => $dep->id, 'nome' => 'Relatórios', 'periodicidade' => 'MENSAL', 'ativo' => true]);
            $e = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Relatório', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'meta' => 10, 'ativo' => true]);
            $x = fn (string $data, float $q, string $v) => ExecucaoEntregavel::create(['entregavel_id' => $e->id, 'tarefa_unidade_id' => $t->id, 'unidade_organica_id' => $dep->id, 'colaborador_id' => $col, 'quantidade' => $q, 'data' => $data, 'validacao' => $v, 'origem' => 'MANUAL']);
            $x('2026-06-02', 15, 'VALIDADA');
            $x('2026-06-30', 2.5, 'VALIDADA');
            $x('2026-06-10', 7, 'RECUSADA');
            $x('2026-07-01', 99, 'VALIDADA');   // fora do mês
        });
        $soma = $this->indicador($p, $o, ['nome' => 'Entregas validadas', 'origem' => 'MODULO', 'dono_externo' => null,
            'fonte' => ['modulo' => 'entregaveis', 'operacao' => 'SOMA', 'campo' => 'quantidade', 'filtros' => [['campo' => 'validacao', 'operador' => 'IGUAL', 'valor' => 'VALIDADA']]]]);
        $contagem = $this->indicador($p, $o, ['nome' => 'Entregas não recusadas', 'origem' => 'MODULO', 'dono_externo' => null,
            'fonte' => ['modulo' => 'entregaveis', 'operacao' => 'CONTAGEM', 'filtros' => [['campo' => 'validacao', 'operador' => 'DIFERENTE', 'valor' => 'RECUSADA']]]]);
        $media = $this->indicador($p, $o, ['nome' => 'Média', 'origem' => 'MODULO', 'dono_externo' => null,
            'fonte' => ['modulo' => 'entregaveis', 'operacao' => 'MEDIA', 'campo' => 'quantidade']]);
        $manual = $this->indicador($p, $o);

        $this->postJson("/api/estrategia/indicadores/{$soma}/testar", ['periodo' => '2026-06'], $this->gestor)->assertOk()->assertJsonPath('dados.periodo', '2026-06')->assertJsonPath('dados.valor', '17.5000');
        $this->postJson("/api/estrategia/indicadores/{$soma}/testar", [], $this->gestor)->assertOk()->assertJsonPath('dados.periodo', now()->format('Y-m'));
        $this->postJson("/api/estrategia/indicadores/{$contagem}/testar", ['periodo' => '2026-06'], $this->gestor)->assertOk()->assertJsonPath('dados.valor', '2');
        $this->postJson("/api/estrategia/indicadores/{$media}/testar", ['periodo' => '2026-06'], $this->gestor)->assertOk()->assertJsonPath('dados.valor', '8.1667');   // (15 + 2,5 + 7) ÷ 3 = 24,5 ÷ 3
        $this->postJson("/api/estrategia/indicadores/{$soma}/testar", ['periodo' => '2026-T2'], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');
        $this->postJson("/api/estrategia/indicadores/{$manual}/testar", [], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Indicador de recolha manual: o valor é escrito no painel, não há nada para testar.');
        $this->assertSame(0, DB::table('medicoes_indicadores')->count(), 'testar não grava');
        $this->postJson("/api/estrategia/indicadores/{$soma}/medicoes", ['periodo' => '2026-06', 'valor' => 1], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'INDICADOR_NAO_MANUAL');
    }

    #[Test]
    public function aprovar_exige_objectivos_indicadores_e_metas_e_so_um_plano_em_vigor(): void
    {
        $p = $this->plano();
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->aprovador)->assertStatus(422)->assertJsonPath('mensagem', 'O plano ainda não pode ser aprovado: Falta pelo menos um objectivo activo.');
        $o = $this->objectivo($p, ['nome' => 'Crescer']);
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->aprovador)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_INCOMPLETO');
        $i = $this->indicador($p, $o);
        $r = $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->aprovador)->assertStatus(422);
        $this->assertStringContainsString('não tem metas', $r->json('mensagem'));
        $this->metas($i, [['periodo' => '2026-01', 'valor' => 10]])->assertOk();

        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->aprovador)->assertOk()->assertJsonPath('dados.estado', 'APROVADO')->assertJsonPath('dados.congelado', true)->assertJsonPath('dados.aprovacao_excepcao', null);
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->aprovador)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_ESTADO_INVALIDO');

        // um segundo plano completo não se aprova com o primeiro em vigor
        $p2 = $this->plano(['nome' => 'Plano B']);
        $o2 = $this->objectivo($p2);
        $i2 = $this->indicador($p2, $o2);
        $this->metas($i2, [['periodo' => '2026-01', 'valor' => 10]])->assertOk();
        $this->postJson("/api/estrategia/planos/{$p2}/aprovar", [], $this->aprovador)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_JA_VIGENTE');
        $this->postJson("/api/estrategia/planos/{$p}/encerrar", [], $this->aprovador)->assertOk();
        $this->postJson("/api/estrategia/planos/{$p2}/aprovar", [], $this->aprovador)->assertOk();
    }

    #[Test]
    public function quem_elabora_o_plano_nao_o_aprova_salvo_excepcao_com_motivo_auditada(): void
    {
        $gestorEAprovador = $this->sessao([...self::GESTOR, 'est_plano_aprovar']);
        $p = $this->plano([], $gestorEAprovador);
        $o = (int) $this->postJson("/api/estrategia/planos/{$p}/objectivos", ['nome' => 'Crescer', 'perspectiva_id' => array_values($this->perspectivas($p))[0]], $gestorEAprovador)->assertCreated()->json('dados.id');
        $i = (int) $this->postJson("/api/estrategia/planos/{$p}/indicadores", ['nome' => 'I', 'objectivo_id' => $o, 'origem' => 'MANUAL', 'dono_externo' => 'Ana'], $gestorEAprovador)->assertCreated()->json('dados.id');
        $this->metas($i, [['periodo' => '2026-01', 'valor' => 10]], $gestorEAprovador)->assertOk();

        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $gestorEAprovador)->assertStatus(422)->assertJsonPath('codigo', 'AUTO_APROVACAO');
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", ['excepcao' => 'curto'], $gestorEAprovador)->assertStatus(422)->assertJsonPath('codigo', 'AUTO_APROVACAO');
        $this->assertSame('RASCUNHO', DB::table('planos_estrategicos')->where('id', $p)->value('estado'));
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", ['excepcao' => 'Empresa só tem uma pessoa com esta função.'], $gestorEAprovador)->assertOk()
            ->assertJsonPath('dados.estado', 'APROVADO')->assertJsonPath('dados.aprovacao_excepcao', 'Empresa só tem uma pessoa com esta função.');
        $this->assertSame(1, DB::table('logs_auditoria')->where('acao', 'Plano aprovado por quem o elaborou (excepção)')->count());
    }

    #[Test]
    public function plano_aprovado_fica_congelado_e_so_se_altera_com_permissao_e_motivo_auditado(): void
    {
        [$p, $o, $i] = $this->planoAprovavel();
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->aprovador)->assertOk();
        $urlObj = "/api/estrategia/planos/{$p}/objectivos/{$o}";
        $persp = array_values($this->perspectivas($p))[0];

        // a estrutura, as metas e os indicadores estão congelados para quem não tem est_plano_aprovar
        $this->putJson($urlObj, ['nome' => 'Mudado'], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_APROVADO_BLOQUEADO');
        $this->postJson("/api/estrategia/planos/{$p}/objectivos", ['nome' => 'Novo', 'perspectiva_id' => $persp, 'motivo_excepcao' => 'Um motivo bem comprido'], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_APROVADO_BLOQUEADO');
        $this->metas($i, [['periodo' => '2026-02', 'valor' => 5]])->assertStatus(422)->assertJsonPath('codigo', 'PLANO_APROVADO_BLOQUEADO');
        $this->putJson("/api/estrategia/planos/{$p}", ['missao' => 'Nova'], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_APROVADO_BLOQUEADO');
        $this->postJson("/api/estrategia/planos/{$p}/perspectivas", ['nome' => 'Nova perspectiva'], $this->gestor)->assertStatus(422);
        $this->deleteJson("/api/estrategia/planos/{$p}", [], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_NAO_APAGAVEL');

        // com est_plano_aprovar e motivo: permitido e auditado (sem motivo ou com motivo curto: recusado)
        $chefe = $this->sessao([...self::GESTOR, 'est_plano_aprovar']);
        $this->putJson($urlObj, ['nome' => 'Mudado'], $chefe)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_APROVADO_BLOQUEADO');
        $this->putJson($urlObj, ['nome' => 'Mudado', 'motivo_excepcao' => 'curto'], $chefe)->assertStatus(422);
        $this->putJson($urlObj, ['nome' => 'Mudado', 'motivo_excepcao' => 'Correcção de gralha no texto do objectivo.'], $chefe)->assertOk()->assertJsonPath('dados.nome', 'Mudado');
        $this->metas($i, [['periodo' => '2026-02', 'valor' => 5]], $chefe)->assertStatus(422);
        $this->putJson("/api/estrategia/indicadores/{$i}/metas", ['metas' => [['periodo' => '2026-02', 'valor' => 5]], 'motivo_excepcao' => 'Meta de Fevereiro esquecida na aprovação.'], $chefe)->assertOk();
        $this->assertSame(2, DB::table('logs_auditoria')->where('acao', 'Alteração a plano aprovado (excepção)')->count());

        // medições continuam livres no plano aprovado
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-01', 'valor' => 10], $this->gestor)->assertCreated()->assertJsonPath('dados.semaforo', 'VERDE');
    }

    #[Test]
    public function execucao_reabrir_com_motivo_encerrar_e_so_leitura(): void
    {
        [$p, , $i] = $this->planoAprovavel();
        $this->postJson("/api/estrategia/planos/{$p}/iniciar-execucao", [], $this->aprovador)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_ESTADO_INVALIDO');
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->aprovador)->assertOk();
        $this->postJson("/api/estrategia/planos/{$p}/reabrir", [], $this->aprovador)->assertStatus(422)->assertJsonPath('mensagem', 'Indique o motivo da reabertura do plano.');
        $this->postJson("/api/estrategia/planos/{$p}/reabrir", ['motivo' => 'Faltou rever uma meta'], $this->aprovador)->assertOk()
            ->assertJsonPath('dados.estado', 'RASCUNHO')->assertJsonPath('dados.motivo_reabertura', 'Faltou rever uma meta')->assertJsonPath('dados.aprovado_em', null);
        $this->metas($i, [['periodo' => '2026-02', 'valor' => 5]])->assertOk();   // reaberto: editável
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->aprovador)->assertOk();
        $this->postJson("/api/estrategia/planos/{$p}/iniciar-execucao", [], $this->aprovador)->assertOk()->assertJsonPath('dados.estado', 'EM_EXECUCAO')->assertJsonPath('dados.em_vigor', true);
        $this->postJson("/api/estrategia/planos/{$p}/reabrir", ['motivo' => 'Já em execução'], $this->aprovador)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_ESTADO_INVALIDO');
        $this->postJson("/api/estrategia/planos/{$p}/encerrar", [], $this->aprovador)->assertOk()->assertJsonPath('dados.estado', 'ENCERRADO');
        $this->postJson("/api/estrategia/planos/{$p}/encerrar", [], $this->aprovador)->assertStatus(422);

        $chefe = $this->sessao([...self::GESTOR, 'est_plano_aprovar']);
        $this->putJson("/api/estrategia/planos/{$p}", ['missao' => 'X', 'motivo_excepcao' => 'Motivo suficientemente longo'], $chefe)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_FECHADO');
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-01', 'valor' => 1], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_FECHADO');
        $this->getJson("/api/estrategia/planos/{$p}", $this->leitor)->assertOk()->assertJsonPath('dados.plano.estado', 'ENCERRADO');
    }

    #[Test]
    public function apagar_so_um_rascunho_sem_objectivos_nem_indicadores(): void
    {
        $vazio = $this->plano(['nome' => 'Para apagar']);
        $this->deleteJson("/api/estrategia/planos/{$vazio}", [], $this->gestor)->assertOk();
        $this->assertSame(0, DB::table('perspectivas_estrategicas')->where('plano_estrategico_id', $vazio)->count());
        $this->getJson("/api/estrategia/planos/{$vazio}", $this->gestor)->assertNotFound();
        [$p] = $this->planoAprovavel();
        $this->deleteJson("/api/estrategia/planos/{$p}", [], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_COM_DADOS');
    }

    #[Test]
    public function valores_financeiros_so_com_a_tarefa_sensivel_mas_o_semaforo_mantem_se(): void
    {
        $p = $this->plano();
        $i = $this->indicador($p, $this->objectivo($p), ['formato' => 'kz', 'linha_base' => 1500000.5]);
        $this->metas($i, [['periodo' => '2026-03', 'valor' => 1000000]])->assertOk()->assertJsonPath('dados.metas.0.valor', null)->assertJsonPath('dados.oculto', true);
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-03', 'valor' => 900000], $this->gestor)->assertCreated()
            ->assertJsonPath('dados.valor', null)->assertJsonPath('dados.meta', null)->assertJsonPath('dados.cumprimento', null)->assertJsonPath('dados.oculto', true)->assertJsonPath('dados.semaforo', 'AMARELO');
        $this->getJson("/api/estrategia/planos/{$p}/indicadores", $this->gestor)->assertOk()->assertJsonPath('dados.indicadores.0.linha_base', null)->assertJsonPath('dados.indicadores.0.oculto', true);
        $s = $this->getJson("/api/estrategia/indicadores/{$i}/serie?ano=2026", $this->gestor)->assertOk();
        $this->assertSame('AMARELO', $s->json('dados.periodos.2.semaforo'));
        $this->assertNull($s->json('dados.periodos.2.valor'));

        $this->getJson("/api/estrategia/indicadores/{$i}/serie?ano=2026", $this->financeiro)->assertOk()->assertJsonPath('dados.periodos.2.valor', '900000.0000')->assertJsonPath('dados.periodos.2.meta', '1000000.0000')->assertJsonPath('dados.oculto', false);
        $this->getJson("/api/estrategia/planos/{$p}/indicadores", $this->financeiro)->assertJsonPath('dados.indicadores.0.linha_base', '1500000.5000');
        $this->getJson("/api/estrategia/indicadores/{$i}/metas", $this->financeiro)->assertJsonPath('dados.metas.0.valor', '1000000.0000');
    }

    #[Test]
    public function isolamento_por_empresa(): void
    {
        $p = $this->plano();
        $o = $this->objectivo($p);
        $i = $this->indicador($p, $o);
        $outra = $this->criarEmpresa(['nif' => '5417000902']);
        $gestorB = $this->sessao(self::GESTOR, $outra);
        $this->getJson('/api/estrategia/planos', $gestorB)->assertOk()->assertJsonCount(0, 'dados.planos');
        $this->getJson("/api/estrategia/planos/{$p}", $gestorB)->assertNotFound();
        $this->putJson("/api/estrategia/planos/{$p}/objectivos/{$o}", ['nome' => 'Invasor'], $gestorB)->assertNotFound();
        $this->getJson("/api/estrategia/planos/{$p}/indicadores", $gestorB)->assertNotFound();
        $this->putJson("/api/estrategia/indicadores/{$i}/metas", ['metas' => [['periodo' => '2026-01', 'valor' => 1]]], $gestorB)->assertNotFound();
        $this->postJson("/api/estrategia/indicadores/{$i}/medicoes", ['periodo' => '2026-01', 'valor' => 1], $gestorB)->assertNotFound();
        $this->postJson("/api/estrategia/planos/{$p}/aprovar", [], $this->sessao(['est_plano_aprovar'], $outra))->assertNotFound();
        // o mesmo nome de plano pode existir noutra empresa
        $this->postJson('/api/estrategia/planos', ['nome' => 'Plano 2026-2028', 'ano_inicio' => 2026, 'ano_fim' => 2028], $gestorB)->assertCreated();
        // e cada empresa tem o seu plano em vigor
        $this->assertSame(1, DB::table('planos_estrategicos')->where('empresa_id', $outra->id)->count());
    }

    #[Test]
    public function a_migracao_e_idempotente_e_nao_perde_dados(): void
    {
        $this->plano();
        $migracao = require database_path('migrations/2026_12_16_090000_estrategia_nucleo.php');
        $migracao->up();
        $migracao->up();
        $this->assertSame(1, DB::table('planos_estrategicos')->count());
        $this->assertSame(4, DB::table('perspectivas_estrategicas')->count());
        foreach (['planos_estrategicos', 'perspectivas_estrategicas', 'objectivos_estrategicos', 'indicadores_estrategicos', 'metas_indicadores', 'medicoes_indicadores'] as $t) {
            $this->assertTrue(DB::table('pg_class')->where('relname', $t)->where('relrowsecurity', true)->exists(), "RLS em {$t}");
        }
        $licenca = require database_path('migrations/2026_12_16_090100_estrategia_plano_licenca.php');
        $licenca->up();
        $licenca->up();
    }
}
