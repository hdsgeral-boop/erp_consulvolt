<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\Utilizador;
use Tests\TestCase;

/**
 * Testes para:
 * 1. Notificações do sistema por empresa com isolamento estrito.
 * 2. Gestão de métodos de pagamento (Referência e Multicaixa Express) e confirmação via webhook.
 * 3. Gestão de ativação/desligamento do SaaS geral e por empresa.
 */
class NotificacoesEGatewaysTest extends TestCase
{
    public function test_notificacoes_da_empresa_com_isolamento(): void
    {
        $emp1 = $this->criarEmpresa(['nif' => '5000000088']);
        $emp2 = $this->criarEmpresa(['nif' => '5000000099']);

        $u1 = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR]);
        $u1->empresas()->attach($emp1->id);
        $s1 = $this->entrar($u1) + ['X-Empresa-Id' => $emp1->id];

        $u2 = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR]);
        $u2->empresas()->attach($emp2->id);
        $s2 = $this->entrar($u2) + ['X-Empresa-Id' => $emp2->id];

        // Criar notificação na empresa 1
        $r = $this->postJson('/api/sistema/notificacoes/teste', [
            'tipo' => 'SUCESSO',
            'categoria' => 'VENDAS',
            'titulo' => 'Fatura emitida',
            'mensagem' => 'Fatura FT-2026/001 emitida no valor de 50.000 Kz',
        ], $s1)->assertCreated();

        $id = $r->json('dados.id');

        // Empresa 1 vê a notificação
        $resumo1 = $this->getJson('/api/sistema/notificacoes', $s1)->assertOk()->json('dados');
        $this->assertSame(1, $resumo1['total_nao_lidas']);
        $this->assertSame('Fatura emitida', $resumo1['notificacoes'][0]['titulo']);

        // Empresa 2 NÃO vê a notificação da empresa 1 (isolamento total)
        $resumo2 = $this->getJson('/api/sistema/notificacoes', $s2)->assertOk()->json('dados');
        $this->assertSame(0, $resumo2['total_nao_lidas']);

        // Marcar como lida na empresa 1
        $this->postJson("/api/sistema/notificacoes/{$id}/ler", [], $s1)->assertOk();
        $this->assertSame(0, $this->getJson('/api/sistema/notificacoes', $s1)->json('dados.total_nao_lidas'));
    }

    public function test_gestao_e_iniciacao_de_pagamentos_via_gateway(): void
    {
        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        config(['erp.saas.ativo' => true]);

        // Obter e atualizar configurações de gateway
        $config = $this->getJson('/api/plataforma/pagamentos/config', $super)->assertOk()->json('dados');
        $this->assertArrayHasKey('referencia', $config);
        $this->assertArrayHasKey('express', $config);
        $this->assertArrayHasKey('dados_bancarios', $config);

        $this->putJson('/api/plataforma/pagamentos/config', [
            'referencia' => ['entidade' => '00999', 'subentidade' => '12'],
            'express' => ['merchant_id' => 'LOJA_TESTE'],
            'dados_bancarios' => ['banco' => 'BFA', 'iban' => 'AO06 0006 1234 5678 9012 3'],
        ], $super)->assertOk()->assertJsonPath('dados.referencia.entidade', '00999');

        // Criar plano e subscrição para teste de pagamento
        $plano = PlanoLicenca::query()->firstOrCreate(['codigo' => 'teste_p'], [
            'nome' => 'Plano Teste', 'preco_mensal' => 10000, 'moeda' => 'AOA', 'ativo' => true,
        ]);

        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        $empresa = $this->criarEmpresa([
            'nif' => '5000000077',
            'licenca_origem' => Empresa::LICENCA_PLANO,
            'licenca_fim' => now()->addDays(5)->toDateString(),
        ]);
        $perfil = $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true]);
        $uEmpresa = $this->criarUtilizador([
            'papel' => Utilizador::PAPEL_ADMINISTRADOR,
            'perfil_utilizador_id' => $perfil->id,
        ]);
        $uEmpresa->empresas()->attach($empresa->id);
        $sessaoEmpresa = $this->entrar($uEmpresa) + ['X-Empresa-Id' => $empresa->id];

        // Criar pedido de renovação
        $subId = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 2], $sessaoEmpresa)
            ->assertCreated()->json('dados.id');

        // 1. Iniciar pagamento por Referência
        $resRef = $this->postJson("/api/licenca/subscricoes/{$subId}/iniciar-pagamento", [
            'metodo' => 'REFERENCIA',
        ], $sessaoEmpresa)->assertOk()->json('dados');

        $this->assertSame('REFERENCIA', $resRef['gateway']);
        $this->assertNotEmpty($resRef['referencia']);

        // 2. Iniciar pagamento por Multicaixa Express com validação de telemóvel angolano
        $this->postJson("/api/licenca/subscricoes/{$subId}/iniciar-pagamento", [
            'metodo' => 'EXPRESS',
            'telemovel' => '12345678', // Inválido
        ], $sessaoEmpresa)->assertStatus(422);

        $resExp = $this->postJson("/api/licenca/subscricoes/{$subId}/iniciar-pagamento", [
            'metodo' => 'EXPRESS',
            'telemovel' => '923123456',
        ], $sessaoEmpresa)->assertOk()->json('dados');

        $this->assertSame('EXPRESS', $resExp['gateway']);
        $this->assertSame('923123456', $resExp['telemovel']);

        $this->postJson('/api/pagamentos/webhook/referencia', [
            'referencia' => str_replace(' ', '', $resRef['referencia']),
            'status' => 'paid',
            'amount' => 20000,
        ])->assertOk()->assertJsonPath('sucesso', true);

        // Subscrição passa a PAGO e empresa ganha licença de PLANO
        $sub = app(\App\Support\Tenancy\ContextoEmpresa::class)->semIsolamento(
            fn () => SubscricaoEmpresa::query()->findOrFail($subId)
        );
        $this->assertSame(SubscricaoEmpresa::PAGO, $sub->estado);

        $empresa->refresh();
        $this->assertSame(Empresa::LICENCA_PLANO, $empresa->licenca_origem);
        $this->assertNotNull($empresa->licenca_fim);
    }

    public function test_gestao_saas_geral_e_por_empresa(): void
    {
        $empresa = $this->criarEmpresa(['nome' => 'Empresa SaaS Alfa']);
        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        config(['erp.saas.ativo' => true]);

        // Consulta de estado geral
        $estado = $this->getJson('/api/plataforma/saas/estado', $super)->assertOk()->json('dados');
        $this->assertArrayHasKey('saas_ativo', $estado);
        $this->assertArrayHasKey('estatisticas', $estado);

        // Atualização geral
        $this->putJson('/api/plataforma/saas/geral', [
            'licencas_ativas' => true,
            'dias_teste_padrao' => 14,
        ], $super)->assertOk()->assertJsonPath('dados.dias_teste_padrao', 14);

        // Listar empresas para gestão de SaaS
        $empresas = $this->getJson('/api/plataforma/saas/empresas', $super)->assertOk()->json('dados');
        $this->assertNotEmpty($empresas);

        $primeira = $empresas[0]['id'];

        // Atualizar licença da empresa: adicionar 60 dias
        $this->putJson("/api/plataforma/saas/empresas/{$primeira}", [
            'origem' => 'PLANO',
            'dias_adicionar' => 60,
        ], $super)->assertOk()->assertJsonPath('dados.origem', 'PLANO');

        // Revogar licença
        $this->putJson("/api/plataforma/saas/empresas/{$primeira}", [
            'origem' => 'PLANO',
            'revogar' => true,
        ], $super)->assertOk();

        // Tornar vitalícia (SEM_LIMITE)
        $this->putJson("/api/plataforma/saas/empresas/{$primeira}", [
            'origem' => 'SEM_LIMITE',
        ], $super)->assertOk()->assertJsonPath('dados.origem', 'SEM_LIMITE');
    }
}
