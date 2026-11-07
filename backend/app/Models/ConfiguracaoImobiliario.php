<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * configuracao_imobiliario — uma linha por empresa: o artigo de serviço «Renda» da facturação e as contas da caução e do
 * imposto predial retido. Nada vem pré-preenchido: o ERP nunca inventa contas (cada empresa indica as do seu plano).
 */
class ConfiguracaoImobiliario extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const CAMPOS_CONTA = ['conta_caucao_passivo', 'conta_caucao_activo', 'conta_caucao_receita', 'conta_ip_credito'];

    protected $table = 'configuracao_imobiliario';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'produto_renda_id', 'produto_renda_alojamento_id', 'conta_caucao_passivo', 'conta_caucao_activo', 'conta_caucao_receita',
        'conta_ip_credito', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'produto_renda_id' => 'integer', 'produto_renda_alojamento_id' => 'integer'];
    }
}
