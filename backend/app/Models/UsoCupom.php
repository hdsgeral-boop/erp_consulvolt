<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * usos_cupom — ledger de utilização de um cupão (uma linha por subscrição). Conta-se por tenant_id
 * (limite_por_cliente) — um tenant pode ter várias empresas, por isso NÃO usa o trait PertenceEmpresa (o seu
 * âmbito automático filtraria/gravaria só pela empresa activa, sub-contando o uso de um cupão entre empresas
 * irmãs do mesmo tenant). `empresa_id` está presente só para a tabela herdar a política RLS automaticamente
 * (IsolamentoRls); a contagem de negócio é sempre por tenant_id, nunca por empresa_id (ver ServicoCupoes).
 * Sem atualizado_em: é um registo imutável.
 */
class UsoCupom extends ModeloBase
{
    public const UPDATED_AT = null;

    protected $table = 'usos_cupom';

    protected $fillable = ['cupom_id', 'tenant_id', 'empresa_id', 'subscricao_id', 'valor_desconto_aplicado'];

    protected function casts(): array
    {
        return ['valor_desconto_aplicado' => 'decimal:2'];
    }

    public function cupom(): BelongsTo
    {
        return $this->belongsTo(CupomDesconto::class, 'cupom_id');
    }

    public function subscricao(): BelongsTo
    {
        return $this->belongsTo(SubscricaoEmpresa::class, 'subscricao_id');
    }
}
