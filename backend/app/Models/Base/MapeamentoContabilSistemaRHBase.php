<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\TipoOrganizacaoRH;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela mapeamentos_contabeis_sistema_rh (módulo RH). Legado: system_accounting_mapos · 112 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\MapeamentoContabilSistemaRH.
 */
abstract class MapeamentoContabilSistemaRHBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'mapeamentos_contabeis_sistema_rh';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'codigo', 'tipo_organizacao_id', 'avencado', 'numero_conta',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'tipo_organizacao_id' => 'integer',
            'avencado' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function tipoOrganizacao(): BelongsTo
    {
        return $this->belongsTo(TipoOrganizacaoRH::class, 'tipo_organizacao_id');
    }
}
