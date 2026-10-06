<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Colaborador;
use App\Models\ConfigAssiduidade;
use App\Models\ContratoTrabalho;
use App\Models\CoordenadaBancariaColaborador;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoFeriasColaborador;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\ServicoCalendarioRH;
use App\Services\RH\ServicoFerias;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 2 (RH): decisões 1, 3, 5, 6 e 7 do utilizador e lacunas A-08 (cópia, lotes, importação Excel, eliminar período),
 * A-09 (importação de colaboradores e contratos, rubricas em massa), A-10 (recibos PDF/ZIP, mapeamentos em falta
 * detalhados) e M-12 (relógio biométrico lido pelo servidor).
 */
final class RHRonda2Test extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    private string $mes;

    private const TODAS = ['calcular_view', 'processamento_view', 'calcular_lancar', 'calcular_bulk', 'calcular_folha', 'rh_lanc_del', 'processamento_validate',
        'processamento_integrate', 'processamento_reopen', 'rh_recibos_emitir', 'colaboradores_view', 'colaboradores_import', 'contratos_import', 'contratos_new',
        'rh_ferias_view', 'rh_ferias_edit', 'rh_assiduidade_view', 'rh_assid_registar', 'rh_assid_config', 'infotipos_view', 'rh_tabela_irt_gerir', 'config_empresas_gerir'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mes = now()->format('m/Y');
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Fictícia, Lda', 'nif' => '5000000000']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores']);
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true']);
            $alim = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de alimentação', 'sujeito_inss' => false, 'irt' => 'conditional_30k']);
            $adi = InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Adiantamento', 'sujeito_inss' => false, 'irt' => 'false']);
            $ana = Colaborador::create(['nome_completo' => 'Ana Exemplo', 'nif' => '111111111LA011', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org->id, 'dias_uteis_mes' => 22]);
            $bea = Colaborador::create(['nome_completo' => 'Beatriz Exemplo', 'nif' => '222222222LA022', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org->id, 'dias_uteis_mes' => 22]);
            ContratoTrabalho::create(['colaborador_id' => $ana->id, 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8,
                'data_inicio' => now()->subYear()->toDateString(), 'remuneracoes' => [['infotipo_id' => $base->id, 'valor_mes' => 300000]]]);
            $this->ids = ['org' => $org->id, 'base' => $base->id, 'alim' => $alim->id, 'adi' => $adi->id, 'ana' => $ana->id, 'bea' => $bea->id];
        });
        $this->s = $this->sessao(self::TODAS);
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

    private function periodo(?string $mes = null): int
    {
        return $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => $mes ?? $this->mes], $this->s)->assertCreated()->json('dados.id');
    }

    private function lancar(int $p, int $colab, int $rubrica, float $valor, ?float $dias = null): void
    {
        $this->postJson("/api/rh/salarios/periodos/{$p}/lancamentos", ['colaborador_id' => $colab, 'infotipo_salarial_id' => $rubrica, 'valor' => $valor,
            'dias_trabalhados' => $dias], $this->s)->assertCreated();
    }

    /** Ficheiro XLSX em memória com os cabeçalhos e as linhas indicados. */
    private function xlsx(array $linhas, string $folha = 'Template'): UploadedFile
    {
        $livro = new Spreadsheet;
        $livro->getActiveSheet()->setTitle($folha)->fromArray($linhas);
        $caminho = tempnam(sys_get_temp_dir(), 'xlsx_').'.xlsx';
        (new Xlsx($livro))->save($caminho);

        return new UploadedFile($caminho, 'importacao.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    #[Test]
    public function encerrar_com_avisos_exige_confirmacao_e_segregacao_configuravel(): void
    {
        $p = $this->periodo();
        $this->lancar($p, $this->ids['ana'], $this->ids['base'], 300000, 24);   // 24 dias > 22 do contrato → horas extra automáticas
        $r = $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'AVISOS_POR_CONFIRMAR');
        $this->assertStringContainsString('Horas extra automáticas', $r->json('erros.avisos.0.avisos.0'));
        $this->assertSame('Ana Exemplo', $r->json('erros.avisos.0.nome'));
        $this->assertSame('ABERTO', $this->em(fn () => PeriodoProcessamentoSalarial::query()->find($p)->estado));   // nada mudou
        $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", ['confirmar_avisos' => true], $this->s)->assertOk()->assertJsonPath('dados.estado', 'FECHADO');

        // decisão 5: desligada por omissão (o mesmo utilizador valida); ligada, só outro utilizador valida
        $this->getJson('/api/rh/configuracao', $this->s)->assertOk()->assertJsonPath('dados.segregar_encerrar_validar', false);
        $this->putJson('/api/rh/configuracao', ['segregar_encerrar_validar' => true], $this->sessao(['calcular_view', 'rh_ferias_edit']))->assertStatus(403);
        $this->putJson('/api/rh/configuracao', ['segregar_encerrar_validar' => true], $this->s)->assertOk()->assertJsonPath('dados.segregar_encerrar_validar', true);
        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SEGREGACAO_FUNCOES');
        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $this->sessao(['processamento_validate']))->assertOk()->assertJsonPath('dados.estado', 'VALIDADO');
    }

    #[Test]
    public function tabela_de_irt_configuravel_e_reposta(): void
    {
        $p = $this->periodo();
        $this->lancar($p, $this->ids['ana'], $this->ids['base'], 300000);
        // base IRT 291 000 → 31 250 + 91 000 × 18 % = 47 630 (tabela do engine_v2.js)
        $this->getJson("/api/rh/salarios/periodos/{$p}", $this->s)->assertJsonPath('dados.resultados.0.irt', '47630.00');
        $tabela = $this->getJson('/api/rh/configuracao', $this->s)->assertJsonPath('dados.tabela_irt_personalizada', false)->json('dados.tabela_irt');
        $this->assertSame([150000, 0, 0, 0], array_map(fn ($v) => (int) $v, array_values($tabela[0])));
        $tabela[2]['taxa'] = 20;   // 200 000–300 000 passa a 20 %
        $this->putJson('/api/rh/tabela-irt', ['escaloes' => $tabela], $this->sessao(['infotipos_view']))->assertStatus(403);
        $sem = $tabela;
        $sem[1]['max'] = 100000;   // limites não crescentes
        $this->putJson('/api/rh/tabela-irt', ['escaloes' => $sem], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'TABELA_IRT_INVALIDA');
        $this->putJson('/api/rh/tabela-irt', ['escaloes' => $tabela], $this->s)->assertOk();
        $this->getJson("/api/rh/salarios/periodos/{$p}", $this->s)->assertJsonPath('dados.resultados.0.irt', '49450.00')   // 31 250 + 91 000 × 20 %
            ->assertJsonPath('dados.resultados.0.irt_escalao.taxa', 20.0);
        $this->deleteJson('/api/rh/tabela-irt', [], $this->s)->assertOk();
        $this->getJson("/api/rh/salarios/periodos/{$p}", $this->s)->assertJsonPath('dados.resultados.0.irt', '47630.00');
    }

    #[Test]
    public function copiar_lancar_em_lote_editar_e_eliminar_seleccionados_e_eliminar_o_periodo(): void
    {
        $anterior = $this->periodo(now()->subMonth()->format('m/Y'));
        $this->lancar($anterior, $this->ids['ana'], $this->ids['base'], 300000);
        $this->lancar($anterior, $this->ids['ana'], $this->ids['adi'], 20000);
        $this->lancar($anterior, $this->ids['bea'], $this->ids['base'], 250000);
        $this->em(fn () => Colaborador::query()->whereKey($this->ids['bea'])->update(['estado' => 'INACTIVO']));

        $p = $this->periodo();
        $this->lancar($p, $this->ids['ana'], $this->ids['adi'], 5000);
        $c = $this->postJson("/api/rh/salarios/periodos/{$p}/copiar", ['origem_id' => $anterior], $this->s)->assertOk()->json('dados');
        $this->assertSame([1, 0, 1], [$c['copiados'], $c['substituidos'], $c['ja_existentes']]);
        $this->assertCount(1, $c['ignorados']);   // a colaboradora inactiva
        $this->postJson("/api/rh/salarios/periodos/{$p}/copiar", ['origem_id' => $anterior, 'substituir' => true], $this->s)->assertOk()->assertJsonPath('dados.substituidos', 2);
        $this->postJson("/api/rh/salarios/periodos/{$p}/copiar", ['origem_id' => $p], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MESMO_PERIODO');

        // lote com várias rubricas (valor e horas) para dois colaboradores
        $this->em(fn () => Colaborador::query()->whereKey($this->ids['bea'])->update(['estado' => 'ACTIVO']));
        $this->postJson("/api/rh/salarios/periodos/{$p}/lancamentos/lote", ['colaboradores' => [$this->ids['ana'], $this->ids['bea']],
            'rubricas' => [['infotipo_salarial_id' => $this->ids['alim'], 'valor' => 25000], ['infotipo_salarial_id' => $this->ids['adi'], 'valor' => 0]]], $this->s)
            ->assertOk()->assertJsonPath('dados.gravados', 2);   // a rubrica sem valor é ignorada
        $ids = $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p)->where('infotipo_salarial_id', $this->ids['alim'])->pluck('id')->all());
        $this->putJson("/api/rh/salarios/periodos/{$p}/lancamentos/lote", ['ids' => $ids, 'campos' => ['valor' => 30000]], $this->s)->assertOk()->assertJsonPath('dados.alterados', 2);
        $this->assertEquals([30000, 30000], $this->em(fn () => LinhaFolhaSalarial::query()->whereKey($ids)->pluck('valor')->map(fn ($v) => (float) $v)->all()));
        $this->deleteJson("/api/rh/salarios/periodos/{$p}/lancamentos/lote", ['ids' => [$ids[0], $anterior * 100000]], $this->s)->assertStatus(422);
        $this->deleteJson("/api/rh/salarios/periodos/{$p}/lancamentos/lote", ['ids' => $ids], $this->s)->assertOk()->assertJsonPath('dados.eliminados', 2);

        // eliminar o período em aberto (não um encerrado)
        $this->postJson("/api/rh/salarios/periodos/{$anterior}/encerrar", ['confirmar_avisos' => true], $this->s)->assertOk();
        $this->deleteJson("/api/rh/salarios/periodos/{$anterior}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_ESTADO_INVALIDO');
        $this->deleteJson("/api/rh/salarios/periodos/{$p}", [], $this->sessao(['calcular_folha']))->assertStatus(403);
        $this->deleteJson("/api/rh/salarios/periodos/{$p}", [], $this->s)->assertOk();
        $this->assertSame(0, $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p)->count()));
        $this->assertNull($this->em(fn () => PeriodoProcessamentoSalarial::query()->find($p)));
    }

    #[Test]
    public function importa_o_calculo_de_excel_com_simulacao_e_tudo_ou_nada(): void
    {
        $p = $this->periodo();
        $this->get('/api/rh/importacoes/modelos/calculo', $this->s)->assertOk()->assertHeader('content-disposition', 'attachment; filename=Template_Calculo.xlsx');
        $com = $this->xlsx([['NIF', 'Rubrica', 'Valor', 'Horas', 'Dias trabalhados'], ['111111111LA011', 'Salário Base', '300.000,00', '', '22'],
            ['Beatriz Exemplo', 'subsidio de alimentacao', 25000, '', ''], ['999', 'Salário Base', 1, '', ''], ['111111111LA011', 'Salário Base', 1, '', '']]);
        $sim = $this->post("/api/rh/salarios/periodos/{$p}/importar-excel", ['ficheiro' => $com, 'simular' => true], $this->s)->assertOk()->json('dados');
        $this->assertSame([2, 1, 1], [$sim['criados'], $sim['repetidos'], count($sim['rejeitadas'])]);
        $this->assertSame(0, $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p)->count()));   // simulação não grava
        $this->post("/api/rh/salarios/periodos/{$p}/importar-excel", ['ficheiro' => $com], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS');
        $bom = $this->xlsx([['Colaborador', 'Rubrica', 'Valor'], ['111111111LA011', 'Salário Base', 300000], ['222222222LA022', 'Adiantamento', 1500.5]]);
        $this->post("/api/rh/salarios/periodos/{$p}/importar-excel", ['ficheiro' => $bom], $this->s)->assertOk()->assertJsonPath('dados.criados', 2);
        $this->assertEquals(1500.5, (float) $this->em(fn () => LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p)
            ->where('colaborador_id', $this->ids['bea'])->value('valor')));
    }

    #[Test]
    public function importa_colaboradores_e_contratos_e_aplica_rubricas_em_massa(): void
    {
        $this->em(fn () => Banco::create(['nome' => 'Banco Fictício', 'codigo' => '0040']));
        $f = $this->xlsx([['Nome', 'NIF', 'Estado', 'Data de Admissão', 'Estado Civil', 'Banco', 'IBAN', 'Avençado'],
            ['Carlos Exemplo', '333333333LA033', 'ACTIVO', '15/02/2026', 'Casado(a)', '0040', 'AO98004000000000000000123', 'Não'],
            ['Ana Actualizada', '111111111LA011', '', '', '', '', '', ''],
            ['Sem NIF', '', '', '', '', '', '', '']], 'Colaboradores');
        $sim = $this->post('/api/rh/importacoes/colaboradores', ['ficheiro' => $f, 'simular' => true], $this->s)->assertOk()->json('dados');
        $this->assertSame([1, 1, 1], [$sim['novos'], count($sim['existentes']), count($sim['rejeitadas'])]);
        $this->post('/api/rh/importacoes/colaboradores', ['ficheiro' => $f], $this->s)->assertStatus(422);
        $f = $this->xlsx([['Nome', 'NIF', 'Estado', 'Data de Admissão', 'Estado Civil', 'Banco', 'IBAN'],
            ['Carlos Exemplo', '333333333LA033', 'ACTIVO', '15/02/2026', 'Casado(a)', '0040', 'AO98004000000000000000123'], ['Ana Actualizada', '111111111LA011', '', '', '', '', '']], 'Colaboradores');
        $this->post('/api/rh/importacoes/colaboradores', ['ficheiro' => $f], $this->sessao(['colaboradores_view']))->assertStatus(403);
        $this->post('/api/rh/importacoes/colaboradores', ['ficheiro' => $f, 'decisao' => 'ACTUALIZAR'], $this->s)->assertOk()
            ->assertJsonPath('dados.criados', 1)->assertJsonPath('dados.actualizados', 1);
        $carlos = $this->em(fn () => Colaborador::query()->where('nif', '333333333LA033')->first());
        $this->assertSame(['2026-02-15', 'CASADO'], [$carlos->data_admissao->toDateString(), $carlos->estado_civil]);
        $this->assertSame('Ana Actualizada', $this->em(fn () => Colaborador::query()->find($this->ids['ana'])->nome_completo));
        $this->assertNotNull($this->em(fn () => CoordenadaBancariaColaborador::query()->where('colaborador_id', $carlos->id)->value('iban')));

        // contratos (vertical): duas linhas do mesmo NIF = um contrato; quem já tem contrato sobreposto é recusado
        $c = $this->xlsx([['NIF Colaborador', 'Infotipo', 'Valor_Mensal', 'Dias_Mes', 'Horas_Dia', 'Data_Inicio', 'Data_Fim', 'Estado'],
            ['333333333LA033', 'Salário Base', 200000, 22, 8, '01022026', '', 'ACTIVO'], ['333333333LA033', 'Subsídio de alimentação', 20000, '', '', '', '', '']]);
        $this->post('/api/rh/importacoes/contratos', ['ficheiro' => $c], $this->s)->assertOk()->assertJsonPath('dados.criados', 1);
        $ct = $this->em(fn () => ContratoTrabalho::query()->where('colaborador_id', $carlos->id)->first());
        $this->assertCount(2, $ct->remuneracoes);
        $this->post('/api/rh/importacoes/contratos', ['ficheiro' => $c], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS');

        // rubricas em massa: Ana (com contrato) acrescenta; Carlos substitui; Beatriz (sem contrato) cria
        $massa = ['colaboradores' => [$this->ids['ana'], $carlos->id, $this->ids['bea']], 'rubricas' => [['infotipo_salarial_id' => $this->ids['alim'], 'valor_mes' => 30000]],
            'modo' => 'SUBSTITUIR', 'criar' => true, 'novos' => ['data_inicio' => now()->startOfMonth()->toDateString()]];
        $sim = $this->postJson('/api/rh/contratos/massa', $massa + ['simular' => true], $this->s)->assertOk()->json('dados');
        $this->assertSame([2, 1], [count($sim['actualizados']), count($sim['criados'])]);
        $this->assertSame(0, $this->em(fn () => ContratoTrabalho::query()->where('colaborador_id', $this->ids['bea'])->count()));
        $this->postJson('/api/rh/contratos/massa', $massa, $this->s)->assertOk();
        $this->postJson('/api/rh/contratos/massa', $massa, $this->s)->assertOk()->assertJsonCount(3, 'dados.sem_alteracao');
        $rem = collect($this->em(fn () => ContratoTrabalho::query()->where('colaborador_id', $carlos->id)->first()->remuneracoes))->keyBy('infotipo_id');
        $this->assertEquals(30000, $rem[$this->ids['alim']]['valor_mes']);
    }

    #[Test]
    public function recibos_em_pdf_e_zip_e_mapeamentos_em_falta_detalhados(): void
    {
        $p = $this->periodo();
        $this->lancar($p, $this->ids['ana'], $this->ids['base'], 300000);
        $this->lancar($p, $this->ids['bea'], $this->ids['base'], 200000);
        $this->get("/api/rh/salarios/periodos/{$p}/recibos-zip", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_NAO_VALIDADO');
        $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", [], $this->s)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $this->s)->assertOk();
        $pdf = $this->get("/api/rh/salarios/periodos/{$p}/recibos/{$this->ids['ana']}/pdf", $this->s)->assertOk()->assertHeader('content-type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('RECIBO DE VENCIMENTO', $pdf);
        $this->assertStringContainsString('DUPLICADO', $pdf);
        $this->assertStringContainsString('Importância líquida', iconv('CP1252', 'UTF-8', $pdf));
        $zip = $this->get("/api/rh/salarios/periodos/{$p}/recibos-zip", $this->s)->assertOk()->assertHeader('content-type', 'application/zip');
        $caminho = tempnam(sys_get_temp_dir(), 'zip_');
        file_put_contents($caminho, $zip->streamedContent());
        $z = new \ZipArchive;
        $z->open($caminho);
        $this->assertSame(2, $z->numFiles);
        $this->assertStringStartsWith('RV_', $z->getNameIndex(0));
        $z->close();
        $this->get("/api/rh/salarios/periodos/{$p}/recibos-zip?colaboradores={$this->ids['bea']}", $this->sessao(['rh_rel_recibos_view']))->assertStatus(403);

        // contabilizar sem mapeamentos: a lista estruturada alimenta o assistente do ecrã
        $r = $this->postJson("/api/rh/salarios/periodos/{$p}/contabilizar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'MAPEAMENTO_EM_FALTA')->json('erros.em_falta_detalhe');
        $rubrica = collect($r)->firstWhere('tipo', 'RUBRICA');
        $this->assertSame([$this->ids['base'], $this->ids['org'], false], [$rubrica['infotipo_salarial_id'], $rubrica['tipo_organizacao_id'], $rubrica['avencado']]);
        $this->assertContains('NET_PAY_CREDIT', collect($r)->where('tipo', 'SISTEMA')->pluck('codigo')->all());
    }

    #[Test]
    public function ferias_proporcionais_do_ano_de_admissao_transporte_de_saldo_e_feriados_nacionais(): void
    {
        $ano = (int) now()->format('Y');
        $this->em(fn () => Colaborador::query()->whereKey($this->ids['bea'])->update(['data_admissao' => "{$ano}-03-10"]));
        $f = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoFerias::class));
        // admitida a 10/03: meses completos até 31/12 = 9 (10/03–09/12) → 18 dias
        $this->assertSame(18, $this->em(fn () => $f->direito($this->ids['bea'], $ano)));
        $this->assertSame(0, $this->em(fn () => $f->direito($this->ids['bea'], $ano - 1)));
        // transporte: ano anterior com plano de 22 e 15 marcados → +7 no ano seguinte
        $this->em(fn () => PlanoFeriasColaborador::create(['colaborador_id' => $this->ids['ana'], 'ano' => $ano - 1, 'data_inicio' => ($ano - 1).'-07-01',
            'data_fim' => ($ano - 1).'-07-21', 'dias' => 15, 'direito' => 22, 'estado' => 'GOZADO']));
        $calc = $this->em(fn () => $f->calculoDireito($this->ids['ana'], $ano));
        $this->assertSame([22, 7, 29], [$calc['base'], $calc['transporte'], $calc['total']]);
        $this->putJson('/api/rh/configuracao', ['ferias_transporte_saldo' => false], $this->s)->assertOk();
        $this->assertSame(22, $this->em(fn () => $f->direito($this->ids['ana'], $ano)));
        // antiguidade: gozo antes de 6 meses pede confirmação
        $ini = "{$ano}-04-06";
        $this->postJson('/api/rh/ferias', ['colaborador_id' => $this->ids['bea'], 'data_inicio' => $ini, 'data_fim' => "{$ano}-04-08"], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'ANTIGUIDADE_INSUFICIENTE');

        // feriados nacionais de Angola: lista para confirmar (Páscoa de 2026 a 5 de Abril → Sexta-feira Santa a 3/4, Carnaval a 17/2)
        $l = collect($this->getJson('/api/rh/assiduidade/feriados-nacionais?ano=2026', $this->s)->assertOk()->json('dados'))->pluck('nome', 'data');
        $this->assertSame('Sexta-feira Santa', $l['2026-04-03']);
        $this->assertSame('Carnaval', $l['2026-02-17']);
        $this->assertSame('Dia da Independência Nacional', $l['2026-11-11']);
        $this->assertCount(12, $l);
        $this->assertCount(12, ServicoCalendarioRH::feriadosNacionais(2027));
    }

    #[Test]
    public function relogio_biometrico_lido_pelo_servidor(): void
    {
        $this->postJson('/api/rh/assiduidade/registos/importar-relogio', [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RELOGIO_SEM_URL');
        $this->em(fn () => ConfigAssiduidade::query()->create(['relogio' => ['url' => 'http://192.168.1.50/export', 'formato' => 'JSON']]));
        $ontem = now()->subDay()->toDateString();
        Http::fake(['192.168.1.50/*' => Http::response(['dados' => [['nif' => '111111111LA011', 'data' => $ontem, 'entrada' => '08:00', 'saida' => '17:00'],
            ['nif' => '000', 'data' => $ontem, 'entrada' => '08:00', 'saida' => '17:00']]])]);
        $r = $this->postJson('/api/rh/assiduidade/registos/importar-relogio', [], $this->s)->assertOk()->json('dados');
        $this->assertSame([1, 1, '192.168.1.50'], [$r['gravados'], count($r['erros']), $r['fonte']]);
        $this->em(fn () => ConfigAssiduidade::query()->update(['relogio' => ['url' => 'http://192.168.1.51/export', 'formato' => 'CSV']]));
        Http::fake(['192.168.1.51/*' => Http::response('erro', 500)]);
        $this->postJson('/api/rh/assiduidade/registos/importar-relogio', [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RELOGIO_ERRO');
    }

    #[Test]
    public function chefia_ve_a_equipa_e_rh_ve_autoavaliacoes_e_chefias(): void
    {
        // M-11: Ana é a chefia directa da Beatriz; o utilizador do portal está ligado à Ana
        $this->em(fn () => Colaborador::query()->whereKey($this->ids['bea'])->update(['colaborador_gestor_id' => $this->ids['ana']]));
        $chefe = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'rh_portal_usar' => true])->id]);
        $chefe->empresas()->attach($this->empresa->id, ['colaborador_id' => $this->ids['ana']]);
        $sc = $this->entrar($chefe) + ['X-Empresa-Id' => $this->empresa->id];
        $ano = (int) now()->format('Y');
        $e = $this->getJson("/api/rh/avaliacao/equipa?ano={$ano}&periodo=ANUAL", $sc)->assertOk()->json('dados');
        $this->assertSame([$this->ids['bea']], array_column($e['membros'], 'colaborador_id'));
        $this->assertNull($e['membros'][0]['avaliacao']);
        // a colaboradora sem equipa vê a lista vazia; sem ligação ao colaborador é recusado
        $this->getJson("/api/rh/avaliacao/equipa?ano={$ano}&periodo=ANUAL", $this->sessao(['rh_portal_usar']))->assertStatus(403);

        $rh = $this->sessao(['rh_portal_aprovar']);
        $this->getJson("/api/rh/avaliacao/autoavaliacoes?ano={$ano}&periodo=ANUAL", $rh)->assertOk()->assertJsonCount(0, 'dados');
        $this->getJson('/api/rh/avaliacao/chefias', $rh)->assertOk()->assertJsonPath('dados.0.colaborador_id', $this->ids['ana'])->assertJsonPath('dados.0.equipa', 1);
        $this->getJson('/api/rh/avaliacao/chefias', $sc)->assertStatus(403);
    }

    #[Test]
    public function importa_registos_de_produtividade(): void
    {
        $s = $this->sessao(['rh_produtividade_view', 'rh_prod_registar', 'rh_prod_periodo', 'rh_prod_config', 'contratos_new']);
        $item = $this->postJson('/api/rh/produtividade/itens', ['codigo' => 'P01', 'descricao' => 'Peças', 'unidade' => 'un', 'preco_unitario' => 100,
            'infotipo_salarial_id' => $this->ids['base']], $s)->assertCreated()->json('dados.id');
        $this->em(fn () => ContratoTrabalho::query()->where('colaborador_id', $this->ids['ana'])->update(['produtividade' => [['item_id' => $item, 'preco_unitario' => null]]]));
        $mes = now()->subMonth();
        $p = $this->postJson('/api/rh/produtividade/periodos', ['mes' => $mes->format('Y-m'), 'data_inicio' => $mes->startOfMonth()->toDateString(),
            'data_fim' => $mes->copy()->endOfMonth()->toDateString()], $s)->assertCreated()->json('dados.id');
        $f = $this->xlsx([['NIF', 'Código do item', 'Quantidade', 'Data', 'Observações'], ['111111111LA011', 'p01', 12, '', ''], ['222222222LA022', 'P01', 5, '', '']], 'Produtividade');
        $this->post("/api/rh/produtividade/periodos/{$p}/importar", ['ficheiro' => $f], $s)->assertStatus(422)->assertJsonPath('codigo', 'IMPORTACAO_COM_ERROS');   // Beatriz sem o item
        $f = $this->xlsx([['NIF', 'Código do item', 'Quantidade'], ['111111111LA011', 'p01', 12]], 'Produtividade');
        $this->post("/api/rh/produtividade/periodos/{$p}/importar", ['ficheiro' => $f], $s)->assertOk()->assertJsonPath('dados.criados', 1);
        $this->post("/api/rh/produtividade/periodos/{$p}/importar", ['ficheiro' => $f], $s)->assertOk()->assertJsonPath('dados.ignorados', 1);
        $this->getJson("/api/rh/produtividade/periodos/{$p}", $s)->assertJsonCount(1, 'dados.registos')->assertJsonPath('dados.registos.0.valor', '1200.00');
    }

    #[Test]
    public function simula_a_massa_salarial_dos_contratos_sem_gravar(): void
    {
        $r = $this->getJson('/api/rh/contratos/simulacao?mes_ano='.urlencode($this->mes), $this->s)->assertOk()->json('dados');
        $this->assertCount(1, $r['resultados']);   // só a Ana tem contrato
        $this->assertSame(['300000.00', '47630.00'], [$r['resultados'][0]['bruto'], $r['resultados'][0]['irt']]);
        $this->assertCount(1, $r['ignorados']);
        $this->assertSame(0, $this->em(fn () => PeriodoProcessamentoSalarial::query()->count()));
        $this->getJson('/api/rh/contratos/simulacao?mes_ano=13/2026', $this->s)->assertStatus(422);
    }
}
