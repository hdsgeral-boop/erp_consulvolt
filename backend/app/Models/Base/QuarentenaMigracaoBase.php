<?php

namespace App\Models\Base;

use App\Models\ModeloBase;

/**
 * Tabela quarentena_migracao (módulo Sistema). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\QuarentenaMigracao.
 */
abstract class QuarentenaMigracaoBase extends ModeloBase
{
    protected $table = 'quarentena_migracao';

    protected $fillable = [
        'execucao', 'tabela_legado', 'id_legado', 'motivo', 'dados_originais', 'empresa_legado_id', 'resolvido_em', 'resolvido_por', 'resolucao',
    ];

    protected function casts(): array
    {
        return [
            'dados_originais' => 'array',
            'empresa_legado_id' => 'integer',
            'resolvido_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
