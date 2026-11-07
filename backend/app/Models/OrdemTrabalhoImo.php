<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\PertenceEmpresa;

/**
 * ordens_trabalho_imo — ordem de obra, apetrechamento, manutenção ou vistoria de um imóvel ou fracção. O estado muda por
 * ServicoObrasImoveis (trilho em historico_ordem_imo); a ordem concluída e debitável pode ser debitada ao inquilino numa renda.
 */
class OrdemTrabalhoImo extends ModeloBase
{
    use Auditavel, PertenceEmpresa;

    public const TIPOS = ['APETRECHAMENTO', 'PREVENTIVA', 'CORRECTIVA', 'MELHORIA', 'VISTORIA'];

    public const ESTADOS = ['ABERTA', 'AGUARDA_MATERIAL', 'EM_CURSO', 'CONCLUIDA', 'CANCELADA'];

    public const FECHADAS = ['CONCLUIDA', 'CANCELADA'];

    protected $table = 'ordens_trabalho_imo';

    protected string $moduloAuditoria = 'Imobiliário';

    protected $fillable = ['empresa_id', 'numero', 'tipo', 'descricao', 'imovel_id', 'fracao_id', 'prioridade', 'quem_paga', 'debitavel', 'data_pedido', 'data_prevista', 'data_conclusao',
        'responsavel', 'pedido_por', 'custo_estimado', 'ficha_id', 'plano_id', 'plano_data', 'vistoria_reserva_id', 'estado', 'motivo_cancelamento', 'aprovada_por', 'aprovada_em',
        'debitado_em', 'debitado_renda_id', 'debitado_valor', 'notas', 'criado_por', 'atualizado_por'];

    protected function casts(): array
    {
        return ['empresa_id' => 'integer', 'imovel_id' => 'integer', 'fracao_id' => 'integer', 'ficha_id' => 'integer', 'plano_id' => 'integer', 'vistoria_reserva_id' => 'integer',
            'debitado_renda_id' => 'integer', 'debitavel' => 'boolean', 'custo_estimado' => 'float', 'debitado_valor' => 'float', 'data_pedido' => 'date:Y-m-d',
            'data_prevista' => 'date:Y-m-d', 'data_conclusao' => 'date:Y-m-d', 'plano_data' => 'date:Y-m-d', 'aprovada_em' => 'datetime', 'debitado_em' => 'datetime'];
    }

    public function fechada(): bool
    {
        return in_array($this->estado, self::FECHADAS, true);
    }
}
