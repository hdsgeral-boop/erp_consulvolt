<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela series_faturacao_eletronica (módulo Vendas). Legado: fe_series · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\SerieFaturacaoEletronica.
 */
abstract class SerieFaturacaoEletronicaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'series_faturacao_eletronica';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'codigo', 'tipo', 'ano', 'origem', 'origem_nome', 'estabelecimento', 'contingencia', 'estado', 'agt_codigo', 'proximo_numero', 'ultima_data', 'criado_por', 'agt_primeiro_numero', 'agt_ultimo_numero', 'agt_quantidade', 'agt_pedido_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'contingencia' => 'boolean',
            'proximo_numero' => 'integer',
            'agt_quantidade' => 'decimal:3',
            'agt_pedido_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class, 'serie_faturacao_eletronica_id');
    }
}
