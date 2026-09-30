<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela autoavaliacoes_colaborador (módulo RH). Legado: rh_self_evaluations · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AutoavaliacaoColaborador.
 */
abstract class AutoavaliacaoColaboradorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'autoavaliacoes_colaborador';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'colaborador_id', 'ano', 'periodo', 'criterios', 'objetivos', 'realizacoes', 'dificuldades', 'formacao', 'estado', 'estado_original', 'submetida_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'ano' => 'integer',
            'criterios' => 'array',
            'objetivos' => 'array',
            'submetida_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }
}
