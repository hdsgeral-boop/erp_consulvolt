<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Afinação da Fase 5 (ADR-064): resumo dos relatórios de vendas em SQL e validação do SAF-T com erro em JSON. */
final class AfinacaoVendasTest extends TestCase
{
    private Empresa $empresa;

    private int $clienteA;

    private int $clienteB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000777']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $this->clienteA = Terceiro::create(['nome' => 'Cliente A', 'nif' => '5000000701', 'tipo' => Terceiro::CLIENTE])->id;
            $this->clienteB = Terceiro::create(['nome' => 'Cliente B', 'nif' => '5000000702', 'tipo' => Terceiro::CLIENTE])->id;
        });
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    /** Documento inserido directamente (os totais são o que interessa ao resumo). */
    private function documento(string $tipo, string $data, int $cliente, string $liquido, string $imposto, string $pendente = '0', ?string $estado = 'PENDENTE', ?int $empresa = null): void
    {
        static $n = 0;
        $n++;
        DB::table('vendas')->insert(['empresa_id' => $empresa ?? $this->empresa->id, 'tipo_documento' => $tipo, 'numero_documento' => "{$tipo} T/{$n}",
            'data_emissao' => "{$data} 10:00:00", 'cliente_id' => $cliente, 'total_liquido' => $liquido, 'total_imposto' => $imposto,
            'total_bruto' => bcadd($liquido, $imposto, 2), 'valor_pendente' => $pendente, 'estado' => $estado, 'data_vencimento' => $data]);
    }

    #[Test]
    public function resumo_calcula_os_indicadores_do_periodo_em_sql(): void
    {
        $this->documento('FT', '2026-01-10', $this->clienteA, '1000.00', '140.00', '1140.00');
        $this->documento('FR', '2026-01-20', $this->clienteB, '500.00', '70.00');
        $this->documento('FT', '2026-02-05', $this->clienteB, '2000.00', '280.00', '1000.00', 'PARCIAL');
        $this->documento('NC', '2026-02-06', $this->clienteB, '100.00', '14.00');
        $this->documento('FT', '2026-02-07', $this->clienteA, '9999.00', '0.00', '9999.00', 'ANULADO');   // não conta
        $this->documento('OR', '2026-02-07', $this->clienteA, '7777.00', '0.00');                          // não fiscal
        $this->documento('FT', '2025-12-31', $this->clienteA, '5555.00', '0.00');                          // fora do período
        $this->documento('FT', '2026-01-15', $this->clienteA, '8888.00', '0.00', '0', 'PENDENTE', $this->criarEmpresa()->id); // outra empresa

        $r = $this->getJson('/api/vendas/relatorios/resumo?inicio=2026-01-01&fim=2026-02-28', $this->sessao(['vendas_relatorios_view']))->assertOk()
            ->assertJsonPath('dados.documentos', 4)
            ->assertJsonPath('dados.liquido', '3400.00')
            ->assertJsonPath('dados.imposto', '476.00')
            ->assertJsonPath('dados.bruto', '3876.00')
            ->assertJsonPath('dados.notas_credito', '114.00')
            ->assertJsonPath('dados.a_receber', '2140.00');
        $this->assertSame([['mes' => '2026-01', 'liquido' => '1500.00', 'bruto' => '1710.00', 'documentos' => 2],
            ['mes' => '2026-02', 'liquido' => '1900.00', 'bruto' => '2166.00', 'documentos' => 2]], $r->json('dados.por_mes'));
        $this->assertSame(['Cliente B', 'Cliente A'], array_column($r->json('dados.maiores_clientes'), 'nome'));
        $this->assertSame('2850.00', $r->json('dados.maiores_clientes.0.bruto'));
        $this->assertSame(2, $r->json('dados.maiores_clientes.0.documentos'));
        $this->assertSame(['FT T/3', 'FT T/1'], array_column($r->json('dados.pendentes'), 'numero_documento'));
        $this->assertSame('Cliente B', $r->json('dados.pendentes.0.cliente.nome'));

        // fim inclusivo (data_emissao com hora)
        $this->getJson('/api/vendas/relatorios/resumo?inicio=2026-02-07&fim=2026-02-07', $this->sessao(['vendas_relatorios_view']))->assertOk()
            ->assertJsonPath('dados.documentos', 0)->assertJsonPath('dados.liquido', '0.00')->assertJsonPath('dados.por_mes', []);
        $this->getJson('/api/vendas/relatorios/resumo?inicio=2026-02-06&fim=2026-02-06', $this->sessao(['vendas_relatorios_view']))->assertOk()
            ->assertJsonPath('dados.liquido', '-100.00');
    }

    #[Test]
    public function resumo_exige_permissao_e_periodo_valido(): void
    {
        $this->getJson('/api/vendas/relatorios/resumo?inicio=2026-01-01&fim=2026-02-28', $this->sessao(['vendas_faturacao_view']))->assertForbidden();
        $this->getJson('/api/vendas/relatorios/resumo?inicio=2026-03-01&fim=2026-02-28', $this->sessao(['vendas_relatorios_view']))->assertStatus(422)
            ->assertJsonPath('sucesso', false);
    }

    #[Test]
    public function saft_valida_antes_de_gerar_e_responde_erro_em_json(): void
    {
        $s = $this->sessao(['vendas_relatorios_view']);
        // sem documentos: erro no envelope JSON, não um ficheiro
        $this->get('/api/vendas/saft?inicio=2026-01-01&fim=2026-01-31', $s)->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')->assertJsonPath('codigo', 'SEM_DOCUMENTOS')->assertJsonPath('sucesso', false);
        $this->getJson('/api/vendas/saft/validar?inicio=2026-01-01&fim=2026-01-31', $s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_DOCUMENTOS');
        $this->getJson('/api/vendas/saft/validar?inicio=2025-12-01&fim=2026-01-31', $s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_INVALIDO');
        $this->getJson('/api/vendas/saft/validar?inicio=2026-01-01', $s)->assertStatus(422)->assertJsonPath('sucesso', false);

        $this->documento('FT', '2026-01-10', $this->clienteA, '1000.00', '140.00', '1140.00');
        $this->documento('NC', '2026-01-11', $this->clienteA, '100.00', '14.00');
        $r = $this->getJson('/api/vendas/saft/validar?inicio=2026-01-01&fim=2026-01-31', $s)->assertOk()
            ->assertJsonPath('dados.documentos', 2)->assertJsonPath('dados.recibos', 0)
            ->assertJsonPath('dados.nome', 'SAFT_AO_5417000777_2026-01-01_2026-01-31.xml');
        $this->assertTrue(collect($r->json('dados.avisos'))->contains(fn ($a) => str_contains($a, '2 documento(s) sem assinatura SAF-T')));

        // NIF inválido: recusado antes de começar o ficheiro
        $this->empresa->update(['nif' => '12']);
        $this->get('/api/vendas/saft?inicio=2026-01-01&fim=2026-01-31', $s)->assertStatus(422)->assertJsonPath('codigo', 'NIF_INVALIDO');

        $this->getJson('/api/vendas/saft/validar?inicio=2026-01-01&fim=2026-01-31', $this->sessao(['vendas_faturacao_view']))->assertForbidden();
    }
}
