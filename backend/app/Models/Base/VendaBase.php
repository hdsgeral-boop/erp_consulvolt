<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EncomendaCompra;
use App\Models\EstadiaHotel;
use App\Models\GuiaSaida;
use App\Models\ItemDocumentoTesouraria;
use App\Models\ItemReciboVenda;
use App\Models\ItemVenda;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use App\Models\OportunidadeVendaCRM;
use App\Models\PagamentoLavandaria;
use App\Models\PedidoCompra;
use App\Models\PedidoLavandaria;
use App\Models\Projeto;
use App\Models\ReciboVenda;
use App\Models\SerieFaturacaoEletronica;
use App\Models\SessaoPOS;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela vendas (módulo Vendas). Legado: sales · 102 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\Venda.
 */
abstract class VendaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'vendas';

    protected string $moduloAuditoria = 'Vendas';

    protected $fillable = [
        'empresa_id', 'cliente_id', 'tipo_documento', 'tipo_documento_original', 'numero_documento', 'data_emissao', 'total_liquido', 'total_imposto', 'total_bruto', 'data_entrega', 'valor_pago', 'valor_pendente', 'contabilizado', 'estado', 'estado_original', 'projeto_id', 'codigo_projeto', 'local_entrega', 'observacoes', 'contas_pagamento', 'condicoes_pagamento', 'ocultar_meios_pagamento', 'unidade_negocio_id', 'centro_custo_id', 'desconto', 'sessao_pos_id', 'meio_pagamento', 'meio_pagamento_original', 'nome_tabela', 'terceiro_id', 'data_vencimento', 'subtotal', 'total_desconto', 'montante_total', 'linhas', 'codigo_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'total_liquido_moeda', 'total_imposto_moeda', 'total_bruto_moeda', 'terminal_pos_id', 'codigo_terminal_pos', 'pos_pagamentos', 'pos_troco', 'pos_operador', 'pos_lans_contabilizacao', 'pedido_lavandaria_id', 'numero_pedido_lavandaria', 'montante_pago', 'valido_ate', 'dias_validade', 'modo_pagamento', 'modo_pagamento_original', 'plano_pagamentos', 'oportunidade_crm_id', 'fe_documento', 'fe_erros', 'fe_avisos', 'fe_estado', 'fe_validado_em', 'fe_selado_em', 'fe_envio', 'fe_regime', 'fe_tipo', 'serie_faturacao_eletronica_id', 'fe_serie', 'fe_numero', 'fe_estabelecimento', 'fe_data_entrada_sistema', 'motivo_nota_credito', 'sessao_pos_legado_codigo', 'numero_lan_contabilizacao', 'saft_hash', 'saft_hash_controlo', 'estadias_hotel_ids_legado', 'documentos_relacionados_legado',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'cliente_id' => 'integer',
            'data_emissao' => 'datetime',
            'total_liquido' => 'decimal:2',
            'total_imposto' => 'decimal:2',
            'total_bruto' => 'decimal:2',
            'data_entrega' => 'date',
            'valor_pago' => 'decimal:2',
            'valor_pendente' => 'decimal:2',
            'contabilizado' => 'boolean',
            'projeto_id' => 'integer',
            'contas_pagamento' => 'array',
            'ocultar_meios_pagamento' => 'boolean',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'desconto' => 'decimal:2',
            'sessao_pos_id' => 'integer',
            'terceiro_id' => 'integer',
            'data_vencimento' => 'date',
            'subtotal' => 'decimal:2',
            'total_desconto' => 'decimal:2',
            'montante_total' => 'decimal:2',
            'linhas' => 'array',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'taxa_cambio_manual' => 'boolean',
            'total_liquido_moeda' => 'decimal:2',
            'total_imposto_moeda' => 'decimal:2',
            'total_bruto_moeda' => 'decimal:2',
            'terminal_pos_id' => 'integer',
            'pos_pagamentos' => 'array',
            'pos_troco' => 'decimal:2',
            'pos_lans_contabilizacao' => 'array',
            'pedido_lavandaria_id' => 'integer',
            'montante_pago' => 'decimal:2',
            'valido_ate' => 'date',
            'dias_validade' => 'integer',
            'plano_pagamentos' => 'array',
            'oportunidade_crm_id' => 'integer',
            'fe_documento' => 'array',
            'fe_erros' => 'array',
            'fe_avisos' => 'array',
            'fe_validado_em' => 'datetime',
            'fe_selado_em' => 'datetime',
            'fe_envio' => 'array',
            'fe_regime' => 'boolean',
            'serie_faturacao_eletronica_id' => 'integer',
            'fe_numero' => 'integer',
            'fe_data_entrada_sistema' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_id');
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function sessaoPos(): BelongsTo
    {
        return $this->belongsTo(SessaoPOS::class, 'sessao_pos_id');
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function taxaCambioRelacao(): BelongsTo
    {
        return $this->belongsTo(TaxaCambio::class, 'taxa_cambio_id');
    }

    public function terminalPos(): BelongsTo
    {
        return $this->belongsTo(TerminalPOS::class, 'terminal_pos_id');
    }

    public function pedidoLavandaria(): BelongsTo
    {
        return $this->belongsTo(PedidoLavandaria::class, 'pedido_lavandaria_id');
    }

    public function oportunidadeCrm(): BelongsTo
    {
        return $this->belongsTo(OportunidadeVendaCRM::class, 'oportunidade_crm_id');
    }

    public function serieFaturacaoEletronica(): BelongsTo
    {
        return $this->belongsTo(SerieFaturacaoEletronica::class, 'serie_faturacao_eletronica_id');
    }

    public function itensVenda(): HasMany
    {
        return $this->hasMany(ItemVenda::class, 'venda_id');
    }

    public function recibosVenda(): HasMany
    {
        return $this->hasMany(ReciboVenda::class, 'venda_origem_id');
    }

    public function itensReciboVenda(): HasMany
    {
        return $this->hasMany(ItemReciboVenda::class, 'venda_id');
    }

    public function pedidosCompra(): HasMany
    {
        return $this->hasMany(PedidoCompra::class, 'venda_origem_id');
    }

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'venda_origem_id');
    }

    public function guiasSaida(): HasMany
    {
        return $this->hasMany(GuiaSaida::class, 'venda_relacionada_id');
    }

    public function itensDocumentoTesouraria(): HasMany
    {
        return $this->hasMany(ItemDocumentoTesouraria::class, 'venda_id');
    }

    public function movimentosCaixa(): HasMany
    {
        return $this->hasMany(MovimentoCaixa::class, 'venda_id');
    }

    public function estadiasHotel(): HasMany
    {
        return $this->hasMany(EstadiaHotel::class, 'venda_id');
    }

    public function pagamentosLavandaria(): HasMany
    {
        return $this->hasMany(PagamentoLavandaria::class, 'venda_id');
    }

    public function projetos(): HasMany
    {
        return $this->hasMany(Projeto::class, 'encomenda_venda_id');
    }
}
