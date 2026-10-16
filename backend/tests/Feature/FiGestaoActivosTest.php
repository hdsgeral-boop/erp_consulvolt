<?php

namespace Tests\Feature;

use App\Models\CategoriaAtivo;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fecho da Versão Actual (ADR-071) — Activos:
 *   - A2: um bem pode ficar «neste edifício, sem porta» (andar opcional, coerência validada); a planta mostra em «Bens sem
 *     porta» os bens do edifício sem porta e os sem localização, e à parte os bens de outros edifícios;
 *   - A9: ganho na venda na classe 68 e perda/sinistro na 78 (sem pontos); conta antiga fora da classe mantém-se se não mudar.
 * Dados fictícios.
 */
final class FiGestaoActivosTest extends TestCase
{
    private Empresa $empresa;

    private array $h;

    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417200011']);
        $this->naEmpresa(function () {
            foreach (['7315' => 'Amortizações do exercício', '1815' => 'Amortizações acumuladas', '68.1.1' => 'Ganhos em imobilizações (fictício)',
                '78031' => 'Perdas em imobilizações', '6211' => 'Conta antiga fictícia', '6212' => 'Outra conta fictícia'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
        });
        $this->h = $this->sessao(['activos_view', 'activos_gerir', 'activos_cat_gerir', 'activos_edificios_view', 'activos_locais_gerir']);
        $this->cat = $this->postJson('/api/ativos/categorias', ['nome' => 'Mobiliário', 'taxa_anual' => 10], $this->h)->assertCreated()->json('dados.id');
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function bem(array $d): int
    {
        return $this->postJson('/api/ativos/bens', $d + ['categoria_ativo_id' => $this->cat, 'valor_aquisicao' => 1000], $this->h)->assertCreated()->json('dados.id');
    }

    /** @return array{0: int, 1: int, 2: int} edifício, andar, porta A1 */
    private function edificio(string $nome): array
    {
        $e = $this->bem(['descricao' => $nome, 'tipo_ativo' => 'EDIFICIO', 'valor_aquisicao' => 500000]);
        $andar = $this->postJson("/api/ativos/edificios/{$e}/andares", ['numero' => '0'], $this->h)->assertCreated()->json('dados.id');
        $this->postJson("/api/ativos/edificios/{$e}/portas", ['andar_id' => $andar, 'prefixo' => 'A', 'de' => 1, 'ate' => 1], $this->h)->assertCreated();
        $porta = $this->getJson("/api/ativos/edificios/{$e}/planta", $this->h)->json('dados.andares.0.portas.0.id');

        return [$e, $andar, $porta];
    }

    #[Test]
    public function bem_fica_no_edificio_sem_porta_e_a_planta_mostra_os_tres_grupos(): void
    {
        [$e1, $andar1, $porta1] = $this->edificio('Sede fictícia');
        [$e2, , $porta2] = $this->edificio('Armazém fictício');

        // ficha: edifício sem andar nem porta, e edifício + andar sem porta
        $a = $this->bem(['descricao' => 'Estante', 'edificio_id' => $e1]);
        $this->getJson("/api/ativos/bens/{$a}", $this->h)->assertJsonPath('dados.edificio_id', $e1)->assertJsonPath('dados.andar_id', null)
            ->assertJsonPath('dados.porta_id', null)->assertJsonPath('dados.edificio.id', $e1);
        $b = $this->bem(['descricao' => 'Cadeira', 'edificio_id' => $e1, 'andar_id' => $andar1, 'valor_aquisicao' => 250]);
        // coerência: andar de outro edifício, edifício que não é EDIFICIO, porta de outro edifício
        $this->postJson('/api/ativos/bens', ['descricao' => 'X', 'categoria_ativo_id' => $this->cat, 'edificio_id' => $e2, 'andar_id' => $andar1], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'LOCAL_INCOERENTE');
        $this->postJson('/api/ativos/bens', ['descricao' => 'X', 'categoria_ativo_id' => $this->cat, 'edificio_id' => $a], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'NAO_E_EDIFICIO');
        $this->putJson("/api/ativos/bens/{$a}", ['edificio_id' => $e2, 'porta_id' => $porta1], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'LOCAL_INCOERENTE');

        $semLocal = $this->bem(['descricao' => 'Secretária sem local']);
        $noOutro = $this->bem(['descricao' => 'Mesa no armazém', 'porta_id' => $porta2]);
        $naPorta = $this->bem(['descricao' => 'Armário na porta', 'porta_id' => $porta1]);

        $planta = $this->getJson("/api/ativos/edificios/{$e1}/planta", $this->h)->assertOk()->json('dados');
        $grupos = collect($planta['sem_local'])->pluck('grupo', 'id')->all();
        $this->assertSame(['EDIFICIO', 'EDIFICIO', 'SEM_LOCAL'], array_values($grupos));   // os do edifício primeiro
        $this->assertSame([$a, $b, $semLocal], array_keys($grupos));
        $this->assertSame('Rés-do-chão', collect($planta['sem_local'])->firstWhere('id', $b)['local']);
        $this->assertSame([$noOutro], array_column($planta['outros_edificios'], 'id'));
        $this->assertStringContainsString('A1', $planta['outros_edificios'][0]['local']);
        $this->assertNotContains($naPorta, array_column($planta['sem_local'], 'id'));
        $this->assertSame(3, $planta['totais']['bens']);       // 1 na porta + 2 sem porta
        $this->assertSame(2, $planta['totais']['sem_porta']);
        $this->assertSame('2250.00', $planta['totais']['valor']);

        // a folha de inventário do edifício conta também os bens que lá estão sem porta; a do andar só os desse andar
        $inv = $this->sessao(['activos_edificios_view', 'activos_inventario_fisico']);
        $this->postJson("/api/ativos/edificios/{$e1}/inventarios-fisicos", ['ambito' => 'EDIFICIO'], $inv)->assertCreated()->assertJsonPath('dados.esperados', 3);
        $this->postJson("/api/ativos/edificios/{$e1}/inventarios-fisicos", ['ambito' => 'ANDAR', 'andar_id' => $andar1], $inv)->assertCreated()
            ->assertJsonPath('dados.esperados', 2);   // a cadeira (andar, sem porta) e o armário na porta do mesmo andar

        $resumo = $this->getJson('/api/ativos/edificios', $this->h)->assertOk()->json('dados');
        $this->assertSame(1, $resumo['sem_local']);
        $this->assertSame(2, $resumo['sem_porta']);
        $this->assertSame([3, 2], [collect($resumo['edificios'])->firstWhere('id', $e1)['bens'], collect($resumo['edificios'])->firstWhere('id', $e1)['sem_porta']]);

        // arrastar: de outro edifício para uma porta deste; para «Bens sem porta» fica no edifício; tudo a null retira
        $this->postJson("/api/ativos/bens/{$noOutro}/localizacao", ['porta_id' => $porta1], $this->h)->assertOk()->assertJsonPath('dados.edificio_id', $e1);
        $this->postJson("/api/ativos/bens/{$naPorta}/localizacao", ['porta_id' => null, 'edificio_id' => $e1], $this->h)->assertOk()
            ->assertJsonPath('dados.edificio_id', $e1)->assertJsonPath('dados.porta_id', null)->assertJsonPath('mensagem', 'Bem deixado no edifício, sem porta.');
        $this->postJson("/api/ativos/bens/{$semLocal}/localizacao", ['porta_id' => null, 'edificio_id' => $e2, 'andar_id' => $andar1], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'LOCAL_INCOERENTE');
        $this->postJson("/api/ativos/bens/{$a}/localizacao", ['porta_id' => null], $this->h)->assertOk()->assertJsonPath('dados.edificio_id', null);
        $this->postJson("/api/ativos/bens/{$e2}/localizacao", ['porta_id' => null, 'edificio_id' => $e1], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'EDIFICIO_NAO_LOCALIZAVEL');

        // só de leitura não move; outra empresa não vê o edifício
        $this->postJson("/api/ativos/bens/{$a}/localizacao", ['porta_id' => null, 'edificio_id' => $e1], $this->sessao(['activos_edificios_view']))->assertForbidden();
        $outra = $this->criarEmpresa(['nif' => '5417200029']);
        $this->getJson("/api/ativos/edificios/{$e1}/planta", $this->sessao(['activos_edificios_view'], $outra))->assertNotFound();
    }

    #[Test]
    public function categorias_usam_as_classes_68_e_78_e_mantem_contas_antigas_que_nao_mudam(): void
    {
        $c = $this->postJson('/api/ativos/categorias', ['nome' => 'Viaturas', 'conta_venda' => '68.1.1', 'conta_perda' => '78031'], $this->h)
            ->assertCreated()->assertJsonPath('dados.conta_venda', '68.1.1')->json('dados.id');
        $this->postJson('/api/ativos/categorias', ['nome' => 'Outra', 'conta_venda' => '6211'], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INVALIDA');
        $this->postJson('/api/ativos/categorias', ['nome' => 'Outra', 'conta_perda' => '6211'], $this->h)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INVALIDA');

        // mapeamento migrado fora da classe: gravar sem mudar o campo é aceite; trocar por outra fora da classe não
        $this->naEmpresa(fn () => CategoriaAtivo::query()->whereKey($c)->update(['conta_venda' => '6211']));
        $this->putJson("/api/ativos/categorias/{$c}", ['nome' => 'Viaturas ligeiras', 'conta_venda' => '6211'], $this->h)->assertOk()
            ->assertJsonPath('dados.conta_venda', '6211');
        $this->putJson("/api/ativos/categorias/{$c}", ['conta_venda' => '6212'], $this->h)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_INVALIDA');
        $this->putJson("/api/ativos/categorias/{$c}", ['conta_venda' => '68.1.1'], $this->h)->assertOk()->assertJsonPath('dados.conta_venda', '68.1.1');
    }
}
