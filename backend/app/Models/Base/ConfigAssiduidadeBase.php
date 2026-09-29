<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_assiduidade (módulo RH). Legado: rh_attendance_config · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfigAssiduidade.
 */
abstract class ConfigAssiduidadeBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_assiduidade';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'dias_uteis', 'tolerancia_min', 'arredondamento_min', 'extras_min_minutos', 'minimo_dia_horas', 'feriados', 'relogio', 'modo_compensacao', 'limite_compensacao_h', 'extra_nao_util_exige_autorizacao', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'tolerancia_min' => 'integer',
            'arredondamento_min' => 'array',
            'extras_min_minutos' => 'integer',
            'minimo_dia_horas' => 'decimal:3',
            'relogio' => 'array',
            'modo_compensacao' => 'array',
            'limite_compensacao_h' => 'decimal:2',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
