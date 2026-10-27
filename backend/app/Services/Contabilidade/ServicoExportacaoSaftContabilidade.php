<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\DiarioContabil;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use XMLWriter;

/**
 * Ficheiro SAF-T(AO) de Contabilidade (TaxAccountingBasis "C"; Decreto Executivo n.º 317/20): GeneralLedgerAccounts
 * (plano de contas completo, com saldos de abertura/fecho do exercício) e GeneralLedgerEntries (lançamentos, agrupados
 * por diário e por transacção — `numero_lan` — com as linhas a débito antes das de crédito, como o XSD exige). Prazo
 * legal: 10 de Abril do ano seguinte; obrigatório a partir do exercício de 2026 (2025 é facultativo, sem penalização).
 *
 * GroupingCategory de cada conta (confirmado no XSD oficial SAF-T(AO) 1.01_01, github.com/assoft-portugal/SAF-T-AO):
 * prefixo G = contabilidade Geral (classes 1-8), A = contabilidade Analítica (classe 9); sufixo R = conta de 1.º grau
 * (sem conta-mãe), A = agregadora (tipo "T" com conta-mãe), M = movimento (tipo "M", onde se lança directamente). A
 * hierarquia não tem campo próprio no plano de contas — deriva-se do código sem pontos, como em
 * `ServicoRelatoriosContabeis::linhasTotalizadoras()` (a conta-mãe é a totalizadora de prefixo mais comprido que bate
 * certo); só as contas R não levam GroupingCode.
 *
 * A estrutura segue a XSD SAF-T(AO) 1.01_01 (a mesma de `App\Services\Vendas\ServicoExportacaoSaft`, facturação);
 * validar o ficheiro no validador da AGT antes da certificação.
 */
final class ServicoExportacaoSaftContabilidade
{
    private const NAMESPACE = 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01';

    private const CLASSE_ANALITICA = '9';

    private const AVISO_SOFTWARE = 'Dados do software (productId, n.º de certificação) por configurar no servidor: o ficheiro não é aceite para efeitos fiscais.';

    /** @var list<string> */
    private array $avisos = [];

    public function __construct(private readonly ContextoEmpresa $contexto, private readonly ServicoRelatoriosContabeis $relatorios) {}

    /** @return array{nome: string, lancamentos: int, contas: int, avisos: list<string>} */
    public function validar(int $ano): array
    {
        [, $nif] = $this->empresaValida($ano);
        $empresa = $this->contexto->obrigatorio();
        $lancamentos = LancamentoContabil::query()->where('empresa_id', $empresa)->whereBetween('data_documento', ["{$ano}-01-01", "{$ano}-12-31"])->count();
        if ($lancamentos === 0) {
            throw new ErroNegocio('Não há lançamentos contabilísticos no exercício.', 'SEM_LANCAMENTOS', 422);
        }
        $avisos = $this->softwarePorConfigurar() ? [self::AVISO_SOFTWARE] : [];

        return ['nome' => "SAFT_AO_CONTAB_{$nif}_{$ano}.xml", 'lancamentos' => $lancamentos,
            'contas' => PlanoConta::query()->where('empresa_id', $empresa)->whereNull('eliminado_em')->count(), 'avisos' => $avisos];
    }

    /** @return array{xml: string, nome: string, avisos: list<string>, lancamentos: int, contas: int} */
    public function gerar(int $ano): array
    {
        $this->avisos = [];
        [$empresa, $nif] = $this->empresaValida($ano);
        $empresaId = $empresa->id;

        $contas = PlanoConta::query()->where('empresa_id', $empresaId)->whereNull('eliminado_em')->orderBy('codigo')->get();
        $lancamentos = LancamentoContabil::query()->where('empresa_id', $empresaId)
            ->whereBetween('data_documento', ["{$ano}-01-01", "{$ano}-12-31"])
            ->orderBy('numero_lan')->orderBy('tipo_dc')->orderBy('id')->get();
        if ($lancamentos->isEmpty()) {
            throw new ErroNegocio('Não há lançamentos contabilísticos no exercício.', 'SEM_LANCAMENTOS', 422);
        }
        $diarios = DiarioContabil::query()->where('empresa_id', $empresaId)->get()->keyBy('id');

        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->setIndentString('  ');
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('AuditFile');
        $x->writeAttribute('xmlns', self::NAMESPACE);

        $this->cabecalho($x, $empresa, $nif, $ano);
        $x->startElement('MasterFiles');
        $this->contasGerais($x, $contas, $ano);
        $x->endElement();
        $this->lancamentosContabilisticos($x, $lancamentos, $diarios);

        $x->endElement();
        $x->endDocument();

        return ['xml' => $x->outputMemory(), 'nome' => "SAFT_AO_CONTAB_{$nif}_{$ano}.xml", 'avisos' => array_values(array_unique($this->avisos)),
            'lancamentos' => $lancamentos->count(), 'contas' => $contas->count()];
    }

    /** @return array{0: Empresa, 1: string} */
    private function empresaValida(int $ano): array
    {
        if ($ano < 2000 || $ano > (int) now()->addYear()->format('Y')) {
            throw new ErroNegocio('Exercício inválido.', 'EXERCICIO_INVALIDO', 422);
        }
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $nif = preg_replace('/\s+/', '', (string) $empresa->nif);
        if (! preg_match('/^[0-9A-Za-z]{9,15}$/', $nif)) {
            throw new ErroNegocio('A empresa não tem um NIF válido.', 'NIF_INVALIDO', 422);
        }

        return [$empresa, $nif];
    }

    private function softwarePorConfigurar(): bool
    {
        $s = config('erp.agt.software');

        return ! ($s['softwareValidationNumber'] ?? null) || ! ($s['productId'] ?? null);
    }

    private function cabecalho(XMLWriter $x, Empresa $e, string $nif, int $ano): void
    {
        $s = config('erp.agt.software');
        if ($this->softwarePorConfigurar()) {
            $this->avisos[] = self::AVISO_SOFTWARE;
        }
        $x->startElement('Header');
        $this->el($x, 'AuditFileVersion', '1.01_01');
        $this->el($x, 'CompanyID', $e->numero_registo_comercial ?: $nif);
        $this->el($x, 'TaxRegistrationNumber', $nif);
        $this->el($x, 'TaxAccountingBasis', 'C');
        $this->el($x, 'CompanyName', $e->nome);
        $this->el($x, 'BusinessName', $e->nome);
        $x->startElement('CompanyAddress');
        $this->el($x, 'AddressDetail', $e->endereco ?: 'Desconhecido');
        $this->el($x, 'City', $e->municipio ?: 'Desconhecido');
        $this->el($x, 'Province', $e->provincia ?: 'Desconhecido');
        $this->el($x, 'Country', 'AO');
        $x->endElement();
        $this->el($x, 'FiscalYear', (string) $ano);
        $this->el($x, 'StartDate', "{$ano}-01-01");
        $this->el($x, 'EndDate', "{$ano}-12-31");
        $this->el($x, 'CurrencyCode', 'AOA');
        $this->el($x, 'DateCreated', now()->toDateString());
        $this->el($x, 'TaxEntity', 'Global');
        $this->el($x, 'ProductCompanyTaxID', $s['productCompanyTaxId'] ?: $nif);
        $this->el($x, 'SoftwareValidationNumber', $s['softwareValidationNumber'] ?: '0');
        $this->el($x, 'ProductID', $s['productId'] ?: 'ERP Consulvolt');
        $this->el($x, 'ProductVersion', $s['productVersion'] ?: '1.0');
        $this->elOpcional($x, 'Telephone', $e->telefone);
        $this->elOpcional($x, 'Email', $e->email);
        $this->elOpcional($x, 'Website', $e->website);
        $x->endElement();
    }

    /** @param  Collection<int, PlanoConta>  $contas */
    private function contasGerais(XMLWriter $x, Collection $contas, int $ano): void
    {
        // saldos de abertura/fecho do exercício, por conta de movimento, e das totalizadoras (somadas a partir delas)
        $balancete = $this->relatorios->balancete([
            'data_inicio' => "{$ano}-01-01", 'data_fim' => "{$ano}-12-31",
            'incluir_classe_9' => true, 'incluir_apuramento' => true, 'totalizadoras' => true,
        ]);
        $saldos = collect($balancete['linhas'])->keyBy('codigo_conta');
        $zero = ['saldo_inicial_devedor' => '0.00', 'saldo_inicial_credor' => '0.00', 'saldo_devedor' => '0.00', 'saldo_credor' => '0.00'];

        $x->startElement('GeneralLedgerAccounts');
        foreach ($contas as $c) {
            $s = $saldos[$c->codigo] ?? $zero;
            [$categoria, $agregador] = $this->categoriaConta($c, $contas);
            $x->startElement('Account');
            $this->el($x, 'AccountID', $c->codigo);
            $this->el($x, 'AccountDescription', mb_substr((string) $c->descricao, 0, 100));
            $this->el($x, 'OpeningDebitBalance', $s['saldo_inicial_devedor']);
            $this->el($x, 'OpeningCreditBalance', $s['saldo_inicial_credor']);
            $this->el($x, 'ClosingDebitBalance', $s['saldo_devedor']);
            $this->el($x, 'ClosingCreditBalance', $s['saldo_credor']);
            $this->el($x, 'GroupingCategory', $categoria);
            $this->elOpcional($x, 'GroupingCode', $agregador);
            $x->endElement();
        }
        $x->endElement();
    }

    /**
     * GroupingCategory/GroupingCode de uma conta: prefixo G (geral, classes 1-8) ou A (analítica, classe 9); sufixo R
     * (1.º grau, sem conta-mãe), A (agregadora com conta-mãe) ou M (movimento). Conta-mãe = a totalizadora (tipo "T")
     * de prefixo (sem pontos) mais comprido que seja prefixo próprio do código desta conta — mesma regra de
     * `ServicoRelatoriosContabeis::linhasTotalizadoras()`, invertida (procura a mãe em vez dos filhos).
     *
     * @param  Collection<int, PlanoConta>  $todas
     * @return array{0: string, 1: ?string}
     */
    private function categoriaConta(PlanoConta $c, Collection $todas): array
    {
        $prefixo = str_starts_with((string) $c->codigo, self::CLASSE_ANALITICA) ? 'A' : 'G';
        $norm = str_replace('.', '', (string) $c->codigo);
        $mae = null;
        $maiorPrefixo = 0;
        foreach ($todas as $t) {
            if ($t->tipo !== 'T' || $t->id === $c->id) {
                continue;
            }
            $pfx = str_replace('.', '', (string) $t->codigo);
            if ($pfx !== '' && strlen($pfx) < strlen($norm) && str_starts_with($norm, $pfx) && strlen($pfx) > $maiorPrefixo) {
                $mae = $t->codigo;
                $maiorPrefixo = strlen($pfx);
            }
        }
        $sufixo = $mae === null ? 'R' : ($c->tipo === 'M' ? 'M' : 'A');

        return ["{$prefixo}{$sufixo}", $mae];
    }

    /** @param  Collection<int, LancamentoContabil>  $lancamentos */
    private function lancamentosContabilisticos(XMLWriter $x, Collection $lancamentos, Collection $diarios): void
    {
        $debito = $credito = '0.00';
        foreach ($lancamentos as $l) {
            $l->tipo_dc === 'D' ? $debito = bcadd($debito, (string) $l->valor, 2) : $credito = bcadd($credito, (string) $l->valor, 2);
        }

        $x->startElement('GeneralLedgerEntries');
        $this->el($x, 'NumberOfEntries', (string) $lancamentos->count());
        $this->el($x, 'TotalDebit', $debito);
        $this->el($x, 'TotalCredit', $credito);
        foreach ($lancamentos->groupBy('diario_id') as $diarioId => $doDiario) {
            $diario = $diarios[$diarioId] ?? null;
            $x->startElement('Journal');
            $this->el($x, 'JournalID', $diario?->codigo ?: (string) $diarioId);
            $this->el($x, 'Description', $diario?->descricao ?: ($diario?->nome ?: 'Diário'));
            foreach ($doDiario->groupBy('numero_lan') as $numeroLan => $linhas) {
                $primeira = $linhas->first();
                $x->startElement('Transaction');
                $this->el($x, 'TransactionID', (string) $numeroLan);
                $this->el($x, 'Period', (string) (int) $primeira->data_documento->format('m'));
                $this->el($x, 'TransactionDate', $primeira->data_documento->toDateString());
                $this->el($x, 'SourceID', $primeira->nome_utilizador ?: 'ERP');
                $this->el($x, 'Description', mb_substr((string) ($primeira->referencia ?: $primeira->descricao ?: 'Lançamento'), 0, 200));
                $this->el($x, 'DocArchivalNumber', (string) ($primeira->numero_documento ?: $numeroLan));
                $this->el($x, 'TransactionType', 'N');
                $this->el($x, 'GLPostingDate', ($primeira->data_lancamento ?? $primeira->data_documento)->format('Y-m-d\TH:i:s'));
                $x->startElement('Lines');
                foreach ($linhas->where('tipo_dc', 'D') as $l) {
                    $this->linha($x, $l, 'DebitLine', 'DebitAmount');
                }
                foreach ($linhas->where('tipo_dc', 'C') as $l) {
                    $this->linha($x, $l, 'CreditLine', 'CreditAmount');
                }
                $x->endElement();
                $x->endElement();
            }
        }
        $x->endElement();
        $x->endElement();
    }

    private function linha(XMLWriter $x, LancamentoContabil $l, string $grupo, string $campoValor): void
    {
        $x->startElement($grupo === 'DebitLine' ? 'DebitLineGroup' : 'CreditLineGroup');
        $x->startElement($grupo);
        $this->el($x, 'RecordID', (string) $l->id);
        $this->el($x, 'AccountID', (string) $l->codigo_conta);
        // SourceDocumentID é opcional e tem uma subestrutura própria (OriginatingON/InvoiceDate, como em Payments);
        // sem confirmação exacta para GeneralLedgerEntries no XSD(AO), fica de fora em vez de arriscar um elemento mal formado.
        $this->el($x, 'SystemEntryDate', ($l->criado_em ?? $l->data_documento)->format('Y-m-d\TH:i:s'));
        $this->el($x, 'Description', mb_substr((string) ($l->descricao ?: 'Lançamento'), 0, 200));
        $this->el($x, $campoValor, number_format((float) $l->valor, 2, '.', ''));
        $x->endElement();
        $x->endElement();
    }

    private function el(XMLWriter $x, string $nome, string $valor): void
    {
        $x->writeElement($nome, $valor);
    }

    private function elOpcional(XMLWriter $x, string $nome, ?string $valor): void
    {
        if ($valor !== null && trim($valor) !== '') {
            $x->writeElement($nome, trim($valor));
        }
    }
}
