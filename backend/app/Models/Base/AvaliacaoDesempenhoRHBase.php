<?php

namespace App\Models\Base;

use App\Models\BonificacaoAvaliacaoRH;
use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'nota_360' => 'decimal:2',
            'componentes_360' => 'array',
            'avisos_360' => 'array',
            'atualizado_360_em' => 'datetime',
            'conhecimento' => 'array',
            'contestacao' => 'array',
            'colaborador_id' => 'integer',
            'ano' => 'integer',
            'criterios' => 'array',
            'objetivos' => 'array',
            'peso_objetivos' => 'decimal:2',
            'pontuacao' => 'decimal:2',
            'pontuacao_criterios' => 'decimal:2',
            'pontuacao_objetivos' => 'decimal:2',
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

    public function bonificacoesAvaliacaoRh(): HasMany
    {
        return $this->hasMany(BonificacaoAvaliacaoRH::class, 'avaliacao_desempenho_id');
    }
}
