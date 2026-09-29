<?php

namespace App\Http\Resources\Sistema;

use App\Models\LogAuditoria;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LogAuditoria */
final class LogAuditoriaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ocorrido_em' => $this->ocorrido_em?->toAtomString(),
            'nome_utilizador' => $this->nome_utilizador,
            'modulo' => $this->modulo,
            'acao' => $this->acao,
            'tabela' => $this->tabela,
            'registo_id' => $this->registo_id,
            'detalhes' => $this->detalhes,
            'dados_anteriores' => $this->dados_anteriores,
            'dados_novos' => $this->dados_novos,
            'endereco_ip' => $this->endereco_ip,
        ];
    }
}
