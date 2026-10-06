<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Empresa;
use App\Models\ModeloBase;
use App\Models\Utilizador;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela preferencias_utilizador (módulo Sistema). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PreferenciaUtilizador.
 */
abstract class PreferenciaUtilizadorBase extends ModeloBase
{
    use Auditavel;

    protected $table = 'preferencias_utilizador';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'utilizador_id', 'tipo', 'nome', 'valor',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'utilizador_id' => 'integer',
            'valor' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
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
