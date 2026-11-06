<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * fracoes — fracções de um imóvel (o que se arrenda ou vende), com vagas e arrecadações como naturezas acessórias.
 * O estado muda por ServicoFracoes::mudarEstado (deixa trilho em historico_estado_fracao).
 */
class Fracao extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const ESTADOS = ['DISPONIVEL', 'RESERVADA', 'ARRENDADA', 'VENDIDA', 'EM_OBRA', 'INDISPONIVEL'];

    protected $table = 'fracoes';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'imovel_id', 'codigo', 'designacao', 'natureza', 'park_afectacao', 'park_fracao_id', 'park_tipo', 'park_porte', 'park_altura_max',
        'park_comprimento', 'park_largura', 'park_tomada', 'park_distico', 'park_incluido_na_renda', 'porta_id', 'bloco', 'andar', 'numero', 'piso', 'tipologia',
        'finalidade', 'area_bruta', 'area_util', 'quartos', 'casas_banho', 'mobilada', 'estado', 'estado_desde', 'estado_motivo', 'matriz_predial',
        'valor_patrimonial', 'renda_referencia', 'venda_referencia', 'documentos', 'ordem', 'notas', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'imovel_id' => 'integer', 'park_fracao_id' => 'integer', 'porta_id' => 'integer', 'piso' => 'integer', 'quartos' => 'integer',
            'casas_banho' => 'integer', 'ordem' => 'integer', 'area_bruta' => 'float', 'area_util' => 'float', 'valor_patrimonial' => 'float', 'renda_referencia' => 'float',
            'venda_referencia' => 'float', 'park_altura_max' => 'float', 'park_comprimento' => 'float', 'park_largura' => 'float', 'park_tomada' => 'boolean',
            'park_incluido_na_renda' => 'boolean', 'estado_desde' => 'date:Y-m-d', 'documentos' => 'array'];
    }
}
