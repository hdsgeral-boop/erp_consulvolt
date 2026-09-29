<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DiarioContabil;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela configuracoes_acrescimos_diferimentos (módulo Acréscimos). Legado: ad_settings · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfigAcrescimoDiferimento.
 */
abstract class ConfigAcrescimoDiferimentoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_acrescimos_diferimentos';

    protected string $moduloAuditoria = 'Acréscimos';

    protected $fillable = [
        'empresa_id', 'contas', 'diario_id', 'prazo_documento_dias', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'contas' => 'array',
            'diario_id' => 'integer',
            'prazo_documento_dias' => 'integer',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function diario(): BelongsTo
    {
        return $this->belongsTo(DiarioContabil::class, 'diario_id');
    }
}
