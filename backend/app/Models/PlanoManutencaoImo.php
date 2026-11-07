<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** planos_manutencao_imo — calendário de manutenção preventiva ou vistoria de um imóvel ou fracção; gera as ordens, uma por data. */
class PlanoManutencaoImo extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'planos_manutencao_imo';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'nome', 'tipo', 'descricao', 'imovel_id', 'fracao_id', 'periodicidade', 'primeira_data', 'quantas', 'datas', 'prioridade', 'quem_paga', 'debitavel',
        'responsavel', 'antecedencia_dias', 'custo_estimado', 'notas', 'activo', 'proxima', 'ultima_geracao', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'imovel_id' => 'integer', 'fracao_id' => 'integer', 'quantas' => 'integer', 'datas' => 'array', 'debitavel' => 'boolean', 'activo' => 'boolean',
            'antecedencia_dias' => 'integer', 'custo_estimado' => 'float', 'primeira_data' => 'date:Y-m-d', 'proxima' => 'date:Y-m-d', 'ultima_geracao' => 'date:Y-m-d'];
    }
}
