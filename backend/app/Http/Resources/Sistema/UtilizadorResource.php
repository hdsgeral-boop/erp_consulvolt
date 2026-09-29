<?php

namespace App\Http\Resources\Sistema;

use App\Models\Utilizador;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Utilizador */
final class UtilizadorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome_utilizador' => $this->nome_utilizador,
            'nome_completo' => $this->nome_completo,
            'email' => $this->email,
            'papel' => $this->papel,
            'perfil' => $this->whenLoaded('perfil', fn () => $this->perfil ? ['id' => $this->perfil->id, 'nome' => $this->perfil->nome] : null),
            'acesso_todas_empresas' => (bool) $this->acesso_todas_empresas,
            'ativo' => (bool) $this->ativo,
            'ultimo_acesso_em' => $this->ultimo_acesso_em?->toAtomString(),
        ];
    }
}
