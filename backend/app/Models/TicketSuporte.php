<?php

namespace App\Models;

/**
 * Pedido de suporte de um cliente SaaS (`tickets_suporte`). Gerido pela plataforma; pertence ao cliente (tenant), não a
 * uma empresa, por isso sem RLS.
 */
class TicketSuporte extends ModeloBase
{
    public const ABERTO = 'ABERTO';

    public const EM_CURSO = 'EM_CURSO';

    public const RESOLVIDO = 'RESOLVIDO';

    public const FECHADO = 'FECHADO';

    public const ESTADOS = [self::ABERTO, self::EM_CURSO, self::RESOLVIDO, self::FECHADO];

    public const PRIORIDADES = ['BAIXA', 'NORMAL', 'ALTA', 'URGENTE'];

    public const CATEGORIAS = ['GERAL', 'PLANO', 'FACTURACAO'];

    protected $table = 'tickets_suporte';

    protected $fillable = ['tenant_id', 'assunto', 'descricao', 'estado', 'prioridade', 'categoria', 'criado_por'];
}