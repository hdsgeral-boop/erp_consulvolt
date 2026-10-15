<?php

namespace App\Models\Base;

use App\Models\AbateVendaAtivo;
use App\Models\AfetacaoAtivoProjeto;
use App\Models\AmortizacaoAtivo;
use App\Models\AndarEdificio;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\CentroCusto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\InventarioFisicoAtivo;
use App\Models\LancamentoContabil;
use App\Models\LinhaInventarioFisicoAtivo;
use App\Models\ModeloBase;
use App\Models\OrdemManutencaoAtivo;
use App\Models\PlanoManutencaoAtivo;
use App\Models\PortaEdificio;
use App\Models\RegistoManutencaoAtivo;
use App\Models\RequisicaoManutencao;
use App\Models\Terceiro;
use App\Models\TransferenciaCentroCustoAtivo;
use App\Models\UnidadeNegocio;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela ativos_imobilizados (módulo Activos). Legado: fixed_assets · 176 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AtivoImobilizado.
 */
abstract class AtivoImobilizadoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'ativos_imobilizados';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'codigo', 'descricao', 'categoria_ativo_id', 'centro_custo_id', 'valor_aquisicao', 'vida_util', 'data_aquisicao', 'estado', 'estado_original', 'lancamento_contabil_id', 'fornecedor_id', 'valor_residual', 'amortizacao_acumulada', 'vida_util_restante', 'amortizacao_acumulada_inicial', 'quota_fixa', 'edificio_id', 'andar_id', 'porta_id', 'acumulado_fim_ano', 'unidade_negocio_id', 'tipo_ativo',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'categoria_ativo_id' => 'integer',
            'centro_custo_id' => 'integer',
            'valor_aquisicao' => 'decimal:2',
            'vida_util' => 'integer',
            'data_aquisicao' => 'date',
            'lancamento_contabil_id' => 'integer',
            'fornecedor_id' => 'integer',
            'valor_residual' => 'decimal:2',
            'amortizacao_acumulada' => 'decimal:2',
            'vida_util_restante' => 'integer',
            'amortizacao_acumulada_inicial' => 'decimal:2',
            'quota_fixa' => 'decimal:2',
            'edificio_id' => 'integer',
            'andar_id' => 'integer',
            'porta_id' => 'integer',
            'acumulado_fim_ano' => 'integer',
            'unidade_negocio_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function categoriaAtivo(): BelongsTo
    {
        return $this->belongsTo(CategoriaAtivo::class, 'categoria_ativo_id');
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class, 'centro_custo_id');
    }

    public function lancamentoContabil(): BelongsTo
    {
        return $this->belongsTo(LancamentoContabil::class, 'lancamento_contabil_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Terceiro::class, 'fornecedor_id');
    }

    public function edificio(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'edificio_id');
    }

    public function andar(): BelongsTo
    {
        return $this->belongsTo(AndarEdificio::class, 'andar_id');
    }

    public function porta(): BelongsTo
    {
        return $this->belongsTo(PortaEdificio::class, 'porta_id');
    }

    public function unidadeNegocio(): BelongsTo
    {
        return $this->belongsTo(UnidadeNegocio::class, 'unidade_negocio_id');
    }

    public function ativosImobilizadosPorEdificio(): HasMany
    {
        return $this->hasMany(AtivoImobilizado::class, 'edificio_id');
    }

    public function amortizacoesAtivos(): HasMany
    {
        return $this->hasMany(AmortizacaoAtivo::class, 'ativo_imobilizado_id');
    }

    public function abatesVendasAtivos(): HasMany
    {
        return $this->hasMany(AbateVendaAtivo::class, 'ativo_imobilizado_id');
    }

    public function planosManutencaoAtivos(): HasMany
    {
        return $this->hasMany(PlanoManutencaoAtivo::class, 'ativo_imobilizado_id');
    }

    public function registosManutencaoAtivos(): HasMany
    {
        return $this->hasMany(RegistoManutencaoAtivo::class, 'ativo_imobilizado_id');
    }

    public function transferenciasCentrosCustoAtivos(): HasMany
    {
        return $this->hasMany(TransferenciaCentroCustoAtivo::class, 'ativo_imobilizado_id');
    }

    public function afetacoesAtivosProjeto(): HasMany
    {
        return $this->hasMany(AfetacaoAtivoProjeto::class, 'ativo_imobilizado_id');
    }

    public function andaresEdificios(): HasMany
    {
        return $this->hasMany(AndarEdificio::class, 'edificio_id');
    }

    public function portasEdificios(): HasMany
    {
        return $this->hasMany(PortaEdificio::class, 'edificio_id');
    }

    public function inventariosFisicosAtivos(): HasMany
    {
        return $this->hasMany(InventarioFisicoAtivo::class, 'edificio_id');
    }

    public function linhasInventarioFisicoAtivos(): HasMany
    {
        return $this->hasMany(LinhaInventarioFisicoAtivo::class, 'ativo_imobilizado_id');
    }

    public function ordensManutencaoAtivos(): HasMany
    {
        return $this->hasMany(OrdemManutencaoAtivo::class, 'ativo_imobilizado_id');
    }

    public function requisicoesManutencao(): HasMany
    {
        return $this->hasMany(RequisicaoManutencao::class, 'ativo_imobilizado_id');
    }
}
