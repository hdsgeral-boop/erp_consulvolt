<?php

namespace App\Models\Base;

use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\PagamentoLavandaria;
use App\Models\ReclamacaoLavandaria;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela pedidos_lavandaria (módulo POS). Legado: lav_orders · 5 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\PedidoLavandaria.
 */
abstract class PedidoLavandariaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'pedidos_lavandaria';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'numero_encomenda', 'terminal_pos_id', 'codigo_terminal', 'cliente_id', 'recebido_em', 'recebido_por', 'sessao_rececao_id', 'modo_faturacao', 'urgente', 'data_prometida', 'observacoes', 'recolha', 'entrega', 'extras', 'historico_alteracoes', 'itens', 'estado', 'colaborador_atribuido_id', 'nome_atribuido', 'atribuido_em', 'atribuido_por', 'nota_atribuicao', 'atribuicoes', 'entregue_em', 'motivo_cancelamento', 'faturas_ids_legado',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'terminal_pos_id' => 'integer',
            'cliente_id' => 'integer',
            'recebido_em' => 'datetime',
            'sessao_rececao_id' => 'integer',
            'urgente' => 'boolean',
            'data_prometida' => 'datetime',
            'recolha' => 'array',
            'entrega' => 'array',
            'extras' => 'array',
            'historico_alteracoes' => 'array',
            'itens' => 'array',
            'colaborador_atribuido_id' => 'integer',
            'atribuido_em' => 'datetime',
            'atribuicoes' => 'array',
            'entregue_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function terminalPos(): BelongsTo
    {
        return $this->belongsTo(TerminalPOS::class, 'terminal_pos_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_id');
    }

    public function sessaoRececao(): BelongsTo
    {
        return $this->belongsTo(SessaoPOS::class, 'sessao_rececao_id');
    }

    public function colaboradorAtribuido(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_atribuido_id');
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class, 'pedido_lavandaria_id');
    }

    public function pagamentosLavandaria(): HasMany
    {
        return $this->hasMany(PagamentoLavandaria::class, 'pedido_lavandaria_id');
    }

    public function reclamacoesLavandaria(): HasMany
    {
        return $this->hasMany(ReclamacaoLavandaria::class, 'pedido_lavandaria_id');
    }
}
