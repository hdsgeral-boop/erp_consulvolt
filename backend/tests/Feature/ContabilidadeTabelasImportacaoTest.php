<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\LancamentoEstornado;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\PlanoConta;
use App\Models\SaldoHistorico;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contabilidade parte 2 (ADR-055): tabelas auxiliares (diários, notas, centros de custo, unidades de negócio), cópia e envio
 * entre empresas, reciclagem, importação de lançamentos por Excel e saldos históricos.
 */
final class ContabilidadeTabelasImportacaoTest extends TestCase
{
    private Empresa $empresa;

    private Empresa $outra;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        $this->outra = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['311', 'Clientes'], ['4311', 'Banco'], ['621', 'Serviços'], ['911', 'Centro A'], ['912', 'Centro B']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            PlanoConta::create(['codigo' => '31', 'descricao' => 'Terceiros', 'tipo' => 'T']);
            DiarioContabil::create(['codigo' => 'VD', 'descricao' => 'Vendas']);
            NotaDemonstracao::create(['codigo' => '10', 'descricao' => 'Disponibilidades']);
            NotaDemonstracao::create(['codigo' => '23', 'descricao' => 'Prestações de serviços']);
            NotaDemonstracao::create(['codigo' => '4.10', 'descricao' => 'Sub-nota']);
            NotaFluxoCaixa::create(['codigo' => '111', 'descricao' => 'Recebimentos']);
            Terceiro::create(['nif' => '5000001111', 'nome' => 'Cliente A', 'tipo' => 'CLIENTE']);
        });
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys(['tabelas_aux_view', 'aux_gerir', 'aux_eliminar',
            'aux_reciclagem', 'lancamentos_view', 'lancamentos_import', 'lancamentos_saldos'], true))->id]);
        $u->empresas()->attach([$this->empresa->id, $this->outra->id]);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function xlsx(array $linhas): UploadedFile
    {
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray($linhas, null, 'A1', true);
        $f = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($ss))->save($f);

        return new UploadedFile($f, 'importacao.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function como(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    #[Test]
    public function tabelas_auxiliares_crud_importacao_copia_envio_e_sincronizacao(): void
    {
        $id = $this->postJson('/api/contabilidade/tabelas/diarios', ['codigo' => 'cx', 'descricao' => 'Caixa'], $this->s)->assertCreated()->assertJsonPath('dados.codigo', 'CX')->json('dados.id');
        $this->postJson('/api/contabilidade/tabelas/diarios', ['codigo' => 'CX', 'descricao' => 'Outro'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_DUPLICADO');
        $this->putJson("/api/contabilidade/tabelas/diarios/{$id}", ['codigo' => 'CX', 'descricao' => 'Caixa geral'], $this->s)->assertOk()->assertJsonPath('dados.descricao', 'Caixa geral');
        // eliminar um diário com lançamentos é recusado (o legado deixava-os órfãos)
        $this->como(fn () => LancamentoContabil::create(['diario_id' => $id, 'data_documento' => '2026-01-05', 'codigo_conta' => '4311', 'tipo_dc' => 'D', 'valor' => 1]));
        $this->deleteJson("/api/contabilidade/tabelas/diarios/{$id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $vazio = $this->postJson('/api/contabilidade/tabelas/centros-custo', ['codigo' => 'CC-1', 'descricao' => 'Administração'], $this->s)->assertCreated()->json('dados.id');
        $this->deleteJson("/api/contabilidade/tabelas/centros-custo/{$vazio}", [], $this->s)->assertOk();

        // importação: cria, ignora existentes (ou actualiza se pedido), conta repetidos e erros
        $f = fn () => $this->xlsx([['Código', 'Descrição'], ['22', 'Vendas'], ['23', 'Serviços (novo)'], ['22', 'Repetido'], ['', 'Sem código']]);
        $this->postJson('/api/contabilidade/tabelas/notas-demonstracao/importar', ['ficheiro' => $f()], $this->s)->assertOk()
            ->assertJsonPath('dados', ['criados' => 1, 'actualizados' => 0, 'ignorados' => 1, 'repetidos' => 1, 'erros' => 1]);
        $this->postJson('/api/contabilidade/tabelas/notas-demonstracao/importar', ['ficheiro' => $f(), 'actualizar_existentes' => 1], $this->s)->assertOk()
            ->assertJsonPath('dados.actualizados', 2);
        $this->assertSame('Serviços (novo)', $this->como(fn () => NotaDemonstracao::query()->where('codigo', '23')->value('descricao')));

        // copiar para a outra empresa (só os códigos que faltam) e enviar um registo (substituição só confirmada)
        $s2 = ['X-Empresa-Id' => $this->outra->id] + $this->s;
        $this->postJson('/api/contabilidade/tabelas/notas-fluxo-caixa', ['codigo' => '111', 'descricao' => 'Já existe'], $s2)->assertCreated();
        $this->postJson('/api/contabilidade/tabelas/notas-fluxo-caixa/copiar', ['empresa_origem_id' => $this->empresa->id], $s2)->assertOk()
            ->assertJsonPath('dados.copiados', 0)->assertJsonPath('dados.existentes', 1);
        $this->postJson('/api/contabilidade/tabelas/notas-demonstracao/copiar', ['empresa_origem_id' => $this->empresa->id], $s2)->assertOk()->assertJsonPath('dados.copiados', 4);
        $nota = $this->como(fn () => NotaFluxoCaixa::query()->value('id'));
        $this->postJson("/api/contabilidade/tabelas/notas-fluxo-caixa/{$nota}/enviar", ['empresa_destino_id' => $this->outra->id], $this->s)
            ->assertStatus(409)->assertJsonPath('codigo', 'REGISTO_EXISTE_DESTINO');
        $this->postJson("/api/contabilidade/tabelas/notas-fluxo-caixa/{$nota}/enviar", ['empresa_destino_id' => $this->outra->id, 'substituir' => true], $this->s)
            ->assertOk()->assertJsonPath('dados.accao', 'SUBSTITUIDO');
        $this->assertSame('Recebimentos', app(ContextoEmpresa::class)->executarComo($this->outra->id, fn () => NotaFluxoCaixa::query()->value('descricao')));
        $sem = $this->criarEmpresa();
        $this->postJson('/api/contabilidade/tabelas/diarios/copiar', ['empresa_origem_id' => $sem->id], $this->s)->assertStatus(403)->assertJsonPath('codigo', 'SEM_ACESSO_EMPRESA');

        // centros de custo a partir das contas da classe 9
        $this->postJson('/api/contabilidade/tabelas/centros-custo/sincronizar', [], $this->s)->assertOk()->assertJsonPath('dados.criados', 2);
        $this->postJson('/api/contabilidade/tabelas/centros-custo/sincronizar', [], $this->s)->assertOk()->assertJsonPath('dados.criados', 0);
        $this->getJson('/api/contabilidade/tabelas/centros-custo', $this->s)->assertOk()->assertJsonCount(2, 'dados');
        $this->postJson('/api/contabilidade/tabelas/diarios', ['codigo' => 'X', 'descricao' => 'X'], $this->sessaoSo(['tabelas_aux_view']))->assertForbidden();
    }

    private function sessaoSo(array $perms): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($perms, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function unidades_de_negocio_com_hierarquia_sem_ciclos_e_eliminacao_protegida(): void
    {
        $pai = $this->postJson('/api/sistema/unidades-negocio', ['codigo' => 'UN1', 'nome' => 'Sede'], $this->s)->assertCreated()->assertJsonPath('dados.estado', 'ATIVO')->json('dados.id');
        $filho = $this->postJson('/api/sistema/unidades-negocio', ['codigo' => 'UN2', 'nome' => 'Filial', 'unidade_negocio_pai_id' => $pai], $this->s)->assertCreated()->json('dados.id');
        $this->postJson('/api/sistema/unidades-negocio', ['codigo' => 'un1', 'nome' => 'Dup'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'UNIDADE_DUPLICADA');
        $this->putJson("/api/sistema/unidades-negocio/{$pai}", ['unidade_negocio_pai_id' => $filho], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'HIERARQUIA_INVALIDA');
        $this->putJson("/api/sistema/unidades-negocio/{$filho}", ['valido_de' => '2026-05-01', 'valido_ate' => '2026-01-01'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DATAS_INVALIDAS');
        $this->deleteJson("/api/sistema/unidades-negocio/{$pai}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->deleteJson("/api/sistema/unidades-negocio/{$filho}", [], $this->s)->assertOk();
        $this->deleteJson("/api/sistema/unidades-negocio/{$pai}", [], $this->s)->assertOk();
    }

    #[Test]
    public function reciclagem_lista_restaura_como_lancamento_novo_e_elimina(): void
    {
        $diario = $this->como(fn () => DiarioContabil::query()->value('id'));
        $this->como(function () use ($diario) {
            foreach ([['VD2025000007', '311', 'D'], ['VD2025000007', '621', 'C'], ['VD2025000008', '311', 'D'], ['VD2025000008', '621', 'C']] as [$lan, $c, $dc]) {
                LancamentoEstornado::create(['diario_id' => $diario, 'data_documento' => '2026-02-10', 'numero_lan' => $lan, 'numero_documento' => 'FT 7',
                    'codigo_conta' => $c, 'tipo_dc' => $dc, 'valor' => 250]);
            }
        });
        $lista = $this->getJson('/api/contabilidade/reciclagem', $this->s)->assertOk()->json('dados');
        $this->assertCount(2, $lista);   // por lançamento (diário + chave), não só pelo n.º de documento
        $this->assertTrue($lista[0]['equilibrado']);
        $r = $this->postJson('/api/contabilidade/reciclagem/restaurar', ['grupos' => [['diario_id' => $diario, 'chave' => 'VD2025000007']]], $this->s)->assertOk()->json('dados');
        $this->assertSame('VD2026000001', $r[0]['numero_lan']);
        $this->assertSame(2, $this->como(fn () => LancamentoContabil::query()->where('numero_lan', 'VD2026000001')->count()));
        $this->postJson('/api/contabilidade/reciclagem/restaurar', ['grupos' => [['diario_id' => $diario, 'chave' => 'VD2025000007']]], $this->s)->assertStatus(404);
        $this->postJson('/api/contabilidade/reciclagem/eliminar', ['grupos' => [['diario_id' => $diario, 'chave' => 'VD2025000008']]], $this->s)->assertOk()->assertJsonPath('dados.linhas', 2);
        $this->deleteJson('/api/contabilidade/reciclagem', [], $this->s)->assertStatus(422);
        $this->deleteJson('/api/contabilidade/reciclagem', ['confirmar' => true], $this->s)->assertOk()->assertJsonPath('dados.linhas', 0);
    }

    #[Test]
    public function importacao_de_lancamentos_valida_tudo_avisa_e_grava_numa_transaccao(): void
    {
        $cab = ['Diário', 'Data Fiscal', 'Data Documento', 'Data Lancamento', 'No do documento', 'Referencia', 'Conta', 'D-C', 'Valor', 'NIF terceiro',
            'Nota demonstrações', 'Nota fluxo caixa', 'Unidade de Negocio', 'Centro de Custo', 'Descrição movimento', 'URL'];
        $ok = [$cab,
            ['VD', '15/03/2026', '14/03/2026', '', 'FT 1', 'R1', '311', 'D', '1.130,00', '5000001111', '', '', '', '', 'Factura', 'http://doc/1'],
            ['VD', '15/03/2026', '14/03/2026', '', 'FT 1', 'R1', '621', 'C', 1130, '', 'Nota 23', '', '', '', 'Factura', ''],
            ['VD', '2026-03-20', '', '', 'RC 1', '', '4311', 'D', 500, '', '10', '111', '', '', 'Recibo', ''],
            ['VD', '2026-03-20', '', '', 'RC 1', '', '311', 'C', 500, '5000001111', '4.1', '', 'UN-X', '', 'Recibo', ''],
        ];
        $sim = $this->postJson('/api/contabilidade/lancamentos/importar', ['ficheiro' => $this->xlsx($ok), 'simular' => 1], $this->s)->assertOk()->json('dados');
        $this->assertSame([2, 4, true], [$sim['documentos'], $sim['linhas'], $sim['simulacao']]);
        $this->assertSame(['Unidades de negócio: UN-X'], $sim['avisos']);   // "4.1" encontra a nota "4.10" (tolerância do legado)
        $this->postJson('/api/contabilidade/lancamentos/importar', ['ficheiro' => $this->xlsx($ok)], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_AVISOS');
        $r = $this->postJson('/api/contabilidade/lancamentos/importar', ['ficheiro' => $this->xlsx($ok), 'aceitar_avisos' => 1], $this->s)->assertCreated()->json('dados');
        $this->assertSame(['VD2026000001', 'VD2026000002'], $r['lancamentos']);
        $linha = $this->como(fn () => LancamentoContabil::query()->where('numero_documento', 'FT 1')->where('codigo_conta', '311')->first());
        $this->assertSame(['1130.00', 'http://doc/1', '2026-03-14'], [$linha->valor, $linha->url_documento, $linha->data_lancamento->toDateString()]);
        $this->assertNotNull($linha->terceiro_id);
        $this->assertSame('23', $this->como(fn () => LancamentoContabil::query()->where('numero_documento', 'FT 1')->where('codigo_conta', '621')->first()->notaDemonstracao?->codigo));

        // erros: desequilíbrio, conta totalizadora, diário inexistente e documento com dois diários → nada é gravado
        $mau = [$cab,
            ['VD', '2026-04-01', '', '', 'FT 2', '', '311', 'D', 100, '', '', '', '', '', '', ''],
            ['VD', '2026-04-01', '', '', 'FT 2', '', '621', 'C', 90, '', '', '', '', '', '', ''],
            ['XX', '2026-04-01', '', '', 'FT 3', '', '31', 'D', 10, '', '', '', '', '', '', ''],
            ['VD', '2026-04-02', '', '', 'FT 3', '', '621', 'C', 10, '', '', '', '', '', '', ''],
        ];
        $e = $this->postJson('/api/contabilidade/lancamentos/importar', ['ficheiro' => $this->xlsx($mau), 'aceitar_avisos' => 1], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_INVALIDA')->json('erros.erros');
        $this->assertCount(4, $e);
        $this->assertSame(4, $this->como(fn () => LancamentoContabil::query()->count()));
    }

    #[Test]
    public function saldos_historicos_gravam_o_resultado_e_respeitam_o_exercicio_encerrado(): void
    {
        $r = $this->putJson('/api/contabilidade/saldos-historicos/2025', ['demo' => ['23' => 1000, '10' => 400, 'res_liq' => 1], 'fluxo' => ['111' => 900, 'TOT2' => 50]], $this->s)
            ->assertOk()->json('dados');
        $this->assertSame(['registos' => 5, 'res_liq' => '1000.00'], ['registos' => $r['registos'], 'res_liq' => $r['res_liq']]);
        $g = $this->getJson('/api/contabilidade/saldos-historicos/2025', $this->s)->assertOk()->json('dados');
        $this->assertSame('1000.00', collect($g['demo'])->firstWhere('codigo', '23')['valor']);
        $this->assertSame(['1000.00', false], [$g['res_liq'], $g['encerrado']]);
        $this->putJson('/api/contabilidade/saldos-historicos/2025', ['demo' => ['99' => 1], 'fluxo' => []], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NOTA_INEXISTENTE');
        $this->putJson('/api/contabilidade/saldos-historicos/2025', ['demo' => [], 'fluxo' => ['999' => 1]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CODIGO_FLUXO_INVALIDO');
        $lido = $this->postJson('/api/contabilidade/saldos-historicos/importar', ['ficheiro' => $this->xlsx([['TIPO', 'CÓDIGO', 'VALOR'], ['DEMO', '23', 10], ['FLUXO', '111', 5], ['X', '1', 1]])], $this->s)
            ->assertOk()->json('dados');
        $this->assertSame([['23' => '10.00'], ['111' => '5.00'], 1], [$lido['demo'], $lido['fluxo'], $lido['ignoradas']]);
        DB::table('configuracoes_sistema')->insert(['chave' => "closed_year_{$this->empresa->id}_2025", 'valor' => 'true']);
        $this->putJson('/api/contabilidade/saldos-historicos/2025', ['demo' => [], 'fluxo' => []], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_ENCERRADO');
        $this->assertSame(5, $this->como(fn () => SaldoHistorico::query()->count()));
        $this->assertSame(0, $this->como(fn () => CentroCusto::query()->count()));
    }
}
