<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\Empresa;
use App\Models\GrupoConsolidacao;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela membros_consolidacao (módulo Contabilidade). Legado: consolidation_members · 3 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MembroConsolidacao.
 */
abstract class MembroConsolidacaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'membros_consolidacao';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'grupo_consolidacao_id', 'empresa_membro_id', 'percentagem', 'metodo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'grupo_consolidacao_id' => 'integer',
            'empresa_membro_id' => 'integer',
            'percentagem' => 'decimal:4',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function grupoConsolidacao(): BelongsTo
    {
        return $this->belongsTo(GrupoConsolidacao::class, 'grupo_consolidacao_id');
    }

    public function empresaMembro(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_membro_id');
    }
}
