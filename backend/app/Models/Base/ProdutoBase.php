<?php

namespace App\Models\Base;

use App\Models\CatalogoFornecedor;
use App\Models\CategoriaProduto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\EstadiaHotel;
use App\Models\ItemCompra;
use App\Models\ItemGuiaSaida;
use App\Models\ItemVenda;
use App\Models\LinhaSessaoInventario;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use App\Models\MovimentoInventario;
use App\Models\StockArmazem;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela produtos (módulo Logística). Legado: products · 100 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\Produto.
 */
abstract class ProdutoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'produtos';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'codigo', 'nome', 'preco_unitario', 'taxa_imposto', 'quantidade_stock', 'movimenta_stock', 'codigo_conta', 'conta_compra', 'conta_inventario', 'conta_custo', 'categoria_produto_id', 'conta_iva', 'conta_iva_liquidado', 'conta_iva_dedutivel', 'conta_quebra', 'conta_sobra', 'imagem_base64', 'e_quarto', 'e_ativo_imobilizado', 'conta_ativo', 'preco_por_hora', 'preco_por_dia', 'horas_minimas', 'e_servico', 'bloqueado', 'lavandaria_grupo', 'lavandaria_unidade', 'lavandaria_dias_entrega', 'lavandaria_requer_orcamento', 'lavandaria_ativa', 'lavandaria_preco_peca', 'lavandaria_preco_kg', 'unidade_fe', 'tipo_operacao_fe', 'codigo_isencao_fe', 'custo_medio',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'preco_unitario' => 'decimal:2',
            'taxa_imposto' => 'decimal:4',
            'quantidade_stock' => 'decimal:3',
            'movimenta_stock' => 'boolean',
            'categoria_produto_id' => 'integer',
            'e_quarto' => 'boolean',
            'e_ativo_imobilizado' => 'boolean',
            'preco_por_hora' => 'decimal:2',
            'preco_por_dia' => 'decimal:2',
            'horas_minimas' => 'decimal:3',
            'e_servico' => 'boolean',
            'bloqueado' => 'boolean',
            'lavandaria_dias_entrega' => 'integer',
            'lavandaria_requer_orcamento' => 'boolean',
            'lavandaria_ativa' => 'boolean',
            'lavandaria_preco_peca' => 'decimal:2',
            'lavandaria_preco_kg' => 'decimal:2',
            'custo_medio' => 'decimal:6',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function categoriaProduto(): BelongsTo
    {
        return $this->belongsTo(CategoriaProduto::class, 'categoria_produto_id');
    }

    public function itensVenda(): HasMany
    {
        return $this->hasMany(ItemVenda::class, 'produto_id');
    }

    public function itensCompra(): HasMany
    {
        return $this->hasMany(ItemCompra::class, 'produto_id');
    }

    public function catalogoFornecedores(): HasMany
    {
        return $this->hasMany(CatalogoFornecedor::class, 'produto_id');
    }

    public function stockArmazem(): HasMany
    {
        return $this->hasMany(StockArmazem::class, 'produto_id');
    }

    public function movimentosInventario(): HasMany
    {
        return $this->hasMany(MovimentoInventario::class, 'produto_id');
    }

    public function itensGuiaSaida(): HasMany
    {
        return $this->hasMany(ItemGuiaSaida::class, 'produto_id');
    }

    public function linhasSessaoInventario(): HasMany
    {
        return $this->hasMany(LinhaSessaoInventario::class, 'produto_id');
    }

    public function movimentosCaixa(): HasMany
    {
        return $this->hasMany(MovimentoCaixa::class, 'produto_id');
    }

    public function estadiasHotel(): HasMany
    {
        return $this->hasMany(EstadiaHotel::class, 'produto_quarto_id');
    }
}
