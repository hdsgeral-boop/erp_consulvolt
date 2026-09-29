<?php

namespace App\Models\Concerns;

use App\Exceptions\ErroContextoEmpresa;
use App\Models\Empresa;
use App\Models\Scopes\EscopoEmpresa;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model pertencente a uma empresa (tenant):
 *  - leitura: filtrada pela empresa activa (EscopoEmpresa);
 *  - criação: `empresa_id` preenchido com a empresa activa quando omitido;
 *  - escrita: recusa gravar com `empresa_id` diferente da empresa activa (impede fugas entre empresas).
 *
 * Models em que a empresa é opcional (ex.: logs de autenticação) definem `protected bool $empresaOpcional = true`.
 */
trait PertenceEmpresa
{
    public static function bootPertenceEmpresa(): void
    {
        static::addGlobalScope(new EscopoEmpresa);

        static::saving(function (Model $model) {
            $contexto = app(ContextoEmpresa::class);
            if ($contexto->isolamentoDesligado()) {
                return;
            }

            $atual = $model->getAttribute('empresa_id');

            if ($atual === null) {
                if ($contexto->definida()) {
                    $model->setAttribute('empresa_id', $contexto->id());
                } elseif (! ($model->empresaOpcional ?? false)) {
                    throw ErroContextoEmpresa::naoDefinido();
                }

                return;
            }

            if ($contexto->definida() && (int) $atual !== $contexto->id()) {
                throw ErroContextoEmpresa::empresaDiferente($contexto->id(), (int) $atual);
            }
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
