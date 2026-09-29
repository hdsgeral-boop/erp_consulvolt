<?php

namespace App\Models\Base;

use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela feedbacks_avaliacao_360 (módulo RH). Legado: rh_eval_feedback · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\FeedbackAvaliacao360.
 */
abstract class FeedbackAvaliacao360Base extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'feedbacks_avaliacao_360';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'ciclo_avaliacao_id', 'colaborador_id', 'colaborador_chefia_id', 'periodo_referencia', 'data', 'objetivos', 'positivos', 'melhorar', 'acordos', 'registado_por', 'registado_em', 'confirmacao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ciclo_avaliacao_id' => 'integer',
            'colaborador_id' => 'integer',
            'colaborador_chefia_id' => 'integer',
            'data' => 'date',
            'objetivos' => 'array',
            'registado_em' => 'datetime',
            'confirmacao' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function cicloAvaliacao(): BelongsTo
    {
        return $this->belongsTo(CicloAvaliacao360::class, 'ciclo_avaliacao_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function colaboradorChefia(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_chefia_id');
    }
}
