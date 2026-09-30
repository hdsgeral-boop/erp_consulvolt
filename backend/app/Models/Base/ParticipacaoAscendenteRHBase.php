<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela participacoes_ascendentes_rh (módulo RH). Legado: rh_upward_participation · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ParticipacaoAscendenteRH.
 */
abstract class ParticipacaoAscendenteRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'participacoes_ascendentes_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'uid', 'colaborador_id', 'colaborador_alvo_id', 'ano', 'periodo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'colaborador_alvo_id' => 'integer',
            'ano' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function colaboradorAlvo(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_alvo_id');
    }
}
