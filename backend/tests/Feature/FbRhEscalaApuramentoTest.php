<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\EscalaTrabalho;
use App\Models\InfotipoSalarial;
use App\Models\TipoOrganizacaoRH;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase B (Versão Actual) — horário do contrato (1 ou 2 períodos), escala do mês (escalas.js) e apuramento da efectividade
 * com as horas previstas de cada dia (assiduidade.js:674). Junho de 2025: 21 dias úteis (seg-sex). Dados fictícios.
 */
final class FbRhEscalaApuramentoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $ana = Colaborador::create(['nome_completo' => 'Ana Fictícia', 'nif' => '000123456LA001', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22,
                'data_admissao' => '2024-01-02'])->id;
            $ct = ContratoTrabalho::create(['colaborador_id' => $ana, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2024-01-02',
                'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 300000]]])->id;
            $this->ids = ['base' => $base, 'ana' => $ana, 'ct' => $ct];
        });
        $this->s = [
            'rh' => $this->sessao(['contratos_view', 'contratos_new', 'rh_assiduidade_view', 'rh_assid_registar', 'rh_assid_fechar', 'rh_assid_config']),
            'ver' => $this->sessao(['rh_assiduidade_view']),
        ];
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function horario(array $h): TestResponse
    {
        return $this->putJson("/api/rh/contratos/{$this->ids['ct']}", ['colaborador_id' => $this->ids['ana'], 'data_inicio' => '2024-01-02', 'dias_contrato_mes' => 22,
            'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 300000]], 'horario' => $h], $this->s['rh']);
    }

    #[Test]
    public function horario_do_contrato_valida_os_dois_periodos_e_define_as_horas_por_dia(): void
    {
        $this->horario(['entrada' => '08:00', 'saida' => '17:30', 'periodos' => 2])->assertStatus(422)->assertJsonPath('codigo', 'HORARIO_INTERVALO_EM_FALTA');
        $this->horario(['entrada' => '08:00', 'saida' => '17:30', 'periodos' => 2, 'saida_intervalo' => '18:00', 'entrada_intervalo' => '19:00'])
            ->assertStatus(422)->assertJsonPath('codigo', 'HORARIO_PERIODOS_INVALIDOS');
        $this->horario(['entrada' => '08:00', 'saida' => '09:00', 'periodos' => 1, 'intervalo_min' => 60])->assertStatus(422)->assertJsonPath('codigo', 'HORARIO_SEM_HORAS');
        // 1 período: 08:00–17:00 menos 60 min = 8 h
        $this->horario(['entrada' => '08:00', 'saida' => '17:00', 'periodos' => 1, 'intervalo_min' => 60, 'tolerancia_min' => 10, 'dias' => [1, 2, 3, 4, 5]])->assertOk()
            ->assertJsonPath('dados.horas_por_dia', '8.000')->assertJsonPath('dados.horario.periodos', 1);
        // 2 períodos: (12:00 − 08:00) + (17:30 − 13:00) = 8,5 h; o intervalo é o que decorre entre as picagens do meio
        $this->horario(['entrada' => '08:00', 'saida' => '17:30', 'periodos' => 2, 'saida_intervalo' => '12:00', 'entrada_intervalo' => '13:00', 'tolerancia_min' => 10,
            'dias' => [5, 1, 2, 3, 4]])->assertOk()->assertJsonPath('dados.horas_por_dia', '8.500')->assertJsonPath('dados.horario.intervalo_min', 60)
            ->assertJsonPath('dados.horario.dias', [1, 2, 3, 4, 5]);
    }

    #[Test]
    public function escala_prioritaria_contrato_copia_e_excel(): void
    {
        $this->horario(['entrada' => '08:00', 'saida' => '17:30', 'periodos' => 2, 'saida_intervalo' => '12:00', 'entrada_intervalo' => '13:00', 'tolerancia_min' => 10, 'dias' => [1, 2, 3, 4, 5]])->assertOk();
        $ana = [$this->ids['ana']];
        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => $ana, 'de' => '2025-06-07', 'entrada' => '08:00', 'saida' => '17:00'], $this->s['ver'])->assertForbidden();
        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => $ana, 'de' => '2025-06-07', 'entrada' => '08:00', 'saida' => '17:00', 'periodos' => 2], $this->s['rh'])
            ->assertStatus(422)->assertJsonPath('codigo', 'HORARIO_INTERVALO_EM_FALTA');
        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => $ana, 'de' => '2025-06-07', 'entrada' => '08:00', 'saida' => '17:00'], $this->s['rh'])
            ->assertOk()->assertJsonPath('dados.criados', 1);
        // prioritária: linha à parte, sobrepõe-se sem apagar a prevista
        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => $ana, 'de' => '2025-06-07', 'entrada' => '08:00', 'saida' => '12:00', 'prioritaria' => true,
            'nota' => 'Inventário'], $this->s['rh'])->assertOk()->assertJsonPath('dados.criados', 1);
        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => $ana, 'de' => '2025-06-09', 'folga' => true], $this->s['rh'])->assertOk();
        // migrar do contrato 1-8 Jun com folgas: dia 7 protegido pela prioritária, dia 9 fora do intervalo
        $this->postJson('/api/rh/assiduidade/escalas/do-contrato', ['de' => '2025-06-01', 'ate' => '2025-06-08', 'marcar_folgas' => true], $this->s['rh'])->assertOk()
            ->assertJsonPath('dados.criados', 7)->assertJsonPath('dados.protegidos', 1)->assertJsonPath('dados.colaboradores', 1);

        $g = $this->getJson('/api/rh/assiduidade/escalas/2025-06', $this->s['ver'])->assertOk()->json('dados.linhas.0');
        $dia = fn (string $d) => collect($g['celulas'])->firstWhere('dia', $d)['escala'];
        $this->assertTrue($dia('2025-06-07')['prioritaria']);
        $this->assertSame(4.0, (float) $dia('2025-06-07')['horas']);
        $this->assertSame(8.5, (float) $dia('2025-06-02')['horas']);
        $this->assertTrue($dia('2025-06-08')['folga']);
        $this->assertSame(1, $g['prioritarias']);
        $this->assertSame('08:00–17:30', $g['horario_contrato_texto']);

        // copiar 2 e 3 de Junho para 7 e 8 de Julho
        $this->postJson('/api/rh/assiduidade/escalas/copiar', ['de_origem' => '2025-06-02', 'ate_origem' => '2025-06-03', 'para_inicio' => '2025-07-07'], $this->s['rh'])->assertOk()
            ->assertJsonPath('dados.criados', 2);

        // Excel: tudo ou nada; NIF sem os zeros à esquerda (o Excel tira-os) é aceite quando não é ambíguo
        $erro = $this->xlsx([['123456LA001', 'Ana', '14/06/2025', '08:00', '25:00'], ['123456LA001', 'Ana', '15/06/2025', '', '', '', '', '', '', '', 'SIM']]);
        $this->post('/api/rh/assiduidade/escalas/importar', ['ficheiro' => $erro], $this->s['rh'])->assertOk()->assertJsonPath('dados.gravado', false);
        $this->assertNull($this->em(fn () => EscalaTrabalho::query()->where('dia', '2025-06-15')->first()));
        $bom = $this->xlsx([['123456LA001', 'Ana', '14/06/2025', '09:00', '13:00'], ['123456LA001', 'Ana', '15/06/2025', '', '', '', '', '', '', '', 'SIM']]);
        $this->post('/api/rh/assiduidade/escalas/importar', ['ficheiro' => $bom], $this->s['rh'])->assertOk()->assertJsonPath('dados.gravado', true)->assertJsonPath('dados.criados', 2);

        // limpar só as prioritárias: o dia 7 volta à escala prevista (08:00–17:00)
        $this->postJson('/api/rh/assiduidade/escalas/limpar', ['de' => '2025-06-07', 'ate' => '2025-06-07', 'apenas_prioritarias' => true], $this->s['rh'])->assertOk()
            ->assertJsonPath('dados.eliminados', 1);
        $g = $this->getJson('/api/rh/assiduidade/escalas/2025-06', $this->s['ver'])->json('dados.linhas.0');
        $this->assertSame('17:00', collect($g['celulas'])->firstWhere('dia', '2025-06-07')['escala']['saida']);

        $this->get('/api/rh/assiduidade/escalas/modelo', $this->s['ver'])->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Modelo_Escala.xlsx"');
    }

    #[Test]
    public function apuramento_usa_a_escala_e_o_horario_dia_a_dia(): void
    {
        $this->horario(['entrada' => '08:00', 'saida' => '17:30', 'periodos' => 2, 'saida_intervalo' => '12:00', 'entrada_intervalo' => '13:00', 'tolerancia_min' => 10, 'dias' => [1, 2, 3, 4, 5]])->assertOk();
        $ana = [$this->ids['ana']];
        // sábado 7: escala prioritária de 4 h (dia de trabalho); segunda 9: folga na escala
        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => $ana, 'de' => '2025-06-07', 'entrada' => '08:00', 'saida' => '12:00', 'prioritaria' => true], $this->s['rh'])->assertOk();
        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => $ana, 'de' => '2025-06-09', 'folga' => true], $this->s['rh'])->assertOk();
        // 4 picagens com 20 min de atraso: 3,67 + 4,5 = 8,17 h → arredondado a 15 min = 8,25 → falta parcial 0,25 h
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => '2025-06-02', 'entrada' => '08:20', 'saida_intervalo' => '12:00',
            'entrada_intervalo' => '13:00', 'saida' => '17:30'], $this->s['rh'])->assertOk()->assertJsonPath('dados.horas', '8.170');
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => '2025-06-04', 'entrada' => '08:00', 'saida_intervalo' => '12:00',
            'saida' => '17:30'], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'PICAGENS_INTERVALO');
        // só 2 picagens em regime de 2 períodos: 9,5 h brutas → 1 h extra, com aviso
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => '2025-06-03', 'entrada' => '08:00', 'saida' => '17:30'], $this->s['rh'])->assertOk();
        // horas com vírgula decimal
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => '2025-06-05', 'horas' => '8,5'], $this->s['rh'])->assertStatus(422);   // a API valida numérico
        // suspensão de 23 a 30: esses dias não são faltas
        $this->em(fn () => ContratoTrabalho::query()->find($this->ids['ct'])->forceFill(['estado' => 'SUSPENSO', 'suspensao' => ['de' => '2025-06-23', 'ate' => '2025-06-30']])->save());

        $l = $this->getJson('/api/rh/assiduidade/meses/2025-06', $this->s['ver'])->assertOk()->json('dados.linhas.0');
        $this->assertSame(8.5, (float) $l['horas_dia']);
        $this->assertSame(21, $l['dias_uteis']);   // 21 dias de semana − a folga de dia 9 + o sábado 7
        $this->assertContains('2025-06-07', $l['dias_falta_lista']);
        $this->assertNotContains('2025-06-09', $l['dias_falta_lista']);
        $this->assertNotContains('2025-06-23', $l['dias_falta_lista']);
        $this->assertSame(4.0, (float) collect($l['ocorrencias'])->firstWhere('data', '2025-06-07')['horas']);
        $this->assertSame(0.25, (float) collect($l['ocorrencias'])->firstWhere('data', '2025-06-02')['horas']);
        $this->assertSame(1.0, (float) $l['horas_extra_bruta']);
        $this->assertSame(1, $l['dias_atraso']);
        $this->assertSame(0.17, (float) $l['horas_atraso']);
        $this->assertSame(1, $l['sem_picagem_intervalo']);
        $this->assertSame(2, $l['dias_escala']);
        $this->assertSame(1, $l['dias_escala_prioritaria']);
        $this->assertSame(8, $l['dias_suspenso']);
        $this->assertSame(8.5, (float) $l['horario']['horas_previstas']);
        foreach (['escala prioritária', 'sem as picagens do intervalo', 'entrada depois da hora prevista', 'contrato suspenso'] as $aviso) {
            $this->assertTrue(collect($l['avisos'])->contains(fn ($x) => str_contains($x, $aviso)), "falta o aviso «{$aviso}»");
        }
        // faltas de dia inteiro: 21 dias de semana − 2 e 3 (com registo) − 9 (folga) − 23 a 30 (suspenso) = 12, mais o sábado 7
        $this->assertSame(12 + 1, $l['dias_falta']);

        // a efectividade fechada congela a escala do mês
        $this->postJson('/api/rh/assiduidade/meses/2025-06/fechar', [], $this->s['rh'])->assertOk();
        $this->postJson('/api/rh/assiduidade/escalas/aplicar', ['colaboradores' => $ana, 'de' => '2025-06-10', 'folga' => true], $this->s['rh'])->assertStatus(422)->assertJsonPath('codigo', 'MES_FECHADO');
    }

    #[Test]
    public function importacao_da_efectividade_com_quatro_picagens_e_linhas_em_branco(): void
    {
        $livro = new Spreadsheet;
        $f = $livro->getActiveSheet();
        $f->fromArray([['NIF', 'Data', 'Entrada', 'Saida intervalo', 'Entrada intervalo', 'Saída', 'Horas']], null, 'A1');
        $f->setCellValueExplicit('A2', '123456LA001', DataType::TYPE_STRING);
        $f->fromArray([['2025-06-02', '08:00', '12:00', '13:00', '17:00']], null, 'B2');
        $f->setCellValueExplicit('A3', '000123456LA001', DataType::TYPE_STRING);
        $f->fromArray([['2025-06-03', '', '', '', '', '']], null, 'B3');   // linha do modelo deixada em branco
        $f->setCellValueExplicit('A4', '999999999LA999', DataType::TYPE_STRING);
        $f->fromArray([['2025-06-04', '08:00', '', '', '16:00']], null, 'B4');
        $tmp = tempnam(sys_get_temp_dir(), 'efe').'.xlsx';
        (new Xlsx($livro))->save($tmp);
        $r = $this->post('/api/rh/assiduidade/registos/importar', ['ficheiro' => new UploadedFile($tmp, 'efectividade.xlsx', null, null, true)], $this->s['rh'])->assertOk()->json('dados');
        $this->assertSame(1, $r['gravados']);
        $this->assertSame(1, $r['em_branco']);
        $this->assertCount(1, $r['erros']);
        $this->assertStringContainsString('exemplo de NIF registado', $r['erros'][0]);
        $reg = $this->getJson('/api/rh/assiduidade/registos?mes=2025-06', $this->s['ver'])->json('dados.0');
        $this->assertSame('12:00', $reg['saida_intervalo']);
        $this->assertSame('8.000', $reg['horas']);
    }

    /** @param  list<list<string>>  $linhas  NIF, Colaborador, Data, Entrada, Saída, Períodos, Saída int., Entrada int., Intervalo, Tolerância, Folga, Prioritária, Nota */
    private function xlsx(array $linhas): UploadedFile
    {
        $livro = new Spreadsheet;
        $f = $livro->getActiveSheet();
        $f->fromArray([['NIF', 'Colaborador', 'Data', 'Entrada', 'Saída', 'Períodos', 'Saída intervalo', 'Entrada intervalo', 'Intervalo (min)', 'Tolerância (min)', 'Folga', 'Prioritária', 'Nota']]);
        foreach ($linhas as $i => $l) {
            foreach ($l as $j => $v) {
                $f->setCellValueExplicit([$j + 1, $i + 2], $v, DataType::TYPE_STRING);
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'esc').'.xlsx';
        (new Xlsx($livro))->save($tmp);

        return new UploadedFile($tmp, 'escala.xlsx', null, null, true);
    }
}
