<?php

namespace Tests\Feature;

use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\EfectividadeAssiduidade;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\PlanoFeriasColaborador;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\CacheFaltasDoAno;
use App\Services\RH\ServicoAssiduidade;
use App\Services\RH\ServicoAusencias;
use App\Services\RH\ServicoCalendarioRH;
use App\Services\RH\ServicoEscalas;
use App\Services\RH\ServicoFerias;
use App\Support\Tenancy\ContextoEmpresa;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cache Redis do acumulado anual de faltas injustificadas (art. 230.º; ADR-071, pendente «cache do acumulado anual»):
 * a cache é usada (ecrã da efectividade e portal), é invalidada — agora e depois do commit — em cada alteração que muda o
 * apuramento, e está isolada por empresa. Dados fictícios.
 */
final class GlFaltasCacheTest extends TestCase
{
    private Empresa $a;

    private Empresa $b;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2025-04-15 10:00:00');
        $this->a = $this->criarEmpresa();
        $this->b = $this->criarEmpresa();
        $mk = function (string $nome, string $nif) {
            $org = TipoOrganizacaoRH::firstOrCreate(['nome' => 'Colaboradores'])->id;
            $base = InfotipoSalarial::firstOrCreate(['nome' => 'Salário Base'], ['tipo' => 'VENCIMENTO', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $c = Colaborador::create(['nome_completo' => $nome, 'nif' => $nif, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => '2020-01-01']);
            $ct = ContratoTrabalho::create(['colaborador_id' => $c->id, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2020-01-01',
                'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 176000]]]);

            return [$c->id, $ct->id];
        };
        $this->em($this->a, function () use ($mk) {
            [$this->ids['ana'], $this->ids['ct_ana']] = $mk('Ana Fictícia', 'NIFANA001');
            [$this->ids['rui']] = $mk('Rui Fictício', 'NIFRUI002');
            // um único registo em Janeiro: Janeiro, Fevereiro e Março ficam com faltas
            EfectividadeAssiduidade::create(['colaborador_id' => $this->ids['ana'], 'data' => '2025-01-02', 'horas' => 8, 'origem' => 'MANUAL']);
        });
        $this->em($this->b, function () use ($mk) {
            [$this->ids['bia']] = $mk('Bia de Outra Empresa', 'NIFBIA003');
        });
    }

    private function em(Empresa $e, Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($e->id, $f);
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->a->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->a->id];
    }

    private function svc(): ServicoAssiduidade
    {
        return app(ServicoAssiduidade::class);
    }

    private function anoDaAna(array $h): int
    {
        return collect($this->getJson('/api/rh/assiduidade/meses/2025-03', $h)->assertOk()->json('dados.linhas'))->firstWhere('colaborador_id', $this->ids['ana'])['dias_falta_ano'];
    }

    #[Test]
    public function cache_usada_no_ecra_e_no_portal_e_isolada_por_empresa(): void
    {
        $h = $this->sessao(['rh_assiduidade_view']);
        $base = $this->anoDaAna($h);
        $this->assertGreaterThan(12, $base);   // Jan (sem o dia 2) + Fev + Mar

        // a chave é da empresa e do ano; os meses anteriores ficam guardados
        $chave = CacheFaltasDoAno::chave($this->a->id, 2025);
        $this->assertStringStartsWith("{$this->a->id}:rh:faltas_ano:2025:v", $chave);
        $guardado = CacheFaltasDoAno::ler($chave);
        $this->assertSame(['2025-01', '2025-02'], array_keys($guardado['meses']));
        $this->assertSame('2025-01-02', $guardado['primeiro']);

        // a cache é usada: um valor marcado na cache aparece no ecrã sem novo apuramento
        $guardado['meses']['2025-01']['faltas'][$this->ids['ana']] += 100;
        CacheFaltasDoAno::gravar($chave, $guardado);
        $this->assertSame($base + 100, $this->anoDaAna($h));

        // portal: o mesmo apuramento (Jan..Abr, em cache), só com os números da própria colaboradora
        $portal = $this->getJson('/api/rh/portal/resumo', $this->sessao(['rh_portal_usar'], $this->ids['ana']))->assertOk()->json('dados');
        $esperado = $this->em($this->a, fn () => $this->svc()->faltasDoColaborador($this->ids['ana']));
        $this->assertSame($esperado['ano'], $portal['faltas_injustificadas_ano']);
        $this->assertSame($esperado['mes'], $portal['faltas_injustificadas_mes']);
        $this->assertGreaterThan(100, $portal['faltas_injustificadas_ano']);   // inclui o valor marcado: veio da cache
        $this->assertArrayNotHasKey('linhas', $portal);
        $this->assertArrayHasKey('2025-04', CacheFaltasDoAno::ler(CacheFaltasDoAno::chave($this->a->id, 2025))['meses']);

        // isolamento: a empresa B tem chave própria e não vê nada da A; sem efectividade no ano, o portal usa a estimativa
        $chaveB = CacheFaltasDoAno::chave($this->b->id, 2025);
        $this->assertNotSame($chave, $chaveB);
        $this->em($this->b, function () {
            $this->assertNull($this->svc()->faltasDoColaborador($this->ids['bia']));
            $porMes = $this->svc()->faltasPorMes(2025, 3);
            $this->assertFalse($porMes['com_registos']);
            $this->assertSame([], array_merge(...array_values(array_map('array_keys', $porMes['meses']))));
        });
        $versaoA = CacheFaltasDoAno::versao($this->a->id);
        $this->em($this->b, fn () => EfectividadeAssiduidade::create(['colaborador_id' => $this->ids['bia'], 'data' => '2025-01-02', 'horas' => 8, 'origem' => 'MANUAL']));
        $this->assertSame($versaoA, CacheFaltasDoAno::versao($this->a->id));   // alterar a B não invalida a A
        $this->assertSame($base + 100, $this->anoDaAna($h));
        $this->em($this->b, function () {
            $b = $this->svc()->faltasDoColaborador($this->ids['bia']);
            $this->assertNotNull($b);
            $this->assertLessThan(100, $b['ano']);
            $this->assertArrayNotHasKey($this->ids['ana'], $this->svc()->faltasPorMes(2025, 3)['meses']['2025-02']);
        });
    }

    #[Test]
    public function invalidada_em_cada_alteracao_que_muda_o_apuramento(): void
    {
        $verificar = function (string $caso, Closure $acao) {
            $this->em($this->a, fn () => $this->svc()->faltasPorMes(2025, 3));
            $antes = CacheFaltasDoAno::versao($this->a->id);
            $this->assertTrue(Cache::has(CacheFaltasDoAno::chave($this->a->id, 2025)), $caso);
            $this->em($this->a, $acao);
            $this->assertGreaterThan($antes, CacheFaltasDoAno::versao($this->a->id), "não invalidou: {$caso}");
            $this->assertFalse(Cache::has(CacheFaltasDoAno::chave($this->a->id, 2025)), $caso);
        };
        $ana = $this->ids['ana'];
        $fev = fn () => $this->em($this->a, fn () => $this->svc()->faltasDoAno('2025-03', [])[$ana] ?? 0);

        $antes = $fev();
        $verificar('gravar registo', fn () => $this->svc()->gravarRegisto(['colaborador_id' => $ana, 'data' => '2025-02-03', 'horas' => 8]));
        $this->assertSame($antes - 1, $fev());   // o valor é recalculado: menos um dia de falta em Fevereiro

        $csv = tempnam(sys_get_temp_dir(), 'efec').'.csv';
        file_put_contents($csv, "nif,data,horas\nNIFANA001,2025-02-04,8\n");
        $verificar('importar registos', fn () => $this->assertSame(1, $this->svc()->importar(new UploadedFile($csv, 'efec.csv', 'text/csv', null, true))['gravados']));
        @unlink($csv);
        $this->assertSame($antes - 2, $fev());

        $verificar('eliminar registo', fn () => $this->svc()->eliminarRegisto(EfectividadeAssiduidade::query()->where('colaborador_id', $ana)->whereDate('data', '2025-02-04')->firstOrFail()));
        $this->assertSame($antes - 1, $fev());

        $verificar('fechar o mês', fn () => $this->svc()->fechar('2025-02'));
        $verificar('reabrir o mês', fn () => $this->svc()->reabrir('2025-02', 'Correcção de registos'));

        $ausencia = fn (string $dia) => fn () => AusenciaFaltaColaborador::create(['colaborador_id' => $ana, 'tipo' => 'OUTRA', 'data_inicio' => $dia, 'data_fim' => $dia, 'dias' => 1,
            'dias_uteis' => 1, 'estado' => 'PENDENTE_RH', 'remunerada' => 'SIM', 'motivo' => 'Fictícia', 'detectada' => false]);
        $a1 = $this->em($this->a, $ausencia('2025-02-05'));
        $verificar('aprovar ausência justificada', fn () => app(ServicoAusencias::class)->decidir($a1, 'APROVADO', 'SIM', null));
        $this->assertSame($antes - 2, $fev());   // dia aprovado deixa de ser falta
        $a2 = $this->em($this->a, $ausencia('2025-02-06'));
        $verificar('recusar ausência justificada', fn () => app(ServicoAusencias::class)->decidir($a2, 'RECUSADO', null, 'Sem prova'));

        $ferias = $this->em($this->a, fn () => PlanoFeriasColaborador::create(['colaborador_id' => $ana, 'ano' => 2025, 'data_inicio' => '2025-02-17', 'data_fim' => '2025-02-18',
            'dias' => 2, 'direito' => 22, 'estado' => 'PLANEADO']));
        $verificar('aprovar férias', fn () => app(ServicoFerias::class)->alterarEstado($ferias, 'APROVADO'));

        $verificar('alterar a escala', fn () => app(ServicoEscalas::class)->gravarLinhas([['colaborador_id' => $ana, 'dia' => '2025-02-10', 'folga' => true]]));
        $verificar('alterar o horário do contrato', fn () => ContratoTrabalho::query()->findOrFail($this->ids['ct_ana'])->update(['horario' => ['entrada' => '08:00', 'saida' => '16:00',
            'periodos' => 1, 'dias' => [1, 2, 3, 4, 5]]]));
        $verificar('alterar a configuração', fn () => app(ServicoCalendarioRH::class)->gravarConfig(['dias_uteis' => [1, 2, 3, 4, 5], 'tolerancia_min' => 10, 'arredondamento_min' => 15,
            'extras_min_minutos' => 15, 'modo_compensacao' => 'MENSAL', 'extra_nao_util_exige_autorizacao' => false]));
        $verificar('mudar o estado do colaborador', fn () => Colaborador::query()->findOrFail($this->ids['rui'])->update(['estado' => 'INACTIVO']));

        // alterações que não mexem no apuramento não invalidam (ex.: o nome do colaborador)
        $this->em($this->a, fn () => $this->svc()->faltasPorMes(2025, 3));
        $v = CacheFaltasDoAno::versao($this->a->id);
        $this->em($this->a, fn () => Colaborador::query()->findOrFail($ana)->update(['nome_completo' => 'Ana Fictícia Silva']));
        $this->assertSame($v, CacheFaltasDoAno::versao($this->a->id));
    }

    #[Test]
    public function invalidada_de_novo_depois_do_commit(): void
    {
        $this->em($this->a, fn () => $this->svc()->faltasPorMes(2025, 3));
        $this->em($this->a, fn () => DB::transaction(function () {
            $this->svc()->gravarRegisto(['colaborador_id' => $this->ids['ana'], 'data' => '2025-02-03', 'horas' => 8]);
            // pedido concorrente na janela da transacção: ainda vê os dados antigos e guarda-os na chave da versão nova
            Cache::put(CacheFaltasDoAno::chave($this->a->id, 2025), ['primeiro' => '2025-01-02', 'meses' => ['2025-01' => ['ate' => '2025-01-31', 'faltas' => [$this->ids['ana'] => 999]]]], 3600);
        }));
        $this->assertFalse(Cache::has(CacheFaltasDoAno::chave($this->a->id, 2025)));   // depois do commit a versão subiu outra vez
        $this->assertLessThan(999, $this->em($this->a, fn () => $this->svc()->faltasDoAno('2025-02', [])[$this->ids['ana']] ?? 0));
    }
}
