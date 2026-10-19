<?php

namespace App\Http\Resources\Vendas;

use App\Models\ItemVenda;
use App\Models\Venda;
use App\Services\Vendas\ServicoEnvioAgt;
use App\Services\Vendas\ServicoHashSaft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Venda */
final class VendaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo_documento' => $this->tipo_documento,
            'numero_documento' => $this->numero_documento,
            'data_emissao' => $this->data_emissao?->toDateString(),
            'cliente' => $this->whenLoaded('cliente', fn () => ['id' => $this->cliente?->id, 'nome' => $this->cliente?->nome, 'nif' => $this->cliente?->nif, 'endereco' => $this->cliente?->endereco]),
            'cliente_id' => $this->cliente_id,
            'total_liquido' => $this->total_liquido,
            'total_imposto' => $this->total_imposto,
            'total_bruto' => $this->total_bruto,
            'valor_pago' => $this->valor_pago,
            'valor_pendente' => $this->valor_pendente,
            'estado' => $this->estado,
            'contabilizado' => (bool) $this->contabilizado,
            'numero_lan_contabilizacao' => $this->numero_lan_contabilizacao,
            'desconto' => $this->desconto,
            // decisão 10: arredondamento AGT do POS separado do desconto (coluna da ronda 2; lida em bruto até o model ser regenerado)
            'arredondamento_agt' => $this->resource->getAttributes()['arredondamento_agt'] ?? null,
            'codigo_moeda' => $this->codigo_moeda ?: 'AOA',
            'moeda' => $this->codigo_moeda && $this->codigo_moeda !== 'AOA' ? [
                'codigo' => $this->codigo_moeda, 'taxa_cambio' => $this->taxa_cambio, 'taxa_cambio_manual' => (bool) $this->taxa_cambio_manual,
                'total_liquido' => $this->total_liquido_moeda, 'total_imposto' => $this->total_imposto_moeda, 'total_bruto' => $this->total_bruto_moeda,
            ] : null,
            'modo_pagamento' => $this->modo_pagamento,
            'plano_pagamentos' => $this->plano_pagamentos,
            'data_vencimento' => $this->data_vencimento?->toDateString(),
            'valido_ate' => $this->valido_ate?->toDateString(),
            'meio_pagamento' => $this->meio_pagamento,
            'motivo_nota_credito' => $this->motivo_nota_credito,
            'observacoes' => $this->observacoes,
            'condicoes_pagamento' => $this->condicoes_pagamento,
            'unidade_negocio_id' => $this->unidade_negocio_id,
            'centro_custo_id' => $this->centro_custo_id,
            'projeto_id' => $this->projeto_id,
            // paridade (RELATORIO 4.2.4/4.8.2): códigos da UN e do CC e o progresso de entrega/facturação (OR/PF/NE)
            'unidade_negocio' => $this->whenLoaded('unidadeNegocio', fn () => $this->unidadeNegocio ? ['id' => $this->unidadeNegocio->id, 'codigo' => $this->unidadeNegocio->codigo, 'nome' => $this->unidadeNegocio->nome] : null),
            'centro_custo' => $this->whenLoaded('centroCusto', fn () => $this->centroCusto ? ['id' => $this->centroCusto->id, 'codigo' => $this->centroCusto->codigo, 'descricao' => $this->centroCusto->descricao] : null),
            'progresso' => $this->when(array_key_exists('soma_quantidade', $this->resource->getAttributes()), fn () => self::progresso(
                (float) $this->resource->getAttributes()['soma_quantidade'], (float) ($this->resource->getAttributes()['soma_entregue'] ?? 0), (float) ($this->resource->getAttributes()['soma_faturada'] ?? 0))),
            // paridade 4.2.12: contas dos meios de pagamento mostradas no documento e opção de as ocultar na impressão
            'contas_pagamento' => self::contasPagamento($this->contas_pagamento),
            'ocultar_meios_pagamento' => (bool) $this->ocultar_meios_pagamento,
            'faturacao_eletronica' => [
                'serie' => $this->fe_serie, 'numero' => $this->fe_numero, 'estado' => $this->fe_estado, 'regime' => $this->fe_regime,
                'selado_em' => $this->fe_selado_em?->toIso8601String(), 'erros' => $this->fe_erros ?? [], 'avisos' => $this->fe_avisos ?? [],
                'envio' => ServicoEnvioAgt::estadoEnvio($this->resource), 'erros_agt' => $this->fe_envio['erros'] ?? [],
                // última resposta da AGT (A-04): pedido, datas, correcção e as últimas acções registadas no envio
                'envio_detalhe' => $this->fe_envio ? [
                    'request_id' => $this->fe_envio['requestID'] ?? null, 'enviado_em' => $this->fe_envio['enviado_em'] ?? null,
                    'validado_em' => $this->fe_envio['validado_em'] ?? null, 'ultima_consulta' => $this->fe_envio['ultima_consulta'] ?? null,
                    'proxima_consulta' => $this->fe_envio['proxima_consulta'] ?? null, 'correccao' => (bool) ($this->fe_envio['correccao'] ?? false),
                    'tentativas' => (int) ($this->fe_envio['tentativas'] ?? 0), 'historico' => array_values(array_slice((array) ($this->fe_envio['historico'] ?? []), -5)),
                ] : null,
                'hash' => ServicoHashSaft::excerto($this->saft_hash), 'hash_controlo' => $this->saft_hash_controlo,
                // r4_agt: correcções (E46), penalização, contingência, documentos integrados e identificação do software no impresso
                'documento_rejeitado' => $this->fe_envio['documento_rejeitado'] ?? null, 'substituido_por' => $this->fe_envio['substituido_por'] ?? null,
                'substituido_por_id' => $this->fe_envio['substituido_por_id'] ?? null, 'penalizacao' => (bool) ($this->fe_envio['penalizacao'] ?? false),
                'contingencia_desde' => $this->fe_envio['contingencia_desde'] ?? null,
                'importado' => in_array($this->resource->getAttributes()['saft_source_billing'] ?? null, ['I', 'M'], true),
                'sistema_origem' => $this->resource->getAttributes()['sistema_origem'] ?? null,
                'software' => ['produto' => config('erp.agt.software.productId') ?: null, 'certificacao' => config('erp.agt.software.softwareValidationNumber') ?: null],
            ],
            'linhas' => $this->whenLoaded('itensVenda', fn () => $this->itensVenda->map(fn (ItemVenda $i) => [
                'id' => $i->id, 'produto_id' => $i->produto_id, 'descricao' => $i->descricao, 'quantidade' => $i->quantidade,
                'quantidade_faturada' => $i->quantidade_faturada, 'quantidade_devolvida' => $i->quantidade_devolvida, 'preco_unitario' => $i->preco_unitario, 'taxa_imposto' => $i->taxa_imposto,
                'valor' => $i->total_linha, 'total' => $i->total, 'observacoes' => $i->observacoes,
                'preco_unitario_moeda' => $i->preco_unitario_moeda, 'total_moeda' => $i->total_moeda,
                // ADR-071 (R5): UN/CC da linha (vazio = cabeçalho); nomes só quando as relações vêm carregadas (detalhe)
                'unidade_negocio_id' => $i->unidade_negocio_id, 'centro_custo_id' => $i->centro_custo_id,
                'unidade_negocio' => $i->relationLoaded('unidadeNegocio') && $i->unidadeNegocio ? ['id' => $i->unidadeNegocio->id, 'codigo' => $i->unidadeNegocio->codigo, 'nome' => $i->unidadeNegocio->nome] : null,
                'centro_custo' => $i->relationLoaded('centroCusto') && $i->centroCusto ? ['id' => $i->centroCusto->id, 'codigo' => $i->centroCusto->codigo, 'descricao' => $i->centroCusto->descricao] : null,
            ])->values()),
        ];
    }

    /**
     * Percentagens entregue/facturada das linhas (barras «ENT» e «FAT» do legado, arredondadas, 0–100).
     *
     * @return array{entrega: int, faturacao: int}
     */
    public static function progresso(float $quantidade, float $entregue, float $faturada): array
    {
        $pct = fn (float $v) => $quantidade > 0 ? (int) max(0, min(100, round($v / $quantidade * 100))) : 0;

        return ['entrega' => $pct($entregue), 'faturacao' => $pct($faturada)];
    }

    /**
     * Contas de pagamento do documento: as gravadas pelo ecrã novo (meio_pagamento_id, nome, conta, IBAN, SWIFT) ou as
     * migradas do legado ({code, desc, amount}).
     *
     * @return list<array{meio_pagamento_id: int|null, nome: string|null, codigo_conta: string|null, iban: string|null, swift: string|null}>
     */
    public static function contasPagamento(mixed $contas): array
    {
        if (! is_array($contas)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($c) => is_array($c) ? [
            'meio_pagamento_id' => isset($c['meio_pagamento_id']) ? (int) $c['meio_pagamento_id'] : null,
            'nome' => isset($c['nome']) ? (string) $c['nome'] : (isset($c['desc']) ? (string) $c['desc'] : null),
            'codigo_conta' => isset($c['codigo_conta']) ? (string) $c['codigo_conta'] : (isset($c['code']) ? (string) $c['code'] : null),
            'iban' => isset($c['iban']) ? (string) $c['iban'] : null, 'swift' => isset($c['swift']) ? (string) $c['swift'] : null,
        ] : null, $contas)));
    }
}
