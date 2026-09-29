<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela transferencias_centros_custo_ativos (módulo Activos). Legado: asset_movements · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\TransferenciaCentroCustoAtivo.
 */
abstract class TransferenciaCentroCustoAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'transferencias_centros_custo_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'ativo_imobilizado_id', 'centro_custo_origem_id', 'centro_custo_destino_id', 'data', 'projeto_id', 'codigo_projeto',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'centro_custo_origem_id' => 'integer',
            'centro_custo_destino_id' => 'integer',
            'data' => 'date',
            'projeto_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }

    public function centroCustoOrigem(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_origem_id');
    }

    public function centroCustoDestino(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_destino_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }
}
