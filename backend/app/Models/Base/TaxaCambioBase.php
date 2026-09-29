<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\CotacaoCompra;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use App\Models\RececaoCompra;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela taxas_cambio (módulo Sistema). Legado: exchange_rates · 36 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\TaxaCambio.
 */
abstract class TaxaCambioBase extends ModeloBase
{
    use Auditavel;

    protected $table = 'taxas_cambio';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'codigo_moeda', 'data_taxa', 'taxa', 'fonte_dados', 'taxa_compra_bai', 'taxa_venda_bai', 'criado_por', 'atualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'data_taxa' => 'date',
            'taxa' => 'decimal:6',
            'taxa_compra_bai' => 'decimal:6',
            'taxa_venda_bai' => 'decimal:6',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'taxa_cambio_id');
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class, 'taxa_cambio_id');
    }

    public function cotacoesCompra(): HasMany
    {
        return $this->hasMany(CotacaoCompra::class, 'taxa_cambio_id');
    }

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'taxa_cambio_id');
    }

    public function rececoesCompra(): HasMany
    {
        return $this->hasMany(RececaoCompra::class, 'taxa_cambio_id');
    }

    public function faturasCompra(): HasMany
    {
        return $this->hasMany(FaturaCompra::class, 'taxa_cambio_id');
    }

    public function documentosTesouraria(): HasMany
    {
        return $this->hasMany(DocumentoTesouraria::class, 'taxa_cambio_id');
    }

    public function itensDocumentoTesouraria(): HasMany
    {
        return $this->hasMany(ItemDocumentoTesouraria::class, 'taxa_cambio_id');
    }

    public function movimentosCaixa(): HasMany
    {
        return $this->hasMany(MovimentoCaixa::class, 'taxa_cambio_id');
    }
}
