<?php

namespace App\Models;

use App\Models\Concerns\PertenceEmpresa;

/** agt_comunicacoes — registo de cada pedido à AGT e da resposta (dados sensíveis mascarados). Só se acrescenta. */
class ComunicacaoAgt extends ModeloBase
{
    use PertenceEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'agt_comunicacoes';

    protected $fillable = [
        'empresa_id', 'operacao', 'ambiente', 'endpoint', 'http_estado', 'sucesso', 'codigo_erro', 'mensagem', 'duracao_ms', 'tentativa',
        'submission_uuid', 'request_id', 'documentos', 'pedido', 'resposta', 'utilizador',
    ];

    protected function casts(): array
    {
        return [
            'empresa_id' => 'integer', 'http_estado' => 'integer', 'sucesso' => 'boolean', 'duracao_ms' => 'integer', 'tentativa' => 'integer',
            'documentos' => 'array', 'pedido' => 'array', 'resposta' => 'array', 'criado_em' => 'datetime',
        ];
    }
}
