<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** cupons_desconto — catálogo global de cupões/campanhas promocionais da subscrição SaaS. Sem empresa_id/tenant_id (como planos_licenca). */
class CupomDesconto extends ModeloBase
{
    public const PERCENTAGEM = 'PERCENTAGEM';

    public const VALOR_FIXO = 'VALOR_FIXO';

    public const PERCENTAGEM_MAXIMA = 15.0;

    protected $table = 'cupons_desconto';

    protected $fillable = [
        'codigo', 'descricao', 'tipo_desconto', 'valor_desconto', 'moeda', 'data_inicio', 'data_fim',
        'limite_usos', 'usos_atuais', 'limite_por_cliente', 'planos_aplicaveis', 'ciclos_aplicaveis', 'ativo', 'criado_por',
    ];

    protected function casts(): array
    {
        return [
            'valor_desconto' => 'decimal:2', 'data_inicio' => 'date', 'data_fim' => 'date',
            'limite_usos' => 'integer', 'usos_atuais' => 'integer', 'limite_por_cliente' => 'integer',
            'planos_aplicaveis' => 'array', 'ciclos_aplicaveis' => 'array', 'ativo' => 'boolean',
        ];
    }

    public function usos(): HasMany
    {
        return $this->hasMany(UsoCupom::class, 'cupom_id');
    }

    /** O CHECK da BD exige maiúsculas; garantir aqui cobre também gravações directas (fora do controlador). */
    protected function setCodigoAttribute(string $valor): void
    {
        $this->attributes['codigo'] = mb_strtoupper(trim($valor));
    }
}
