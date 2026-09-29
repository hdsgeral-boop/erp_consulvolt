<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\InfotipoSalarial;
use App\Models\ModeloBase;
use App\Models\TipoOrganizacaoRH;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela mapeamentos_contabeis_rh (módulo RH). Legado: accounting_mapos · 225 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MapeamentoContabilRH.
 */
abstract class MapeamentoContabilRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'mapeamentos_contabeis_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'infotipo_salarial_id', 'tipo_organizacao_id', 'avencado', 'numero_conta',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'infotipo_salarial_id' => 'integer',
            'tipo_organizacao_id' => 'integer',
            'avencado' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function infotipoSalarial(): BelongsTo
    {
        return $this->belongsTo(InfotipoSalarial::class, 'infotipo_salarial_id');
    }

    public function tipoOrganizacao(): BelongsTo
    {
        return $this->belongsTo(TipoOrganizacaoRH::class, 'tipo_organizacao_id');
    }
}
