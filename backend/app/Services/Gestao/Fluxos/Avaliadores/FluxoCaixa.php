<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use Illuminate\Support\Facades\DB;

/**
 * Fluxo da folha de caixa (carregarCaixa/avaliarSessao, js/fluxo_tesouraria.js:200-330). Um processo é uma sessão
 * (contas 45). Etapas: abertura → movimentos → fecho e contagem → diferença de caixa → integração na contabilidade.
 * Regras do legado: saldo do sistema = saldo inicial + entradas (REC) − saídas (PAG); o saldo inicial é comparado com o
 * contado no fecho anterior da mesma conta; a diferença (contado − sistema) fica regularizada por uma conferência de caixa
 * FINALIZADA, da mesma conta, entre a abertura e o fecho, com lançamento; movimentos sem conta a débito ou a crédito
 * bloqueiam a contabilização; caixa aberta há mais de um dia e sessão fechada há mais de 3 dias por contabilizar são avisos.
 * Os valores são na moeda da sessão (codigo_moeda), como no legado (caixaFxMoedaSessao).
 */
final class FluxoCaixa extends AvaliadorFluxo
{
    public function temActividade(): bool
    {
        return DB::table('sessoes_caixa')->where('empresa_id', $this->empresa())->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $sessoes = DB::table('sessoes_caixa')->where('empresa_id', $e)->orderBy('codigo_conta')->orderBy('data_abertura')->orderBy('id')->get();
        $mov = DB::table('movimentos_caixa')->where('empresa_id', $e)->groupBy('sessao_caixa_id')
            ->selectRaw("sessao_caixa_id, COUNT(*) AS n, COALESCE(SUM(valor) FILTER (WHERE tipo = 'REC'), 0) AS ent, COALESCE(SUM(valor) FILTER (WHERE tipo = 'PAG'), 0) AS sai,
                COUNT(*) FILTER (WHERE COALESCE(TRIM(conta_debito), '') = '' OR COALESCE(TRIM(conta_credito), '') = '') AS sem_contas,
                (array_agg(COALESCE(numero_documento, descricao, '#' || id::text) ORDER BY id) FILTER (WHERE COALESCE(TRIM(conta_debito), '') = '' OR COALESCE(TRIM(conta_credito), '') = ''))[1:6] AS lista_sem,
                COUNT(*) FILTER (WHERE NOT COALESCE(contabilizado, false)) AS por_integrar")->get()->keyBy('sessao_caixa_id');
        $conf = DB::table('conferencias_caixa')->where('empresa_id', $e)->where('estado', 'FINALIZADO')->orderByDesc('data_conferencia')->get();
        $nomes = DB::table('plano_contas')->where('empresa_id', $e)->whereNull('eliminado_em')->where('codigo', 'like', '45%')->pluck('descricao', 'codigo')
            ->mapWithKeys(fn ($d, $c) => [trim((string) $c) => (string) $d]);
        $processos = [];
        foreach ($sessoes as $i => $s) {
            $ant = ($sessoes[$i - 1] ?? null)?->codigo_conta === $s->codigo_conta ? $sessoes[$i - 1] : null;
            $processos[] = $this->sessao($s, $ant, $mov[$s->id] ?? null, $conf, $nomes[trim((string) $s->codigo_conta)] ?? '');
        }

        return ['processos' => $processos, 'kpis' => [
            self::kpi('abertas', 'Caixas abertas', count(array_filter($processos, fn ($p) => $p['aberta']))),
            self::kpi('por_contabilizar', 'Por contabilizar', $n = count(array_filter($processos, fn ($p) => $p['por_contabilizar'])), 'num', $n > 0),
            self::kpi('diferencas', 'Diferenças por regularizar', $n = count(array_filter($processos, fn ($p) => $p['etapas']['dif']['estado'] === 'curso')), 'num', $n > 0),
            self::kpi('contabilizadas', 'Sessões contabilizadas', count(array_filter($processos, fn ($p) => $p['_concluidas'] === 5 && $p['etapas']['integ']['resumo'] === 'Contabilizada'))),
        ]];
    }

    private function sessao(object $s, ?object $ant, ?object $m, $conferencias, string $nomeConta): array
    {
        $E = [];
        $moeda = $s->codigo_moeda ?: 'AOA';
        $entradas = self::dinheiro($m->ent ?? 0);
        $saidas = self::dinheiro($m->sai ?? 0);
        $n = (int) ($m->n ?? 0);
        $sistema = bcsub(bcadd(self::dinheiro($s->saldo_abertura), $entradas, 2), $saidas, 2);
        $aberta = $s->estado === 'ABERTA';
        $contabilizada = $s->estado === 'CONTABILIZADA';
        $diferenca = $aberta || $s->saldo_fisico === null ? '0.00' : bcsub(self::dinheiro($s->saldo_fisico), $s->saldo_fecho !== null ? self::dinheiro($s->saldo_fecho) : $sistema, 2);
        $hoje = self::hoje();
        $dias = fn ($d) => $d ? (int) floor((strtotime($hoje) - strtotime(substr((string) $d, 0, 10))) / 86400) : 0;
        $saldoAnt = $ant ? self::dinheiro($ant->saldo_fisico ?? $ant->saldo_fecho) : null;
        $E['abert'] = self::feito(self::data($s->data_abertura), array_merge([self::f('Conta', trim("{$s->codigo_conta} {$nomeConta}")), self::f('Operador', $s->operador ?: '—'),
            self::f('Saldo inicial', self::dinheiro($s->saldo_abertura), 'kz')], $ant ? [self::f('Contado no fecho anterior', $saldoAnt, 'kz')] : []), [],
            $ant && $ant->estado !== 'ABERTA' && abs((float) bcsub($saldoAnt, self::dinheiro($s->saldo_abertura), 2)) > 0.01
                ? [self::aviso('O saldo inicial ('.self::kz($s->saldo_abertura).') é diferente do saldo contado no fecho da sessão anterior ('.self::kz($saldoAnt).').')] : []);
        $semContas = (int) ($m->sem_contas ?? 0);
        $arr = fn ($v) => $v ? array_values(array_filter(str_getcsv(trim((string) $v, '{}')), fn ($x) => $x !== '' && $x !== 'NULL')) : [];
        $probContas = $semContas ? [self::erro("{$semContas} movimento(s) sem conta a débito ou a crédito: ".self::lista($arr($m->lista_sem)).'. Não podem ser contabilizados.')] : [];
        $factosMov = [self::f('Movimentos', $n, 'num'), self::f('Entradas', $entradas, 'kz'), self::f('Saídas', $saidas, 'kz'), self::f('Saldo do sistema', $sistema, 'kz'), self::f('Moeda', $moeda)];
        if ($aberta) {
            $d = $dias($s->data_abertura);
            $E['mov'] = self::etapa('curso', "{$n} movimento(s) · caixa aberta", $factosMov,
                array_merge($probContas, $d >= 1 ? [self::aviso('A caixa está aberta desde '.self::data($s->data_abertura)." ({$d} dia(s)). Feche a sessão no fim de cada dia.")] : []),
                [self::accao('Ir para a caixa aberta', 'teso_folha_caixa', true)]);
        } else {
            $E['mov'] = self::feito($n ? "{$n} movimento(s)" : 'Sem movimentos', $factosMov, [], $probContas);
        }
        $E['fecho'] = $aberta ? self::porFazer('Por fechar') : self::feito('Fechada '.self::data($s->data_fecho), [self::f('Data de fecho', $s->data_fecho, 'data'),
            self::f('Saldo do sistema', $s->saldo_fecho !== null ? self::dinheiro($s->saldo_fecho) : $sistema, 'kz'), self::f('Saldo contado', $s->saldo_fisico !== null ? self::dinheiro($s->saldo_fisico) : null, 'kz'),
            self::f('Diferença', $diferenca, 'kz')]);
        if ($aberta) {
            $E['dif'] = self::porFazer('No fecho');
        } elseif (abs((float) $diferenca) <= 0.01) {
            $E['dif'] = self::naoAplica('Sem diferença');
        } else {
            $ini = substr((string) $s->data_abertura, 0, 10);
            $fim = substr((string) ($s->data_fecho ?: $hoje), 0, 10);
            $c = $conferencias->first(fn ($x) => (string) $x->codigo_conta === (string) $s->codigo_conta && substr((string) $x->data_conferencia, 0, 10) >= $ini && substr((string) $x->data_conferencia, 0, 10) <= $fim);
            $tipo = (bccomp($diferenca, '0', 2) > 0 ? 'Sobra' : 'Falta').' de '.self::kz(ltrim($diferenca, '-'));
            $E['dif'] = $c && $c->referencia_lancamento
                ? self::feito("Regularizada ({$c->referencia_lancamento})", [self::f('Diferença', $tipo), self::f('Conferência', $c->data_conferencia, 'data'),
                    self::f('Conta de regularização', trim(($c->conta_regularizacao ?: '—').' '.($c->nome_conta_regularizacao ?? ''))), self::f('Lançamento', $c->referencia_lancamento)])
                : self::etapa('curso', $tipo, array_merge([self::f('Diferença', $tipo)], $c ? [self::f('Conferência', $c->data_conferencia, 'data')] : []),
                    [self::aviso($c ? 'A conferência de '.self::data($c->data_conferencia).' foi finalizada sem lançamento de regularização da '.mb_strtolower($tipo).'.'
                        : "{$tipo} entre o saldo contado e o do sistema, por justificar e regularizar.")], [self::accao('Conferência de caixa', 'teso_gestao_conferencia', true)]);
        }
        $porIntegrar = (int) ($m->por_integrar ?? 0);
        if ($aberta) {
            $E['integ'] = self::porFazer('Depois do fecho');
        } elseif ($semContas && ! $contabilizada) {
            $E['integ'] = self::etapa('bloqueada', 'Contas em falta', [], [self::erro('Corrija as contas dos movimentos antes de contabilizar (etapa Movimentos).')], [self::accao('Abrir sessões', 'teso_folha_caixa', true)]);
        } elseif ($contabilizada) {
            $E['integ'] = $porIntegrar
                ? self::etapa('curso', "{$porIntegrar} por integrar", [self::f('Integrados', ($n - $porIntegrar)." de {$n}")], [self::aviso("A sessão está contabilizada mas {$porIntegrar} movimento(s) não foram integrados.")],
                    [self::accao('Abrir sessões', 'teso_folha_caixa', true)])
                : self::feito('Contabilizada', [self::f('Movimentos integrados', $n, 'num'), self::f('Diário', 'Caixa (CX)')]);
        } elseif (! $n) {
            $E['integ'] = self::naoAplica('Nada a contabilizar');
        } else {
            $d = $dias($s->data_fecho ?: $s->data_abertura);
            $E['integ'] = self::etapa('curso', 'Por contabilizar', [self::f('Movimentos por integrar', $porIntegrar, 'num'), self::f('Entradas', $entradas, 'kz'), self::f('Saídas', $saidas, 'kz')],
                $d > 3 ? [self::aviso("Sessão fechada há {$d} dias e ainda por contabilizar.")] : [], [self::accao('Contabilizar na Folha de Caixa', 'teso_folha_caixa', true)]);
        }

        return [
            'chave' => 'caixa-'.$s->id, 'titulo' => self::data($s->data_abertura), 'subtitulo' => implode(' · ', array_filter([trim("{$s->codigo_conta} {$nomeConta}"), $s->operador])),
            'data' => substr((string) $s->data_abertura, 0, 10).'|'.str_pad((string) $s->id, 8, '0', STR_PAD_LEFT), 'valor' => $sistema, 'valor_pendente' => ltrim($diferenca, '-'), 'etapas' => $E,
            'conta' => (string) $s->codigo_conta, 'moeda' => $moeda, 'aberta' => $aberta, 'por_contabilizar' => $s->estado === 'FECHADA' && $n > 0, 'diferenca' => $diferenca,
            'documentos' => [['tipo' => 'sessao_caixa', 'id' => $s->id, 'numero' => (string) $s->codigo_conta]],
        ] + self::contagem($E);
    }
}
