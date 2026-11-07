<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/** espacos_comuns — área comum de um imóvel (salão, ginásio, piscina…) com o seu regulamento. Não é fracção: não se arrenda nem conta para a ocupação. */
class EspacoComum extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'espacos_comuns';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'imovel_id', 'tipo', 'nome', 'localizacao', 'regime', 'capacidade', 'hora_abre', 'hora_fecha', 'dias_semana', 'duracao_min',
        'duracao_max', 'antecedencia_min_dias', 'antecedencia_max_dias', 'limite_mes_fraccao', 'exige_aprovacao', 'preco', 'preco_unidade', 'caucao',
        'prazo_pagamento_horas', 'cancel_dias', 'cancel_pct', 'regulamento', 'regulamento_url', 'activo', 'notas', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'imovel_id' => 'integer', 'capacidade' => 'integer', 'dias_semana' => 'array', 'duracao_min' => 'float', 'duracao_max' => 'float',
            'antecedencia_min_dias' => 'integer', 'antecedencia_max_dias' => 'integer', 'limite_mes_fraccao' => 'integer', 'exige_aprovacao' => 'boolean', 'preco' => 'float',
            'caucao' => 'float', 'prazo_pagamento_horas' => 'integer', 'cancel_dias' => 'integer', 'cancel_pct' => 'integer', 'activo' => 'boolean'];
    }
}
