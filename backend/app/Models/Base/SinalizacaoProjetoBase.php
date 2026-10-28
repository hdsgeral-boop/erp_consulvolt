<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Projeto;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tabela sinalizacoes_catalogo_projeto (módulo Projectos): catálogo partilhado (por empresa) de sinalizações com cor.
 * GERADO — não editar: o código de negócio vai em App\Models\SinalizacaoProjeto.
 */
abstract class SinalizacaoProjetoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'sinalizacoes_catalogo_projeto';

    protected string $moduloAuditoria = 'Projectos';

    protected $fillable = ['empresa_id', 'nome', 'cor'];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projetos(): BelongsToMany
    {
        return $this->belongsToMany(Projeto::class, 'projeto_sinalizacao', 'sinalizacao_id', 'projeto_id')
            ->withPivot(['motivo', 'levantada_por', 'levantada_em']);
    }
}
