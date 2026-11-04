<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\ElegibilidadeProdutividadeRH;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\PeriodoProdutividadeRH;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\ServicoProdutividade;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Os itens de produtividade saem do contrato: a migração 2026_11_20_090000 copia o JSON `contratos_trabalho.produtividade`
 * para `elegibilidade_produtividade_rh` (origem CONTRATO, preço próprio quando > 0, sem duplicar) e elimina a coluna;
 * `ServicoProdutividade::elegiveis()` passa a ler só a tabela. Dados fictícios.
 */
final class ProdutividadeSaiDoContratoTest extends TestCase
{
    private const MIGRACAO = 'migrations/2026_11_20_090000_produtividade_sai_do_contrato.php';

    private Empresa $empresa;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $prod = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de produtividade', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $mk = fn (string $n, string $nif) => Colaborador::create(['nome_completo' => $n, 'nif' => $nif, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => '2020-01-01'])->id;
            $item = fn (string $c, float $preco) => ItemProdutividadeRH::create(['codigo' => $c, 'descricao' => $c, 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'preco_unitario' => $preco,
                'infotipo_salarial_id' => $prod, 'ativo' => true])->id;
            $this->ids = ['ana' => $mk('Ana Fictícia', '900000001LA001'), 'rui' => $mk('Rui Fictício', '900000002LA002'), 'eva' => $mk('Eva Fictícia', '900000003LA003'),
                'pec' => $item('PEC', 100), 'obj' => $item('OBJ', 50000), 'vel' => $item('VEL', 10)];
            $ct = fn (string $c, string $estado, string $inicio, ?string $fim = null) => ContratoTrabalho::create(['colaborador_id' => $this->ids[$c], 'estado' => $estado, 'dias_contrato_mes' => 22,
                'horas_por_dia' => 8, 'data_inicio' => $inicio, 'data_fim' => $fim, 'remuneracoes' => []])->id;
            $this->ids += ['ct_ana' => $ct('ana', 'ACTIVO', '2020-01-01'), 'ct_rui' => $ct('rui', 'CESSADO', '2020-01-01', '2024-12-31'),
                'ct_eva_velho' => $ct('eva', 'CESSADO', '2018-01-01', '2019-12-31'), 'ct_eva' => $ct('eva', 'ACTIVO', '2020-01-01')];
        });
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    /** @return array<int, array{origem: string, preco: ?float}> */
    private function linhas(int $colaborador): array
    {
        return DB::table('elegibilidade_produtividade_rh')->where('colaborador_id', $colaborador)->orderBy('item_produtividade_id')->get()
            ->mapWithKeys(fn ($l) => [(int) $l->item_produtividade_id => ['origem' => $l->origem, 'preco' => $l->preco_unitario !== null ? (float) $l->preco_unitario : null]])->all();
    }

    #[Test]
    public function a_coluna_ja_nao_existe_e_o_contrato_nao_a_aceita_nem_devolve(): void
    {
        $this->assertFalse(Schema::hasColumn('contratos_trabalho', 'produtividade'));
        $c = $this->em(fn () => ContratoTrabalho::query()->find($this->ids['ct_ana']));
        $this->assertArrayNotHasKey('produtividade', $c->toArray());
        $this->assertNotContains('produtividade', $c->getFillable());
    }

    #[Test]
    public function a_migracao_move_os_itens_para_a_elegibilidade_sem_duplicar_e_ignora_quem_nao_tem_contrato_activo(): void
    {
        $migracao = require database_path(self::MIGRACAO);
        $migracao->down();   // repõe a coluna (o DDL é revertido com o teste)
        $this->assertTrue(Schema::hasColumn('contratos_trabalho', 'produtividade'));
        $json = fn (array $l) => json_encode($l);
        DB::table('contratos_trabalho')->where('id', $this->ids['ct_ana'])->update(['produtividade' => $json([['item_id' => $this->ids['pec'], 'preco_unitario' => 150], ['item_id' => $this->ids['obj'], 'preco_unitario' => null]])]);
        DB::table('contratos_trabalho')->where('id', $this->ids['ct_rui'])->update(['produtividade' => $json([['item_id' => $this->ids['pec'], 'preco_unitario' => null]])]);   // cessado, sem contrato activo
        DB::table('contratos_trabalho')->where('id', $this->ids['ct_eva_velho'])->update(['produtividade' => $json([['item_id' => $this->ids['vel'], 'preco_unitario' => 0], ['item_id' => 999999, 'preco_unitario' => null]])]);
        // a Ana já tinha o OBJ na elegibilidade (MANUAL, preço 5): não se duplica nem se altera
        $this->em(fn () => ElegibilidadeProdutividadeRH::create(['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['obj'], 'ativo' => true, 'origem' => 'MANUAL', 'preco_unitario' => 5]));

        $migracao->up();

        $this->assertFalse(Schema::hasColumn('contratos_trabalho', 'produtividade'));
        $this->assertSame(['migrados' => 2, 'existentes' => 1, 'ignorados' => 2], $migracao->resultado);   // ignorados: Rui (sem activo) e o item inexistente da Eva
        $this->assertSame([$this->ids['pec'] => ['origem' => 'CONTRATO', 'preco' => 150.0], $this->ids['obj'] => ['origem' => 'MANUAL', 'preco' => 5.0]], $this->linhas($this->ids['ana']));
        $this->assertSame([], $this->linhas($this->ids['rui']));
        $this->assertSame([$this->ids['vel'] => ['origem' => 'CONTRATO', 'preco' => null]], $this->linhas($this->ids['eva']));   // preço 0 = preço do item
        $this->assertSame(1, DB::table('elegibilidade_produtividade_rh')->where('colaborador_id', $this->ids['ana'])->where('item_produtividade_id', $this->ids['obj'])->count());
        $this->assertTrue((bool) DB::table('elegibilidade_produtividade_rh')->where('origem', 'CONTRATO')->value('ativo'));

        $migracao->up();   // idempotente: sem a coluna, nada a fazer
        $this->assertSame(3, DB::table('elegibilidade_produtividade_rh')->count());
    }

    #[Test]
    public function a_migracao_e_reversivel_ao_nivel_dos_dados(): void
    {
        $migracao = require database_path(self::MIGRACAO);
        $migracao->down();
        DB::table('contratos_trabalho')->where('id', $this->ids['ct_ana'])->update(['produtividade' => json_encode([['item_id' => $this->ids['pec'], 'preco_unitario' => 150]])]);
        $migracao->up();
        $this->assertSame(1, DB::table('elegibilidade_produtividade_rh')->where('origem', 'CONTRATO')->count());

        $migracao->down();
        $this->assertSame(0, DB::table('elegibilidade_produtividade_rh')->where('origem', 'CONTRATO')->count());
        $this->assertSame([['item_id' => $this->ids['pec'], 'preco_unitario' => 150]], json_decode((string) DB::table('contratos_trabalho')->where('id', $this->ids['ct_ana'])->value('produtividade'), true));
        $this->assertNull(DB::table('contratos_trabalho')->where('id', $this->ids['ct_eva'])->value('produtividade'));
    }

    #[Test]
    public function elegiveis_usa_so_a_tabela_de_elegibilidade(): void
    {
        $p = $this->em(fn () => PeriodoProdutividadeRH::create(['mes' => '2026-09', 'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30', 'estado' => 'ABERTO']));
        $servico = app(ServicoProdutividade::class);
        $this->assertSame([], $this->em(fn () => $servico->elegiveis($p)));   // contratos activos sem elegibilidade: ninguém
        $this->em(fn () => ElegibilidadeProdutividadeRH::create(['colaborador_id' => $this->ids['ana'], 'item_produtividade_id' => $this->ids['pec'], 'ativo' => true, 'origem' => 'CONTRATO', 'preco_unitario' => 150]));
        $this->em(fn () => ElegibilidadeProdutividadeRH::create(['colaborador_id' => $this->ids['eva'], 'item_produtividade_id' => $this->ids['obj'], 'ativo' => false, 'origem' => 'MANUAL']));
        $e = $this->em(fn () => $servico->elegiveis($p));
        $this->assertSame([$this->ids['ana']], array_keys($e));   // a linha inactiva não conta
        $this->assertSame(['150', 'CONTRATO'], [(string) (float) $e[$this->ids['ana']]['itens'][$this->ids['pec']]['preco'], $e[$this->ids['ana']]['itens'][$this->ids['pec']]['origem']]);
    }
}
