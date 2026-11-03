<?php

namespace App\Models;

/**
 * outbox_eventos — efeito externo de uma escrita de negócio, gravado na mesma transacção e publicado depois (ADR-080).
 *
 * Propositadamente SEM `PertenceEmpresa`: `empresa_id` é nulo nos eventos globais da plataforma e o publicador corre sem empresa
 * activa (agendador). O isolamento é feito pela política RLS da tabela (ContextoTenant) e, no código, pelo publicador, que executa
 * cada manipulador com `ContextoEmpresa::executarComo($evento->empresa_id)`.
 */
class OutboxEvento extends ModeloBase
{
    public const UPDATED_AT = null;

    public const PENDENTE = 'PENDENTE';

    public const PUBLICADO = 'PUBLICADO';

    public const MORTO = 'MORTO';

    protected $table = 'outbox_eventos';

    protected $fillable = ['empresa_id', 'tipo', 'agregado_tipo', 'agregado_id', 'chave', 'carga', 'estado', 'tentativas', 'proxima_tentativa_em', 'reservado_ate', 'ultimo_erro', 'publicado_em'];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer', 'agregado_id' => 'integer', 'tentativas' => 'integer', 'carga' => 'array',
            'proxima_tentativa_em' => 'datetime', 'reservado_ate' => 'datetime', 'criado_em' => 'datetime', 'publicado_em' => 'datetime',
        ];
    }
}
