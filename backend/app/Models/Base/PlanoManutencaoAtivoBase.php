<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\OrdemManutencaoAtivo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela planos_manutencao_ativos (módulo Activos). Legado: asset_maintenance_plans · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PlanoManutencaoAtivo.
 */
abstract class PlanoManutencaoAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'planos_manutencao_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'ativo_imobilizado_id', 'categoria_ativo_id', 'tipo', 'periodicidade', 'proxima_data', 'proximo_contador', 'contador_atual', 'antecedencia_dias', 'duracao_horas', 'responsavel', 'instrucoes', 'tarefas', 'itens', 'ativo', 'ultima_data', 'execucoes', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'categoria_ativo_id' => 'integer',
            'periodicidade' => 'array',
            'proxima_data' => 'date',
            'proximo_contador' => 'decimal:2',
            'contador_atual' => 'decimal:2',
            'antecedencia_dias' => 'integer',
            'duracao_horas' => 'decimal:3',
            'tarefas' => 'array',
            'itens' => 'array',
            'ativo' => 'boolean',
            'ultima_data' => 'date',
            'execucoes' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }

    public function categoriaAtivo(): BelongsTo
    {
        return $this->belongsTo(CategoriaAtivo::class, 'categoria_ativo_id');
    }

    public function ordensManutencaoAtivos(): HasMany
    {
        return $this->hasMany(OrdemManutencaoAtivo::class, 'plano_manutencao_id');
    }
}
