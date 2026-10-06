<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\ModeloBase;

/**
 * Tabela execucoes_cambios_bai (módulo Sistema). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ExecucaoCambioBAI.
 */
abstract class ExecucaoCambioBAIBase extends ModeloBase
{
    use Auditavel;

    protected $table = 'execucoes_cambios_bai';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'origem', 'estado', 'codigo_erro', 'mensagem', 'moedas', 'utilizador_id', 'iniciado_em', 'concluido_em',
    ];

    protected function casts(): array
    {
        return [
            'moedas' => 'integer',
            'utilizador_id' => 'integer',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
