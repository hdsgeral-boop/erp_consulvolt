<?php

namespace App\Services\Plataforma;

use App\Models\TicketSuporte;
use App\Services\RH\ServicoCalendarioRH;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;

/**
 * Prazo de SLA dos tickets de suporte, consciente do calendário de dias úteis (Fase 6 da extensão SaaS, secção 14
 * de `segurança.txt`, pontos 263-264: "horário de atendimento" e "calendário de feriados").
 *
 * Honesto sobre o que NÃO faz: o sistema não tem, em lado nenhum, um horário de expediente (hora de abertura/fecho)
 * configurado — só dias úteis e feriados (`ConfigAssiduidade`, reutilizado de `ServicoCalendarioRH`, a mesma fonte
 * usada pela assiduidade/férias do RH). Inventar horas de expediente sem essa configuração existir violaria a
 * instrução do próprio documento ("não inventar... verificar antes"). Por isso o cálculo soma as horas do SLA ao
 * relógio normalmente e, se o resultado cair num dia não útil (fim-de-semana/feriado), desliza para o mesmo horário
 * no próximo dia útil — uma melhoria real sobre o comportamento anterior (contava tempo corrido 24/7, incluindo
 * fins-de-semana), sem fabricar uma granularidade horária que não existe.
 */
final class ServicoSlaSuporte
{
    public function __construct(
        private readonly ServicoCalendarioRH $calendario,
        private readonly ContextoEmpresa $contextoEmpresa,
    ) {}

    public function calcularPrazo(string $prioridade, ?Carbon $desde = null): Carbon
    {
        $horas = TicketSuporte::SLA_HORAS[$prioridade] ?? TicketSuporte::SLA_HORAS['NORMAL'];
        $prazo = ($desde ?? now())->copy()->addHours($horas);

        // Tickets de suporte são do tenant (plataforma), não de uma empresa — na maioria das chamadas não há
        // nenhuma empresa activa (ContextoEmpresa::definida() = false), e ConfigAssiduidade exige-a (fail-closed).
        // Sem empresa activa, usa-se o calendário por omissão (seg-sex, sem feriados) em vez de rebentar o pedido.
        $cfg = $this->contextoEmpresa->definida() ? $this->calendario->config() : ServicoCalendarioRH::PADRAO;
        $tentativas = 0;
        while (! $this->calendario->diaUtil($prazo->toDateString(), $cfg) && $tentativas++ < 14) {
            $prazo = $prazo->addDay();
        }

        return $prazo;
    }
}
