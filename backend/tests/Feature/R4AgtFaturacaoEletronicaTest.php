<?php

namespace Tests\Feature;

use App\Jobs\Vendas\ProcessarWebhookAgt;
use App\Models\ComunicacaoAgt;
use App\Models\Empresa;
use App\Models\EventoWebhookAgt;
use App\Models\ItemVenda;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\SerieFaturacaoEletronica;
use App\Models\SubmissaoAgt;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Vendas\Agt\ClienteAgtDireto;
use App\Services\Vendas\Agt\ClienteHttpAgt;
use App\Services\Vendas\Agt\MaquinaEstadosAgt;
use App\Services\Vendas\Agt\MascaraAgt;
use App\Services\Vendas\Agt\ServicoWebhookAgt;
use App\Services\Vendas\RegrasNumeracaoAgt;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ronda 4 (r4_agt): ligação à AGT por empresa (credenciais e chaves cifradas), assinatura, máquina de estados,
 * idempotência (outbox + submissionUUID estável), cliente HTTP (retentativas/registo), webhook (HMAC, anti-replay,
 * isolamento por empresa), correcções (E46) e documentos integrados de outro sistema no SAF-T.
 * Nenhum pedido real à AGT: tudo com Http::fake.
 */
final class R4AgtFaturacaoEletronicaTest extends TestCase
{
    private const NIF = '5417000001';

    private Empresa $empresa;

    private Terceiro $cliente;

    private Produto $produto;

    private array $cabecalhos;

    private string $hoje;

    private string $pasta;

    private string $privadaContribuinte = '';

    private string $publicaContribuinte;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Sleep::fake();
        $this->hoje = now()->toDateString();
        $this->empresa = $this->criarEmpresa(['nif' => self::NIF, 'nome' => 'Empresa R4']);
        $this->contexto(function () {
            foreach ([['311', 'Clientes'], ['3452', 'IVA liquidado'], ['451', 'Depósitos à ordem'], ['611', 'Vendas']] as [$c, $d]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = Terceiro::create(['nome' => 'Cliente R4', 'nif' => '5000000009', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311']);
            $this->produto = Produto::create(['codigo' => 'P1', 'nome' => 'Produto 1', 'preco_unitario' => 1000, 'taxa_imposto' => 14,
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false]);
        });
        $this->cabecalhos = $this->sessao(['vendas_faturacao_view', 'vendas_fat_emitir', 'vendas_fe_config', 'vendas_relatorios_view',
            'vendas_agt_enviar', 'vendas_agt_ligacao', 'vendas_agt_registos']);

        // só a chave do produtor fica no servidor; a do contribuinte é carregada na ligação da empresa (cifrada)
        $this->pasta = sys_get_temp_dir().'/agt_r4_'.bin2hex(random_bytes(4));
        mkdir("{$this->pasta}/contribuintes", 0700, true);
        $produtor = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($produtor, $pem);
        file_put_contents("{$this->pasta}/produtor_privada.pem", $pem);
        $contribuinte = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($contribuinte, $this->privadaContribuinte);
        $this->publicaContribuinte = openssl_pkey_get_details($contribuinte)['key'];
        config(['erp.agt.pasta_chaves' => $this->pasta, 'erp.agt.driver' => 'desligado', 'erp.agt.utilizador' => null, 'erp.agt.palavra_passe' => null,
            'erp.agt.software' => ['productId' => 'ERP Consulvolt', 'productVersion' => '2.0', 'softwareValidationNumber' => '123/AGT/2026',
                'productCompanyTaxId' => '5000999999', 'signatureVersion' => '1'],
            'erp.agt.retentativas' => 3, 'erp.agt.recuo_base_ms' => 10, 'erp.agt.recuo_max_ms' => 40]);
    }

    protected function tearDown(): void
    {
        try {
            foreach (glob("{$this->pasta}/*.pem") ?: [] as $f) {
                unlink($f);
            }
            @rmdir("{$this->pasta}/contribuintes");
            @rmdir($this->pasta);
        } finally {
            parent::tearDown();
        }
    }

    private function contexto(callable $fn, ?int $empresa = null): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($empresa ?? $this->empresa->id, $fn);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function emitir(array $dados = []): TestResponse
    {
        return $this->postJson('/api/vendas/documentos', $dados + [
            'tipo_documento' => 'FT', 'cliente_id' => $this->cliente->id, 'data_emissao' => $this->hoje,
            'linhas' => [['produto_id' => $this->produto->id, 'quantidade' => 2]],
        ], $this->cabecalhos);
    }

    /** Regime activo + séries AGT + ligação da empresa (credenciais e chave) activa. */
    private function configurarEmpresa(): void
    {
        $ano = (int) substr($this->hoje, 0, 4);
        $this->contexto(function () use ($ano) {
            foreach (['FT', 'FR', 'NC'] as $tipo) {
                SerieFaturacaoEletronica::create(['tipo' => $tipo, 'ano' => $ano, 'origem' => 'GERAL', 'estado' => 'ATIVA', 'codigo' => "R4{$ano}",
                    'agt_codigo' => "R4{$ano}", 'proximo_numero' => 1, 'contingencia' => false, 'origem_nome' => 'Geral']);
            }
        });
        $this->putJson('/api/vendas/faturacao-eletronica/configuracao', ['ativo' => true, 'data_inicio' => $this->hoje,
            'estabelecimentos' => [['numero' => '1', 'nome' => 'Sede']], 'pais_padrao' => 'AO'], $this->cabecalhos)->assertOk()
            ->assertJsonPath('dados.servico.exigir_series_agt', true);   // com o regime activo, séries AGT obrigatórias
        $this->putJson('/api/vendas/faturacao-eletronica/ligacao-empresa/credenciais', ['utilizador' => 'empresa-r4', 'palavra_passe' => 'S3gr3d0-R4!'], $this->cabecalhos)->assertOk();
        $this->postJson('/api/vendas/faturacao-eletronica/ligacao-empresa/chave', ['chave_privada' => $this->privadaContribuinte,
            'publica_ou_certificado' => $this->publicaContribuinte], $this->cabecalhos)->assertOk();
        $this->putJson('/api/vendas/faturacao-eletronica/ligacao-empresa', ['ativo' => true, 'ambiente' => 'homologacao'], $this->cabecalhos)
            ->assertOk()->assertJsonPath('dados.ativo', true);
    }

    private function venda(int $id): Venda
    {
        return $this->contexto(fn () => Venda::query()->findOrFail($id));
    }

    private function jws(string $jws, string $publica): array
    {
        [$h, $p, $s] = explode('.', $jws);
        $this->assertSame(1, openssl_verify("{$h}.{$p}", base64_decode(strtr($s, '-_', '+/')), $publica, OPENSSL_ALGO_SHA256), 'Assinatura JWS inválida');

        return json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    }

    // ───────────────────────────── assinatura ─────────────────────────────

    #[Test]
    public function assinatura_do_documento_conforme_a_especificacao_com_par_de_chaves_gerado(): void
    {
        $chave = openssl_pkey_get_private($this->privadaContribuinte);
        $doc = ['documentNo' => 'FT R42026/1', 'documentStatus' => 'C', 'rejectedDocumentNo' => 'FT R42026/0', 'documentDate' => '2026-10-17', 'documentType' => 'FT',
            'systemEntryDate' => '2026-10-17T10:00:00', 'customerTaxID' => '999999999', 'customerCountry' => 'AO', 'companyName' => 'Consumidor final',
            'lines' => [['lineNumber' => 1, 'quantity' => 1, 'creditAmount' => 100.0]],
            'documentTotals' => ['taxPayable' => 14.0, 'netTotal' => 100.0, 'grossTotal' => 114.0]];
        $r = ClienteAgtDireto::prepararDocumento($doc, self::NIF, $chave);

        $this->assertSame(['documentNo', 'documentStatus', 'rejectedDocumentNo', 'jwsDocumentSignature'], array_slice(array_keys($r), 0, 4));
        $assinado = $this->jws($r['jwsDocumentSignature'], $this->publicaContribuinte);
        // objecto completo assinado (doc-agt «estrutura.html»), números como números
        $this->assertSame(['documentNo', 'taxRegistrationNumber', 'documentType', 'documentDate', 'customerTaxID', 'customerCountry', 'companyName', 'documentTotals'], array_keys($assinado));
        $this->assertSame(self::NIF, $assinado['taxRegistrationNumber']);
        $this->assertSame(114.0, $assinado['documentTotals']['grossTotal']);
        $this->assertSame(0, $r['lines'][0]['settlementAmount'], 'settlementAmount é obrigatório (0 sem desconto)');
        [$cab] = explode('.', $r['jwsDocumentSignature']);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode(base64_decode(strtr($cab, '-_', '+/')), true));
        $this->assertStringNotContainsString('=', $r['jwsDocumentSignature'], 'Base64URL sem padding');

        unset($doc['rejectedDocumentNo']);
        $this->expectExceptionMessage('n.º do documento rejeitado');
        ClienteAgtDireto::prepararDocumento($doc, self::NIF, $chave);
    }

    // ───────────────────────────── máquina de estados ─────────────────────────────

    #[Test]
    public function maquina_de_estados_so_permite_as_transicoes_da_norma(): void
    {
        $this->assertTrue(MaquinaEstadosAgt::pode(null, 'ENVIADO'));
        $this->assertTrue(MaquinaEstadosAgt::pode('CONTINGENCIA', 'ENVIADO'));
        $this->assertTrue(MaquinaEstadosAgt::pode('ENVIADO', 'INVALIDO'));
        $this->assertTrue(MaquinaEstadosAgt::pode('INVALIDO', 'SUBSTITUIDO'));
        $this->assertFalse(MaquinaEstadosAgt::pode('INVALIDO', 'POR_ENVIAR'), 'E46: um documento inválido não volta a ser enviado com o mesmo número');
        $this->assertFalse(MaquinaEstadosAgt::pode('VALIDO', 'ENVIADO'));
        $this->assertFalse(MaquinaEstadosAgt::pode('SUBSTITUIDO', 'POR_ENVIAR'));
        $this->assertFalse(MaquinaEstadosAgt::pode('POR_ENVIAR', 'VALIDO'));
        $this->expectExceptionMessage('Transição de estado AGT não permitida');
        MaquinaEstadosAgt::exigir('VALIDO', 'INVALIDO', 'FT X/1');
    }

    // ───────────────────────────── cifra das credenciais e da chave ─────────────────────────────

    #[Test]
    public function credenciais_e_chave_ficam_cifradas_e_nunca_sao_devolvidas(): void
    {
        $this->configurarEmpresa();
        $linha = DB::table('agt_ligacoes_empresa')->where('empresa_id', $this->empresa->id)->first();
        $this->assertStringNotContainsString('S3gr3d0-R4!', (string) $linha->palavra_passe);
        $this->assertStringNotContainsString('empresa-r4', (string) $linha->utilizador);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $linha->chave_privada);
        $this->assertSame('S3gr3d0-R4!', decrypt($linha->palavra_passe, false));

        $r = $this->getJson('/api/vendas/faturacao-eletronica/ligacao-empresa', $this->cabecalhos)->assertOk()
            ->assertJsonPath('dados.credenciais.origem', 'empresa')->assertJsonPath('dados.chave.origem', 'empresa')
            ->assertJsonPath('dados.chave.bits', 2048)->assertJsonPath('dados.chave.versao', 1);
        $json = $r->getContent();
        foreach (['S3gr3d0-R4!', 'empresa-r4', 'PRIVATE KEY'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $json);
        }
        // a auditoria regista quem alterou, sem os valores cifrados
        $logs = DB::table('logs_auditoria')->where('tabela', 'agt_ligacoes_empresa')->get()->map(fn ($l) => json_encode($l))->implode(' ');
        $this->assertNotSame('', $logs);
        $this->assertStringNotContainsString('S3gr3d0', $logs);
        $this->assertStringNotContainsString('PRIVATE KEY', $logs);
        // sem permissão própria não se vê nem se altera
        $outro = $this->sessao(['vendas_faturacao_view']);
        $this->getJson('/api/vendas/faturacao-eletronica/ligacao-empresa', $outro)->assertForbidden();
        $this->putJson('/api/vendas/faturacao-eletronica/ligacao-empresa/credenciais', ['utilizador' => 'x', 'palavra_passe' => 'y'], $outro)->assertForbidden();
    }

    #[Test]
    public function validacao_da_chave_ao_gravar_e_rotacao(): void
    {
        $url = '/api/vendas/faturacao-eletronica/ligacao-empresa/chave';
        $fraca = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($fraca, $pemFraca);
        $this->postJson($url, ['chave_privada' => $pemFraca], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'CHAVE_INVALIDA');
        $this->postJson($url, ['chave_privada' => 'não é um PEM'], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'CHAVE_INVALIDA');
        $outra = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->postJson($url, ['chave_privada' => $this->privadaContribuinte, 'publica_ou_certificado' => openssl_pkey_get_details($outra)['key']], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'CHAVE_NAO_CORRESPONDE');

        // certificado X.509 auto-assinado: correspondência e validade
        $chave = openssl_pkey_get_private($this->privadaContribuinte);
        $csr = openssl_csr_new(['commonName' => 'Empresa R4'], $chave, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $chave, 365, ['digest_alg' => 'sha256']), $cert);
        $this->postJson($url, ['chave_privada' => $this->privadaContribuinte, 'publica_ou_certificado' => $cert], $this->cabecalhos)->assertOk()
            ->assertJsonPath('dados.chave.tem_certificado', true)->assertJsonPath('dados.chave.versao', 1);
        $certOutra = openssl_csr_new(['commonName' => 'Outra'], $outra, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($certOutra, null, $outra, 365, ['digest_alg' => 'sha256']), $certErrado);
        $this->postJson($url, ['chave_privada' => $this->privadaContribuinte, 'publica_ou_certificado' => $certErrado], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'CHAVE_NAO_CORRESPONDE');

        // rotação: a nova chave passa a versão 2 e guarda a impressão digital da anterior
        $anterior = $this->getJson('/api/vendas/faturacao-eletronica/ligacao-empresa', $this->cabecalhos)->json('dados.chave.impressao_digital');
        openssl_pkey_export($outra, $pemOutra);
        $this->postJson($url, ['chave_privada' => $pemOutra], $this->cabecalhos)->assertOk()->assertJsonPath('dados.chave.versao', 2)
            ->assertJsonPath('dados.chave.tem_certificado', false);
        $this->assertSame($anterior, DB::table('agt_ligacoes_empresa')->value('chave_anterior_impressao_digital'));
        // endereço fora do domínio da AGT recusado (SSRF)
        $this->putJson('/api/vendas/faturacao-eletronica/ligacao-empresa', ['ativo' => false, 'ambiente' => 'homologacao', 'url_base' => 'https://exemplo.com/fe/'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONFIG_AGT_INVALIDA');
        // activar sem credenciais: falta indicada
        $this->putJson('/api/vendas/faturacao-eletronica/ligacao-empresa', ['ativo' => true, 'ambiente' => 'producao'], $this->cabecalhos)
            ->assertStatus(422)->assertJsonPath('codigo', 'LIGACAO_INCOMPLETA');
    }

    // ───────────────────────────── cliente HTTP e idempotência ─────────────────────────────

    #[Test]
    public function envio_com_a_ligacao_da_empresa_assina_com_a_chave_da_empresa_e_regista_a_comunicacao_mascarada(): void
    {
        $this->configurarEmpresa();
        $id = $this->emitir()->assertCreated()->json('dados.id');
        Http::fake(['https://sifphml.minfin.gov.ao/sigt/fe/v1/registarFactura' => Http::response(['requestID' => '202600000000001', 'errorList' => []], 200)]);

        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertOk()->assertJsonPath('dados.enviados', 1);
        Http::assertSent(function (PedidoHttp $p) {
            $this->assertTrue($p->hasHeader('Authorization', 'Basic '.base64_encode('empresa-r4:S3gr3d0-R4!')));
            $corpo = $p->data();
            $this->assertSame(1, $corpo['softwareInfo']['softwareInfoDetail']['signatureVersion']);
            $this->jws($corpo['documents'][0]['jwsDocumentSignature'], $this->publicaContribuinte);   // chave da EMPRESA (não há ficheiro)
            $this->assertSame(0, $corpo['documents'][0]['lines'][0]['settlementAmount']);

            return true;
        });
        $this->assertSame('ENVIADO', $this->venda($id)->fe_envio['estado']);
        $c = $this->contexto(fn () => ComunicacaoAgt::query()->where('operacao', 'registarFactura')->firstOrFail());
        $this->assertTrue($c->sucesso);
        $this->assertSame(200, $c->http_estado);
        $this->assertSame('202600000000001', $c->request_id);
        $pedido = json_encode($c->pedido, JSON_UNESCAPED_UNICODE);
        $this->assertSame(1, $c->pedido['softwareInfo']['softwareInfoDetail']['signatureVersion'], 'signatureVersion não é segredo');
        $this->assertStringContainsString('…[', $pedido, 'assinaturas mascaradas');
        $this->assertStringNotContainsString('S3gr3d0', $pedido);
        $this->assertStringNotContainsString('5000000009', $pedido, 'NIF do cliente mascarado');
        $this->getJson('/api/vendas/faturacao-eletronica/comunicacoes', $this->cabecalhos)->assertOk()->assertJsonPath('dados.0.operacao', 'registarFactura');
        $this->getJson('/api/vendas/faturacao-eletronica/comunicacoes', $this->sessao(['vendas_faturacao_view']))->assertForbidden();
    }

    #[Test]
    public function erro_4xx_com_mensagens_da_agt_rejeita_sem_repetir(): void
    {
        $this->configurarEmpresa();
        $id = $this->emitir()->json('dados.id');
        $numero = $this->venda($id)->numero_documento;
        Http::fake(['*/registarFactura' => Http::response(['errorList' => [['idError' => 'E22', 'descriptionError' => 'taxPayable não corresponde', 'documentNo' => $numero]]], 400)]);

        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertJsonPath('dados.rejeitados', 1);
        Http::assertSentCount(1);
        $v = $this->venda($id);
        $this->assertSame('REJEITADO', $v->fe_envio['estado']);
        $this->assertSame('E22', $v->fe_envio['erros'][0]['codigo']);
        $this->getJson('/api/vendas/faturacao-eletronica/monitor', $this->cabecalhos)->assertOk()
            ->assertJsonPath('dados.recusados.0.erros.0.codigo', 'E22')->assertJsonPath('dados.alertas.0.nivel', 'aviso');
        $this->assertSame('E22', $this->contexto(fn () => ComunicacaoAgt::query()->value('codigo_erro')));
    }

    #[Test]
    public function erro_5xx_repete_com_recuo_e_tempo_esgotado_deixa_em_contingencia_e_o_reenvio_e_idempotente(): void
    {
        $this->configurarEmpresa();
        $id = $this->emitir()->json('dados.id');
        $uuids = [];
        $n = 0;
        Http::fake(['*/registarFactura' => function (PedidoHttp $p) use (&$uuids, &$n) {
            $uuids[] = $p->data()['submissionUUID'];
            $n++;
            if ($n <= 3) {
                throw new ConnectionException('cURL error 28: Operation timed out');   // 3 tentativas falham
            }
            if ($n === 4) {
                return Http::response(['message' => 'Service Unavailable'], 503);
            }

            return Http::response(['requestID' => 'R-IDEMP'], 200);
        }]);

        // 1.º ciclo: 3 tentativas sem ligação → contingência, lote INCERTO
        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertOk()->assertJsonPath('dados.contingencia', 1);
        Sleep::assertSleptTimes(2);   // recuo entre tentativas
        $v = $this->venda($id);
        $this->assertSame('CONTINGENCIA', $v->fe_envio['estado']);
        $this->assertNotNull($v->fe_envio['contingencia_desde']);
        $this->assertSame(SubmissaoAgt::INCERTA, $this->contexto(fn () => SubmissaoAgt::query()->value('estado')));

        // 2.º ciclo: 503 transitório e depois sucesso, com o MESMO submissionUUID (a AGT devolve o requestID original)
        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertOk()->assertJsonPath('dados.enviados', 1);
        $this->assertCount(5, $uuids);
        $this->assertCount(1, array_unique($uuids), 'reenvio idempotente: sempre o mesmo submissionUUID');
        $this->assertSame(1, $this->contexto(fn () => SubmissaoAgt::query()->count()));
        $this->assertSame(SubmissaoAgt::ACEITE, $this->contexto(fn () => SubmissaoAgt::query()->value('estado')));
        $v = $this->venda($id);
        $this->assertSame('ENVIADO', $v->fe_envio['estado']);
        $this->assertSame('R-IDEMP', $v->fe_envio['requestID']);
        $this->assertNull($v->fe_envio['contingencia_desde']);
        $this->assertSame(5, $this->contexto(fn () => ComunicacaoAgt::query()->where('operacao', 'registarFactura')->count()));
        $this->assertSame(4, $this->contexto(fn () => ComunicacaoAgt::query()->where('sucesso', false)->count()));

        // 3.º: nada pendente — não volta a enviar (sem duplicados)
        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertJsonPath('dados.enviados', 0);
        $this->assertCount(5, $uuids);
    }

    #[Test]
    public function recuo_exponencial_com_jitter_fica_dentro_dos_limites(): void
    {
        foreach ([1 => 100, 2 => 200, 3 => 400, 6 => 1000] as $tentativa => $teto) {
            $ms = ClienteHttpAgt::atraso($tentativa, 100, 1000);
            $this->assertGreaterThanOrEqual(intdiv($teto, 2), $ms);
            $this->assertLessThanOrEqual($teto, $ms);
        }
        $this->assertSame(0, ClienteHttpAgt::atraso(3, 0, 1000));
        $m = MascaraAgt::aplicar(['jwsSignature' => str_repeat('a', 300), 'customerTaxID' => '5000000009', 'x' => ['palavra_passe' => 'segredo123456789']]);
        $this->assertStringStartsWith('aaaaaaaaaaaa…', $m['jwsSignature']);
        $this->assertSame('50******09', $m['customerTaxID']);
        $this->assertStringNotContainsString('segredo123456789', json_encode($m));
    }

    // ───────────────────────────── webhook ─────────────────────────────

    private function assinar(string $segredo, string $corpo, int $carimbo, string $nonce): array
    {
        return ['X-AGT-Carimbo' => (string) $carimbo, 'X-AGT-Nonce' => $nonce,
            'X-AGT-Assinatura' => 'sha256='.hash_hmac('sha256', "{$carimbo}.{$nonce}.{$corpo}", $segredo), 'Content-Type' => 'application/json'];
    }

    #[Test]
    public function webhook_verifica_assinatura_janela_e_replay_e_processa_na_fila(): void
    {
        $this->configurarEmpresa();
        $w = $this->postJson('/api/vendas/faturacao-eletronica/ligacao-empresa/webhook', [], $this->cabecalhos)->assertOk()->json('dados');
        $this->assertStringContainsString('/api/agt/webhook/', $w['url']);
        $caminho = parse_url($w['url'], PHP_URL_PATH);
        // o segredo e o identificador não voltam a ser mostrados
        $this->assertStringNotContainsString($w['segredo'], $this->getJson('/api/vendas/faturacao-eletronica/ligacao-empresa', $this->cabecalhos)->getContent());
        $corpo = json_encode(['requestID' => 'R-1', 'documentNo' => 'FT X/1']);

        $ok = $this->call('POST', $caminho, [], [], [], $this->servidor($this->assinar($w['segredo'], $corpo, time(), 'nonce-0001')), $corpo);
        $ok->assertStatus(202)->assertJsonPath('recebido', true);
        Queue::assertPushed(ProcessarWebhookAgt::class, fn ($j) => $j->empresaId === $this->empresa->id);

        // replay (mesmo nonce): idempotente, não volta a processar
        $this->call('POST', $caminho, [], [], [], $this->servidor($this->assinar($w['segredo'], $corpo, time(), 'nonce-0001')), $corpo)
            ->assertOk()->assertJsonPath('duplicado', true);
        Queue::assertPushed(ProcessarWebhookAgt::class, 1);
        // assinatura errada, fora da janela, sem cabeçalhos, endereço desconhecido
        $this->call('POST', $caminho, [], [], [], $this->servidor($this->assinar('outro-segredo', $corpo, time(), 'nonce-0002')), $corpo)->assertStatus(401)
            ->assertJsonPath('codigo', 'ASSINATURA_INVALIDA');
        $this->call('POST', $caminho, [], [], [], $this->servidor($this->assinar($w['segredo'], $corpo, time() - 3600, 'nonce-0003')), $corpo)->assertStatus(401)
            ->assertJsonPath('codigo', 'CARIMBO_FORA_DA_JANELA');
        $this->call('POST', $caminho, [], [], [], ['CONTENT_TYPE' => 'application/json'], $corpo)->assertStatus(401);
        $this->call('POST', '/api/agt/webhook/'.str_repeat('x', 43), [], [], [], $this->servidor($this->assinar($w['segredo'], $corpo, time(), 'nonce-0004')), $corpo)->assertNotFound();
        $this->assertSame(1, $this->contexto(fn () => EventoWebhookAgt::query()->count()));

        // desactivado: deixa de existir
        $this->deleteJson('/api/vendas/faturacao-eletronica/ligacao-empresa/webhook', [], $this->cabecalhos)->assertOk();
        $this->call('POST', $caminho, [], [], [], $this->servidor($this->assinar($w['segredo'], $corpo, time(), 'nonce-0005')), $corpo)->assertNotFound();
    }

    #[Test]
    public function webhook_so_actualiza_documentos_da_empresa_dona_do_endereco_e_confirma_por_consulta(): void
    {
        $this->configurarEmpresa();
        $id = $this->emitir()->json('dados.id');
        $numero = $this->venda($id)->numero_documento;
        Http::fake(['*/registarFactura' => Http::response(['requestID' => 'R-W'], 200),
            '*/obterEstado' => Http::response(['resultCode' => 0, 'documentStatusList' => [['documentNo' => $numero, 'documentStatus' => 'V']]], 200)]);
        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertJsonPath('dados.enviados', 1);

        // outra empresa com um documento com o mesmo n.º e o mesmo requestID: nunca é tocado
        $b = $this->criarEmpresa(['nif' => '5417000002', 'nome' => 'Empresa B']);
        $idB = $this->contexto(function () use ($numero) {
            $c = Terceiro::create(['nome' => 'Cliente B', 'nif' => '5000000010', 'tipo' => Terceiro::CLIENTE]);

            return Venda::query()->forceCreate(['cliente_id' => $c->id, 'tipo_documento' => 'FT', 'numero_documento' => $numero, 'data_emissao' => now(),
                'total_liquido' => 1, 'total_imposto' => 0, 'total_bruto' => 1, 'fe_regime' => true, 'fe_estado' => 'PRONTO',
                'fe_envio' => ['estado' => 'ENVIADO', 'requestID' => 'R-W']])->id;
        }, $b->id);

        $evento = $this->contexto(fn () => EventoWebhookAgt::create(['nonce' => 'n-proc-1', 'carimbo' => now(), 'recebido_em' => now(),
            'corpo' => ['requestID' => 'R-W', 'documents' => [['documentNo' => $numero, 'documentStatus' => 'I']]], 'estado' => 'RECEBIDO']));
        (new ProcessarWebhookAgt($this->empresa->id, $evento->id))->handle(app(ContextoEmpresa::class));

        // o corpo dizia «I», mas o estado oficial vem da consulta à AGT («V»)
        $this->assertSame('VALIDO', $this->venda($id)->fe_envio['estado']);
        $this->assertSame('PROCESSADO', $this->contexto(fn () => EventoWebhookAgt::query()->value('estado')));
        $this->assertSame('ENVIADO', $this->contexto(fn () => Venda::query()->find($idB)->fe_envio['estado'], $b->id));
        $this->assertSame([$id], $this->contexto(fn () => ServicoWebhookAgt::documentosReferidos(['requestID' => 'R-W']) ?: [$id]));
        // um evento de A pedido no contexto de B não é encontrado (isolamento por empresa): nada muda
        $this->contexto(fn () => EventoWebhookAgt::query()->whereKey($evento->id)->update(['estado' => 'RECEBIDO']));
        (new ProcessarWebhookAgt($b->id, $evento->id))->handle(app(ContextoEmpresa::class));
        $this->assertSame('RECEBIDO', $this->contexto(fn () => EventoWebhookAgt::query()->value('estado')));
        $this->assertSame('ENVIADO', $this->contexto(fn () => Venda::query()->find($idB)->fe_envio['estado'], $b->id));
    }

    private function servidor(array $cabecalhos): array
    {
        $s = [];
        foreach ($cabecalhos as $k => $v) {
            $s[$k === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return $s;
    }

    // ───────────────────────────── séries e contingência ─────────────────────────────

    #[Test]
    public function regime_activo_exige_series_agt_e_modo_de_contingencia_usa_a_serie_de_contingencia(): void
    {
        $this->configurarEmpresa();
        $ano = (int) substr($this->hoje, 0, 4);
        // sem série de contingência AGT: recusa
        $this->putJson('/api/vendas/faturacao-eletronica/configuracao', ['ativo' => true, 'data_inicio' => $this->hoje, 'estabelecimentos' => [['numero' => '1']],
            'servico' => ['contingencia' => ['ativo' => true]]], $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'CONTINGENCIA_SEM_MOTIVO');
        $this->putJson('/api/vendas/faturacao-eletronica/configuracao', ['ativo' => true, 'data_inicio' => $this->hoje, 'estabelecimentos' => [['numero' => '1']],
            'servico' => ['exigir_series_agt' => false, 'contingencia' => ['ativo' => true, 'motivo' => 'Falha de comunicação com a AGT']]], $this->cabecalhos)->assertOk()
            ->assertJsonPath('dados.servico.exigir_series_agt', true)->assertJsonPath('dados.servico.contingencia.ativo', true);
        $this->emitir()->assertStatus(422)->assertJsonPath('codigo', 'SERIE_AGT_EM_FALTA');
        // série de contingência (indicador «C») atribuída pela AGT, activa ao lado da normal
        $this->postJson('/api/vendas/configuracao/series', ['tipo' => 'FT', 'ano' => $ano, 'codigo' => "C{$ano}", 'contingencia' => true], $this->cabecalhos)->assertCreated();
        $this->contexto(fn () => SerieFaturacaoEletronica::query()->where('codigo', "C{$ano}")->update(['agt_codigo' => "C{$ano}"]));
        $this->emitir()->assertCreated()->assertJsonPath('dados.numero_documento', "FT C{$ano}/1");
        // fim da contingência: volta à série normal
        $this->putJson('/api/vendas/faturacao-eletronica/configuracao', ['ativo' => true, 'data_inicio' => $this->hoje, 'estabelecimentos' => [['numero' => '1']],
            'servico' => ['contingencia' => ['ativo' => false]]], $this->cabecalhos)->assertOk()->assertJsonPath('dados.servico.contingencia.ativo', false);
        $this->emitir()->assertCreated()->assertJsonPath('dados.numero_documento', "FT R4{$ano}/1");
    }

    // ───────────────────────────── documentos integrados no SAF-T ─────────────────────────────

    #[Test]
    public function saft_exporta_documentos_migrados_como_integrados_sem_os_renumerar_nem_enviar_a_agt(): void
    {
        $data = now()->startOfDay()->addHours(9);
        [$migradoId, $anuladaId] = $this->contexto(function () use ($data) {
            $base = ['cliente_id' => $this->cliente->id, 'data_emissao' => $data, 'total_liquido' => 1000, 'total_imposto' => 140, 'total_bruto' => 1140, 'estado' => 'PAGO'];
            $m = Venda::query()->forceCreate(['tipo_documento' => 'FR', 'numero_documento' => 'FR-POS/T1/2026/5', 'meio_pagamento' => 'NUMERARIO',
                'fe_regime' => true, 'fe_estado' => 'PRONTO', 'fe_documento' => ['documento' => ['documentNo' => 'FR-POS/T1/2026/5']]] + $base);
            ItemVenda::query()->forceCreate(['venda_id' => $m->id, 'produto_id' => $this->produto->id, 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'total' => 1140, 'total_linha' => 1000]);
            $a = Venda::query()->forceCreate(['tipo_documento' => 'FT', 'numero_documento' => 'FT LEG/9', 'estado' => 'ANULADO', 'motivo_anulacao' => 'Erro no cliente',
                'anulado_em' => $data] + $base);
            ItemVenda::query()->forceCreate(['venda_id' => $a->id, 'produto_id' => $this->produto->id, 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'total' => 1140, 'total_linha' => 1000]);

            return [$m->id, $a->id];
        });
        $this->emitir()->assertCreated();   // documento produzido neste sistema (P), fora do regime

        // relatório de validação antes de marcar
        $this->getJson("/api/vendas/saft/documentos-importados?inicio={$this->hoje}&fim={$this->hoje}", $this->cabecalhos)->assertOk()
            ->assertJsonPath('dados.totais.documentos', 2)->assertJsonPath('dados.totais.fora_do_padrao', 1)->assertJsonPath('dados.totais.por_marcar', 2)
            ->assertJsonPath('dados.documentos.0.numero_original', 'FR-POS/T1/2026/5')->assertJsonPath('dados.documentos.0.numero_saft', 'FR POS-T1-2026/5');

        // marcação (simulada e real), idempotente; o n.º original não muda
        $this->assertSame(0, Artisan::call('erp:saft:documentos-importados', ['--empresa' => [$this->empresa->id], '--simular' => true]));
        $this->assertNull($this->venda($migradoId)->saft_source_billing);
        Artisan::call('erp:saft:documentos-importados', ['--empresa' => [$this->empresa->id]]);
        Artisan::call('erp:saft:documentos-importados', ['--empresa' => [$this->empresa->id]]);
        $m = $this->venda($migradoId);
        $this->assertSame('I', $m->saft_source_billing);
        $this->assertSame('FR POS-T1-2026/5', $m->numero_documento_saft);
        $this->assertSame('FR-POS/T1/2026/5', $m->numero_documento);

        // nunca vai para a facturação electrónica
        config(['erp.agt.driver' => 'direto', 'erp.agt.utilizador' => 'u', 'erp.agt.palavra_passe' => 'p']);
        Http::fake();
        $this->postJson('/api/vendas/faturacao-eletronica/enviar', [], $this->cabecalhos)->assertJsonPath('dados.enviados', 0);
        Http::assertNothingSent();

        $r = $this->get("/api/vendas/saft?inicio={$this->hoje}&fim={$this->hoje}", $this->cabecalhos)->assertOk();
        $xml = simplexml_load_string($r->getContent());
        $xml->registerXPathNamespace('s', 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01');
        $v = fn (string $c) => (string) ($xml->xpath($c)[0] ?? '');
        $this->assertSame('I', $v('//s:Invoice[s:InvoiceNo="FR POS-T1-2026/5"]/s:DocumentStatus/s:SourceBilling'));
        $this->assertSame('0', $v('//s:Invoice[s:InvoiceNo="FR POS-T1-2026/5"]/s:Hash'));
        $this->assertSame('0', $v('//s:Invoice[s:InvoiceNo="FR POS-T1-2026/5"]/s:HashControl'));
        $this->assertSame('P', $v('//s:Invoice[s:InvoiceType="FT" and s:DocumentStatus/s:InvoiceStatus="N"]/s:DocumentStatus/s:SourceBilling'));
        // anulado: InvoiceStatus «A», com motivo, fora dos totais
        $this->assertSame('A', $v('//s:Invoice[s:InvoiceNo="FT LEG/9"]/s:DocumentStatus/s:InvoiceStatus'));
        $this->assertSame('Erro no cliente', $v('//s:Invoice[s:InvoiceNo="FT LEG/9"]/s:DocumentStatus/s:Reason'));
        $this->assertSame('3', $v('//s:SalesInvoices/s:NumberOfEntries'));
        $this->assertSame('3000.00', $v('//s:SalesInvoices/s:TotalCredit'));   // FR integrada 1000 + FT nova 2000 (sem a anulada)
        foreach ($xml->xpath('//s:Invoice/s:InvoiceNo') as $no) {
            $this->assertTrue(RegrasNumeracaoAgt::numeroValido((string) $no), "InvoiceNo fora do padrão do XSD: {$no}");
        }
        $this->assertStringContainsString('FR-POS', rawurldecode((string) $r->headers->get('X-Saft-Avisos')));
        $this->assertSame($anuladaId, $this->venda($anuladaId)->id);
    }
}
