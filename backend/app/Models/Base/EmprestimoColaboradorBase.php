<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PedidoPortalColaborador;
use App\Models\PrestacaoEmprestimo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela emprestimos_colaboradores (módulo RH). Legado: rh_loans · 2 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\EmprestimoColaborador.
 */
abstract class EmprestimoColaboradorBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'emprestimos_colaboradores';

    protected string $moduloAuditoria = 'RH';

    protected $fillable = [
        'empresa_id', 'colaborador_id', 'numero', 'capital', 'prestacoes', 'taxa_juro', 'valor_prestacao', 'inicio_desconto', 'motivo', 'estado', 'pedido_portal_colaborador_id', 'criado_por', 'total_juros', 'total_a_pagar', 'aprovado_em', 'aprovado_por', 'lancamento_referencia', 'lancado_em', 'lancado_por', 'motivo_cancelamento', 'cancelado_em', 'cancelado_por', 'decidido_em', 'liquidado_em',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'colaborador_id' => 'integer',
            'capital' => 'decimal:2',
            'prestacoes' => 'integer',
            'taxa_juro' => 'decimal:4',
            'valor_prestacao' => 'decimal:2',
            'pedido_portal_colaborador_id' => 'integer',
            'total_juros' => 'decimal:2',
            'total_a_pagar' => 'decimal:2',
            'aprovado_em' => 'datetime',
            'lancado_em' => 'datetime',
            'cancelado_em' => 'datetime',
            'decidido_em' => 'datetime',
            'liquidado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function pedidoPortalColaborador(): BelongsTo
    {
        return $this->belongsTo(PedidoPortalColaborador::class, 'pedido_portal_colaborador_id');
    }

    public function pedidosPortalColaborador(): HasMany
    {
        return $this->hasMany(PedidoPortalColaborador::class, 'emprestimo_id');
    }

    public function prestacoesEmprestimos(): HasMany
    {
        return $this->hasMany(PrestacaoEmprestimo::class, 'emprestimo_id');
    }
}
