<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DiarioContabil;
use App\Models\EstadiaHotel;
use App\Models\LancamentoContabil;
use App\Models\LiquidacaoPOS;
use App\Models\ModeloBase;
use App\Models\PagamentoLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\TerminalPOS;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela sessoes_pos (módulo POS). Legado: pos_sessions · 4 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\SessaoPOS.
 */
abstract class SessaoPOSBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'sessoes_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'terminal_pos_id', 'codigo_terminal', 'nome_terminal', 'codigo_sessao', 'estado', 'aberto_em', 'fundo_maneio_abertura', 'operador_id', 'nome_operador', 'estado_contabilizacao', 'estado_liquidacao', 'estado_desvio', 'estado_desvio_original', 'fechado_em', 'fechado_por', 'numero_z', 'numero_vendas', 'total_vendas', 'totais_por_metodo', 'transferencias', 'vendas_numerario', 'numerario_esperado', 'numerario_contado', 'contagens_numerario', 'desvio', 'fechos_tpa', 'justificacao', 'lans_contabilizacao', 'diario_contabilizacao_id', 'contabilizado_em', 'contabilizado_por', 'deliberacao', 'descontabilizado_em', 'descontabilizado_por', 'deliberacao_cancelada',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'terminal_pos_id' => 'integer',
            'aberto_em' => 'datetime',
            'fundo_maneio_abertura' => 'decimal:2',
            'operador_id' => 'integer',
            'fechado_em' => 'datetime',
            'numero_vendas' => 'integer',
            'total_vendas' => 'decimal:2',
            'totais_por_metodo' => 'array',
            'transferencias' => 'array',
            'vendas_numerario' => 'decimal:2',
            'numerario_esperado' => 'decimal:2',
            'numerario_contado' => 'decimal:2',
            'contagens_numerario' => 'array',
            'desvio' => 'decimal:2',
            'fechos_tpa' => 'array',
            'lans_contabilizacao' => 'array',
            'diario_contabilizacao_id' => 'integer',
            'contabilizado_em' => 'datetime',
            'deliberacao' => 'array',
            'descontabilizado_em' => 'datetime',
            'deliberacao_cancelada' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function terminalPos(): BelongsTo
    {
        return $this->belongsTo(TerminalPOS::class, 'terminal_pos_id');
    }

    public function diarioContabilizacao(): BelongsTo
    {
        return $this->belongsTo(DiarioContabil::class, 'diario_contabilizacao_id');
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'sessao_pos_id');
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class, 'sessao_pos_id');
    }

    public function liquidacoesPos(): HasMany
    {
        return $this->hasMany(LiquidacaoPOS::class, 'sessao_pos_id');
    }

    public function estadiasHotelPorSessaoPos(): HasMany
    {
        return $this->hasMany(EstadiaHotel::class, 'sessao_pos_id');
    }

    public function estadiasHotelPorSessaoFecho(): HasMany
    {
        return $this->hasMany(EstadiaHotel::class, 'sessao_fecho_id');
    }

    public function pedidosLavandaria(): HasMany
    {
        return $this->hasMany(PedidoLavandaria::class, 'sessao_rececao_id');
    }

    public function pagamentosLavandaria(): HasMany
    {
        return $this->hasMany(PagamentoLavandaria::class, 'sessao_pos_id');
    }
}
