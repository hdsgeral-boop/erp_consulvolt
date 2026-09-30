<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaOrcamento;
use App\Models\LinhaPrevisaoOrcamental;
use App\Models\LogAlertaOrcamental;
use App\Models\ModeloBase;
use App\Models\PedidoExtrapolacaoOrcamento;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela rubricas_orcamentais (módulo Orçamento). Legado: orc_rubrics · 75 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RubricaOrcamental.
 */
abstract class RubricaOrcamentalBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'rubricas_orcamentais';

    protected string $moduloAuditoria = 'Orçamento';

    protected $fillable = [
        'empresa_id', 'tipo', 'tipo_original', 'codigo', 'nome', 'natureza', 'natureza_original', 'grupo', 'contas', 'ordem', 'ativo', 'descricao', 'controlo', 'indutor', 'cambial_pct', 'variavel_pct',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'contas' => 'array',
            'ordem' => 'integer',
            'ativo' => 'boolean',
            'controlo' => 'array',
            'cambial_pct' => 'decimal:4',
            'variavel_pct' => 'decimal:4',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function linhasOrcamento(): HasMany
    {
        return $this->hasMany(LinhaOrcamento::class, 'rubrica_orcamental_id');
    }

    public function linhasPrevisaoOrcamental(): HasMany
    {
        return $this->hasMany(LinhaPrevisaoOrcamental::class, 'rubrica_orcamental_id');
    }

    public function pedidosExtrapolacaoOrcamento(): HasMany
    {
        return $this->hasMany(PedidoExtrapolacaoOrcamento::class, 'rubrica_orcamental_id');
    }

    public function logsAlertasOrcamentais(): HasMany
    {
        return $this->hasMany(LogAlertaOrcamental::class, 'rubrica_orcamental_id');
    }
}
