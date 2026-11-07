<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * reservas_espaco — reserva de uma área comum, por uma fracção com contrato (ou interna, em nome da administração). O estado muda por
 * ServicoReservasEspaco/ServicoReservasFinanceiro, que deixam trilho em historico_reserva_imo.
 */
class ReservaEspaco extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const ESTADOS = ['PEDIDA', 'APROVADA', 'AGUARDA_PAGAMENTO', 'CONFIRMADA', 'REALIZADA', 'FALTOU', 'CANCELADA', 'RECUSADA'];

    protected $table = 'reservas_espaco';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'espaco_id', 'imovel_id', 'fracao_id', 'contrato_id', 'terceiro_id', 'interna', 'data', 'hora_inicio', 'hora_fim', 'pessoas', 'motivo',
        'contacto', 'estado', 'estado_em', 'estado_por', 'motivo_estado', 'realizada_em', 'valor', 'valor_detalhe', 'caucao', 'caucao_recebida', 'caucao_devolvida',
        'caucao_retida', 'caucao_motivo', 'divida_na_marcacao', 'autorizacao_motivo', 'autorizacao_por', 'autorizacao_em', 'prazo_pagamento', 'caducada', 'venda_id',
        'nota_credito_id', 'reembolso_previsto', 'reembolso_retido', 'reembolso_pct', 'nc_pendente', 'notas', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'espaco_id' => 'integer', 'imovel_id' => 'integer', 'fracao_id' => 'integer', 'contrato_id' => 'integer', 'terceiro_id' => 'integer',
            'interna' => 'boolean', 'data' => 'date:Y-m-d', 'pessoas' => 'integer', 'estado_em' => 'datetime', 'realizada_em' => 'datetime', 'valor' => 'float', 'caucao' => 'float',
            'caucao_recebida' => 'float', 'caucao_devolvida' => 'float', 'caucao_retida' => 'float', 'divida_na_marcacao' => 'float', 'autorizacao_em' => 'datetime',
            'prazo_pagamento' => 'datetime', 'caducada' => 'boolean', 'venda_id' => 'integer', 'nota_credito_id' => 'integer', 'reembolso_previsto' => 'float',
            'reembolso_retido' => 'float', 'reembolso_pct' => 'integer', 'nc_pendente' => 'boolean'];
    }
}
