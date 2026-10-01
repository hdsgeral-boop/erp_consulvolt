<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Models\AtividadeComercialCRM;
use App\Models\FunilVendasCRM;
use App\Models\OportunidadeVendaCRM;
use App\Services\CRM\RegrasCRM;
use App\Services\CRM\ServicoConfiguracaoCRM;
use App\Services\CRM\ServicoOportunidadesCRM;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo do CRM (avaliarCRM, js/fluxo_gestao.js:145-225). Um processo é uma oportunidade aberta ou fechada nos últimos 90 dias.
 * Etapas: oportunidade → qualificação e actividades → proposta → negociação e fecho → encomenda ou factura → recebimento.
 * Saúde, probabilidade efectiva e etapa vêm do módulo (ServicoOportunidadesCRM::saude, RegrasCRM, ADR-054); documentos =
 * vendas não anuladas ligadas à oportunidade (OR/PF propostas; NE/FT/FR documentos finais; FT/FR recebidas quando PAGO ou FR).
 * Uma oportunidade perdida termina o processo (conta como concluída e não como bloqueio, como no legado).
 */
final class FluxoCRM extends AvaliadorFluxo
{
    public function __construct(ContextoEmpresa $contexto, private readonly ServicoOportunidadesCRM $oportunidades, private readonly ServicoConfiguracaoCRM $config)
    {
        parent::__construct($contexto);
    }

    public function temActividade(): bool
    {
        return DB::table('oportunidades_venda_crm')->where('empresa_id', $this->empresa())->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $hoje = RegrasCRM::hoje();
        $lim = RegrasCRM::somarDias($hoje, -90);
        $opps = OportunidadeVendaCRM::query()->where(fn ($q) => $q->where('estado', 'ABERTA')->orWhere('fechado_em', '>=', $lim))->get();
        $funis = FunilVendasCRM::query()->get()->keyBy('id');
        $contas = DB::table('contas_crm')->where('empresa_id', $e)->get(['id', 'nome', 'terceiro_id'])->keyBy('id');
        $acts = AtividadeComercialCRM::query()->whereIn('oportunidade_crm_id', $opps->pluck('id'))->get()->groupBy('oportunidade_crm_id');
        $vendas = DB::table('vendas')->where('empresa_id', $e)->whereIn('oportunidade_crm_id', $opps->pluck('id'))->whereRaw("COALESCE(estado, '') !~* '(ANUL|CANCEL)'")
            ->get(['id', 'oportunidade_crm_id', 'tipo_documento', 'numero_documento', 'total_bruto', 'estado', 'data_vencimento'])->groupBy('oportunidade_crm_id');
        $cfg = $this->config->obter();
        $processos = [];
        foreach ($opps as $o) {
            $funil = $funis[$o->funil_vendas_crm_id] ?? null;
            if (! $funil) {
                continue;
            }
            $etapasFunil = $funil->etapas ?? [];
            $conta = $contas[$o->conta_crm_id] ?? null;
            $etapa = RegrasCRM::etapa($etapasFunil, $o->etapa_codigo) ?? [];
            $minhas = $acts[$o->id] ?? collect();
            $feitas = $minhas->where('concluida', true);
            $atrasadas = $minhas->filter(fn ($a) => ! $a->concluida && $a->data_prevista && $a->data_prevista->toDateString() < $hoje);
            $docs = $vendas[$o->id] ?? collect();
            $props = $docs->whereIn('tipo_documento', ['OR', 'PF']);
            $finais = $docs->whereIn('tipo_documento', ['NE', 'FT', 'FR']);
            $facts = $docs->whereIn('tipo_documento', ['FT', 'FR']);
            $saude = $this->oportunidades->saude($o, $etapa ?: null, $minhas->where('concluida', false)->values(), $cfg);
            $perdida = $o->estado === 'PERDIDA';
            $ganha = $o->estado === 'GANHA';
            $ordem = collect($etapasFunil)->search(fn ($x) => ($x['id'] ?? null) === $o->etapa_codigo);
            $ordem = $ordem === false ? -1 : $ordem;
            $prob = (float) RegrasCRM::probabilidade($o->probabilidade !== null ? (string) $o->probabilidade : null, $etapa);
            $E = [];
            $E['lead'] = self::feito($conta->nome ?? 'Conta', [self::f('Conta', $conta->nome ?? '—'), self::f('Tipo', ($conta->terceiro_id ?? null) ? 'Cliente' : 'Prospect'),
                self::f('Valor', self::dinheiro($o->valor), 'kz'), self::f('Origem', $o->origem ?: '—'), self::f('Responsável', $o->responsavel ?: '—')], [self::accao('Abrir pipeline', 'crm_pipeline')]);
            $E['qual'] = $feitas->isNotEmpty() || $ganha || $perdida
                ? self::etapa($atrasadas->isNotEmpty() && ! $ganha && ! $perdida ? 'curso' : 'concluida', $feitas->count().' feita(s)',
                    [self::f('Actividades feitas', $feitas->count(), 'num'), self::f('Pendentes', $minhas->count() - $feitas->count(), 'num'), self::f('Em atraso', $atrasadas->count(), 'num')],
                    $atrasadas->take(5)->map(fn ($a) => self::aviso("{$a->titulo}: prevista ".self::data($a->data_prevista).' ('.($a->responsavel ?: '—').').'))->values()->all(), [self::accao('Agenda comercial', 'crm_agenda')])
                : self::etapa('curso', $minhas->isNotEmpty() ? 'Actividades agendadas' : 'Sem actividades', [self::f('Pendentes', $minhas->count(), 'num')],
                    $saude['nivel'] === 'RISCO' || $minhas->isEmpty() ? [self::aviso(implode('; ', $saude['motivos']) ?: 'Sem próxima actividade agendada.')] : [], [self::accao('Abrir pipeline', 'crm_pipeline', true)]);
            $E['prop'] = $props->isNotEmpty()
                ? self::feito($props->pluck('numero_documento')->implode(', '), [self::f('Proposta(s)', $props->map(fn ($s) => $s->numero_documento.' · '.self::kz($s->total_bruto))->implode('; '))])
                : ($finais->isNotEmpty() || $perdida ? self::naoAplica($perdida ? 'Não emitida (perdida)' : 'Directamente para documento')
                    : self::etapa($ordem >= 1 ? 'curso' : 'fazer', 'Por emitir', [], [], [self::accao('Converter em proposta', 'crm_pipeline', $ordem >= 1)]));
            $E['fecho'] = $ganha ? self::feito('Ganha em '.self::data($o->fechado_em), [self::f('Etapa', $etapa['nome'] ?? 'Ganha'), self::f('Valor', self::dinheiro($o->valor), 'kz')])
                : ($perdida ? self::etapa('bloqueada', 'Perdida', [self::f('Fechada em', $o->fechado_em, 'data'), self::f('Motivo', $o->motivo_perda ?: '—')],
                    [self::erro('Oportunidade perdida'.($o->motivo_perda ? ": {$o->motivo_perda}" : '').'. O processo termina aqui.')])
                    : self::etapa($ordem >= 2 ? 'curso' : 'fazer', ($etapa['nome'] ?? 'Em aberto').' · '.round($prob).'%', [self::f('Etapa', $etapa['nome'] ?? '—'), self::f('Probabilidade', round($prob).'%'),
                        self::f('Fecho previsto', $o->data_fecho_prevista, 'data'), self::f('Saúde', $saude['nivel'] === 'RISCO' ? 'Em risco' : ($saude['nivel'] === 'ATENCAO' ? 'Atenção' : 'Normal'))],
                        $saude['nivel'] !== 'OK' ? [['nivel' => $saude['nivel'] === 'RISCO' ? 'erro' : 'aviso', 'texto' => implode('; ', $saude['motivos']) ?: 'Oportunidade a precisar de atenção.']] : [],
                        [self::accao('Abrir pipeline', 'crm_pipeline', true)]));
            $porReceber = '0.00';
            if ($perdida) {
                $E['doc'] = self::porFazer('Não aplicável (perdida)');
                $E['rec'] = self::porFazer('Não aplicável (perdida)');
            } else {
                $E['doc'] = $finais->isNotEmpty()
                    ? self::feito($finais->pluck('numero_documento')->implode(', '), [self::f('Documento(s)', $finais->map(fn ($s) => "{$s->tipo_documento} {$s->numero_documento} · ".self::kz($s->total_bruto))->implode('; '))],
                        [self::accao('Abrir faturação', 'vendas_faturacao')])
                    : ($ganha ? self::etapa('curso', 'Por emitir', [], [self::aviso('Oportunidade ganha sem encomenda nem factura ligada. Converta-a no pipeline.')], [self::accao('Converter', 'crm_pipeline', true)])
                        : self::porFazer('Depois de ganhar'));
                if ($facts->isEmpty()) {
                    $E['rec'] = self::porFazer($finais->isNotEmpty() ? 'Depois de facturar' : 'Depois do documento');
                } else {
                    $porPagar = $facts->reject(fn ($s) => $s->estado === 'PAGO' || $s->tipo_documento === 'FR');
                    $porReceber = self::somar($porPagar, fn ($s) => $s->total_bruto);
                    $E['rec'] = $porPagar->isEmpty()
                        ? self::feito('Recebido', [self::f('Facturas', $facts->count(), 'num'), self::f('Valor', self::somar($facts, fn ($s) => $s->total_bruto), 'kz')])
                        : self::etapa('curso', $porPagar->count().' por receber', [self::f('Por receber', $porReceber, 'kz')], $porPagar->map(fn ($s) => self::aviso("{$s->numero_documento}: ".self::kz($s->total_bruto)
                            .($s->data_vencimento ? ' · vence '.self::data($s->data_vencimento) : '').($s->data_vencimento && $s->data_vencimento < $hoje ? ' (vencida)' : '').'.'))->values()->all(),
                            [self::accao('Registar recebimento', 'teso_gestao_recebimentos', true), self::accao('Fluxo de vendas', 'fluxo_processos')]);
                }
            }
            $c = self::contagem($E);
            if ($perdida) {
                $c = ['_concluidas' => 6, '_bloqueado' => false];
            }
            $processos[] = ['chave' => 'oportunidade-'.$o->id, 'titulo' => (string) $o->titulo, 'subtitulo' => (string) ($conta->nome ?? ''),
                'data' => ($o->atualizado_em ?? $o->criado_em)?->format('Y-m-d H:i:s'), 'valor' => self::dinheiro($o->valor), 'valor_pendente' => $porReceber, 'etapas' => $E,
                'estado' => $o->estado, 'saude' => $saude['nivel'], 'perdida' => $perdida, 'ganha' => $ganha, 'terminado' => $perdida,
                'documentos' => array_merge([['tipo' => 'oportunidade_crm', 'id' => $o->id, 'numero' => $o->titulo]], $docs->map(fn ($s) => ['tipo' => 'venda', 'id' => $s->id, 'tipo_documento' => $s->tipo_documento, 'numero' => $s->numero_documento])->values()->all()),
            ] + $c;
        }
        usort($processos, fn ($a, $b) => strcmp((string) $b['data'], (string) $a['data']));
        $abertas = array_filter($processos, fn ($p) => $p['estado'] === 'ABERTA');

        return ['processos' => $processos, 'kpis' => [
            self::kpi('em_aberto', 'Em aberto', count($abertas)),
            self::kpi('pipeline', 'Pipeline em aberto', array_reduce($abertas, fn ($s, $p) => bcadd($s, $p['valor'], 2), '0.00'), 'kz'),
            self::kpi('em_risco', 'Em risco', $n = count(array_filter($abertas, fn ($p) => $p['saude'] === 'RISCO')), 'num', $n > 0),
            self::kpi('ganhas_sem_recebimento', 'Ganhas sem recebimento', count(array_filter($processos, fn ($p) => $p['ganha'] && $p['etapas']['rec']['estado'] !== 'concluida'))),
        ]];
    }
}
