<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/**
 * agt_submissoes — «outbox» dos lotes de documentos enviados à AGT (registarFactura). O submissionUUID é fixado antes
 * do envio e reutilizado nas retentativas: um pedido repetido devolve o requestID original (idempotência).
 */
class SubmissaoAgt extends ModeloBase
{
    use PertenceEmpresa;

    public const PENDENTE = 'PENDENTE';

    public const ACEITE = 'ACEITE';

    public const RECUSADA = 'RECUSADA';

    /** O pedido pode ter chegado à AGT (tempo esgotado, 5xx): reenvia-se com o MESMO submissionUUID e os mesmos documentos. */
    public const INCERTA = 'INCERTA';

    protected $table = 'agt_submissoes';

    protected $fillable = ['empresa_id', 'submission_uuid', 'venda_ids', 'documentos', 'estado', 'tentativas', 'request_id', 'ultimo_erro', 'proxima_tentativa_em'];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer', 'venda_ids' => 'array', 'documentos' => 'array', 'tentativas' => 'integer',
            'proxima_tentativa_em' => 'datetime', 'criado_em' => 'datetime', 'atualizado_em' => 'datetime',
        ];
    }
}
