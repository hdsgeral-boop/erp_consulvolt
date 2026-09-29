<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela respostas_ascendentes_rh (módulo RH). Legado: rh_upward_responses · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RespostaAscendenteRH.
 */
abstract class RespostaAscendenteRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'respostas_ascendentes_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'uid', 'colaborador_alvo_id', 'ano', 'periodo', 'respostas', 'comentario',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_alvo_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaboradorAlvo(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_alvo_id');
    }
}
