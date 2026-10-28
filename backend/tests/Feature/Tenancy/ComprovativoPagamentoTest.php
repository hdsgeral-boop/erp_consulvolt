<?php

namespace Tests\Feature\Tenancy;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\TransacaoGateway;
use App\Services\Pagamentos\ServicoComprovativosPagamento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Comprovativo de transferência bancária da subscrição SaaS (motor de precificação, Módulo C). */
class ComprovativoPagamentoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.licencas.ativo' => true]);
        Storage::fake(ServicoComprovativosPagamento::DISCO);
    }

    public function test_upload_aceite_para_imagem_e_cria_a_transacao_manual_pendente(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $gestor = $this->sessaoGestor($empresa);
        $id = $this->pedirRenovacao($gestor, 5000);

        $imagem = UploadedFile::fake()->image('comprovativo.png', 10, 10);
        $r = $this->postJson("/api/licenca/subscricoes/{$id}/comprovativo", ['comprovativo' => $imagem], $gestor)->assertCreated();

        $anexoId = $r->json('dados.id');
        // DB::table, não o modelo Eloquent: PertenceEmpresa exige um contexto de empresa activo, que já não existe
        // aqui fora do ciclo de um pedido HTTP (ver ResolverEmpresaAtiva — o contexto só vive durante o pedido).
        $anexo = DB::table('anexos_comprovativo_pagamento')->where('id', $anexoId)->first();
        Storage::disk(ServicoComprovativosPagamento::DISCO)->assertExists($anexo->caminho);
        $this->assertSame($empresa->id, (int) $anexo->empresa_id);

        $transacao = TransacaoGateway::query()->where('subscricao_id', $id)->first();
        $this->assertNotNull($transacao);
        $this->assertSame(TransacaoGateway::GATEWAY_MANUAL, $transacao->gateway);
        $this->assertSame(TransacaoGateway::ESTADO_PENDENTE, $transacao->estado);
        $this->assertSame(5000.0, (float) $transacao->valor);
        $this->assertSame($transacao->id, (int) $anexo->transacao_id);

        // Um segundo anexo da mesma subscrição reutiliza a mesma transacção MANUAL pendente, não cria outra.
        $outraImagem = UploadedFile::fake()->image('outra.png', 10, 10);
        $this->postJson("/api/licenca/subscricoes/{$id}/comprovativo", ['comprovativo' => $outraImagem], $gestor)->assertCreated();
        $this->assertSame(1, TransacaoGateway::query()->where('subscricao_id', $id)->count());
    }

    public function test_tipo_invalido_e_ficheiro_grande_sao_rejeitados(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $gestor = $this->sessaoGestor($empresa);
        $id = $this->pedirRenovacao($gestor, 3000);

        // Tipo não aceite, mesmo disfarçado com uma extensão plausível (o conteúdo é o que conta, não o nome).
        $executavel = UploadedFile::fake()->create('comprovativo.pdf', 10, 'application/x-msdownload');
        $this->postJson("/api/licenca/subscricoes/{$id}/comprovativo", ['comprovativo' => $executavel], $gestor)
            ->assertStatus(422)->assertJsonPath('codigo', 'ANEXO_TIPO_INVALIDO');

        // Maior que 5 MB: rejeitado pela validação da própria rota, antes de chegar ao serviço.
        $grande = UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf');
        $this->postJson("/api/licenca/subscricoes/{$id}/comprovativo", ['comprovativo' => $grande], $gestor)->assertStatus(422);

        $this->assertSame(0, DB::table('anexos_comprovativo_pagamento')->count());
    }

    public function test_so_se_pode_anexar_a_um_pedido_pendente(): void
    {
        $empresa = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $gestor = $this->sessaoGestor($empresa);
        $id = $this->pedirRenovacao($gestor, 2000);
        $this->deleteJson("/api/licenca/subscricoes/{$id}", [], $gestor)->assertOk();

        $imagem = UploadedFile::fake()->image('comprovativo.png', 10, 10);
        $this->postJson("/api/licenca/subscricoes/{$id}/comprovativo", ['comprovativo' => $imagem], $gestor)
            ->assertStatus(422)->assertJsonPath('codigo', 'SUBSCRICAO_NAO_PENDENTE');
    }

    public function test_download_do_comprovativo_e_isolado_por_empresa(): void
    {
        $empresaA = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $empresaB = $this->empresaComLicenca(now()->addDays(30)->toDateString());
        $gestorA = $this->sessaoGestor($empresaA);
        $gestorB = $this->sessaoGestor($empresaB);
        $id = $this->pedirRenovacao($gestorA, 4000);

        $imagem = UploadedFile::fake()->image('comprovativo.png', 10, 10);
        $anexoId = $this->postJson("/api/licenca/subscricoes/{$id}/comprovativo", ['comprovativo' => $imagem], $gestorA)->assertCreated()->json('dados.id');

        $this->getJson("/api/licenca/comprovativos/{$anexoId}", $gestorA)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson("/api/licenca/comprovativos/{$anexoId}", $gestorB)->assertNotFound();
    }

    private function pedirRenovacao(array $sessao, float $precoMensal): int
    {
        $plano = $this->plano($precoMensal);

        return $this->postJson('/api/licenca/renovacao', ['plano_id' => $plano->id, 'meses' => 1], $sessao)->assertCreated()->json('dados.id');
    }

    private function empresaComLicenca(?string $fim): Empresa
    {
        $e = $this->criarEmpresa();
        DB::table('empresas')->where('id', $e->id)->update(['licenca_origem' => Empresa::LICENCA_PLANO, 'licenca_fim' => $fim, 'licenca_inicio' => now()->subYear()->toDateString()]);

        return $e->fresh();
    }

    /** @param  array<string, mixed>  $extra */
    private function plano(float $precoMensal, array $extra = []): PlanoLicenca
    {
        return PlanoLicenca::query()->create($extra + ['codigo' => 'plano'.random_int(100, 99999), 'nome' => 'Plano', 'preco_mensal' => $precoMensal, 'moeda' => 'AOA', 'ativo' => true]);
    }

    /** @return array<string, mixed> */
    private function sessaoGestor(Empresa $empresa): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_licenca_gerir' => true, 'config_licenca_view' => true])->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }
}
