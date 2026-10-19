<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Versão de um plano de licença: rascunho editável ou publicada (imutável). A publicada mais recente está em vigor. */
class PlanoLicencaVersao extends ModeloBase
{
    public const RASCUNHO = 'RASCUNHO';

    public const PUBLICADA = 'PUBLICADA';

    public const DESCONTINUADA = 'DESCONTINUADA';

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'planos_licenca_versoes';

    protected $fillable = ['plano_id', 'numero', 'estado', 'preco_mensal', 'moeda', 'max_empresas', 'max_utilizadores', 'modulos', 'publicada_em'];

    protected function casts(): array
    {
        return ['modulos' => 'array', 'preco_mensal' => 'decimal:2', 'publicada_em' => 'datetime'];
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoLicenca::class, 'plano_id');
    }
}