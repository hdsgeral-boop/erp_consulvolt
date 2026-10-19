<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Produto;
use App\Models\RegistoConversaoContasProduto;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabela categorias_produtos (módulo Logística). Legado: product_categories · 24 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\CategoriaProduto.
 */
abstract class CategoriaProdutoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa, SoftDeletes;

    protected $table = 'categorias_produtos';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'nome', 'descricao', 'codigo_conta', 'conta_custo', 'conta_compra', 'conta_inventario', 'conta_iva_liquidado', 'conta_iva_dedutivel', 'conta_quebra', 'conta_sobra', 'conta_ativo', 'chave_conversao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
            'eliminado_em' => 'datetime',
        ];
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class, 'categoria_produto_id');
    }

    public function registosConversaoContasProdutos(): HasMany
    {
        return $this->hasMany(RegistoConversaoContasProduto::class, 'categoria_nova_id');
    }
}
