<?php

namespace App\Http\Resources\Terceiros;

use App\Models\EnderecoTerceiro;
use App\Models\Terceiro;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Terceiro */
final class TerceiroResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'nif' => $this->nif,
            'tipo' => $this->tipo,
            'e_cliente' => $this->eCliente(),
            'e_fornecedor' => $this->eFornecedor(),
            'endereco' => $this->endereco,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'codigo_conta' => $this->codigo_conta,
            'conta_compra_transitoria' => $this->conta_compra_transitoria,
            'codigo_moeda' => $this->codigo_moeda,
            'fe_pais' => $this->fe_pais,
            // só na lista de clientes: tem documentos de venda/recibos (pode ainda não ter o papel de cliente — 4.4.2)
            'tem_documentos_venda' => $this->when(array_key_exists('tem_documentos_venda', $this->resource->getAttributes()), fn () => (bool) $this->resource->getAttribute('tem_documentos_venda')),
            'enderecos' => $this->when($request->routeIs('terceiros.show'), fn () => EnderecoTerceiro::query()->where('nif', $this->nif)->get()),
        ];
    }
}
