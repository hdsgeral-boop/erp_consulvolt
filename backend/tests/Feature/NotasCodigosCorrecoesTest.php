<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\LogAuditoria;
use App\Models\NotaDemonstracao;
use App\Models\PlanoConta;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Migracao\ConversorTipos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Correcções da análise de cálculos e fluxos (2026-10-02):
 *   E-CON-1 — «Sincronizar notas automática» (recoverDataMapping): nota pelo prefixo da conta nas linhas sem nota;
 *   E-CON-2 — espaços (incluindo U+00A0) nas pontas dos códigos: limpeza no ETL e validação de dados.
 */
final class NotasCodigosCorrecoesTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $notas = [];

    private int $diario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->em(function () {
            foreach (['4311' => 'Banco', '611' => 'Vendas', '7521' => 'Serviços', '3111' => 'Clientes', '881' => 'Resultados', '9111' => 'Analítica'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            foreach (['10' => 'Disponibilidades', '22' => 'Vendas', '29' => 'Fornecimentos'] as $c => $d) {   // sem a nota 9 (clientes)
                $this->notas[$c] = NotaDemonstracao::create(['codigo' => (string) $c, 'descricao' => $d])->id;
            }
            $this->diario = DiarioContabil::create(['codigo' => 'DG', 'nome' => 'Diário geral'])->id;
        });
        $this->s = $this->sessao(['config_ferramentas', 'config_manutencao_view']);
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function lancar(string $data, string $numero, array $linhas): void
    {
        $this->em(fn () => app(ServicoLancamentos::class)->criar(['diario_id' => $this->diario, 'data_documento' => $data, 'numero_documento' => $numero,
            'descricao' => $numero, 'linhas' => $linhas]));
    }

    /** @return array<string, ?int> conta => nota da linha */
    private function notasDe(string $numero): array
    {
        return $this->em(fn () => LancamentoContabil::query()->where('numero_documento', $numero)->orderBy('id')->get()
            ->mapWithKeys(fn ($l) => [$l->codigo_conta => $l->nota_demonstracao_id !== null ? (int) $l->nota_demonstracao_id : null])->all());
    }

    #[Test]
    public function sincronizar_notas_pela_conta_simula_por_omissao_e_aplica_so_a_linhas_sem_nota(): void
    {
        $ano = (int) now()->format('Y');
        $this->lancar("{$ano}-03-10", 'V1', [['codigo_conta' => '3111', 'tipo_dc' => 'D', 'valor' => 114], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => 114]]);
        $this->lancar("{$ano}-03-11", 'R1', [['codigo_conta' => '4311', 'tipo_dc' => 'D', 'valor' => 114], ['codigo_conta' => '3111', 'tipo_dc' => 'C', 'valor' => 114]]);
        // nota escolhida pelo utilizador (diferente da regra): nunca é substituída
        $this->lancar("{$ano}-03-12", 'S1', [['codigo_conta' => '7521', 'tipo_dc' => 'D', 'valor' => 50, 'nota_demonstracao_id' => $this->notas['22']],
            ['codigo_conta' => '4311', 'tipo_dc' => 'C', 'valor' => 50], ['codigo_conta' => '9111', 'tipo_dc' => 'D', 'valor' => 5], ['codigo_conta' => '881', 'tipo_dc' => 'C', 'valor' => 5]]);
        // exercício anterior encerrado: fica de fora
        $this->lancar(($ano - 1).'-12-31', 'A1', [['codigo_conta' => '4311', 'tipo_dc' => 'D', 'valor' => 10], ['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => 10]]);
        $this->em(fn () => app(ServicoExercicios::class)->encerrar($this->empresa->id, $ano - 1));

        $url = '/api/contabilidade/tabelas/notas-demonstracao/sincronizar-por-conta';
        $this->postJson($url, [], $this->sessao(['lancamentos_view', 'config_manutencao_view']))->assertForbidden();

        // simulação (omissão): nada gravado
        $sim = $this->postJson($url, [], $this->s)->assertOk()->json('dados');
        $this->assertFalse($sim['aplicado']);
        $this->assertSame(3, $sim['linhas_a_atribuir']);   // 611 (V1) e 4311 (R1, S1) do ano corrente
        $this->assertSame(['10' => 2, '22' => 1], collect($sim['por_nota'])->pluck('linhas', 'nota')->all());
        $this->assertSame([['nota' => '9', 'linhas' => 2]], $sim['nota_em_falta']);   // 3111 → nota 9, que a empresa não tem
        $this->assertSame(['88' => 1, '91' => 1], collect($sim['sem_regra'])->pluck('linhas', 'prefixo')->all());
        $this->assertSame([$ano - 1], $sim['exercicios_encerrados']);
        $this->assertSame(2, $sim['linhas_em_exercicios_encerrados']);
        $this->assertSame(['3111' => null, '611' => null], $this->notasDe('V1'));

        // aplicar: só linhas sem nota, nunca no exercício encerrado, com auditoria
        $this->postJson($url, ['aplicar' => true], $this->s)->assertOk()->assertJsonPath('dados.aplicado', true)->assertJsonPath('dados.linhas_a_atribuir', 3);
        $this->assertSame(['3111' => null, '611' => $this->notas['22']], $this->notasDe('V1'));
        $this->assertSame(['4311' => $this->notas['10'], '3111' => null], $this->notasDe('R1'));
        $this->assertSame(['7521' => $this->notas['22'], '4311' => $this->notas['10'], '9111' => null, '881' => null], $this->notasDe('S1'));
        $this->assertSame(['4311' => null, '611' => null], $this->notasDe('A1'));
        $log = app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('acao', 'NOTAS_POR_CONTA')->sole());
        $this->assertEquals([10, 22], array_keys($log->dados_novos['linhas_por_nota']));   // chave = código da nota
        $this->assertCount(2, $log->dados_novos['linhas_por_nota']['10']);

        // nada mais a fazer
        $this->postJson($url, ['aplicar' => true], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NADA_A_FAZER');
    }

    #[Test]
    public function etl_retira_espacos_das_pontas_dos_codigos_e_validacao_lista_os_gravados(): void
    {
        $ocorrencias = [];
        $c = new ConversorTipos(function (string $regra) use (&$ocorrencias) {
            $ocorrencias[] = $regra;
        });
        $this->assertSame('75216', $c->converter("75216\u{00A0}", 'varchar(20)'));   // U+00A0 nas pontas: em qualquer texto
        $this->assertSame('Ana Maria', $c->converter("\u{2007}Ana Maria\u{202F}", 'text'));
        $this->assertSame(' Ana ', $c->converter(' Ana ', 'text'));   // espaços ASCII de um nome mantêm-se (validação terceiros_nome_com_espacos)
        $this->assertSame('P-01', $c->converter(" P-01\t", 'varchar(50)', 'codigo'));   // numa coluna de código também os ASCII
        $this->assertSame('3111', $c->converter("\u{00A0}3111 ", 'varchar(20)', 'codigo_conta'));
        $this->assertNull($c->converter("\u{00A0} ", 'varchar(20)', 'conta_custo'));
        $this->assertSame(1234, $c->converter(' 1234 ', 'bigint', 'codigo'));
        $this->assertContains('NORMALIZACAO', $ocorrencias);
        $this->assertTrue(ConversorTipos::colunaDeCodigo('numero_conta') && ConversorTipos::colunaDeCodigo('reconciliacao_codigo'));
        $this->assertFalse(ConversorTipos::colunaDeCodigo('nome') || ConversorTipos::colunaDeCodigo('descricao') || ConversorTipos::colunaDeCodigo(null));

        // dados já gravados com espaços (como a conta «75216 » migrada): a validação lista-os
        $this->em(function () {
            $id = DB::table('lancamentos_contabeis')->insertGetId(['empresa_id' => $this->empresa->id, 'diario_id' => $this->diario, 'data_documento' => now()->toDateString(),
                'numero_lan' => 'X1', 'codigo_conta' => "75216\u{00A0}", 'tipo_dc' => 'D', 'valor' => 404.76]);
            DB::table('produtos')->insert(['empresa_id' => $this->empresa->id, 'codigo' => 'PRD 1 ', 'nome' => 'Produto']);
            $this->assertGreaterThan(0, $id);
        });
        $v = collect($this->getJson('/api/sistema/validacoes', $this->s)->assertOk()->json('dados'))->keyBy('codigo');
        $this->assertSame(2, $v['codigos_com_espacos_nas_pontas']['ocorrencias']);
        $linhas = $this->getJson('/api/sistema/validacoes/codigos_com_espacos_nas_pontas', $this->s)->assertOk()->json('dados.linhas');
        $this->assertSame([['Lançamentos (conta)', "[75216\u{00A0}]", 1], ['Produto', '[PRD 1 ]', 1]],
            array_map(fn ($l) => [$l['origem'], $l['codigo_gravado'], (int) $l['registos']], $linhas));
    }
}
