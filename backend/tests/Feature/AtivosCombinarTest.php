<?php

namespace Tests\Feature;

use App\Models\AlocacaoAquisicaoAtivo;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Activos › Aquisições Pendentes › «Inventariar Seleccionados (Combinar)» (js/ui_assets.js:2784, 3208-3217, 595-606, 733-745):
 * várias linhas 11/12 inventariadas em conjunto, com o valor repartido pelas linhas (alocacoes_aquisicao_ativos).
 */
final class AtivosCombinarTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000099']);
        $this->ids['forn'] = $this->planoETerceiro($this->empresa->id);
        $this->s = $this->sessao(['activos_view', 'activos_gerir', 'activos_cat_gerir', 'activos_inventariar']);
        $this->ids['cat'] = $this->postJson('/api/ativos/categorias', ['nome' => 'Equipamento', 'taxa_anual' => 25, 'vida_util_padrao' => 48,
            'conta_gasto' => '7315', 'conta_amortizacao_acumulada' => '1815'], $this->s)->assertCreated()->json('dados.id');
    }

    private function planoETerceiro(int $empresa): int
    {
        return app(ContextoEmpresa::class)->executarComo($empresa, function () {
            foreach (['7315' => 'Amortizações do exercício', '1815' => 'Amortizações acumuladas', '1141' => 'Equipamento administrativo',
                '1142' => 'Equipamento informático', '3211' => 'Fornecedores'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }

            return Terceiro::create(['nome' => 'Fornecedor de equipamento', 'tipo' => 'FORNECEDOR', 'codigo_conta' => '3211'])->id;
        });
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    /** Compra D conta / C 3211; devolve o id da linha a débito. */
    private function compra(string $valor, string $data, string $conta = '1141', ?int $empresa = null, ?int $terceiro = null): int
    {
        return app(ContextoEmpresa::class)->executarComo($empresa ?? $this->empresa->id, fn () => app(ServicoLancamentos::class)->criar([
            'diario_id' => app(LocalizadorLancamentos::class)->diario('CP', 'Compras')->id, 'data_documento' => $data, 'numero_documento' => "FT {$data}",
            'linhas' => [['codigo_conta' => $conta, 'tipo_dc' => 'D', 'valor' => $valor, 'terceiro_id' => $terceiro ?? $this->ids['forn']],
                ['codigo_conta' => '3211', 'tipo_dc' => 'C', 'valor' => $valor, 'terceiro_id' => $terceiro ?? $this->ids['forn']]],
        ])->firstWhere('tipo_dc', 'D')->id);
    }

    private function item(float $valor, array $extra = []): array
    {
        return $extra + ['descricao' => 'Servidor', 'categoria_ativo_id' => $this->ids['cat'], 'valor_aquisicao' => $valor, 'vida_util' => 48];
    }

    private function pendentes(): array
    {
        return collect($this->getJson('/api/ativos/aquisicoes-pendentes', $this->s)->assertOk()->json('dados.linhas'))->keyBy('id')->all();
    }

    #[Test]
    public function combina_linhas_de_contas_diferentes_num_so_activo_e_reparte_o_valor(): void
    {
        $b = $this->compra('30000', '2026-02-05', '1142');
        $a = $this->compra('50000', '2026-01-10');
        // a linha A já tem 10 000 inventariados à parte: restante 40 000
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$a}/inventariar", ['itens' => [$this->item(10000, ['descricao' => 'Cabo'])]], $this->s)->assertCreated();

        $r = $this->postJson('/api/ativos/aquisicoes-pendentes/combinar', ['linhas' => [$b, $a], 'itens' => [$this->item(70000)]], $this->s)
            ->assertCreated()->json('dados');
        $ativo = $r['ativos'][0];
        // data e fornecedor da primeira linha (data, id), FK para a primeira linha coberta
        $this->assertSame(['2026-01-10', $this->ids['forn'], $a, '0.00'], [substr($ativo['data_aquisicao'], 0, 10), $ativo['fornecedor_id'], $ativo['lancamento_contabil_id'], $r['por_inventariar']]);
        $this->assertSame([['id' => $a, 'por_inventariar' => '0.00'], ['id' => $b, 'por_inventariar' => '0.00']], $r['linhas']);
        $aloc = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => AlocacaoAquisicaoAtivo::query()->pluck('valor', 'lancamento_contabil_id')->all());
        $this->assertEquals([$a => '40000.00', $b => '30000.00'], $aloc);
        $this->assertSame([], $this->pendentes());

        // o fluxo do imobilizado conta o activo combinado nas duas aquisições, cada uma pelo valor alocado
        $fluxo = collect($this->getJson('/api/ativos/fluxo', $this->s)->assertOk()->json('dados.aquisicoes'))->where('tipo', 'lancamento')->values();
        $this->assertEqualsCanonicalizing(['50000.00', '30000.00'], $fluxo->pluck('inventariado')->all());

        // a linha B não é a FK do activo, mas está coberta pela combinação: o estorno do lançamento fica bloqueado
        $bloqueio = app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => app(ServicoLancamentos::class)->bloqueioEstorno(LancamentoContabil::query()->whereKey($b)->get()));
        $this->assertSame('LANCAMENTO_COM_ATIVOS', $bloqueio['codigo'] ?? null);
    }

    #[Test]
    public function inventario_parcial_reparte_por_ordem_e_deixa_o_resto_pendente(): void
    {
        $a = $this->compra('50000', '2026-01-10');
        $b = $this->compra('30000', '2026-02-05');
        $r = $this->postJson('/api/ativos/aquisicoes-pendentes/combinar', ['linhas' => [$a, $b], 'itens' => [$this->item(45000), $this->item(15000, ['descricao' => 'Rack'])]], $this->s)
            ->assertCreated()->json('dados');
        // 45 000 → A; 15 000 → 5 000 de A + 10 000 de B; B fica com 20 000 por inventariar
        $this->assertSame([$a, $a], [$r['ativos'][0]['lancamento_contabil_id'], $r['ativos'][1]['lancamento_contabil_id']]);
        $this->assertSame('20000.00', $r['por_inventariar']);
        $p = $this->pendentes();
        $this->assertSame([[$b], '10000.00', '20000.00', 'PARCIAL', 1], [array_keys($p), $p[$b]['inventariado'], $p[$b]['por_inventariar'], $p[$b]['estado'], $p[$b]['ativos']]);

        // o restante continua a inventariar-se linha a linha, limitado ao que falta
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$b}/inventariar", ['itens' => [$this->item(20000.01)]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXCEDE_AQUISICAO');
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$b}/inventariar", ['itens' => [$this->item(20000)]], $this->s)->assertCreated()->assertJsonPath('dados.por_inventariar', '0.00');

        // eliminar o activo combinado devolve o valor às linhas
        $this->deleteJson('/api/ativos/bens/'.$r['ativos'][1]['id'], [], $this->sessao(['activos_view', 'activos_eliminar']))->assertOk();
        $p = $this->pendentes();
        $this->assertSame(['5000.00', '10000.00'], [$p[$a]['por_inventariar'], $p[$b]['por_inventariar']]);
    }

    #[Test]
    public function recusa_excesso_linhas_invalidas_de_outra_empresa_ou_ja_inventariadas(): void
    {
        $a = $this->compra('50000', '2026-01-10');
        $b = $this->compra('30000', '2026-02-05');
        $url = '/api/ativos/aquisicoes-pendentes/combinar';

        $this->postJson($url, ['linhas' => [$a, $b], 'itens' => [$this->item(80000.01)]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXCEDE_AQUISICAO');
        $this->postJson($url, ['linhas' => [$a], 'itens' => [$this->item(1)]], $this->s)->assertStatus(422);
        $this->postJson($url, ['linhas' => [$a, $a], 'itens' => [$this->item(1)]], $this->s)->assertStatus(422);
        $this->postJson($url, ['linhas' => [$a, 'x'], 'itens' => [$this->item(1)]], $this->s)->assertStatus(422);
        $this->postJson($url, ['linhas' => [$a, $b], 'itens' => [$this->item(1, ['codigo' => 'X1']), $this->item(1, ['codigo' => 'x1'])]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');

        // linha a crédito (3211) não é aquisição
        $credito = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('tipo_dc', 'C')->value('id'));
        $this->postJson($url, ['linhas' => [$a, $credito], 'itens' => [$this->item(1)]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LINHA_NAO_AQUISICAO');

        // linha de outra empresa: invisível pelo isolamento (empresa activa)
        $outra = $this->criarEmpresa(['nif' => '5417000100']);
        $fornOutra = $this->planoETerceiro($outra->id);
        $alheia = $this->compra('10000', '2026-01-15', '1141', $outra->id, $fornOutra);
        $this->postJson($url, ['linhas' => [$a, $alheia], 'itens' => [$this->item(1)]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LINHA_NAO_AQUISICAO');

        // linha já totalmente inventariada
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$b}/inventariar", ['itens' => [$this->item(30000)]], $this->s)->assertCreated();
        $this->postJson($url, ['linhas' => [$a, $b], 'itens' => [$this->item(1)]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LINHA_SEM_RESTANTE');

        // nada foi gravado pelas tentativas recusadas
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => AlocacaoAquisicaoAtivo::query()->count()));
        $this->assertSame('50000.00', $this->pendentes()[$a]['por_inventariar']);
    }

    #[Test]
    public function ficha_mostra_todas_as_linhas_e_alterar_o_valor_reparte_outra_vez_pela_mesma_ordem(): void
    {
        $a = $this->compra('50000', '2026-01-10');
        $b = $this->compra('30000', '2026-02-05');
        $c = $this->compra('20000', '2026-03-01', '1142');
        // outro activo já cobre 10 000 de B: B só tem 20 000 para o combinado
        $this->postJson("/api/ativos/aquisicoes-pendentes/{$b}/inventariar", ['itens' => [$this->item(10000, ['descricao' => 'Cabo'])]], $this->s)->assertCreated();
        $id = $this->postJson('/api/ativos/aquisicoes-pendentes/combinar', ['linhas' => [$c, $b, $a], 'itens' => [$this->item(60000)]], $this->s)
            ->assertCreated()->json('dados.ativos.0.id');
        $aloc = fn () => app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => AlocacaoAquisicaoAtivo::query()->where('ativo_imobilizado_id', $id)->pluck('valor', 'lancamento_contabil_id')->map(fn ($v) => (string) $v)->all());
        $this->assertEquals([$a => '50000.00', $b => '10000.00'], $aloc());

        // ficha: todas as linhas combinadas, por ordem (data, id), com o valor de cada uma
        $ficha = $this->getJson("/api/ativos/bens/{$id}", $this->s)->assertOk()->json('dados');
        $this->assertTrue($ficha['combinado']);
        $this->assertSame([[$a, '50000.00'], [$b, '10000.00']], array_map(fn ($o) => [$o['id'], $o['valor_alocado']], $ficha['origens']));
        $this->assertSame($a, $ficha['origem']['id']);

        // 85 000: A 50 000 + B 20 000 (o resto de B é do outro activo) + nada de C (não fazia parte) → não cabe, recusa sem alterar
        $this->putJson("/api/ativos/bens/{$id}", ['valor_aquisicao' => 85000], $this->s)->assertStatus(422)
            ->assertJsonPath('codigo', 'EXCEDE_AQUISICAO')->assertJsonPath('erros.disponivel', '70000.00');
        $this->assertEquals([$a => '50000.00', $b => '10000.00'], $aloc());
        $this->getJson("/api/ativos/bens/{$id}", $this->s)->assertJsonPath('dados.valor_aquisicao', '60000.00');

        // 70 000 cabe: B passa a 20 000
        $this->putJson("/api/ativos/bens/{$id}", ['valor_aquisicao' => 70000], $this->s)->assertOk();
        $this->assertEquals([$a => '50000.00', $b => '20000.00'], $aloc());
        $this->assertArrayNotHasKey($b, $this->pendentes());

        // 30 000: só A (pela mesma ordem); B volta a ficar pendente com 20 000; a FK continua em A
        $this->putJson("/api/ativos/bens/{$id}", ['valor_aquisicao' => 30000], $this->s)->assertOk()->assertJsonPath('dados.lancamento_contabil_id', $a);
        $this->assertEquals([$a => '30000.00'], $aloc());
        $p = $this->pendentes();
        $this->assertSame(['20000.00', '20000.00'], [$p[$a]['por_inventariar'], $p[$b]['por_inventariar']]);
        // a linha B saiu da combinação: só A aparece na ficha
        $this->assertSame([$a], array_column($this->getJson("/api/ativos/bens/{$id}", $this->s)->json('dados.origens'), 'id'));

        // valor 0 num combinado é recusado
        $this->putJson("/api/ativos/bens/{$id}", ['valor_aquisicao' => 0], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DADOS_INVALIDOS');
    }

    #[Test]
    public function exige_a_permissao_de_inventariar(): void
    {
        $a = $this->compra('50000', '2026-01-10');
        $b = $this->compra('30000', '2026-02-05');
        $this->postJson('/api/ativos/aquisicoes-pendentes/combinar', ['linhas' => [$a, $b], 'itens' => [$this->item(1)]], $this->sessao(['activos_view', 'activos_pendentes_view']))
            ->assertForbidden();
        $this->postJson('/api/ativos/aquisicoes-pendentes/combinar', ['linhas' => [$a, $b], 'itens' => [$this->item(1)]])->assertUnauthorized();
    }
}
