<?php

namespace Tests\Feature;

use App\Models\AtividadeComercialCRM;
use App\Models\Colaborador;
use App\Models\ContaCRM;
use App\Models\Empresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\FunilVendasCRM;
use App\Models\OportunidadeVendaCRM;
use App\Models\TarefaUnidadeOrganica;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase J (ADR-078) — ponte CRM → entregáveis: actividade concluída que cumpre o filtro vale uma entrega PENDENTE (chave
 * CRM|<ent>|<act>); sincronizar cria, corrige datas e retira órfãs removíveis; ganchos do CRM (concluir, reabrir, eliminar).
 */
final class FjEntregaveisCRMTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    private array $chefe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa FJC', 'nif' => '5000888821']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22])->id;
            $this->ids = ['ana' => $mk('Ana'), 'chefe' => $mk('Chefe')];
            $dep = UnidadeOrganica::create(['nome' => 'Comercial', 'codigo' => 'COM', 'tipo' => 'DEPARTAMENTO', 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true]);
            Colaborador::query()->whereKey([$this->ids['ana'], $this->ids['chefe']])->update(['unidade_organica_id' => $dep->id]);
            $funil = FunilVendasCRM::create(['nome' => 'Vendas', 'ordem' => 1, 'ativo' => true, 'etapas' => [['id' => 'lead', 'nome' => 'Lead', 'tipo' => 'ABERTA'], ['id' => 'ganha', 'nome' => 'Ganha', 'tipo' => 'GANHA']]]);
            $outroFunil = FunilVendasCRM::create(['nome' => 'Parcerias', 'ordem' => 2, 'ativo' => true, 'etapas' => [['id' => 'lead', 'nome' => 'Lead', 'tipo' => 'ABERTA']]]);
            $conta = ContaCRM::create(['nome' => 'Cliente Potencial', 'tipo' => 'PROSPECT', 'origem' => 'SITE']);
            $ganha = OportunidadeVendaCRM::create(['funil_vendas_crm_id' => $funil->id, 'conta_crm_id' => $conta->id, 'titulo' => 'Negócio ganho', 'etapa_codigo' => 'ganha', 'estado' => 'GANHA', 'valor' => 100]);
            $aberta = OportunidadeVendaCRM::create(['funil_vendas_crm_id' => $funil->id, 'conta_crm_id' => $conta->id, 'titulo' => 'Negócio aberto', 'etapa_codigo' => 'lead', 'estado' => 'ABERTA', 'valor' => 50]);
            $parceria = OportunidadeVendaCRM::create(['funil_vendas_crm_id' => $outroFunil->id, 'conta_crm_id' => $conta->id, 'titulo' => 'Parceria', 'etapa_codigo' => 'lead', 'estado' => 'ABERTA', 'valor' => 50]);
            $t = TarefaUnidadeOrganica::create(['unidade_organica_id' => $dep->id, 'nome' => 'Prospecção', 'periodicidade' => 'MENSAL', 'ativo' => true]);
            $e = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Reuniões com clientes ganhos', 'metrica' => 'TAREFAS', 'unidade' => 'reuniões', 'meta' => 3, 'ativo' => true,
                'origem_dados' => 'CRM', 'crm_filtro' => ['tipos' => ['REUNIAO', 'CHAMADA'], 'funil_vendas_crm_id' => $funil->id, 'so_ganhas' => true]]);
            $this->ids += ['dep' => $dep->id, 'funil' => $funil->id, 'conta' => $conta->id, 'ganha' => $ganha->id, 'aberta' => $aberta->id, 'parceria' => $parceria->id, 'ent' => $e->id];
        });
        // a Ana (utilizador ligado) é quem conclui as actividades no CRM
        $this->s = $this->sessao(['crm_pipeline_view', 'crm_agenda_view', 'crm_editar', 'est_estrutura_view', 'est_editar'], $this->ids['ana']);
        $this->chefe = $this->sessao(['rh_portal_usar', 'est_estrutura_view', 'est_editar'], $this->ids['chefe']);
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

    private function actividade(string $tipo, ?int $opp, string $data = '2026-03-02'): int
    {
        return $this->postJson('/api/crm/atividades', ['tipo' => $tipo, 'oportunidade_crm_id' => $opp, 'conta_crm_id' => $this->ids['conta'], 'titulo' => "{$tipo} teste", 'data_prevista' => $data], $this->s)
            ->assertCreated()->json('dados.id');
    }

    private function execucoes(): Collection
    {
        return $this->em(fn () => ExecucaoEntregavel::query()->where('entregavel_id', $this->ids['ent'])->orderBy('id')->get());
    }

    #[Test]
    public function ganchos_do_crm_criam_e_retiram_entregas_e_a_sincronizacao_corrige_as_diferencas(): void
    {
        $e = $this->ids['ent'];
        $reuniao = $this->actividade('REUNIAO', $this->ids['ganha']);
        $chamada = $this->actividade('CHAMADA', $this->ids['ganha']);
        $email = $this->actividade('EMAIL', $this->ids['ganha']);            // tipo fora do filtro
        $aberta = $this->actividade('REUNIAO', $this->ids['aberta']);       // oportunidade não ganha
        $parceria = $this->actividade('REUNIAO', $this->ids['parceria']);   // outro funil
        // antes de concluir: previstas 2, feitas 0, nada importado
        $st = $this->getJson("/api/rh/estrutura/entregaveis/{$e}/crm/estado", $this->s)->assertOk()->json('dados');
        $this->assertSame([2, 0, 0, 2], [$st['previstas'], $st['feitas'], $st['importadas'], count($st['por_fazer'])]);
        // concluir a reunião → gancho cria a entrega PENDENTE com a chave CRM|<ent>|<act>, executora = Ana (concluida_por)
        $this->postJson("/api/crm/atividades/{$reuniao}/concluir", ['resultado' => 'Fechado'], $this->s)->assertOk();
        foreach ([$email, $aberta, $parceria] as $fora) {
            $this->postJson("/api/crm/atividades/{$fora}/concluir", [], $this->s)->assertOk();
        }
        $x = $this->execucoes();
        $this->assertCount(1, $x);
        $this->assertSame(["CRM|{$e}|{$reuniao}", 'CRM', 'PENDENTE', $this->ids['ana'], '1.0000', '2026-03-10'], [$x[0]->origem_chave, $x[0]->origem, $x[0]->validacao, $x[0]->colaborador_id, (string) $x[0]->quantidade, $x[0]->data->toDateString()]);
        $this->assertStringContainsString('Reunião: REUNIAO teste', (string) $x[0]->observacoes);
        // reabrir → a entrega pendente sai; concluir outra vez → volta (sem duplicar)
        $this->postJson("/api/crm/atividades/{$reuniao}/reabrir", [], $this->s)->assertOk();
        $this->assertCount(0, $this->execucoes());
        $this->postJson("/api/crm/atividades/{$reuniao}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/crm/atividades/{$reuniao}/concluir", [], $this->s)->assertOk();
        $this->assertCount(1, $this->execucoes());
        // validada pelo responsável: reabrir deixa-a presa (com aviso) e a sincronização reporta-a como órfã presa
        $this->postJson("/api/rh/estrutura/execucoes/{$this->execucoes()[0]->id}/validar", ['decisao' => 'VALIDADA'], $this->chefe)->assertOk();
        $this->postJson("/api/crm/atividades/{$reuniao}/reabrir", [], $this->s)->assertOk()->assertJsonPath('mensagem', fn ($m) => str_contains($m, 'ficam'));
        $this->assertCount(1, $this->execucoes());
        $st = $this->getJson("/api/rh/estrutura/entregaveis/{$e}/crm/estado", $this->s)->json('dados');
        $this->assertSame([1, 0], [count($st['orfas_presas']), count($st['orfas_removiveis'])]);
        $this->assertSame('já validada', $st['orfas_presas'][0]['motivo']);
        $this->postJson("/api/crm/atividades/{$reuniao}/concluir", [], $this->s)->assertOk();   // volta a contar: a entrega presa é a dela (sem duplicar)
        $this->assertCount(1, $this->execucoes());

        // a chamada concluída por alguém sem colaborador ligado fica «sem colaborador»; sincronizar não a cria
        $semColab = $this->sessao(['crm_pipeline_view', 'crm_agenda_view', 'crm_editar']);
        $this->postJson("/api/crm/atividades/{$chamada}/concluir", [], $semColab)->assertOk();
        $st = $this->getJson("/api/rh/estrutura/entregaveis/{$e}/crm/estado", $this->s)->json('dados');
        $this->assertSame([2, 1, 1], [$st['feitas'], $st['importadas'], count($st['sem_colaborador'])]);
        $r = $this->postJson("/api/rh/estrutura/entregaveis/{$e}/crm/sincronizar", [], $this->s)->assertOk()->json('dados');
        $this->assertSame([0, 0, 0, 1], [$r['criadas'], $r['actualizadas'], $r['removidas'], $r['sem_colaborador']]);
        // concluída pela Ana, mas sem gancho (escrita directa): sincronizar cria; data alterada no CRM: sincronizar corrige; apagada: sincronizar retira
        $nomeAna = $this->em(fn () => DB::table('utilizador_empresa')->where('colaborador_id', $this->ids['ana'])->join('utilizadores', 'utilizadores.id', '=', 'utilizador_empresa.utilizador_id')->value('nome_utilizador'));
        $this->em(fn () => AtividadeComercialCRM::query()->whereKey($chamada)->update(['concluida_por' => $nomeAna]));
        $r = $this->postJson("/api/rh/estrutura/entregaveis/{$e}/crm/sincronizar", [], $this->s)->assertOk()->json('dados');
        $this->assertSame(1, $r['criadas']);
        $this->em(fn () => AtividadeComercialCRM::query()->whereKey($chamada)->update(['concluida_em' => '2026-03-01 09:00:00']));
        $this->postJson("/api/rh/estrutura/entregaveis/{$e}/crm/sincronizar", [], $this->s)->assertOk()->assertJsonPath('dados.actualizadas', 1);
        $this->assertSame('2026-03-01', $this->execucoes()->firstWhere('origem_chave', "CRM|{$e}|{$chamada}")->data->toDateString());
        $this->deleteJson("/api/crm/atividades/{$chamada}", [], $this->s)->assertOk();   // gancho: a pendente sai logo
        $this->assertCount(1, $this->execucoes());
        $this->postJson("/api/rh/estrutura/entregaveis/{$e}/crm/sincronizar", [], $this->s)->assertOk()->assertJsonPath('dados.removidas', 0);
        // sem est_editar não se sincroniza; entregável manual não tem estado CRM
        $this->postJson("/api/rh/estrutura/entregaveis/{$e}/crm/sincronizar", [], $this->chefe)->assertOk();
        $this->postJson("/api/rh/estrutura/entregaveis/{$e}/crm/sincronizar", [], $this->sessao(['est_estrutura_view']))->assertForbidden();
    }
}
