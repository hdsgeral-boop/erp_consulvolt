<?php

namespace App\Http\Resources\Terceiros;

use App\Models\ContaBancariaTerceiro;
use App\Models\EnderecoTerceiro;
use App\Models\Terceiro;
use App\Services\Terceiros\ContaTerceiro;
use App\Services\Terceiros\ServicoTerceiros;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Terceiro
 *
 * conta_cliente / conta_fornecedor são as contas EFECTIVAS do papel (a própria ou o codigo_conta antigo da classe certa,
 * como ContaTerceiro.cliente()/fornecedor() do legado novo); codigo_conta é o registo antigo, só para leitura.
 */
final class TerceiroResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $atributos = $this->resource->getAttributes();
        $ficha = $request->routeIs('terceiros.show');

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'nif' => $this->nif,
            'tipo' => $this->tipo,
            'rotulo_tipo' => ContaTerceiro::rotuloTipo($this->tipo),
            'e_cliente' => $this->eCliente(),
            'e_fornecedor' => $this->eFornecedor(),
            'endereco' => $this->endereco,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'codigo_conta' => $this->codigo_conta,
            'conta_cliente' => $this->contaCliente() ?: null,
            'conta_fornecedor' => $this->contaFornecedor() ?: null,
            'conta_compra_transitoria' => $this->conta_compra_transitoria,
            'codigo_moeda' => $this->codigo_moeda,
            'fe_pais' => $this->fe_pais,
            // só na lista de clientes: tem documentos de venda/recibos (pode ainda não ter o papel de cliente — 4.4.2)
            'tem_documentos_venda' => $this->when(array_key_exists('tem_documentos_venda', $atributos), fn () => (bool) $this->resource->getAttribute('tem_documentos_venda')),
            // coluna «Banco» da lista (n.º de contas bancárias e o banco da predefinida)
            'contas_bancarias' => $this->when(array_key_exists('contas_bancarias', $atributos), fn () => (int) $this->resource->getAttribute('contas_bancarias')),
            'banco_predefinido' => $this->when(array_key_exists('banco_predefinido', $atributos), fn () => $this->resource->getAttribute('banco_predefinido')),
            'enderecos' => $this->when($ficha, fn () => EnderecoTerceiro::query()->where('nif', $this->nif)->get()),
            'morada' => $this->when($ficha, fn () => app(ServicoTerceiros::class)->morada($this->resource)?->only(EnderecoTerceiro::CAMPOS)),
            'lista_contas_bancarias' => $this->when($ficha, fn () => ContaBancariaTerceiro::query()->where('terceiro_id', $this->id)
                ->orderByDesc('predefinida')->orderBy('id')->get()),
        ];
    }
}
