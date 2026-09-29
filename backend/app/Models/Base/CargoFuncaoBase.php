<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PostoTrabalho;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela cargos_funcoes (módulo RH). Legado: roles · 76 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CargoFuncao.
 */
abstract class CargoFuncaoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'cargos_funcoes';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'nome', 'descricao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'cargo_funcao_id');
    }

    public function postosTrabalho(): HasMany
    {
        return $this->hasMany(PostoTrabalho::class, 'cargo_funcao_id');
    }
}
