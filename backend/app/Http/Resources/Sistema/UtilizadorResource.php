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
            'telefone' => $this->telefone,
            'papel' => $this->papel,
            'perfil' => $this->whenLoaded('perfil', fn () => $this->perfil ? ['id' => $this->perfil->id, 'nome' => $this->perfil->nome] : null),
            'acesso_todas_empresas' => (bool) $this->acesso_todas_empresas,
            'ativo' => (bool) $this->ativo,
            // palavra-passe provisória (definida por um administrador): o ecrã pede a alteração no primeiro acesso
            'alterar_palavra_passe' => (bool) $this->alterar_palavra_passe,
            'ultimo_acesso_em' => $this->ultimo_acesso_em?->toAtomString(),
        ];
    }
}
