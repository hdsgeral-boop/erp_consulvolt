<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\OrdemManutencaoAtivo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela registos_manutencao_ativos (módulo Activos). Legado: asset_maintenance_records · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RegistoManutencaoAtivo.
 */
abstract class RegistoManutencaoAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'registos_manutencao_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'ativo_imobilizado_id', 'tipo', 'tipo_original', 'data', 'descricao', 'custo', 'estado', 'estado_original', 'resolucao', 'data_execucao', 'ordem_manutencao_id', 'numero_ordem',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'data' => 'date',
            'custo' => 'decimal:2',
            'data_execucao' => 'date',
            'ordem_manutencao_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }

    public function ordemManutencao(): BelongsTo
    {
        return $this->belongsTo(OrdemManutencaoAtivo::class, 'ordem_manutencao_id');
    }
}
