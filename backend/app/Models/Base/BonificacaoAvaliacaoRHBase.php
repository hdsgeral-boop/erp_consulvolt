<?php

namespace App\Models\Base;

use App\Models\AvaliacaoDesempenhoRH;
use App\Models\CicloAvaliacao360;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaFolhaSalarial;
use App\Models\ModeloBase;
use App\Models\PeriodoProcessamentoSalarial;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela bonificacoes_avaliacao_rh (módulo RH). Legado: rh_eval_bonus · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\BonificacaoAvaliacaoRH.
 */
abstract class BonificacaoAvaliacaoRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'bonificacoes_avaliacao_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'ciclo_avaliacao_id', 'colaborador_id', 'avaliacao_desempenho_id', 'metodo', 'classificacao', 'nota', 'base', 'valor', 'estado', 'calculado_por', 'calculado_em', 'aprovado_por', 'aprovado_em', 'linha_folha_salarial_id', 'periodo_processamento_salarial_id', 'lancado_por', 'lancado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ciclo_avaliacao_id' => 'integer',
            'colaborador_id' => 'integer',
            'avaliacao_desempenho_id' => 'integer',
            'nota' => 'decimal:2',
            'base' => 'decimal:2',
            'valor' => 'decimal:2',
            'calculado_em' => 'datetime',
            'aprovado_em' => 'datetime',
            'linha_folha_salarial_id' => 'integer',
            'periodo_processamento_salarial_id' => 'integer',
            'lancado_em' => 'datetime',
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

    public function avaliacaoDesempenho(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoDesempenhoRH::class, 'avaliacao_desempenho_id');
    }

    public function linhaFolhaSalarial(): BelongsTo
    {
        return $this->belongsTo(LinhaFolhaSalarial::class, 'linha_folha_salarial_id');
    }

    public function periodoProcessamentoSalarial(): BelongsTo
    {
        return $this->belongsTo(PeriodoProcessamentoSalarial::class, 'periodo_processamento_salarial_id');
    }

    public function linhasFolhaSalarial(): HasMany
    {
        return $this->hasMany(LinhaFolhaSalarial::class, 'bonificacao_avaliacao_id');
    }
}
