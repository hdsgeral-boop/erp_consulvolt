<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comprovativo de transferência bancária anexado pelo cliente a um pedido de subscrição SaaS. Ao contrário de
 * UsoCupom, aqui não há contagem entre empresas irmãs — cada comprovativo pertence sempre à empresa da própria
 * subscrição — por isso usa PertenceEmpresa normalmente (âmbito automático + protecção contra leitura cruzada).
 * O backoffice da plataforma (Fase 4, listagem entre tenants) consulta por `DB::table`, sem o âmbito do Eloquent,
 * tal como PlataformaSaasController::listarEmpresas().
 */
class AnexoComprovativoPagamento extends ModeloBase
{
    use PertenceEmpresa;

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = null;

    protected $table = 'anexos_comprovativo_pagamento';

    protected $fillable = [
        'subscricao_id', 'transacao_id', 'empresa_id', 'nome_original', 'caminho', 'tamanho_bytes', 'tipo_mime', 'enviado_por', 'ip',
    ];

    public function subscricao(): BelongsTo
    {
        return $this->belongsTo(SubscricaoEmpresa::class, 'subscricao_id');
    }

    public function transacao(): BelongsTo
    {
        return $this->belongsTo(TransacaoGateway::class, 'transacao_id');
    }
}
