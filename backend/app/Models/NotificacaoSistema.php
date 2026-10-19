<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificação e alerta de sistema por empresa (com isolamento multi-empresa estrito).
 */
class NotificacaoSistema extends ModeloBase
{
    use PertenceEmpresa;

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'notificacoes_sistema';

    protected $fillable = [
        'empresa_id',
        'utilizador_id',
        'autor_nome',
        'tipo',
        'categoria',
        'titulo',
        'mensagem',
        'link',
        'lida',
        'lida_em',
    ];

    protected function casts(): array
    {
        return [
            'lida' => 'boolean',
            'lida_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function utilizador(): BelongsTo
    {
        return $this->belongsTo(Utilizador::class, 'utilizador_id');
    }
}
