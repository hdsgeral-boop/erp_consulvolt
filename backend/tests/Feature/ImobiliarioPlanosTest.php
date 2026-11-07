<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixturesImobiliarioF5;
use Tests\TestCase;

/**
 * Gestão Imobiliária, Onda 4 (F5): planos de manutenção e vistoria. Datas propostas por periodicidade a partir do dia original, calendário
 * (em falta, por gerar, estado da ordem), gerar as próximas dentro da antecedência ou todas sem duplicar (UNIQUE plano + data), antecedência 0,
 * plano inactivo, eliminar com ordens por fechar, geração de todos os planos com falhas isoladas e a rotina diária (comando agendado) por empresa.
 * A data é fixada em 2026-03-20 (sexta-feira).
 */
final class ImobiliarioPlanosTest extends TestCase
{
    use FixturesImobiliarioF5;

    private int $im;

    private int $fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarObras();
        $this->im = $this->imovel();
        $this->fa = $this->fracao($this->im, '101');
    }

    /** @param  array<string, mixed>  $o @return array<string, mixed> */
    private function plano(array $o = [], ?array $h = null): array
    {
        return $this->postJson('/api/imobiliario/planos', $o + ['nome' => 'Pintura', 'descricao' => 'Pintar a escada', 'imovel_id' => $this->im, 'primeira_data' => '2026-01-31', 'periodicidade' => 'MENSAL',
            'quantas' => 4, 'antecedencia_dias' => 30], $h ?? $this->s)->assertCreated()->json('dados');
    }

    /** @return array<string, mixed> */
    private function calendario(int $plano): array
    {
        return $this->getJson("/api/imobiliario/planos/{$plano}/calendario", $this->s)->assertOk()->json('dados');
    }

    #[Test]
    public function o_plano_propoe_as_datas_a_partir_da_primeira_e_grava_as_escritas_uma_a_uma(): void
    {
        $r = $this->getJson('/api/imobiliario/planos/propor-datas?primeira=2026-01-31&periodicidade=MENSAL&quantas=4', $this->s)->assertOk();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $r->json('dados'));
        $this->getJson('/api/imobiliario/planos/propor-datas', $this->s)->assertStatus(422);
        $p = $this->plano();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $p['datas']);
        $this->assertSame(['PREVENTIVA', 'MENSAL', 30, '2026-03-31', true], [$p['tipo'], $p['periodicidade'], $p['antecedencia_dias'], $p['proxima'], $p['activo']]);   // a próxima é a primeira não passada
        // as datas escritas à mão (a de Dezembro que passa para Janeiro) sobrepõem a periodicidade; ordenam-se, sem repetições
        $q = $this->plano(['nome' => 'Vistoria', 'tipo' => 'VISTORIA', 'primeira_data' => null, 'periodicidade' => 'MANUAL', 'datas' => ['2026-12-20', '2026-06-10', '2026-06-10', '2026-02-30']]);
        $this->assertSame(['2026-06-10', '2026-12-20'], $q['datas']);
        $this->assertSame('2026-06-10', $q['proxima']);
        // fracção: o imóvel deriva dela
        $f = $this->plano(['nome' => 'Da fracção', 'imovel_id' => null, 'fracao_id' => $this->fa]);
        $this->assertSame($this->im, $f['imovel_id']);
        // editar mantém o que o pedido não traz
        $e = $this->putJson("/api/imobiliario/planos/{$p['id']}", ['nome' => 'Pintura anual', 'antecedencia_dias' => 0], $this->s)->assertOk()->json('dados');
        $this->assertSame(['Pintura anual', 0, 'Pintar a escada', $p['datas']], [$e['nome'], $e['antecedencia_dias'], $e['descricao'], $e['datas']]);
    }

    #[Test]
    public function o_plano_valida_o_tipo_a_descricao_o_local_e_as_datas(): void
    {
        $base = ['nome' => 'X', 'descricao' => 'Y', 'imovel_id' => $this->im, 'datas' => ['2026-05-01']];
        $this->postJson('/api/imobiliario/planos', ['tipo' => 'CORRECTIVA'] + $base, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_TIPO');
        $this->postJson('/api/imobiliario/planos', ['tipo' => 'APETRECHAMENTO'] + $base, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_TIPO');
        $this->postJson('/api/imobiliario/planos', ['descricao' => ' '] + $base, $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Descreva o que se vai fazer em cada data.');
        $this->postJson('/api/imobiliario/planos', ['imovel_id' => null] + $base, $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Indique o imóvel ou a fracção do plano.');
        $this->postJson('/api/imobiliario/planos', ['datas' => []] + $base, $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'O plano precisa de pelo menos uma data.');
        $this->postJson('/api/imobiliario/planos', ['datas' => ['2026-02-30']] + $base, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_SEM_DATAS');
        $im2 = $this->imovel('Outro');
        $this->postJson('/api/imobiliario/planos', ['imovel_id' => $im2, 'fracao_id' => $this->fa] + $base, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_FRACCAO_IMOVEL');
        $this->postJson('/api/imobiliario/planos', ['custo_estimado' => -5] + $base, $this->s)->assertStatus(422);
        $cinq = array_map(fn ($i) => sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), 1 + $i % 28), range(0, 61));
        $this->postJson('/api/imobiliario/planos', ['datas' => $cinq] + $base, $this->s)->assertStatus(422);   // máximo 60 (a validação ou o serviço recusam)
        // quem paga o senhorio nunca é debitável
        $p = $this->postJson('/api/imobiliario/planos', ['quem_paga' => 'SENHORIO', 'debitavel' => true] + $base, $this->s)->assertCreated()->json('dados');
        $this->assertFalse($p['debitavel']);
        $q = $this->postJson('/api/imobiliario/planos', ['nome' => 'Z', 'quem_paga' => 'INQUILINO', 'debitavel' => true] + $base, $this->s)->assertCreated()->json('dados');
        $this->assertTrue($q['debitavel']);
        // sem nome, o nome vem da descrição
        $this->assertSame('Y', $this->postJson('/api/imobiliario/planos', ['nome' => null] + $base, $this->s)->assertCreated()->json('dados.nome'));
    }

    #[Test]
    public function o_calendario_marca_em_falta_por_gerar_e_o_estado_da_ordem_gerada(): void
    {
        $p = $this->plano();
        $c = $this->calendario($p['id']);
        $this->assertSame(['EM_FALTA', 'EM_FALTA', 'POR_GERAR', 'POR_GERAR'], array_column($c['linhas'], 'estado'));
        $this->assertSame([0, 2, 2, '2026-03-31'], [$c['geradas'], $c['em_falta'], $c['por_gerar'], $c['proxima']]);
        $this->assertSame('2026-04-19', $c['horizonte']);   // hoje + 30 dias
        $l = $this->getJson('/api/imobiliario/planos', $this->s)->assertOk()->json('dados.0');
        $this->assertSame([4, 2, 2, '2026-03-31', 0], [$l['n_datas'], $l['em_falta'], $l['por_gerar'], $l['proxima_data'], $l['concluidas']]);
        $this->postJson("/api/imobiliario/planos/{$p['id']}/gerar", [], $this->s)->assertOk();
        $c = $this->calendario($p['id']);
        $this->assertSame(['ABERTA', 'ABERTA', 'ABERTA', 'POR_GERAR'], array_column($c['linhas'], 'estado'));
        $this->assertSame([3, 0, 1, '2026-03-31'], [$c['geradas'], $c['em_falta'], $c['por_gerar'], $c['proxima']]);
        $this->assertSame(['OB 2026/0001', 'OB 2026/0002', 'OB 2026/0003', null], array_column($c['linhas'], 'ordem_numero'));
        $this->postJson("/api/imobiliario/obras/ordens/{$c['linhas'][0]['ordem_id']}/concluir", [], $this->s)->assertOk();   // a ordem recuperada tem o pedido na data em falta, por isso concluir hoje é válido
        $this->assertSame('CONCLUIDA', $this->calendario($p['id'])['linhas'][0]['estado']);
    }

    #[Test]
    public function gerar_as_proximas_cria_so_o_que_cabe_na_antecedencia_e_repetir_nao_duplica(): void
    {
        $p = $this->plano(['descricao' => 'Pintar a escada', 'custo_estimado' => 40000, 'prioridade' => 'ALTA', 'responsavel' => 'Carlos', 'quem_paga' => 'INQUILINO']);
        $r = $this->postJson("/api/imobiliario/planos/{$p['id']}/gerar", [], $this->s)->assertOk();
        $this->assertSame(3, count($r->json('dados.criadas')));
        $this->assertSame([1, '2026-04-19'], [$r->json('dados.saltadas'), $r->json('dados.horizonte')]);   // 30 de Abril fica fora da antecedência
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_column($r->json('dados.criadas'), 'data'));
        // as ordens: descrição «{descrição} ({nome})», quem paga, custo, prioridade; as datas passadas guardam o rasto (pedido = a própria data)
        $o = (array) DB::table('ordens_trabalho_imo')->where('plano_data', '2026-01-31')->first();
        $this->assertSame(['PREVENTIVA', 'Pintar a escada (Pintura)', 'ALTA', 'INQUILINO', '40000.00', 'Carlos', '2026-01-31', '2026-01-31', 'Plano Pintura', 'ABERTA'],
            [$o['tipo'], $o['descricao'], $o['prioridade'], $o['quem_paga'], $o['custo_estimado'], $o['responsavel'], $o['data_pedido'], $o['data_prevista'], $o['pedido_por'], $o['estado']]);
        $futura = (array) DB::table('ordens_trabalho_imo')->where('plano_data', '2026-03-31')->first();
        $this->assertSame(['2026-03-20', '2026-03-31'], [$futura['data_pedido'], $futura['data_prevista']]);   // data futura: pedida hoje
        $this->assertSame(['2026-04-30', '2026-03-20'], [DB::table('planos_manutencao_imo')->value('proxima') ?? '', DB::table('planos_manutencao_imo')->value('ultima_geracao')]);
        // repetir não duplica
        $r2 = $this->postJson("/api/imobiliario/planos/{$p['id']}/gerar", [], $this->s)->assertOk();
        $this->assertSame([0, 4], [count($r2->json('dados.criadas')), $r2->json('dados.saltadas')]);
        $this->assertSame(3, DB::table('ordens_trabalho_imo')->count());
        // gerar todas cria a que faltava
        $r3 = $this->postJson("/api/imobiliario/planos/{$p['id']}/gerar", ['todas' => true], $this->s)->assertOk();
        $this->assertSame(['2026-04-30'], array_column($r3->json('dados.criadas'), 'data'));
        $this->assertSame([4, 'todas'], [DB::table('ordens_trabalho_imo')->count(), $r3->json('dados.horizonte')]);
        // até uma data indicada
        $q = $this->plano(['nome' => 'Revisão', 'primeira_data' => '2026-06-01', 'periodicidade' => 'MENSAL', 'quantas' => 4]);
        $r4 = $this->postJson("/api/imobiliario/planos/{$q['id']}/gerar", ['ate' => '2026-07-15'], $this->s)->assertOk();
        $this->assertSame(['2026-06-01', '2026-07-01'], array_column($r4->json('dados.criadas'), 'data'));
    }

    #[Test]
    public function a_antecedencia_zero_so_gera_o_que_e_para_hoje_ou_ja_passou(): void
    {
        $p = $this->plano(['antecedencia_dias' => 0, 'primeira_data' => '2026-03-01', 'periodicidade' => 'SEMESTRAL', 'quantas' => 2]);   // 2026-03-01 e 2026-09-01
        $c = $this->calendario($p['id']);
        $this->assertSame('2026-03-20', $c['horizonte']);   // o legado tratava 0 como 30
        $r = $this->postJson("/api/imobiliario/planos/{$p['id']}/gerar", [], $this->s)->assertOk();
        $this->assertSame(['2026-03-01'], array_column($r->json('dados.criadas'), 'data'));
        $this->assertSame(1, $r->json('dados.saltadas'));
    }

    #[Test]
    public function plano_inactivo_nao_gera_e_eliminar_exige_as_ordens_fechadas(): void
    {
        $p = $this->plano();
        $this->putJson("/api/imobiliario/planos/{$p['id']}", ['activo' => false], $this->s)->assertOk()->assertJsonPath('dados.activo', false);
        $this->postJson("/api/imobiliario/planos/{$p['id']}/gerar", [], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'O plano está inactivo: reactive-o antes de gerar ordens.');
        $this->assertSame(0, $this->postJson('/api/imobiliario/planos/gerar-todos', [], $this->s)->assertOk()->json('dados.criadas'));
        $this->putJson("/api/imobiliario/planos/{$p['id']}", ['activo' => true], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/planos/{$p['id']}/gerar", [], $this->s)->assertOk();
        $this->deleteJson("/api/imobiliario/planos/{$p['id']}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PLANO_COM_ORDENS')
            ->assertJsonPath('mensagem', 'O plano já gerou 3 ordem(ns) por fechar (OB 2026/0001, OB 2026/0002, OB 2026/0003). Feche-as ou cancele-as antes de apagar o plano.');
        foreach (DB::table('ordens_trabalho_imo')->orderBy('id')->pluck('id') as $i => $id) {
            $i === 0 ? $this->postJson("/api/imobiliario/obras/ordens/{$id}/concluir", [], $this->s)->assertOk()
                : $this->postJson("/api/imobiliario/obras/ordens/{$id}/cancelar", ['motivo' => 'Não é preciso'], $this->s)->assertOk();
        }
        $r = $this->deleteJson("/api/imobiliario/planos/{$p['id']}", [], $this->s)->assertOk();
        $this->assertSame(3, $r->json('dados.ordens_historicas'));
        $this->assertSame([0, 3], [DB::table('planos_manutencao_imo')->count(), DB::table('ordens_trabalho_imo')->count()]);   // as ordens ficam no histórico
        $this->assertSame(0, DB::table('ordens_trabalho_imo')->whereNotNull('plano_id')->count());
    }

    #[Test]
    public function gerar_todos_e_o_resumo_contam_os_planos_activos_e_um_que_falha_nao_impede_os_outros(): void
    {
        $a = $this->plano(['nome' => 'A', 'primeira_data' => '2026-03-31', 'quantas' => 1, 'periodicidade' => 'ANUAL']);
        $this->plano(['nome' => 'B', 'primeira_data' => '2026-01-10', 'quantas' => 2, 'periodicidade' => 'ANUAL']);   // 2026-01-10 em falta, 2027-01-10 por gerar
        $r = $this->getJson('/api/imobiliario/obras/resumo', $this->s)->assertOk()->json('dados');
        $this->assertSame([2, 1, 2], [$r['planos_activos'], $r['planos_em_falta'], $r['planos_por_gerar']]);
        $av = collect($this->getJson('/api/imobiliario/obras/avisos', $this->s)->json('dados'))->groupBy('tipo');
        $this->assertSame(1, count($av['PLANO_EM_FALTA']));
        $this->assertSame(1, count($av['PLANO_PROXIMO']));   // 2026-03-31 está dentro dos 30 dias e sem ordem
        $this->assertStringContainsString('Plano «B»: 1 data(s) já passada(s) sem ordem gerada.', $av['PLANO_EM_FALTA'][0]['texto']);
        // um plano cuja geração falha (dados incoerentes: a fracção é de outro imóvel) isola-se: nada fica criado desse plano e o outro gera
        $outro = $this->fracao($this->imovel('Outro prédio'), '901');
        DB::table('planos_manutencao_imo')->where('id', $a['id'])->update(['fracao_id' => $outro]);
        $g = $this->postJson('/api/imobiliario/planos/gerar-todos', [], $this->s)->assertOk();
        $this->assertSame([1, 1, 'A fracção não pertence ao imóvel indicado.', 'A'], [$g->json('dados.criadas'), count($g->json('dados.falhas')), $g->json('dados.falhas.0.erro'), $g->json('dados.falhas.0.plano')]);
        $this->assertSame([1, 0], [DB::table('ordens_trabalho_imo')->count(), DB::table('ordens_trabalho_imo')->where('plano_id', $a['id'])->count()]);
    }

    #[Test]
    public function a_rotina_diaria_gera_as_ordens_dos_planos_e_actualiza_as_recepcoes_por_empresa(): void
    {
        $this->darStock($this->cama, '2');
        $p = $this->plano(['primeira_data' => '2026-03-25', 'quantas' => 2, 'periodicidade' => 'MENSAL']);   // 25/03 (dentro dos 30 dias) e 25/04 (fora)
        // uma ordem com material pedido: o armazém entrega e a rotina regista a recepção
        $o = $this->ordem(['fracao_id' => $this->fa, 'tipo' => 'APETRECHAMENTO']);
        $this->necessidades($o['id'], [['produto_id' => $this->cama, 'quantidade' => 2]]);
        $this->aprovar($o['id']);
        $x = $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertOk();
        $this->entregarRequisicao($x->json('dados.requisicao_id'));
        // outra empresa, com o seu plano: a rotina é isolada por empresa
        $outra = $this->criarEmpresa(['nif' => '5417000999']);
        $h = $this->sessao(array_merge($this->perms, self::PERMS_OBRAS), $outra);
        $im2 = $this->imovel('Prédio da outra', $h);
        $q = $this->postJson('/api/imobiliario/planos', ['nome' => 'Da outra', 'descricao' => 'Vistoria', 'tipo' => 'VISTORIA', 'imovel_id' => $im2, 'datas' => ['2026-03-22']], $h)->assertCreated()->json('dados');

        $this->assertSame(0, Artisan::call('erp:imobiliario:obras'));
        $this->assertStringContainsString('2 ordem(ns) criada(s) pelos planos, 1 ordem(ns) com recepções actualizadas, 1 bem(ns) entrados no inventário, 0 falha(s).', Artisan::output());
        $this->assertSame(['2026-03-25'], DB::table('ordens_trabalho_imo')->where('plano_id', $p['id'])->pluck('plano_data')->all());
        $this->assertSame(1, DB::table('ordens_trabalho_imo')->where('plano_id', $q['id'])->count());
        $this->assertSame($outra->id, (int) DB::table('ordens_trabalho_imo')->where('plano_id', $q['id'])->value('empresa_id'));
        $this->assertSame(['RECEBIDO'], DB::table('necessidades_ordem_imo')->pluck('estado')->all());
        $this->assertSame(1, DB::table('bens_fracao')->where('fracao_id', $this->fa)->count());
        // repetir a rotina não duplica nada
        $this->assertSame(0, Artisan::call('erp:imobiliario:obras'));
        $this->assertStringContainsString('0 ordem(ns) criada(s) pelos planos, 0 ordem(ns) com recepções actualizadas, 0 bem(ns)', Artisan::output());
        $this->assertSame(1, DB::table('ordens_trabalho_imo')->where('plano_id', $p['id'])->count());
        $this->assertSame(1, DB::table('bens_fracao')->count());
    }
}
