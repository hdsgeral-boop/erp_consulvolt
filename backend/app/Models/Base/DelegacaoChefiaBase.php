<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela delegacoes_chefia (módulo Recursos Humanos / Estrutura Orgânica, Fase K). Sem legado.
 * Escrita à mão no formato das classes geradas: o código de negócio vai em App\Models\DelegacaoChefia.
 */
abstract class DelegacaoChefiaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'delegacoes_chefia';

    protected string $moduloAuditoria = 'Recursos Humanos › Delegações';

    protected $fillable = [
        'empresa_id', 'delegante_colaborador_id', 'delegado_colaborador_id', 'data_inicio', 'data_fim', 'ambito', 'motivo', 'estado', 'revogada_por', 'revogada_em', 'criado_por', 'criado_por_colaborador_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'delegante_colaborador_id' => 'integer',
            'delegado_colaborador_id' => 'integer',
            'data_inicio' => 'date:Y-m-d',
            'data_fim' => 'date:Y-m-d',
            'ambito' => 'array',
            'revogada_em' => 'datetime',
            'criado_por_colaborador_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function delegante(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'delegante_colaborador_id');
    }

    public function delegado(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'delegado_colaborador_id');
    }
}
