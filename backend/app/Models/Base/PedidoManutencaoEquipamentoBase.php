<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Empresa;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela pedidos_manutencao_equipamentos (módulo Activos). Legado: maintenance_requests · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PedidoManutencaoEquipamento.
 */
abstract class PedidoManutencaoEquipamentoBase extends ModeloBase
{
    use Auditavel;

    protected $table = 'pedidos_manutencao_equipamentos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'estado', 'historico_alteracoes', 'ambito', 'nome_empresa_ambito', 'acao', 'rotulo_acao', 'parametros', 'resumo_parametros', 'justificacao', 'impacto_no_pedido', 'pedido_por', 'aprovado_por', 'aprovado_em', 'nota_aprovacao', 'expira_em', 'impacto_na_aprovacao', 'rejeitado_por', 'rejeitado_em', 'motivo_rejeicao', 'executado_por', 'executado_em', 'impacto_na_execucao', 'resultado', 'cancelado_por', 'cancelado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'historico_alteracoes' => 'array',
            'aprovado_por' => 'array',
            'aprovado_em' => 'datetime',
            'expira_em' => 'datetime',
            'rejeitado_por' => 'array',
            'rejeitado_em' => 'datetime',
            'executado_em' => 'datetime',
            'cancelado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
