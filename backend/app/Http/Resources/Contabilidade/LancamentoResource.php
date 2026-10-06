<?php

namespace App\Http\Resources\Contabilidade;

use App\Models\LancamentoContabil;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LancamentoContabil */
final class LancamentoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'diario_id' => $this->diario_id,
            'numero_lan' => $this->numero_lan,
            'numero_documento' => $this->numero_documento,
            'referencia' => $this->referencia,
            'data_documento' => $this->data_documento?->toDateString(),
            'data_lancamento' => $this->data_lancamento?->toAtomString(),
            'codigo_conta' => $this->codigo_conta,
            'tipo_dc' => $this->tipo_dc,
            'valor' => $this->valor,
            'descricao' => $this->descricao,
            'terceiro_id' => $this->terceiro_id,
            // {id, nome, nif}: só com a relação carregada (eager loading nos controladores; aqui nunca há carregamento preguiçoso)
            'terceiro' => $this->terceiro_id === null ? null : $this->whenLoaded('terceiro', fn () => $this->terceiro
                ? ['id' => $this->terceiro->id, 'nome' => $this->terceiro->nome, 'nif' => $this->terceiro->nif]
                : ['id' => $this->terceiro_id, 'nome' => null, 'nif' => null]),
            'centro_custo_id' => $this->centro_custo_id,
            'unidade_negocio_id' => $this->unidade_negocio_id,
            'projeto_id' => $this->projeto_id,
            'nota_demonstracao_id' => $this->nota_demonstracao_id,
            'nota_fluxo_caixa_id' => $this->nota_fluxo_caixa_id,
            'reconciliacao_codigo' => $this->reconciliacao_codigo,
            // moeda estrangeira da linha (A-07; M7 do legado: colunas Moeda, Valor na moeda e Câmbio da lista)
            'codigo_moeda' => $this->codigo_moeda,
            'valor_moeda' => $this->valor_moeda,
            'taxa_cambio' => $this->taxa_cambio,
            'tipo_origem' => $this->tipo_origem,
            'estorno_de_id' => $this->estorno_de_id,
            'estornado_por_id' => $this->estornado_por_id,
            'estornado_em' => $this->estornado_em?->toAtomString(),
            'nome_utilizador' => $this->nome_utilizador,
        ];
    }
}
