<?php

namespace Tests\Feature;

use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SAF-T(AO) de Contabilidade (Decreto Executivo n.º 317/20): GeneralLedgerAccounts e GeneralLedgerEntries. */
final class ContabilidadeSaftTest extends TestCase
{
    private const NIF = '5417000099';

    private Empresa $empresa;

    private DiarioContabil $diario;

    private array $cabecalhos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => self::NIF, 'nome' => 'Empresa SAF-T Contab, Lda']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            // 1 (Disponibilidades, totalizadora de 1.º grau) > 11 (Caixa, totalizadora) > 111 (Caixa, movimento)
            // 6 (Proveitos, totalizadora de 1.º grau) > 61 (Vendas, movimento)
            // 9 (contabilidade analítica) > 91 (movimento) — confirma o prefixo "A"
            foreach ([
                ['1', 'Disponibilidades', 'T'], ['11', 'Caixa e equivalentes', 'T'], ['111', 'Caixa', 'M'],
                ['6', 'Proveitos', 'T'], ['61', 'Vendas', 'M'],
                ['9', 'Contabilidade analítica', 'T'], ['91', 'Centros de custo', 'M'],
            ] as [$c, $d, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => $t]);
            }
            $this->diario = DiarioContabil::create(['codigo' => 'VD', 'descricao' => 'Vendas']);
        });
        $this->cabecalhos = $this->sessao(['lancamentos_view', 'lancamentos_post', 'relatorios_contabeis_view']);
        config(['erp.agt.software' => ['productId' => 'ERP Consulvolt', 'productVersion' => '2.0', 'softwareValidationNumber' => '123/AGT/2026', 'productCompanyTaxId' => '5000999999']]);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function lancamento(array $linhas, string $data): void
    {
        $this->postJson('/api/contabilidade/lancamentos', ['diario_id' => $this->diario->id, 'data_documento' => $data,
            'descricao' => 'Venda a dinheiro', 'linhas' => $linhas], $this->cabecalhos)->assertCreated();
    }

    #[Test]
    public function gera_o_ficheiro_com_o_plano_de_contas_e_os_lancamentos_do_exercicio(): void
    {
        $this->lancamento([['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '1000.00'], ['codigo_conta' => '61', 'tipo_dc' => 'C', 'valor' => '1000.00']], '2026-03-10');
        $this->lancamento([['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '500.00'], ['codigo_conta' => '61', 'tipo_dc' => 'C', 'valor' => '500.00']], '2026-04-05');
        // fora do exercício de 2026: não deve entrar
        $this->lancamento([['codigo_conta' => '111', 'tipo_dc' => 'D', 'valor' => '10.00'], ['codigo_conta' => '61', 'tipo_dc' => 'C', 'valor' => '10.00']], '2027-01-02');

        $this->getJson('/api/contabilidade/saft/validar?ano=2026', $this->cabecalhos)->assertOk()
            ->assertJsonPath('dados.lancamentos', 4)->assertJsonPath('dados.avisos', []);   // 4 linhas (2 lançamentos × D+C)

        $r = $this->get('/api/contabilidade/saft?ano=2026', $this->cabecalhos)->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->assertStringContainsString('filename="SAFT_AO_CONTAB_'.self::NIF, $r->headers->get('Content-Disposition'));
        $xml = simplexml_load_string($r->getContent());
        $this->assertNotFalse($xml, 'XML inválido');
        $xml->registerXPathNamespace('s', 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01');
        $v = fn (string $caminho) => (string) ($xml->xpath($caminho)[0] ?? '');
        $contagem = fn (string $caminho) => count($xml->xpath($caminho));

        $this->assertSame('C', $v('//s:Header/s:TaxAccountingBasis'));
        $this->assertSame('2026', $v('//s:Header/s:FiscalYear'));
        $this->assertSame('2026-01-01', $v('//s:Header/s:StartDate'));
        $this->assertSame('2026-12-31', $v('//s:Header/s:EndDate'));

        // plano de contas completo (7 contas), incluindo as que não tiveram movimento (6, 9, 91)
        $this->assertSame(7, $contagem('//s:GeneralLedgerAccounts/s:Account'));
        $this->assertSame('GR', $v('//s:Account[s:AccountID="1"]/s:GroupingCategory'));       // 1.º grau, sem GroupingCode
        $this->assertSame('', $v('//s:Account[s:AccountID="1"]/s:GroupingCode'));
        $this->assertSame('GA', $v('//s:Account[s:AccountID="11"]/s:GroupingCategory'));       // agregadora, mãe = 1
        $this->assertSame('1', $v('//s:Account[s:AccountID="11"]/s:GroupingCode'));
        $this->assertSame('GM', $v('//s:Account[s:AccountID="111"]/s:GroupingCategory'));      // movimento, mãe = 11 (prefixo mais comprido)
        $this->assertSame('11', $v('//s:Account[s:AccountID="111"]/s:GroupingCode'));
        $this->assertSame('GM', $v('//s:Account[s:AccountID="61"]/s:GroupingCategory'));       // mãe = 6
        $this->assertSame('6', $v('//s:Account[s:AccountID="61"]/s:GroupingCode'));
        $this->assertSame('AR', $v('//s:Account[s:AccountID="9"]/s:GroupingCategory'));        // classe 9 → prefixo A, 1.º grau
        $this->assertSame('AM', $v('//s:Account[s:AccountID="91"]/s:GroupingCategory'));

        // saldos: a conta 111 fecha com 1500,00 a débito (só os 2 lançamentos de 2026)
        $this->assertSame('0.00', $v('//s:Account[s:AccountID="111"]/s:OpeningDebitBalance'));
        $this->assertSame('1500.00', $v('//s:Account[s:AccountID="111"]/s:ClosingDebitBalance'));
        $this->assertSame('0.00', $v('//s:Account[s:AccountID="111"]/s:ClosingCreditBalance'));
        // a totalizadora 11 soma a conta de movimento que agrega
        $this->assertSame('1500.00', $v('//s:Account[s:AccountID="11"]/s:ClosingDebitBalance'));

        // lançamentos: só os de 2026 (4 linhas: 2 lançamentos × D+C), agrupados por diário/transacção, débito antes de crédito
        $this->assertSame('4', $v('//s:GeneralLedgerEntries/s:NumberOfEntries'));
        $this->assertSame('1500.00', $v('//s:GeneralLedgerEntries/s:TotalDebit'));
        $this->assertSame('1500.00', $v('//s:GeneralLedgerEntries/s:TotalCredit'));
        $this->assertSame('VD', $v('//s:Journal/s:JournalID'));
        $this->assertSame(2, $contagem('//s:Journal/s:Transaction'));
        $primeira = $xml->xpath('//s:Transaction[s:TransactionDate="2026-03-10"]')[0];
        $primeira->registerXPathNamespace('s', 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01');
        $this->assertSame('VD2026000001', (string) $primeira->xpath('s:TransactionID')[0]);
        $this->assertSame('111', (string) $primeira->xpath('s:Lines/s:DebitLineGroup/s:DebitLine/s:AccountID')[0]);
        $this->assertSame('1000.00', (string) $primeira->xpath('s:Lines/s:DebitLineGroup/s:DebitLine/s:DebitAmount')[0]);
        $this->assertSame('61', (string) $primeira->xpath('s:Lines/s:CreditLineGroup/s:CreditLine/s:AccountID')[0]);
    }

    #[Test]
    public function sem_lancamentos_no_exercicio_recusa_com_erro_de_negocio(): void
    {
        $this->getJson('/api/contabilidade/saft?ano=2024', $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'SEM_LANCAMENTOS');
    }

    #[Test]
    public function exercicio_demasiado_futuro_e_invalido(): void
    {
        $this->getJson('/api/contabilidade/saft?ano=2099', $this->cabecalhos)->assertStatus(422)->assertJsonPath('codigo', 'EXERCICIO_INVALIDO');
    }

    #[Test]
    public function exige_permissao(): void
    {
        $semPermissao = $this->sessao(['lancamentos_view']);
        $this->getJson('/api/contabilidade/saft?ano=2026', $semPermissao)->assertForbidden();
    }
}
