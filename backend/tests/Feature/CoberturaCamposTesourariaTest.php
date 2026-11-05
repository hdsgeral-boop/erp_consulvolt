<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auditoria de cobertura de campos (docs/paridade/COBERTURA_CAMPOS_2026-11.md), lote Tesouraria:
 * movimentos_caixa.url_documento (registo, folha e lançamento) e conferencias_caixa.nome_conta_regularizacao. Dados fictícios.
 */
final class CoberturaCamposTesourariaTest extends TestCase
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
            foreach (['4511' => 'Caixa sede', '611' => 'Vendas', '752' => 'Serviços', '6881' => 'Outros proveitos', '7881' => 'Outros custos'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
        });
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys(['teso_folha_caixa_view', 'teso_caixa_operar', 'teso_caixa_fechar', 'teso_caixa_contabilizar',
            'teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_integrar', 'teso_gestao_conferencia_view', 'teso_conf_registar'], true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function movimento_de_caixa_guarda_o_url_do_documento_e_leva_o_ao_lancamento(): void
    {
        $s = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje], $this->s)->assertCreated()->json('dados.id');
        $mov = fn (array $d) => $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/movimentos", $d + ['tipo' => 'PAG', 'data_documento' => $this->hoje, 'descricao' => 'Despesa',
            'conta_contrapartida' => '752', 'valor' => 200], $this->s);

        $mov(['url_documento' => 'javascript:alert(1)'])->assertStatus(422);
        $mov(['url_documento' => 'ftp://exemplo.ao/recibo.pdf'])->assertStatus(422);
        $mov(['url_documento' => 'https://exemplo.ao/'.str_repeat('x', 150)])->assertStatus(422);
        $mov([])->assertCreated();   // opcional
        $r = $mov(['url_documento' => 'https://exemplo.ao/recibos/123.pdf'])->assertCreated();
        $linhas = collect($r->json('dados.movimentos'));
        $this->assertSame(['https://exemplo.ao/recibos/123.pdf'], $linhas->pluck('url_documento')->filter()->values()->all());

        $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/fechar", ['saldo_fisico' => 0, 'data' => $this->hoje], $this->s)->assertOk();
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['caixa_sobras' => '6881', 'caixa_quebras' => '7881']], $this->s)->assertOk();
        $lan = $this->postJson("/api/tesouraria/caixa/sessoes/{$s}/contabilizar", [], $this->s)->assertOk()->json('dados.numeros_lan_contabilizacao.0');
        $urls = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $lan)->pluck('url_documento')->filter()->unique()->values()->all());
        $this->assertSame(['https://exemplo.ao/recibos/123.pdf'], $urls);
    }

    #[Test]
    public function conferencia_finalizada_com_regularizacao_guarda_o_nome_da_conta(): void
    {
        $this->putJson('/api/tesouraria/configuracao/contas', ['contas' => ['caixa_sobras' => '6881', 'caixa_quebras' => '7881']], $this->s)->assertOk();
        $doc = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoDocumentosTesouraria::class)->gravar(['tipo' => 'RECEBIMENTO', 'data_documento' => $this->hoje,
            'conta_financeira' => '4511', 'descricao' => 'Recebimento', 'linhas' => [['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '2000']]])->id);
        $this->postJson("/api/tesouraria/documentos/{$doc}/integrar", [], $this->s)->assertOk();
        $corpo = ['codigo_conta' => '4511', 'data_conferencia' => $this->hoje, 'denominacoes' => ['N1000' => 2, 'M100' => 3]];
        $c = $this->postJson('/api/tesouraria/conferencias', $corpo, $this->s)->assertCreated()->assertJsonPath('dados.nome_conta_regularizacao', null)->json('dados.id');
        $this->putJson("/api/tesouraria/conferencias/{$c}", $corpo + ['justificacao' => 'Recebimento em numerário não registado'], $this->s)->assertOk();
        $this->postJson("/api/tesouraria/conferencias/{$c}/finalizar", ['regularizar' => true], $this->s)->assertOk()
            ->assertJsonPath('dados.conta_regularizacao', '6881')->assertJsonPath('dados.nome_conta_regularizacao', 'Outros proveitos');
        $this->getJson('/api/tesouraria/conferencias', $this->s)->assertOk()->assertJsonPath('dados.0.nome_conta_regularizacao', 'Outros proveitos');
    }
}
