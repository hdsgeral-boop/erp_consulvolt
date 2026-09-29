<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_pos (módulo POS). Legado: pos_settings · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfiguracaoPOS.
 */
abstract class ConfiguracaoPOSBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'conta_sobra', 'conta_quebra', 'conta_operador', 'tolerancia_desvio', 'codigo_diario', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'tolerancia_desvio' => 'integer',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
