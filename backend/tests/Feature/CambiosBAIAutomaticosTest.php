<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\Moeda;
use App\Models\TaxaCambio;
use App\Services\Integracoes\Cambios\ServicoCambiosBAIAutomaticos;
use App\Services\Sistema\ServicoCambiosBAI;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Câmbios do BAI automáticos (PROMPT_PROXIMOS_PASSOS §2): agendamento, pendente sem gravar, validação, rejeição e falha do BAI. */
final class CambiosBAIAutomaticosTest extends TestCase
{
    private const HTML = '<div class="ExchangeRatesList"><table><thead><tr><th>Divisas</th><th>Notas</th></tr><tr><th>Moeda</th><th>Venda</th><th>Compra</th><th>Venda</th><th>Compra</th></tr></thead>'
        .'<tbody><tr><td>USD Dólar</td><td>920,00</td><td>910,00</td><td>930</td><td>890</td></tr><tr><td>EUR Euro</td><td>1.060,50</td><td>1.040,50</td><td>1</td><td>1</td></tr></tbody></table></div>';

    private Empresa $empresa;

    private array $gestor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        foreach ([['AOA', 'Kwanza', 'Kz'], ['USD', 'Dólar dos EUA', 'US$'], ['EUR', 'Euro', '€']] as [$c, $n, $s]) {
            Moeda::create(['codigo' => $c, 'nome' => $n, 'simbolo' => $s, 'casas_decimais' => 2, 'ativo' => true]);
        }
        $this->gestor = $this->sessao(['config_moedas_view', 'config_moedas_gerir']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function servico(): ServicoCambiosBAIAutomaticos
    {
        return app(ServicoCambiosBAIAutomaticos::class);
    }

    #[Test]
    public function a_tarefa_esta_agendada_e_so_corre_quando_devida(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'sistema:cambios-bai');
        $this->assertNotNull($evento, 'tarefa sistema:cambios-bai não agendada');
        $this->assertSame('* * * * *', $evento->expression);

        Http::fake([ServicoCambiosBAI::URL => Http::response(self::HTML)]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00'));
        $this->assertNull($this->servico()->executarSeDevido(), 'desligada por omissão');
        Http::assertNothingSent();

        $this->servico()->gravarConfiguracao(true, '08:30');
        Carbon::setTestNow(Carbon::parse('2026-10-06 08:29:00'));
        $this->assertNull($this->servico()->executarSeDevido(), 'antes da hora');

        Carbon::setTestNow(Carbon::parse('2026-10-06 08:31:00'));
        $exec = $this->servico()->executarSeDevido();
        $this->assertSame(['AGENDADA', 'SUCESSO', 2], [$exec['origem'], $exec['estado'], $exec['moedas']]);
        $this->assertSame(2, DB::table('cambios_bai_pendentes')->where('estado', 'PENDENTE')->count());
        $this->assertSame(0, TaxaCambio::query()->count(), 'nada é gravado nos câmbios sem validação');

        Carbon::setTestNow(Carbon::parse('2026-10-06 15:00:00'));
        $this->assertNull($this->servico()->executarSeDevido(), 'uma obtenção com sucesso por dia');

        // no dia seguinte volta a correr e substitui as pendentes anteriores
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:30:00'));
        $this->assertNotNull($this->servico()->executarSeDevido());
        $this->assertSame(2, DB::table('cambios_bai_pendentes')->where('estado', 'PENDENTE')->count());
        $this->assertSame(2, DB::table('cambios_bai_pendentes')->where('estado', 'SUBSTITUIDO')->count());
        $this->assertSame(['2026-10-07'], DB::table('cambios_bai_pendentes')->where('estado', 'PENDENTE')->distinct()->pluck('data_cotacao')->map(fn ($d) => substr((string) $d, 0, 10))->all());
    }

    #[Test]
    public function falha_do_bai_e_registada_repetida_com_intervalo_e_informada_na_saude(): void
    {
        Http::fake([ServicoCambiosBAI::URL => Http::response('bloqueado', 403)]);
        $this->servico()->gravarConfiguracao(true, '08:00');

        Carbon::setTestNow(Carbon::parse('2026-10-06 08:00:00'));
        $exec = $this->servico()->executarSeDevido();
        $this->assertSame(['FALHA', 'BAI_INDISPONIVEL'], [$exec['estado'], $exec['codigo_erro']]);
        $this->assertSame(0, DB::table('cambios_bai_pendentes')->count());

        Carbon::setTestNow(Carbon::parse('2026-10-06 08:20:00'));
        $this->assertNull($this->servico()->executarSeDevido(), 'intervalo de 30 minutos entre tentativas');
        Carbon::setTestNow(Carbon::parse('2026-10-06 08:31:00'));
        $this->assertNotNull($this->servico()->executarSeDevido());
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:02:00'));
        $this->assertNotNull($this->servico()->executarSeDevido());
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00'));
        $this->assertNull($this->servico()->executarSeDevido(), 'no máximo 3 tentativas por dia');
        $this->assertSame(3, DB::table('execucoes_cambios_bai')->where('estado', 'FALHA')->count());

        // a falha aparece no ecrã e no /api/saude como informação (o serviço continua OK)
        $this->getJson('/api/sistema/cambios/bai/automatico', $this->gestor)->assertOk()
            ->assertJsonPath('dados.ultima_falha.codigo_erro', 'BAI_INDISPONIVEL')->assertJsonPath('dados.ativo', true)->assertJsonPath('dados.hora', '08:00');
        $this->getJson('/api/saude')->assertOk()->assertJsonPath('dados.estado', 'OK')->assertJsonPath('dados.informacao.cambios_bai.estado', 'FALHA');

        // obtenção manual com o BAI em baixo: 502 com o código, registada
        $this->postJson('/api/sistema/cambios/bai/automatico/obter', [], $this->gestor)->assertStatus(502)->assertJsonPath('codigo', 'BAI_INDISPONIVEL');
        $this->assertSame(4, DB::table('execucoes_cambios_bai')->where('estado', 'FALHA')->count());
    }

    #[Test]
    public function pendentes_so_sao_gravados_depois_de_validados_com_auditoria_e_podem_ser_rejeitados(): void
    {
        Http::fake([ServicoCambiosBAI::URL => Http::response(self::HTML)]);
        TaxaCambio::create(['codigo_moeda' => 'USD', 'data_taxa' => now()->subDays(3)->toDateString(), 'taxa' => 800, 'fonte_dados' => 'BNA']);

        $this->putJson('/api/sistema/cambios/bai/automatico', ['ativo' => true, 'hora' => '25:00'], $this->gestor)->assertStatus(422);
        $this->putJson('/api/sistema/cambios/bai/automatico', ['ativo' => true, 'hora' => '07:45'], $this->gestor)->assertOk()->assertJsonPath('dados.hora', '07:45');
        $this->assertSame('1', DB::table('configuracoes_sistema')->where('chave', 'cambios_bai_auto_ativo')->value('valor'));

        $this->postJson('/api/sistema/cambios/bai/automatico/obter', [], $this->gestor)->assertOk()->assertJsonCount(2, 'dados.pendentes');
        $this->assertSame(1, TaxaCambio::query()->count(), 'a obtenção não grava nos câmbios');

        $estado = $this->getJson('/api/sistema/cambios/bai/automatico', $this->gestor)->assertOk();
        $usd = collect($estado->json('dados.pendentes'))->firstWhere('codigo_moeda', 'USD');
        $this->assertSame([915.0, 800.0, 14.38, true], [$usd['media'], $usd['ultimo']['taxa'], $usd['variacao'], $usd['alerta']]);

        // só leitura: vê, mas não valida nem configura
        $leitor = $this->sessao(['config_moedas_view']);
        $this->getJson('/api/sistema/cambios/bai/automatico', $leitor)->assertOk();
        $this->postJson('/api/sistema/cambios/bai/pendentes/validar', ['moedas' => ['USD']], $leitor)->assertForbidden();
        $this->putJson('/api/sistema/cambios/bai/automatico', ['ativo' => false, 'hora' => '07:45'], $leitor)->assertForbidden();

        // validar USD grava a média guardada, para todas as empresas, origem BAI; EUR continua pendente
        $this->postJson('/api/sistema/cambios/bai/pendentes/validar', ['moedas' => ['usd']], $this->gestor)->assertOk()->assertJsonPath('dados.novos', 1);
        $t = TaxaCambio::query()->where('codigo_moeda', 'USD')->whereDate('data_taxa', now()->toDateString())->firstOrFail();
        $this->assertSame(['915.000000', 'BAI', null, '910.000000'], [(string) $t->taxa, $t->fonte_dados, $t->empresa_id, (string) $t->taxa_compra_bai]);
        $linha = DB::table('cambios_bai_pendentes')->where('codigo_moeda', 'USD')->first();
        $this->assertSame('VALIDADO', $linha->estado);
        $this->assertNotNull($linha->decidido_por_id);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Validação dos câmbios do BAI')->whereNotNull('utilizador_id')->exists());

        // validar de novo uma moeda já validada: recusado
        $this->postJson('/api/sistema/cambios/bai/pendentes/validar', ['moedas' => ['USD']], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'BAI_PENDENTE_INEXISTENTE');

        // rejeitar o EUR: nada gravado, auditado
        $this->postJson('/api/sistema/cambios/bai/pendentes/rejeitar', ['motivo' => 'Valor estranho'], $this->gestor)->assertOk()->assertJsonPath('dados.rejeitados', 1);
        $this->assertFalse(TaxaCambio::query()->where('codigo_moeda', 'EUR')->exists());
        $this->assertSame(['REJEITADO', 'Valor estranho'], [DB::table('cambios_bai_pendentes')->where('codigo_moeda', 'EUR')->value('estado'),
            DB::table('cambios_bai_pendentes')->where('codigo_moeda', 'EUR')->value('motivo')]);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Câmbios do BAI rejeitados')->exists());
        $this->getJson('/api/sistema/cambios/bai/automatico', $this->gestor)->assertJsonCount(0, 'dados.pendentes');
        $this->getJson('/api/saude')->assertOk()->assertJsonPath('dados.informacao.cambios_bai.estado', 'OK');
    }
}
