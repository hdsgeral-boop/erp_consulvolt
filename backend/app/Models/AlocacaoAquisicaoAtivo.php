<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * alocacoes_aquisicao_ativos — parte de uma linha de aquisição (11/12 a débito) coberta por um activo inventariado em
 * combinação de várias linhas (ServicoAquisicoesAtivos::combinar; legado journal_line_id = "ID1,ID2").
 */
final class AlocacaoAquisicaoAtivo extends ModeloBase
{
    use PertenceEmpresa;

    protected $table = 'alocacoes_aquisicao_ativos';

    protected $fillable = ['empresa_id', 'ativo_imobilizado_id', 'lancamento_contabil_id', 'valor'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'ativo_imobilizado_id' => 'integer', 'lancamento_contabil_id' => 'integer', 'valor' => 'decimal:2'];
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
