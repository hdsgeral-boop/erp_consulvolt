<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\RegistoManutencaoAtivo;
use App\Models\Terceiro;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 3 (r3_activos): propriedade dos bens e edifícios (próprio/alheio — só os próprios amortizam, entram nos mapas e se
 * ligam a aquisições), estrutura dos edifícios para o desenho do painel, e gestão das tarefas das ordens de manutenção
 * (progresso, conclusão bloqueada com tarefas obrigatórias por fazer, quem/quando, reabrir tarefa reabre a ordem e repõe o
 * plano, histórico, acrescentar/retirar, permissões e isolamento). Dados fictícios.
 */
final class R3ActivosTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417300031']);
        $this->naEmpresa(function () {
            foreach (['7315' => 'Amortizações do exercício', '1815' => 'Amortizações acumuladas', '1141' => 'Equipamento administrativo', '3211' => 'Fornecedores'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->ids['forn'] = Terceiro::create(['nome' => 'Fornecedor fictício', 'tipo' => 'FORNECEDOR', 'codigo_conta' => '3211'])->id;
            $this->ids['senhorio'] = Terceiro::create(['nome' => 'Senhorio fictício', 'tipo' => 'FORNECEDOR'])->id;
        });
        $this->h = $this->sessao(['activos_view', 'activos_gerir', 'activos_cat_gerir', 'activos_amortizacoes_view', 'activos_amort_calcular', 'activos_mapa_view',
            'activos_abater', 'activos_inventariar', 'activos_edificios_view', 'activos_locais_gerir', 'activos_manutencao_view', 'activos_manut_gerir']);
        $this->ids['cat'] = $this->postJson('/api/ativos/categorias', ['nome' => 'Equipamento', 'taxa_anual' => 25, 'vida_util_padrao' => 48,
            'conta_gasto' => '7315', 'conta_amortizacao_acumulada' => '1815'], $this->h)->assertCreated()->json('dados.id');
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
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
        return $this->postJson('/api/ativos/bens', $d + ['categoria_ativo_id' => $this->ids['cat']], $this->h)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function propriedade_proprio_e_alheio_com_validacao_no_servidor(): void
    {
        // por omissão (e para todos os existentes) o bem é próprio
        $proprio = $this->bem(['descricao' => 'Computador próprio', 'valor_aquisicao' => 1200, 'data_aquisicao' => '2026-01-05', 'vida_util' => 12]);
        $this->getJson("/api/ativos/bens/{$proprio}", $this->h)->assertJsonPath('dados.propriedade', 'PROPRIO')->assertJsonPath('dados.proprietario_nome', null);

        // alheio: sem aquisição nem amortização obrigatórias; os campos de cálculo ficam a zero; dados do contrato opcionais
        $alheio = $this->postJson('/api/ativos/bens', ['descricao' => 'Escritório arrendado', 'categoria_ativo_id' => $this->ids['cat'], 'tipo_ativo' => 'EDIFICIO',
            'propriedade' => 'ALHEIO', 'regime_alheio' => 'ARRENDADO', 'proprietario_terceiro_id' => $this->ids['senhorio'], 'contrato_referencia' => 'CT-01',
            'contrato_inicio' => '2026-02-01', 'contrato_fim' => '2028-01-31', 'renda_mensal' => 350000, 'vida_util' => 600, 'quota_fixa' => 99, 'valor_residual' => 5],
            $this->h)->assertCreated()->assertJsonPath('dados.propriedade', 'ALHEIO')->assertJsonPath('dados.vida_util', 0)->assertJsonPath('dados.quota_fixa', '0.00')
            ->assertJsonPath('dados.valor_aquisicao', '0.00')->assertJsonPath('dados.renda_mensal', '350000.00')->json('dados');
        $this->assertSame('2026-02-01', substr($alheio['data_aquisicao'], 0, 10));   // entrada ao serviço = início do contrato
        $this->getJson("/api/ativos/bens/{$alheio['id']}", $this->h)->assertJsonPath('dados.proprietario.nome', 'Senhorio fictício');

        // validação coerente
        $this->postJson('/api/ativos/bens', ['descricao' => 'X', 'categoria_ativo_id' => $this->ids['cat'], 'propriedade' => 'EMPRESTADO'], $this->h)->assertStatus(422);
        $this->postJson('/api/ativos/bens', ['descricao' => 'X', 'categoria_ativo_id' => $this->ids['cat'], 'propriedade' => 'ALHEIO', 'contrato_inicio' => '2026-05-01',
            'contrato_fim' => '2026-04-01'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'DADOS_INVALIDOS');
        $this->postJson('/api/ativos/bens', ['descricao' => 'X', 'categoria_ativo_id' => $this->ids['cat'], 'propriedade' => 'ALHEIO', 'proprietario_terceiro_id' => 999999],
            $this->h)->assertStatus(422);
        // um próprio não guarda dados de contrato
        $this->putJson("/api/ativos/bens/{$proprio}", ['proprietario_nome' => 'Ninguém'], $this->h)->assertOk()->assertJsonPath('dados.proprietario_nome', null);

        // só os próprios amortizam
        $this->postJson('/api/ativos/amortizacoes/calcular', ['periodos' => ['02-2026']], $this->h)->assertOk();
        $linhas = $this->getJson('/api/ativos/amortizacoes?periodo=02-2026', $this->h)->assertOk()->json('dados.linhas');
        $this->assertSame([$proprio], array_column($linhas, 'ativo_imobilizado_id'));
        $this->putJson('/api/ativos/amortizacoes/quota', ['ativo_imobilizado_id' => $alheio['id'], 'periodo' => '03-2026', 'valor' => 10], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_ALHEIO');
        // mapas fiscais e de amortizações sem alheios
        $this->assertSame([$proprio], array_column($this->getJson('/api/ativos/mapas/fiscal?ano=2026', $this->h)->assertOk()->json('dados.linhas'), 'ativo_imobilizado_id'));
        $this->assertSame([$proprio], array_column($this->getJson('/api/ativos/mapas/amortizacoes?ano=2026', $this->h)->assertOk()->json('dados.linhas'), 'ativo_imobilizado_id'));
        // filtro da lista
        $this->assertSame([$alheio['id']], array_column($this->getJson('/api/ativos/bens?propriedade=ALHEIO', $this->h)->json('dados'), 'id'));
        $this->assertSame([$proprio], array_column($this->getJson('/api/ativos/bens?propriedade=PROPRIO', $this->h)->json('dados'), 'id'));

        // com amortizações, um próprio não passa a alheio
        $this->putJson("/api/ativos/bens/{$proprio}", ['propriedade' => 'ALHEIO'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'PROPRIEDADE_BLOQUEADA');
        // sem histórico passa; e volta a próprio limpando o contrato
        $livre = $this->bem(['descricao' => 'Mesa cedida', 'valor_aquisicao' => 50, 'data_aquisicao' => '2026-06-01']);
        $this->putJson("/api/ativos/bens/{$livre}", ['propriedade' => 'ALHEIO', 'regime_alheio' => 'CEDIDO', 'proprietario_nome' => 'Parceiro fictício'], $this->h)
            ->assertOk()->assertJsonPath('dados.propriedade', 'ALHEIO')->assertJsonPath('dados.proprietario_nome', 'Parceiro fictício');
        $this->putJson("/api/ativos/bens/{$livre}", ['propriedade' => 'PROPRIO', 'valor_aquisicao' => 50], $this->h)->assertOk()
            ->assertJsonPath('dados.propriedade', 'PROPRIO')->assertJsonPath('dados.regime_alheio', null)->assertJsonPath('dados.proprietario_nome', null);

        // alheio não se abate nem se liga a uma aquisição
        $this->postJson('/api/ativos/abates', ['ativo_imobilizado_id' => $alheio['id'], 'tipo' => 'FIM_VIDA', 'data' => '2026-03-31'], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_ALHEIO');
        $linha = $this->naEmpresa(fn () => app(ServicoLancamentos::class)->criar([
            'diario_id' => app(LocalizadorLancamentos::class)->diario('CP', 'Compras')->id, 'data_documento' => '2026-01-10', 'numero_documento' => 'FT F/9',
            'linhas' => [['codigo_conta' => '1141', 'tipo_dc' => 'D', 'valor' => '5000', 'terceiro_id' => $this->ids['forn']],
                ['codigo_conta' => '3211', 'tipo_dc' => 'C', 'valor' => '5000', 'terceiro_id' => $this->ids['forn']]],
        ])->firstWhere('tipo_dc', 'D')->id);
        $outroAlheio = $this->bem(['descricao' => 'Impressora de terceiros', 'propriedade' => 'ALHEIO', 'regime_alheio' => 'TERCEIROS']);
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$linha}/ligar", ['ids' => [$outroAlheio]], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'ATIVO_ALHEIO');
    }

    #[Test]
    public function edificios_trazem_pisos_e_portas_para_o_desenho(): void
    {
        $ed = $this->bem(['descricao' => 'Sede fictícia', 'tipo_ativo' => 'EDIFICIO', 'valor_aquisicao' => 1000000, 'data_aquisicao' => '2026-01-01']);
        $r0 = $this->postJson("/api/ativos/edificios/{$ed}/andares", ['numero' => '0'], $this->h)->assertCreated()->json('dados.id');
        $r1 = $this->postJson("/api/ativos/edificios/{$ed}/andares", ['numero' => '1'], $this->h)->assertCreated()->json('dados.id');
        $this->postJson("/api/ativos/edificios/{$ed}/portas", ['andar_id' => $r0, 'prefixo' => 'A', 'de' => 1, 'ate' => 3], $this->h)->assertCreated();
        $this->postJson("/api/ativos/edificios/{$ed}/portas", ['andar_id' => $r1, 'prefixo' => 'B', 'de' => 1, 'ate' => 2], $this->h)->assertCreated();
        $porta = $this->getJson("/api/ativos/edificios/{$ed}/planta", $this->h)->json('dados.andares.0.portas.1.id');
        $bem = $this->bem(['descricao' => 'Secretária', 'valor_aquisicao' => 100, 'porta_id' => $porta]);

        $e = $this->getJson('/api/ativos/edificios', $this->h)->assertOk()->json('dados.edificios.0');
        $this->assertSame(['PROPRIO', 2, 5, 1], [$e['propriedade'], $e['andares'], $e['portas'], $e['bens']]);
        $this->assertSame(['Rés-do-chão', '1.º andar'], array_column($e['pisos'], 'nome'));
        $this->assertSame(['A1', 'A2', 'A3'], array_column($e['pisos'][0]['portas'], 'numero'));
        $this->assertSame([0, 1, 0], array_column($e['pisos'][0]['portas'], 'n_bens'));
        $this->getJson("/api/ativos/edificios/{$ed}/planta", $this->h)->assertJsonPath('dados.edificio.propriedade', 'PROPRIO')
            ->assertJsonPath('dados.andares.0.portas.1.bens.0.id', $bem);
    }

    /** Plano com duas tarefas (uma obrigatória e uma opcional na ordem avulsa) — devolve o id da ordem. */
    private function ordem(array $tarefas, ?int $plano = null): int
    {
        if ($plano) {
            $this->postJson("/api/ativos/manutencao/planos/{$plano}/ordens", [], $this->h)->assertCreated();

            return $this->getJson('/api/ativos/manutencao/ordens', $this->h)->json('dados.0.id');
        }

        return $this->postJson('/api/ativos/manutencao/ordens', ['ativo_imobilizado_id' => $this->ids['gerador'], 'tipo' => 'CORRECTIVA', 'data_prevista' => now()->toDateString(),
            'descricao' => 'Avaria fictícia', 'tarefas' => $tarefas], $this->h)->assertCreated()->json('dados.id');
    }

    #[Test]
    public function ordem_fica_pendente_ate_todas_as_tarefas_obrigatorias_estarem_feitas(): void
    {
        $this->ids['gerador'] = $this->bem(['descricao' => 'Gerador fictício', 'valor_aquisicao' => 1000, 'data_aquisicao' => '2026-01-01']);
        $o = $this->ordem(['Desligar quadro', ['texto' => 'Medir isolamento', 'obrigatoria' => true], ['texto' => 'Limpar sala', 'obrigatoria' => false], '']);
        $d = $this->getJson("/api/ativos/manutencao/ordens/{$o}", $this->h)->assertOk()->assertJsonPath('dados.progresso.total', 3)
            ->assertJsonPath('dados.progresso.situacao', 'POR_INICIAR')->assertJsonPath('dados.tarefas.2.obrigatoria', false)->json('dados');
        [$t1, $t2, $t3] = array_column($d['tarefas'], 'id');
        $this->assertSame('PLANEADA', $d['estado']);

        // a primeira tarefa feita põe a ordem em curso, com quem/quando e histórico
        $r = $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t1}/estado", ['feito' => true], $this->h)->assertOk()
            ->assertJsonPath('dados.estado', 'EM_CURSO')->assertJsonPath('dados.progresso.feitas', 1)->assertJsonPath('dados.progresso.situacao', 'EM_CURSO')->json('dados');
        $this->assertNotNull($r['tarefas'][0]['feito_por']);
        $this->assertNotNull($r['tarefas'][0]['feito_em']);
        $this->assertSame('TAREFA_CONCLUIDA', end($r['historico'])['accao']);

        // com uma obrigatória por fazer, não conclui (nem forçando)
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/concluir", ['resolucao' => 'Feito', 'forcar' => true], $this->h)->assertStatus(422)
            ->assertJsonPath('codigo', 'TAREFAS_PENDENTES')->assertJsonPath('erros.tarefas', ['Medir isolamento']);
        $this->getJson("/api/ativos/manutencao/ordens/{$o}", $this->h)->assertJsonPath('dados.estado', 'EM_CURSO');

        // acrescentar e retirar tarefas; uma tarefa feita não se retira
        $t4 = collect($this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas", ['texto' => 'Verificar ligações', 'obrigatoria' => true], $this->h)->assertCreated()
            ->assertJsonPath('dados.progresso.total', 4)->json('dados.tarefas'))->last()['id'];
        $this->deleteJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t1}", [], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'TAREFA_FEITA');
        $this->deleteJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t4}", [], $this->h)->assertOk()->assertJsonPath('dados.progresso.total', 3);
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/zzz/estado", ['feito' => true], $this->h)->assertStatus(404);

        // todas as obrigatórias feitas: pronta; a opcional por fazer fica registada na conclusão
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t2}/estado", ['feito' => true], $this->h)->assertOk()->assertJsonPath('dados.progresso.situacao', 'PRONTA');
        $c = $this->postJson("/api/ativos/manutencao/ordens/{$o}/concluir", ['resolucao' => 'Isolamento medido'], $this->h)->assertOk()
            ->assertJsonPath('dados.estado', 'CONCLUIDA')->json('dados');
        $this->assertSame(['ORDEM_CONCLUIDA', '1 tarefa(s) opcional(is) por fazer'], [end($c['historico'])['accao'], end($c['historico'])['motivo']]);
        $this->assertSame(1, $this->naEmpresa(fn () => RegistoManutencaoAtivo::query()->where('ordem_manutencao_id', $o)->count()));

        // concluída: marcar já não; reabrir uma tarefa exige motivo e reabre a ordem (sai do histórico do activo)
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t3}/estado", ['feito' => true], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'ORDEM_FECHADA');
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t2}/estado", ['feito' => false], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'MOTIVO_EM_FALTA');
        $re = $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t2}/estado", ['feito' => false, 'motivo' => 'Valor fora do limite'], $this->h)->assertOk()
            ->assertJsonPath('dados.estado', 'EM_CURSO')->assertJsonPath('dados.data_fim', null)->assertJsonPath('dados.progresso.situacao', 'EM_CURSO')->json('dados');
        $this->assertSame(['ORDEM_REABERTA', 'TAREFA_REABERTA'], array_column(array_slice($re['historico'], -2), 'accao'));
        $this->assertNotNull($re['reaberta_em']);
        $this->assertSame(0, $this->naEmpresa(fn () => RegistoManutencaoAtivo::query()->where('ordem_manutencao_id', $o)->count()));
    }

    #[Test]
    public function reabrir_repoe_o_plano_e_permissoes_e_isolamento(): void
    {
        $this->ids['gerador'] = $this->bem(['descricao' => 'Gerador fictício', 'valor_aquisicao' => 1000, 'data_aquisicao' => '2026-01-01']);
        $proxima = now()->addDays(3)->toDateString();
        $plano = $this->postJson('/api/ativos/manutencao/planos', ['nome' => 'Revisão', 'ativo_imobilizado_id' => $this->ids['gerador'], 'tipo' => 'PREVENTIVA',
            'periodicidade' => ['modo' => 'TEMPO', 'intervalo' => 3, 'unidade' => 'MESES'], 'proxima_data' => $proxima, 'tarefas' => ['Trocar óleo', 'Limpar filtro']],
            $this->h)->assertCreated()->json('dados.id');
        $o = $this->ordem([], $plano);
        // compatível com o pedido antigo (lista de booleanos); a ordem lista o progresso
        $this->putJson("/api/ativos/manutencao/ordens/{$o}/tarefas", ['feitas' => [true, true]], $this->h)->assertOk()->assertJsonPath('dados.tarefas.1.feito', true);
        $this->getJson('/api/ativos/manutencao/ordens', $this->h)->assertJsonPath('dados.0.progresso.feitas', 2)->assertJsonPath('dados.0.progresso.situacao', 'PRONTA');
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/concluir", ['resolucao' => 'Revisão feita'], $this->h)->assertOk();
        $p = collect($this->getJson('/api/ativos/manutencao/planos', $this->h)->json('dados'))->firstWhere('id', $plano);
        $this->assertSame(1, $p['execucoes']);
        $t = $this->getJson("/api/ativos/manutencao/ordens/{$o}", $this->h)->json('dados.tarefas.0.id');

        // permissões e isolamento antes de reabrir
        $ver = $this->sessao(['activos_manutencao_view']);
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t}/estado", ['feito' => false, 'motivo' => 'x'], $ver)->assertForbidden();
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas", ['texto' => 'x'], $ver)->assertForbidden();
        $outra = $this->criarEmpresa(['nif' => '5417300032']);
        $h2 = $this->sessao(['activos_manutencao_view', 'activos_manut_gerir'], $outra);
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t}/estado", ['feito' => false, 'motivo' => 'x'], $h2)->assertNotFound();
        $this->deleteJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t}", [], $h2)->assertNotFound();

        // reabrir: o plano volta ao estado anterior à conclusão
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/tarefas/{$t}/estado", ['feito' => false, 'motivo' => 'Fuga de óleo'], $this->h)->assertOk()
            ->assertJsonPath('dados.estado', 'EM_CURSO');
        $p = collect($this->getJson('/api/ativos/manutencao/planos', $this->h)->json('dados'))->firstWhere('id', $plano);
        $this->assertSame([0, null, $proxima], [$p['execucoes'], $p['ultima_data'], substr((string) $p['proxima_data'], 0, 10)]);
        // e volta a concluir quando tudo estiver feito
        $this->postJson("/api/ativos/manutencao/ordens/{$o}/concluir", ['resolucao' => 'Fuga corrigida', 'tarefas' => [true, true]], $this->h)->assertOk()
            ->assertJsonPath('dados.estado', 'CONCLUIDA');
        $this->assertSame(1, collect($this->getJson('/api/ativos/manutencao/planos', $this->h)->json('dados'))->firstWhere('id', $plano)['execucoes']);
    }
}
