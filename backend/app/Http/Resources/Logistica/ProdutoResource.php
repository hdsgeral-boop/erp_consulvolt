<?php

namespace App\Http\Resources\Logistica;

use App\Models\Produto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Produto */
final class ProdutoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detalhe = $request->routeIs('logistica.produtos.show', 'logistica.produtos.store', 'logistica.produtos.update');

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nome' => $this->nome,
            'preco_unitario' => $this->preco_unitario,
            'taxa_imposto' => $this->taxa_imposto,
            'categoria_produto_id' => $this->categoria_produto_id,
            'movimenta_stock' => (bool) $this->movimenta_stock,
            'e_servico' => (bool) $this->e_servico,
            'e_quarto' => (bool) $this->e_quarto,
            'e_ativo_imobilizado' => (bool) $this->e_ativo_imobilizado,
            'bloqueado' => (bool) $this->bloqueado,
            'unidade_fe' => $this->unidade_fe,
            'tipo_operacao_fe' => $this->tipo_operacao_fe,
            'codigo_isencao_fe' => $this->codigo_isencao_fe,
            'contas' => [
                'venda' => $this->codigo_conta, 'custo' => $this->conta_custo, 'compra' => $this->conta_compra, 'inventario' => $this->conta_inventario,
                'iva_liquidado' => $this->conta_iva_liquidado, 'iva_dedutivel' => $this->conta_iva_dedutivel,
                'quebra' => $this->conta_quebra, 'sobra' => $this->conta_sobra, 'ativo' => $this->conta_ativo,
            ],
            'hotelaria' => $this->when((bool) $this->e_quarto, fn () => ['preco_por_hora' => $this->preco_por_hora, 'preco_por_dia' => $this->preco_por_dia, 'horas_minimas' => $this->horas_minimas]),
            'lavandaria' => $this->when((bool) $this->lavandaria_ativa, fn () => ['grupo' => $this->lavandaria_grupo, 'unidade' => $this->lavandaria_unidade,
                'dias_entrega' => $this->lavandaria_dias_entrega, 'requer_orcamento' => (bool) $this->lavandaria_requer_orcamento,
                'preco_peca' => $this->lavandaria_preco_peca, 'preco_kg' => $this->lavandaria_preco_kg]),
            'imagem_base64' => $this->when($detalhe, $this->imagem_base64),
            // Stock informativo do legado (products.stock_qty); o stock real é por armazém (/api/logistica/stock).
            'quantidade_stock_legado' => $this->when($detalhe, $this->quantidade_stock),
        ];
    }
}
