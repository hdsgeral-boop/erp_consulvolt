<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\CriterioAvaliacaoRH;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\ParticipanteAvaliacao360;
use App\Models\RespostaAvaliacao360;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Services\RH\ServicoAvaliacao;
use App\Services\RH\ServicoAvaliacao360;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Avaliação de desempenho e 360º (RH parte 3b, ADR-041).
 * Direcção (responsável: Director) › Departamento (responsável: Chefe) ‹ Ana, Bruno, Carla, Duarte.
 */
final class AvaliacaoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s = [];

    private string $ano;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ano = now()->format('Y');
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22])->id;
            foreach (['ana' => 'Ana', 'bruno' => 'Bruno', 'carla' => 'Carla', 'duarte' => 'Duarte', 'chefe' => 'Chefe', 'director' => 'Director'] as $k => $n) {
                $this->ids[$k] = $mk($n);
            }
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'colaborador_responsavel_id' => $this->ids['director'], 'ativo' => true]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'unidade_organica_pai_id' => $dir->id, 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true]);
            Colaborador::query()->whereKey([$this->ids['ana'], $this->ids['bruno'], $this->ids['carla'], $this->ids['duarte']])->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->whereKey([$this->ids['chefe'], $this->ids['director']])->update(['unidade_organica_id' => $dir->id]);
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $this->ids['bonus'] = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Prémio de desempenho', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            ContratoTrabalho::create(['colaborador_id' => $this->ids['ana'], 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2020-01-01',
                'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 300000]]]);
        });
        foreach (['ana', 'bruno', 'carla', 'duarte', 'chefe', 'director'] as $k) {
            $this->s[$k] = $this->sessao(['rh_portal_usar'], $this->ids[$k]);
        }
        $rh = ['rh_avaliacao_view', 'rh_avaliacao_edit', 'rh_avaliacao_itens', 'rh_avaliacao_config_view', 'rh_avaliacao_ciclo_view', 'rh_aval_config', 'rh_aval_abrir',
            'rh_aval_parecer', 'rh_aval_bonus_calcular', 'rh_aval_bonus_aprovar', 'rh_aval_bonus_lancar', 'calcular_folha', 'calcular_view', 'rh_portal_aprovar'];
        $this->s['rh'] = $this->sessao($rh);
        $this->s['rh2'] = $this->sessao($rh);
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function formulas_do_legado(): void
    {
        $r = ServicoAvaliacao::calcular([['nota' => 4, 'peso' => 1], ['nota' => 5, 'peso' => 1]], [['descricao' => 'Vendas', 'peso' => 1, 'resultado' => 80]]);
        $this->assertSame([4.5, 4.2, 4.41, 'Muito Bom'], [$r['pontuacao_criterios'], $r['pontuacao_objetivos'], $r['pontuacao'], $r['classificacao']]);   // 4,5 × 0,7 + 4,2 × 0,3
        $this->assertSame(125.0, ServicoAvaliacao::resultadoQuantitativo(8, 10, 'MENOR'));
        $this->assertSame(200.0, ServicoAvaliacao::resultadoQuantitativo(500, 100, 'MAIOR'));
        $this->assertSame(75.0, ServicoAvaliacao::resultadoQualitativo(4));
        $this->assertSame(['Excelente', 'Bom', 'Insuficiente'], [ServicoAvaliacao::classificar(4.5), ServicoAvaliacao::classificar(2.5), ServicoAvaliacao::classificar(1.49)]);
        $this->assertSame(4.0, ServicoAvaliacao::calcular([['nota' => 4, 'peso' => 1]], [])['pontuacao']);   // só critérios
    }

    #[Test]
    public function ciclo_360_contestacao_bonificacao_e_ascendente(): void
    {
        $rh = $this->s['rh'];
        $this->getJson('/api/rh/avaliacao/itens', $rh)->assertOk()->assertJsonCount(8, 'dados');   // critérios padrão na 1.ª utilização
        $this->postJson('/api/rh/avaliacao/itens', ['ambito' => 'COMUM', 'tipo' => 'OBJECTIVO', 'nome' => 'Vendas', 'natureza' => 'QUANTITATIVO', 'meta' => 100], $rh)->assertCreated();
        $this->postJson('/api/rh/avaliacao/itens', ['ambito' => 'COMUM', 'tipo' => 'CRITERIO', 'nome' => 'iniciativa'], $rh)->assertStatus(422)->assertJsonPath('codigo', 'ITEM_DUPLICADO');

        $mes = now()->format('m/Y');
        $this->postJson('/api/rh/avaliacao/ciclos', ['ano' => $this->ano, 'periodo' => 'ANUAL', 'pesos' => ['CHEFIA' => 50, 'AUTO' => 10, 'PARES' => 20, 'SUBORDINADOS' => 30]], $rh)
            ->assertStatus(422)->assertJsonPath('codigo', 'PESOS_INVALIDOS');
        $c = $this->postJson('/api/rh/avaliacao/ciclos', ['ano' => $this->ano, 'periodo' => 'ANUAL', 'prazos' => ['respostas_ate' => now()->addMonth()->toDateString()],
            'bonificacao' => ['metodo' => 'PERCENTAGEM', 'infotipo_salarial_id' => $this->ids['bonus'], 'mes_lancamento' => $mes]], $rh)->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/avaliacao/ciclos/{$c}/abrir", [], $rh)->assertOk()->assertJsonPath('dados.estado', 'ABERTO');
        $outro = $this->postJson('/api/rh/avaliacao/ciclos', ['ano' => $this->ano, 'periodo' => 'S1'], $rh)->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/avaliacao/ciclos/{$outro}/abrir", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'CICLO_ABERTO_EXISTENTE');

        // tarefas do Bruno: 3 pares (Ana, Carla, Duarte) e a chefia como subordinado
        $t = collect($this->getJson('/api/rh/avaliacao/360/tarefas', $this->s['bruno'])->assertOk()->json('dados'));
        $this->assertSame(['PARES' => 3, 'SUBORDINADOS' => 1], $t->countBy('grupo')->all());
        $criterios = array_keys(ServicoAvaliacao::CRITERIOS_PADRAO);
        $notas = fn (int $n) => array_map(fn ($k) => ['chave' => $k, 'nota' => $n], $criterios);
        foreach (['bruno', 'carla', 'duarte'] as $k) {
            $this->postJson('/api/rh/avaliacao/360/respostas', ['colaborador_avaliado_id' => $this->ids['ana'], 'notas' => $notas(4), 'comentario' => "Comentário de {$k}"], $this->s[$k])->assertOk();
        }
        $this->postJson('/api/rh/avaliacao/360/respostas', ['colaborador_avaliado_id' => $this->ids['ana'], 'notas' => $notas(4)], $this->s['bruno'])
            ->assertStatus(422)->assertJsonPath('codigo', 'TAREFA_INVALIDA');
        // anonimato: 3 participações e 3 respostas, sem nenhuma coluna que as ligue
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $this->assertSame(3, ParticipanteAvaliacao360::query()->where('colaborador_avaliado_id', $this->ids['ana'])->count());
            $this->assertArrayNotHasKey('colaborador_avaliador_id', RespostaAvaliacao360::query()->first()->getAttributes());
        });
        $this->putJson('/api/rh/avaliacao/autoavaliacao', ['ano' => $this->ano, 'periodo' => 'ANUAL', 'criterios' => $notas(5), 'realizacoes' => 'Cumpri os objectivos do ano.',
            'submeter' => true], $this->s['ana'])->assertOk()->assertJsonPath('dados.estado', 'SUBMETIDA');

        // avaliação pela chefia directa (sem permissão do RH); ninguém se avalia a si próprio
        $aval = ['colaborador_id' => $this->ids['ana'], 'ano' => $this->ano, 'periodo' => 'ANUAL', 'criterios' => $notas(4),
            'objetivos' => [['chave' => app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => CriterioAvaliacaoRH::query()->where('nome', 'Vendas')->value('chave')), 'atingido' => 80]],
            'avaliador' => 'Chefe', 'data_avaliacao' => now()->toDateString(), 'concluir' => true];
        $this->postJson('/api/rh/avaliacao/avaliacoes', $aval, $this->s['ana'])->assertStatus(403)->assertJsonPath('codigo', 'AUTO_AVALIACAO');
        $this->postJson('/api/rh/avaliacao/avaliacoes', $aval, $this->s['bruno'])->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO');
        $a = $this->postJson('/api/rh/avaliacao/avaliacoes', $aval, $this->s['chefe'])->assertOk()->assertJsonPath('dados.estado', 'CONCLUIDA')
            ->assertJsonPath('dados.pontuacao', '4.06')->assertJsonPath('dados.classificacao', 'Muito Bom')->json('dados.id');   // 4 × 0,7 + 4,2 × 0,3

        // resultados anónimos só depois do prazo das respostas
        $this->getJson("/api/rh/avaliacao/avaliacoes/{$a}/resultado-360", $this->s['ana'])->assertOk()->assertJsonPath('dados.liberado', false)->assertJsonMissingPath('dados.componentes');
        $this->putJson("/api/rh/avaliacao/ciclos/{$c}", ['prazos' => ['respostas_ate' => now()->subDay()->toDateString()]], $rh)->assertOk();
        // (4,06 × 50 + 5 × 10 + 4 × 20) / 80 = 4,16 (sem subordinados: pesos disponíveis 80)
        $this->getJson("/api/rh/avaliacao/avaliacoes/{$a}/resultado-360", $this->s['ana'])->assertJsonPath('dados.liberado', true)->assertJsonPath('dados.nota', 4.16)
            ->assertJsonPath('dados.classificacao', 'Muito Bom')->assertJsonCount(3, 'dados.comentarios');

        // conhecimento, contestação (decisor = chefia da chefia = Director), parecer e decisão
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/conhecimento", ['comentario' => 'Tomei conhecimento.'], $this->s['ana'])->assertOk();
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/reabrir", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'AVALIACAO_CONHECIDA');
        $this->deleteJson("/api/rh/avaliacao/avaliacoes/{$a}", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/contestar", ['fundamentacao' => 'Discordo.'], $this->s['ana'])->assertStatus(422)->assertJsonPath('codigo', 'FUNDAMENTACAO_CURTA');
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/contestar", ['fundamentacao' => 'Os resultados de vendas não consideram o trimestre em que substituí a equipa.'], $this->s['ana'])
            ->assertOk()->assertJsonPath('dados.contestacao.decisor_colaborador_id', $this->ids['director']);
        $dec = ['resultado' => 'ALTERADA', 'nota' => 4.6, 'justificacao' => 'Confirmada a substituição da equipa no 3.º trimestre.'];
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/decidir-contestacao", $dec, $this->s['director'])->assertStatus(422)->assertJsonPath('codigo', 'PARECER_EM_FALTA');
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/parecer", ['texto' => 'Os registos confirmam a substituição.'], $rh)->assertOk();
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/decidir-contestacao", $dec, $this->s['chefe'])->assertStatus(403)->assertJsonPath('codigo', 'CONFLITO_INTERESSES');
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/decidir-contestacao", $dec, $rh)->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO');
        $this->postJson("/api/rh/avaliacao/avaliacoes/{$a}/decidir-contestacao", $dec, $this->s['director'])->assertOk()
            ->assertJsonPath('dados.contestacao.decisao.classificacao', 'Excelente');
        $this->getJson("/api/rh/avaliacao/avaliacoes?ano={$this->ano}&periodo=ANUAL", $rh)->assertJsonPath('dados.0.fase', 'FINAL')->assertJsonPath('dados.0.nota_final', 4.6);

        // bonificação: Excelente → 15 % × 300 000 = 45 000; quem calcula não aprova; lançar no processamento do mês
        $this->postJson("/api/rh/avaliacao/ciclos/{$c}/bonificacoes/calcular", [], $rh)->assertOk()->assertJsonPath('dados.propostas', 1)->assertJsonPath('dados.total', 45000.0);
        $this->postJson("/api/rh/avaliacao/ciclos/{$c}/bonificacoes/aprovar", [], $rh)->assertStatus(403)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $this->postJson("/api/rh/avaliacao/ciclos/{$c}/bonificacoes/aprovar", [], $this->s['rh2'])->assertOk()->assertJsonPath('dados.aprovadas', 1);
        $this->postJson("/api/rh/avaliacao/ciclos/{$c}/bonificacoes/lancar", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INEXISTENTE');
        $sal = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => $mes], $rh)->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/avaliacao/ciclos/{$c}/bonificacoes/lancar", [], $rh)->assertOk()->assertJsonPath('dados.lancadas', 1);
        $linha = fn () => app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $sal)
            ->where('infotipo_salarial_id', $this->ids['bonus'])->first());
        $this->assertSame(['45000.00', 'BONIFICACAO'], [$linha()->valor, $linha()->origem]);
        $this->postJson("/api/rh/avaliacao/ciclos/{$c}/bonificacoes/calcular", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'BONIFICACOES_BLOQUEADAS');
        $this->putJson("/api/rh/avaliacao/ciclos/{$c}", ['bonificacao' => ['metodo' => 'FIXO']], $rh)->assertStatus(422)->assertJsonPath('codigo', 'BONIFICACAO_BLOQUEADA');
        $b = $this->getJson("/api/rh/avaliacao/ciclos/{$c}/bonificacoes", $rh)->json('dados.0.id');
        $this->postJson("/api/rh/avaliacao/bonificacoes/{$b}/anular", [], $rh)->assertOk()->assertJsonPath('dados.estado', 'PROPOSTA');
        $this->assertNull($linha());

        // acompanhamento: registado pela chefia, confirmado pelo colaborador, depois bloqueado
        $fb = ['ciclo_avaliacao_id' => $c, 'colaborador_id' => $this->ids['ana'], 'periodo_referencia' => "{$this->ano}-T1", 'data' => now()->toDateString(), 'positivos' => 'Boa evolução.'];
        $f = $this->postJson('/api/rh/avaliacao/feedbacks', $fb, $this->s['chefe'])->assertOk()->json('dados.id');
        $this->postJson('/api/rh/avaliacao/feedbacks', $fb, $this->s['bruno'])->assertStatus(403);
        $this->postJson("/api/rh/avaliacao/feedbacks/{$f}/confirmar", [], $this->s['ana'])->assertOk();
        $this->postJson('/api/rh/avaliacao/feedbacks', $fb, $this->s['chefe'])->assertStatus(422)->assertJsonPath('codigo', 'FEEDBACK_CONFIRMADO');

        // avaliação ascendente da chefia (mínimo de anonimato do ciclo = 3)
        $asc = ['ano' => $this->ano, 'periodo' => 'ANUAL', 'respostas' => array_map(fn ($k) => ['chave' => $k, 'nota' => 4], array_keys(ServicoAvaliacao360::LIDERANCA))];
        foreach (['ana', 'bruno'] as $k) {
            $this->postJson('/api/rh/avaliacao/ascendente', $asc, $this->s[$k])->assertOk();
        }
        $this->getJson("/api/rh/avaliacao/ascendente/{$this->ids['chefe']}?ano={$this->ano}&periodo=ANUAL", $this->s['chefe'])->assertJsonPath('dados.liberado', false);
        $this->postJson('/api/rh/avaliacao/ascendente', $asc, $this->s['carla'])->assertOk();
        $this->postJson('/api/rh/avaliacao/ascendente', $asc, $this->s['carla'])->assertStatus(422)->assertJsonPath('codigo', 'JA_RESPONDIDO');
        $this->getJson("/api/rh/avaliacao/ascendente/{$this->ids['chefe']}?ano={$this->ano}&periodo=ANUAL", $this->s['chefe'])->assertJsonPath('dados.liberado', true)
            ->assertJsonPath('dados.media', 4.0);
        $this->getJson("/api/rh/avaliacao/ascendente/{$this->ids['chefe']}?ano={$this->ano}&periodo=ANUAL", $this->s['bruno'])->assertStatus(403);
    }
}
