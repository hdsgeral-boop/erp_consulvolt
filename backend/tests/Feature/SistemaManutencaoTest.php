<?php

namespace Tests\Feature;

use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\PedidoManutencaoEquipamento;
use App\Models\PlanoConta;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manutenção de dados (ADR-058): pedido → aprovação por OUTRO administrador (com a sua palavra-passe) → execução em 24 h;
 * as 5 acções com equivalente seguro (anular pendentes, estornar integrados, reconciliações órfãs, eliminar empresa vazia).
 */
final class SistemaManutencaoTest extends TestCase
{
    private Empresa $empresa;

    private Utilizador $pedinte;

    private array $sp;

    private array $sa;

    private string $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000301']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['4311' => 'Banco', '4511' => 'Caixa', '752' => 'Serviços'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
        });
        $perfil = $this->criarPerfil(['_v2' => true, 'config_manutencao_view' => true, 'teso_gestao_pagamentos_view' => true, 'teso_doc_emitir' => true,
            'teso_integrar' => true], 'Manutenção');
        $this->pedinte = $this->criarUtilizador(['nome_utilizador' => 'pedinte', 'papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfil->id]);
        $aprovador = $this->criarUtilizador(['nome_utilizador' => 'aprovador', 'papel' => Utilizador::PAPEL_ADMINISTRADOR, 'perfil_utilizador_id' => $perfil->id]);
        foreach ([$this->pedinte, $aprovador] as $u) {
            $u->empresas()->attach($this->empresa->id);
        }
        $this->sp = $this->entrar($this->pedinte) + ['X-Empresa-Id' => $this->empresa->id];
        $this->sa = $this->entrar($aprovador) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function documento(string $conta = '4311', ?string $data = null): int
    {
        return $this->postJson('/api/tesouraria/documentos', ['tipo' => 'PAGAMENTO', 'data_documento' => $data ?? $this->hoje, 'conta_financeira' => $conta,
            'descricao' => 'Despesa de serviços', 'linhas' => [['codigo_conta' => '752', 'tipo_dc' => 'D', 'valor' => 100]]], $this->sp)->assertCreated()->json('dados.id');
    }

    private function pedir(string $acao, array $parametros = []): int
    {
        return $this->postJson('/api/sistema/manutencao/pedidos', ['acao' => $acao, 'parametros' => $parametros, 'ciente' => true,
            'justificacao' => 'Refazer a importação de tesouraria deste mês.'], $this->sp)->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE')->json('dados.id');
    }

    private function aprovarEExecutar(int $id): void
    {
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026'], $this->sa)->assertOk();
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/executar", ['confirmacao' => "CONFIRMO #{$id}", 'copia_seguranca_confirmada' => true], $this->sp)
            ->assertOk()->assertJsonPath('dados.estado', 'EXECUTADO');
    }

    private function doc(int $id): DocumentoTesouraria
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DocumentoTesouraria::query()->findOrFail($id));
    }

    #[Test]
    public function catalogo_mapeia_as_13_accoes_e_recusa_as_nao_portadas(): void
    {
        $c = $this->getJson('/api/sistema/manutencao/acoes', $this->sp)->assertOk()->json('dados');
        $this->assertCount(5, $c['acoes']);
        $this->assertCount(8, $c['nao_portadas']);
        $legado = array_merge(array_column($c['acoes'], 'legado'), array_column($c['nao_portadas'], 'codigo_legado'));
        $this->assertCount(13, array_unique($legado));
        $this->postJson('/api/sistema/manutencao/pedidos', ['acao' => 'APAGAR_BD', 'justificacao' => str_repeat('x', 30), 'ciente' => true], $this->sp)
            ->assertStatus(422)->assertJsonPath('codigo', 'ACAO_NAO_PORTADA');
    }

    #[Test]
    public function fluxo_completo_anula_os_pendentes_com_segregacao_e_palavra_passe(): void
    {
        $d1 = $this->documento();
        $d2 = $this->documento('4511');
        $integrado = $this->documento();
        $this->postJson("/api/tesouraria/documentos/{$integrado}/integrar", [], $this->sp)->assertOk();

        $this->postJson('/api/sistema/manutencao/pedidos', ['acao' => 'ANULAR_PENDENTES_TESOURARIA', 'justificacao' => 'curta', 'ciente' => true], $this->sp)->assertStatus(422);
        $this->postJson('/api/sistema/manutencao/pedidos', ['acao' => 'ANULAR_PENDENTES_TESOURARIA', 'justificacao' => str_repeat('j', 25), 'ciente' => false], $this->sp)->assertStatus(422);
        $imp = $this->postJson('/api/sistema/manutencao/impacto', ['acao' => 'ANULAR_PENDENTES_TESOURARIA'], $this->sp)->assertOk();
        $this->assertSame(2, $imp->json('dados.linhas')['Documentos pendentes a anular']);
        $id = $this->pedir('ANULAR_PENDENTES_TESOURARIA');

        // quem pede não aprova; um não-administrador também não; palavra-passe errada fica na auditoria
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026'], $this->sp)->assertStatus(403)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $comum = $this->criarUtilizador(['perfil_utilizador_id' => $this->pedinte->perfil_utilizador_id]);
        $comum->empresas()->attach($this->empresa->id);
        $sc = $this->entrar($comum) + ['X-Empresa-Id' => $this->empresa->id];
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026'], $sc)->assertStatus(403);
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/aprovar", ['palavra_passe' => 'errada'], $this->sa)->assertStatus(422)->assertJsonPath('codigo', 'PALAVRA_PASSE_INCORRECTA');
        $this->assertSame(1, app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'Tentativa de aprovação falhada')->count()));

        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026', 'nota' => 'Visto.'], $this->sa)
            ->assertOk()->assertJsonPath('dados.estado', 'APROVADO')->assertJsonPath('dados.aprovado_por.nome_utilizador', 'aprovador');
        // execução: só quem pediu/aprovou, com a frase exacta e a cópia confirmada
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/executar", ['confirmacao' => "CONFIRMO #{$id}", 'copia_seguranca_confirmada' => true], $sc)->assertStatus(403);
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/executar", ['confirmacao' => 'CONFIRMO', 'copia_seguranca_confirmada' => true], $this->sp)->assertStatus(422);
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/executar", ['confirmacao' => "confirmo #{$id}", 'copia_seguranca_confirmada' => true], $this->sp)
            ->assertOk()->assertJsonPath('dados.resultado', '2 documento(s) anulado(s).');

        $this->assertSame(['ANULADO', 'ANULADO', 'INTEGRADO'], [$this->doc($d1)->estado, $this->doc($d2)->estado, $this->doc($integrado)->estado]);
        $this->assertStringContainsString("pedido #{$id}", $this->doc($d1)->motivo_anulacao);
        $p = $this->getJson("/api/sistema/manutencao/pedidos/{$id}", $this->sp)->assertOk();
        $this->assertCount(3, $p->json('dados.historico'));
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/executar", ['confirmacao' => "CONFIRMO #{$id}", 'copia_seguranca_confirmada' => true], $this->sp)->assertStatus(422);
    }

    #[Test]
    public function aprovacao_expira_em_24_horas_e_pedido_pendente_em_7_dias(): void
    {
        $this->documento();
        $id = $this->pedir('ANULAR_PENDENTES_TESOURARIA');
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/aprovar", ['palavra_passe' => 'Palavra#Passe2026'], $this->sa)->assertOk();
        $this->travel(25)->hours();
        $this->sp = $this->entrar($this->pedinte) + ['X-Empresa-Id' => $this->empresa->id];
        $this->getJson("/api/sistema/manutencao/pedidos/{$id}", $this->sp)->assertOk()->assertJsonPath('dados.estado', 'EXPIRADO')->assertJsonPath('dados.estado_gravado', 'APROVADO');
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/executar", ['confirmacao' => "CONFIRMO #{$id}", 'copia_seguranca_confirmada' => true], $this->sp)
            ->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_NAO_APROVADO');
        $this->assertSame('EXPIRADO', PedidoManutencaoEquipamento::query()->findOrFail($id)->estado);

        $outro = $this->pedir('ANULAR_PENDENTES_TESOURARIA');
        $this->travel(8)->days();
        $sa = $this->entrar(Utilizador::query()->where('nome_utilizador', 'aprovador')->firstOrFail()) + ['X-Empresa-Id' => $this->empresa->id];
        $this->postJson("/api/sistema/manutencao/pedidos/{$outro}/aprovar", ['palavra_passe' => 'Palavra#Passe2026'], $sa)->assertStatus(422)->assertJsonPath('codigo', 'PEDIDO_NAO_PENDENTE');
    }

    #[Test]
    public function rejeitar_e_cancelar(): void
    {
        $this->documento('4311', '2026-03-15');
        $this->documento();
        $id = $this->pedir('ANULAR_PENDENTES_TESOURARIA_DATA', ['data' => '2026-03-15']);
        $this->assertSame('Data: 2026-03-15', PedidoManutencaoEquipamento::query()->findOrFail($id)->resumo_parametros);
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/rejeitar", ['palavra_passe' => 'Palavra#Passe2026', 'motivo' => 'não'], $this->sa)->assertStatus(422);
        $this->postJson("/api/sistema/manutencao/pedidos/{$id}/rejeitar", ['palavra_passe' => 'Palavra#Passe2026', 'motivo' => 'Ainda há conferência por fazer.'], $this->sa)
            ->assertOk()->assertJsonPath('dados.estado', 'REJEITADO');

        $id2 = $this->pedir('ANULAR_PENDENTES_TESOURARIA_DATA', ['data' => '2026-03-15']);
        $this->postJson("/api/sistema/manutencao/pedidos/{$id2}/cancelar", ['motivo' => 'Engano no pedido'], $this->sa)->assertStatus(403);
        $this->postJson("/api/sistema/manutencao/pedidos/{$id2}/cancelar", ['motivo' => 'Engano no pedido'], $this->sp)->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
        $this->getJson('/api/sistema/manutencao/pedidos', $this->sp)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson('/api/sistema/manutencao/pedidos?estado=TODOS', $this->sp)->assertOk()->assertJsonCount(2, 'dados');
    }

    #[Test]
    public function estorna_integrados_e_retira_reconciliacoes_orfas(): void
    {
        $d = $this->documento();
        $this->postJson("/api/tesouraria/documentos/{$d}/integrar", [], $this->sp)->assertOk();
        $this->postJson('/api/sistema/manutencao/pedidos', ['acao' => 'DESINTEGRAR_TESOURARIA', 'parametros' => ['tipo' => 'OUTRO'], 'justificacao' => str_repeat('j', 25), 'ciente' => true], $this->sp)
            ->assertStatus(422)->assertJsonPath('codigo', 'PARAMETRO_INVALIDO');
        $this->aprovarEExecutar($this->pedir('DESINTEGRAR_TESOURARIA', ['tipo' => 'PAGAMENTO']));
        $this->assertSame('PENDENTE', $this->doc($d)->estado);
        $this->assertSame(1, DB::table('lancamentos_contabeis')->where('empresa_id', $this->empresa->id)->whereNotNull('estorno_de_id')->distinct()->count('numero_lan'));

        $base = ['empresa_id' => $this->empresa->id, 'tipo_dc' => 'D', 'valor' => 50, 'data_documento' => $this->hoje];
        DB::table('lancamentos_contabeis')->insert([$base + ['codigo_conta' => '4311', 'reconciliacao_codigo' => 'ORFA-1'], $base + ['codigo_conta' => '4311', 'reconciliacao_codigo' => 'BANCO-1'],
            $base + ['codigo_conta' => '752', 'reconciliacao_codigo' => 'COMP-1']]);
        DB::table('reconciliacoes_bancarias')->insert(['empresa_id' => $this->empresa->id, 'reconciliacao_codigo' => 'BANCO-1', 'estado' => 'CONCILIADO_BANCO', 'data' => now()]);
        $this->aprovarEExecutar($this->pedir('LIMPAR_RECONCILIACOES_ORFAS'));
        $codigos = DB::table('lancamentos_contabeis')->where('empresa_id', $this->empresa->id)->whereNotNull('reconciliacao_codigo')->orderBy('reconciliacao_codigo')->pluck('reconciliacao_codigo')->all();
        $this->assertSame(['BANCO-1', 'COMP-1'], $codigos);
    }

    #[Test]
    public function elimina_so_empresas_sem_movimentos(): void
    {
        $vazia = $this->criarEmpresa(['nif' => '5417000399']);
        $comDados = $this->criarEmpresa(['nif' => '5417000398']);
        DB::table('lancamentos_contabeis')->insert(['empresa_id' => $comDados->id, 'codigo_conta' => '4311', 'tipo_dc' => 'D', 'valor' => 1, 'data_documento' => $this->hoje]);
        $this->postJson('/api/sistema/manutencao/pedidos', ['acao' => 'ELIMINAR_EMPRESA', 'parametros' => ['empresa_id' => $comDados->id], 'justificacao' => str_repeat('j', 25), 'ciente' => true], $this->sp)
            ->assertStatus(422)->assertJsonPath('codigo', 'ACAO_BLOQUEADA');

        $id = $this->pedir('ELIMINAR_EMPRESA', ['empresa_id' => $vazia->id]);
        $this->assertNull(PedidoManutencaoEquipamento::query()->findOrFail($id)->empresa_id);
        $this->aprovarEExecutar($id);
        $this->assertSoftDeleted('empresas', ['id' => $vazia->id], deletedAtColumn: 'eliminado_em');
        $this->assertSame('INATIVO', DB::table('empresas')->where('id', $vazia->id)->value('estado'));
    }
}
