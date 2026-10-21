<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Segurança da cobrança SaaS: webhook assinado (HMAC), anti-replay, idempotência, valor/moeda exatos,
 * estado pendente, auditoria de todas as tentativas e segredos dos gateways nunca expostos.
 */
class WebhookPagamentoSeguroTest extends TestCase
{
    private const SEGREDO_WEBHOOK = 'segredo-webhook-de-teste-123456';

    private const CHAVE_API = 'CHAVE-API-ULTRA-SECRETA-987654';

    /** @return array{0:int,1:string,2:array} id da subscrição, referência Multicaixa (sem espaços) e sessão da empresa */
    private function cenario(): array
    {
        // O super admin cria-se e entra com o SaaS desligado (como nos restantes testes); só depois se liga.
        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));
        config(['erp.saas.ativo' => true]);

        $this->putJson('/api/plataforma/pagamentos/config', [
            'referencia' => ['chave_api' => self::CHAVE_API, 'webhook_segredo' => self::SEGREDO_WEBHOOK],
            'express' => ['webhook_segredo' => self::SEGREDO_WEBHOOK],
        ], $super)->assertOk();

        $plano = PlanoLicenca::query()->firstOrCreate(['codigo' => 'teste_seguro'], [
            'nome' => 'Plano Seguro', 'preco_mensal' => 10000, 'moeda' => 'AOA', 'ativo' => true,
        ]);

        app(ContextoTenant::class)->definir(1);
        $empresa = $this->criarEmpresa([
            'nif' => '5000000066',
            'licenca_origem' => Empresa::LICENCA_PLANO,
            'licenca_fim' => now()->addDays(5)->toDateString(),
        ]);
        $perfil = $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true]);
        $u = $this->criarUtilizador(['papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);
        $sessao = $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];

        $subId = $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 2], $sessao)->assertCreated()->json('dados.id');
        $ref = $this->postJson("/api/licenca/subscricoes/{$subId}/iniciar-pagamento", ['metodo' => 'REFERENCIA'], $sessao)->assertOk()->json('dados.referencia');

        return [(int) $subId, str_replace(' ', '', (string) $ref), $sessao];
    }

    /** Envia o webhook assinado (ou não) sobre o corpo JSON bruto. */
    private function enviar(array $corpo, ?string $evento = 'evt-1', ?int $timestamp = null, string $segredo = self::SEGREDO_WEBHOOK, string $gateway = 'referencia'): TestResponse
    {
        $bruto = json_encode($corpo);
        $ts = (string) ($timestamp ?? time());
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$bruto, $segredo),
        ];
        if ($evento !== null) {
            $server['HTTP_X_WEBHOOK_EVENT_ID'] = $evento;
        }

        return $this->call('POST', "/api/pagamentos/webhook/{$gateway}", [], [], [], $server, $bruto);
    }

    private function subscricao(int $id): SubscricaoEmpresa
    {
        return app(ContextoEmpresa::class)->semIsolamento(fn () => SubscricaoEmpresa::query()->findOrFail($id));
    }

    private function corpoValido(string $ref, array $extra = []): array
    {
        return $extra + ['referencia' => $ref, 'status' => 'paid', 'amount' => 20000, 'moeda' => 'AOA'];
    }

    public function test_webhook_valido_confirma_e_audita(): void
    {
        [$subId, $ref] = $this->cenario();

        $this->enviar($this->corpoValido($ref))->assertOk()->assertJsonPath('sucesso', true);

        $this->assertSame(SubscricaoEmpresa::PAGO, $this->subscricao($subId)->estado);
        $this->assertSame(1, DB::table('webhooks_pagamento')->where('resultado', 'ACEITE')->where('evento_id', 'evt-1')->count());
    }

    public function test_assinatura_invalida_e_rejeitada_e_auditada(): void
    {
        [$subId, $ref] = $this->cenario();

        $this->enviar($this->corpoValido($ref), 'evt-x', null, 'outro-segredo-qualquer-123')->assertStatus(401);

        $this->assertSame(SubscricaoEmpresa::PENDENTE, $this->subscricao($subId)->estado);
        $log = DB::table('webhooks_pagamento')->where('evento_id', 'evt-x')->first();
        $this->assertSame('REJEITADO', $log->resultado);
        $this->assertFalse((bool) $log->assinatura_valida);
    }

    public function test_replay_fora_da_janela_e_rejeitado(): void
    {
        [$subId, $ref] = $this->cenario();

        $this->enviar($this->corpoValido($ref), 'evt-velho', time() - 3600)->assertStatus(400);

        $this->assertSame(SubscricaoEmpresa::PENDENTE, $this->subscricao($subId)->estado);
        $this->assertSame(1, DB::table('webhooks_pagamento')->where('evento_id', 'evt-velho')->where('resultado', 'REJEITADO')->count());
    }

    public function test_evento_duplicado_e_rejeitado(): void
    {
        [$subId, $ref] = $this->cenario();

        $this->enviar($this->corpoValido($ref), 'evt-dup')->assertOk();
        $fimDepoisDoPrimeiro = Empresa::query()->findOrFail($this->subscricao($subId)->empresa_id)->licenca_fim;

        $this->enviar($this->corpoValido($ref), 'evt-dup')->assertStatus(409);

        // A licença não foi prolongada uma segunda vez
        $this->assertEquals($fimDepoisDoPrimeiro, Empresa::query()->findOrFail($this->subscricao($subId)->empresa_id)->licenca_fim);
        $this->assertSame(1, DB::table('webhooks_pagamento')->where('evento_id', 'evt-dup')->where('resultado', 'ACEITE')->count());
        $this->assertSame(1, DB::table('webhooks_pagamento')->where('evento_id', 'evt-dup')->where('resultado', 'REJEITADO')->count());
    }

    public function test_evento_sem_identificador_e_rejeitado(): void
    {
        [$subId, $ref] = $this->cenario();

        $this->enviar($this->corpoValido($ref), null)->assertStatus(400);

        $this->assertSame(SubscricaoEmpresa::PENDENTE, $this->subscricao($subId)->estado);
    }

    public function test_valor_diferente_e_rejeitado(): void
    {
        [$subId, $ref] = $this->cenario();

        $this->enviar($this->corpoValido($ref, ['amount' => 100]), 'evt-valor')->assertStatus(422);

        $this->assertSame(SubscricaoEmpresa::PENDENTE, $this->subscricao($subId)->estado);
        $this->assertSame(1, DB::table('webhooks_pagamento')->where('evento_id', 'evt-valor')->where('resultado', 'REJEITADO')->count());
    }

    public function test_moeda_diferente_ou_em_falta_e_rejeitada(): void
    {
        [$subId, $ref] = $this->cenario();

        $this->enviar($this->corpoValido($ref, ['moeda' => 'USD']), 'evt-moeda')->assertStatus(422);
        $corpo = $this->corpoValido($ref);
        unset($corpo['moeda']);
        $this->enviar($corpo, 'evt-sem-moeda')->assertStatus(422);

        $this->assertSame(SubscricaoEmpresa::PENDENTE, $this->subscricao($subId)->estado);
    }

    public function test_subscricao_nao_pendente_e_rejeitada(): void
    {
        [$subId, $ref] = $this->cenario();
        app(ContextoEmpresa::class)->semIsolamento(
            fn () => SubscricaoEmpresa::query()->whereKey($subId)->update(['estado' => SubscricaoEmpresa::CANCELADO])
        );

        $this->enviar($this->corpoValido($ref), 'evt-cancelada')->assertStatus(409);

        $this->assertSame(SubscricaoEmpresa::CANCELADO, $this->subscricao($subId)->estado);
        $this->assertSame(1, DB::table('webhooks_pagamento')->where('evento_id', 'evt-cancelada')->where('resultado', 'REJEITADO')->count());
    }

    public function test_gateway_diferente_da_transacao_e_rejeitado(): void
    {
        [$subId, $ref] = $this->cenario();

        // Referência da transação REFERENCIA enviada pelo canal EXPRESS: não corresponde
        $this->enviar($this->corpoValido($ref, ['id_transacao' => 'inexistente']), 'evt-gw', null, self::SEGREDO_WEBHOOK, 'express')->assertStatus(422);

        $this->assertSame(SubscricaoEmpresa::PENDENTE, $this->subscricao($subId)->estado);
    }

    public function test_segredos_dos_gateways_nao_sao_expostos_e_ficam_cifrados(): void
    {
        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));

        $resposta = $this->putJson('/api/plataforma/pagamentos/config', [
            'referencia' => ['chave_api' => self::CHAVE_API, 'webhook_segredo' => self::SEGREDO_WEBHOOK],
        ], $super)->assertOk();

        $resposta->assertJsonPath('dados.referencia.configurado', true);
        $this->assertStringNotContainsString(self::CHAVE_API, $resposta->getContent());
        $this->assertStringNotContainsString(self::SEGREDO_WEBHOOK, $resposta->getContent());
        $this->assertStringNotContainsString(substr(self::CHAVE_API, -4), (string) $resposta->json('dados.referencia.chave_api'));

        $leitura = $this->getJson('/api/plataforma/pagamentos/config', $super)->assertOk();
        $leitura->assertJsonPath('dados.referencia.configurado', true);
        $this->assertStringNotContainsString(self::CHAVE_API, $leitura->getContent());

        // Em repouso: cifrado, nunca em texto simples
        $guardado = (string) DB::table('configuracoes_gateway')->where('chave', 'ref_chave_api')->value('valor');
        $this->assertStringStartsWith('enc:', $guardado);
        $this->assertStringNotContainsString(self::CHAVE_API, $guardado);
    }

    public function test_simulacao_de_pagamento_desativada_em_producao(): void
    {
        [$subId, , $sessao] = $this->cenario();

        $this->app['env'] = 'production';

        $this->postJson("/api/licenca/subscricoes/{$subId}/simular-confirmacao", [], $sessao)->assertNotFound();
        $this->assertSame(SubscricaoEmpresa::PENDENTE, $this->subscricao($subId)->estado);
    }
}
