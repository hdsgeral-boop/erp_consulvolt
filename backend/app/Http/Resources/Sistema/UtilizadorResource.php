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
            // Áreas do SaaS que o servidor realmente deixa abrir (ExigirPlataforma/ExigirSuporte): o papel sozinho não chega,
            // um super-administrador de um cliente não é da plataforma. O frontend usa isto nas guardas de rota.
            'area_plataforma' => $this->ePlataforma(),
            'area_suporte' => $this->eSuportePlataforma(),
            // Conta ROOT global (ADR-084): só se revela ao próprio titular (nunca em listas de outros utilizadores).
            'conta_root' => $request->user()?->is($this->resource) ? $this->eRoot() : false,
            'ativo' => (bool) $this->ativo,
            'deve_alterar_password' => (bool) $this->deve_alterar_password,
            'ultimo_acesso_em' => $this->ultimo_acesso_em?->toAtomString(),
        ];
    }
}
