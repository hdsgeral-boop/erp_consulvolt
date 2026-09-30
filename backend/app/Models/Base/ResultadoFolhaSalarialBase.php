<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela resultados_folha_salarial (módulo RH). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ResultadoFolhaSalarial.
 */
abstract class ResultadoFolhaSalarialBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'resultados_folha_salarial';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'periodo_processamento_salarial_id', 'colaborador_id', 'tipo_organizacao_id', 'unidade_negocio_id', 'centro_custo_id', 'avencado', 'reformado', 'dias_contrato', 'dias_trabalhados', 'bruto', 'base_inss', 'inss_trabalhador', 'inss_patronal', 'isencoes', 'base_irt', 'irt', 'descontos', 'liquido', 'rubricas', 'avisos', 'modo_calculo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'periodo_processamento_salarial_id' => 'integer',
            'colaborador_id' => 'integer',
            'tipo_organizacao_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'avencado' => 'boolean',
            'reformado' => 'boolean',
            'dias_contrato' => 'decimal:2',
            'dias_trabalhados' => 'decimal:2',
            'bruto' => 'decimal:2',
            'base_inss' => 'decimal:2',
            'inss_trabalhador' => 'decimal:2',
            'inss_patronal' => 'decimal:2',
            'isencoes' => 'decimal:2',
            'base_irt' => 'decimal:2',
            'irt' => 'decimal:2',
            'descontos' => 'decimal:2',
            'liquido' => 'decimal:2',
            'rubricas' => 'array',
            'avisos' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
