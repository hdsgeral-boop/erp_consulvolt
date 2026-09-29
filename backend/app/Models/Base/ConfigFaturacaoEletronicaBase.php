<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;

/**
 * Tabela configuracoes_faturacao_eletronica (módulo Vendas). Legado: fe_config · 1 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ConfigFaturacaoEletronica.
 */
abstract class ConfigFaturacaoEletronicaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'configuracoes_faturacao_eletronica';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'ativo', 'data_inicio', 'estabelecimentos', 'pais_padrao', 'isencao_padrao', 'software', 'atualizado_por', 'servico',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo' => 'boolean',
            'data_inicio' => 'date',
            'estabelecimentos' => 'array',
            'software' => 'array',
            'servico' => 'array',
            'atualizado_em' => 'datetime',
            'criado_em' => 'datetime',
        ];
    }
}
