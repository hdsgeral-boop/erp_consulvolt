<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Vendas\Agt\ChavesAgt;
use App\Services\Vendas\ServicoHashSaft;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Vendas parte 2 (ADR-030): Hash SAF-T, multi-moeda, envio à AGT (simulada), séries, regime, QR e ficheiro SAF-T. */
final class FaturacaoEletronicaTest extends TestCase
{
    private const NIF = '5417000000';

    private Empresa $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private array $cabecalhos;

    private string $hoje;

    private string $pasta;

    /** @var array<string, string> chaves públicas PEM */
    private array $publicas = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => self::NIF, 'nome' => 'Empresa & Filhos, Lda']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach ([['311', 'Clientes'], ['3452', 'IVA liquidado'], ['451', 'Depósitos à ordem'], ['611', 'Vendas']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente <A & B>', 'nif' => '5000000001', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Produto 1', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => true]);
        });
        $this->cabecalhos = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fe_config', 'vendas_relatorios_view', 'vendas_recibos',
            'vendas_fat_contabilizar']);

        // Chaves de teste (as reais ficam em /run/segredos/agt, fora do Git)
        $this->pasta = sys_get_temp_dir().'/agt_testes_'.bin2hex(random_bytes(4));
        mkdir("{$this->pasta}/contribuintes", 0700, true);
        foreach (['produtor_privada.pem' => 'produtor', 'contribuintes/'.self::NIF.'.pem' => 'contribuinte', 'saft_privada.pem' => 'saft'] as $ficheiro => $nome) {
            $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($chave, $pem);
            file_put_contents("{$this->pasta}/{$ficheiro}", $pem);
            $this->publicas[$nome] = openssl_pkey_get_details($chave)['key'];
        }
        config(['erp.agt.pasta_chaves' => $this->pasta, 'erp.agt.driver' => 'direto', 'erp.agt.utilizador' => 'produtor', 'erp.agt.palavra_passe' => 'segredo',
            'erp.agt.software' => ['productId' => 'ERP Consulvolt', 'productVersion' => '2.0', 'softwareValidationNumber' => '123/AGT/2026', 'productCompanyTaxId' => '5000999999']]);
    }

    protected function tearDown(): void
    {
        try {
            // (sem GLOB_BRACE: não existe na libc do Alpine)
            foreach ([...(glob("{$this->pasta}/*.pem") ?: []), ...(glob("{$this->pasta}/contribuintes/*.pem") ?: [])] as $f) {
                unlink($f);
            }
            @rmdir("{$this->pasta}/contribuintes");
            @rmdir($this->pasta);
        } finally {
            parent::tearDown();
        }
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function emitir(array $dados = []): TestResponse
    {
        return $this->postJson('/api/vendas/documentos', $dados + [
            'tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 2]],
        ], $this->cabecalhos);
    }

    private function ativarRegime(array $servico = []): void
    {
        $this->putJson('/api/vendas/faturacao-eletronica/configuracao', ['ativo' => true, 'data_inicio' => $this->hoje,
            'estabelecimentos' => [['numero' => '1', 'nome' => 'Sede']], 'pais_padrao' => 'AO', 'servico' => $servico], $this->cabecalhos)->assertOk();
    }

    private function venda(int $id): Venda
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Venda::query()->findOrFail($id));
    }

    private function verificaJws(string $jws, string $publica): array
    {
        [$h, $p, $s] = explode('.', $jws);
        $this->assertSame(1, openssl_verify("{$h}.{$p}", base64_decode(strtr($s, '-_', '+/')), $publica, OPENSSL_ALGO_SHA256), 'Assinatura JWS inválida');
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode(base64_decode(strtr($h, '-_', '+/')), true));

        return json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    }

    #[Test]
    public function hash_saft_e_calculado_na_emissao_e_encadeado_por_serie(): void
    {
        $v1 = $this->venda($this->emitir()->assertCreated()->json('dados.id'));
        $v2 = $this->venda($this->emitir()->assertCreated()->json('dados.id'));

        $this->assertSame('1', $v1->saft_hash_controlo);
        $this->assertSame(1, openssl_verify(ServicoHashSaft::mensagem($v1, null), base64_decode($v1->saft_hash), $this->publicas['saft'], OPENSSL_ALGO_SHA1));
        $this->assertStringEndsWith(';', ServicoHashSaft::mensagem($v1, null));   // 1.º documento: hash anterior vazio
        $this->assertSame(1, openssl_verify(ServicoHashSaft::mensagem($v2, $v1->saft_hash), base64_decode($v2->saft_hash), $this->publicas['saft'], OPENSSL_ALGO_SHA1));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2};\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2};FT A\d{4}\/2;2280\.00;/', ServicoHashSaft::mensagem($v2, $v1->saft_hash));

        // sem chave SAF-T instalada (software não certificado): HashControl "0", sem inventar assinatura
        unlink("{$this->pasta}/saft_privada.pem");
        $this->app->forgetInstance(ChavesAgt::class);
        $v3 = $this->venda($this->emitir()->json('dados.id'));
        $this->assertNull($v3->saft_hash);
        $this->assertSame('0', $v3->saft_hash_controlo);
        // o orçamento (não fiscal) não é assinado
        $this->assertNull($this->venda($this->emitir(['tipo_documento' => 'OR'])->json('dados.id'))->saft_hash_controlo);
    }

    #[Test]
    public function factura_em_moeda_estrangeira_guarda_kz_oficial_e_valores_na_moeda(): void
    {
        $this->emitir(['codigo_moeda' => 'USD'])->assertStatus(422)->assertJsonPath('codigo', 'CAMBIO_EM_FALTA');
        TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => now()->subDays(3)->toDateString(), 'taxa' => '900.5']);

        $r = $this->emitir(['codigo_moeda' => 'USD', 'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 3, 'preco_unitario' => 10]]])->assertCreated()
            ->assertJsonPath('dados.codigo_moeda', 'USD')
            ->assertJsonPath('dados.moeda.total_liquido', '30.00')->assertJsonPath('dados.moeda.total_imposto', '4.20')->assertJsonPath('dados.moeda.total_bruto', '34.20')
            ->assertJsonPath('dados.total_liquido', '27015.00')->assertJsonPath('dados.total_imposto', '3782.10')->assertJsonPath('dados.total_bruto', '30797.10')
            ->assertJsonPath('dados.faturacao_eletronica.estado', 'PRONTO');
        $v = $this->venda($r->json('dados.id'));
        $this->assertEquals(['currencyCode' => 'USD', 'currencyAmount' => 34.2, 'exchangeRate' => 900.5], $v->fe_documento['documento']['documentTotals']['currency']);
        $this->assertEquals(9005, $v->fe_documento['documento']['lines'][0]['unitPrice']);

        // câmbio manual prevalece; a NC herda a moeda e o câmbio da factura (o contravalor anula o da factura)
        $this->emitir(['codigo_moeda' => 'USD', 'taxa_cambio' => 950, 'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1, 'preco_unitario' => 10]]])
            ->assertJsonPath('dados.moeda.taxa_cambio_manual', true)->assertJsonPath('dados.total_liquido', '9500.00');
        TaxaCambio::create(['empresa_id' => null, 'codigo_moeda' => 'USD', 'data_taxa' => $this->hoje, 'taxa' => '1000']);
        $this->emitir(['tipo_documento' => 'NC', 'venda_origem_id' => $v->id, 'motivo_nota_credito' => 'Devolução', 'codigo_moeda' => 'EUR',
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 3, 'preco_unitario' => 10]]])->assertCreated()
            ->assertJsonPath('dados.codigo_moeda', 'USD')->assertJsonPath('dados.total_bruto', '30797.10');
        $this->getJson("/api/vendas/documentos/{$v->id}", $this->cabecalhos)->assertJsonPath('dados.estado', 'PAGO');
    }

    #[Test]
    public function envia_a_agt_com_assinaturas_e_segue_o_ciclo_de_estados_ate_valido(): void
    {
        $this->ativarRegime();
        $id = $this->emitir()->assertCreated()->assertJsonPath('dados.faturacao_eletronica.envio', 'POR_ENVIAR')->json('dados.id');
        $numero = $this->venda($id)->numero_documento;

        Http::fake([
            '*/registarFactura' => Http::sequence()->push(['requestID' => 'R-1'], 200)->push(['requestID' => 'R-2'], 200),
            '*/obterEstado' => Http::sequence()
                ->push(['resultCode' => '8'], 200)
                ->push(['resultCode' => '0', 'documentStatusList' => [['documentNo' => $numero, 'documentStatus' => 'I',
                    'errorList' => [['idError' => 'E40', 'descriptionError' => 'NIF do adquirente inválido']]]]], 200)
                ->push(['resultCode' => '0', 'documentStatusList' => [['documentNo' => $numero, 'documentStatus' => 'V']]], 200),
        ]);

        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertOk()->assertJsonPath('dados.enviados', 1);
        Http::assertSent(function (PedidoHttp $p) use ($numero) {
            if (! str_ends_with($p->url(), '/registarFactura')) {
                return false;
            }
            $this->assertTrue($p->hasHeader('Authorization', 'Basic '.base64_encode('produtor:segredo')));
            $corpo = $p->data();
            $this->assertSame(self::NIF, $corpo['taxRegistrationNumber']);
            $this->verificaJws($corpo['softwareInfo']['jwsSoftwareSignature'], $this->publicas['produtor']);
            $doc = $corpo['documents'][0];
            $this->assertSame('N', $doc['documentStatus']);
            $assinado = $this->verificaJws($doc['jwsDocumentSignature'], $this->publicas['contribuinte']);
            $this->assertSame($numero, $assinado['documentNo']);
            $this->assertEquals(2280, $assinado['documentTotals']['grossTotal']);

            return true;
        });
        $this->assertSame('ENVIADO', $this->venda($id)->fe_envio['estado']);

        $this->postJson('/api/vendas/faturacao-eletronica/consultar', ['forcar' => true], $this->cabecalhos)->assertOk()->assertJsonPath('dados.aguardar', 1);  // em processamento
        $this->postJson('/api/vendas/faturacao-eletronica/consultar', ['forcar' => true], $this->cabecalhos)->assertJsonPath('dados.invalidos', 1);
        $this->getJson("/api/vendas/documentos/{$id}", $this->cabecalhos)->assertJsonPath('dados.faturacao_eletronica.envio', 'INVALIDO')
            ->assertJsonPath('dados.faturacao_eletronica.erros_agt.0.codigo', 'E40');

        // corrigidos os dados de origem: revalida e reenvia como correcção (documentStatus "C")
        $this->postJson("/api/vendas/documentos/{$id}/revalidar", [], $this->cabecalhos)->assertOk()->assertJsonPath('dados.enviados', 1);
        Http::assertSent(fn (PedidoHttp $p) => str_ends_with($p->url(), '/registarFactura') && ($p->data()['documents'][0]['documentStatus'] ?? null) === 'C');
        $this->postJson('/api/vendas/faturacao-eletronica/consultar', ['forcar' => true], $this->cabecalhos)->assertJsonPath('dados.validos', 1);
        $v = $this->venda($id);
        $this->assertSame('VALIDO', $v->fe_envio['estado']);
        $this->assertFalse($v->fe_envio['correccao']);
        $this->assertNotEmpty($v->fe_envio['historico']);
        $this->getJson('/api/vendas/faturacao-eletronica/resumo', $this->cabecalhos)->assertJsonPath('dados.VALIDO', 1);
    }

    #[Test]
    public function documento_que_a_agt_ja_tem_e_rejeicoes_e_ligacao_desligada(): void
    {
        $this->ativarRegime();
        $a = $this->emitir()->json('dados.id');
        $b = $this->emitir()->json('dados.id');
        [$na, $nb] = [$this->venda($a)->numero_documento, $this->venda($b)->numero_documento];
        Http::fake([
            '*/registarFactura' => Http::response(['errorList' => [
                ['idError' => 'E09', 'descriptionError' => 'Documento já existe', 'documentNo' => $na],
                ['idError' => 'E08', 'descriptionError' => 'Assinatura inválida', 'documentNo' => $nb],
            ]], 400),
            '*/consultarFactura' => Http::response(['documentStatus' => 'V'], 200),
        ]);
        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertJsonPath('dados.enviados', 1)->assertJsonPath('dados.rejeitados', 1);
        $this->assertSame('REJEITADO', $this->venda($b)->fe_envio['estado']);
        $this->postJson('/api/vendas/faturacao-eletronica/consultar', ['forcar' => true], $this->cabecalhos)->assertJsonPath('dados.validos', 1);
        $this->assertSame('VALIDO', $this->venda($a)->fe_envio['estado']);
    }

    #[Test]
    public function com_a_ligacao_desligada_o_documento_fica_em_erro_para_o_proximo_ciclo(): void
    {
        config(['erp.agt.driver' => 'desligado']);
        $this->ativarRegime();
        $c = $this->emitir()->json('dados.id');
        $this->postJson('/api/vendas/faturacao-eletronica/enviar', ['ids' => [$c]], $this->cabecalhos)->assertJsonPath('dados.erros', 1);
        $this->assertSame('AGT_DESLIGADA', $this->venda($c)->fe_envio['erros'][0]['codigo']);
        $this->getJson('/api/vendas/faturacao-eletronica/ligacao', $this->cabecalhos)->assertJsonPath('dados.driver', 'desligado');
    }

    #[Test]
    public function pedido_assinado_pode_ser_previsualizado_sem_envio(): void
    {
        $this->ativarRegime();
        $id = $this->emitir()->json('dados.id');
        Http::fake();
        $r = $this->getJson("/api/vendas/documentos/{$id}/pedido-assinado", $this->cabecalhos)->assertOk()->assertJsonPath('dados.simulado', true);
        $this->verificaJws($r->json('dados.pedido.documents.0.jwsDocumentSignature'), $this->publicas['contribuinte']);
        Http::assertNothingSent();
        $this->getJson('/api/vendas/faturacao-eletronica/ligacao', $this->cabecalhos)->assertJsonPath('dados.pronto_para_enviar', true)
            ->assertJsonPath('dados.contribuintes.0', self::NIF)->assertJsonMissingPath('dados.palavra_passe');
    }

    #[Test]
    public function series_regras_do_legado_pedido_a_agt_e_exigencia_de_series_agt(): void
    {
        $ano = (int) substr($this->hoje, 0, 4);
        $s = $this->postJson('/api/vendas/configuracao/series', ['tipo' => 'FT', 'ano' => $ano, 'codigo' => 'LOJA1'], $this->cabecalhos)->assertCreated()->json('dados.id');
        $this->postJson('/api/vendas/configuracao/series', ['tipo' => 'FT', 'ano' => $ano, 'codigo' => 'LOJA2'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'SERIE_ATIVA_DUPLICADA');
        $this->postJson('/api/vendas/configuracao/series', ['tipo' => 'FT', 'ano' => $ano, 'codigo' => 'LO JA'], $this->cabecalhos)->assertStatus(422);

        Http::fake(['*/solicitarSerie' => Http::response(['seriesFEResult' => ['seriesCode' => "FT{$ano}AGT", 'firstDocumentNo' => 1, 'lastDocumentNo' => 2, 'authorizedQuantity' => 2]], 200)]);
        $this->postJson("/api/vendas/configuracao/series/{$s}/solicitar-agt", [], $this->cabecalhos)->assertOk()->assertJsonPath('dados.agt_codigo', "FT{$ano}AGT");

        $this->ativarRegime(['exigir_series_agt' => true]);
        $this->emitir()->assertCreated()->assertJsonPath('dados.numero_documento', "FT FT{$ano}AGT/1");
        $this->emitir()->assertCreated();
        $this->emitir()->assertStatus(422)->assertJsonPath('codigo', 'SERIE_ESGOTADA');   // quantidade autorizada pela AGT esgotada
        $this->emitir(['tipo_documento' => 'FR', 'conta_disponibilidade' => '451'])->assertStatus(422)->assertJsonPath('codigo', 'SERIE_AGT_EM_FALTA');
        $this->deleteJson("/api/vendas/configuracao/series/{$s}", [], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'SERIE_USADA');
    }

    #[Test]
    public function regime_nao_se_activa_com_documentos_fora_nem_se_desactiva_com_documentos_dentro(): void
    {
        $this->emitir()->assertCreated();   // fora do regime
        $this->putJson('/api/vendas/faturacao-eletronica/configuracao', ['ativo' => true, 'data_inicio' => $this->hoje, 'estabelecimentos' => [['numero' => '1']]], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTOS_FORA_DO_REGIME');
        $amanha = now()->addDay()->toDateString();
        $this->putJson('/api/vendas/faturacao-eletronica/configuracao', ['ativo' => true, 'data_inicio' => $amanha, 'estabelecimentos' => [['numero' => '1']]], $this->cabecalhos)->assertOk();

        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Venda::query()->limit(1)->update(['fe_regime' => true]));
        $this->putJson('/api/vendas/faturacao-eletronica/configuracao', ['ativo' => false, 'data_inicio' => $amanha, 'estabelecimentos' => [['numero' => '1']]], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'REGIME_COM_DOCUMENTOS');
    }

    #[Test]
    public function qr_code_do_documento_no_regime(): void
    {
        $fora = $this->emitir()->json('dados.id');
        $this->get("/api/vendas/documentos/{$fora}/qr", $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'FORA_DO_REGIME');
    }

    #[Test]
    public function qr_code_png_e_svg_com_endereco_de_consulta_da_agt(): void
    {
        $this->ativarRegime();
        $id = $this->emitir()->json('dados.id');
        $numero = $this->venda($id)->numero_documento;
        $r = $this->get("/api/vendas/documentos/{$id}/qr", $this->cabecalhos)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame('https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor='.self::NIF.'&document='.str_replace(' ', '%20', $numero),
            $r->headers->get('X-Url-Consulta'));
        [$largura, $altura] = getimagesizefromstring($r->getContent());
        $this->assertSame([350, 350], [$largura, $altura]);
        $this->get("/api/vendas/documentos/{$id}/qr?formato=svg", $this->cabecalhos)->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    #[Test]
    public function ficheiro_saft_com_estrutura_totais_hash_escape_e_pagamentos(): void
    {
        $ft = $this->emitir()->json('dados.id');
        $this->emitir(['tipo_documento' => 'NC', 'venda_origem_id' => $ft, 'motivo_nota_credito' => 'Devolução',
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 1]]])->assertCreated();
        $this->postJson("/api/vendas/documentos/{$ft}/contabilizar", [], $this->cabecalhos)->assertOk();
        $this->postJson('/api/vendas/recibos', ['cliente_id' => $this->cliente->id, 'data' => $this->hoje, 'codigo_conta' => '451',
            'meio_pagamento' => 'TRANSFERENCIA', 'alocacoes' => [['venda_id' => $ft, 'montante' => '500']]], $this->cabecalhos)->assertCreated();

        $r = $this->get("/api/vendas/saft?inicio={$this->hoje}&fim={$this->hoje}", $this->cabecalhos)->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->assertStringContainsString('filename="SAFT_AO_'.self::NIF, $r->headers->get('Content-Disposition'));
        $xml = simplexml_load_string($r->getContent());
        $this->assertNotFalse($xml, 'XML inválido');
        $xml->registerXPathNamespace('s', 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01');
        $v = fn (string $caminho) => (string) ($xml->xpath($caminho)[0] ?? '');

        $this->assertSame('Empresa & Filhos, Lda', $v('//s:Header/s:CompanyName'));
        $this->assertSame('123/AGT/2026', $v('//s:Header/s:SoftwareValidationNumber'));
        $this->assertSame('Cliente <A & B>', $v('//s:Customer/s:CompanyName'));
        $this->assertSame('2', $v('//s:SalesInvoices/s:NumberOfEntries'));
        $this->assertSame('1000.00', $v('//s:SalesInvoices/s:TotalDebit'));    // NC (sem imposto)
        $this->assertSame('2000.00', $v('//s:SalesInvoices/s:TotalCredit'));   // FT (sem imposto)
        $this->assertSame($this->venda($ft)->saft_hash, $v('//s:Invoice[s:InvoiceType="FT"]/s:Hash'));
        $this->assertSame($this->venda($ft)->numero_documento, $v('//s:Invoice[s:InvoiceType="NC"]/s:Line/s:References/s:Reference'));
        $this->assertSame('2000.00', $v('//s:Invoice[s:InvoiceType="FT"]/s:Line/s:CreditAmount'));
        $this->assertSame('NOR', $v('//s:Invoice/s:Line/s:Tax/s:TaxCode'));
        $this->assertSame('1', $v('//s:Payments/s:NumberOfEntries'));
        $this->assertSame('TB', $v('//s:Payment/s:PaymentMethod/s:PaymentMechanism'));
        $this->assertSame($this->venda($ft)->numero_documento, $v('//s:Payment/s:Line/s:SourceDocumentID/s:OriginatingON'));
        $this->assertCount(0, $xml->xpath('//s:InvoiceNao'));

        $this->get('/api/vendas/saft?inicio=2020-01-01&fim=2020-01-31', $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'SEM_DOCUMENTOS');
    }
}
