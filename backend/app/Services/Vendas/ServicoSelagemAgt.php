<?php

namespace App\Services\Vendas;

use App\Models\ConfigFaturacaoEletronica;
use App\Models\Empresa;
use App\Models\ItemVenda;
use App\Models\Terceiro;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Selagem dos documentos fiscais (FacturaAGT.construir/validar/selar, js/facturacao_agt.js:234-462).
 * Constrói o documento electrónico AGT, valida-o localmente (E01/E02/E03/E22/E23/E24) e sela o documento:
 * a partir daqui os campos fiscais são imutáveis (Venda::CAMPOS_SELADOS). Dentro do regime, a emissão é
 * pré-validada ANTES de numerar (preValidar; como o legado, «Nada foi gravado»): com erros não se consome o n.º
 * da série. Fora do regime (ou com erros só detectáveis depois), o documento fica selado com os erros em fe_erros.
 * A assinatura JWS e o envio à AGT são feitos pelo serviço intermédio (Vendas, parte 2).
 */
final class ServicoSelagemAgt
{
    /** Tipos de operação AGT (OPERACOES, js/facturacao_agt.js:48-52). */
    public const OPERACOES = ['TB' => 'Transmissão de bens', 'SG' => 'Prestação de serviço (geral)', 'SE' => 'Serviços de educação', 'SS' => 'Serviços de saúde',
        'STP' => 'Transporte de passageiros', 'SR' => 'Serviços sujeitos a royalties', 'SIF' => 'Intermediação financeira ou seguradora',
        'SHS' => 'Hotelaria e similares', 'ST' => 'Telecomunicações', 'AS' => 'Arrendamento e subarrendamento', 'QT' => 'Quotas', 'RD' => 'Repasse de despesas'];

    /**
     * @param  EloquentCollection<int, ItemVenda>  $itens
     * @param  string|null  $referenciaOrigem  N.º do documento de origem (obrigatório nas notas de crédito)
     */
    public function selar(Venda $venda, EloquentCollection $itens, ?ConfigFaturacaoEletronica $config, ?string $referenciaOrigem = null): void
    {
        $itens->loadMissing('produto');
        $documento = $this->construir($venda, $itens, $config, $referenciaOrigem);
        [$erros, $avisos] = $this->validar($documento, $venda, $referenciaOrigem);
        $agora = now();

        $venda->forceFill([
            'fe_documento' => $documento, 'fe_erros' => $erros, 'fe_avisos' => $avisos,
            'fe_estado' => $erros ? 'COM_ERROS' : 'PRONTO', 'fe_validado_em' => $agora, 'fe_selado_em' => $agora,
            'fe_regime' => $this->emRegime($config, $venda), 'fe_tipo' => $venda->tipo_documento,
        ])->save();
        foreach ($itens as $item) {
            $item->forceFill(['fe_selado' => true])->save();
        }
    }

    /**
     * Refaz o documento electrónico depois de corrigidos os dados de origem (cliente, empresa, produto).
     * Só grava se já não houver erros locais. Devolve a lista de erros (vazia = revalidado).
     *
     * @param  EloquentCollection<int, ItemVenda>  $itens
     * @return list<string>
     */
    public function revalidar(Venda $venda, EloquentCollection $itens, ?ConfigFaturacaoEletronica $config, ?string $referenciaOrigem = null): array
    {
        $itens->loadMissing('produto');
        $documento = $this->construir($venda, $itens, $config, $referenciaOrigem);
        [$erros, $avisos] = $this->validar($documento, $venda, $referenciaOrigem);
        if (! $erros) {
            $venda->permitirRevalidacao = true;
            try {
                $venda->forceFill(['fe_documento' => $documento, 'fe_erros' => [], 'fe_avisos' => $avisos, 'fe_estado' => 'PRONTO', 'fe_validado_em' => now()])->save();
            } finally {
                $venda->permitirRevalidacao = false;
            }
        }

        return $erros;
    }

    /**
     * Pré-validação antes de numerar (FE.prevalidar, js/ui_sales.js:1793-1803): o documento é construído com os dados
     * ainda por gravar e um n.º provisório com formato válido (o n.º definitivo vem da série, que é nossa).
     * Devolve [erros, avisos]; com erros a emissão é recusada sem consumir o n.º da série («Nada foi gravado»).
     *
     * @param  EloquentCollection<int, ItemVenda>  $itens  não gravados, com a relação produto definida
     * @return array{0: list<string>, 1: list<string>}
     */
    public function preValidar(Venda $venda, EloquentCollection $itens, ?ConfigFaturacaoEletronica $config, ?string $referenciaOrigem = null): array
    {
        $venda->numero_documento = "{$venda->tipo_documento} PREVALIDACAO/1";

        return $this->validar($this->construir($venda, $itens, $config, $referenciaOrigem), $venda, $referenciaOrigem);
    }

    public function emRegime(?ConfigFaturacaoEletronica $config, Venda $venda): bool
    {
        return $this->emRegimeNaData($config, $venda->data_emissao->toDateString());
    }

    public function emRegimeNaData(?ConfigFaturacaoEletronica $config, string $data): bool
    {
        return (bool) ($config?->ativo) && $config->data_inicio !== null && substr($data, 0, 10) >= $config->data_inicio->toDateString();
    }

    /** @param  EloquentCollection<int, ItemVenda>  $itens */
    private function construir(Venda $venda, EloquentCollection $itens, ?ConfigFaturacaoEletronica $config, ?string $referenciaOrigem): array
    {
        $empresa = Empresa::query()->find($venda->empresa_id);
        $cliente = Terceiro::query()->find($venda->cliente_id);
        $nc = $venda->tipo_documento === 'NC';
        // Moeda estrangeira: linhas e totais em Kz (valores oficiais), com o bloco currency (moeda, total na moeda, câmbio)
        $estrangeira = $venda->codigo_moeda && $venda->codigo_moeda !== 'AOA';
        $linhas = [];
        foreach ($itens->values() as $i => $item) {
            $preco = $estrangeira && $item->preco_unitario_moeda !== null
                ? bcmul((string) $item->preco_unitario_moeda, (string) $venda->taxa_cambio, 6) : (string) $item->preco_unitario;
            $valor = $item->total_linha !== null ? number_format((float) $item->total_linha, 2, '.', '')
                : CalculadoraDocumento::arredondar(bcmul((string) $item->quantidade, $preco, 8));
            $taxa = CatalogoAgt::taxaTexto($item->taxa_imposto);
            $codigoTaxa = CatalogoAgt::codigoTaxa($taxa);
            $linha = [
                'lineNumber' => $i + 1,
                'operationType' => $item->produto?->tipo_operacao_fe ?: ($item->produto?->movimenta_stock ? 'TB' : 'SG'),
                'productCode' => $item->produto?->codigo ?: (string) ($item->produto_id ?? 'SERV'),
                'productDescription' => $item->descricao ?: $item->produto?->nome,
                'quantity' => (float) $item->quantidade,
                'unitOfMeasure' => $item->produto?->unidade_fe ?: 'UN',
                'unitPriceBase' => (float) $preco,
                'unitPrice' => (float) $preco,
                ($nc ? 'debitAmount' : 'creditAmount') => (float) $valor,
                'taxes' => [array_filter([
                    'taxType' => 'IVA', 'taxCountryRegion' => 'AO', 'taxCode' => $codigoTaxa, 'taxPercentage' => (float) $taxa,
                    'taxContribution' => (float) bcsub((string) $item->total, $valor, 2),
                    'taxExemptionCode' => $codigoTaxa === 'ISE' ? ($item->produto?->codigo_isencao_fe ?: $config?->isencao_padrao) : null,
                ], fn ($v) => $v !== null)],
            ];
            if ($nc) {
                $linha['referenceInfo'] = ['reference' => $referenciaOrigem, 'reason' => $venda->motivo_nota_credito];
            }
            $linhas[] = $linha;
        }

        return [
            'taxRegistrationNumber' => $empresa?->nif,
            'documento' => [
                'documentNo' => $venda->numero_documento,
                'documentStatus' => 'N',
                'documentDate' => $venda->data_emissao->toDateString(),
                'documentType' => $venda->tipo_documento,
                'systemEntryDate' => ($venda->fe_data_entrada_sistema ?? now())->format('Y-m-d\TH:i:s'),
                'customerTaxID' => $cliente?->nif ?: '999999999',
                'customerCountry' => $cliente?->fe_pais ?: ($config?->pais_padrao ?: 'AO'),
                'companyName' => $cliente?->nome ?: 'Consumidor Final',
                'lines' => $linhas,
                'documentTotals' => ['taxPayable' => (float) $venda->total_imposto, 'netTotal' => (float) $venda->total_liquido, 'grossTotal' => (float) $venda->total_bruto]
                    + ($estrangeira ? ['currency' => ['currencyCode' => $venda->codigo_moeda, 'currencyAmount' => (float) $venda->total_bruto_moeda,
                        'exchangeRate' => (float) $venda->taxa_cambio]] : []),
            ],
        ];
    }

    /** @return array{0: list<string>, 1: list<string>} [erros, avisos] (validar, js/facturacao_agt.js:320-402) */
    private function validar(array $d, Venda $venda, ?string $referenciaOrigem): array
    {
        $erros = $avisos = [];
        $doc = $d['documento'];
        if (! $d['taxRegistrationNumber'] || ! preg_match('/^[0-9A-Za-z]{9,15}$/', (string) $d['taxRegistrationNumber'])) {
            $erros[] = 'E01/E02: NIF da empresa em falta ou inválido.';
        }
        if (! preg_match('/^[A-Z]{2} [A-Za-z0-9]+\/[1-9]\d*$/', $doc['documentNo']) || strlen($doc['documentNo']) < 8 || strlen($doc['documentNo']) > 60) {
            $erros[] = "E02: número de documento com formato inválido ({$doc['documentNo']}).";
        }
        if (! str_starts_with($doc['documentNo'], $doc['documentType'].' ')) {
            $erros[] = 'E03: o prefixo do número não corresponde ao tipo de documento.';
        }
        // regras do legado (validar, js/facturacao_agt.js:345-372) que faltavam
        if (! $doc['customerTaxID'] || mb_strlen((string) $doc['customerTaxID']) > 50) {
            $erros[] = 'E02: NIF do cliente inválido (1 a 50 caracteres).';
        } elseif ($doc['customerTaxID'] === '999999999') {
            $avisos[] = 'Cliente sem NIF: documento emitido a consumidor final (999999999).';
        }
        if (! preg_match('/^[A-Z]{2}$/', (string) $doc['customerCountry'])) {
            $erros[] = "E02: país do cliente deve ter o código ISO de 2 letras ({$doc['customerCountry']}).";
        }
        if (! $doc['companyName']) {
            $erros[] = 'E01: nome do cliente em falta.';
        }
        if (! $doc['lines']) {
            $erros[] = 'E01: documento sem linhas.';
        }
        $somaImposto = $somaLiquido = '0.00';
        foreach ($doc['lines'] as $l) {
            $valor = (string) ($l['creditAmount'] ?? $l['debitAmount']);
            $imposto = (string) $l['taxes'][0]['taxContribution'];
            if (! isset(self::OPERACOES[$l['operationType']])) {
                $erros[] = "E03: linha {$l['lineNumber']} com tipo de operação inválido ({$l['operationType']}).";
            }
            if (! $l['productCode']) {
                $erros[] = "E01: linha {$l['lineNumber']} sem código do produto.";
            }
            if (! $l['unitOfMeasure']) {
                $erros[] = "E01: linha {$l['lineNumber']} sem unidade de medida.";
            }
            $isencao = $l['taxes'][0]['taxExemptionCode'] ?? null;
            if ($l['taxes'][0]['taxCode'] === 'ISE' && $isencao && ! preg_match('/^M\d{2}$/', (string) $isencao)) {
                $erros[] = "E02: linha {$l['lineNumber']} com motivo de isenção de formato inválido ({$isencao}).";
            }
            if ($l['quantity'] <= 0) {
                $erros[] = "E03: linha {$l['lineNumber']} com quantidade não positiva.";
            }
            if ($l['unitPrice'] < 0) {
                $erros[] = "E03: linha {$l['lineNumber']} com preço negativo.";
            }
            if (! $l['productDescription']) {
                $erros[] = "E01: linha {$l['lineNumber']} sem descrição do produto.";
            }
            $esperado = CalculadoraDocumento::excessoCentimo(bcdiv(bcmul(number_format((float) $valor, 2, '.', ''), (string) $l['taxes'][0]['taxPercentage'], 8), '100', 8));
            if (bccomp(number_format((float) $imposto, 2, '.', ''), $esperado, 2) !== 0) {
                $erros[] = "E03: linha {$l['lineNumber']} com imposto diferente do calculado ({$esperado}).";
            }
            if ($l['taxes'][0]['taxCode'] === 'ISE' && empty($l['taxes'][0]['taxExemptionCode'])) {
                $erros[] = "E01: linha {$l['lineNumber']} isenta sem motivo de isenção (código M).";
            }
            if ($l['taxes'][0]['taxCode'] === 'OUT') {
                $avisos[] = "Linha {$l['lineNumber']}: taxa de IVA fora das taxas legais (código OUT).";
            }
            $somaImposto = bcadd($somaImposto, number_format((float) $imposto, 2, '.', ''), 2);
            $somaLiquido = bcadd($somaLiquido, number_format((float) $valor, 2, '.', ''), 2);
        }
        $t = $doc['documentTotals'];
        if ($doc['lines'] && bccomp($somaLiquido, '0', 2) <= 0) {
            $erros[] = $doc['documentType'] === 'NC' ? 'E03: numa nota de crédito o total a débito tem de ser superior a zero.'
                : 'E03: o total a crédito tem de ser superior a zero (documento de valor zero).';
        }
        if (bccomp($somaImposto, number_format($t['taxPayable'], 2, '.', ''), 2) !== 0) {
            $erros[] = 'E22: soma do imposto das linhas diferente do total do imposto.';
        }
        if (bccomp($somaLiquido, number_format($t['netTotal'], 2, '.', ''), 2) !== 0) {
            $erros[] = 'E23: soma dos valores líquidos diferente do total líquido.';
        }
        if (abs($t['netTotal'] + $t['taxPayable'] - $t['grossTotal']) > 0.001) {
            $erros[] = 'E24: total bruto diferente de líquido + imposto.';
        }
        if ($venda->tipo_documento === 'NC' && (! $venda->motivo_nota_credito || ! $referenciaOrigem)) {
            $erros[] = 'E01: nota de crédito sem referência ou motivo.';
        }

        return [$erros, $avisos];
    }
}
