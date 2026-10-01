<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Models\ItemAcrescimoDiferimento;
use App\Services\Acrescimos\CalculadoraAcrescimos;
use App\Services\Acrescimos\ServicoItensAcrescimos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo dos acréscimos e diferimentos (avaliarAD, js/fluxo_gestao.js:230-300). Um processo é um registo. Etapas: registo →
 * lançamento inicial (só diferimentos) → reconhecimentos mensais → documento real / fim do período → regularização ou
 * término → conta 37 saldada. O mapa de cada registo (quotas, lançamentos contabilizados, reconhecido e saldo na conta de
 * balanço) é o do módulo (ServicoItensAcrescimos::mapa, ADR-053). Um registo fechado com saldo na conta 37 bloqueia.
 */
final class FluxoAcrescimos extends AvaliadorFluxo
{
    public function __construct(ContextoEmpresa $contexto, private readonly ServicoItensAcrescimos $itens)
    {
        parent::__construct($contexto);
    }

    public function temActividade(): bool
    {
        return DB::table('itens_acrescimos_diferimentos')->where('empresa_id', $this->empresa())->exists();
    }

    public function avaliar(): array
    {
        $mesAct = substr(self::hoje(), 0, 7);
        $hoje = self::hoje();
        $processos = [];
        foreach (ItemAcrescimoDiferimento::query()->orderByDesc('data_inicio')->get() as $it) {
            $mapa = $this->itens->mapa($it);
            $acr = $it->tipo === 'ACRESCIMO';
            $feitos = collect($mapa['lancamentos'])->where('estado', 'CONTABILIZADO');
            $estadoNome = CalculadoraAcrescimos::ESTADOS[$it->estado] ?? $it->estado;
            $E = [];
            $E['reg'] = self::feito((CalculadoraAcrescimos::TIPOS[$it->tipo] ?? $it->tipo).' de '.mb_strtolower(CalculadoraAcrescimos::NATUREZAS[$it->natureza] ?? (string) $it->natureza),
                [self::f('Descrição', $it->descricao), self::f('Valor', self::dinheiro($it->valor), 'kz'), self::f('Período', self::data($it->data_inicio).' a '.self::data($it->data_fim)),
                    self::f('Contas', "{$it->conta_resultado} / {$it->conta_balanco}")], [self::accao('Abrir registos', 'ad_registos')]);
            if ($acr) {
                $E['ini'] = self::feito('Não aplicável (acréscimo)');
            } else {
                $ini = $feitos->firstWhere('tipo', 'INICIAL');
                $E['ini'] = $ini ? self::feito($ini->numero_lan ?: 'Contabilizado', [self::f('Lançamento', $ini->numero_lan ?: '—'), self::f('Data', $ini->data_documento, 'data')])
                    : ($it->estado === 'ANULADO' ? self::feito('Anulado') : self::etapa('curso', 'Por contabilizar', [], [self::aviso('O diferimento inicial ainda não foi contabilizado. Aparece na proposta mensal.')],
                        [self::accao('Proposta mensal', 'ad_propostas', true)]));
            }
            $quotas = $mapa['quotas'];
            $devidas = array_filter($quotas, fn ($q) => $q['periodo'] <= $mesAct);
            $feitasQ = array_filter($quotas, fn ($q) => ! empty($q['lancamento']));
            $atraso = array_values(array_filter($devidas, fn ($q) => empty($q['lancamento']) && $q['periodo'] < $mesAct));
            $final = in_array($it->estado, ['REGULARIZADO', 'CONCLUIDO', 'ANULADO'], true);
            $E['recon'] = count($feitasQ) === count($quotas) || ($final && ! $atraso)
                ? self::feito(count($feitasQ).' de '.count($quotas), [self::f('Reconhecido', $mapa['reconhecido'], 'kz'), self::f('Quotas', count($feitasQ).' de '.count($quotas))])
                : (! $devidas ? self::porFazer('Começa em '.($quotas[0]['periodo'] ?? '—'))
                    : self::etapa('curso', count($feitasQ).' de '.count($quotas), [self::f('Reconhecido', $mapa['reconhecido'], 'kz'), self::f('Quotas vencidas por contabilizar', count($atraso), 'num')],
                        $atraso ? [self::aviso('Quotas de '.implode(', ', array_slice(array_column($atraso, 'periodo'), 0, 6)).(count($atraso) > 6 ? '…' : '').' por contabilizar.')] : [],
                        [self::accao('Proposta mensal', 'ad_propostas', true)]));
            $terminou = (string) $it->data_fim?->toDateString() < $hoje;
            $reg = $it->regularizacao;
            if ($acr) {
                $E['doc'] = $reg || $final
                    ? self::feito(! empty($reg['anulacao']) ? 'Anulado sem documento' : (! empty($reg['doc']) ? "Doc. {$reg['doc']}" : ($it->estado === 'ANULADO' ? 'Anulado' : 'Registado')),
                        $reg ? [self::f('Documento', $reg['doc'] ?? '—'), self::f('Valor real (sem IVA)', self::dinheiro($reg['valor'] ?? 0), 'kz'), self::f('Data', $reg['data'] ?? null, 'data'),
                            self::f('Diferença face ao estimado', bcsub(self::dinheiro($reg['valor'] ?? 0), self::dinheiro($it->valor), 2), 'kz')] : [])
                    : ($terminou ? self::etapa('curso', 'Documento em falta', [], [self::aviso('O período terminou em '.self::data($it->data_fim)
                        .' e ainda não foi ligado o documento real. Recolha-o das compras/vendas/Diário/tesouraria ou regularize manualmente.')],
                        [self::accao('Recolher documentos', 'ad_recolher', true), self::accao('Abrir registos', 'ad_registos')]) : self::porFazer('Até '.self::data($it->data_fim)));
            } else {
                $E['doc'] = $it->termino ? self::feito('Término em '.self::data($it->termino['data'] ?? null), [self::f('Motivo', ($it->termino['motivo'] ?? '') ?: '—')])
                    : ($terminou || $final ? self::feito('Terminou em '.self::data($it->data_fim)) : self::porFazer('Termina em '.self::data($it->data_fim)));
            }
            $E['regul'] = $final ? self::feito($estadoNome)
                : (in_array($it->estado, ['A_REGULARIZAR', 'A_TERMINAR'], true) ? self::etapa('curso', $estadoNome, [], [self::aviso('A regularização/término está registada e aguarda contabilização na proposta mensal.')],
                    [self::accao('Proposta mensal', 'ad_propostas', true)])
                    : (! $acr && count($feitasQ) === count($quotas) ? self::feito('Não aplicável (terminou no prazo)') : self::porFazer($acr ? 'Depois do documento real' : 'Só se terminar antes do prazo')));
            $saldo = self::dinheiro($mapa['saldo_balanco']);
            $zero = bccomp($saldo, '0', 2) === 0;
            $E['saldo'] = $zero && ($final || count($feitasQ) === count($quotas)) ? self::feito('Saldo zero', [self::f('Saldo na conta '.$it->conta_balanco, '0.00', 'kz')])
                : ($final ? self::etapa('bloqueada', 'Saldo '.self::kz($saldo), [self::f('Saldo na conta '.$it->conta_balanco, $saldo, 'kz')],
                    [self::erro('O registo está fechado mas deixa saldo na conta 37. Verifique os lançamentos no mapa do registo.')], [self::accao('Abrir registos', 'ad_registos', true)])
                    : self::etapa($feitos->isNotEmpty() ? 'curso' : 'fazer', 'Saldo '.self::kz($saldo), [self::f('Saldo na conta '.$it->conta_balanco, $saldo, 'kz')]));
            $processos[] = ['chave' => 'acrescimo-'.$it->id, 'titulo' => (string) $it->descricao,
                'subtitulo' => (CalculadoraAcrescimos::TIPOS[$it->tipo] ?? $it->tipo).' · '.(CalculadoraAcrescimos::NATUREZAS[$it->natureza] ?? $it->natureza),
                'data' => $it->data_inicio?->toDateString(), 'valor' => self::dinheiro($it->valor), 'valor_pendente' => ltrim($saldo, '-'), 'etapas' => $E, 'estado' => $it->estado, 'estado_nome' => $estadoNome,
                'quotas_em_atraso' => count($atraso), 'documentos' => [['tipo' => 'acrescimo_diferimento', 'id' => $it->id, 'numero' => $it->descricao]]] + self::contagem($E);
        }

        return ['processos' => $processos, 'kpis' => [
            self::kpi('em_curso', 'Em curso', count(array_filter($processos, fn ($p) => $p['_concluidas'] < 6 && ! $p['_bloqueado']))),
            self::kpi('quotas_atraso', 'Quotas em atraso', $n = count(array_filter($processos, fn ($p) => $p['etapas']['recon']['problemas'])), 'num', $n > 0),
            self::kpi('documento_falta', 'Documento real em falta', $n = count(array_filter($processos, fn ($p) => $p['etapas']['doc']['estado'] === 'curso')), 'num', $n > 0),
            self::kpi('concluidos', 'Concluídos', count(array_filter($processos, fn ($p) => $p['_concluidas'] === 6))),
        ]];
    }
}
