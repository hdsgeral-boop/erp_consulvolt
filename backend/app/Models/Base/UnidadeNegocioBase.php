<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Colaborador;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\CotacaoCompra;
use App\Models\EncomendaCompra;
use App\Models\FaturaCompra;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\LancamentoEstornado;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use App\Models\OrcamentoAnual;
use App\Models\PedidoCompra;
use App\Models\PrevisaoOrcamental;
use App\Models\Projeto;
use App\Models\RececaoCompra;
use App\Models\ReciboVenda;
use App\Models\TerminalPOS;
use App\Models\UnidadeNegocio;
use App\Models\UnidadeOrganica;
use App\Models\Venda;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela unidades_negocio (módulo Sistema). Legado: business_units · 16 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\UnidadeNegocio.
 */
abstract class UnidadeNegocioBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'unidades_negocio';

    protected string $moduloAuditoria = 'Sistema';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'nome_abreviado', 'descricao', 'unidade_negocio_pai_id', 'ordem_sequencia', 'estado', 'valido_de', 'valido_ate', 'endereco', 'cidade', 'estado_fluxo', 'codigo_postal', 'pais', 'telefone', 'email', 'fax', 'website', 'codigo_moeda', 'bolsa_valores', 'simbolo_bolsa', 'colaborador_gestor_id', 'tem_vendas', 'tem_servico',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'unidade_negocio_pai_id' => 'integer',
            'ordem_sequencia' => 'integer',
            'valido_de' => 'date',
            'bolsa_valores' => 'decimal:2',
            'colaborador_gestor_id' => 'integer',
            'tem_vendas' => 'boolean',
            'tem_servico' => 'boolean',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function unidadeNegocioPai(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_pai_id');
    }

    public function colaboradorGestor(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_gestor_id');
    }

    public function unidadesNegocioPorUnidadeNegocioPai(): HasMany
    {
        return $this->hasMany(UnidadeNegocio::class, 'unidade_negocio_pai_id');
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'unidade_negocio_id');
    }

    public function lancamentosEstornados(): HasMany
    {
        return $this->hasMany(LancamentoEstornado::class, 'unidade_negocio_id');
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class, 'unidade_negocio_id');
    }

    public function recibosVenda(): HasMany
    {
        return $this->hasMany(ReciboVenda::class, 'unidade_negocio_id');
    }

    public function pedidosCompra(): HasMany
    {
        return $this->hasMany(PedidoCompra::class, 'unidade_negocio_id');
    }

    public function cotacoesCompra(): HasMany
    {
        return $this->hasMany(CotacaoCompra::class, 'unidade_negocio_id');
    }

    public function encomendasCompra(): HasMany
    {
        return $this->hasMany(EncomendaCompra::class, 'unidade_negocio_id');
    }

    public function rececoesCompra(): HasMany
    {
        return $this->hasMany(RececaoCompra::class, 'unidade_negocio_id');
    }

    public function faturasCompra(): HasMany
    {
        return $this->hasMany(FaturaCompra::class, 'unidade_negocio_id');
    }

    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class, 'unidade_negocio_id');
    }

    public function unidadesOrganicas(): HasMany
    {
        return $this->hasMany(UnidadeOrganica::class, 'unidade_negocio_id');
    }

    public function itensDocumentoTesouraria(): HasMany
    {
        return $this->hasMany(ItemDocumentoTesouraria::class, 'unidade_negocio_id');
    }

    public function movimentosCaixa(): HasMany
    {
        return $this->hasMany(MovimentoCaixa::class, 'unidade_negocio_id');
    }

    public function terminaisPos(): HasMany
    {
        return $this->hasMany(TerminalPOS::class, 'unidade_negocio_id');
    }

    public function ativosImobilizados(): HasMany
    {
        return $this->hasMany(AtivoImobilizado::class, 'unidade_negocio_id');
    }

    public function projetos(): HasMany
    {
        return $this->hasMany(Projeto::class, 'unidade_negocio_id');
    }

    public function orcamentosAnuais(): HasMany
    {
        return $this->hasMany(OrcamentoAnual::class, 'unidade_negocio_id');
    }

    public function previsoesOrcamentais(): HasMany
    {
        return $this->hasMany(PrevisaoOrcamental::class, 'unidade_negocio_id');
    }

    public function itensAcrescimosDiferimentos(): HasMany
    {
        return $this->hasMany(ItemAcrescimoDiferimento::class, 'unidade_negocio_id');
    }
}
