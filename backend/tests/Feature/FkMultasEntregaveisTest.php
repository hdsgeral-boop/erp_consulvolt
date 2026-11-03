<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ImpedimentoEntregavel;
use App\Models\LinhaFolhaSalarial;
use App\Models\MultaEntregavel;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\TarefaUnidadeOrganica;
use App\Models\UnidadeOrganica;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\CenarioFaseK;
use Tests\TestCase;

/**
 * Fase K (ADR-079) — multas de entregáveis: propor a partir dos atrasos (idempotente, penalizado por ordem), decisão humana (nunca o
 * próprio penalizado), tecto opcional e lançamento/anulação na folha como nos empréstimos. Nada se desconta sem uma pessoa aplicar.
 */
final class FkMultasEntregaveisTest extends TestCase
{
    use CenarioFaseK;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenarioK();
    }

    private function propor(?array $h = null): array
    {
        return $this->postJson('/api/rh/estrutura/multas/propor', [], $h ?? $this->s['rh'])->assertOk()->json('dados');
    }

    private function multa(): MultaEntregavel
    {
        return $this->em(fn () => MultaEntregavel::query()->where('colaborador_id', $this->ids['ana'])->firstOrFail());
    }

    private function periodo(string $estado = 'ABERTO', string $mesAno = '03/2026'): int
    {
        return $this->em(fn () => PeriodoProcessamentoSalarial::create(['mes_ano' => $mesAno, 'estado' => $estado, 'contabilizado' => false, 'modo_calculo' => 'ATUAL'])->id);
    }

    #[Test]
    public function propor_e_idempotente_o_penalizado_segue_a_ordem_e_ha_avisos_sem_ninguem(): void
    {
        $rh = $this->s['rh'];
        $this->postJson('/api/rh/estrutura/multas/propor', [], $this->s['chefe'])->assertForbidden();
        // mais dois atrasos: sem dono → responsável da tarefa; unidade sem ninguém → aviso (não se propõe); sem multa → nada
        $extra = $this->em(function () {
            $t2 = TarefaUnidadeOrganica::create(['unidade_organica_id' => $this->ids['dep'], 'nome' => 'Mapas', 'periodicidade' => 'MENSAL', 'ativo' => true, 'colaborador_responsavel_id' => $this->ids['rui']]);
            $orfa = UnidadeOrganica::create(['nome' => 'Órfã', 'codigo' => 'ORF', 'tipo' => 'DEPARTAMENTO', 'ativo' => true, 'ordem' => 30]);
            $t3 = TarefaUnidadeOrganica::create(['unidade_organica_id' => $orfa->id, 'nome' => 'Sem responsável', 'periodicidade' => 'MENSAL', 'ativo' => true]);
            $mk = fn (int $t, string $n, ?float $multa) => EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t, 'nome' => $n, 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'meta' => 1, 'prazo_dia' => 5, 'prazo_mes' => 2,
                'alerta' => true, 'multa_valor' => $multa, 'ativo' => true])->id;

            return ['mapa' => $mk($t2->id, 'Mapa', 200), 'orfao' => $mk($t3->id, 'Órfão', 300), 'sem_multa' => $mk($t2->id, 'Sem multa', null)];
        });
        $r = $this->propor();
        $this->assertSame([2, 0, 1], [$r['criadas'], $r['ignoradas'], count($r['avisos'])]);
        $this->assertStringContainsString('Órfão', $r['avisos'][0]);
        $porEntregavel = $this->em(fn () => MultaEntregavel::query()->pluck('colaborador_id', 'entregavel_id')->all());
        $this->assertSame([$this->ids['entregavel'] => $this->ids['ana'], $extra['mapa'] => $this->ids['rui']], $porEntregavel);   // dono; senão o responsável da tarefa
        $m = $this->multa();
        $this->assertSame(['PROPOSTA', '5000.00', '2026-02-01', '2026-03-05', 5, 'Multa por atraso do relatório'], [$m->estado, (string) $m->valor, $m->periodo_de->toDateString(), $m->limite->toDateString(),
            $m->dias_atraso, $m->descricao]);
        // idempotente
        $r2 = $this->propor();
        $this->assertSame([0, 2], [$r2['criadas'], $r2['ignoradas']]);
        $this->assertSame(2, $this->em(fn () => MultaEntregavel::query()->count()));

        // a unidade do penalizado, ver (est_estrutura_view ou est_multas_gerir) e filtros
        $this->getJson('/api/rh/estrutura/multas', $this->s['rui'])->assertForbidden();
        $lista = $this->getJson('/api/rh/estrutura/multas?estado=PROPOSTA&unidade_id='.$this->ids['dir'], $this->s['chefe'])->assertOk()->json('dados');   // a Direcção inclui o Departamento
        $this->assertCount(2, $lista);
        $daAna = collect($lista)->firstWhere('colaborador_id', $this->ids['ana']);
        $this->assertSame(['Proposta', 'Relatório', 'Ana', 'Departamento'], [$daAna['rotulo_estado'], $daAna['entregavel_nome'], $daAna['colaborador_nome'], $daAna['unidade_nome']]);
        $this->assertCount(1, $this->getJson('/api/rh/estrutura/multas?colaborador_id='.$this->ids['ana'], $rh)->json('dados'));
        $this->assertCount(0, $this->getJson('/api/rh/estrutura/multas?estado=APLICADA', $rh)->json('dados'));
        $this->assertCount(0, $this->getJson('/api/rh/estrutura/multas?de=2026-03-01', $rh)->json('dados'));
        $this->assertCount(2, $this->getJson('/api/rh/estrutura/multas?de=2026-02-01&ate=2026-02-28', $rh)->json('dados'));
    }

    #[Test]
    public function com_impedimento_aceite_nao_ha_multa_e_o_valor_vem_do_entregavel(): void
    {
        $this->em(fn () => ImpedimentoEntregavel::create(['entregavel_id' => $this->ids['entregavel'], 'tarefa_unidade_id' => $this->ids['tarefa'], 'unidade_organica_id' => $this->ids['dep'], 'colaborador_id' => $this->ids['ana'],
            'periodo_de' => '2026-02-01', 'prazo' => '2026-03-05', 'motivo' => 'Sem acesso', 'estado' => 'ACEITE']));
        $this->assertSame([0, 0, []], array_values(array_intersect_key($this->propor(), array_flip(['criadas', 'ignoradas', 'avisos']))));
    }

    #[Test]
    public function aplicar_dispensar_e_repor_so_com_permissao_e_nunca_o_proprio_penalizado(): void
    {
        $gestor = $this->em(fn () => Colaborador::create(['nome_completo' => 'Gestor', 'nif' => '1010', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => Colaborador::query()->value('tipo_organizacao_id'), 'dias_uteis_mes' => 22,
            'data_admissao' => '2020-01-06', 'unidade_organica_id' => $this->ids['dep']])->id);
        $this->em(fn () => EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $this->ids['tarefa'], 'nome' => 'Do gestor', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'meta' => 1, 'prazo_dia' => 5, 'prazo_mes' => 2,
            'colaborador_id' => $gestor, 'alerta' => true, 'multa_valor' => 100, 'ativo' => true]));
        $sg = $this->sessao(['est_multas_gerir', 'est_estrutura_view', 'rh_portal_usar'], $gestor);
        $this->assertSame(2, $this->propor($sg)['criadas']);
        $deAna = $this->multa()->id;
        $doGestor = $this->em(fn () => MultaEntregavel::query()->where('colaborador_id', $gestor)->value('id'));

        // sem permissão; e o próprio penalizado nunca decide (AUTO_DECISAO), mesmo com a permissão
        $this->postJson("/api/rh/estrutura/multas/{$deAna}/aplicar", [], $this->s['chefe'])->assertForbidden();
        $this->postJson("/api/rh/estrutura/multas/{$doGestor}/aplicar", [], $sg)->assertForbidden()->assertJsonPath('codigo', 'AUTO_DECISAO');
        $this->postJson("/api/rh/estrutura/multas/{$doGestor}/dispensar", ['motivo' => 'Perdoo-me a mim próprio'], $sg)->assertForbidden()->assertJsonPath('codigo', 'AUTO_DECISAO');
        $this->assertSame('PROPOSTA', $this->em(fn () => MultaEntregavel::query()->find($doGestor)->estado));

        // aplicar (uma vez) → APLICADA com quem decidiu; só se aplicam propostas
        $a = $this->postJson("/api/rh/estrutura/multas/{$deAna}/aplicar", ['nota' => 'Confirmado'], $sg)->assertOk()->assertJsonPath('dados.estado', 'APLICADA')->json('dados');
        $this->assertSame([$gestor, 'Confirmado'], [$a['decidido_por_colaborador_id'], $a['motivo_decisao']]);
        $this->postJson("/api/rh/estrutura/multas/{$deAna}/aplicar", [], $sg)->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_INVALIDO');
        $this->postJson("/api/rh/estrutura/multas/{$deAna}/dispensar", ['motivo' => 'Tarde demais'], $sg)->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_INVALIDO');
        // reabrir a aplicada (ainda por descontar), dispensar com motivo, reabrir a dispensada
        $this->postJson("/api/rh/estrutura/multas/{$deAna}/repor", [], $sg)->assertOk()->assertJsonPath('dados.estado', 'PROPOSTA')->assertJsonPath('dados.decidido_por', null);
        $this->postJson("/api/rh/estrutura/multas/{$deAna}/dispensar", ['motivo' => 'ab'], $sg)->assertStatus(422);
        $this->postJson("/api/rh/estrutura/multas/{$deAna}/dispensar", ['motivo' => 'Justificado pela direcção'], $sg)->assertOk()->assertJsonPath('dados.estado', 'DISPENSADA');
        $this->postJson("/api/rh/estrutura/multas/{$deAna}/repor", [], $sg)->assertOk()->assertJsonPath('dados.estado', 'PROPOSTA');
        $this->postJson("/api/rh/estrutura/multas/{$doGestor}/repor", [], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_INVALIDO');   // proposta: nada a repor
        // o ecrã diz quem pode decidir
        $linha = collect($this->getJson('/api/rh/estrutura/multas', $sg)->json('dados'));
        $this->assertSame([true, false], [$linha->firstWhere('id', $deAna)['pode_decidir'], $linha->firstWhere('id', $doGestor)['pode_decidir']]);
    }

    #[Test]
    public function folha_importar_so_o_aplicado_tecto_opcional_anular_e_retirar_o_lancamento(): void
    {
        $rh = $this->s['rh'];
        $periodo = $this->periodo();
        $this->propor();
        $m = $this->multa();
        $importar = fn (?array $h = null, array $corpo = []) => $this->postJson("/api/rh/salarios/periodos/{$periodo}/importar-multas-entregaveis", $corpo, $h ?? $rh);

        // configuração: rubrica de DESCONTO e tecto (est_multas_gerir ou contratos_new); sem rubrica não se lança
        $this->getJson('/api/rh/configuracao', $rh)->assertOk()->assertJsonPath('dados.multas_entregavel_max_pct', 0)->assertJsonPath('dados.infotipo_multa_entregavel_id', null);
        $importar()->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_EM_FALTA');
        $this->putJson('/api/rh/configuracao', ['infotipo_multa_entregavel_id' => $this->ids['multa']], $this->s['chefe'])->assertForbidden();
        $this->putJson('/api/rh/configuracao', ['infotipo_multa_entregavel_id' => $this->ids['base']], $rh)->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_NAO_DESCONTO');
        $this->putJson('/api/rh/configuracao', ['multas_entregavel_max_pct' => 101], $rh)->assertStatus(422);
        $this->putJson('/api/rh/configuracao', ['infotipo_multa_entregavel_id' => $this->ids['multa']], $rh)->assertOk()->assertJsonPath('dados.infotipo_multa_entregavel_id', $this->ids['multa']);

        // as permissões da folha: a dos empréstimos E a das multas
        $soMultas = $this->sessao(['est_multas_gerir', 'est_estrutura_view']);
        $soFolha = $this->sessao(['rh_emp_lancar', 'calcular_view']);
        $importar($soMultas)->assertForbidden();
        $importar($soFolha)->assertForbidden();
        $this->postJson("/api/rh/salarios/periodos/{$periodo}/anular-multas-entregaveis", [], $soFolha)->assertForbidden();

        // uma PROPOSTA nunca se desconta; só depois de aplicada por uma pessoa
        $importar()->assertOk()->assertJsonPath('dados.lancados', 0)->assertJsonPath('dados.total', '0.00');
        $this->postJson("/api/rh/estrutura/multas/{$m->id}/aplicar", [], $rh)->assertOk();
        $r = $importar()->assertOk()->assertJsonPath('dados.lancados', 1)->assertJsonPath('dados.total', '5000.00')->json('dados');
        $this->assertSame([], $r['avisos']);
        $linha = $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $periodo)->where('infotipo_salarial_id', $this->ids['multa'])->firstOrFail());
        $this->assertSame([(string) $this->ids['ana'], 'MULTA_ENTREGAVEL', '5000.00'], [(string) $linha->colaborador_id, $linha->origem, (string) $linha->valor]);
        $descontada = $this->em(fn () => MultaEntregavel::query()->findOrFail($m->id));
        $this->assertSame(['DESCONTADA', $linha->id, $periodo], [$descontada->estado, $descontada->linha_folha_salarial_id, $descontada->periodo_processamento_salarial_id]);
        $importar()->assertOk()->assertJsonPath('dados.lancados', 0);   // já descontada: nada novo
        $this->postJson("/api/rh/estrutura/multas/{$m->id}/repor", [], $rh)->assertStatus(422);   // descontada não se reabre

        // anular repõe: a linha sai e a multa volta a APLICADA
        $this->postJson("/api/rh/salarios/periodos/{$periodo}/anular-multas-entregaveis", [], $rh)->assertOk()->assertJsonPath('dados.repostas', 1);
        $this->assertSame(0, $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $periodo)->count()));
        $this->assertSame(['APLICADA', null], $this->em(fn () => [MultaEntregavel::query()->find($m->id)->estado, MultaEntregavel::query()->find($m->id)->linha_folha_salarial_id]));

        // tecto de 1 % de 300000 = 3000 < 5000: a multa fica aplicada e vai para os avisos; com 2 % (6000) desconta
        $this->putJson('/api/rh/configuracao', ['multas_entregavel_max_pct' => 1], $rh)->assertOk()->assertJsonPath('dados.multas_entregavel_max_pct', 1);
        $r = $importar()->assertOk()->assertJsonPath('dados.lancados', 0)->json('dados');
        $this->assertStringContainsString('tecto de 1%', $r['avisos'][0]);
        $this->assertSame('APLICADA', $this->em(fn () => MultaEntregavel::query()->find($m->id)->estado));
        $this->putJson('/api/rh/configuracao', ['multas_entregavel_max_pct' => 2], $rh)->assertOk();
        $importar()->assertOk()->assertJsonPath('dados.lancados', 1);

        // retirar o lançamento pela folha repõe a multa (como as prestações dos empréstimos)
        $linha = $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $periodo)->firstOrFail());
        $this->deleteJson("/api/rh/salarios/periodos/{$periodo}/lancamentos/{$linha->id}", [], $rh)->assertOk();
        $this->assertSame('APLICADA', $this->em(fn () => MultaEntregavel::query()->find($m->id)->estado));
        // existindo já uma linha na rubrica, não se substitui sem pedir
        $this->em(fn () => LinhaFolhaSalarial::create(['periodo_processamento_salarial_id' => $periodo, 'colaborador_id' => $this->ids['ana'], 'infotipo_salarial_id' => $this->ids['multa'], 'valor' => 10, 'origem' => 'MANUAL']));
        $importar()->assertOk()->assertJsonPath('dados.lancados', 0)->assertJsonPath('dados.ignorados', 1);
        $importar(null, ['substituir' => true])->assertOk()->assertJsonPath('dados.actualizados', 1);
        $this->assertSame('5000.00', (string) $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $periodo)->value('valor')));

        // período fechado: não se lança nem se anula
        $fechado = $this->periodo('VALIDADO', '02/2026');
        $this->postJson("/api/rh/salarios/periodos/{$fechado}/importar-multas-entregaveis", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_ESTADO_INVALIDO');
        $this->postJson("/api/rh/salarios/periodos/{$fechado}/anular-multas-entregaveis", [], $rh)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_ESTADO_INVALIDO');
    }

    #[Test]
    public function os_atrasos_e_os_meus_entregaveis_trazem_a_multa_proposta(): void
    {
        $atrasos = fn (array $h, string $url = '/api/rh/portal/aprovacoes/atrasos') => $this->getJson($url, $h)->assertOk()->json('dados');
        $this->assertNull($atrasos($this->s['chefe'])[0]['multa']);
        $this->propor();
        $m = $this->multa();
        $this->assertSame(['id' => $m->id, 'estado' => 'PROPOSTA'], $atrasos($this->s['chefe'])[0]['multa']);
        $this->assertSame(['id' => $m->id, 'estado' => 'PROPOSTA'], $atrasos($this->s['rh'], '/api/rh/estrutura/entregaveis/atrasos')[0]['multa']);
        $this->assertSame('PROPOSTA', $this->getJson('/api/rh/portal/entregaveis', $this->s['ana'])->assertOk()->json('dados.entregaveis.0.multa.estado'));
        $this->postJson("/api/rh/estrutura/multas/{$m->id}/aplicar", [], $this->s['rh'])->assertOk();
        $this->assertSame('APLICADA', $atrasos($this->s['chefe'])[0]['multa']['estado']);
    }
}
