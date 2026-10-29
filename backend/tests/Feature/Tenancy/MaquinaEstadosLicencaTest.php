<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\Utilizador;
use App\Services\Plataforma\MaquinaEstadosLicenca;
use Tests\TestCase;

/**
 * Fase 1 da extensão SaaS (segurança.txt, secções 4.2/6/10): máquina de estados explícita da licença por empresa,
 * substituindo a inferência por datas por um campo auditável com transições validadas.
 */
class MaquinaEstadosLicencaTest extends TestCase
{
    public function test_empresa_nova_nasce_em_trial_com_licencas_ativas_e_em_activa_sem_elas(): void
    {
        config(['erp.licencas.ativo' => true]);
        $trial = $this->criarEmpresa(['nif' => '5000000201']);
        $this->assertSame(Empresa::LICENCA_TRIAL, $trial->estado_licenca);
        $this->assertNotNull($trial->estado_licenca_desde);

        config(['erp.licencas.ativo' => false]);
        $semLimite = $this->criarEmpresa(['nif' => '5000000202']);
        $this->assertSame(Empresa::LICENCA_ACTIVA, $semLimite->estado_licenca);
    }

    public function test_transicoes_invalidas_sao_rejeitadas_e_validas_sao_auditadas(): void
    {
        $empresa = $this->criarEmpresa(['nif' => '5000000203', 'estado_licenca' => Empresa::LICENCA_ACTIVA]);
        $maquina = app(MaquinaEstadosLicenca::class);

        // ACTIVA -> SUSPENSA sem motivo válido falha
        try {
            $maquina->transicionar($empresa, Empresa::LICENCA_SUSPENSA);
            $this->fail('Devia exigir um motivo válido.');
        } catch (\App\Exceptions\ErroNegocio $e) {
            $this->assertSame('MOTIVO_OBRIGATORIO', $e->codigo);
        }

        $maquina->transicionar($empresa, Empresa::LICENCA_SUSPENSA, Empresa::MOTIVO_ADMINISTRATIVA, 'Teste administrativo.');
        $empresa->refresh();
        $this->assertSame(Empresa::LICENCA_SUSPENSA, $empresa->estado_licenca);
        $this->assertSame(Empresa::MOTIVO_ADMINISTRATIVA, $empresa->motivo_suspensao);

        $log = \App\Models\LogAuditoria::withoutGlobalScopes()->where('tabela', 'empresas')->where('registo_id', (string) $empresa->id)
            ->where('acao', 'Transição de estado da licença')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('ACTIVA', $log->detalhes);
        $this->assertStringContainsString('SUSPENSA', $log->detalhes);

        // ENCERRADA é terminal: nenhuma transição é permitida a partir daí
        $maquina->transicionar($empresa, Empresa::LICENCA_CANCELADA, notas: 'Teste');
        $maquina->transicionar($empresa, Empresa::LICENCA_EM_ENCERRAMENTO);
        $maquina->transicionar($empresa, Empresa::LICENCA_ENCERRADA);
        $this->expectException(\App\Exceptions\ErroNegocio::class);
        $maquina->transicionar($empresa, Empresa::LICENCA_ACTIVA);
    }

    public function test_sincronizar_aplica_tolerancia_e_depois_suspensao_por_incumprimento(): void
    {
        config(['erp.licencas.dias_tolerancia' => 5]);
        $empresa = $this->criarEmpresa([
            'nif' => '5000000204', 'licenca_origem' => Empresa::LICENCA_PLANO,
            'licenca_fim' => now()->subDays(2)->toDateString(), 'estado_licenca' => Empresa::LICENCA_ACTIVA,
        ]);

        app(MaquinaEstadosLicenca::class)->sincronizar($empresa);
        $empresa->refresh();
        $this->assertSame(Empresa::LICENCA_TOLERANCIA, $empresa->estado_licenca, 'Dentro da janela de tolerância, não deve suspender.');

        $empresa->update(['licenca_fim' => now()->subDays(10)->toDateString()]);
        app(MaquinaEstadosLicenca::class)->sincronizar($empresa);
        $empresa->refresh();
        $this->assertSame(Empresa::LICENCA_SUSPENSA, $empresa->estado_licenca);
        $this->assertSame(Empresa::MOTIVO_INCUMPRIMENTO, $empresa->motivo_suspensao);
    }

    public function test_sincronizar_nunca_reverte_uma_suspensao_manual_so_pela_data(): void
    {
        $empresa = $this->criarEmpresa([
            'nif' => '5000000205', 'licenca_origem' => Empresa::LICENCA_PLANO,
            'licenca_fim' => now()->addDays(10)->toDateString(),   // licença ainda válida...
            'estado_licenca' => Empresa::LICENCA_SUSPENSA, 'motivo_suspensao' => Empresa::MOTIVO_SEGURANCA,   // ...mas suspensa por segurança
        ]);

        app(MaquinaEstadosLicenca::class)->sincronizar($empresa);
        $empresa->refresh();
        $this->assertSame(Empresa::LICENCA_SUSPENSA, $empresa->estado_licenca, 'Só um operador reactiva uma suspensão de segurança, nunca o recálculo automático.');
        $this->assertSame(Empresa::MOTIVO_SEGURANCA, $empresa->motivo_suspensao);
    }

    public function test_confirmar_pagamento_reactiva_empresa_suspensa_por_incumprimento(): void
    {
        $plano = PlanoLicenca::query()->firstOrCreate(['codigo' => 'teste_estado'], [
            'nome' => 'Plano Estado', 'preco_mensal' => 5000, 'moeda' => 'AOA', 'ativo' => true,
        ]);

        app(\App\Support\Tenancy\ContextoTenant::class)->definir(1);
        $empresa = $this->criarEmpresa([
            'nif' => '5000000206', 'licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => now()->subDays(20)->toDateString(),
            'estado_licenca' => Empresa::LICENCA_SUSPENSA, 'motivo_suspensao' => Empresa::MOTIVO_INCUMPRIMENTO,
        ]);
        $perfil = $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true]);
        $utilizador = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfil->id]);
        $utilizador->empresas()->attach($empresa->id);
        $sessao = $this->entrar($utilizador) + ['X-Empresa-Id' => $empresa->id];

        $subId = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $sessao)->assertCreated()->json('dados.id');

        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true])) + ['X-Empresa-Id' => $empresa->id];
        $this->postJson("/api/licenca/subscricoes/{$subId}/simular-confirmacao", [], $super)->assertOk();

        $sub = app(\App\Support\Tenancy\ContextoEmpresa::class)->semIsolamento(fn () => SubscricaoEmpresa::query()->findOrFail($subId));
        $this->assertSame(SubscricaoEmpresa::PAGO, $sub->estado);
        $empresa->refresh();
        $this->assertSame(Empresa::LICENCA_ACTIVA, $empresa->estado_licenca, 'O pagamento confirmado deve reactivar a licença automaticamente.');
        $this->assertNull($empresa->motivo_suspensao);
    }
}
