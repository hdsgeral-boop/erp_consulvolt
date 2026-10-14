<?php

namespace App\Models;

/**
 * Pedido de restauro da plataforma a partir do backup do legado (ServicoRestauroLegado). Sem empresa nem chaves
 * estrangeiras: sobrevive ao TRUNCATE do próprio restauro.
 */
final class RestauroLegado extends ModeloBase
{
    public const ACTIVOS = ['SIMULANDO', 'SIMULADO', 'APROVADO', 'EM_EXECUCAO'];

    protected $table = 'restauros_legado';

    protected $fillable = [
        'estado', 'nome_ficheiro', 'caminho', 'sha256', 'tamanho_bytes', 'pedido_por', 'pedido_em', 'simulacao', 'simulado_em', 'aprovado_por', 'aprovado_em',
        'expira_em', 'executado_por', 'iniciado_em', 'concluido_em', 'copia_seguranca', 'relatorio', 'erro', 'historico',
    ];

    protected $hidden = ['caminho'];

    protected function casts(): array
    {
        return [
            'tamanho_bytes' => 'integer',
            'pedido_por' => 'array',
            'simulacao' => 'array',
            'aprovado_por' => 'array',
            'executado_por' => 'array',
            'relatorio' => 'array',
            'historico' => 'array',
            'pedido_em' => 'datetime',
            'simulado_em' => 'datetime',
            'aprovado_em' => 'datetime',
            'expira_em' => 'datetime',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
            'criado_em' => 'datetime',
            'atualizado_em' => 'datetime',
        ];
    }
}
