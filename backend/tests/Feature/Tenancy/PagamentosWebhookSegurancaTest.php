<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\TransacaoGateway;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Segurança do webhook de pagamentos (Fase 0 da extensão SaaS, secção 8.1/8.3 do documento): o endpoint
 * `POST /api/pagamentos/webhook/{gateway}` é público e sem sessão, por isso nunca pode confiar directamente no
 * payload recebido — exige assinatura HMAC válida e nunca aceita o montante declarado pelo remetente sem o
 * confrontar com o valor efectivamente devido.
 */
class PagamentosWebhookSegurancaTest extends TestCase
{
    private function prepararTransacao(): array
    {
        $plano = PlanoLicenca::query()->firstOrCreate(['codigo' => 'teste_seg'], [
            'nome' => 'Plano Segurança', 'preco_mensal' => 10000, 'moeda' => 'AOA', 'ativo' => true,
        ]);

        app(ContextoTenant::class)->definir(1);
        $empresa = $this->criarEmpresa([
            'nif' => '5000000066',
            'licenca_origem' => Empresa::LICENCA_PLANO,
            'licenca_fim' => now()->addDays(5)->toDateString(),
        ]);
        $perfil = $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true]);
        $utilizador = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfil->id]);
        $utilizador->empresas()->attach($empresa->id);
        $sessao = $this->entrar($utilizador) + ['X-Empresa-Id' => $empresa->id];

        $subId = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $sessao)
            ->assertCreated()->json('dados.id');

        $resRef = $this->postJson("/api/licenca/subscricoes/{$subId}/iniciar-pagamento", ['metodo' => 'REFERENCIA'], $sessao)
            ->assertOk()->json('dados');

        return ['subscricaoId' => $subId, 'referencia' => str_replace(' ', '', $resRef['referencia'])];
    }

    private function assinatura(array $corpo, string $segredo): array
    {
        return ['X-Webhook-Assinatura' => 'sha256='.hash_hmac('sha256', json_encode($corpo), $segredo)];
    }

    public function test_webhook_sem_segredo_configurado_e_rejeitado(): void
    {
        $t = $this->prepararTransacao();
        DB::table('configuracoes_gateway')->where('chave', 'ref_webhook_segredo')->update(['valor' => '']);
        Log::spy();

        $corpo = ['referencia' => $t['referencia'], 'status' => 'paid', 'amount' => 10000];
        $this->postJson('/api/pagamentos/webhook/referencia', $corpo)->assertStatus(401)->assertJsonPath('sucesso', false);

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'sem segredo'))->once();
        $sub = app(ContextoEmpresa::class)->semIsolamento(fn () => SubscricaoEmpresa::query()->find($t['subscricaoId']));
        $this->assertSame(SubscricaoEmpresa::PENDENTE, $sub->estado);
    }

    public function test_webhook_com_assinatura_invalida_e_rejeitado(): void
    {
        $t = $this->prepararTransacao();
        DB::table('configuracoes_gateway')->where('chave', 'ref_webhook_segredo')->update(['valor' => 'segredo-correto']);
        Log::spy();

        $corpo = ['referencia' => $t['referencia'], 'status' => 'paid', 'amount' => 10000];
        $this->postJson('/api/pagamentos/webhook/referencia', $corpo, $this->assinatura($corpo, 'segredo-errado'))
            ->assertStatus(401)->assertJsonPath('sucesso', false);

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'assinatura inválida'))->once();
        $sub = app(ContextoEmpresa::class)->semIsolamento(fn () => SubscricaoEmpresa::query()->find($t['subscricaoId']));
        $this->assertSame(SubscricaoEmpresa::PENDENTE, $sub->estado);
    }

    public function test_webhook_sem_cabecalho_de_assinatura_e_rejeitado(): void
    {
        $t = $this->prepararTransacao();
        DB::table('configuracoes_gateway')->where('chave', 'ref_webhook_segredo')->update(['valor' => 'segredo-correto']);

        $this->postJson('/api/pagamentos/webhook/referencia', ['referencia' => $t['referencia'], 'status' => 'paid', 'amount' => 10000])
            ->assertStatus(401)->assertJsonPath('sucesso', false);
    }

    public function test_webhook_com_montante_divergente_nao_confirma_o_pagamento(): void
    {
        $t = $this->prepararTransacao();
        $segredo = 'segredo-correto';
        DB::table('configuracoes_gateway')->where('chave', 'ref_webhook_segredo')->update(['valor' => $segredo]);
        Log::spy();

        // o valor devido é 10000 (1 mês); o payload declara 1 (quase nada) — tentativa de fraude
        $corpo = ['referencia' => $t['referencia'], 'status' => 'paid', 'amount' => 1];
        $this->postJson('/api/pagamentos/webhook/referencia', $corpo, $this->assinatura($corpo, $segredo))
            ->assertStatus(400)->assertJsonPath('sucesso', false);

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'montante divergente'))->once();
        $sub = app(ContextoEmpresa::class)->semIsolamento(fn () => SubscricaoEmpresa::query()->find($t['subscricaoId']));
        $this->assertSame(SubscricaoEmpresa::PENDENTE, $sub->estado, 'Um montante divergente nunca pode confirmar o pagamento.');
        $transacao = app(ContextoEmpresa::class)->semIsolamento(
            fn () => TransacaoGateway::query()->where('subscricao_id', $t['subscricaoId'])->first()
        );
        $this->assertSame(TransacaoGateway::ESTADO_PENDENTE, $transacao->estado);
    }

    public function test_reenvio_do_mesmo_webhook_valido_nao_reprocessa_o_pagamento(): void
    {
        $t = $this->prepararTransacao();
        $segredo = 'segredo-correto';
        DB::table('configuracoes_gateway')->where('chave', 'ref_webhook_segredo')->update(['valor' => $segredo]);

        $corpo = ['referencia' => $t['referencia'], 'status' => 'paid', 'amount' => 10000];
        $assinatura = $this->assinatura($corpo, $segredo);

        $this->postJson('/api/pagamentos/webhook/referencia', $corpo, $assinatura)->assertOk()->assertJsonPath('sucesso', true);

        // reenvio (replay) do mesmo payload, já assinado e válido: idempotente, não prolonga a licença outra vez
        $this->postJson('/api/pagamentos/webhook/referencia', $corpo, $assinatura)
            ->assertOk()->assertJsonPath('sucesso', true)->assertJsonPath('mensagem', 'Notificação recebida, mas o pedido já não se encontra pendente.');

        $subFinal = app(ContextoEmpresa::class)->semIsolamento(fn () => SubscricaoEmpresa::query()->find($t['subscricaoId']));
        $this->assertSame(SubscricaoEmpresa::PAGO, $subFinal->estado);
    }
}
