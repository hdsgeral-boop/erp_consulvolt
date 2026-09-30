<?php

namespace App\Services\CRM;

use App\Models\OportunidadeVendaCRM;

/**
 * Previsão de vendas e indicadores (previsao e kpis, crm_dados.js:374-429). Paridade:
 *   - previsão: oportunidades abertas por mês de fecho previsto (N meses a partir do actual): bruto, ponderado
 *     (valor × probabilidade efectiva), número e «compromisso» (probabilidade ≥ 75 %); as de fecho já ultrapassado
 *     ficam em «atrasadas»; totais por responsável;
 *   - indicadores de um funil no período: criadas, ganhas, perdidas (pela data de fecho), valores, taxa de ganho,
 *     conversão lead → ganho, conversão entre etapas em aberto (pelo histórico), ciclo médio e mediano (dias da
 *     criação ao fecho das ganhas), valor do funil e ponderado, por etapa, motivos de perda e ticket médio.
 * Correcção: valores em decimal exacto (bcmath).
 */
final class ServicoIndicadoresCRM
{
    public function __construct(private readonly ServicoConfiguracaoCRM $config) {}

    public function previsao(?int $funilId, int $meses, ?string $responsavel): array
    {
        $funis = $this->config->funis()->keyBy('id');
        $inicio = substr(RegrasCRM::hoje(), 0, 7);
        $chaves = [];
        for ($i = 0; $i < $meses; $i++) {
            $chaves[] = now()->startOfMonth()->addMonths($i)->format('Y-m');
        }
        $abertas = OportunidadeVendaCRM::query()->where('estado', 'ABERTA')->when($funilId, fn ($q, $f) => $q->where('funil_vendas_crm_id', $f))
            ->when($responsavel, fn ($q, $r) => $q->where('responsavel', $r))->orderBy('id')->get();
        $porMes = array_map(fn ($k) => ['mes' => $k, 'bruto' => '0.00', 'ponderado' => '0.00', 'n' => 0, 'compromisso' => '0.00'], $chaves);
        $idx = array_flip($chaves);
        $atrasadas = ['bruto' => '0.00', 'ponderado' => '0.00', 'n' => 0];
        $porResp = [];
        foreach ($abertas as $o) {
            $prob = RegrasCRM::probabilidade($o->probabilidade === null ? null : (string) $o->probabilidade, RegrasCRM::etapa(($funis[$o->funil_vendas_crm_id] ?? null)?->etapas, $o->etapa_codigo));
            $pond = RegrasCRM::ponderado((string) $o->valor, $prob);
            $r = $o->responsavel ?: '—';
            $porResp[$r] ??= ['responsavel' => $r, 'bruto' => '0.00', 'ponderado' => '0.00', 'n' => 0];
            $porResp[$r] = ['responsavel' => $r, 'bruto' => bcadd($porResp[$r]['bruto'], (string) $o->valor, 2), 'ponderado' => bcadd($porResp[$r]['ponderado'], $pond, 2), 'n' => $porResp[$r]['n'] + 1];
            $k = (string) $o->data_fecho_prevista?->format('Y-m');
            if ($k < $inicio) {
                $atrasadas = ['bruto' => bcadd($atrasadas['bruto'], (string) $o->valor, 2), 'ponderado' => bcadd($atrasadas['ponderado'], $pond, 2), 'n' => $atrasadas['n'] + 1];

                continue;
            }
            if (! isset($idx[$k])) {
                continue;
            }
            $m = &$porMes[$idx[$k]];
            $m['bruto'] = bcadd($m['bruto'], (string) $o->valor, 2);
            $m['ponderado'] = bcadd($m['ponderado'], $pond, 2);
            $m['n']++;
            if (bccomp($prob, '75', 4) >= 0) {
                $m['compromisso'] = bcadd($m['compromisso'], (string) $o->valor, 2);
            }
            unset($m);
        }

        return ['por_mes' => $porMes, 'atrasadas' => $atrasadas, 'por_responsavel' => array_values($porResp), 'abertas' => $abertas->count()];
    }

    public function kpis(?int $funilId, ?string $de, ?string $ate): array
    {
        $funis = $this->config->funis();
        $funil = ($funilId ? $funis->firstWhere('id', $funilId) : null) ?? $funis->first();
        $etapas = array_values($funil->etapas ?? []);
        $doFunil = OportunidadeVendaCRM::query()->where('funil_vendas_crm_id', $funil->id)->orderBy('id')->get();
        $noPeriodo = fn ($d) => $d && (! $de || $d->toDateString() >= $de) && (! $ate || $d->toDateString() <= $ate);
        $criadas = $doFunil->filter(fn ($o) => $noPeriodo($o->criado_em));
        $abertas = $doFunil->where('estado', 'ABERTA');
        $fechadas = $doFunil->filter(fn ($o) => $o->estado !== 'ABERTA' && $noPeriodo($o->fechado_em));
        $ganhas = $fechadas->where('estado', 'GANHA');
        $perdidas = $fechadas->where('estado', 'PERDIDA');
        $ordem = [];
        foreach ($etapas as $i => $e) {
            $ordem[$e['id']] = $i;
        }
        $abertasEt = array_values(array_filter($etapas, fn ($e) => $e['tipo'] === 'ABERTA'));
        $ganhaIdx = collect($etapas)->search(fn ($e) => $e['tipo'] === 'GANHA');
        $ganhaIdx = $ganhaIdx === false ? 99 : $ganhaIdx;
        $chegou = fn ($o, int $idx) => collect($o->historico ?? [])->contains(function ($h) use ($ordem, $etapas, $idx) {
            $i = $ordem[$h['etapa_codigo'] ?? ''] ?? -1;

            return $i >= $idx && ($etapas[$i]['tipo'] ?? null) !== 'PERDIDA';
        }) || ($idx <= $ganhaIdx && $o->estado === 'GANHA');
        $conversao = [];
        foreach ($abertasEt as $i => $e) {
            $base = $criadas->filter(fn ($o) => $chegou($o, $ordem[$e['id']]));
            $ultima = $i === count($abertasEt) - 1;
            $seguinte = $ultima ? $ganhaIdx : $ordem[$abertasEt[$i + 1]['id']];
            $avancaram = $base->filter(fn ($o) => $ultima ? $o->estado === 'GANHA' : $chegou($o, $seguinte))->count();
            $conversao[] = ['etapa' => $e['nome'], 'seguinte' => $ultima ? 'Ganha' : $abertasEt[$i + 1]['nome'], 'entraram' => $base->count(), 'avancaram' => $avancaram,
                'taxa' => $base->count() ? round($avancaram / $base->count() * 100, 2) : null];
        }
        $ciclo = $ganhas->map(fn ($o) => RegrasCRM::diasEntre($o->criado_em?->toDateString(), $o->fechado_em?->toDateString()))->sort()->values();
        $motivos = $perdidas->groupBy(fn ($o) => $o->motivo_perda ?: 'Sem motivo')->map(fn ($g, $m) => ['motivo' => $m, 'n' => $g->count(), 'valor' => $this->soma($g)])
            ->sortByDesc(fn ($x) => (float) $x['valor'])->values();
        $pond = fn ($o) => RegrasCRM::ponderado((string) $o->valor, RegrasCRM::probabilidade($o->probabilidade === null ? null : (string) $o->probabilidade, RegrasCRM::etapa($etapas, $o->etapa_codigo)));

        return [
            'funil' => $funil->only(['id', 'nome']), 'criadas' => $criadas->count(), 'ganhas' => $ganhas->count(), 'perdidas' => $perdidas->count(),
            'valor_ganho' => $this->soma($ganhas), 'valor_perdido' => $this->soma($perdidas),
            'taxa_ganho' => $ganhas->count() + $perdidas->count() ? round($ganhas->count() / ($ganhas->count() + $perdidas->count()) * 100, 2) : null,
            'conversao_lead_ganho' => $criadas->count() ? round($criadas->where('estado', 'GANHA')->count() / $criadas->count() * 100, 2) : null,
            'conversao' => $conversao, 'ciclo_medio' => $ciclo->count() ? round($ciclo->avg(), 2) : null, 'ciclo_mediana' => $ciclo->count() ? $ciclo[intdiv($ciclo->count(), 2)] : null,
            'valor_funil' => $this->soma($abertas), 'ponderado' => $abertas->reduce(fn ($s, $o) => bcadd($s, $pond($o), 2), '0.00'),
            'por_etapa' => array_map(fn ($e) => ['etapa' => $e['nome'], 'cor' => $e['cor'] ?? null, 'n' => $abertas->where('etapa_codigo', $e['id'])->count(),
                'valor' => $this->soma($abertas->where('etapa_codigo', $e['id'])),
                'ponderado' => $abertas->where('etapa_codigo', $e['id'])->reduce(fn ($s, $o) => bcadd($s, $pond($o), 2), '0.00')], $abertasEt),
            'motivos' => $motivos->all(), 'ticket_medio' => $ganhas->count() ? bcdiv($this->soma($ganhas), (string) $ganhas->count(), 2) : null,
        ];
    }

    private function soma($opps): string
    {
        return $opps->reduce(fn ($s, $o) => bcadd($s, (string) $o->valor, 2), '0.00');
    }
}
