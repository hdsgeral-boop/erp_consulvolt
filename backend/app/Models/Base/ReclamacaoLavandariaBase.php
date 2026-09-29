<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DocumentoTesouraria;
use App\Models\ModeloBase;
use App\Models\PedidoLavandaria;
use App\Models\Terceiro;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela reclamacoes_lavandaria (módulo POS). Legado: lav_claims · 0 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ReclamacaoLavandaria.
 */
abstract class ReclamacaoLavandariaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'reclamacoes_lavandaria';

    protected string $moduloAuditoria = 'POS';

    protected $fillable = [
        'empresa_id', 'pedido_lavandaria_id', 'numero_encomenda', 'linha_pedido_id', 'nome_item', 'descricao_peca', 'estado_lancamento', 'cliente_id', 'descricao', 'valor_declarado', 'estado', 'criado_por', 'referencia_comprovativo', 'data_comprovativo', 'valor_comprovativo', 'nota_decisao', 'decidido_em', 'decidido_por', 'valor_compensacao', 'documento_tesouraria_id', 'pago_em', 'pago_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'pedido_lavandaria_id' => 'integer',
            'numero_encomenda' => 'integer',
            'estado_lancamento' => 'date',
            'cliente_id' => 'integer',
            'valor_declarado' => 'decimal:2',
            'data_comprovativo' => 'date',
            'valor_comprovativo' => 'decimal:2',
            'decidido_em' => 'datetime',
            'valor_compensacao' => 'decimal:2',
            'documento_tesouraria_id' => 'integer',
            'pago_em' => 'datetime',
            'pago_por' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function pedidoLavandaria(): BelongsTo
    {
        return $this->belongsTo(PedidoLavandaria::class, 'pedido_lavandaria_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'cliente_id');
    }

    public function documentoTesouraria(): BelongsTo
    {
        return $this->belongsTo(DocumentoTesouraria::class, 'documento_tesouraria_id');
    }
}
