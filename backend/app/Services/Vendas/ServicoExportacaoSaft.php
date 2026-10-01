<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigFaturacaoEletronica;
use App\Models\Empresa;
use App\Models\Produto;
use App\Models\ReciboVenda;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use XMLWriter;

/**
 * Ficheiro SAF-T(AO) de facturação (TaxAccountingBasis "F"): MasterFiles, SalesInvoices (FT, FR, NC) e Payments (recibos).
 * Substitui exportSaft do legado (js/ui_sales.js:10300-10590), que tinha erros graves:
 *   - Hash falso (soma de caracteres + texto fixo) recalculado na exportação → aqui o Hash gravado na emissão;
 *   - etiqueta inexistente <InvoiceNao>, <AuditFileSchemaVersion>, <SoftwareValidactionNumber>, n.º de certificação inventado;
 *   - NC subtraídas do TotalCredit em vez de somadas ao TotalDebit; linhas com o valor COM imposto;
 *   - isenção sempre M10; XML sem escape (um "&" no nome do cliente corrompia o ficheiro).
 * Os valores são os oficiais em Kz; documentos em moeda estrangeira levam o bloco <Currency>.
 * Documentos anteriores ao sistema novo (sem Hash) saem com Hash "0" e HashControl "0" e são assinalados nos avisos.
 * A estrutura segue a XSD SAF-T(AO) 1.01_01; validar o ficheiro no validador da AGT antes da certificação.
 */
final class ServicoExportacaoSaft
{
    private const NAMESPACE = 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01';

    private const MECANISMOS = ['NUMERARIO' => 'NU', 'TPA' => 'CD', 'TRANSFERENCIA' => 'TB', 'CONTA_CORRENTE' => 'OU'];

    private const AVISO_SOFTWARE = 'Dados do software (productId, n.º de certificação) por configurar no servidor: o ficheiro não é aceite para efeitos fiscais.';

    /** @var list<string> */
    private array $avisos = [];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Validação prévia, sem gerar o ficheiro (GET /api/vendas/saft/validar e início de gerar()): período, NIF da empresa
     * e existência de documentos. Lança ErroNegocio (422, envelope JSON) e devolve as contagens e os avisos conhecidos à partida.
     *
     * @return array{nome: string, documentos: int, recibos: int, avisos: list<string>}
     */
    public function validar(string $inicio, string $fim): array
    {
        $nif = $this->empresaValida($inicio, $fim)[1];
        $documentos = $this->consultaVendas($inicio, $fim)->count();
        $recibos = $this->consultaRecibos($inicio, $fim)->count();
        if ($documentos === 0 && $recibos === 0) {
            throw new ErroNegocio('Não há documentos fiscais nem recibos no período.', 'SEM_DOCUMENTOS', 422);
        }
        $avisos = [];
        if ($this->softwarePorConfigurar()) {
            $avisos[] = self::AVISO_SOFTWARE;
        }
        $semHash = $this->consultaVendas($inicio, $fim)->where(fn ($q) => $q->whereNull('saft_hash')->orWhere('saft_hash', ''))->count();
        if ($semHash) {
            $avisos[] = $this->avisoSemHash($semHash);
        }

        return ['nome' => "SAFT_AO_{$nif}_{$inicio}_{$fim}.xml", 'documentos' => $documentos, 'recibos' => $recibos, 'avisos' => $avisos];
    }

    /** @return array{xml: string, nome: string, avisos: list<string>, documentos: int, recibos: int} */
    public function gerar(string $inicio, string $fim): array
    {
        $this->avisos = [];
        [$empresa, $nif] = $this->empresaValida($inicio, $fim);

        $vendas = $this->consultaVendas($inicio, $fim)
            ->with(['itensVenda' => fn ($q) => $q->orderBy('id')])->orderBy('data_emissao')->orderBy('id')->get();
        $recibos = $this->consultaRecibos($inicio, $fim)
            ->with('itensReciboVenda.venda:id,numero_documento,data_emissao')->orderBy('data')->orderBy('id')->get();
        if ($vendas->isEmpty() && $recibos->isEmpty()) {
            throw new ErroNegocio('Não há documentos fiscais nem recibos no período.', 'SEM_DOCUMENTOS', 422);
        }

        $clientes = Terceiro::query()->withTrashed()->whereIn('id', $vendas->pluck('cliente_id')->merge($recibos->pluck('cliente_id'))->unique())->get()->keyBy('id');
        $produtos = Produto::query()->withTrashed()->whereIn('id', $vendas->flatMap(fn ($v) => $v->itensVenda->pluck('produto_id'))->filter()->unique())->get()->keyBy('id');
        $referencias = DB::table('vendas_documentos_relacionados as r')->join('vendas as o', 'o.id', '=', 'r.venda_relacionada_id')
            ->where('r.empresa_id', $empresa->id)->whereIn('r.venda_id', $vendas->where('tipo_documento', 'NC')->pluck('id'))
            ->pluck('o.numero_documento', 'r.venda_id');

        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->setIndentString('  ');
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('AuditFile');
        $x->writeAttribute('xmlns', self::NAMESPACE);

        $this->cabecalho($x, $empresa, $nif, $inicio, $fim);
        $this->ficheirosMestre($x, $vendas, $clientes, $produtos);

        $x->startElement('SourceDocuments');
        if ($vendas->isNotEmpty()) {
            $this->facturas($x, $vendas, $produtos, $referencias);
        }
        if ($recibos->isNotEmpty()) {
            $this->pagamentos($x, $recibos);
        }
        $x->endElement();
        $x->endElement();
        $x->endDocument();

        $semHash = $vendas->filter(fn ($v) => ! $v->saft_hash)->count();
        if ($semHash) {
            $this->avisos[] = $this->avisoSemHash($semHash);
        }

        return ['xml' => $x->outputMemory(), 'nome' => "SAFT_AO_{$nif}_{$inicio}_{$fim}.xml", 'avisos' => array_values(array_unique($this->avisos)),
            'documentos' => $vendas->count(), 'recibos' => $recibos->count()];
    }

    /** @return array{0: Empresa, 1: string} */
    private function empresaValida(string $inicio, string $fim): array
    {
        if (! $this->dataValida($inicio) || ! $this->dataValida($fim) || $inicio > $fim || substr($inicio, 0, 4) !== substr($fim, 0, 4)) {
            throw new ErroNegocio('O período tem de estar dentro do mesmo exercício (início ≤ fim).', 'PERIODO_INVALIDO', 422);
        }
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $nif = preg_replace('/\s+/', '', (string) $empresa->nif);
        if (! preg_match('/^[0-9A-Za-z]{9,15}$/', $nif)) {
            throw new ErroNegocio('A empresa não tem um NIF válido.', 'NIF_INVALIDO', 422);
        }

        return [$empresa, $nif];
    }

    private function dataValida(string $d): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);

        return $dt !== false && $dt->format('Y-m-d') === $d;
    }

    /** @return Builder<Venda> */
    private function consultaVendas(string $inicio, string $fim): Builder
    {
        return Venda::query()->whereIn('tipo_documento', Venda::FISCAIS)
            ->where('data_emissao', '>=', $inicio)->where('data_emissao', '<', date('Y-m-d', strtotime("{$fim} +1 day")))
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'));
    }

    /** @return Builder<ReciboVenda> */
    private function consultaRecibos(string $inicio, string $fim): Builder
    {
        return ReciboVenda::query()->whereBetween('data', [$inicio, $fim])->whereNull('venda_origem_id')
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'));
    }

    private function softwarePorConfigurar(): bool
    {
        $s = config('erp.agt.software');

        return ! ($s['softwareValidationNumber'] ?? null) || ! ($s['productId'] ?? null);
    }

    private function avisoSemHash(int $n): string
    {
        return "{$n} documento(s) sem assinatura SAF-T (anteriores ao sistema novo ou sem chave SAF-T instalada): Hash \"0\".";
    }

    private function cabecalho(XMLWriter $x, Empresa $e, string $nif, string $inicio, string $fim): void
    {
        $s = config('erp.agt.software');
        if ($this->softwarePorConfigurar()) {
            $this->avisos[] = self::AVISO_SOFTWARE;
        }
        $x->startElement('Header');
        $this->el($x, 'AuditFileVersion', '1.01_01');
        $this->el($x, 'CompanyID', $e->numero_registo_comercial ?: $nif);
        $this->el($x, 'TaxRegistrationNumber', $nif);
        $this->el($x, 'TaxAccountingBasis', 'F');
        $this->el($x, 'CompanyName', $e->nome);
        $this->el($x, 'BusinessName', $e->nome);
        $x->startElement('CompanyAddress');
        $this->el($x, 'AddressDetail', $e->endereco ?: 'Desconhecido');
        $this->el($x, 'City', $e->municipio ?: 'Desconhecido');
        $this->el($x, 'Province', $e->provincia ?: 'Desconhecido');
        $this->el($x, 'Country', 'AO');
        $x->endElement();
        $this->el($x, 'FiscalYear', substr($inicio, 0, 4));
        $this->el($x, 'StartDate', $inicio);
        $this->el($x, 'EndDate', $fim);
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

    private function ficheirosMestre(XMLWriter $x, $vendas, $clientes, $produtos): void
    {
        $x->startElement('MasterFiles');
        foreach ($clientes as $c) {
            $x->startElement('Customer');
            $this->el($x, 'CustomerID', (string) $c->id);
            $this->el($x, 'AccountID', $c->codigo_conta ?: 'Desconhecido');
            $this->el($x, 'CustomerTaxID', preg_replace('/\s+/', '', (string) $c->nif) ?: '999999999');
            $this->el($x, 'CompanyName', $c->nome ?: 'Consumidor Final');
            $x->startElement('BillingAddress');
            $this->el($x, 'AddressDetail', $c->endereco ?: 'Desconhecido');
            $this->el($x, 'City', 'Desconhecido');
            $this->el($x, 'Country', $c->fe_pais ?: 'AO');
            $x->endElement();
            $this->el($x, 'SelfBillingIndicator', '0');
            $x->endElement();
        }
        foreach ($produtos as $p) {
            $x->startElement('Product');
            $this->el($x, 'ProductType', $p->e_servico || ! $p->movimenta_stock ? 'S' : 'P');
            $this->el($x, 'ProductCode', $p->codigo ?: (string) $p->id);
            $this->el($x, 'ProductDescription', $p->nome ?: (string) $p->codigo);
            $this->el($x, 'ProductNumberCode', $p->codigo ?: (string) $p->id);
            $x->endElement();
        }
        $x->startElement('TaxTable');
        $taxas = $vendas->flatMap(fn ($v) => $v->itensVenda->map(fn ($i) => CatalogoAgt::taxaTexto($i->taxa_imposto ?? 0)))->unique()->sort()->values();
        foreach ($taxas as $t) {
            $codigo = CatalogoAgt::codigoTaxa($t);
            $x->startElement('TaxTableEntry');
            $this->el($x, 'TaxType', 'IVA');
            $this->el($x, 'TaxCountryRegion', 'AO');
            $this->el($x, 'TaxCode', $codigo);
            $this->el($x, 'Description', match ($codigo) {
                'NOR' => 'Taxa normal', 'INT' => 'Taxa intermédia', 'RED' => 'Taxa reduzida', 'ISE' => 'Isento', default => 'Outra taxa'
            });
            $this->el($x, 'TaxPercentage', number_format((float) $t, 2, '.', ''));
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
    }

    private function facturas(XMLWriter $x, $vendas, $produtos, $referencias): void
    {
        $debito = $credito = '0.00';
        foreach ($vendas as $v) {
            $v->tipo_documento === 'NC' ? $debito = bcadd($debito, (string) $v->total_liquido, 2) : $credito = bcadd($credito, (string) $v->total_liquido, 2);
        }
        $isencaoPadrao = ConfigFaturacaoEletronica::query()->value('isencao_padrao');

        $x->startElement('SalesInvoices');
        $this->el($x, 'NumberOfEntries', (string) $vendas->count());
        $this->el($x, 'TotalDebit', $debito);
        $this->el($x, 'TotalCredit', $credito);
        foreach ($vendas as $v) {
            $nc = $v->tipo_documento === 'NC';
            $entrada = ($v->fe_data_entrada_sistema ?? $v->data_emissao)->format('Y-m-d\TH:i:s');
            $x->startElement('Invoice');
            $this->el($x, 'InvoiceNo', $v->numero_documento);
            $x->startElement('DocumentStatus');
            $this->el($x, 'InvoiceStatus', 'N');
            $this->el($x, 'InvoiceStatusDate', $entrada);
            $this->el($x, 'SourceID', 'ERP');
            $this->el($x, 'SourceBilling', 'P');
            $x->endElement();
            $this->el($x, 'Hash', $v->saft_hash ?: '0');
            $this->el($x, 'HashControl', $v->saft_hash_controlo ?: '0');
            $this->el($x, 'Period', (string) (int) $v->data_emissao->format('m'));
            $this->el($x, 'InvoiceDate', $v->data_emissao->toDateString());
            $this->el($x, 'InvoiceType', $v->tipo_documento);
            $x->startElement('SpecialRegimes');
            $this->el($x, 'SelfBillingIndicator', '0');
            $this->el($x, 'CashVATSchemeIndicator', '0');
            $this->el($x, 'ThirdPartiesBillingIndicator', '0');
            $x->endElement();
            $this->el($x, 'SourceID', 'ERP');
            $this->el($x, 'SystemEntryDate', $entrada);
            $this->el($x, 'CustomerID', (string) $v->cliente_id);

            foreach ($v->itensVenda->values() as $n => $i) {
                $p = $produtos[$i->produto_id] ?? null;
                $valor = $i->total_linha !== null ? number_format((float) $i->total_linha, 2, '.', '')
                    : CalculadoraDocumento::arredondar(bcmul((string) $i->quantidade, (string) $i->preco_unitario, 8));
                $taxa = CatalogoAgt::taxaTexto($i->taxa_imposto ?? 0);
                $codigo = CatalogoAgt::codigoTaxa($taxa);
                $x->startElement('Line');
                $this->el($x, 'LineNumber', (string) ($n + 1));
                $this->el($x, 'ProductCode', $p?->codigo ?: (string) ($i->produto_id ?? 'SERV'));
                $this->el($x, 'ProductDescription', $p?->nome ?: ($i->descricao ?: 'Artigo'));
                $this->el($x, 'Quantity', rtrim(rtrim(number_format((float) $i->quantidade, 3, '.', ''), '0'), '.'));
                $this->el($x, 'UnitOfMeasure', $p?->unidade_fe ?: 'UN');
                $this->el($x, 'UnitPrice', number_format((float) $i->preco_unitario, 2, '.', ''));
                $this->el($x, 'TaxPointDate', $v->data_emissao->toDateString());
                if ($nc) {
                    $x->startElement('References');
                    $this->el($x, 'Reference', $referencias[$v->id] ?? 'Desconhecida');
                    $this->el($x, 'Reason', mb_substr((string) ($v->motivo_nota_credito ?: 'Correcção'), 0, 50));
                    $x->endElement();
                }
                $this->el($x, 'Description', mb_substr($i->descricao ?: ($p?->nome ?: 'Artigo'), 0, 200));
                $this->el($x, $nc ? 'DebitAmount' : 'CreditAmount', $valor);
                $x->startElement('Tax');
                $this->el($x, 'TaxType', 'IVA');
                $this->el($x, 'TaxCountryRegion', 'AO');
                $this->el($x, 'TaxCode', $codigo);
                $this->el($x, 'TaxPercentage', number_format((float) $taxa, 2, '.', ''));
                $x->endElement();
                if ($codigo === 'ISE') {
                    $m = $p?->codigo_isencao_fe ?: $isencaoPadrao;
                    if (! $m) {
                        $this->avisos[] = "{$v->numero_documento}: linha isenta sem motivo de isenção (código M).";
                    }
                    $this->el($x, 'TaxExemptionReason', mb_substr(CatalogoAgt::ISENCOES[$m] ?? 'Isento', 0, 60));
                    $this->el($x, 'TaxExemptionCode', $m ?: 'M00');
                }
                $this->el($x, 'SettlementAmount', '0.00');
                $x->endElement();
            }

            $x->startElement('DocumentTotals');
            $this->el($x, 'TaxPayable', number_format((float) $v->total_imposto, 2, '.', ''));
            $this->el($x, 'NetTotal', number_format((float) $v->total_liquido, 2, '.', ''));
            $this->el($x, 'GrossTotal', number_format((float) $v->total_bruto, 2, '.', ''));
            if ($v->codigo_moeda && $v->codigo_moeda !== 'AOA') {
                $x->startElement('Currency');
                $this->el($x, 'CurrencyCode', $v->codigo_moeda);
                $this->el($x, 'CurrencyAmount', number_format((float) $v->total_bruto_moeda, 2, '.', ''));
                $this->el($x, 'ExchangeRate', rtrim(rtrim(number_format((float) $v->taxa_cambio, 6, '.', ''), '0'), '.'));
                $x->endElement();
            }
            if ($v->tipo_documento === 'FR') {
                $x->startElement('Payment');
                $this->el($x, 'PaymentMechanism', self::MECANISMOS[$v->meio_pagamento] ?? 'NU');
                $this->el($x, 'PaymentAmount', number_format((float) $v->total_bruto, 2, '.', ''));
                $this->el($x, 'PaymentDate', $v->data_emissao->toDateString());
                $x->endElement();
            }
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
    }

    private function pagamentos(XMLWriter $x, $recibos): void
    {
        $total = $recibos->reduce(fn ($s, $r) => bcadd($s, (string) $r->montante_total, 2), '0.00');
        $x->startElement('Payments');
        $this->el($x, 'NumberOfEntries', (string) $recibos->count());
        $this->el($x, 'TotalDebit', '0.00');
        $this->el($x, 'TotalCredit', $total);
        foreach ($recibos as $r) {
            $entrada = ($r->criado_em ?? $r->data)->format('Y-m-d\TH:i:s');
            $x->startElement('Payment');
            $this->el($x, 'PaymentRefNo', $r->numero_recibo);
            $this->el($x, 'Period', (string) (int) $r->data->format('m'));
            $this->el($x, 'TransactionDate', $r->data->toDateString());
            $this->el($x, 'PaymentType', 'RG');
            $x->startElement('DocumentStatus');
            $this->el($x, 'PaymentStatus', 'N');
            $this->el($x, 'PaymentStatusDate', $entrada);
            $this->el($x, 'SourceID', 'ERP');
            $this->el($x, 'SourcePayment', 'P');
            $x->endElement();
            $x->startElement('PaymentMethod');
            $this->el($x, 'PaymentMechanism', self::MECANISMOS[$r->meio_pagamento] ?? 'OU');
            $this->el($x, 'PaymentAmount', number_format((float) $r->montante_total, 2, '.', ''));
            $this->el($x, 'PaymentDate', $r->data->toDateString());
            $x->endElement();
            $this->el($x, 'SourceID', 'ERP');
            $this->el($x, 'SystemEntryDate', $entrada);
            $this->el($x, 'CustomerID', (string) $r->cliente_id);
            foreach ($r->itensReciboVenda->values() as $n => $i) {
                $x->startElement('Line');
                $this->el($x, 'LineNumber', (string) ($n + 1));
                $x->startElement('SourceDocumentID');
                $this->el($x, 'OriginatingON', $i->venda?->numero_documento ?? 'Desconhecido');
                $this->el($x, 'InvoiceDate', $i->venda?->data_emissao?->toDateString() ?? $r->data->toDateString());
                $x->endElement();
                $this->el($x, 'CreditAmount', number_format((float) $i->montante_pago, 2, '.', ''));
                $x->endElement();
            }
            $x->startElement('DocumentTotals');
            $this->el($x, 'TaxPayable', '0.00');
            $this->el($x, 'NetTotal', number_format((float) $r->montante_total, 2, '.', ''));
            $this->el($x, 'GrossTotal', number_format((float) $r->montante_total, 2, '.', ''));
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
    }

    private function el(XMLWriter $x, string $nome, string $valor): void
    {
        $x->writeElement($nome, $valor);   // XMLWriter faz o escape de &, <, >
    }

    private function elOpcional(XMLWriter $x, string $nome, ?string $valor): void
    {
        if ($valor !== null && trim($valor) !== '') {
            $x->writeElement($nome, trim($valor));
        }
    }
}
