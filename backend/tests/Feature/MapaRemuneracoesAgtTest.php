<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LogAuditoria;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\ServicoMapaRemuneracoesAgt as Mapa;
use App\Support\Tenancy\ContextoEmpresa;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mapa de Remunerações no modelo da AGT (IRT A2.1): estrutura igual à do modelo de referência (fixture sem dados pessoais) e
 * células calculadas iguais às da folha validada (recibo / Mapa Fiscal de IRT). Ver docs/RH_MAPA_REMUNERACOES_AGT.md.
 */
final class MapaRemuneracoesAgtTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    private ?Spreadsheet $livro = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Fictícia, Lda', 'nif' => '5000000000']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores']);
            $i = fn (string $tipo, string $nome, bool $inss, string $irt) => InfotipoSalarial::create(['tipo' => $tipo, 'nome' => $nome, 'sujeito_inss' => $inss, 'irt' => $irt])->id;
            $this->ids = ['base' => $i('VENCIMENTO', 'Salário Base', true, 'true'), 'alim' => $i('VENCIMENTO', 'Subsídio de alimentação', false, 'conditional_30k'),
                'transp' => $i('VENCIMENTO', 'Subsídio de transporte', false, 'conditional_30k'), 'extra' => $i('VENCIMENTO', 'Horas Extras', true, 'true'),
                'premio' => $i('VENCIMENTO', 'Prémio de Produtividade', true, 'true'), 'familia' => $i('VENCIMENTO', 'Abono de Família', false, 'false'),
                'adi' => $i('DESCONTO', 'Adiantamento', false, 'false')];
            $c = fn (array $a) => Colaborador::create($a + ['estado' => 'ACTIVO', 'tipo_organizacao_id' => $org->id, 'dias_uteis_mes' => 22])->id;
            $this->ids += [
                'ana' => $c(['nome_completo' => 'Ana Exemplo', 'nif' => '111111111LA011', 'numero_inss' => '1000001', 'provincia' => 'LUANDA', 'municipio' => 'Talatona']),
                'bruno' => $c(['nome_completo' => 'Bruno Reformado', 'nif' => '222222222LA022', 'numero_inss' => '1000002', 'provincia' => 'LUANDA', 'municipio' => 'Viana', 'reformado' => true]),
                'carla' => $c(['nome_completo' => '=HYPERLINK("http://x")', 'nif' => null, 'provincia' => null]),
                'dario' => $c(['nome_completo' => 'Dario Avençado', 'nif' => '333333333LA033', 'avencado' => true]),
            ];
        });
        $this->s = $this->sessao(['calcular_view', 'processamento_view', 'calcular_lancar', 'calcular_folha', 'processamento_validate', 'rh_rel_irt_view']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function lancar(int $p, string $colab, string $rubrica, float $valor): void
    {
        $this->postJson("/api/rh/salarios/periodos/{$p}/lancamentos", ['colaborador_id' => $this->ids[$colab], 'infotipo_salarial_id' => $this->ids[$rubrica], 'valor' => $valor], $this->s)->assertCreated();
    }

    /** Folha com os quatro casos (validada por omissão); devolve o id do período. */
    private function folha(bool $validar = true): int
    {
        $p = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => '08/2026'], $this->s)->assertCreated()->json('dados.id');
        foreach ([['ana', 'base', 300000], ['ana', 'alim', 50000], ['ana', 'transp', 30000], ['ana', 'extra', 20000], ['ana', 'premio', 40000], ['ana', 'familia', 15000],
            ['bruno', 'base', 400000], ['carla', 'base', 120000], ['carla', 'adi', 5000], ['dario', 'base', 100000]] as [$c, $r, $v]) {
            $this->lancar($p, $c, $r, $v);
        }
        if ($validar) {
            $this->fechar($p);
        }

        return $p;
    }

    private function fechar(int $p): void
    {
        $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", ['confirmar_avisos' => true], $this->s)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $this->s)->assertOk();
    }

    private function folhaXlsx(int $p): Worksheet
    {
        $r = $this->get("/api/rh/salarios/periodos/{$p}/mapa-agt", $this->s)->assertOk();
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', (string) $r->headers->get('Content-Disposition'));
        $caminho = tempnam(sys_get_temp_dir(), 'mapa_').'.xlsx';
        file_put_contents($caminho, $r->streamedContent());

        $this->livro = IOFactory::load($caminho);   // o livro tem de ficar referenciado: sem ele as fórmulas da folha não calculam

        return $this->livro->getActiveSheet();
    }

    /** @return array<string, int> linha do ficheiro por nome (coluna B) */
    private function linhas(Worksheet $f): array
    {
        $out = [];
        for ($r = 5; $r <= $f->getHighestRow(); $r++) {
            $out[(string) $f->getCell("B{$r}")->getValue()] = $r;
        }

        return $out;
    }

    #[Test]
    public function a_estrutura_e_igual_a_do_modelo_de_referencia(): void
    {
        $f = $this->folhaXlsx($this->folha());
        $m = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/mapa_remuneracao_agt_estrutura.json'), true);
        $this->assertSame($m['folha'], $f->getTitle());
        $this->assertSame($m['rotulo_nif'], $f->getCell('B1')->getValue());
        $this->assertSame($m['rotulo_periodo'], $f->getCell('B2')->getValue());
        $this->assertSame('5000000000', (string) $f->getCell('A1')->getValue());
        $this->assertSame('2026-8', (string) $f->getCell('A2')->getValue());
        $cab = [];
        foreach (range(0, 31) as $i) {
            $cab[] = $f->getCell([$i + 1, 4])->getValue();
        }
        $this->assertSame($m['cabecalhos'], $cab);
        $this->assertSame($m['cabecalhos'], Mapa::CABECALHOS);
        $this->assertSame('AF', $f->getHighestColumn());
        $this->assertEqualsWithDelta(30.0, $f->getRowDimension(4)->getRowHeight(), 0.01);
        foreach ($m['larguras'] as $col => $w) {
            $this->assertEqualsWithDelta($w, $f->getColumnDimension($col)->getWidth(), 0.01, "largura {$col}");
        }
        $this->assertTrue($f->getStyle('A4')->getFont()->getBold());
    }

    #[Test]
    public function as_celulas_calculadas_batem_com_a_folha_validada(): void
    {
        $p = $this->folha();
        $f = $this->folhaXlsx($p);
        $l = $this->linhas($f);
        $this->assertCount(3, $l, 'o avençado (Grupo B) não entra no mapa');
        $v = fn (string $c, string $nome) => (float) $f->getCell($c.$l[$nome])->getCalculatedValue();

        // Ana: subsídios isentos (30 000 + 30 000), horas extra, prémio, abono de família não tributável
        $this->assertSame('111111111LA011', $f->getCell('A'.$l['Ana Exemplo'])->getValue());
        $this->assertSame('LUANDA', $f->getCell('D'.$l['Ana Exemplo'])->getValue());
        $esperado = ['F' => 300000, 'G' => 0, 'H' => 50000, 'I' => 30000, 'J' => 15000, 'S' => 20000, 'V' => 40000, 'X' => 0, 'Y' => 455000, 'AA' => 360000, 'AC' => 10800,
            'N' => 75000, 'AD' => 369200, 'AF' => 62398];
        foreach ($esperado as $c => $x) {
            $this->assertEqualsWithDelta($x, $v($c, 'Ana Exemplo'), 0.005, "Ana {$c}");
        }
        $this->assertSame('N', $f->getCell('AB'.$l['Ana Exemplo'])->getValue());

        // Bruno reformado: sem contribuição de Segurança Social
        $this->assertSame('S', $f->getCell('AB'.$l['Bruno Reformado'])->getValue());
        $this->assertEqualsWithDelta(0, $v('AC', 'Bruno Reformado'), 0.005);
        $this->assertEqualsWithDelta(400000, $v('AD', 'Bruno Reformado'), 0.005);

        // Carla: texto livre neutralizado
        $this->assertArrayHasKey("'=HYPERLINK(\"http://x\")", $l);

        // consistência com a folha: Y = bruto − faltas; AD = base IRT; AC = INSS do trabalhador; AF = IRT do recibo; somas = totais da folha
        $res = $this->getJson("/api/rh/salarios/periodos/{$p}", $this->s)->assertOk()->json('dados.resultados');
        $res = array_values(array_filter($res, fn ($x) => ! $x['avencado']));
        $somas = ['Y' => 0.0, 'AC' => 0.0, 'AD' => 0.0, 'AF' => 0.0];
        foreach ($res as $r) {
            $linha = $l[$r['nome']] ?? $l["'".$r['nome']];
            $this->assertEqualsWithDelta((float) $r['bruto'], (float) $f->getCell('Y'.$linha)->getCalculatedValue(), 0.005);
            $this->assertEqualsWithDelta((float) $r['base_irt'], (float) $f->getCell('AD'.$linha)->getCalculatedValue(), 0.005, $r['nome']);
            $this->assertEqualsWithDelta((float) $r['inss_trabalhador'], (float) $f->getCell('AC'.$linha)->getValue(), 0.005);
            $this->assertEqualsWithDelta((float) $r['irt'], (float) $f->getCell('AF'.$linha)->getValue(), 0.005);
            foreach (array_keys($somas) as $c) {
                $somas[$c] += (float) $f->getCell($c.$linha)->getCalculatedValue();
            }
        }
        $this->assertEqualsWithDelta(array_sum(array_column($res, 'inss_trabalhador')), $somas['AC'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($res, 'irt')), $somas['AF'], 0.01);
        $this->assertEqualsWithDelta(array_sum(array_column($res, 'base_irt')), $somas['AD'], 0.01);
        // as fórmulas do modelo mantêm-se como fórmulas
        $this->assertStringStartsWith('=F5-G5', (string) $f->getCell('Y5')->getValue());
        $this->assertStringStartsWith('=MAX(0,Y5-AC5-N5)', (string) $f->getCell('AD5')->getValue());
    }

    #[Test]
    public function a_verificacao_sinaliza_quem_nao_tem_nif_e_exclui_os_avencados(): void
    {
        $p = $this->folha();
        $d = $this->getJson("/api/rh/salarios/periodos/{$p}/mapa-agt/verificacao", $this->s)->assertOk()->json('dados');
        $this->assertSame(3, $d['colaboradores']);
        $this->assertSame(1, $d['avencados_excluidos']);
        $this->assertSame('Mapa Remuneração 2026 - Agosto.xlsx', $d['ficheiro']);
        $codigos = collect($d['avisos'])->where('colaborador_id', $this->ids['carla'])->pluck('codigo')->all();
        $this->assertEqualsCanonicalizing(['SEM_NIF', 'SEM_NSS', 'SEM_LOCALIZACAO'], $codigos);
        $this->assertCount(3, $d['avisos']);
    }

    #[Test]
    public function permissoes_estado_do_periodo_e_auditoria(): void
    {
        $p = $this->folha(false);
        $this->get("/api/rh/salarios/periodos/{$p}/mapa-agt", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_NAO_VALIDADO');
        $this->fechar($p);
        $this->get("/api/rh/salarios/periodos/{$p}/mapa-agt", $this->sessao(['rh_rel_recibos_view']))->assertStatus(403);
        $this->get("/api/rh/salarios/periodos/{$p}/mapa-agt", $this->s)->assertOk();
        $this->assertTrue(LogAuditoria::query()->withoutGlobalScopes()->where('acao', 'like', 'Exportação do Mapa de Remunerações%')->exists());
    }

    #[Test]
    public function texto_livre_com_prefixo_de_formula_e_neutralizado_e_rubricas_classificadas(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)'] as $t) {
            $this->assertSame("'".$t, Mapa::seguro($t));
        }
        $this->assertSame('Ana', Mapa::seguro(' Ana '));
        $this->assertSame('F', Mapa::coluna('Sal. Base'));
        $this->assertSame('S', Mapa::coluna('H. Extras'));
        $this->assertSame('R', Mapa::coluna('Subsídio de Férias'));
        $this->assertNull(Mapa::coluna('Subsídio de Comunicação'));
    }
}
