<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Afinação da Fase 5 (ADR-064) — Tesouraria: paginação, terceiro nas linhas, integração em lote, disponibilidades e extracto. */
final class AfinacaoTesourariaTest extends TestCase
{
    private Empresa $empresa;

    private Terceiro $fornecedor;

    private array $s;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['3211' => 'Fornecedores', '4311' => 'Banco BAI', '4511' => 'Caixa', '752' => 'Serviços'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->fornecedor = Terceiro::create(['nome' => 'Fornecedor B', 'nif' => '5000000002', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '3211']);
        });
        $this->s = $this->sessao(['teso_gestao_pagamentos_view', 'teso_doc_emitir', 'teso_doc_eliminar', 'teso_integrar', 'teso_folha_caixa_view', 'teso_caixa_operar']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function documento(string $tipo, string $conta, string $valor): int
    {
        return $this->postJson('/api/tesouraria/documentos', ['tipo' => $tipo, 'data_documento' => $this->hoje, 'conta_financeira' => $conta,
            'descricao' => 'Serviços diversos', 'linhas' => [['codigo_conta' => '752', 'tipo_dc' => $tipo === 'PAGAMENTO' ? 'D' : 'C', 'valor' => $valor,
                'terceiro_id' => $this->fornecedor->id]]], $this->s)->assertCreated()->assertJsonPath('dados.linhas.0.terceiro.nome', 'Fornecedor B')
            ->assertJsonPath('dados.linhas.0.terceiro.nif', '5000000002')->json('dados.id');
    }

    #[Test]
    public function documentos_paginados_e_integracao_em_lote(): void
    {
        $d1 = $this->documento('PAGAMENTO', '4311', '100');
        $d2 = $this->documento('PAGAMENTO', '4311', '250.50');
        $d3 = $this->documento('PAGAMENTO', '4311', '10');
        $this->postJson("/api/tesouraria/documentos/{$d3}/anular", ['motivo' => 'Duplicado'], $this->s)->assertOk();

        $this->getJson('/api/tesouraria/documentos?por_pagina=2', $this->s)->assertOk()->assertJsonCount(2, 'dados')
            ->assertJsonPath('metadados.paginacao.total', 3)->assertJsonPath('metadados.paginacao.ultima_pagina', 2);
        $this->getJson('/api/tesouraria/documentos?estado=ANULADO', $this->s)->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $d3);

        $r = $this->postJson('/api/tesouraria/documentos/integrar', ['ids' => [$d1, $d2, $d3, $d1, 999999]], $this->s)->assertOk()
            ->assertJsonCount(2, 'dados.integrados')->assertJsonCount(2, 'dados.erros')
            ->assertJsonPath('dados.erros.0.id', $d3)->assertJsonPath('dados.erros.0.codigo', 'DOCUMENTO_NAO_INTEGRAVEL')
            ->assertJsonPath('dados.erros.1.codigo', 'DOCUMENTO_INEXISTENTE');
        $this->assertNotEmpty($r->json('dados.integrados.0.numero_lan_contabilizacao'));
        $this->getJson("/api/tesouraria/documentos/{$d2}", $this->s)->assertJsonPath('dados.estado', 'INTEGRADO');
        $this->getJson('/api/tesouraria/documentos?estado=INTEGRADO', $this->s)->assertJsonCount(2, 'dados');

        // sem a permissão de integrar
        $leitor = $this->sessao(['teso_gestao_pagamentos_view']);
        $this->postJson('/api/tesouraria/documentos/integrar', ['ids' => [$d1]], $leitor)->assertStatus(403);
        $this->postJson('/api/tesouraria/documentos/integrar', ['ids' => []], $this->s)->assertStatus(422);
    }

    #[Test]
    public function disponibilidades_e_extracto_de_conta(): void
    {
        $p1 = $this->documento('PAGAMENTO', '4311', '100');
        $p2 = $this->documento('PAGAMENTO', '4311', '250.50');
        $r1 = $this->documento('RECEBIMENTO', '4511', '500');
        $this->postJson('/api/tesouraria/documentos/integrar', ['ids' => [$p1, $p2, $r1]], $this->s)->assertOk()->assertJsonCount(3, 'dados.integrados');

        $mapas = $this->sessao(['teso_gestao_mapas_view']);
        $this->getJson('/api/tesouraria/disponibilidades?data='.$this->hoje, $mapas)->assertOk()
            ->assertJsonPath('dados.contas.0.codigo_conta', '4311')->assertJsonPath('dados.contas.0.tipo', 'BANCO')->assertJsonPath('dados.contas.0.saldo', '-350.50')
            ->assertJsonPath('dados.contas.1.codigo_conta', '4511')->assertJsonPath('dados.contas.1.tipo', 'CAIXA')->assertJsonPath('dados.contas.1.saldo', '500.00')
            ->assertJsonPath('dados.totais.bancos', '-350.50')->assertJsonPath('dados.totais.caixa', '500.00')->assertJsonPath('dados.totais.total', '149.50')
            ->assertJsonCount(2, 'dados.contas');
        // à data anterior não havia movimentos
        $this->getJson('/api/tesouraria/disponibilidades?data='.now()->subDay()->toDateString(), $mapas)->assertJsonCount(0, 'dados.contas')
            ->assertJsonPath('dados.totais.total', '0.00');

        $this->getJson("/api/tesouraria/extrato-conta?codigo_conta=4311&data_inicio={$this->hoje}&data_fim={$this->hoje}", $mapas)->assertOk()
            ->assertJsonPath('dados.descricao', 'Banco BAI')->assertJsonPath('dados.saldo_inicial', '0.00')->assertJsonPath('dados.credito', '350.50')
            ->assertJsonPath('dados.saldo_final', '-350.50')->assertJsonCount(2, 'dados.movimentos')
            ->assertJsonPath('dados.movimentos.0.saldo', '-100.00')->assertJsonPath('dados.movimentos.1.saldo', '-350.50');
        // só contas de disponibilidades (43/45); período válido; permissão própria do ecrã
        $this->getJson("/api/tesouraria/extrato-conta?codigo_conta=3211&data_inicio={$this->hoje}&data_fim={$this->hoje}", $mapas)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_NAO_DISPONIBILIDADES');
        $this->getJson("/api/tesouraria/extrato-conta?codigo_conta=4311&data_inicio={$this->hoje}&data_fim=2000-01-01", $mapas)->assertStatus(422);
        $this->getJson('/api/tesouraria/disponibilidades', $this->s)->assertStatus(403);
    }

    #[Test]
    public function movimentos_de_caixa_trazem_o_terceiro(): void
    {
        $sessao = $this->postJson('/api/tesouraria/caixa/sessoes', ['codigo_conta' => '4511', 'data' => $this->hoje, 'saldo_abertura' => 0], $this->s)
            ->assertCreated()->json('dados.id');
        $this->postJson("/api/tesouraria/caixa/sessoes/{$sessao}/movimentos", ['tipo' => 'PAG', 'data_documento' => $this->hoje, 'conta_contrapartida' => '752',
            'valor' => 0.5, 'descricao' => 'Despesa', 'terceiro_id' => $this->fornecedor->id], $this->s)->assertCreated()
            ->assertJsonPath('dados.movimentos.0.terceiro.nome', 'Fornecedor B')->assertJsonPath('dados.movimentos.0.terceiro.id', $this->fornecedor->id);
        $this->getJson("/api/tesouraria/caixa/sessoes/{$sessao}", $this->s)->assertJsonPath('dados.movimentos.0.terceiro.nif', '5000000002');
    }
}
