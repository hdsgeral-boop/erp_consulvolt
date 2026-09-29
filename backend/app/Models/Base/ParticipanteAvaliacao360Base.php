<?php

namespace App\Models\Base;

use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela participantes_avaliacao_360 (módulo RH). Legado: rh_eval_360_part · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ParticipanteAvaliacao360.
 */
abstract class ParticipanteAvaliacao360Base extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'participantes_avaliacao_360';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'uid', 'ciclo_avaliacao_id', 'colaborador_avaliador_id', 'colaborador_avaliado_id', 'grupo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ciclo_avaliacao_id' => 'integer',
            'colaborador_avaliador_id' => 'integer',
            'colaborador_avaliado_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function cicloAvaliacao(): BelongsTo
    {
        return $this->belongsTo(CicloAvaliacao360::class, 'ciclo_avaliacao_id');
    }

    public function colaboradorAvaliador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_avaliador_id');
    }

    public function colaboradorAvaliado(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_avaliado_id');
    }
}
