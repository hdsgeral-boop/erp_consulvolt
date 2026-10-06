<?php

namespace App\Models\Base;

use App\Models\Armazem;
use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ContaMesaPOS;
use App\Models\EstadiaHotel;
use App\Models\MesaPOS;
use App\Models\ModeloBase;
use App\Models\PagamentoLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela terminais_pos (módulo POS). Legado: pos_terminals · 8 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\TerminalPOS.
 */
abstract class TerminalPOSBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'terminais_pos';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'tipo', 'tipo_original', 'unidade_negocio_id', 'centro_custo_id', 'armazem_id', 'cliente_padrao_id', 'fundo_maneio_padrao', 'meios_pagamento', 'contadores', 'contadores_sessao', 'contadores_z', 'ativo', 'id_legado', 'criado_por', 'atualizado_por', 'lavandaria_contadores_os', 'lavandaria_contadores_rc', 'lavandaria_contadores_ft', 'hotel_hora_entrada', 'hotel_hora_saida', 'hotel_tolerancia_atraso_min', 'hotel_bloco_horas', 'hotel_bloco_horas_de', 'hotel_bloco_horas_ate',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'armazem_id' => 'integer',
            'cliente_padrao_id' => 'integer',
            'fundo_maneio_padrao' => 'decimal:2',
            'meios_pagamento' => 'array',
            'contadores' => 'array',
            'contadores_sessao' => 'array',
            'contadores_z' => 'array',
            'ativo' => 'boolean',
            'lavandaria_contadores_os' => 'array',
            'lavandaria_contadores_rc' => 'array',
            'lavandaria_contadores_ft' => 'array',
            'hotel_tolerancia_atraso_min' => 'integer',
            'hotel_bloco_horas' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function armazem(): BelongsTo
    {
        return $this->belongsTo(Armazem::class, 'armazem_id');
    }

    public function clientePadrao(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_padrao_id');
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class, 'terminal_pos_id');
    }

    public function sessoesPos(): HasMany
    {
        return $this->hasMany(SessaoPOS::class, 'terminal_pos_id');
    }

    public function estadiasHotel(): HasMany
    {
        return $this->hasMany(EstadiaHotel::class, 'terminal_pos_id');
    }

    public function pedidosLavandaria(): HasMany
    {
        return $this->hasMany(PedidoLavandaria::class, 'terminal_pos_id');
    }

    public function pagamentosLavandaria(): HasMany
    {
        return $this->hasMany(PagamentoLavandaria::class, 'terminal_pos_id');
    }

    public function mesasPos(): HasMany
    {
        return $this->hasMany(MesaPOS::class, 'terminal_pos_id');
    }

    public function contasMesaPos(): HasMany
    {
        return $this->hasMany(ContaMesaPOS::class, 'terminal_pos_id');
    }
}
