<?php

namespace App\Models\Base;

use App\Models\AssuntoMetodoTrabalho;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela historico_assuntos_metodo (módulo Projectos). Legado: mt_assunto_log · 12 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\HistoricoAssuntoMetodo.
 */
abstract class HistoricoAssuntoMetodoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'historico_assuntos_metodo';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'assunto_id', 'acao', 'detalhe', 'por', 'em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'assunto_id' => 'integer',
            'em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function assunto(): BelongsTo
    {
        return $this->belongsTo(AssuntoMetodoTrabalho::class, 'assunto_id');
    }
}
