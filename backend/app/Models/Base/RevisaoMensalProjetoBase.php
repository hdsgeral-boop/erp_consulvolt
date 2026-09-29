<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LinhaRevisaoProjeto;
use App\Models\ModeloBase;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela revisoes_mensais_projeto (módulo Projectos). Legado: project_reviews · 20 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RevisaoMensalProjeto.
 */
abstract class RevisaoMensalProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'revisoes_mensais_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = [
        'empresa_id', 'projeto_id', 'mes', 'ano', 'estado',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'projeto_id' => 'integer',
            'mes' => 'integer',
            'ano' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function linhasRevisaoProjeto(): HasMany
    {
        return $this->hasMany(LinhaRevisaoProjeto::class, 'revisao_mensal_projeto_id');
    }
}
