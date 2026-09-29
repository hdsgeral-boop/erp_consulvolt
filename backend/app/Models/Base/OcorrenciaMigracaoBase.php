<?php

namespace App\Models\Base;

use App\Models\ModeloBase;

/**
 * Tabela ocorrencias_migracao (módulo Sistema). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\OcorrenciaMigracao.
 */
abstract class OcorrenciaMigracaoBase extends ModeloBase
{
    protected $table = 'ocorrencias_migracao';

    protected $fillable = [
        'execucao', 'tabela_legado', 'id_legado', 'tabela_destino', 'coluna', 'regra', 'gravidade', 'valor_original', 'valor_final', 'descricao', 'dados_originais', 'empresa_legado_id',
    ];

    protected function casts(): array
    {
        return [
            'dados_originais' => 'array',
            'empresa_legado_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
