<?php

namespace App\Http\Resources\Vendas;

use App\Models\ItemVenda;
use App\Models\Venda;
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
            'cliente' => $this->whenLoaded('cliente', fn () => ['id' => $this->cliente?->id, 'nome' => $this->cliente?->nome, 'nif' => $this->cliente?->nif]),
            'cliente_id' => $this->cliente_id,
            'total_liquido' => $this->total_liquido,
            'total_imposto' => $this->total_imposto,
            'total_bruto' => $this->total_bruto,
            'valor_pago' => $this->valor_pago,
            'valor_pendente' => $this->valor_pendente,
            'estado' => $this->estado,
            'contabilizado' => (bool) $this->contabilizado,
            'numero_lan_contabilizacao' => $this->numero_lan_contabilizacao,
            'codigo_moeda' => $this->codigo_moeda,
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
            'faturacao_eletronica' => [
                'serie' => $this->fe_serie, 'numero' => $this->fe_numero, 'estado' => $this->fe_estado, 'regime' => $this->fe_regime,
                'selado_em' => $this->fe_selado_em?->toIso8601String(), 'erros' => $this->fe_erros ?? [], 'avisos' => $this->fe_avisos ?? [],
            ],
            'linhas' => $this->whenLoaded('itensVenda', fn () => $this->itensVenda->map(fn (ItemVenda $i) => [
                'id' => $i->id, 'produto_id' => $i->produto_id, 'descricao' => $i->descricao, 'quantidade' => $i->quantidade,
                'quantidade_faturada' => $i->quantidade_faturada, 'preco_unitario' => $i->preco_unitario, 'taxa_imposto' => $i->taxa_imposto,
                'valor' => $i->total_linha, 'total' => $i->total, 'observacoes' => $i->observacoes,
            ])->values()),
        ];
    }
}
