<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paridade 9.3.1 (verificação de 2026-10-08): a lista /api/crm/contas traz o «Facturado 12 meses» do cliente e a
 * «Próxima actividade» por concluir (da conta ou das suas oportunidades), como a lista do antigo (crm_ui_gestao.js:57-66).
 */
final class ParidadeCRMContasTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000931', 'nome' => 'Empresa Paridade CRM, Lda']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PlanoConta::create(['codigo' => '311', 'descricao' => 'Clientes', 'tipo' => 'M']));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'crm_contas_view' => true, 'crm_pipeline_view' => true, 'crm_editar' => true, 'crm_converter' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function lista_de_contas_com_facturado_12_meses_e_proxima_actividade(): void
    {
        $s = $this->s;
        $f = $this->getJson('/api/crm/funis', $s)->assertOk()->json('dados.0');
        $conta = $this->postJson('/api/crm/contas', ['nome' => 'Cliente Fictício, Lda', 'nif' => '5000777777', 'origem' => 'Website'], $s)->assertCreated()->json('dados.id');
        $sem = $this->postJson('/api/crm/contas', ['nome' => 'Prospect Sem Nada'], $s)->assertCreated()->json('dados.id');

        // oportunidade (cria a tarefa automática «Primeiro contacto») + actividade da conta mais cedo + uma concluída ainda mais cedo
        $o = $this->postJson('/api/crm/oportunidades', ['titulo' => 'Negócio', 'conta_crm_id' => $conta, 'funil_vendas_crm_id' => $f['id'], 'valor' => 1000], $s)->assertCreated()->json('dados.id');
        $this->postJson('/api/crm/atividades', ['tipo' => 'REUNIAO', 'conta_crm_id' => $conta, 'titulo' => 'Reunião de apresentação', 'data_prevista' => now()->addDays(-1)->toDateString()], $s)->assertCreated();
        $feita = $this->postJson('/api/crm/atividades', ['tipo' => 'CHAMADA', 'oportunidade_crm_id' => $o, 'titulo' => 'Chamada antiga', 'data_prevista' => now()->subDays(20)->toDateString()], $s)
            ->assertCreated()->json('dados.id');
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DB::table('atividades_comerciais_crm')->where('id', $feita)->update(['concluida' => true]));

        // passa a cliente e tem facturação: 5 000 há 2 meses + 3 000 há 13 meses (fora dos 12 meses) + uma anulada
        $cliente = $this->postJson("/api/crm/contas/{$conta}/converter-em-cliente", ['codigo_conta' => '311'], $s)->assertOk()->json('dados.terceiro_id');
        $ft = fn (string $n, string $total, $data, string $estado = 'PAGO') => DB::table('vendas')->insert(['empresa_id' => $this->empresa->id, 'cliente_id' => $cliente,
            'tipo_documento' => 'FT', 'numero_documento' => $n, 'data_emissao' => $data, 'total_bruto' => $total, 'valor_pendente' => 0, 'data_vencimento' => $data, 'estado' => $estado]);
        $ft('FT 2026/1', '5000.00', now()->subMonths(2));
        $ft('FT 2025/9', '3000.00', now()->subMonths(13));
        $ft('FT 2026/2', '900.00', now()->subMonth(), 'ANULADO');

        $linhas = collect($this->getJson('/api/crm/contas?por_pagina=50', $s)->assertOk()->json('dados'))->keyBy('id');
        $c = $linhas[$conta];
        $this->assertSame('Website', $c['origem_original']);
        $this->assertSame(1, $c['oportunidades_abertas']);
        $this->assertSame('1000.00', $c['valor_aberto']);
        $this->assertSame('5000.00', $c['financeiro']['facturado_12m']);
        $this->assertSame(['Reunião de apresentação', now()->addDays(-1)->toDateString()], [$c['proxima_atividade']['titulo'], $c['proxima_atividade']['data_prevista']]);

        $p = $linhas[$sem];
        $this->assertNull($p['financeiro']);
        $this->assertNull($p['proxima_atividade']);
    }

    #[Test]
    public function proxima_actividade_vem_da_oportunidade_quando_a_conta_nao_tem_actividades_proprias(): void
    {
        $s = $this->s;
        $f = $this->getJson('/api/crm/funis', $s)->assertOk()->json('dados.0');
        $conta = $this->postJson('/api/crm/contas', ['nome' => 'Prospect Oportunidade'], $s)->assertCreated()->json('dados.id');
        $o = $this->postJson('/api/crm/oportunidades', ['titulo' => 'Negócio B', 'conta_crm_id' => $conta, 'funil_vendas_crm_id' => $f['id']], $s)->assertCreated()->json('dados.id');
        // só actividades da oportunidade, sem conta_crm_id
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DB::table('atividades_comerciais_crm')->where('oportunidade_crm_id', $o)->update(['conta_crm_id' => null]));

        $c = collect($this->getJson('/api/crm/contas', $s)->assertOk()->json('dados'))->firstWhere('id', $conta);
        $this->assertSame('Primeiro contacto com o cliente', $c['proxima_atividade']['titulo']);
        // a lista de outra empresa não vê esta conta
        $outra = $this->criarEmpresa(['nif' => '5417000932', 'nome' => 'Outra Empresa']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'crm_contas_view' => true])->id]);
        $u->empresas()->attach($outra->id);
        $this->assertNull(collect($this->getJson('/api/crm/contas', $this->entrar($u) + ['X-Empresa-Id' => $outra->id])->assertOk()->json('dados'))->firstWhere('id', $conta));
    }
}
