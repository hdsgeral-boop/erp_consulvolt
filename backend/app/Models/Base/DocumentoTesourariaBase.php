<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LiquidacaoPOS;
use App\Models\ModeloBase;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\Projeto;
use App\Models\ReclamacaoLavandaria;
use App\Models\TaxaCambio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela documentos_tesouraria (módulo Tesouraria). Legado: treasury_documents · 5781 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\DocumentoTesouraria.
 */
abstract class DocumentoTesourariaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'documentos_tesouraria';

    protected string $moduloAuditoria = 'Tesouraria';

    protected $fillable = [
        'empresa_id', 'tipo', 'data_documento', 'conta_financeira', 'descricao', 'valor_total', 'estado', 'referencia', 'projeto_id', 'codigo_projeto', 'importado', 'url_documento', 'codigo_moeda', 'taxa_cambio', 'taxa_cambio_id', 'taxa_cambio_manual', 'valor_total_moeda', 'periodo_processamento_salarial_id', 'reconciliacao_codigo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data_documento' => 'date',
            'valor_total' => 'decimal:2',
            'projeto_id' => 'integer',
            'importado' => 'boolean',
            'taxa_cambio' => 'decimal:6',
            'taxa_cambio_id' => 'integer',
            'taxa_cambio_manual' => 'boolean',
            'valor_total_moeda' => 'decimal:2',
            'periodo_processamento_salarial_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function projeto(): BelongsTo
    {
        return $this->belongsTo(Projeto::class, 'projeto_id');
    }

    public function taxaCambioRelacao(): BelongsTo
    {
        return $this->belongsTo(TaxaCambio::class, 'taxa_cambio_id');
    }

    public function periodoProcessamentoSalarial(): BelongsTo
    {
        return $this->belongsTo(PeriodoProcessamentoSalarial::class, 'periodo_processamento_salarial_id');
    }

    public function itensDocumentoTesouraria(): HasMany
    {
        return $this->hasMany(ItemDocumentoTesouraria::class, 'documento_tesouraria_id');
    }

    public function liquidacoesPos(): HasMany
    {
        return $this->hasMany(LiquidacaoPOS::class, 'documento_tesouraria_id');
    }

    public function reclamacoesLavandaria(): HasMany
    {
        return $this->hasMany(ReclamacaoLavandaria::class, 'documento_tesouraria_id');
    }
}
