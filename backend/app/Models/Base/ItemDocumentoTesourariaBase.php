<?php

namespace App\Models\Base;

use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\DocumentoTesouraria;
use App\Models\ModeloBase;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\Projeto;
use App\Models\TaxaCambio;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela itens_documento_tesouraria (módulo Tesouraria). Legado: treasury_items · 6252 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\ItemDocumentoTesouraria.
 */
abstract class ItemDocumentoTesourariaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'itens_documento_tesouraria';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'codigo_conta', 'terceiro_id', 'numero_documento', 'nota_demonstracao_id', 'nota_fluxo_caixa_id', 'descricao', 'valor', 'tipo_dc', 'documento_tesouraria_id', 'projeto_id', 'codigo_projeto', 'data_documento_original', 'nif_importado', 'unidade_negocio_id', 'centro_custo_id', 'codigo_moeda', 'valor_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'cambial_moeda_documento', 'cambial_saldo_moeda', 'cambial_saldo_kz', 'valor_introduzido',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'terceiro_id' => 'integer',
            'nota_demonstracao_id' => 'integer',
            'nota_fluxo_caixa_id' => 'integer',
            'valor' => 'decimal:2',
            'documento_tesouraria_id' => 'integer',
            'projeto_id' => 'integer',
            'unidade_negocio_id' => 'integer',
            'centro_custo_id' => 'integer',
            'valor_moeda' => 'decimal:2',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'taxa_cambio_manual' => 'boolean',
            'cambial_saldo_moeda' => 'decimal:2',
            'cambial_saldo_kz' => 'decimal:2',
            'valor_introduzido' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function terceiro(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'terceiro_id');
    }

    public function notaDemonstracao(): BelongsTo
    {
        return $this->belongsTo(NotaDemonstracao::class, 'nota_demonstracao_id');
    }

    public function notaFluxoCaixa(): BelongsTo
    {
        return $this->belongsTo(NotaFluxoCaixa::class, 'nota_fluxo_caixa_id');
    }

    public function documentoTesouraria(): BelongsTo
    {
        return $this->belongsTo(DocumentoTesouraria::class, 'documento_tesouraria_id');
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

    public function taxaCambioRelacao(): BelongsTo
    {
        return $this->belongsTo(TaxaCambio::class, 'taxa_cambio_id');
    }
}
