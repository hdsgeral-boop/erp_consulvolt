<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\CoordenadaBancariaColaborador;
use App\Models\ModeloBase;
use App\Models\ReciboVenda;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela bancos (módulo RH). Legado: banks · 13 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\Banco.
 */
abstract class BancoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'bancos';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'nome', 'codigo', 'nif', 'endereco', 'codigo_conta',
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

    public function recibosVenda(): HasMany
    {
        return $this->hasMany(ReciboVenda::class, 'banco_id');
    }

    public function coordenadasBancariasColaboradores(): HasMany
    {
        return $this->hasMany(CoordenadaBancariaColaborador::class, 'banco_id');
    }
}
