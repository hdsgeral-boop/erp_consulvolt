<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela criterios_avaliacao_rh (módulo RH). Legado: rh_evaluation_items · 26 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CriterioAvaliacaoRH.
 */
abstract class CriterioAvaliacaoRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'criterios_avaliacao_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'ambito', 'colaborador_id', 'tipo', 'chave', 'nome', 'descricao', 'peso', 'ordem', 'ativo', 'natureza', 'meta', 'unidade', 'sentido',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'peso' => 'decimal:4',
            'ordem' => 'integer',
            'ativo' => 'boolean',
            'meta' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }
}
