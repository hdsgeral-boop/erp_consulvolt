<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaRequisicaoProjeto;
use App\Models\ModeloBase;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela requisicoes_material_projeto (módulo Projectos). Legado: project_requisitions · 7 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RequisicaoMaterialProjeto.
 */
abstract class RequisicaoMaterialProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'requisicoes_material_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'nome_requerente', 'data', 'estado', 'data_prevista',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'data' => 'date',
            'data_prevista' => 'date',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function linhasRequisicaoProjeto(): HasMany
    {
        return $this->hasMany(LinhaRequisicaoProjeto::class, 'requisicao_material_projeto_id');
    }
}
