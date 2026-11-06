<?php

namespace Tests\Feature;

use App\Models\Armazem;
use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F7 do POS: configuração de impressão na BD, por empresa e com sobreposição por terminal (valores efectivos =
 * padrão < empresa < terminal), validação, permissões, isolamento entre empresas e auditoria.
 */
final class POSConfigImpressaoTest extends TestCase
{
    private Empresa $empresa;

    private array $gestor;

    private array $operador;

    private int $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000099']);
        $armazem = app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['489' => 'Transitória numerário', '4511' => 'Caixa'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }

            return Armazem::create(['nome' => 'Loja', 'predefinido' => true])->id;
        });
        $this->gestor = $this->sessao(['pos_view', 'pos_print_config', 'pos_terminais_gerir']);
        $this->operador = $this->sessao(['pos_venda']);
        $this->terminal = $this->postJson('/api/pos/terminais', ['codigo' => 'T01', 'nome' => 'Loja 1', 'armazem_id' => $armazem, 'fundo_maneio_padrao' => 0,
            'meios_pagamento' => [['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '4511']]], $this->gestor)->assertCreated()->json('dados.id');
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    #[Test]
    public function sem_configuracao_devolve_o_padrao(): void
    {
        $r = $this->getJson('/api/pos/impressao/config', $this->operador)->assertOk();
        $r->assertJsonPath('dados.guardada', false)->assertJsonPath('dados.efectiva.formato', 'TERMICO')->assertJsonPath('dados.efectiva.largura', 80)
            ->assertJsonPath('dados.efectiva.rodape', 'Obrigado pela preferência!');
    }

    #[Test]
    public function grava_por_empresa_e_o_terminal_sobrepoe_so_o_que_preenche(): void
    {
        $this->putJson('/api/pos/impressao/config', ['formato' => 'A4', 'largura' => 58, 'automatico' => false, 'consulta' => 'DIRECTO', 'rodape' => 'Volte sempre'], $this->gestor)
            ->assertOk()->assertJsonPath('dados.empresa.formato', 'A4')->assertJsonPath('dados.efectiva.automatico', false);
        $this->assertTrue($this->terminal > 0);

        $this->putJson('/api/pos/impressao/config', ['terminal_pos_id' => $this->terminal, 'formato' => 'TERMICO'], $this->gestor)->assertOk()
            ->assertJsonPath('dados.terminal.formato', 'TERMICO')->assertJsonPath('dados.terminal.largura', null);
        $ef = $this->getJson('/api/pos/impressao/config?terminal_pos_id='.$this->terminal, $this->operador)->assertOk()->json('dados.efectiva');
        $this->assertSame(['formato' => 'TERMICO', 'largura' => 58, 'automatico' => false, 'consulta' => 'DIRECTO', 'rodape' => 'Volte sempre'], $ef);
        // sem terminal continua a ser o da empresa
        $this->getJson('/api/pos/impressao/config', $this->operador)->assertJsonPath('dados.efectiva.formato', 'A4');

        $this->deleteJson('/api/pos/impressao/config/terminal/'.$this->terminal, [], $this->gestor)->assertOk()->assertJsonPath('dados.terminal', null)->assertJsonPath('dados.efectiva.formato', 'A4');
        $this->assertSame(1, \DB::table('configuracoes_impressao_pos')->count());
    }

    #[Test]
    public function regras_de_validacao_e_permissoes(): void
    {
        $this->putJson('/api/pos/impressao/config', ['formato' => 'PDF'], $this->gestor)->assertStatus(422);
        $this->putJson('/api/pos/impressao/config', ['largura' => 70], $this->gestor)->assertStatus(422);
        $this->putJson('/api/pos/impressao/config', ['consulta' => 'X'], $this->gestor)->assertStatus(422);
        $this->putJson('/api/pos/impressao/config', ['rodape' => str_repeat('a', 201)], $this->gestor)->assertStatus(422);
        $this->putJson('/api/pos/impressao/config', ['terminal_pos_id' => 99999], $this->gestor)->assertStatus(404);
        $this->putJson('/api/pos/impressao/config', ['formato' => 'A4'], $this->operador)->assertStatus(403);
        $this->deleteJson('/api/pos/impressao/config/terminal/'.$this->terminal, [], $this->operador)->assertStatus(403);
        $semAcesso = $this->sessao(['teso_view']);
        $this->getJson('/api/pos/impressao/config', $semAcesso)->assertStatus(403);
        // o rodapé não guarda marcação
        $this->putJson('/api/pos/impressao/config', ['rodape' => '<b>Obrigado</b><script>x</script>'], $this->gestor)->assertOk();
        $this->assertStringNotContainsString('<', $this->getJson('/api/pos/impressao/config', $this->operador)->json('dados.efectiva.rodape'));
    }

    #[Test]
    public function isolada_por_empresa_e_auditada(): void
    {
        $this->putJson('/api/pos/impressao/config', ['formato' => 'A4', 'rodape' => 'Empresa A'], $this->gestor)->assertOk();
        $outra = $this->criarEmpresa(['nif' => '5417000107']);
        $s = $this->sessao(['pos_view', 'pos_print_config'], $outra);
        $this->getJson('/api/pos/impressao/config', $s)->assertOk()->assertJsonPath('dados.guardada', false)->assertJsonPath('dados.efectiva.formato', 'TERMICO');
        // terminal de outra empresa não é aceite
        $this->putJson('/api/pos/impressao/config', ['terminal_pos_id' => $this->terminal, 'formato' => 'A4'], $s)->assertStatus(404);
        $logs = app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('tabela', 'configuracoes_impressao_pos')->get());
        $this->assertCount(1, $logs);
        $this->assertSame('Criou', $logs[0]->acao);
    }
}
