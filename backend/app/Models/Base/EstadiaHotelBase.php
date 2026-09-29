<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela estadias_hotel (módulo POS). Legado: hotel_stays · 8 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\EstadiaHotel.
 */
abstract class EstadiaHotelBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'estadias_hotel';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'terminal_pos_id', 'codigo_terminal', 'sessao_pos_id', 'produto_quarto_id', 'nome_quarto', 'estado', 'cliente_hospede_id', 'nome_hospede', 'numero_hospedes', 'modo', 'entrada_em', 'saida_prevista_em', 'quantidade', 'preco_unitario', 'taxa_imposto', 'observacoes', 'itens', 'historico_alteracoes', 'criado_por', 'atualizado_por', 'saida_em', 'quantidade_final', 'opcao_atraso', 'percentagem_desconto', 'venda_id', 'numero_venda', 'sessao_fecho_id', 'fechado_em', 'fechado_por', 'motivo_cancelamento',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'terminal_pos_id' => 'integer',
            'sessao_pos_id' => 'integer',
            'produto_quarto_id' => 'integer',
            'cliente_hospede_id' => 'integer',
            'numero_hospedes' => 'integer',
            'entrada_em' => 'datetime',
            'saida_prevista_em' => 'datetime',
            'quantidade' => 'decimal:3',
            'preco_unitario' => 'decimal:2',
            'taxa_imposto' => 'decimal:4',
            'itens' => 'array',
            'historico_alteracoes' => 'array',
            'saida_em' => 'datetime',
            'quantidade_final' => 'decimal:3',
            'percentagem_desconto' => 'decimal:4',
            'venda_id' => 'integer',
            'sessao_fecho_id' => 'integer',
            'fechado_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function terminalPos(): BelongsTo
    {
        return $this->belongsTo(TerminalPOS::class, 'terminal_pos_id');
    }

    public function sessaoPos(): BelongsTo
    {
        return $this->belongsTo(SessaoPOS::class, 'sessao_pos_id');
    }

    public function produtoQuarto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_quarto_id');
    }

    public function clienteHospede(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_hospede_id');
    }

    public function venda(): BelongsTo
    {
        return $this->belongsTo(Venda::class, 'venda_id');
    }

    public function sessaoFecho(): BelongsTo
    {
        return $this->belongsTo(SessaoPOS::class, 'sessao_fecho_id');
    }
}
