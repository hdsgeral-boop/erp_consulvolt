<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContaMesaPOS;
use App\Models\ModeloBase;
use App\Models\TerminalPOS;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela mesas_pos (módulo POS). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MesaPOS.
 */
abstract class MesaPOSBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'mesas_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'terminal_pos_id', 'nome', 'ordem', 'ativo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'terminal_pos_id' => 'integer',
            'ordem' => 'integer',
            'ativo' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function terminalPos(): BelongsTo
    {
        return $this->belongsTo(TerminalPOS::class, 'terminal_pos_id');
    }

    public function contasMesaPos(): HasMany
    {
        return $this->hasMany(ContaMesaPOS::class, 'mesa_pos_id');
    }
}
