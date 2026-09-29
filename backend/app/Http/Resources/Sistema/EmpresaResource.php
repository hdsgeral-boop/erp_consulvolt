<?php

namespace App\Http\Resources\Sistema;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Empresa */
final class EmpresaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'nif' => $this->nif,
            'endereco' => $this->endereco,
            'provincia' => $this->provincia,
            'municipio' => $this->municipio,
            'comuna' => $this->comuna,
            'telefone' => $this->telefone,
            'email' => $this->email,
            'website' => $this->website,
            'numero_registo_comercial' => $this->numero_registo_comercial,
            'taxa_inss_patronal' => $this->taxa_inss_patronal !== null ? (float) $this->taxa_inss_patronal : null,
            'taxa_inss_trabalhador' => $this->taxa_inss_trabalhador !== null ? (float) $this->taxa_inss_trabalhador : null,
            'estado' => $this->estado,
            'e_consolidacao' => (bool) $this->e_consolidacao,
            'moeda_consolidacao' => $this->moeda_consolidacao,
            'tem_logotipo' => $this->logotipo !== null && $this->logotipo !== '',
            // O logótipo (base64, dezenas de KB) só é enviado no detalhe.
            'logotipo' => $this->when($request->routeIs('sistema.empresas.show'), $this->logotipo),
        ];
    }
}
