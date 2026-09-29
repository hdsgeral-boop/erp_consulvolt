<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela efectividade_assiduidade (módulo RH). Legado: rh_attendance · 190 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\EfectividadeAssiduidade.
 */
abstract class EfectividadeAssiduidadeBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'efectividade_assiduidade';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'data', 'colaborador_id', 'entrada', 'saida', 'horas', 'origem', 'fonte', 'observacoes', 'atualizado_por', 'criado_por', 'autorizado_extra',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data' => 'date',
            'colaborador_id' => 'integer',
            'horas' => 'decimal:3',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }
}
