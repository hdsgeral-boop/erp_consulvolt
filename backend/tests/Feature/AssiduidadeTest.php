<?php

namespace Tests\Feature;

use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\EfectividadeAssiduidade;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\PlanoFeriasColaborador;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\ServicoAssiduidade;
use App\Services\RH\ServicoCalendarioRH;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Assiduidade e ausências (RH parte 2a, ADR-038). Cenário em Junho de 2025 (21 dias úteis, 8 h/dia):
 * Ana — 02/06 10 h (2 h extra), 03/06 7,9 h (arredonda a 8 h), 04/06 6 h (2 h de falta parcial), sábado 07/06 4 h (extra),
 * sem registo a 05, 06 e 09/06 (3 dias seguidos em dias úteis), 8 h nos restantes; Novo — admitido a 20/06, sem registos;
 * Rui — INACTIVO (não entra).
 */
final class AssiduidadeTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true', 'base_horaria' => true]);
            $he = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Horas Extras', 'sujeito_inss' => true, 'irt' => 'true', 'calculo_horas' => 'EXTRA']);
            $fi = InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Falta injustificada', 'sujeito_inss' => false, 'irt' => 'false', 'calculo_horas' => 'FALTA']);
            $mk = function (string $nome, string $nif, string $estado, string $inicio) use ($org, $base) {
                $c = Colaborador::create(['nome_completo' => $nome, 'nif' => $nif, 'estado' => $estado, 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => $inicio]);
                ContratoTrabalho::create(['colaborador_id' => $c->id, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => $inicio,
                    'remuneracoes' => [['infotipo_id' => $base->id, 'valor_mes' => 176000]]]);

                return $c->id;
            };
            $this->ids = ['ana' => $mk('Ana', '111', 'ACTIVO', '2025-01-01'), 'novo' => $mk('Novo', '222', 'ACTIVO', '2025-06-20'), 'rui' => $mk('Rui', '333', 'INACTIVO', '2025-01-01'),
                'he' => $he->id, 'fi' => $fi->id];
            foreach (ServicoCalendarioRH::dias('2025-06-01', '2025-06-30') as $d) {
                $sem = (int) date('w', strtotime($d));
                if ($sem === 0 || $sem === 6 || in_array($d, ['2025-06-05', '2025-06-06', '2025-06-09'], true)) {
                    continue;
                }
                EfectividadeAssiduidade::create(['colaborador_id' => $this->ids['ana'], 'data' => $d, 'horas' => match ($d) {
                    '2025-06-02' => 10, '2025-06-03' => 7.9, '2025-06-04' => 6, default => 8
                }, 'origem' => 'FICHEIRO']);
            }
            EfectividadeAssiduidade::create(['colaborador_id' => $this->ids['ana'], 'data' => '2025-06-07', 'horas' => 4, 'origem' => 'MANUAL']);
        });
        $this->s = $this->sessao(['rh_assiduidade_view', 'rh_assid_registar', 'rh_assid_fechar', 'rh_assid_config', 'rh_portal_aprovar', 'calcular_folha', 'calcular_view']);
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function naEmpresa(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    #[Test]
    public function apuramento_fecho_justificacao_e_lancamento_no_processamento(): void
    {
        $r = $this->getJson('/api/rh/assiduidade/meses/2025-06', $this->s)->assertOk()->assertJsonPath('dados.estado', 'ABERTO')->assertJsonPath('dados.dias_uteis', 21)->json('dados');
        $linhas = collect($r['linhas'])->keyBy('colaborador_id');
        $this->assertCount(2, $linhas);   // o inactivo não entra
        $this->assertEquals([6, 26, 3], [$linhas[$this->ids['ana']]['horas_extra'], $linhas[$this->ids['ana']]['horas_falta'], $linhas[$this->ids['ana']]['dias_falta']]);
        $this->assertSame(7, $linhas[$this->ids['novo']]['dias_falta']);   // só desde a admissão (20/06)

        $this->postJson('/api/rh/assiduidade/meses/2025-06/fechar', [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'FECHADO')->assertJsonPath('dados.ausencias_geradas', 3);
        $this->postJson('/api/rh/assiduidade/meses/2025-06/fechar', [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MES_FECHADO');
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => '2025-06-05', 'horas' => 8], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MES_FECHADO');
        $detectadas = $this->getJson('/api/rh/assiduidade/ausencias?estado=POR_JUSTIFICAR', $this->s)->assertJsonCount(3, 'dados')->json('dados');
        $bloco = collect($detectadas)->first(fn ($a) => $a['colaborador_id'] === $this->ids['ana'] && $a['dias_uteis'] === 3);
        $this->assertSame(['2025-06-05', '2025-06-09'], [substr($bloco['data_inicio'], 0, 10), substr($bloco['data_fim'], 0, 10)]);

        // mudar a configuração depois do fecho não altera o apuramento a lançar (fotografia da configuração)
        $this->putJson('/api/rh/assiduidade/configuracao', ['dias_uteis' => [1, 2, 3, 4, 5], 'tolerancia_min' => 10, 'arredondamento_min' => 15, 'extras_min_minutos' => 15,
            'modo_compensacao' => 'MENSAL', 'extra_nao_util_exige_autorizacao' => false], $this->s)->assertOk()->assertJsonPath('dados.modo_compensacao', 'MENSAL');

        // justificar a falta de 3 dias (doença) e aprovar
        $this->postJson("/api/rh/assiduidade/ausencias/{$bloco['id']}/justificar", ['tipo' => 'DOENCA', 'motivo' => 'Gripe'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'PROVA_EM_FALTA');
        $this->postJson("/api/rh/assiduidade/ausencias/{$bloco['id']}/justificar", ['tipo' => 'DOENCA', 'motivo' => 'Gripe', 'documento_url' => 'https://docs.exemplo/atestado.pdf'], $this->s)
            ->assertOk()->assertJsonPath('dados.estado', 'PENDENTE_RH');
        $this->postJson("/api/rh/assiduidade/ausencias/{$bloco['id']}/decidir", ['decisao' => 'APROVADO'], $this->sessao(['rh_portal_aprovar'], $this->ids['ana']))
            ->assertStatus(403)->assertJsonPath('codigo', 'AUTO_APROVACAO');
        $this->postJson("/api/rh/assiduidade/ausencias/{$bloco['id']}/decidir", ['decisao' => 'APROVADO'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'APROVADO');

        // lançar no processamento de 06/2025: Ana 6 h extra e 2 h de falta (26 − 24 justificadas); Novo 56 h de falta
        $p = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => '06/2025'], $this->s)->assertCreated()->json('dados.id');
        $this->postJson("/api/rh/salarios/periodos/{$p}/importar-efectividade", ['infotipo_extra_id' => $this->ids['fi'], 'infotipo_falta_id' => $this->ids['fi']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_INVALIDA');
        $lanc = ['infotipo_extra_id' => $this->ids['he'], 'infotipo_falta_id' => $this->ids['fi']];
        $this->postJson("/api/rh/salarios/periodos/{$p}/importar-efectividade", $lanc, $this->s)->assertOk()->assertJsonPath('dados.lancados', 3);
        $horas = fn () => $this->naEmpresa(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p)->orderBy('colaborador_id')->orderBy('infotipo_salarial_id')->get()
            ->map(fn ($l) => "{$l->colaborador_id}:{$l->infotipo_salarial_id}:".(float) $l->horas)->all());
        $this->assertSame(["{$this->ids['ana']}:{$this->ids['he']}:6", "{$this->ids['ana']}:{$this->ids['fi']}:2", "{$this->ids['novo']}:{$this->ids['fi']}:56"], $horas());

        // o processamento calcula as horas: 6 h × 1 000 × 1,5 = 9 000; falta 2 h × 1 000 = 2 000
        $res = collect($this->getJson("/api/rh/salarios/periodos/{$p}", $this->s)->json('dados.resultados'))->firstWhere('colaborador_id', $this->ids['ana']);
        $this->assertSame('9000.00', collect($res['rubricas'])->firstWhere('infotipo_id', $this->ids['he'])['valor']);

        // a ausência do Novo é aprovada depois: relançar retira o lançamento de falta que deixou de existir
        $nova = collect($detectadas)->firstWhere('colaborador_id', $this->ids['novo']);
        $this->postJson("/api/rh/assiduidade/ausencias/{$nova['id']}/justificar", ['tipo' => 'OUTRA', 'motivo' => 'Integração'], $this->s)->assertOk();
        $this->postJson("/api/rh/assiduidade/ausencias/{$nova['id']}/decidir", ['decisao' => 'APROVADO'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DECISAO_REMUNERACAO');
        $this->postJson("/api/rh/assiduidade/ausencias/{$nova['id']}/decidir", ['decisao' => 'APROVADO', 'remunerada' => 'SIM'], $this->s)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/importar-efectividade", $lanc, $this->s)->assertOk()->assertJsonPath('dados.removidos', 1);
        $this->assertCount(2, $horas());

        // reabrir exige motivo e o processamento aberto
        $this->postJson('/api/rh/assiduidade/meses/2025-06/reabrir', ['motivo' => 'x'], $this->s)->assertStatus(422);
        $this->postJson('/api/rh/assiduidade/meses/2025-06/reabrir', ['motivo' => 'Correcção de registos'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'REABERTO');
        $this->postJson("/api/rh/salarios/periodos/{$p}/importar-efectividade", $lanc, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MES_NAO_FECHADO');
    }

    #[Test]
    public function ausencias_registadas_pelo_rh_com_regras_da_lei(): void
    {
        $this->naEmpresa(fn () => PlanoFeriasColaborador::create(['colaborador_id' => $this->ids['ana'], 'ano' => 2025, 'data_inicio' => '2025-07-01', 'data_fim' => '2025-07-05',
            'dias' => 4, 'direito' => 22, 'estado' => 'APROVADO']));
        $base = ['colaborador_id' => $this->ids['ana'], 'motivo' => 'Motivo', 'documento_url' => 'https://docs.exemplo/prova.pdf'];
        $this->postJson('/api/rh/assiduidade/ausencias', $base + ['tipo' => 'FORMACAO', 'data_inicio' => '2025-07-02', 'data_fim' => '2025-07-02'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SOBREPOSICAO');   // férias (o legado não verificava)
        $this->postJson('/api/rh/assiduidade/ausencias', $base + ['tipo' => 'CASAMENTO_FAMILIAR', 'data_inicio' => '2025-07-10', 'data_fim' => '2025-07-11'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_EXCEDIDO');
        $this->postJson('/api/rh/assiduidade/ausencias', $base + ['tipo' => 'SINDICAL_DELEGADO', 'data_inicio' => '2025-07-10', 'data_fim' => '2025-07-10'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SEM_UNIDADES');
        $a = $this->postJson('/api/rh/assiduidade/ausencias', $base + ['tipo' => 'OBRIGACOES_LEGAIS', 'data_inicio' => '2025-07-14', 'data_fim' => '2025-07-16'], $this->s)
            ->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE_RH')->assertJsonPath('dados.dias_uteis', 3)->assertJsonPath('dados.remunerada', 'SIM')->json('dados');
        $this->assertTrue(collect($a['avisos'])->contains(fn ($x) => str_contains($x, 'limite de 2 dias no mês')));
        $this->postJson('/api/rh/assiduidade/ausencias', $base + ['tipo' => 'FORMACAO', 'data_inicio' => '2025-07-15', 'data_fim' => '2025-07-15'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SOBREPOSICAO');
        $this->postJson("/api/rh/assiduidade/ausencias/{$a['id']}/cancelar", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'CANCELADO');
        $this->postJson("/api/rh/assiduidade/ausencias/{$a['id']}/decidir", ['decisao' => 'APROVADO'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ESTADO_INVALIDO');

        // casamento de familiar é justificado mas não remunerado: continua descontado, sem falta injustificada
        $this->postJson('/api/rh/assiduidade/ausencias', $base + ['tipo' => 'CASAMENTO_FAMILIAR', 'colaborador_id' => $this->ids['ana'], 'data_inicio' => '2025-06-05', 'data_fim' => '2025-06-05'], $this->s)
            ->assertCreated();
        $id = $this->naEmpresa(fn () => AusenciaFaltaColaborador::query()->where('tipo', 'CASAMENTO_FAMILIAR')->value('id'));
        $this->postJson("/api/rh/assiduidade/ausencias/{$id}/decidir", ['decisao' => 'APROVADO'], $this->s)->assertOk()->assertJsonPath('dados.remunerada', 'NAO');
        $ana = collect($this->getJson('/api/rh/assiduidade/meses/2025-06', $this->s)->json('dados.linhas'))->firstWhere('colaborador_id', $this->ids['ana']);
        $this->assertEquals([26, 2], [$ana['horas_falta'], $ana['dias_falta']]);
    }

    #[Test]
    public function registos_manuais_importacao_e_configuracao(): void
    {
        $this->assertSame(8.0, ServicoAssiduidade::horasEntre('22:00', '06:00'));   // turno que passa da meia-noite
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => now()->addDay()->toDateString(), 'horas' => 8], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DATA_FUTURA');
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => '2025-05-02'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'HORAS_EM_FALTA');
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => '2025-05-02', 'entrada' => '08:00', 'saida' => '17:30'], $this->s)
            ->assertOk()->assertJsonPath('dados.horas', '9.500');
        $this->postJson('/api/rh/assiduidade/registos', ['colaborador_id' => $this->ids['ana'], 'data' => '2025-05-02', 'horas' => 7], $this->s)->assertOk();   // um por dia (actualiza)
        $this->assertSame(1, $this->naEmpresa(fn () => EfectividadeAssiduidade::query()->where('data', '2025-05-02')->count()));

        $csv = "nif,data,entrada,saida\n111,05/05/2025,08:00,16:00\n999,06/05/2025,08:00,16:00\n111,31/02/2025,08:00,16:00\n";
        $f = UploadedFile::fake()->createWithContent('relogio.csv', $csv);
        $this->post('/api/rh/assiduidade/registos/importar', ['ficheiro' => $f], $this->s + ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('dados.gravados', 1)->assertJsonCount(2, 'dados.erros');
        $this->assertSame('FICHEIRO', $this->naEmpresa(fn () => EfectividadeAssiduidade::query()->where('data', '2025-05-05')->value('origem')));

        $cfg = ['dias_uteis' => [1, 2, 3, 4, 5], 'tolerancia_min' => 10, 'arredondamento_min' => 15, 'extras_min_minutos' => 15, 'extra_nao_util_exige_autorizacao' => true];
        $this->putJson('/api/rh/assiduidade/configuracao', $cfg + ['modo_compensacao' => 'LIMITE'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_EM_FALTA');
        $this->putJson('/api/rh/assiduidade/configuracao', $cfg + ['modo_compensacao' => 'DIA', 'relogio' => ['url' => 'https://u:p@relogio.local/x']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'URL_RELOGIO_INVALIDA');
        // feriado a 10/06 e sábado sem autorização: Ana perde as 4 h extra do sábado; 10/06 deixa de ser dia útil (8 h trabalhadas → extra)
        $this->putJson('/api/rh/assiduidade/configuracao', $cfg + ['modo_compensacao' => 'MENSAL', 'feriados' => ['2025-06-10']], $this->s)->assertOk();
        $r = $this->getJson('/api/rh/assiduidade/meses/2025-06', $this->s)->assertJsonPath('dados.dias_uteis', 20)->json('dados.linhas');
        $ana = collect($r)->firstWhere('colaborador_id', $this->ids['ana']);
        // o feriado trabalhado também é dia não útil sem autorização: 8 + 4 = 12 h não autorizadas; extra só 2 h (02/06);
        // falta 26 h; compensação mensal min(2, 26) = 2 → extra 0, falta 24
        $this->assertEquals([0, 24, 2, 12], [$ana['horas_extra'], $ana['horas_falta'], $ana['horas_compensadas'], $ana['horas_nao_autorizadas']]);
    }
}
