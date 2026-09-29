<?php

namespace App\Models\Base;

use App\Models\BonificacaoAvaliacaoRH;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\InfotipoSalarial;
use App\Models\ModeloBase;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PeriodoProdutividadeRH;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela linhas_folha_salarial (módulo RH). Legado: payroll_entries · 1114 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\LinhaFolhaSalarial.
 */
abstract class LinhaFolhaSalarialBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'linhas_folha_salarial';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'periodo_processamento_salarial_id', 'colaborador_id', 'infotipo_salarial_id', 'valor', 'dias_trabalhados', 'horas', 'origem_efetividade', 'origem_produtividade', 'periodo_produtividade_id', 'origem', 'bonificacao_avaliacao_id',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'periodo_processamento_salarial_id' => 'integer',
            'colaborador_id' => 'integer',
            'infotipo_salarial_id' => 'integer',
            'valor' => 'decimal:2',
            'dias_trabalhados' => 'decimal:3',
            'horas' => 'decimal:3',
            'origem_efetividade' => 'boolean',
            'origem_produtividade' => 'boolean',
            'periodo_produtividade_id' => 'integer',
            'bonificacao_avaliacao_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function periodoProcessamentoSalarial(): BelongsTo
    {
        return $this->belongsTo(PeriodoProcessamentoSalarial::class, 'periodo_processamento_salarial_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function infotipoSalarial(): BelongsTo
    {
        return $this->belongsTo(InfotipoSalarial::class, 'infotipo_salarial_id');
    }

    public function periodoProdutividade(): BelongsTo
    {
        return $this->belongsTo(PeriodoProdutividadeRH::class, 'periodo_produtividade_id');
    }

    public function bonificacaoAvaliacao(): BelongsTo
    {
        return $this->belongsTo(BonificacaoAvaliacaoRH::class, 'bonificacao_avaliacao_id');
    }

    public function bonificacoesAvaliacaoRh(): HasMany
    {
        return $this->hasMany(BonificacaoAvaliacaoRH::class, 'linha_folha_salarial_id');
    }
}
