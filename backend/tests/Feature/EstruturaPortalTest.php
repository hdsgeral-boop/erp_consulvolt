<?php

namespace Tests\Feature;

use App\Models\AusenciaFaltaColaborador;
use App\Models\CargoFuncao;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\DependenteColaborador;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\PlanoFeriasColaborador;
use App\Models\PostoTrabalho;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Services\RH\ServicoDocumentosRH;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Estrutura orgânica e portal do colaborador (RH parte 3a, ADR-040).
 * Direcção (responsável: Chefe) › Departamento (sem responsável) ‹ Ana → a chefia directa da Ana é o Chefe.
 */
final class EstruturaPortalTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    /** @var array<string, array<string, mixed>> sessões: ana, chefe, rh, rh2 */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Teste', 'nif' => '5000999999', 'municipio' => 'Luanda']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $cargo = CargoFuncao::create(['nome' => 'Técnica'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22,
                'cargo_funcao_id' => $cargo, 'data_admissao' => '2020-03-02'])->id;
            $this->ids = ['ana' => $mk('Ana'), 'chefe' => $mk('Chefe'), 'rui' => $mk('Rui'), 'cargo' => $cargo];
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'ativo' => true]);
            Colaborador::query()->whereKey([$this->ids['ana'], $this->ids['rui']])->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->whereKey($this->ids['chefe'])->update(['unidade_organica_id' => $dir->id]);
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            ContratoTrabalho::create(['colaborador_id' => $this->ids['ana'], 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2020-03-02',
                'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 300000]]]);
            $this->ids += ['dir' => $dir->id, 'dep' => $dep->id];
        });
        $rh = ['rh_portal_aprovar', 'rh_portal_gestao_view', 'rh_portal_modelos', 'est_estrutura_view', 'est_editar', 'est_eliminar', 'rh_funcoes_gerir', 'rh_funcao_del', 'funcoes_view'];
        $this->s = ['ana' => $this->sessao(['rh_portal_usar'], $this->ids['ana']), 'chefe' => $this->sessao(['rh_portal_usar', 'rh_portal_aprovar'], $this->ids['chefe']),
            'rh' => $this->sessao($rh), 'rh2' => $this->sessao($rh)];
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id, '_uid' => $u->id];
    }

    private function h(string $quem): array
    {
        return array_diff_key($this->s[$quem], ['_uid' => 1]);
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    #[Test]
    public function estrutura_sem_ciclos_afectacao_e_chefia_directa(): void
    {
        $rh = $this->h('rh');
        $this->getJson("/api/rh/estrutura/chefia/{$this->ids['ana']}", $rh)->assertOk()->assertJsonPath('dados.chefia_colaborador_id', $this->ids['chefe']);
        $this->getJson("/api/rh/estrutura/chefia/{$this->ids['chefe']}", $rh)->assertJsonPath('dados.chefia_colaborador_id', null)
            ->assertJsonPath('dados.equipa_directa', [$this->ids['ana'], $this->ids['rui']]);
        $this->putJson("/api/rh/estrutura/unidades/{$this->ids['dir']}", ['nome' => 'Direcção', 'unidade_organica_pai_id' => $this->ids['dep']], $rh)
            ->assertStatus(422)->assertJsonPath('codigo', 'CICLO_HIERARQUIA');
        $this->postJson('/api/rh/estrutura/unidades', ['nome' => 'Outra', 'codigo' => 'dir'], $rh)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');

        // chefia em ciclo: o gestor do Chefe não pode ser a Ana (que depende do Chefe)
        $this->postJson('/api/rh/estrutura/afectacao', ['colaboradores' => [$this->ids['chefe']], 'colaborador_gestor_id' => $this->ids['ana']], $rh)
            ->assertStatus(422)->assertJsonPath('codigo', 'CICLO_CHEFIA');

        // postos: do departamento, 1 vaga; posto sem unidade na afectação → a unidade é a do posto; acima das vagas é aviso
        $p1 = $this->postJson('/api/rh/estrutura/postos', ['unidade_organica_id' => $this->ids['dep'], 'cargo_funcao_id' => $this->ids['cargo'], 'vagas' => 1], $rh)->assertCreated()->json('dados.id');
        $p2 = $this->postJson('/api/rh/estrutura/postos', ['unidade_organica_id' => $this->ids['dir'], 'titulo' => 'Director', 'posto_superior_id' => $p1], $rh)->assertCreated()->json('dados.id');
        $this->putJson("/api/rh/estrutura/postos/{$p1}", ['unidade_organica_id' => $this->ids['dep'], 'titulo' => 'Técnico', 'posto_superior_id' => $p2], $rh)
            ->assertStatus(422)->assertJsonPath('codigo', 'CICLO_HIERARQUIA');
        $this->postJson('/api/rh/estrutura/afectacao', ['colaboradores' => [$this->ids['ana']], 'unidade_organica_id' => $this->ids['dir'], 'posto_trabalho_id' => $p1], $rh)
            ->assertStatus(422)->assertJsonPath('codigo', 'POSTO_FORA_DA_UNIDADE');
        // gestor explícito da Ana = Rui; a afectação em massa sem gestor não o apaga (o legado apagava)
        $this->postJson('/api/rh/estrutura/afectacao', ['colaboradores' => [$this->ids['ana']], 'colaborador_gestor_id' => $this->ids['rui']], $rh)->assertOk();
        $this->postJson('/api/rh/estrutura/afectacao', ['colaboradores' => [$this->ids['ana'], $this->ids['rui']], 'posto_trabalho_id' => $p1], $rh)->assertOk()
            ->assertJsonPath('dados.colaboradores.0.unidade_organica_id', $this->ids['dep'])->assertJsonPath('dados.colaboradores.0.colaborador_gestor_id', $this->ids['rui'])
            ->assertJsonCount(1, 'dados.avisos');   // 2 ocupantes para 1 vaga
        $this->getJson("/api/rh/estrutura/chefia/{$this->ids['ana']}", $rh)->assertJsonPath('dados.chefia_colaborador_id', $this->ids['rui']);
        // um gestor inactivo deixa de contar: volta a subir na árvore
        $this->em(fn () => Colaborador::query()->whereKey($this->ids['rui'])->update(['estado' => 'INACTIVO']));
        $this->getJson("/api/rh/estrutura/chefia/{$this->ids['ana']}", $rh)->assertJsonPath('dados.chefia_colaborador_id', $this->ids['chefe']);

        // eliminações protegidas e referências limpas
        $this->deleteJson("/api/rh/estrutura/unidades/{$this->ids['dir']}", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->deleteJson("/api/rh/cargos/{$this->ids['cargo']}", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->postJson('/api/rh/cargos', ['nome' => 'técnica'], $rh)->assertStatus(422)->assertJsonPath('codigo', 'CARGO_DUPLICADO');
        $this->deleteJson("/api/rh/estrutura/postos/{$p1}", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');   // a Ana ocupa-o
        $this->postJson('/api/rh/estrutura/afectacao', ['colaboradores' => [$this->ids['ana']], 'posto_trabalho_id' => null], $rh)->assertOk();
        $this->deleteJson("/api/rh/estrutura/postos/{$p1}", [], $rh)->assertOk();
        $this->assertNull($this->em(fn () => PostoTrabalho::query()->find($p2)->posto_superior_id));
        $this->assertNull($this->em(fn () => Colaborador::query()->find($this->ids['rui'])->posto_trabalho_id));   // inactivo: referência limpa
    }

    #[Test]
    public function ferias_e_ausencia_pelo_circuito_chefia_e_rh(): void
    {
        $ini = now()->addMonth()->startOfMonth()->addDays(2)->toDateString();
        $fim = now()->addMonth()->startOfMonth()->addDays(13)->toDateString();
        $p = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'FERIAS', 'data_inicio' => $ini, 'data_fim' => $fim], $this->h('ana'))->assertCreated()
            ->assertJsonPath('dados.estado', 'PENDENTE_CHEFIA')->assertJsonPath('dados.etapas.0.aprovador_colaborador_id', $this->ids['chefe'])->json('dados');
        $this->assertSame('PEDIDO', $this->em(fn () => PlanoFeriasColaborador::query()->find($p['plano_ferias_colaborador_id'])->estado));
        $this->getJson('/api/rh/portal/aprovacoes', $this->h('chefe'))->assertJsonCount(1, 'dados');

        $dec = fn (string $quem, array $d) => $this->postJson("/api/rh/portal/pedidos/{$p['id']}/decidir", $d, $this->h($quem));
        $dec('ana', ['decisao' => 'APROVADO'])->assertStatus(403)->assertJsonPath('codigo', 'AUTO_APROVACAO');
        $dec('rh', ['decisao' => 'APROVADO'])->assertStatus(403)->assertJsonPath('codigo', 'SEM_PERMISSAO_ETAPA');   // é a vez da chefia
        $dec('chefe', ['decisao' => 'APROVADO'])->assertOk()->assertJsonPath('dados.estado', 'PENDENTE_RH');
        $dec('chefe', ['decisao' => 'APROVADO'])->assertStatus(403)->assertJsonPath('codigo', 'SEGREGACAO_ETAPAS');   // a chefia também tem a permissão do RH
        $dec('rh', ['decisao' => 'APROVADO'])->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $this->assertSame('APROVADO', $this->em(fn () => PlanoFeriasColaborador::query()->find($p['plano_ferias_colaborador_id'])->estado));

        // ausência a critério do empregador: chefia → RH (sem data de decisão), aprovação exige a decisão de remuneração
        $a = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'AUSENCIA', 'ausencia_tipo' => 'OUTRA', 'motivo' => 'Assunto familiar', 'data_inicio' => $ini, 'data_fim' => $ini], $this->h('ana'))
            ->assertStatus(422)->assertJsonPath('codigo', 'SOBREPOSICAO');   // sobrepõe as férias aprovadas
        $dia = now()->addMonths(2)->startOfMonth()->addDays(9)->toDateString();
        $a = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'AUSENCIA', 'ausencia_tipo' => 'OUTRA', 'motivo' => 'Assunto familiar', 'data_inicio' => $dia, 'data_fim' => $dia], $this->h('ana'))
            ->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE_CHEFIA')->json('dados');
        $this->postJson("/api/rh/portal/pedidos/{$a['id']}/decidir", ['decisao' => 'APROVADO'], $this->h('chefe'))->assertOk();
        $aus = $this->em(fn () => AusenciaFaltaColaborador::query()->find($a['ausencia_falta_id']));
        $this->assertSame(['PENDENTE_RH', null], [$aus->estado, $aus->decidido_em]);
        $this->postJson("/api/rh/portal/pedidos/{$a['id']}/decidir", ['decisao' => 'APROVADO'], $this->h('rh'))->assertStatus(422)->assertJsonPath('codigo', 'DECISAO_REMUNERACAO');
        $this->postJson("/api/rh/portal/pedidos/{$a['id']}/decidir", ['decisao' => 'APROVADO', 'remunerada' => 'NAO'], $this->h('rh'))->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $aus->refresh();
        $this->assertSame(['APROVADO', 'NAO'], [$aus->estado, $aus->remunerada]);

        // recusa com nota; cancelar só pelo próprio
        $q = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'FERIAS', 'data_inicio' => now()->addMonths(3)->startOfMonth()->toDateString(),
            'data_fim' => now()->addMonths(3)->startOfMonth()->addDays(4)->toDateString()], $this->h('ana'))->assertCreated()->json('dados');
        $this->postJson("/api/rh/portal/pedidos/{$q['id']}/decidir", ['decisao' => 'RECUSADO'], $this->h('chefe'))->assertStatus(422)->assertJsonPath('codigo', 'NOTA_EM_FALTA');
        $this->postJson("/api/rh/portal/pedidos/{$q['id']}/cancelar", [], $this->h('rh'))->assertStatus(403);
        $this->postJson("/api/rh/portal/pedidos/{$q['id']}/cancelar", [], $this->h('ana'))->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
        $this->assertSame('CANCELADO', $this->em(fn () => PlanoFeriasColaborador::query()->find($q['plano_ferias_colaborador_id'])->estado));
        $this->getJson('/api/rh/portal/resumo', $this->h('ana'))->assertOk()->assertJsonPath('dados.chefia_colaborador_id', $this->ids['chefe']);
        $this->getJson('/api/rh/portal/resumo', $this->h('rh'))->assertStatus(403)->assertJsonPath('codigo', 'SEM_COLABORADOR');
    }

    #[Test]
    public function documentos_com_modelos_numeracao_e_agregado(): void
    {
        $doc = fn (string $modelo) => $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'DOCUMENTO', 'documento' => $modelo, 'finalidade' => 'crédito bancário', 'destinatario' => 'Banco A'], $this->h('ana'));
        $p1 = $doc('DECL_RENDIMENTOS')->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE_RH')->json('dados.id');
        $doc('DECL_RENDIMENTOS')->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_DUPLICADO');
        $this->postJson("/api/rh/portal/pedidos/{$p1}/decidir", ['decisao' => 'APROVADO'], $this->h('rh'))->assertStatus(422)->assertJsonPath('codigo', 'USAR_EMISSAO');

        // sem assinante o modelo não se emite sozinho: devolve a proposta para revisão
        $this->postJson("/api/rh/portal/pedidos/{$p1}/emitir", [], $this->h('rh'))->assertOk()->assertJsonPath('dados.emitido', false)
            ->assertJsonPath('dados.proposta.faltas', []);
        $this->putJson('/api/rh/portal/modelos', ['codigo' => 'DECL_RENDIMENTOS', 'nome' => 'Declaração de rendimentos', 'titulo' => 'DECLARAÇÃO', 'texto' => 'Texto com {{salario}} desconhecida.'], $this->h('rh'))
            ->assertStatus(422)->assertJsonPath('codigo', 'VARIAVEL_DESCONHECIDA');
        $texto = ServicoDocumentosRH::padrao()['DECL_RENDIMENTOS']['texto'];
        $this->putJson('/api/rh/portal/modelos', ['codigo' => 'DECL_RENDIMENTOS', 'nome' => 'Declaração de rendimentos', 'titulo' => 'DECLARAÇÃO DE RENDIMENTOS', 'texto' => $texto,
            'auto_emitir' => true, 'assinante' => 'Directora de RH'], $this->h('rh'))->assertOk();
        $e = $this->postJson("/api/rh/portal/pedidos/{$p1}/emitir", [], $this->h('rh'))->assertOk()->assertJsonPath('dados.emitido', true)
            ->assertJsonPath('dados.pedido.estado', 'EMITIDO')->json('dados.pedido.documento');
        $ano = now()->format('Y');
        $this->assertSame("DOC/{$ano}/0001", $e['numero']);
        $this->assertStringContainsString('300 000,00 Kz (trezentos mil kwanzas)', $e['texto']);
        $this->assertStringContainsString('desde 2 de Março de 2020', $e['texto']);
        $this->assertStringContainsString('a apresentar a Banco A', $e['texto']);

        // emissão com texto revisto pelo RH: número seguinte
        $p2 = $doc('DECL_SERVICO')->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/portal/pedidos/{$p2}/emitir", ['texto' => 'Declaramos que a colaboradora presta serviço nesta empresa.', 'assinante' => 'Director Geral'], $this->h('rh2'))
            ->assertOk()->assertJsonPath('dados.pedido.documento.numero', "DOC/{$ano}/0002")->assertJsonPath('dados.pedido.documento.automatico', false);

        // agregado: parentesco do legado («Filho(a)») normalizado; aprovação recusada se os dados mudaram entretanto
        $g = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'AGREGADO', 'dependentes' => [['nome' => 'Filho', 'parentesco' => 'Filho(a)', 'data_nascimento' => '2015-01-01']]], $this->h('ana'))
            ->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/portal/pedidos/{$g}/decidir", ['decisao' => 'APROVADO'], $this->h('rh'))->assertOk()->assertJsonPath('dados.estado', 'APROVADO');
        $this->assertSame(['FILHO'], $this->em(fn () => DependenteColaborador::query()->where('colaborador_id', $this->ids['ana'])->pluck('parentesco')->all()));
        $g2 = $this->postJson('/api/rh/portal/pedidos', ['tipo' => 'AGREGADO', 'dependentes' => []], $this->h('ana'))->assertCreated()->json('dados.id');
        $this->em(fn () => DependenteColaborador::create(['colaborador_id' => $this->ids['ana'], 'nome' => 'Cônjuge', 'parentesco' => 'CONJUGE', 'ordem' => 2]));
        $this->postJson("/api/rh/portal/pedidos/{$g2}/decidir", ['decisao' => 'APROVADO'], $this->h('rh'))->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_DESACTUALIZADO');

        // ligação utilizador ↔ colaborador: um utilizador por colaborador
        $this->postJson('/api/rh/portal/ligacoes', ['utilizador_id' => $this->s['rh2']['_uid'], 'colaborador_id' => $this->ids['ana']], $this->h('rh'))
            ->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_JA_LIGADO');
        $this->postJson('/api/rh/portal/ligacoes', ['utilizador_id' => $this->s['rh2']['_uid'], 'colaborador_id' => $this->ids['rui']], $this->h('rh'))->assertOk();
        $this->getJson('/api/rh/portal/recibos', $this->h('ana'))->assertOk()->assertJsonCount(0, 'dados');
    }
}
