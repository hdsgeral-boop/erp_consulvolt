<?php

namespace App\Models\Base;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\ItemDocumentoTesouraria;
use App\Models\LancamentoContabil;
use App\Models\LancamentoEstornado;
use App\Models\ModeloBase;
use App\Models\MovimentoCaixa;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tabela notas_fluxo_caixa (módulo Contabilidade). Legado: cashflow_notes · 411 linhas reais no backup.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\NotaFluxoCaixa.
 */
abstract class NotaFluxoCaixaBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'notas_fluxo_caixa';

    protected string $moduloAuditoria = 'Contabilidade';

    protected $fillable = [
        'empresa_id', 'codigo', 'descricao',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function lancamentosContabeis(): HasMany
    {
        return $this->hasMany(LancamentoContabil::class, 'nota_fluxo_caixa_id');
    }

    public function lancamentosEstornados(): HasMany
    {
        return $this->hasMany(LancamentoEstornado::class, 'nota_fluxo_caixa_id');
    }

    public function itensDocumentoTesouraria(): HasMany
    {
        return $this->hasMany(ItemDocumentoTesouraria::class, 'nota_fluxo_caixa_id');
    }

    public function movimentosCaixa(): HasMany
    {
        return $this->hasMany(MovimentoCaixa::class, 'nota_fluxo_caixa_id');
    }
}
