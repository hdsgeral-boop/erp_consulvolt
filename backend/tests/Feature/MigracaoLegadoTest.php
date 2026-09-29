<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ETL do backup legado (erp:migrar-backup-legado) sobre um backup Dexie sintético que exercita
 * cada regra de integridade (ADR-003/004/005/016/017/020) — o backup real é validado à parte.
 */
final class MigracaoLegadoTest extends TestCase
{
    private string $ficheiro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ficheiro = tempnam(sys_get_temp_dir(), 'backup_').'.json';
        file_put_contents($this->ficheiro, json_encode($this->backup(), JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        @unlink($this->ficheiro);
        parent::tearDown();
    }

    /** Backup Dexie mínimo com todos os casos especiais conhecidos do legado. */
    private function backup(): array
    {
        $mestre = ['is_master_data' => 1, 'name' => 'SISTEMA - DADO MESTRE', 'description' => 'REGISTO MESTRE OBRIGATÓRIO (NÃO APAGAR)'];
        $t = fn (string $nome, array $linhas) => ['tableName' => $nome, 'inbound' => true, 'rows' => $linhas];

        return ['formatName' => 'dexie', 'formatVersion' => 1, 'data' => ['databaseName' => 'WSTB_PayrollDB', 'data' => [
            $t('companies', [
                ['id' => 1, 'name' => 'WSTB, LDA', 'nif' => '5000000001', 'inss_patronal' => 8, 'inss_trabalhador' => 3],
                ['id' => 10, 'name' => 'EMPRESA DEZ, LDA', 'nif' => '5000000010', 'is_master_data' => 1, 'company_id' => 3, 'value' => 0, 'code' => 'MASTER'],
                ['id' => 11, 'name' => 'SISTEMA - DADO MESTRE', 'nif' => '999999999', 'is_master_data' => 1],
                ['id' => 22, 'name' => 'HOLDING', 'nif' => '5000000022', 'is_consolidation' => true, 'consolidation_run_id' => 99],
            ]),
            $t('user_profiles', [['id' => 1, 'name' => 'Visualizador', 'permissions' => ['_v2' => true, 'config_logs_view' => true]]]),
            $t('users', [
                ['id' => 1, 'username' => 'admin', 'role' => 'superadmin', 'profile_id' => 1, 'allowed_companies' => [],
                    'password_hash' => 'osuPYeOnI5bTXe+Ib78Bc3Lto7iOlxpsOeEcyEuD79U=', 'password_salt' => 'ABEiM0RVZneImaq7zN3u/w==',
                    'password_algo' => 'PBKDF2-SHA256-120000', 'colaboradores' => ['1' => 5, '11' => 7]],
                ['id' => 2, 'username' => 'celso', 'role' => 'viewer', 'profile_id' => 77, 'allowed_companies' => [],
                    'password_hash' => 'osuPYeOnI5bTXe+Ib78Bc3Lto7iOlxpsOeEcyEuD79U=', 'password_salt' => 'ABEiM0RVZneImaq7zN3u/w=='],
            ]),
            $t('employees', [['id' => 5, 'company_id' => 1, 'name' => 'Ana', 'status' => 'Não ACTIVO', 'estado_civil' => 'Casado(a)'], ['id' => 6, 'company_id' => 1] + $mestre]),
            $t('org_types', [['id' => 1, 'company_id' => 1, 'name' => 'Direcção'], ['id' => 2, 'company_id' => 2, 'name' => 'Empresa eliminada']]),
            $t('infotypes', [['id' => 1, 'company_id' => 1, 'name' => 'Subsídio de alimentação', 'type' => 'VENCIMENTO', 'irt' => 'conditional_30k']]),
            $t('accounting_mapos', [['id' => 1, 'company_id' => 1, 'infotype_id' => 1, 'org_type_id' => -1, 'account_code' => '7211'],
                ['id' => 2, 'company_id' => 1, 'infotype_id' => 1, 'org_type_id' => 1, 'account_code' => '7212']]),
            $t('chart_of_accounts', [['id' => 1, 'company_id' => 1, 'code' => '71511', 'description' => 'Vendas', 'type' => 'M'],
                ['id' => 2, 'company_id' => 1, 'code' => '71511', 'description' => 'Duplicada', 'type' => 'M']]),
            $t('journals', [['id' => 1, 'company_id' => 1, 'code' => 'VD', 'description' => 'VENDAS'], ['id' => 2, 'company_id' => 11, 'code' => 'CO', 'description' => 'Fictícia']]),
            $t('journal_lines', [
                ['id' => 10, 'company_id' => 1, 'journal_id' => 1, 'doc_date' => '2026-03-15', 'entry_date' => '15-03-2026', 'account_code' => '31', 'value' => 100.004, 'type_dc' => 'D', 'description' => 'x'],
                ['id' => 11, 'company_id' => 1, 'journal_id' => 1, 'doc_date' => '2026-08', 'account_code' => '61', 'value' => 100, 'type_dc' => 'C', 'description' => 'só mês'],
                ['id' => 12, 'company_id' => 1, 'journal_id' => 'NaN', '$types' => ['journal_id' => 'nan'], 'doc_date' => '2026-03-15', 'account_code' => '61', 'value' => 5, 'type_dc' => 'C'],
                ['id' => 13, 'company_id' => 1, 'journal_id' => 103, 'doc_date' => '2026-03-15', 'account_code' => '31', 'value' => 5, 'type_dc' => 'D'],
                ['id' => 14, 'company_id' => 22, 'journal_id' => null, 'consolidation_run_id' => 5, 'doc_date' => '2026-03-15', 'account_code' => '31', 'value' => 1, 'type_dc' => 'D'],
            ]),
            $t('consolidation_groups', [['id' => 2, 'holding_company_id' => 22, 'last_run_id' => 5]]),
            $t('consolidation_runs', [['id' => 5, 'group_id' => 2, 'holding_company_id' => 22, 'status' => 'CONCLUIDA']]),
            $t('third_parties', [['id' => 1, 'company_id' => 1, 'nif' => '5417', 'name' => 'Cliente', 'type' => 'Colaborador']]),
            $t('sales', [
                ['id' => 1, 'company_id' => 1, 'customer_id' => 1, 'doc_type' => 'Factura', 'doc_number' => 'FT 2026/1', 'status' => 'Pendente', 'total_gross' => 114, 'pos_session_id' => 'POS_SESS_1787321170305'],
                ['id' => 2, 'company_id' => 1, 'customer_id' => 1, 'doc_type' => 'Nota de CRÉDITO', 'doc_number' => 'NC 2026/1', 'related_doc_id' => '1,999'],
            ]),
            $t('sale_items', [['id' => 1, 'sale_id' => 1, 'quantity' => 1, 'unit_price' => 100], ['id' => 2, 'sale_id' => 777, 'quantity' => 1]]),
            $t('purchase_requests', [['id' => 3, 'company_id' => 1, 'status' => 'PENDENTE']]),
            $t('purchase_items', [['id' => 1, 'parent_id' => 3, 'parent_type' => 'REQUEST', 'quantity' => 2], ['id' => 2, 'parent_id' => 9, 'quantity' => 1]]),
            $t('warehouses', [['id' => 1, 'company_id' => 1, 'name' => 'Principal']]),
            $t('delivery_notes', [['id' => 4, 'company_id' => 1, 'warehouse_id' => 1, 'doc_number' => 'GS 2026/1']]),
            $t('purchase_deliveries', [['id' => 4, 'company_id' => 1, 'warehouse_id' => 1]]),
            $t('delivery_items', [['id' => 1, 'delivery_id' => 4, 'quantity' => 1, 'fx_q1' => 1, 'unit_cost_kz' => 10], ['id' => 2, 'delivery_id' => 4, 'quantity' => 3]]),
            $t('treasury_documents', [['id' => 1, 'company_id' => 1, 'type' => 'PAGAMENTO', 'total_value' => 50]]),
            $t('treasury_items', [['id' => 1, 'doc_id' => 1, 'value' => 50, 'type_dc' => 'D'], ['id' => 2, 'doc_id' => 555, 'value' => 9, 'type_dc' => 'C']]),
            $t('system_config', [['key' => 'closed_year_1_2025', 'value' => 'true']]),
            $t('audit_logs', [
                ['id' => 1, 'company_id' => 1, 'timestamp' => '2026-05-01T03:26:59.105Z', 'user' => ['username' => 'admin', 'role' => 'superadmin'], 'module' => 'RH', 'action' => 'Criou', 'record_id' => [15, 91], 'details' => 'x'],
                ['id' => 2, 'company_id' => 21, 'timestamp' => '2026-09-15T05:02:20.390Z', 'user' => 'admin', 'module' => 'Navegação', 'action' => 'Acesso', 'record_id' => 'welcome'],
            ]),
        ]]];
    }

    private function migrar(array $opcoes = []): int
    {
        return $this->artisan('erp:migrar-backup-legado', ['caminho' => $this->ficheiro] + $opcoes)->run();
    }

    private function ocorrencias(string $regra): int
    {
        return DB::table('ocorrencias_migracao')->where('regra', $regra)->count();
    }

    #[Test]
    public function migra_o_backup_aplicando_todas_as_regras_de_integridade(): void
    {
        $this->assertSame(0, $this->migrar());

        // Empresas: 11 (fictícia) descartada; 10 (flag mestre mas real) mantida
        $this->assertSame([1, 10, 22], DB::table('empresas')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertNull(DB::table('empresas')->where('id', 22)->value('execucao_consolidacao_id'));   // run 99 inexistente -> anulada

        // Linha "dado mestre" descartada; normalização com texto original
        $this->assertSame(1, DB::table('colaboradores')->count());
        $this->assertSame(['INACTIVO', 'Não ACTIVO', 'CASADO'], array_values((array) DB::table('colaboradores')->select('estado', 'estado_original', 'estado_civil')->first()));
        $venda = DB::table('vendas')->where('id', 1)->first();
        $this->assertSame(['FT', 'Factura', 'PENDENTE'], [$venda->tipo_documento, $venda->tipo_documento_original, $venda->estado]);
        $this->assertSame('NC', DB::table('vendas')->where('id', 2)->value('tipo_documento'));
        $this->assertSame('COLABORADOR', DB::table('terceiros')->value('tipo'));

        // POS_SESS_ do localStorage -> código legado; lista de ids -> pivô (999 inexistente ignorado)
        $this->assertSame('POS_SESS_1787321170305', $venda->sessao_pos_legado_codigo);
        $this->assertNull($venda->sessao_pos_id);
        $this->assertSame([[2, 1]], DB::table('vendas_documentos_relacionados')->get()->map(fn ($r) => [(int) $r->venda_id, (int) $r->venda_relacionada_id])->all());

        // org_type_id = -1 -> Avençado
        $mapa = DB::table('mapeamentos_contabeis_rh')->orderBy('id')->get();
        $this->assertTrue((bool) $mapa[0]->avencado);
        $this->assertNull($mapa[0]->tipo_organizacao_id);
        $this->assertFalse((bool) $mapa[1]->avencado);

        // Quarentena: conta duplicada, diário e tipo de órgão de empresas não migradas, linhas sem documento-pai
        $quarentena = DB::table('quarentena_migracao')->pluck('tabela_legado')->countBy()->all();
        $this->assertEquals(['chart_of_accounts' => 1, 'journals' => 1, 'org_types' => 1, 'sale_items' => 1, 'purchase_items' => 1, 'treasury_items' => 1], $quarentena);

        // Lançamentos: diário NaN/inexistente -> diário REC; data só com mês -> dia 1; DD-MM-AAAA; arredondamento ao cêntimo
        $rec = DB::table('diarios_contabeis')->where('empresa_id', 1)->where('codigo', 'REC')->value('id');
        $this->assertNotNull($rec);
        $this->assertSame([(int) $rec, (int) $rec], DB::table('lancamentos_contabeis')->whereIn('id', [12, 13])->orderBy('id')->pluck('diario_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame('2026-08-01', DB::table('lancamentos_contabeis')->where('id', 11)->value('data_documento'));
        $this->assertStringStartsWith('2026-03-15 00:00:00', DB::table('lancamentos_contabeis')->where('id', 10)->value('data_lancamento'));
        $this->assertSame('100.00', DB::table('lancamentos_contabeis')->where('id', 10)->value('valor'));
        $this->assertSame(1, $this->ocorrencias('DATA_SO_MES'));
        $this->assertSame(1, $this->ocorrencias('ARREDONDAMENTO'));
        $this->assertSame(2, $this->ocorrencias('DIARIO_RECUPERACAO'));

        // Consolidação: empresa = holding
        $this->assertSame(22, (int) DB::table('execucoes_consolidacao')->where('id', 5)->value('empresa_id'));

        // Polimórficos: itens_compra por parent_type; delivery_items pelo tipo de campos
        $this->assertSame(3, (int) DB::table('itens_compra')->where('id', 1)->value('pedido_compra_id'));
        $itens = DB::table('itens_guia_saida')->orderBy('id')->get();
        $this->assertSame([4, null], [(int) $itens[0]->rececao_compra_id, $itens[0]->guia_saida_id]);
        $this->assertSame([null, 4], [$itens[1]->rececao_compra_id, (int) $itens[1]->guia_saida_id]);

        // Empresa derivada do documento-pai
        $this->assertSame(1, (int) DB::table('itens_venda')->value('empresa_id'));
        $this->assertSame(1, (int) DB::table('itens_documento_tesouraria')->value('empresa_id'));

        // Sem id no legado (system_config): gerado pela base
        $this->assertSame('closed_year_1_2025', DB::table('configuracoes_sistema')->value('chave'));

        // Auditoria: objecto de sessão -> username; lista -> texto; empresa eliminada mantida (sem FK)
        $logs = DB::table('logs_auditoria')->orderBy('id')->get();
        $this->assertSame(['admin', '15,91', 21], [$logs[0]->nome_utilizador, $logs[0]->registo_id, (int) $logs[1]->empresa_id]);

        // Utilizadores: allowed_companies vazio -> acesso a todas; perfil inexistente anulado; colaborador de empresa não migrada ignorado
        $celso = DB::table('utilizadores')->where('nome_utilizador', 'celso')->first();
        $this->assertTrue((bool) $celso->acesso_todas_empresas);
        $this->assertNull($celso->perfil_utilizador_id);
        $this->assertSame([[1, 5]], DB::table('utilizador_empresa')->where('utilizador_id', 1)->get()->map(fn ($r) => [(int) $r->empresa_id, (int) $r->colaborador_id])->all());

        // Sequences recalibradas: o próximo id continua a numeração do legado
        $this->assertSame(15, (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('lancamentos_contabeis', 'id')) AS n")->n);

        // Execução registada
        $execucao = DB::table('execucoes_migracao')->first();
        $this->assertSame('CONCLUIDA', $execucao->estado);
        $this->assertSame(hash_file('sha256', $this->ficheiro), $execucao->sha256);
        $this->assertSame(0, DB::table('etl_linhas_legado')->count());   // área de preparação limpa
    }

    #[Test]
    public function utilizador_migrado_entra_com_a_palavra_passe_do_legado(): void
    {
        $this->migrar();

        $this->postJson('/api/autenticacao/entrar', ['nome_utilizador' => 'admin', 'palavra_passe' => 'Senha@Legado2026'])
            ->assertOk()
            ->assertJsonPath('dados.permissoes', ['*'])
            ->assertJsonCount(3, 'dados.empresas');
        $this->assertStringStartsWith('$argon2id$', DB::table('utilizadores')->where('nome_utilizador', 'admin')->value('palavra_passe'));
    }

    #[Test]
    public function simulacao_valida_tudo_e_nao_grava_nada(): void
    {
        $this->assertSame(0, $this->migrar(['--simular' => true]));

        $this->assertSame(0, DB::table('empresas')->count());
        $this->assertSame(0, DB::table('lancamentos_contabeis')->count());
        $this->assertSame('SIMULADA', DB::table('execucoes_migracao')->value('estado'));
        $this->assertNotNull(DB::table('execucoes_migracao')->value('relatorio'));
    }

    #[Test]
    public function recusa_migrar_sobre_dados_existentes_sem_substituir(): void
    {
        $this->migrar();

        $this->assertSame(1, $this->migrar());
        $this->assertSame('FALHADA', DB::table('execucoes_migracao')->orderByDesc('id')->value('estado'));
        $this->assertSame(0, $this->migrar(['--substituir' => true, '--force' => true]));
        $this->assertSame(3, DB::table('empresas')->count());
    }

    #[Test]
    public function recusa_ficheiros_que_nao_sao_exports_dexie(): void
    {
        file_put_contents($this->ficheiro, json_encode(['formatName' => 'outro', 'data' => []]));

        $this->assertSame(1, $this->migrar());
        $this->assertSame(0, DB::table('empresas')->count());
    }
}
