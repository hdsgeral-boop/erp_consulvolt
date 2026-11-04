<?php

namespace App\Services\Plataforma;

use App\Http\Controllers\Api\SaudeController;
use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\SubscricaoEmpresa;
use App\Models\Tenant;
use App\Models\TicketSuporte;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores do dashboard executivo da plataforma (Fase 5 da extensão SaaS, secção 11 de `segurança.txt`).
 * Fórmulas (documentadas aqui, não só no código — a secção 11 exige isto explicitamente):
 *
 *  - MRR (receita mensal recorrente): para cada empresa com licença utilizável (activa/tolerância/pagamento
 *    pendente), o valor mensal equivalente da sua ÚLTIMA subscrição paga (valor ÷ meses dessa subscrição) — reflecte
 *    o preço que o cliente efectivamente paga, não o preço de tabela actual do plano (que pode ter mudado desde então).
 *  - ARR = MRR × 12. Nunca confundir com facturação anual efectivamente realizada (ponto 517/519 da secção 11).
 *  - Receita recebida (mês): soma de `subscricoes_empresa.valor` com estado PAGO e `pago_em` no mês actual — dinheiro
 *    que entrou, não MRR (ponto 517: nunca confundir as duas).
 *  - Taxa de cancelamento (mês): empresas que entraram em CANCELADA ou SUSPENSA este mês, sobre o total de empresas
 *    com licença utilizável no início do mês (aproximação: total actual não-cancelado/encerrado + as que cancelaram).
 *  - Taxa de conversão de trial (mês): trials que converteram para ACTIVA este mês (`licenca_origem=PLANO` e
 *    `estado_licenca_desde` no mês), sobre o total de trials que começaram neste mês.
 */
final class ServicoDashboardExecutivo
{
    public const TTL_SERIES = 120;

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return array<string, mixed> */
    public function indicadores(): array
    {
        return $this->contexto->semIsolamento(function () {
            $inicioMes = now()->startOfMonth();

            return [
                'mrr' => $this->mrr(),
                'arr' => $this->mrr() * 12,
                'receita_recebida_mes' => $this->receitaRecebida($inicioMes),
                'pagamentos' => [
                    'pendentes' => SubscricaoEmpresa::query()->where('estado', SubscricaoEmpresa::PENDENTE)->count(),
                    'confirmados_mes' => SubscricaoEmpresa::query()->where('estado', SubscricaoEmpresa::PAGO)->where('pago_em', '>=', $inicioMes)->count(),
                ],
                'taxa_cancelamento_mes' => $this->taxaCancelamento($inicioMes),
                'taxa_conversao_trial_mes' => $this->taxaConversaoTrial($inicioMes),
                'tickets' => $this->tickets(),
                'disponibilidade' => $this->disponibilidade(),
            ] + $this->series();
        });
    }

    /**
     * Séries para os gráficos do painel: clientes novos e receita recebida por mês (12 meses), acessos por dia (30 dias)
     * e clientes por plano. O cliente que opera a plataforma fica fora das contagens de negócio. Receita e acessos vêm
     * de funções SECURITY DEFINER (agregados de todos os clientes, ver 2026_11_16_110000). Só números, sem dados
     * pessoais; em cache de 2 minutos (chave global `plataforma:painel:series`, ver CACHE.md).
     *
     * @return array<string, mixed>
     */
    private function series(): array
    {
        return Cache::remember('plataforma:painel:series', self::TTL_SERIES, function () {
            $plataforma = (int) config('erp.dominio.plataforma_tenant_id', 1);
            $meses = collect(range(11, 0))->map(fn (int $i) => now()->startOfMonth()->subMonths($i));
            $dias = collect(range(29, 0))->map(fn (int $i) => now()->subDays($i)->startOfDay());

            $clientes = Tenant::query()->where('id', '<>', $plataforma)->get(['id', 'plano', 'criado_em']);
            $novos = $clientes->groupBy(fn (Tenant $t) => $t->criado_em?->format('Y-m'))->map->count();
            $receita = collect(DB::select('SELECT mes, total FROM plataforma_receita_por_mes(?)', [$meses->first()]))->pluck('total', 'mes');
            $acessos = collect(DB::select('SELECT dia, total FROM plataforma_acessos_por_dia(?, ?)', [$dias->first(), $plataforma]))->pluck('total', 'dia');
            $nomesPlanos = PlanoLicenca::query()->pluck('nome', 'codigo');

            return [
                'moeda' => 'AOA',
                'meses' => $meses->map(fn (Carbon $m) => $m->locale('pt')->isoFormat('MMM YY'))->all(),
                'clientes_novos_mes' => $meses->map(fn (Carbon $m) => (int) ($novos[$m->format('Y-m')] ?? 0))->all(),
                'receita_mes' => $meses->map(fn (Carbon $m) => round((float) ($receita[$m->format('Y-m')] ?? 0), 2))->all(),
                'dias' => $dias->map(fn (Carbon $d) => $d->format('d/m'))->all(),
                'acessos_dia' => $dias->map(fn (Carbon $d) => (int) ($acessos[$d->format('Y-m-d')] ?? 0))->all(),
                'por_plano' => $clientes->groupBy(fn (Tenant $t) => $t->plano ?: '')->map->count()
                    ->map(fn (int $n, string $codigo) => ['plano' => $codigo === '' ? 'Sem plano' : ($nomesPlanos[$codigo] ?? $codigo), 'clientes' => $n])->values()->all(),
            ];
        });
    }

    /** Valor mensal equivalente da última subscrição paga de cada empresa com licença utilizável. */
    private function mrr(): float
    {
        $utilizaveis = [Empresa::LICENCA_ACTIVA, Empresa::LICENCA_TOLERANCIA, Empresa::LICENCA_PAGAMENTO_PENDENTE];

        $total = DB::table('empresas as e')
            ->whereIn('e.estado_licenca', $utilizaveis)
            ->joinSub(
                DB::table('subscricoes_empresa')->select('empresa_id')->selectRaw('MAX(id) as ultima_id')
                    ->where('estado', SubscricaoEmpresa::PAGO)->groupBy('empresa_id'),
                'ultimas', 'ultimas.empresa_id', '=', 'e.id',
            )
            ->join('subscricoes_empresa as s', 's.id', '=', 'ultimas.ultima_id')
            ->where('s.meses', '>', 0)
            ->selectRaw('COALESCE(SUM(s.valor / s.meses), 0) as total')
            ->value('total');

        return round((float) $total, 2);
    }

    private function receitaRecebida(Carbon $desde): float
    {
        return (float) (SubscricaoEmpresa::query()->where('estado', SubscricaoEmpresa::PAGO)->where('pago_em', '>=', $desde)->sum('valor') ?? 0);
    }

    private function taxaCancelamento(Carbon $desde): float
    {
        $canceladasEsteMes = Empresa::query()->whereIn('estado_licenca', [Empresa::LICENCA_CANCELADA, Empresa::LICENCA_SUSPENSA])
            ->where('estado_licenca_desde', '>=', $desde)->count();
        $baseUtilizaveis = Empresa::query()->whereNotIn('estado_licenca', [Empresa::LICENCA_CANCELADA, Empresa::LICENCA_EM_ENCERRAMENTO, Empresa::LICENCA_ENCERRADA])->count();
        $base = $baseUtilizaveis + $canceladasEsteMes;

        return $base > 0 ? round($canceladasEsteMes / $base * 100, 1) : 0.0;
    }

    private function taxaConversaoTrial(Carbon $desde): float
    {
        $convertidosEsteMes = Empresa::query()->where('licenca_origem', Empresa::LICENCA_PLANO)
            ->whereIn('estado_licenca', [Empresa::LICENCA_ACTIVA, Empresa::LICENCA_TOLERANCIA])
            ->where('estado_licenca_desde', '>=', $desde)->count();
        $iniciaramTrialEsteMes = Empresa::query()->where('licenca_inicio', '>=', $desde->toDateString())
            ->whereIn('licenca_origem', [Empresa::LICENCA_TESTE, Empresa::LICENCA_PLANO])->count();

        return $iniciaramTrialEsteMes > 0 ? round($convertidosEsteMes / $iniciaramTrialEsteMes * 100, 1) : 0.0;
    }

    /** Reutiliza TicketSuporte (sem filtro de categoria: visão executiva global, diferente de DashboardSuporteController). */
    private function tickets(): array
    {
        $abertos = TicketSuporte::query()->whereNotIn('estado', TicketSuporte::ESTADOS_TERMINAIS)->count();
        $criticos = TicketSuporte::query()->whereNotIn('estado', TicketSuporte::ESTADOS_TERMINAIS)
            ->whereIn('prioridade', ['URGENTE', 'ALTA'])->count();
        $vencidos = TicketSuporte::query()->whereNotNull('prazo_sla')->whereNotIn('estado', TicketSuporte::ESTADOS_TERMINAIS)
            ->where('prazo_sla', '<', now())->count();
        $tempoMedioPrimeiraRespostaHoras = TicketSuporte::query()->whereNotNull('primeira_resposta_em')
            ->selectRaw('avg(extract(epoch from (primeira_resposta_em - criado_em)) / 3600.0) as media')->value('media');

        return [
            'abertos' => $abertos,
            'criticos' => $criticos,
            'vencidos' => $vencidos,
            'tempo_medio_primeira_resposta_horas' => $tempoMedioPrimeiraRespostaHoras !== null ? round((float) $tempoMedioPrimeiraRespostaHoras, 1) : null,
        ];
    }

    /** Reutiliza /api/saude (SaudeController) em vez de duplicar a lógica de verificação de componentes. */
    private function disponibilidade(): bool
    {
        $resposta = app(SaudeController::class)();

        return $resposta->getStatusCode() === 200;
    }
}
