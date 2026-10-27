<?php

namespace Tests\Feature;

use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase A (Versão Actual) — pagamentos e recebimentos só por bancos ou cartão (legado novo ui_tesouraria.js:653-678 e
 * :861-877, estendido pela conta-cartão de tesouraria). Contas 42/43 e contas-cartão (classe 3 marcada) nos
 * documentos novos; o numerário (45) vai à Folha de Caixa; documentos anteriores em 45 continuam editáveis.
 */
final class FaTesourariaSoBancosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['4211' => 'Depósito a prazo', '4311' => 'Banco Ficticio', '4511' => 'Caixa sede', '4512' => 'Caixa loja', '752' => 'Serviços', '611' => 'Vendas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
        });
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys(['teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_integrar', 'teso_desintegrar'], true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function corpo(string $conta, string $tipo = 'PAGAMENTO'): array
    {
        return ['tipo' => $tipo, 'data_documento' => $this->hoje, 'conta_financeira' => $conta, 'descricao' => 'Pagamento de serviços ficticio',
            'linhas' => [['codigo_conta' => $tipo === 'PAGAMENTO' ? '752' : '611', 'tipo_dc' => $tipo === 'PAGAMENTO' ? 'D' : 'C', 'valor' => '1500']]];
    }

    private function documento(string $conta, string $tipo = 'PAGAMENTO'): TestResponse
    {
        return $this->postJson('/api/tesouraria/documentos', $this->corpo($conta, $tipo), $this->s);
    }

    /** Documento anterior (migrado) numa conta de caixa — gravado pelo serviço sem a regra do ecrã, como os circuitos internos. */
    private function documentoAnteriorEmCaixa(): int
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoDocumentosTesouraria::class)->gravar($this->corpo('4511'))->id);
    }

    #[Test]
    public function documentos_novos_so_aceitam_contas_bancarias_42_e_43(): void
    {
        foreach (['PAGAMENTO', 'RECEBIMENTO'] as $tipo) {
            $this->documento('4511', $tipo)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_FINANCEIRA_SO_BANCOS')
                ->assertJsonPath('mensagem', 'Os pagamentos e recebimentos só movimentam contas bancárias (42 depósitos a prazo, 43 depósitos à ordem) ou um cartão de tesouraria. O numerário (45) passou para a Folha de Caixa.');
        }
        $this->documento('611')->assertStatus(422)->assertJsonPath('codigo', 'CONTA_FINANCEIRA_SO_BANCOS');
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DocumentoTesouraria::query()->count()));

        // 43 (depósitos à ordem) e 42 (depósitos a prazo) — ambas vão ao diário de bancos
        foreach (['4311', '4211'] as $conta) {
            $id = $this->documento($conta)->assertCreated()->assertJsonPath('dados.conta_financeira', $conta)->json('dados.id');
            $i = $this->postJson("/api/tesouraria/documentos/{$id}/integrar", [], $this->s)->assertOk();
            $this->assertStringStartsWith('BD', $i->json('dados.numero_lan_contabilizacao'));
        }
    }

    #[Test]
    public function documento_novo_numa_conta_cartao_e_aceite_e_vai_ao_diario_de_bancos(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PlanoConta::create(['codigo' => '3282', 'descricao' => 'Cartão de crédito BAI',
            'tipo' => 'M', 'cartao_tesouraria' => true, 'cartao_banco' => 'BAI']));
        $id = $this->documento('3282')->assertCreated()->assertJsonPath('dados.conta_financeira', '3282')->json('dados.id');
        $i = $this->postJson("/api/tesouraria/documentos/{$id}/integrar", [], $this->s)->assertOk();
        $this->assertStringStartsWith('BD', $i->json('dados.numero_lan_contabilizacao'));
    }

    #[Test]
    public function documento_novo_em_banco_nao_pode_passar_para_caixa(): void
    {
        $id = $this->documento('4311')->assertCreated()->json('dados.id');
        $this->putJson("/api/tesouraria/documentos/{$id}", $this->corpo('4511'), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_FINANCEIRA_SO_BANCOS');
        $this->putJson("/api/tesouraria/documentos/{$id}", $this->corpo('4211'), $this->s)->assertOk()->assertJsonPath('dados.conta_financeira', '4211');
    }

    #[Test]
    public function documento_anterior_em_caixa_continua_legivel_editavel_e_integravel(): void
    {
        $id = $this->documentoAnteriorEmCaixa();
        $this->getJson("/api/tesouraria/documentos/{$id}", $this->s)->assertOk()->assertJsonPath('dados.conta_financeira', '4511');
        // corrige-se mantendo uma conta de caixa («Caixa (45) — documento anterior»)
        $this->putJson("/api/tesouraria/documentos/{$id}", ['descricao' => 'Correcção do documento anterior'] + $this->corpo('4512'), $this->s)->assertOk()
            ->assertJsonPath('dados.conta_financeira', '4512');
        $i = $this->postJson("/api/tesouraria/documentos/{$id}/integrar", [], $this->s)->assertOk();
        $this->assertStringStartsWith('CX', $i->json('dados.numero_lan_contabilizacao'));
        $this->postJson("/api/tesouraria/documentos/{$id}/desintegrar", ['motivo' => 'Correcção de teste'], $this->s)->assertOk();

        // também pode passar para um banco; depois disso já não volta à caixa
        $this->putJson("/api/tesouraria/documentos/{$id}", $this->corpo('4311'), $this->s)->assertOk()->assertJsonPath('dados.conta_financeira', '4311');
        $this->putJson("/api/tesouraria/documentos/{$id}", $this->corpo('4511'), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_FINANCEIRA_SO_BANCOS');
    }

    #[Test]
    public function copiar_um_documento_anterior_em_caixa_obriga_a_escolher_um_banco(): void
    {
        $id = $this->documentoAnteriorEmCaixa();
        $origem = $this->getJson("/api/tesouraria/documentos/{$id}", $this->s)->assertOk()->json('dados');
        // a cópia é um documento NOVO (POST): a conta de caixa já não é aceite
        $this->documento($origem['conta_financeira'])->assertStatus(422)->assertJsonPath('codigo', 'CONTA_FINANCEIRA_SO_BANCOS');
    }
}
