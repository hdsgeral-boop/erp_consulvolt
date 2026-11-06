<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PecaLavandaria;
use App\Services\POS\Lavandaria\RegrasLavandaria;
use App\Services\POS\ServicoMigracaoLavandaria;
use App\Support\POS\AuditoriaMigracaoLavandaria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F0b do POS — preservar os dados da lavandaria: opções de preço (lav_prices), modo/tamanho das linhas e de service_prices,
 * contadores FR; modo completar (idempotente, sem sobrescrever o editado no sistema novo, com relato de erros) e auditoria só de leitura.
 * Fixtures geradas por código (sem dados pessoais), no formato real do legado (js/lavandaria.js).
 */
final class MigracaoLavandariaF0bTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000051']);
    }

    private function naEmpresa(callable $fn): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $fn);
    }

    /** Peça tal como a deixou a carga original: sem lav_prices, service_prices em bruto (mode/size). */
    private function peca(string $codigo, array $extra = []): int
    {
        return $this->naEmpresa(fn () => DB::table('pecas_lavandaria')->insertGetId($extra + ['empresa_id' => $this->empresa->id, 'codigo' => $codigo, 'nome' => "Peça {$codigo}",
            'unidade' => 'PECA', 'preco' => 1140, 'ativo' => true,
            'precos_servico' => json_encode([['produto_id' => 5, 'preco' => 1500, 'mode' => 'KG', 'size' => ''], ['produto_id' => 5, 'preco' => 900, 'mode' => 'TAMANHO', 'size' => 'M']])]));
    }

    /** Ordem já normalizada pela carga antiga: chaves em português, mas `size` por traduzir. */
    private function ordem(string $numero, array $itens, array $extra = []): int
    {
        return $this->naEmpresa(fn () => DB::table('pedidos_lavandaria')->insertGetId($extra + ['empresa_id' => $this->empresa->id, 'numero_encomenda' => $numero,
            'estado' => 'RECEBIDA', 'modo_faturacao' => 'ENTREGA', 'recebido_em' => now(), 'data_prometida' => now()->addDays(2), 'itens' => json_encode($itens),
            'extras' => json_encode([]), 'historico_alteracoes' => json_encode([]), 'atribuicoes' => json_encode([])]));
    }

    private function linhaLegado(array $extra = []): array
    {
        return $extra + ['linha_id' => 1, 'nome' => 'Lavar', 'quantidade' => 2, 'preco' => 1140, 'valor' => '2280.00', 'estado' => 'RECEBIDA', 'unidade' => 'TAMANHO', 'size' => 'Casal',
            'taxa_imposto' => 14, 'etiquetas' => ['L0100001-01']];
    }

    /** lav_prices real: 3 opções válidas + 1 TAMANHO sem nome (que o legado também ignorava). */
    private function lavPrices(): array
    {
        return [['mode' => 'PECA', 'size' => '', 'price' => 1140], ['mode' => 'KG', 'size' => '', 'price' => 3000.5], ['mode' => 'TAMANHO', 'size' => ' Casal ', 'price' => 5000],
            ['mode' => 'TAMANHO', 'size' => '', 'price' => 10]];
    }

    private function terminal(array $extra = []): int
    {
        return $this->naEmpresa(fn () => DB::table('terminais_pos')->insertGetId($extra + ['empresa_id' => $this->empresa->id, 'codigo' => 'L01', 'nome' => 'Lavandaria', 'tipo' => 'LAVANDARIA',
            'meios_pagamento' => json_encode([]), 'ativo' => true]));
    }

    #[Test]
    public function completar_traduz_opcoes_de_preco_tamanhos_e_modos(): void
    {
        $peca = $this->peca('PC0001');
        $ordem = $this->ordem('OS/L01/2026/00001', [$this->linhaLegado()]);
        $terminal = $this->terminal();
        $r = app(ServicoMigracaoLavandaria::class)->completar(['pecas' => [$peca => ['empresa' => $this->empresa->id, 'codigo' => 'PC0001', 'lav_prices' => $this->lavPrices()]],
            'terminais' => [$terminal => ['empresa' => $this->empresa->id, 'codigo' => 'L01', 'fr' => ['2026' => 7, '2025' => 'x']]]]);

        $p = DB::table('pecas_lavandaria')->where('id', $peca)->first();
        $this->assertSame('LEGADO', $p->opcoes_preco_origem);
        $this->assertEquals([['modo' => 'PECA', 'tamanho' => '', 'preco' => '1140.00'], ['modo' => 'KG', 'tamanho' => '', 'preco' => '3000.50'],
            ['modo' => 'TAMANHO', 'tamanho' => 'Casal', 'preco' => '5000.00']], json_decode($p->opcoes_preco, true));
        $this->assertEquals([['produto_id' => 5, 'preco' => 1500, 'modo' => 'KG', 'tamanho' => ''], ['produto_id' => 5, 'preco' => 900, 'modo' => 'TAMANHO', 'tamanho' => 'M']],
            json_decode($p->precos_servico, true));
        $i = json_decode(DB::table('pedidos_lavandaria')->where('id', $ordem)->value('itens'), true)[0];
        $this->assertSame(['Casal', 'TAMANHO', 'TAMANHO'], [$i['tamanho'], $i['modo'], $i['unidade']]);
        $this->assertArrayNotHasKey('size', $i);
        $this->assertSame(['2026' => 7], json_decode(DB::table('terminais_pos')->where('id', $terminal)->value('lavandaria_contadores_fr'), true));
        $this->assertSame([1, 0, 1, 1, 1], [$r['pecas_com_opcoes_legado'], $r['pecas_com_opcoes_derivadas'], $r['pecas_precos_servico_traduzidos'], $r['pedidos_itens_traduzidos'], $r['terminais_contadores_fr']]);
        $this->assertCount(1, $r['erros']);   // a opção TAMANHO sem nome foi descartada e relatada
        $this->assertSame(['pecas_lavandaria', $peca], [$r['erros'][0]['tabela'], $r['erros'][0]['id']]);
        $this->assertStringContainsString('descartada', $r['erros'][0]['motivo']);
        // o modelo lê as opções (cast) e o resto da peça não muda
        $this->assertSame('Casal', $this->naEmpresa(fn () => PecaLavandaria::query()->findOrFail($peca))->opcoes_preco[2]['tamanho']);
        $this->assertSame('1140.00', number_format((float) $p->preco, 2, '.', ''));
        $this->assertSame('PECA', $p->unidade);
    }

    #[Test]
    public function completar_e_idempotente_e_os_totais_das_ordens_nao_mudam(): void
    {
        $peca = $this->peca('PC0001');
        $ordem = $this->ordem('OS/L01/2026/00001', [$this->linhaLegado(), $this->linhaLegado(['linha_id' => 2, 'unidade' => 'KG', 'size' => '', 'quantidade' => 1.5, 'preco' => 3000, 'valor' => '4500.00'])]);
        $legado = ['pecas' => [$peca => ['empresa' => $this->empresa->id, 'codigo' => 'PC0001', 'lav_prices' => $this->lavPrices()]]];
        $totais = fn () => RegrasLavandaria::totais(json_decode(DB::table('pedidos_lavandaria')->where('id', $ordem)->value('itens'), true), [], '0.00', '0.00')['total'];
        $antesTotal = $totais();
        $servico = app(ServicoMigracaoLavandaria::class);

        $servico->completar($legado);
        $apos1 = [DB::table('pecas_lavandaria')->where('id', $peca)->first(), DB::table('pedidos_lavandaria')->where('id', $ordem)->first()];
        $r2 = $servico->completar($legado);
        $apos2 = [DB::table('pecas_lavandaria')->where('id', $peca)->first(), DB::table('pedidos_lavandaria')->where('id', $ordem)->first()];

        $this->assertEquals($apos1, $apos2);
        $this->assertSame([0, 0, 0, 0, 0], [$r2['pecas_com_opcoes_legado'], $r2['pecas_com_opcoes_derivadas'], $r2['pecas_precos_servico_traduzidos'], $r2['pedidos_itens_traduzidos'], $r2['terminais_contadores_fr']]);
        $this->assertSame('6780.00', $antesTotal);
        $this->assertSame($antesTotal, $totais());   // o total da ordem antiga não muda
        $this->assertSame('KG', json_decode($apos2[1]->itens, true)[1]['modo']);
    }

    #[Test]
    public function completar_nao_sobrescreve_o_que_foi_editado_no_sistema_novo(): void
    {
        $novaOpcoes = [['modo' => 'PECA', 'tamanho' => '', 'preco' => '2000.00']];
        $editada = $this->peca('PC0001', ['opcoes_preco' => json_encode($novaOpcoes), 'opcoes_preco_origem' => 'NOVO', 'precos_servico' => json_encode([['produto_id' => 5, 'preco' => '2000.00']])]);
        $migrada = $this->peca('PC0002', ['opcoes_preco' => json_encode([['modo' => 'LEGADO-JA-LIDO', 'tamanho' => '', 'preco' => '1.00']]), 'opcoes_preco_origem' => 'LEGADO']);
        // ordem criada no sistema novo: sem nenhuma chave do legado (nem `materiais`: não se acrescenta nada)
        $linhaNova = ['linha_id' => 'a', 'nome' => 'Lavar', 'quantidade' => 1, 'preco' => '1140.00', 'valor' => '1140.00', 'estado' => 'RECEBIDA', 'unidade' => 'PECA'];
        $ordemNova = $this->ordem('OS/L01/2026/00002', [$linhaNova]);
        $terminal = $this->terminal(['lavandaria_contadores_fr' => json_encode(['2026' => 50])]);
        $antes = [DB::table('pecas_lavandaria')->whereIn('id', [$editada, $migrada])->orderBy('id')->get(), DB::table('pedidos_lavandaria')->where('id', $ordemNova)->first(),
            DB::table('terminais_pos')->where('id', $terminal)->first()];

        $legadoPecas = fn (int $id, string $codigo) => [$id => ['empresa' => $this->empresa->id, 'codigo' => $codigo, 'lav_prices' => $this->lavPrices()]];
        $r = app(ServicoMigracaoLavandaria::class)->completar(['pecas' => $legadoPecas($editada, 'PC0001') + $legadoPecas($migrada, 'PC0002'),
            'terminais' => [$terminal => ['empresa' => $this->empresa->id, 'codigo' => 'L01', 'fr' => ['2026' => 1]]]]);

        $depois = [DB::table('pecas_lavandaria')->whereIn('id', [$editada, $migrada])->orderBy('id')->get(), DB::table('pedidos_lavandaria')->where('id', $ordemNova)->first(),
            DB::table('terminais_pos')->where('id', $terminal)->first()];
        // só o precos_servico da peça editada (que já estava em português) e o das migradas em bruto é que se tocam: opções, ordem e terminal ficam iguais
        $this->assertEquals($antes[1], $depois[1]);
        $this->assertEquals($antes[2], $depois[2]);
        $this->assertEquals($novaOpcoes, json_decode($depois[0][0]->opcoes_preco, true));
        $this->assertSame('NOVO', $depois[0][0]->opcoes_preco_origem);
        $this->assertSame('LEGADO-JA-LIDO', json_decode($depois[0][1]->opcoes_preco, true)[0]['modo']);
        $this->assertEquals(json_decode($antes[0][0]->precos_servico, true), json_decode($depois[0][0]->precos_servico, true));
        $this->assertSame([0, 0, 0], [$r['pecas_com_opcoes_legado'], $r['pedidos_itens_traduzidos'], $r['terminais_contadores_fr']]);
    }

    #[Test]
    public function sem_backup_reconstroi_as_opcoes_como_derivadas_e_o_backup_substitui_so_as_derivadas(): void
    {
        $kg = $this->peca('PC0001', ['unidade' => 'KG', 'preco' => 3000, 'precos_servico' => null]);
        $servico = app(ServicoMigracaoLavandaria::class);
        $r = $servico->completar();
        $p = DB::table('pecas_lavandaria')->where('id', $kg)->first();
        $this->assertSame('DERIVADA', $p->opcoes_preco_origem);
        $this->assertEquals([['modo' => 'KG', 'tamanho' => '', 'preco' => '3000.00']], json_decode($p->opcoes_preco, true));
        $this->assertSame(1, $r['pecas_com_opcoes_derivadas']);

        $servico->completar();   // idempotente também sem backup
        $this->assertEquals($p, DB::table('pecas_lavandaria')->where('id', $kg)->first());

        $r = $servico->completar(['pecas' => [$kg => ['empresa' => $this->empresa->id, 'codigo' => 'PC0001', 'lav_prices' => [['mode' => 'KG', 'size' => '', 'price' => 3000], ['mode' => 'PECA', 'price' => 1000]]]]]);
        $p = DB::table('pecas_lavandaria')->where('id', $kg)->first();
        $this->assertSame(['LEGADO', 1], [$p->opcoes_preco_origem, $r['pecas_com_opcoes_legado']]);
        $this->assertSame(['KG', 'PECA'], array_column(json_decode($p->opcoes_preco, true), 'modo'));
    }

    #[Test]
    public function normalizar_da_carga_traduz_lav_prices_em_bruto_e_deriva_na_falta(): void
    {
        $comBruto = $this->peca('PC0001', ['opcoes_preco' => json_encode($this->lavPrices())]);   // como a carga (futura) grava lav_prices
        $semBruto = $this->peca('PC0002');
        app(ServicoMigracaoLavandaria::class)->normalizar();
        $a = DB::table('pecas_lavandaria')->where('id', $comBruto)->first();
        $b = DB::table('pecas_lavandaria')->where('id', $semBruto)->first();
        $this->assertSame(['LEGADO', 3, 'Casal'], [$a->opcoes_preco_origem, count(json_decode($a->opcoes_preco, true)), json_decode($a->opcoes_preco, true)[2]['tamanho']]);
        $this->assertSame('DERIVADA', $b->opcoes_preco_origem);
        $this->assertSame('M', json_decode($a->precos_servico, true)[1]['tamanho']);
    }

    #[Test]
    public function completar_relata_erros_sem_parar(): void
    {
        $outra = $this->peca('PC0001');
        $mal = $this->peca('PC0002');
        $ok = $this->peca('PC0003');
        $ordemMa = $this->naEmpresa(fn () => DB::table('pedidos_lavandaria')->insertGetId(['empresa_id' => $this->empresa->id, 'numero_encomenda' => 'OS/L01/2026/00009', 'estado' => 'RECEBIDA',
            'itens' => json_encode('isto não é uma lista de linhas')]));
        $terminal = $this->terminal();
        $r = app(ServicoMigracaoLavandaria::class)->completar(['pecas' => [
            $outra => ['empresa' => $this->empresa->id + 999, 'codigo' => 'PC0001', 'lav_prices' => $this->lavPrices()],
            $mal => ['empresa' => $this->empresa->id, 'codigo' => 'PC0002', 'lav_prices' => 'isto não é uma lista'],
            $ok => ['empresa' => $this->empresa->id, 'codigo' => 'PC0003', 'lav_prices' => $this->lavPrices()],
        ], 'terminais' => [$terminal => ['empresa' => $this->empresa->id, 'codigo' => 'OUTRO', 'fr' => ['2026' => 3]]]]);

        $motivos = collect($r['erros'])->map(fn ($e) => "{$e['tabela']}#{$e['id']}")->all();
        $this->assertContains("pecas_lavandaria#{$outra}", $motivos);   // empresa diferente no backup: não aplicado
        $this->assertContains("pecas_lavandaria#{$mal}", $motivos);     // lav_prices inválido
        $this->assertContains("terminais_pos#{$terminal}", $motivos);   // código diferente
        $this->assertSame('DERIVADA', DB::table('pecas_lavandaria')->where('id', $outra)->value('opcoes_preco_origem'));   // continua a reconstruir
        $this->assertSame('DERIVADA', DB::table('pecas_lavandaria')->where('id', $mal)->value('opcoes_preco_origem'));
        $this->assertSame('LEGADO', DB::table('pecas_lavandaria')->where('id', $ok)->value('opcoes_preco_origem'));   // o resto segue
        $this->assertNull(DB::table('terminais_pos')->where('id', $terminal)->value('lavandaria_contadores_fr'));
        $this->assertContains("pedidos_lavandaria#{$ordemMa}", $motivos);   // itens ilegíveis: ordem não tocada
    }

    #[Test]
    public function simular_desfaz_tudo(): void
    {
        $peca = $this->peca('PC0001');
        $r = app(ServicoMigracaoLavandaria::class)->completar(['pecas' => [$peca => ['empresa' => $this->empresa->id, 'codigo' => 'PC0001', 'lav_prices' => $this->lavPrices()]]], true);
        $this->assertTrue($r['simulacao']);
        $this->assertSame(1, $r['pecas_com_opcoes_legado']);
        $this->assertNull(DB::table('pecas_lavandaria')->where('id', $peca)->value('opcoes_preco'));
    }

    #[Test]
    public function le_lav_prices_e_contadores_fr_do_backup_dexie(): void
    {
        $ficheiro = tempnam(sys_get_temp_dir(), 'dexie');
        file_put_contents($ficheiro, json_encode(['formatName' => 'dexie', 'data' => ['data' => [
            ['tableName' => 'lav_pieces', 'rows' => [['id' => 2, 'lav_company_id' => 7, 'code' => 'PC0002', 'lav_prices' => $this->lavPrices()]]],
            ['tableName' => 'pos_terminals', 'rows' => [['id' => 3, 'pos_company_id' => 7, 'code' => 'L01', 'lav_fr_counters' => ['2026' => 4]]]],
            ['tableName' => 'outra', 'rows' => [['id' => 9]]],
        ]]]));
        try {
            $l = ServicoMigracaoLavandaria::legadoDoBackup($ficheiro);
        } finally {
            unlink($ficheiro);
        }
        $this->assertSame([7, 'PC0002', 4], [$l['pecas'][2]['empresa'], $l['pecas'][2]['codigo'], count($l['pecas'][2]['lav_prices'])]);
        $this->assertSame([7, 'L01', ['2026' => 4]], [$l['terminais'][3]['empresa'], $l['terminais'][3]['codigo'], $l['terminais'][3]['fr']]);
    }

    #[Test]
    public function comando_completar_em_simulacao_e_json(): void
    {
        $peca = $this->peca('PC0001');
        $this->artisan('erp:lavandaria:completar-migracao', ['--simular' => true, '--json' => true])->assertSuccessful();
        $this->assertNull(DB::table('pecas_lavandaria')->where('id', $peca)->value('opcoes_preco'));
        $this->artisan('erp:lavandaria:completar-migracao', ['--json' => true])->assertSuccessful();
        $this->assertSame('DERIVADA', DB::table('pecas_lavandaria')->where('id', $peca)->value('opcoes_preco_origem'));
    }

    #[Test]
    public function auditoria_detecta_perdas_inconsistencias_e_numeros_duplicados_sem_dados_pessoais(): void
    {
        // a F0 cria os UNIQUE quando os dados o permitem; o que se audita são exactamente os dados que os violariam
        foreach (['uq_pedidos_lavandaria_empresa_id_numero_encomenda', 'uq_pagamentos_lavandaria_empresa_id_numero_recibo', 'uq_pecas_lavandaria_empresa_id_codigo'] as $indice) {
            DB::statement("ALTER TABLE IF EXISTS pedidos_lavandaria DROP CONSTRAINT IF EXISTS {$indice}");
            DB::statement("ALTER TABLE IF EXISTS pagamentos_lavandaria DROP CONSTRAINT IF EXISTS {$indice}");
            DB::statement("ALTER TABLE IF EXISTS pecas_lavandaria DROP CONSTRAINT IF EXISTS {$indice}");
            DB::statement("DROP INDEX IF EXISTS {$indice}");
        }
        $semOpcoes = $this->peca('PC0001');                                  // P02 + P06 (service_prices com KG/TAMANHO, sem lav_prices)
        $this->peca('PC0001');                                               // P08 código repetido
        $linhaBruta = ['line_id' => 1, 'name' => 'Lavar', 'qty' => 1, 'price' => 100, 'size' => 'M'];   // O14 + O15
        $o1 = $this->ordem('OS/L01/2026/00001', [$linhaBruta]);
        $o2 = $this->ordem('OS/L01/2026/00001', [$this->linhaLegado(['size' => null])]);                // O11 duplicado
        unset($o2);
        $totalLinha = $this->ordem('OS/L01/2026/00003', [['linha_id' => 1, 'nome' => 'Lavar', 'quantidade' => 1, 'preco' => '100.00', 'valor' => '100.00', 'estado' => 'RECEBIDA', 'unidade' => 'PECA']]);
        foreach ([['RC-LAV/L01/2026/00001', 70], ['RC-LAV/L01/2026/00001', 70]] as [$recibo, $m]) {   // G08 duplicado; 140 > 100: C01
            $this->naEmpresa(fn () => DB::table('pagamentos_lavandaria')->insert(['empresa_id' => $this->empresa->id, 'pedido_lavandaria_id' => $totalLinha, 'montante' => $m,
                'numero_recibo' => $recibo, 'natureza_registo' => 'PAGAMENTO', 'estado' => 'REGISTADO', 'pos_pagamentos' => json_encode([['meio_id' => 'pm', 'tipo' => 'NUMERARIO', 'valor' => $m]])]));
        }
        $r = (new AuditoriaMigracaoLavandaria(DB::connection()))->auditar($this->empresa->id);
        $v = $r['verificacoes'];

        foreach (['P02_peca_sem_opcoes_preco', 'P06_peca_provavel_perda_de_opcoes', 'P04_precos_servico_por_traduzir', 'P08_peca_codigo_duplicado', 'O14_linhas_por_traduzir', 'O15_linha_tamanho_por_traduzir',
            'O11_numero_de_ordem_duplicado', 'G08_numero_de_recibo_duplicado', 'C01_pago_maior_que_total_da_ordem'] as $codigo) {
            $this->assertArrayHasKey($codigo, $v, $codigo);
        }
        $this->assertSame('ERRO', $v['P06_peca_provavel_perda_de_opcoes']['gravidade']);
        $this->assertContains($semOpcoes, $v['P06_peca_provavel_perda_de_opcoes']['ids']);
        $this->assertContains($o1, $v['O15_linha_tamanho_por_traduzir']['ids']);
        $this->assertSame(2, $v['G08_numero_de_recibo_duplicado']['total']);
        $this->assertSame([$this->empresa->id => 2], $v['O11_numero_de_ordem_duplicado']['por_empresa']);
        $this->assertContains($totalLinha, $v['C01_pago_maior_que_total_da_ordem']['ids']);
        $this->assertSame(['pecas_lavandaria' => 2, 'pedidos_lavandaria' => 3, 'pagamentos_lavandaria' => 2], array_intersect_key($r['totais'], array_flip(['pecas_lavandaria', 'pedidos_lavandaria', 'pagamentos_lavandaria'])));
        $this->assertGreaterThan(0, $r['resumo']['erros']);
        // sem dados pessoais: só códigos, gravidades, descrições fixas, contagens e ids
        $json = json_encode($r);
        $this->assertStringNotContainsString('Peça PC0001', $json);
        $this->assertStringNotContainsString('OS/L01/2026', $json);
        $this->assertStringContainsString('SEM DADOS', (new AuditoriaMigracaoLavandaria(DB::connection()))->auditar(999999)['resumo']['conclusao']);

        // depois de completar, as perdas de tradução desaparecem (a perda de lav_prices só se resolve com o backup)
        app(ServicoMigracaoLavandaria::class)->completar();
        $r2 = (new AuditoriaMigracaoLavandaria(DB::connection()))->auditar($this->empresa->id);
        $this->assertArrayNotHasKey('O15_linha_tamanho_por_traduzir', $r2['verificacoes']);
        $this->assertArrayNotHasKey('P02_peca_sem_opcoes_preco', $r2['verificacoes']);
        $this->assertArrayHasKey('P03_peca_opcoes_derivadas', $r2['verificacoes']);
        $this->assertSame(0, ($r2['verificacoes']['O14_linhas_por_traduzir']['total'] ?? 0));
    }

    #[Test]
    public function comando_de_auditoria_escreve_json_e_markdown_e_nao_altera_dados(): void
    {
        $peca = $this->peca('PC0001');
        $pasta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aud_lav_'.uniqid();
        $antes = DB::table('pecas_lavandaria')->where('id', $peca)->first();
        $this->artisan('erp:lavandaria:auditar-migracao', ['--saida' => $pasta, '--empresa' => $this->empresa->id])->run();
        $ficheiros = glob($pasta.DIRECTORY_SEPARATOR.'lavandaria_migracao_*');
        $this->assertCount(2, $ficheiros);
        $json = json_decode(file_get_contents(array_values(preg_grep('/\.json$/', $ficheiros))[0]), true);
        $this->assertSame(1, $json['totais']['pecas_lavandaria']);
        $this->assertStringContainsString('Auditoria da migração da lavandaria', file_get_contents(array_values(preg_grep('/\.md$/', $ficheiros))[0]));
        $this->assertEquals($antes, DB::table('pecas_lavandaria')->where('id', $peca)->first());
        array_map('unlink', $ficheiros);
        rmdir($pasta);
    }
}
