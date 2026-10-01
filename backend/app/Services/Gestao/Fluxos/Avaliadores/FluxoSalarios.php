<?php

namespace App\Services\Gestao\Fluxos\Avaliadores;

use App\Services\RH\ServicoFolhaSalarial;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo de salários e RH (contexto/avaliar/calcularBuracos, js/fluxo_processos.js:47-357). Um processo é um período de
 * salários (os 12 mais recentes, como o legado). Etapas: dados base → lançar rubricas → fechar cálculo → processar e
 * validar → integrar na contabilidade → liquidar salários e obrigações. Regras do legado:
 *   - dados base: colaboradores e IBAN registado (sem IBAN = aviso);
 *   - integração bloqueada quando, num período VALIDADO por integrar, falta a conta de uma rubrica com valor ou a conta de
 *     sistema «Salários a pagar» (as restantes contas de sistema são avisos); mesma resolução de contas da contabilização
 *     (ServicoFolhaSalarial::contaSistema; a das rubricas reproduz a regra privada contaRubrica);
 *   - liquidação por componente (salários líquidos, INSS, IRT, adiantamentos): devido = |Σ D−C| das linhas do período nas
 *     contas do componente; liquidado = compensado (reconciliacao_codigo) + pago na Tesouraria com o período salarial;
 *   - períodos em falta = meses sem processamento entre o primeiro e o último (todos os períodos).
 * As linhas do período no Diário são as do diário SAL com o período (migradas) ou com o documento SAL<MM><AAAA>/o número de
 * lançamento da contabilização (ADR-036), sem os pares estornados.
 */
final class FluxoSalarios extends AvaliadorFluxo
{
    private const SISTEMA = [['NET_PAY_CREDIT', 'Salários a pagar', 'erro'], ['IRT_CREDIT', 'IRT a pagar', 'aviso'], ['INSS_FUNC_CREDIT', 'INSS do trabalhador a pagar', 'aviso'],
        ['INSS_EMP_DEBIT', 'INSS encargo (gasto)', 'aviso'], ['INSS_EMP_CREDIT', 'INSS patronal a pagar', 'aviso']];

    public function __construct(ContextoEmpresa $contexto, private readonly ServicoFolhaSalarial $folha)
    {
        parent::__construct($contexto);
    }

    public function temActividade(): bool
    {
        return DB::table('periodos_processamento_salarial')->where('empresa_id', $this->empresa())->exists();
    }

    public function avaliar(): array
    {
        $e = $this->empresa();
        $chave = fn ($my) => (fn ($p) => ($p[1] ?? '0000').'-'.str_pad($p[0] ?? '00', 2, '0', STR_PAD_LEFT))(explode('/', (string) $my));
        $todos = DB::table('periodos_processamento_salarial')->where('empresa_id', $e)->whereNotNull('mes_ano')->get()->sortByDesc(fn ($p) => $chave($p->mes_ano))->values();
        $colab = DB::table('colaboradores')->where('empresa_id', $e)->whereNull('eliminado_em')->get(['id', 'nome_completo', 'tipo_organizacao_id', 'avencado'])->keyBy('id');
        $comIban = DB::table('coordenadas_bancarias_colaboradores')->where('empresa_id', $e)->whereRaw("TRIM(COALESCE(iban, '')) <> ''")->pluck('colaborador_id')->flip();
        $mapas = DB::table('mapeamentos_contabeis_rh')->where('empresa_id', $e)->get();
        $sistema = DB::table('mapeamentos_contabeis_sistema_rh')->where('empresa_id', $e)->get();
        $infotipos = DB::table('infotipos_salariais')->where('empresa_id', $e)->pluck('nome', 'id');
        $orgs = DB::table('tipos_organizacao_rh')->where('empresa_id', $e)->pluck('nome', 'id');
        $periodos = $todos->take(12);
        $ids = $periodos->pluck('id');
        $entradas = DB::table('linhas_folha_salarial')->where('empresa_id', $e)->whereIn('periodo_processamento_salarial_id', $ids)
            ->get(['periodo_processamento_salarial_id', 'colaborador_id', 'infotipo_salarial_id', 'valor'])->groupBy('periodo_processamento_salarial_id');
        $sal = DB::table('diarios_contabeis')->where('empresa_id', $e)->where('codigo', 'SAL')->pluck('id');
        $docsSal = $periodos->mapWithKeys(fn ($p) => ['SAL'.str_replace('/', '', (string) $p->mes_ano) => $p->id]);
        $lansSal = $periodos->filter(fn ($p) => $p->numero_lan_contabilizacao)->mapWithKeys(fn ($p) => [$p->numero_lan_contabilizacao => $p->id]);
        $linhas = DB::table('lancamentos_contabeis')->where('empresa_id', $e)->whereIn('diario_id', $sal)->whereNull('estornado_por_id')->whereNull('estorno_de_id')
            ->where(fn ($q) => $q->whereIn('periodo_id', $ids)->orWhereIn('numero_documento', $docsSal->keys())->orWhereIn('numero_lan', $lansSal->keys()))
            ->get(['periodo_id', 'numero_documento', 'numero_lan', 'codigo_conta', 'tipo_dc', 'valor', 'reconciliacao_codigo'])
            ->groupBy(fn ($l) => $ids->contains($l->periodo_id) ? $l->periodo_id : ($lansSal[$l->numero_lan] ?? $docsSal[$l->numero_documento] ?? 0));
        $docsTes = DB::table('documentos_tesouraria')->where('empresa_id', $e)->where('tipo', 'PAGAMENTO')->where('estado', '<>', 'ANULADO')->whereIn('periodo_processamento_salarial_id', $ids)
            ->get(['id', 'referencia', 'periodo_processamento_salarial_id'])->groupBy('periodo_processamento_salarial_id');
        $itensTes = DB::table('itens_documento_tesouraria')->where('empresa_id', $e)->whereIn('documento_tesouraria_id', $docsTes->flatten(1)->pluck('id'))
            ->get(['documento_tesouraria_id', 'codigo_conta', 'tipo_dc', 'valor'])->groupBy('documento_tesouraria_id');
        $contasDe = fn (array $codigos) => $sistema->whereIn('codigo', $codigos)->pluck('numero_conta')->map(fn ($c) => trim((string) $c))->filter()->unique()->values()->all();
        $adiant = $mapas->filter(fn ($m) => preg_match('/adiant/i', (string) ($infotipos[$m->infotipo_salarial_id] ?? '')))->pluck('numero_conta')->map(fn ($c) => trim((string) $c))->filter()->unique()->values()->all();
        $componentes = [['liquido', 'Salários líquidos', $contasDe(['NET_PAY_CREDIT'])], ['ss', 'Segurança social (INSS)', $contasDe(['INSS_FUNC_CREDIT', 'INSS_EMP_CREDIT'])],
            ['irt', 'IRT', $contasDe(['IRT_CREDIT', 'IRT_AVENCADO_CREDIT'])], ['adiant', 'Adiantamentos a regularizar', $adiant]];
        $ctx = compact('colab', 'comIban', 'mapas', 'sistema', 'infotipos', 'orgs', 'componentes');
        $processos = [];
        foreach ($periodos as $p) {
            $docs = $docsTes[$p->id] ?? collect();
            $processos[] = $this->periodo($p, $entradas[$p->id] ?? collect(), $linhas[$p->id] ?? collect(), $docs, $docs->flatMap(fn ($d) => $itensTes[$d->id] ?? collect()), $ctx);
        }
        $buracos = self::buracos($todos);

        return ['processos' => $processos, 'buracos' => $buracos, 'kpis' => array_merge(self::kpisBase($processos, 6, 'Períodos em curso'), [
            self::kpi('periodos_em_falta', 'Períodos em falta', count($buracos['meses']), 'num', count($buracos['meses']) > 0),
        ])];
    }

    /** Meses sem período entre o primeiro e o último (calcularBuracos, fluxo_processos.js:345-357); ignora meses fora de 1–12. */
    public static function buracos(Collection $periodos): array
    {
        $existentes = [];
        foreach ($periodos as $p) {
            [$m, $a] = array_map('intval', array_pad(explode('/', (string) $p->mes_ano), 2, 0));
            if ($a > 1900 && $m >= 1 && $m <= 12) {
                $existentes[$a * 12 + $m - 1] = true;
            }
        }
        if (count($existentes) < 2) {
            return ['meses' => [], 'primeiro' => null, 'ultimo' => null];
        }
        $ordem = array_keys($existentes);
        sort($ordem);
        $txt = fn ($n) => str_pad((string) ($n % 12 + 1), 2, '0', STR_PAD_LEFT).'/'.intdiv($n, 12);
        $meses = [];
        for ($n = $ordem[0] + 1; $n < end($ordem); $n++) {
            if (! isset($existentes[$n])) {
                $meses[] = $txt($n);
            }
        }

        return ['meses' => $meses, 'primeiro' => $txt($ordem[0]), 'ultimo' => $txt(end($ordem))];
    }

    private function periodo(object $p, Collection $entradas, Collection $linhas, Collection $docs, Collection $itensTes, array $ctx): array
    {
        $E = [];
        $nColab = $ctx['colab']->count();
        $aberto = ! $p->estado || $p->estado === 'ABERTO';
        $nomes = fn (Collection $l) => self::lista($l->map(fn ($c) => $c->nome_completo ?: "#{$c->id}"));
        $semIban = $ctx['colab']->reject(fn ($c) => isset($ctx['comIban'][$c->id]));
        $E['base'] = $nColab === 0
            ? self::etapa('fazer', 'Sem colaboradores', [self::f('Colaboradores', 0, 'num')], [self::erro('Registe os colaboradores e os contratos antes de lançar o período.')], [self::accao('Abrir Colaboradores', 'colaboradores', true)])
            : self::feito("{$nColab} colaboradores", [self::f('Colaboradores', $nColab, 'num'), self::f('Com IBAN registado', $nColab - $semIban->count(), 'num')],
                array_merge($semIban->isNotEmpty() ? [self::accao('Registar IBAN', 'bancario', true)] : [], [self::accao('Abrir Colaboradores', 'colaboradores')]),
                $semIban->isNotEmpty() ? [self::aviso($semIban->count().' sem IBAN: '.$nomes($semIban).'. Não impede o cálculo, mas o pagamento por banco fica incompleto.')] : []);
        $comLanc = $entradas->pluck('colaborador_id')->unique()->flip();
        $semLanc = $ctx['colab']->reject(fn ($c) => isset($comLanc[$c->id]));
        $nLanc = $entradas->count();
        if (! $aberto) {
            $E['calc'] = self::feito("{$nLanc} lançamentos", [self::f('Lançamentos', $nLanc, 'num'), self::f('Colaboradores com lançamentos', $comLanc->count(), 'num')], [self::accao('Ver lançamentos', 'calcular')]);
        } elseif (! $nLanc) {
            $E['calc'] = self::etapa('fazer', 'Sem lançamentos', [self::f('Lançamentos', 0, 'num')], [], [self::accao('Lançar em Calcular', 'calcular', true)]);
        } else {
            $E['calc'] = self::etapa('curso', "{$nLanc} lançamentos", [self::f('Lançamentos', $nLanc, 'num'), self::f('Colaboradores com lançamentos', $comLanc->count()." de {$nColab}")],
                $semLanc->isNotEmpty() ? [self::aviso($semLanc->count().' colaborador(es) sem lançamentos: '.$nomes($semLanc).'.')] : [], [self::accao('Continuar em Calcular', 'calcular', true)]);
        }
        $E['fecho'] = $aberto
            ? self::etapa('fazer', $nLanc ? 'Pronto a rever e fechar' : 'Aguarda lançamentos', [self::f('Estado do período', 'Aberto')],
                $nLanc && $semLanc->isNotEmpty() ? [self::aviso('Confirme os colaboradores sem lançamentos antes de fechar o cálculo.')] : [], $nLanc ? [self::accao('Fechar em Calcular', 'calcular', true)] : [])
            : self::feito('Cálculo fechado', [self::f('Estado do período', $p->estado === 'VALIDADO' ? 'Validado' : 'Fechado')]);
        $E['valid'] = match ($p->estado) {
            'VALIDADO' => self::feito('Validado', [self::f('Estado do período', 'Validado')], [self::accao('Ver mapa salarial e recibos', 'processamento')]),
            'FECHADO' => self::etapa('curso', 'Aguarda validação', [self::f('Estado do período', 'Fechado')], [], [self::accao('Rever e validar', 'processamento', true)]),
            default => self::porFazer('Depois de fechar'),
        };
        $integrado = $linhas->isNotEmpty() || (bool) $p->contabilizado;
        if ($integrado) {
            $lans = $linhas->pluck('numero_lan')->filter()->unique()->values();
            $E['integ'] = self::feito($lans->first() ?: 'Integrado', [self::f('Lançamento(s)', $lans->implode(', ') ?: '—'), self::f('Total a débito', self::somar($linhas->where('tipo_dc', 'D'), fn ($l) => $l->valor), 'kz'),
                self::f('Linhas no diário', $linhas->count(), 'num')], $linhas->isNotEmpty() ? [self::accao('Abrir no diário', 'lancamentos', true)] : [self::accao('Abrir Processamentos', 'processamento', true)],
                $linhas->isEmpty() ? [self::aviso('O período está marcado como integrado, mas não tem linhas no diário. Volte a integrar em Processamentos.')] : []);
        } elseif ($p->estado === 'VALIDADO') {
            $faltas = $this->verificarMapeamentos($entradas, $ctx);
            $erros = array_filter($faltas, fn ($f) => $f['nivel'] === 'erro');
            $E['integ'] = $erros
                ? self::etapa('bloqueada', count($erros).' conta(s) em falta', [self::f('Estado', 'Validado, por integrar')], $faltas,
                    [self::accao('Corrigir no Mapeamento Contábil', 'contabilidade', true), self::accao('Abrir Processamentos', 'processamento')])
                : self::etapa('curso', 'Pronto a integrar', [self::f('Estado', 'Validado, por integrar')], $faltas, [self::accao('Integrar em Processamentos', 'processamento', true)]);
        } else {
            $E['integ'] = self::porFazer('Depois de validar');
        }
        $assinado = fn ($l) => $l->tipo_dc === 'D' ? (string) $l->valor : bcmul((string) $l->valor, '-1', 2);
        $refs = self::lista($docs->map(fn ($d) => $d->referencia ?: "TES-{$d->id}"));
        $aplicaveis = [];
        $semContas = [];
        foreach ($ctx['componentes'] as [$id, $nome, $contas]) {
            if (! $contas && $id !== 'adiant') {
                $semContas[] = $nome;
            }
            $lin = $linhas->filter(fn ($l) => in_array(trim((string) $l->codigo_conta), $contas, true));
            $devido = ltrim(self::somar($lin, $assinado), '-');
            $comp = ltrim(self::somar($lin->filter(fn ($l) => $l->reconciliacao_codigo), $assinado), '-');
            $pagoTes = ltrim(self::somar($itensTes->filter(fn ($i) => in_array(trim((string) $i->codigo_conta), $contas, true)), $assinado), '-');
            if (bccomp($devido, '0', 2) > 0) {
                $aplicaveis[] = ['id' => $id, 'nome' => $nome, 'contas' => $contas, 'devido' => $devido, 'comp' => $comp, 'pago' => $pagoTes, 'liquidado' => self::min($devido, bcadd($comp, $pagoTes, 2))];
            }
        }
        $pend = array_filter($aplicaveis, fn ($c) => bccomp($c['liquidado'], bcsub($c['devido'], '0.01', 2), 2) < 0);
        $factosPag = array_map(fn ($c) => self::f($c['nome'], self::kz($c['liquidado']).' de '.self::kz($c['devido']).(bccomp($c['comp'], '0', 2) > 0 ? ' · compensação' : '').(bccomp($c['pago'], '0', 2) > 0 ? ' · pagamento' : '')), $aplicaveis);
        if ($refs) {
            $factosPag[] = self::f('Pagamento(s) na Tesouraria', $refs);
        }
        $avisoContas = $semContas ? [self::aviso('Contas de sistema por mapear: '.implode(', ', $semContas).'. Sem elas não é possível acompanhar a liquidação dessas obrigações.')] : [];
        $porPagar = array_reduce($pend, fn ($s, $c) => bcadd($s, bcsub($c['devido'], $c['liquidado'], 2), 2), '0.00');
        if (! $integrado) {
            $E['pag'] = self::etapa('fazer', 'Depois de integrar', [], $docs->isNotEmpty() ? [self::aviso('Há '.$docs->count()." pagamento(s) associado(s) a este período antes da integração ({$refs}).")] : []);
        } elseif (! $aplicaveis) {
            $E['pag'] = self::etapa('curso', 'Sem obrigações identificadas', [], $avisoContas, [self::accao('Abrir Mapeamento Contábil', 'contabilidade', true)]);
        } elseif (! $pend) {
            $E['pag'] = self::feito('Tudo liquidado', $factosPag, array_merge($docs->isNotEmpty() ? [self::accao('Ver pagamentos na Tesouraria', 'teso_gestao_pagamentos')] : [], [self::accao('Ver extracto de conta', 'relatorios_contabeis')]), $avisoContas);
        } else {
            $feitas = count($aplicaveis) - count($pend);
            $E['pag'] = self::etapa('curso', $feitas ? "{$feitas} de ".count($aplicaveis).' liquidadas' : 'Aguarda liquidação', $factosPag, array_merge(array_values(array_map(fn ($c) => self::aviso(
                "{$c['nome']}: faltam ".self::kz(bcsub($c['devido'], $c['liquidado'], 2)).". Pague na Tesouraria escolhendo o período {$p->mes_ano}, ou compense no extracto da conta ".implode(', ', $c['contas']).'.'), $pend)), $avisoContas),
                [self::accao('Importar pendentes na Tesouraria', 'teso_gestao_pagamentos', true), self::accao('Ver extracto por compensar', 'relatorios_contabeis')]);
        }
        [$m, $a] = array_pad(explode('/', (string) $p->mes_ano), 2, '');

        return [
            'chave' => 'periodo-'.$p->id, 'titulo' => (string) $p->mes_ano, 'subtitulo' => 'Processamento salarial', 'data' => "{$a}-".str_pad($m, 2, '0', STR_PAD_LEFT),
            'valor' => self::somar($linhas->where('tipo_dc', 'D'), fn ($l) => $l->valor), 'valor_pendente' => $porPagar, 'etapas' => $E,
            'documentos' => [['tipo' => 'periodo_salarial', 'id' => $p->id, 'numero' => $p->mes_ano]],
        ] + self::contagem($E);
    }

    /** Verificação prévia dos mapeamentos (verificarMapeamentos, fluxo_processos.js:76-97). */
    private function verificarMapeamentos(Collection $entradas, array $ctx): array
    {
        $faltas = [];
        $orgs = [];
        foreach ($entradas as $l) {
            if ((float) $l->valor == 0.0 || ! ($c = $ctx['colab'][$l->colaborador_id] ?? null)) {
                continue;
            }
            $org = $c->tipo_organizacao_id === null ? null : (int) $c->tipo_organizacao_id;
            $orgs[($c->avencado ? 'a' : 'o').'|'.$org] = [$org, (bool) $c->avencado];
            if (! self::contaRubrica($ctx['mapas'], (int) $l->infotipo_salarial_id, $org, (bool) $c->avencado)) {
                $nome = $ctx['infotipos'][$l->infotipo_salarial_id] ?? "Rubrica #{$l->infotipo_salarial_id}";
                $onde = $c->avencado ? 'Colaborador avençado' : ($ctx['orgs'][$org] ?? "o tipo de órgão {$org}");
                $faltas["R|{$nome}|{$onde}"] = self::erro("Rubrica \"{$nome}\" sem conta contabilística para {$onde}.");
            }
        }
        foreach ($orgs as [$org, $avencado]) {
            foreach (self::SISTEMA as [$codigo, $rotulo, $nivel]) {
                if (! $this->folha->contaSistema($ctx['sistema'], $codigo, $org, $avencado && $codigo === 'NET_PAY_CREDIT')) {
                    $onde = $avencado ? 'Colaborador avençado' : ($ctx['orgs'][$org] ?? "o tipo de órgão {$org}");
                    $faltas["S|{$codigo}|{$onde}"] = ['nivel' => $nivel, 'texto' => "Conta de sistema \"{$rotulo}\" não mapeada para {$onde}."];
                }
            }
        }

        return array_values($faltas);
    }

    /** Mesma regra de ServicoFolhaSalarial::contaRubrica (privada): avençado → mapeamento de avençados; senão o do tipo de organização. */
    private static function contaRubrica(Collection $mapas, int $infotipo, ?int $org, bool $avencado): ?string
    {
        $doInfotipo = $mapas->where('infotipo_salarial_id', $infotipo)->filter(fn ($x) => (string) $x->numero_conta !== '');
        if ($avencado && ($m = $doInfotipo->first(fn ($x) => $x->avencado))) {
            return $m->numero_conta;
        }

        return $doInfotipo->first(fn ($x) => ! $x->avencado && (int) $x->tipo_organizacao_id === (int) $org)?->numero_conta;
    }
}
