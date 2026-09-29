<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Empresa (tenant). Legado: companies.
 */
class Empresa extends ModeloBase
{
    use Auditavel;
    use SoftDeletes;

    public const ESTADO_ATIVO = 'ATIVO';

    public const ESTADO_INATIVO = 'INATIVO';

    protected $table = 'empresas';

    protected string $moduloAuditoria = 'Sistema/Empresas';

    protected $fillable = [
        'nome', 'nif', 'endereco', 'provincia', 'municipio', 'comuna', 'telefone', 'email', 'website',
        'numero_registo_comercial', 'rodape_documento', 'logotipo', 'taxa_inss_patronal', 'taxa_inss_trabalhador',
        'regras_ia', 'estado', 'e_consolidacao', 'moeda_consolidacao', 'data_fim_consolidacao',
    ];

    protected $hidden = ['logotipo'];

    protected function casts(): array
    {
        return [
            'taxa_inss_patronal' => 'decimal:2',
            'taxa_inss_trabalhador' => 'decimal:2',
            'e_consolidacao' => 'boolean',
            'data_fim_consolidacao' => 'date',
        ];
    }

    public function utilizadores(): BelongsToMany
    {
        return $this->belongsToMany(Utilizador::class, 'utilizador_empresa', 'empresa_id', 'utilizador_id')
            ->withPivot('colaborador_id');
    }

    public function estaAtiva(): bool
    {
        return $this->estado === self::ESTADO_ATIVO && ! $this->trashed();
    }
}
