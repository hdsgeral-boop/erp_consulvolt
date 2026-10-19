<?php

namespace App\Models\Base;

use App\Models\AtivoImobilizado;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;
use App\Models\LancamentoContabil;
use App\Models\ModeloBase;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tabela alocacoes_aquisicao_ativos (módulo Activos). Tabela nova do desenho.
 * GERADO por ferramentas/gerador/gerar_esquema.mjs — não editar: o código de negócio vai em App\Models\AlocacaoAquisicaoAtivo.
 */
abstract class AlocacaoAquisicaoAtivoBase extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    protected $table = 'alocacoes_aquisicao_ativos';

    protected string $moduloAuditoria = 'Activos';

    protected $fillable = [
        'empresa_id', 'ativo_imobilizado_id', 'lancamento_contabil_id', 'valor',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer',
            'ativo_imobilizado_id' => 'integer',
            'lancamento_contabil_id' => 'integer',
            'valor' => 'decimal:2',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }

    public function ativoImobilizado(): BelongsTo
    {
        return $this->belongsTo(AtivoImobilizado::class, 'ativo_imobilizado_id');
    }

    public function lancamentoContabil(): BelongsTo
    {
        return $this->belongsTo(LancamentoContabil::class, 'lancamento_contabil_id');
    }
}
