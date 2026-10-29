<?php

namespace App\Models;

/**
 * Pedido de suporte de um cliente SaaS (`tickets_suporte`). Gerido pela plataforma; pertence ao cliente (tenant), não a
 * uma empresa, por isso sem RLS.
 */
class TicketSuporte extends ModeloBase
{
    public const ABERTO = 'ABERTO';

    /** Fase 6 da extensão SaaS (secção 13.3): estados intermédios entre "aberto" e "em atendimento". */
    public const EM_TRIAGEM = 'EM_TRIAGEM';

    public const EM_ANALISE = 'EM_ANALISE';

    public const ATRIBUIDO = 'ATRIBUIDO';

    public const EM_CURSO = 'EM_CURSO';

    public const A_AGUARDAR_CLIENTE = 'A_AGUARDAR_CLIENTE';

    public const A_AGUARDAR_TERCEIRO = 'A_AGUARDAR_TERCEIRO';

    public const RESOLVIDO = 'RESOLVIDO';

    public const FECHADO = 'FECHADO';

    public const REABERTO = 'REABERTO';

    public const CANCELADO = 'CANCELADO';

    /** Os 11 estados da secção 13.3 do documento (segurança.txt) — ver MaquinaEstadosTicket para as transições válidas. */
    public const ESTADOS = [
        self::ABERTO, self::EM_TRIAGEM, self::EM_ANALISE, self::ATRIBUIDO, self::EM_CURSO,
        self::A_AGUARDAR_CLIENTE, self::A_AGUARDAR_TERCEIRO, self::RESOLVIDO, self::FECHADO, self::REABERTO, self::CANCELADO,
    ];

    /** Estados que já não contam para "em atraso" nem para filas de trabalho activas. */
    public const ESTADOS_TERMINAIS = [self::RESOLVIDO, self::FECHADO, self::CANCELADO];

    public const PRIORIDADES = ['BAIXA', 'NORMAL', 'ALTA', 'URGENTE'];

    /**
     * As 15 categorias da secção 13.2 do documento, mais as 4 já existentes (GERAL/PLANO/FACTURACAO/MARKETING,
     * preservadas por compatibilidade com tickets já criados).
     */
    public const CATEGORIAS = [
        'GERAL', 'PLANO', 'FACTURACAO', 'MARKETING',
        'INCIDENTE_TECNICO', 'ERRO_FUNCIONAL', 'AUTENTICACAO', 'PERMISSOES', 'PAGAMENTO', 'SUBSCRICAO',
        'CONFIGURACAO', 'MELHORIA', 'DUVIDA', 'DESEMPENHO', 'INTEGRACAO', 'SEGURANCA', 'ADMINISTRATIVA', 'OUTROS',
    ];

    /**
     * Categoria → chave de gestão do catálogo (módulo `suporte`, ADR-077, alargado na Fase 6 da extensão SaaS):
     * financeiro trata o que afecta a cobrança (plano/facturação/pagamento/subscrição); técnico trata o que é um
     * incidente ou dúvida sobre o funcionamento do sistema; o resto (incl. administrativa/outros) fica em geral.
     */
    public const CHAVE_POR_CATEGORIA = [
        'GERAL' => 'suporte_tickets_geral_gerir',
        'MARKETING' => 'suporte_tickets_marketing_gerir',
        'PLANO' => 'suporte_tickets_financeiro_gerir',
        'FACTURACAO' => 'suporte_tickets_financeiro_gerir',
        'PAGAMENTO' => 'suporte_tickets_financeiro_gerir',
        'SUBSCRICAO' => 'suporte_tickets_financeiro_gerir',
        'INCIDENTE_TECNICO' => 'suporte_tickets_tecnico_gerir',
        'ERRO_FUNCIONAL' => 'suporte_tickets_tecnico_gerir',
        'AUTENTICACAO' => 'suporte_tickets_tecnico_gerir',
        'PERMISSOES' => 'suporte_tickets_tecnico_gerir',
        'CONFIGURACAO' => 'suporte_tickets_tecnico_gerir',
        'DESEMPENHO' => 'suporte_tickets_tecnico_gerir',
        'INTEGRACAO' => 'suporte_tickets_tecnico_gerir',
        'SEGURANCA' => 'suporte_tickets_tecnico_gerir',
        'MELHORIA' => 'suporte_tickets_geral_gerir',
        'DUVIDA' => 'suporte_tickets_geral_gerir',
        'ADMINISTRATIVA' => 'suporte_tickets_geral_gerir',
        'OUTROS' => 'suporte_tickets_geral_gerir',
    ];

    /** SLA por prioridade (horas até ao prazo de primeira resposta/resolução). */
    public const SLA_HORAS = ['URGENTE' => 4, 'ALTA' => 24, 'NORMAL' => 72, 'BAIXA' => 120];

    protected $table = 'tickets_suporte';

    protected $fillable = [
        'tenant_id', 'assunto', 'descricao', 'estado', 'prioridade', 'categoria', 'criado_por',
        'agente_id', 'prazo_sla', 'primeira_resposta_em', 'csat_nota', 'csat_comentario', 'sla_violado_avisado_em',
        'tempo_atendimento_minutos',
    ];

    protected function casts(): array
    {
        return [
            'prazo_sla' => 'datetime', 'primeira_resposta_em' => 'datetime', 'csat_nota' => 'integer', 'agente_id' => 'integer',
            'sla_violado_avisado_em' => 'datetime', 'tempo_atendimento_minutos' => 'integer',
        ];
    }

    /** Prazo do SLA (ainda não vencido = em dia; estados abertos com prazo vencido = em atraso). */
    public function emAtraso(): bool
    {
        return $this->prazo_sla !== null && ! in_array($this->estado, self::ESTADOS_TERMINAIS, true) && now()->greaterThan($this->prazo_sla);
    }
}