<?php

namespace App\Models\Base;

use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela avaliacoes_desempenho_rh (módulo RH). Legado: rh_evaluations · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AvaliacaoDesempenhoRH.
 */
abstract class AvaliacaoDesempenhoRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'avaliacoes_desempenho_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'ciclo_avaliacao_id', 'nota_360', 'classificacao_360', 'componentes_360', 'avisos_360', 'atualizado_360_em', 'conhecimento', 'comentario_colaborador', 'contestacao', 'colaborador_id', 'ano', 'periodo', 'criterios', 'objetivos', 'peso_objetivos', 'pontuacao', 'pontuacao_criterios', 'pontuacao_objetivos', 'classificacao', 'estado', 'avaliador', 'data_avaliacao', 'pontos_fortes', 'pontos_melhorar', 'plano_desenvolvimento', 'concluida_em', 'concluida_por', 'reaberta_em', 'reaberta_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ciclo_avaliacao_id' => 'integer',
            'avisos_360' => 'date',
            'atualizado_360_em' => 'datetime',
            'colaborador_id' => 'integer',
            'peso_objetivos' => 'decimal:4',
            'data_avaliacao' => 'date',
            'concluida_em' => 'datetime',
            'reaberta_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
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
}
