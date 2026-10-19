<?php

namespace App\Models\Base;

use App\Models\CategoriaProduto;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ModeloBase;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela registos_conversao_contas_produtos (módulo Logística). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\RegistoConversaoContasProduto.
 */
abstract class RegistoConversaoContasProdutoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'registos_conversao_contas_produtos';

    protected string $moduloAuditoria = 'Logística';

    protected $fillable = [
        'empresa_id', 'produto_id', 'categoria_anterior_id', 'categoria_nova_id', 'categoria_criada', 'contas', 'avisos', 'executado_por',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'produto_id' => 'integer',
            'categoria_anterior_id' => 'integer',
            'categoria_nova_id' => 'integer',
            'categoria_criada' => 'boolean',
            'contas' => 'array',
            'avisos' => 'array',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class, 'produto_id');
    }

    public function categoriaNova(): BelongsTo
    {
        return $this->belongsTo(CategoriaProduto::class, 'categoria_nova_id');
    }
}
