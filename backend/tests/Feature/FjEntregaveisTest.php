<?php

namespace Tests\Feature;

use App\Exceptions\ErroNegocio;
use App\Models\CargoFuncao;
use App\Models\Colaborador;
use App\Models\ElegibilidadeProdutividadeRH;
use App\Models\Empresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\PeriodoProdutividadeRH;
use App\Models\TarefaUnidadeOrganica;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Services\Estrutura\ServicoEntregaveis;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase J (ADR-078) — tarefas das unidades e entregáveis: regras de prazo e período servido, CRUD, execuções, validação pela
 * cadeia de responsáveis (que sobe a árvore), impedimentos, atrasos, cópia e criação do item de produtividade.
 * Árvore: Direcção (responsável: Director) › Departamento (responsável: Chefe) ‹ Ana e Rui.
 */
final class FjEntregaveisTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    /** @var array<string, array<string, mixed>> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa FJ', 'nif' => '5000888801']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => '2020-01-06'])->id;
            $this->ids = ['ana' => $mk('Ana'), 'rui' => $mk('Rui'), 'chefe' => $mk('Chefe'), 'director' => $mk('Director')];
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'colaborador_responsavel_id' => $this->ids['director'], 'ativo' => true, 'ordem' => 10]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true, 'ordem' => 10]);
            $outra = UnidadeOrganica::create(['nome' => 'Outra', 'codigo' => 'OUT', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'ativo' => true, 'ordem' => 20]);
            Colaborador::query()->whereKey([$this->ids['ana'], $this->ids['rui']])->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->whereKey($this->ids['chefe'])->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->whereKey($this->ids['director'])->update(['unidade_organica_id' => $dir->id]);
            $prod = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de produtividade', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $item = ItemProdutividadeRH::create(['codigo' => 'REL', 'descricao' => 'Relatórios', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'preco_unitario' => 1000, 'infotipo_salarial_id' => $prod, 'ativo' => true])->id;
            $itemH = ItemProdutividadeRH::create(['codigo' => 'HRS', 'descricao' => 'Horas', 'metrica' => 'HORAS', 'unidade' => 'h', 'preco_unitario' => 500, 'infotipo_salarial_id' => $prod, 'ativo' => true])->id;
            $this->ids += ['dir' => $dir->id, 'dep' => $dep->id, 'outra' => $outra->id, 'prod' => $prod, 'item' => $item, 'item_horas' => $itemH];
        });
        $gestor = ['est_estrutura_view', 'est_editar', 'est_eliminar', 'rh_prod_config', 'rh_produtividade_view', 'rh_prod_registar', 'colaboradores_view'];
        $this->s = ['gestor' => $this->sessao($gestor), 'ana' => $this->sessao(['rh_portal_usar'], $this->ids['ana']), 'rui' => $this->sessao(['rh_portal_usar'], $this->ids['rui']),
            'chefe' => $this->sessao(['rh_portal_usar', 'est_estrutura_view'], $this->ids['chefe']), 'director' => $this->sessao(['rh_portal_usar', 'est_estrutura_view'], $this->ids['director']),
            'validador' => $this->sessao(['est_estrutura_view', 'est_validar_entregas']), 'leitor' => $this->sessao(['est_organigrama_view'])];
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function tarefa(array $extra = []): int
    {
        return $this->postJson('/api/rh/estrutura/tarefas', $extra + ['unidade_organica_id' => $this->ids['dep'], 'nome' => 'Relatório mensal', 'codigo' => 'T-REL', 'periodicidade' => 'MENSAL'], $this->s['gestor'])
            ->assertCreated()->json('dados.id');
    }

    private function entregavel(int $tarefa, array $extra = []): int
    {
        return $this->postJson("/api/rh/estrutura/tarefas/{$tarefa}/entregaveis", $extra + ['nome' => 'Relatório', 'unidade' => 'unidades', 'meta' => 2, 'prazo_dia' => 15, 'prazo_mes' => 2], $this->s['gestor'])
            ->assertCreated()->json('dados.id');
    }

    #[Test]
    public function prazo_periodo_servido_limite_e_unidades_de_medida(): void
    {
        $e = new EntregavelTarefaUnidade(['prazo_dia' => 15, 'prazo_mes' => 2]);
        // prazo «dia 15 do mês seguinte»: uma entrega a 2026-02-10 serve Janeiro de 2026, com limite a 15/02
        $this->assertSame(['inicio' => '2026-01-01', 'fim' => '2026-01-31'], ServicoEntregaveis::periodoServido($e, 'MENSAL', '2026-02-10'));
        $this->assertSame('2026-02-15', ServicoEntregaveis::limiteDaEntrega($e, 'MENSAL', '2026-02-10'));
        $this->assertFalse(ServicoEntregaveis::foraDePrazo($e, 'MENSAL', '2026-02-15'));
        $this->assertTrue(ServicoEntregaveis::foraDePrazo($e, 'MENSAL', '2026-02-16'));
        // trimestral: entrega em Abril com prazo no mês 4 (seguinte ao período) serve o 1.º trimestre
        $t = new EntregavelTarefaUnidade(['prazo_dia' => 31, 'prazo_mes' => 4]);
        $this->assertSame(['inicio' => '2026-01-01', 'fim' => '2026-03-31'], ServicoEntregaveis::periodoServido($t, 'TRIMESTRAL', '2026-04-20'));
        $this->assertSame('2026-04-30', ServicoEntregaveis::limiteDaEntrega($t, 'TRIMESTRAL', '2026-04-20'));   // dia 31 num mês curto → último dia
        // anual: prazo no 13.º mês (Janeiro seguinte); recuo passa o ano
        $a = new EntregavelTarefaUnidade(['prazo_dia' => 20, 'prazo_mes' => 13]);
        $this->assertSame(['inicio' => '2025-01-01', 'fim' => '2025-12-31'], ServicoEntregaveis::periodoServido($a, 'ANUAL', '2026-01-05'));
        $this->assertSame('2026-01-20', ServicoEntregaveis::limiteDaEntrega($a, 'ANUAL', '2026-01-05'));
        // sem prazo não há limite
        $this->assertNull(ServicoEntregaveis::limiteDaEntrega(new EntregavelTarefaUnidade(['prazo_mes' => 1]), 'MENSAL', '2026-02-10'));
        $this->assertSame('dia 15 do mês seguinte', ServicoEntregaveis::prazoTexto($e, 'MENSAL'));

        $this->assertSame(ServicoEntregaveis::chaveUnidade('m²'), ServicoEntregaveis::chaveUnidade('M2'));
        $this->assertSame('unidades', ServicoEntregaveis::chaveUnidade('Un'));
        $this->assertSame(ServicoEntregaveis::chaveUnidade('Horas'), ServicoEntregaveis::chaveUnidade('h'));
        $this->assertSame(ServicoEntregaveis::chaveUnidade('ton'), ServicoEntregaveis::chaveUnidade('T'));
        $this->assertSame('%', ServicoEntregaveis::chaveUnidade('pct'));
        $this->assertSame('relatorios', ServicoEntregaveis::chaveUnidade('Relatórios'));

        $this->assertSame('https://drive.google.com/x', ServicoEntregaveis::normalizarUrl('drive.google.com/x'));
        $this->assertNull(ServicoEntregaveis::normalizarUrl('  '));
        foreach (['javascript:alert(1)', 'ftp://x.y/z', 'https://user:pass@x.y/z'] as $mau) {
            try {
                ServicoEntregaveis::normalizarUrl($mau);
                $this->fail("«{$mau}» devia ser recusado.");
            } catch (ErroNegocio $erro) {
                $this->assertSame('URL_INVALIDO', $erro->codigo);
            }
        }
    }

    #[Test]
    public function crud_de_tarefas_e_entregaveis_com_regras(): void
    {
        $g = $this->s['gestor'];
        $this->getJson("/api/rh/estrutura/unidades/{$this->ids['dep']}/tarefas", $this->s['leitor'])->assertOk();   // est_organigrama_view lê
        $this->postJson('/api/rh/estrutura/tarefas', ['unidade_organica_id' => $this->ids['dep'], 'nome' => 'X'], $this->s['leitor'])->assertForbidden();
        $this->postJson('/api/rh/estrutura/tarefas', ['unidade_organica_id' => 999999, 'nome' => 'X'], $g)->assertStatus(422)->assertJsonPath('codigo', 'UNIDADE_INVALIDA');
        $t = $this->tarefa(['url_instrucoes' => 'docs.empresa.ao/relatorio']);
        $this->assertSame('https://docs.empresa.ao/relatorio', $this->em(fn () => TarefaUnidadeOrganica::query()->find($t)->url_instrucoes));
        $this->postJson('/api/rh/estrutura/tarefas', ['unidade_organica_id' => $this->ids['dep'], 'nome' => 'Outra', 'codigo' => 't-rel'], $g)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');
        $posto = $this->postJson('/api/rh/estrutura/postos', ['unidade_organica_id' => $this->ids['outra'], 'cargo_funcao_id' => $this->em(fn () => CargoFuncao::create(['nome' => 'Técnico'])->id)], $g)->assertCreated()->json('dados.id');
        $this->putJson("/api/rh/estrutura/tarefas/{$t}", ['nome' => 'Relatório mensal', 'posto_trabalho_id' => $posto], $g)->assertStatus(422)->assertJsonPath('codigo', 'POSTO_FORA_DA_UNIDADE');

        // entregável: unidade de medida obrigatória; avaliação exige meta; alerta exige prazo; prazo_mes dentro da periodicidade; item compatível
        $this->postJson("/api/rh/estrutura/tarefas/{$t}/entregaveis", ['nome' => 'Sem unidade'], $g)->assertStatus(422)->assertJsonPath('codigo', 'UNIDADE_MEDIDA_EM_FALTA');
        $this->postJson("/api/rh/estrutura/tarefas/{$t}/entregaveis", ['nome' => 'X', 'unidade' => 'un', 'avaliacao' => true], $g)->assertStatus(422)->assertJsonPath('codigo', 'META_EM_FALTA');
        $this->postJson("/api/rh/estrutura/tarefas/{$t}/entregaveis", ['nome' => 'X', 'unidade' => 'un', 'alerta' => true], $g)->assertStatus(422)->assertJsonPath('codigo', 'PRAZO_EM_FALTA');
        $this->postJson("/api/rh/estrutura/tarefas/{$t}/entregaveis", ['nome' => 'X', 'unidade' => 'un', 'prazo_dia' => 1, 'prazo_mes' => 3], $g)->assertStatus(422)->assertJsonPath('codigo', 'PRAZO_MES_INVALIDO');
        $this->postJson("/api/rh/estrutura/tarefas/{$t}/entregaveis", ['nome' => 'X', 'unidade' => 'un', 'item_produtividade_id' => $this->ids['item_horas']], $g)->assertStatus(422)->assertJsonPath('codigo', 'ITEM_INCOMPATIVEL');
        $this->postJson("/api/rh/estrutura/tarefas/{$t}/entregaveis", ['nome' => 'X', 'unidade' => 'peças', 'item_produtividade_id' => $this->ids['item']], $g)->assertStatus(422)->assertJsonPath('codigo', 'ITEM_INCOMPATIVEL');
        $this->postJson("/api/rh/estrutura/tarefas/{$t}/entregaveis", ['nome' => 'X', 'unidade' => 'un', 'origem_dados' => 'CRM'], $g)->assertStatus(422)->assertJsonPath('codigo', 'CRM_FILTRO_EM_FALTA');
        $e = $this->entregavel($t, ['item_produtividade_id' => $this->ids['item'], 'colaborador_id' => $this->ids['ana'], 'alerta' => true, 'multa_valor' => 5000]);   // «unidades» = «un»
        $lista = $this->getJson("/api/rh/estrutura/unidades/{$this->ids['dep']}/tarefas", $g)->assertOk()->json('dados');
        $this->assertSame('REL', $lista['tarefas'][0]['entregaveis'][0]['item']['codigo']);
        $this->assertSame('Ana', $lista['tarefas'][0]['entregaveis'][0]['dono']['nome']);
        $this->assertSame('DENTRO_DO_PRAZO', $lista['tarefas'][0]['entregaveis'][0]['resumo']['estado']);   // 10/03: serve Fevereiro, limite 15/03
        $this->assertSame('2026-02-01', $lista['tarefas'][0]['entregaveis'][0]['resumo']['periodo_de']);

        // cópia: sem dono, nome único no destino, copiado_de_id
        $t2 = $this->postJson('/api/rh/estrutura/tarefas', ['unidade_organica_id' => $this->ids['outra'], 'nome' => 'Tarefa da outra', 'periodicidade' => 'TRIMESTRAL'], $g)->assertCreated()->json('dados.id');
        $copia = $this->postJson("/api/rh/estrutura/entregaveis/{$e}/copiar", ['tarefa_destino_id' => $t2], $g)->assertCreated()->json('dados');
        $this->assertSame(['Relatório (cópia)', null, $e, 2], [$copia['nome'], $copia['colaborador_id'], $copia['copiado_de_id'], $copia['prazo_mes']]);
        $this->postJson("/api/rh/estrutura/entregaveis/{$e}/copiar", ['tarefa_destino_id' => $t2], $g)->assertStatus(422)->assertJsonPath('codigo', 'NOME_DUPLICADO');

        // criar item a partir de um entregável sem item: código por omissão ENT-<id>, preço > 0 e rubrica obrigatórios
        $e2 = $this->postJson("/api/rh/estrutura/tarefas/{$t}/entregaveis", ['nome' => 'Actas de reunião', 'unidade' => 'actas', 'metrica' => 'TAREFAS'], $g)->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/estrutura/entregaveis/{$e2}/criar-item", ['preco_unitario' => 0, 'infotipo_salarial_id' => $this->ids['prod']], $g)->assertStatus(422);
        $item = $this->postJson("/api/rh/estrutura/entregaveis/{$e2}/criar-item", ['preco_unitario' => 250, 'infotipo_salarial_id' => $this->ids['prod']], $g)->assertCreated()->json('dados');
        $this->assertSame(["ENT-{$e2}", 'TAREFAS', 'actas', $item['item']['id']], [$item['item']['codigo'], $item['item']['metrica'], $item['item']['unidade'], $item['entregavel']['item_produtividade_id']]);
        $this->postJson("/api/rh/estrutura/entregaveis/{$e2}/criar-item", ['preco_unitario' => 250, 'infotipo_salarial_id' => $this->ids['prod']], $g)->assertStatus(422)->assertJsonPath('codigo', 'JA_LIGADO');
        $this->assertSame('ACTAS-DE-REUNI', $this->getJson('/api/rh/estrutura/entregaveis/sugerir-codigo?nome=Actas de reunião', $g)->assertOk()->json('dados.codigo'));
        $this->assertSame('REL-2', $this->getJson('/api/rh/estrutura/entregaveis/sugerir-codigo?nome=rel', $g)->json('dados.codigo'));

        // eliminar: com execuções é recusado (desactive em vez de apagar); sem execuções apaga entregáveis
        $this->postJson("/api/rh/estrutura/entregaveis/{$e}/execucoes", ['colaborador_id' => $this->ids['ana'], 'quantidade' => 1, 'data' => '2026-03-02'], $g)->assertCreated();
        $this->deleteJson("/api/rh/estrutura/entregaveis/{$e}", [], $g)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->deleteJson("/api/rh/estrutura/tarefas/{$t}", [], $g)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->deleteJson("/api/rh/estrutura/tarefas/{$t2}", [], $g)->assertOk();
        $this->assertNull($this->em(fn () => EntregavelTarefaUnidade::query()->find($copia['id'])));
    }

    #[Test]
    public function execucoes_e_validacao_pela_cadeia_que_sobe_a_arvore(): void
    {
        $g = $this->s['gestor'];
        $t = $this->tarefa();
        $e = $this->entregavel($t, ['item_produtividade_id' => $this->ids['item'], 'colaborador_id' => $this->ids['ana']]);
        $reg = fn (array $h, array $d) => $this->postJson("/api/rh/estrutura/entregaveis/{$e}/execucoes", $d + ['colaborador_id' => $this->ids['ana'], 'quantidade' => 1, 'data' => '2026-03-02'], $h);
        $reg($g, ['quantidade' => 0])->assertStatus(422);
        $reg($g, ['data' => '2026-03-11'])->assertStatus(422)->assertJsonPath('codigo', 'DATA_FUTURA');
        $reg($g, ['url_evidencia' => 'javascript:x'])->assertStatus(422)->assertJsonPath('codigo', 'URL_INVALIDO');
        $reg($this->s['rui'], [])->assertForbidden();   // o Rui não é o executor, nem responsável, nem edita a estrutura
        $x = $reg($this->s['ana'], ['url_evidencia' => 'drive.x/y'])->assertCreated()->assertJsonPath('dados.validacao', 'PENDENTE')->assertJsonPath('dados.unidade_organica_id', $this->ids['dep'])->json('dados');
        $this->assertSame($this->ids['ana'], $x['registado_por_colaborador_id']);

        $val = fn (array $h, int $id, string $decisao, ?string $motivo = null) => $this->postJson("/api/rh/estrutura/execucoes/{$id}/validar", ['decisao' => $decisao, 'motivo' => $motivo], $h);
        $val($this->s['ana'], $x['id'], 'VALIDADA')->assertForbidden();        // nunca a própria
        $val($this->s['rui'], $x['id'], 'VALIDADA')->assertForbidden();        // nem um colega sem papel
        $val($this->s['chefe'], $x['id'], 'DEVOLVIDA')->assertStatus(422)->assertJsonPath('codigo', 'MOTIVO_EM_FALTA');
        $val($this->s['chefe'], $x['id'], 'DEVOLVIDA', 'Falta a assinatura')->assertOk()->assertJsonPath('dados.validacao', 'DEVOLVIDA')->assertJsonPath('dados.validada_como', 'RESPONSAVEL');
        $this->postJson("/api/rh/estrutura/execucoes/{$x['id']}/reenviar", [], $this->s['rui'])->assertForbidden();
        $this->postJson("/api/rh/estrutura/execucoes/{$x['id']}/reenviar", [], $this->s['ana'])->assertOk()->assertJsonPath('dados.validacao', 'PENDENTE')->assertJsonPath('dados.devolucao_anterior.motivo', 'Falta a assinatura');
        // o director (responsável da unidade ACIMA) também valida: a cadeia sobe a árvore (correcção do legado parent_id)
        $val($this->s['director'], $x['id'], 'VALIDADA')->assertOk()->assertJsonPath('dados.validada_como', 'RESPONSAVEL');
        // validar com item torna a executora elegível (origem ENTREGAVEL)
        $this->assertSame('ENTREGAVEL', $this->em(fn () => ElegibilidadeProdutividadeRH::query()->where('colaborador_id', $this->ids['ana'])->where('item_produtividade_id', $this->ids['item'])->value('origem')));
        // repor por validar; depois quem tem est_validar_entregas valida como PERMISSAO
        $this->postJson("/api/rh/estrutura/execucoes/{$x['id']}/repor", [], $this->s['chefe'])->assertOk()->assertJsonPath('dados.validacao', 'PENDENTE')->assertJsonPath('dados.validada_por', null);
        $val($this->s['validador'], $x['id'], 'RECUSADA', 'Não corresponde')->assertOk()->assertJsonPath('dados.validada_como', 'PERMISSAO');
        $this->postJson("/api/rh/estrutura/execucoes/{$x['id']}/reenviar", [], $this->s['ana'])->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_INVALIDO');   // só devolvidas
        // editar repõe PENDENTE e guarda a recusa anterior
        $this->putJson("/api/rh/estrutura/execucoes/{$x['id']}", ['quantidade' => 2, 'data' => '2026-03-03'], $this->s['ana'])->assertOk()->assertJsonPath('dados.validacao', 'PENDENTE')->assertJsonPath('dados.devolucao_anterior.validacao', 'RECUSADA');
        $lista = $this->getJson("/api/rh/estrutura/entregaveis/{$e}/execucoes", $this->s['chefe'])->assertOk()->json('dados');
        $this->assertTrue($lista[0]['pode_validar']);
        $this->assertFalse($lista[0]['convertida']);
        // o responsável regista por outra pessoa, mas não valida o que ele próprio lançou
        $y = $reg($this->s['chefe'], ['colaborador_id' => $this->ids['rui']])->assertCreated()->json('dados');
        $val($this->s['chefe'], $y['id'], 'VALIDADA')->assertForbidden();
        $val($this->s['director'], $y['id'], 'VALIDADA')->assertOk();
        // convertida: não se edita, elimina nem revalida
        $this->em(fn () => ExecucaoEntregavel::query()->whereKey($y['id'])->update(['periodo_produtividade_id' => PeriodoProdutividadeRH::create(['mes' => '2026-03', 'data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31', 'estado' => 'ABERTO'])->id]));
        $this->deleteJson("/api/rh/estrutura/execucoes/{$y['id']}", [], $g)->assertStatus(422)->assertJsonPath('codigo', 'JA_CONVERTIDA');
        $this->postJson("/api/rh/estrutura/execucoes/{$y['id']}/repor", [], $this->s['director'])->assertStatus(422)->assertJsonPath('codigo', 'JA_CONVERTIDA');
        $this->deleteJson("/api/rh/estrutura/execucoes/{$x['id']}", [], $this->s['ana'])->assertOk();
    }

    #[Test]
    public function impedimentos_e_atrasos(): void
    {
        $g = $this->s['gestor'];
        $t = $this->tarefa();
        // dia 5 do mês seguinte: a 10/03 a obrigação de Fevereiro (limite 05/03) já passou sem meta → em atraso
        $e = $this->entregavel($t, ['prazo_dia' => 5, 'prazo_mes' => 2, 'alerta' => true, 'multa_valor' => 1500, 'multa_descricao' => 'Multa da AGT', 'colaborador_id' => $this->ids['ana']]);
        $semAlerta = $this->entregavel($t, ['nome' => 'Sem alerta', 'prazo_dia' => 5, 'prazo_mes' => 2, 'meta' => null]);
        $atrasos = $this->getJson("/api/rh/estrutura/entregaveis/atrasos?unidade_id={$this->ids['dir']}", $g)->assertOk()->json('dados');
        $this->assertCount(1, $atrasos);   // só com alerta por omissão; a unidade pedida (Direcção) inclui as descendentes
        $this->assertSame(['2026-02-01', '2026-02-28', '2026-03-05', 5, 2.0, 0.0, 1500.0, 'Ana'], [$atrasos[0]['periodo_de'], $atrasos[0]['periodo_ate'], $atrasos[0]['limite'], $atrasos[0]['dias_atraso'],
            $atrasos[0]['meta'], $atrasos[0]['realizado'], $atrasos[0]['multa_valor'], $atrasos[0]['dono']['nome']]);
        $this->assertCount(2, $this->getJson("/api/rh/estrutura/entregaveis/atrasos?unidade_id={$this->ids['dep']}&so_com_alerta=0", $g)->json('dados'));
        $this->assertCount(0, $this->getJson("/api/rh/estrutura/entregaveis/atrasos?unidade_id={$this->ids['outra']}&so_com_alerta=0", $g)->json('dados'));
        // entrega a tempo (dentro da janela, até ao limite) com a meta → deixa de estar em atraso; uma entrega sem meta basta
        $this->postJson("/api/rh/estrutura/entregaveis/{$e}/execucoes", ['colaborador_id' => $this->ids['ana'], 'quantidade' => 2, 'data' => '2026-03-04'], $g)->assertCreated();
        $this->postJson("/api/rh/estrutura/entregaveis/{$semAlerta}/execucoes", ['colaborador_id' => $this->ids['ana'], 'quantidade' => 1, 'data' => '2026-03-06'], $g)->assertCreated();   // tarde: não conta
        $atrasos = $this->getJson("/api/rh/estrutura/entregaveis/atrasos?unidade_id={$this->ids['dep']}&so_com_alerta=0", $g)->json('dados');
        $this->assertSame([$semAlerta], array_column($atrasos, 'entregavel_id'));
        $resumo = collect($this->getJson("/api/rh/estrutura/unidades/{$this->ids['dep']}/tarefas", $g)->json('dados.tarefas.0.entregaveis'))->keyBy('id');
        $this->assertSame(['CUMPRIDO', 2.0, 100.0], [$resumo[$e]['resumo']['estado'], $resumo[$e]['resumo']['realizado'], $resumo[$e]['resumo']['pct']]);
        $this->assertSame('CUMPRIDO', $resumo[$semAlerta]['resumo']['estado']);   // sem meta, a entrega (mesmo tardia) conta no realizado do período

        // impedimento: só dono ou responsável; um não recusado por período; decide o responsável da cadeia, nunca o autor
        $imp = fn (array $h, int $ent, array $d = []) => $this->postJson("/api/rh/estrutura/entregaveis/{$ent}/impedimentos", $d + ['periodo_de' => '2026-02-01', 'motivo' => 'Sistema da AGT em baixo'], $h);
        $imp($this->s['rui'], $semAlerta)->assertForbidden();
        $imp($g, $semAlerta)->assertForbidden();   // o gestor não está ligado a um colaborador
        $imp($this->s['chefe'], $semAlerta, ['motivo' => ''])->assertStatus(422);
        $i = $imp($this->s['chefe'], $semAlerta, ['periodo_de' => '2026-02-17', 'url_documento' => 'agt.ao/aviso'])->assertCreated()->assertJsonPath('dados.periodo_de', '2026-02-01')->assertJsonPath('dados.prazo', '2026-03-05')->json('dados');
        $imp($this->s['chefe'], $semAlerta)->assertStatus(422)->assertJsonPath('codigo', 'IMPEDIMENTO_DUPLICADO');
        $dec = fn (array $h, array $d) => $this->postJson("/api/rh/estrutura/impedimentos/{$i['id']}/decidir", $d, $h);
        $dec($this->s['chefe'], ['estado' => 'ACEITE'])->assertForbidden();   // o próprio autor não decide
        $dec($this->s['rui'], ['estado' => 'ACEITE'])->assertForbidden();
        $dec($this->s['director'], ['estado' => 'RECUSADO'])->assertStatus(422)->assertJsonPath('codigo', 'NOTA_EM_FALTA');
        $dec($this->s['director'], ['estado' => 'ACEITE'])->assertOk()->assertJsonPath('dados.estado', 'ACEITE');
        $dec($this->s['director'], ['estado' => 'ACEITE'])->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_INVALIDO');
        // aceite: o período fica justificado e sai dos atrasos
        $this->assertCount(0, $this->getJson("/api/rh/estrutura/entregaveis/atrasos?unidade_id={$this->ids['dep']}&so_com_alerta=0", $g)->json('dados'));
        $this->assertCount(1, $this->getJson("/api/rh/estrutura/entregaveis/{$semAlerta}/impedimentos", $g)->assertOk()->json('dados'));
        // quem tem est_validar_entregas também decide impedimentos
        $i2 = $imp($this->s['ana'], $e, ['periodo_de' => '2026-01-01'])->assertCreated()->json('dados');
        $this->postJson("/api/rh/estrutura/impedimentos/{$i2['id']}/decidir", ['estado' => 'RECUSADO', 'nota' => 'Havia alternativa'], $this->s['validador'])->assertOk()->assertJsonPath('dados.estado', 'RECUSADO');
        $imp($this->s['ana'], $e, ['periodo_de' => '2026-01-01'])->assertCreated();   // recusado não bloqueia um novo
    }

    #[Test]
    public function estrutura_isolada_por_empresa(): void
    {
        $t = $this->tarefa();
        $outra = $this->criarEmpresa(['nome' => 'Outra FJ', 'nif' => '5000888802']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'est_estrutura_view' => true, 'est_editar' => true])->id]);
        $u->empresas()->attach($outra->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $outra->id];
        $this->getJson("/api/rh/estrutura/unidades/{$this->ids['dep']}/tarefas", $s)->assertNotFound();
        $this->putJson("/api/rh/estrutura/tarefas/{$t}", ['nome' => 'Roubada'], $s)->assertNotFound();
    }
}
