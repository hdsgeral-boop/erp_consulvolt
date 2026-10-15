<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemOrdemManutencao;
use App\Models\ModeloBase;
use App\Models\PlanoManutencaoAtivo;
use App\Models\RegistoManutencaoAtivo;
use App\Models\RequisicaoManutencao;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela ordens_manutencao_ativos (módulo Activos). Legado: asset_maintenance_orders · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\OrdemManutencaoAtivo.
 */
abstract class OrdemManutencaoAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'ordens_manutencao_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'numero', 'ativo_imobilizado_id', 'plano_manutencao_id', 'tipo', 'prioridade', 'estado', 'data_prevista', 'data_inicio', 'data_fim', 'descricao', 'responsavel', 'tarefas', 'horas', 'custo_mao_obra', 'custo_materiais', 'custo_servicos', 'resolucao', 'leitura_contador', 'criado_por', 'motivo_cancelamento', 'cancelado_em', 'cancelado_por', 'concluido_em', 'concluido_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'plano_manutencao_id' => 'integer',
            'data_prevista' => 'date',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'tarefas' => 'array',
            'horas' => 'decimal:3',
            'custo_mao_obra' => 'decimal:2',
            'custo_materiais' => 'decimal:2',
            'custo_servicos' => 'decimal:2',
            'leitura_contador' => 'decimal:2',
            'cancelado_em' => 'datetime',
            'concluido_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }

    public function planoManutencao(): BelongsTo
    {
        return $this->belongsTo(PlanoManutencaoAtivo::class, 'plano_manutencao_id');
    }

    public function registosManutencaoAtivos(): HasMany
    {
        return $this->hasMany(RegistoManutencaoAtivo::class, 'ordem_manutencao_id');
    }

    public function itensOrdemManutencao(): HasMany
    {
        return $this->hasMany(ItemOrdemManutencao::class, 'ordem_manutencao_id');
    }

    public function requisicoesManutencao(): HasMany
    {
        return $this->hasMany(RequisicaoManutencao::class, 'ordem_manutencao_id');
    }
}
