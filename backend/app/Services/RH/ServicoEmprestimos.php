<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ConfiguracaoEmprestimo;
use App\Models\ContratoTrabalho;
use App\Models\DiarioContabil;
use App\Models\EmprestimoColaborador;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\PedidoPortalColaborador;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PrestacaoEmprestimo;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Empréstimos a colaboradores (Versão Actual: js/modules/rh/emprestimos.js; ecrã emprestimos_ui.js; portal_ui.js:398).
 * Percurso: pedido no Portal (capital, prestações, mês de início, motivo) → circuito do portal (chefia, se a configuração
 * o exigir, e RH) → aprovado gera o PLANO (prestação constante, sistema francês) → o RH lança o capital na conta corrente
 * do colaborador → «Importar Empréstimos» traz as prestações do mês como desconto no processamento.
 * Regras (paridade):
 *   - i = taxa anual / 100 / 12; prestação = C·i / (1 − (1+i)^−n); com i = 0, prestação = C/n; juro do mês = saldo × i;
 *     amortização = prestação − juro; a ÚLTIMA prestação fecha o saldo ao cêntimo (plano :75);
 *   - n ≤ prestacoes_max; capital ≤ multiplo_salario_max × salário base do contrato; sem salário apurável o pedido é
 *     recusado (:116); só um empréstimo em curso de cada vez (:127);
 *   - desconto = lançamento na rubrica infotipo_desconto_id com o total da prestação; a prestação passa a DESCONTADO e o
 *     empréstimo a LIQUIDADO quando já não há prestações por descontar (:229);
 *   - conta corrente: D conta_emprestimo / C conta_contrapartida pelo capital, no diário configurado (obrigatório), com
 *     o terceiro do colaborador (pelo NIF) (:281);
 *   - cancelar só sem prestações descontadas (:310).
 * Melhorias:
 *   - plano em bcmath; tudo no servidor numa transacção, com bloqueios (o legado gravava no navegador e numerava
 *     «EMP AAAA/NNN» contando os registos — dois pedidos ao mesmo tempo ficavam com o mesmo número);
 *   - o mês de início não pode estar no passado; a rubrica de desconto tem de ser um DESCONTO e as contas têm de ser de
 *     movimento; a conta corrente usa o ServicoLancamentos (exercício aberto, equilíbrio, numeração);
 *   - o mês das prestações (AAAA-MM) é comparado com o mês do processamento (MM/AAAA) — o legado comparava os dois
 *     formatos e não encontrava prestações;
 *   - retirar o lançamento do desconto no processamento (ou anular as prestações do período) devolve a prestação a
 *     «por descontar» e reabre o empréstimo liquidado (no legado ficava descontada sem lançamento).
 */
final class ServicoEmprestimos
{
    public const PADRAO = ['conta_emprestimo' => '', 'conta_contrapartida' => '', 'diario_id' => null, 'infotipo_desconto_id' => null,
        'prestacoes_max' => 12, 'multiplo_salario_max' => '3', 'taxa_juro_padrao' => '0', 'exige_chefia' => true];

    public const ORIGEM_LANCAMENTO = 'EMPRESTIMO';

    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly ServicoPlanoContas $planoContas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Configuração ─────────────

    public function definicoes(): array
    {
        $r = ConfiguracaoEmprestimo::query()->orderBy('id')->first();
        $d = self::PADRAO;
        foreach (array_keys(self::PADRAO) as $k) {
            if ($r && $r->{$k} !== null) {
                $d[$k] = $r->{$k};
            }
        }

        return ['id' => $r?->id, 'conta_emprestimo' => (string) $d['conta_emprestimo'], 'conta_contrapartida' => (string) $d['conta_contrapartida'],
            'diario_id' => $d['diario_id'] ? (int) $d['diario_id'] : null, 'infotipo_desconto_id' => $d['infotipo_desconto_id'] ? (int) $d['infotipo_desconto_id'] : null,
            'prestacoes_max' => max(1, (int) $d['prestacoes_max']), 'multiplo_salario_max' => number_format((float) $d['multiplo_salario_max'], 2, '.', ''),
            'taxa_juro_padrao' => number_format((float) $d['taxa_juro_padrao'], 4, '.', ''), 'exige_chefia' => (bool) $d['exige_chefia'],
            'por_configurar' => $this->porConfigurar($d)];
    }

    /** @param  array<string, mixed>  $d  dados validados */
    public function gravarDefinicoes(array $d): array
    {
        foreach (['conta_emprestimo' => 'A conta dos empréstimos', 'conta_contrapartida' => 'A conta de contrapartida'] as $k => $rotulo) {
            if (! empty($d[$k])) {
                try {
                    $this->planoContas->contaDeMovimento((string) $d[$k]);
                } catch (ErroNegocio $e) {
                    throw new ErroNegocio("{$rotulo}: {$e->getMessage()}", $e->codigo, 422);
                }
            }
        }
        if (! empty($d['conta_emprestimo']) && ($d['conta_emprestimo'] ?? null) === ($d['conta_contrapartida'] ?? null)) {
            throw new ErroNegocio('A conta dos empréstimos e a de contrapartida têm de ser diferentes.', 'CONTAS_IGUAIS', 422);
        }
        if (! empty($d['infotipo_desconto_id']) && InfotipoSalarial::query()->find($d['infotipo_desconto_id'])?->tipo !== 'DESCONTO') {
            throw new ErroNegocio('A rubrica de desconto dos empréstimos tem de ser uma rubrica do tipo DESCONTO.', 'RUBRICA_NAO_DESCONTO', 422);
        }
        if (! empty($d['diario_id'])) {
            DiarioContabil::query()->findOrFail($d['diario_id']);
        }
        DB::transaction(function () use ($d) {
            $r = ConfiguracaoEmprestimo::query()->orderBy('id')->lockForUpdate()->first();
            $dados = ['conta_emprestimo' => $d['conta_emprestimo'] ?? null, 'conta_contrapartida' => $d['conta_contrapartida'] ?? null, 'diario_id' => $d['diario_id'] ?? null,
                'infotipo_desconto_id' => $d['infotipo_desconto_id'] ?? null, 'prestacoes_max' => (int) $d['prestacoes_max'],
                'multiplo_salario_max' => (string) $d['multiplo_salario_max'], 'taxa_juro_padrao' => (string) $d['taxa_juro_padrao'], 'exige_chefia' => (bool) $d['exige_chefia']];
            $r ? $r->update($dados) : ConfiguracaoEmprestimo::create($dados);
        });

        return $this->definicoes();
    }

    /** O que falta configurar antes de se poder trabalhar com empréstimos (porConfigurar, emprestimos.js:62). @return list<string> */
    private function porConfigurar(array $d): array
    {
        return array_values(array_filter([
            empty($d['conta_emprestimo']) ? 'a conta de empréstimos a colaboradores (conta corrente)' : null,
            empty($d['conta_contrapartida']) ? 'a conta de contrapartida do pagamento' : null,
            empty($d['diario_id']) ? 'o diário onde o empréstimo é integrado' : null,
            empty($d['infotipo_desconto_id']) ? 'a rubrica de desconto usada no cálculo salarial' : null,
        ]));
    }

    // ───────────── Plano de amortização ─────────────

    /**
     * Plano de prestação constante (plano, emprestimos.js:75), em bcmath.
     *
     * @return array{prestacao: string, linhas: list<array{numero: int, mes_ano: string, capital: string, juro: string, total: string, saldo: string}>, total_juros: string, total: string}
     */
    public static function plano(mixed $capital, int $prestacoes, mixed $taxaAnual, string $inicio): array
    {
        $c = self::r2((string) $capital);
        $n = max(1, $prestacoes);
        $i = bcdiv(self::n($taxaAnual), '1200', 12);
        if (bccomp($i, '0', 12) > 0) {
            $fator = bcpow(bcadd('1', $i, 12), (string) $n, 12);
            $prestacao = self::r2(bcdiv(bcmul($c, bcmul($i, $fator, 12), 12), bcsub($fator, '1', 12), 12));   // C·i / (1 − (1+i)^−n)
        } else {
            $prestacao = self::r2(bcdiv($c, (string) $n, 12));
        }
        $saldo = $c;
        $linhas = [];
        $juros = $total = '0.00';
        for ($k = 1; $k <= $n; $k++) {
            $juro = bccomp($i, '0', 12) > 0 ? self::r2(bcmul($saldo, $i, 12)) : '0.00';
            $amort = bcsub($prestacao, $juro, 2);
            $valor = $prestacao;
            if ($k === $n || bccomp($amort, $saldo, 2) > 0) {   // a última fecha o saldo ao cêntimo
                $amort = $saldo;
                $valor = bcadd($amort, $juro, 2);
            }
            $saldo = bcsub($saldo, $amort, 2);
            $linhas[] = ['numero' => $k, 'mes_ano' => self::mesMais($inicio, $k - 1), 'capital' => $amort, 'juro' => $juro, 'total' => $valor,
                'saldo' => bccomp($saldo, '0', 2) < 0 ? '0.00' : $saldo];
            $juros = bcadd($juros, $juro, 2);
            $total = bcadd($total, $valor, 2);
            if ($k < $n && bccomp($saldo, '0', 2) <= 0) {
                break;
            }
        }

        return ['prestacao' => $prestacao, 'linhas' => $linhas, 'total_juros' => $juros, 'total' => $total];
    }

    public static function mesMais(string $mes, int $k): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $mes, $m)) {
            throw new ErroNegocio('Mês inválido (use AAAA-MM).', 'MES_INVALIDO', 422);
        }
        $t = (int) $m[1] * 12 + (int) $m[2] - 1 + $k;

        return sprintf('%04d-%02d', intdiv($t, 12), $t % 12 + 1);
    }

    // ───────────── Pedido (portal) e decisão ─────────────

    /** Salário base do contrato em vigor no mês corrente (salarioBase, emprestimos.js:144). */
    public function salarioBase(int $colaborador): string
    {
        $contratos = ContratoTrabalho::query()->where('colaborador_id', $colaborador)->get();
        $hoje = now()->toDateString();

        return RegrasContrato::salarioBase(RegrasContrato::emVigor($contratos, $hoje, $hoje));
    }

    /** Limites que o portal mostra ao colaborador (tecto pelo salário, prestações, juro). */
    public function limites(int $colaborador): array
    {
        $d = $this->definicoes();
        $base = $this->salarioBase($colaborador);

        return ['prestacoes_max' => $d['prestacoes_max'], 'multiplo_salario_max' => $d['multiplo_salario_max'], 'taxa_juro' => $d['taxa_juro_padrao'],
            'salario_base' => $base, 'tecto' => bccomp($d['multiplo_salario_max'], '0', 2) > 0 ? bcmul($base, $d['multiplo_salario_max'], 2) : null,
            'por_configurar' => $d['por_configurar'], 'exige_chefia' => $d['exige_chefia'],
            'em_curso' => EmprestimoColaborador::query()->where('colaborador_id', $colaborador)->whereIn('estado', EmprestimoColaborador::EM_CURSO)->value('numero')];
    }

    /**
     * Valida o pedido e cria o empréstimo pendente (pedir, emprestimos.js:99). Chamado dentro da transacção do pedido
     * do portal (ServicoPortalColaborador::criar), com o colaborador bloqueado.
     *
     * @param  array{capital: mixed, prestacoes: mixed, inicio_desconto: string, motivo: ?string}  $d
     */
    public function criarPedido(Colaborador $c, array $d, string $estado): EmprestimoColaborador
    {
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["emprestimos:{$c->id}"]);
        $cfg = $this->definicoes();
        if ($cfg['por_configurar']) {
            throw new ErroNegocio('Os empréstimos ainda não estão configurados: falta '.implode(', ', $cfg['por_configurar']).'. Fale com o RH.', 'EMPRESTIMOS_POR_CONFIGURAR', 422);
        }
        $capital = self::r2((string) ($d['capital'] ?? 0));
        $n = (int) ($d['prestacoes'] ?? 0);
        $inicio = trim((string) ($d['inicio_desconto'] ?? ''));
        if (bccomp($capital, '0', 2) <= 0) {
            throw new ErroNegocio('Indique o valor do empréstimo.', 'CAPITAL_INVALIDO', 422);
        }
        if ($n < 1) {
            throw new ErroNegocio('Indique o número de prestações.', 'PRESTACOES_INVALIDAS', 422);
        }
        if ($n > $cfg['prestacoes_max']) {
            throw new ErroNegocio("O máximo são {$cfg['prestacoes_max']} prestações.", 'PRESTACOES_ACIMA_DO_MAXIMO', 422);
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $inicio)) {
            throw new ErroNegocio('Indique o mês em que começa o desconto.', 'MES_INVALIDO', 422);
        }
        if ($inicio < now()->format('Y-m')) {
            throw new ErroNegocio('O desconto não pode começar num mês que já passou.', 'MES_NO_PASSADO', 422);
        }
        if (mb_strlen(trim((string) ($d['motivo'] ?? ''))) < 5) {
            throw new ErroNegocio('Explique brevemente o motivo do pedido.', 'MOTIVO_EM_FALTA', 422);
        }
        if (bccomp($cfg['multiplo_salario_max'], '0', 2) > 0) {
            $base = $this->salarioBase($c->id);
            if (bccomp($base, '0', 2) <= 0) {
                throw new ErroNegocio('Não foi possível apurar o seu salário base (contrato activo em falta ou sem remunerações). Fale com o RH.', 'SEM_SALARIO_BASE', 422);
            }
            $tecto = bcmul($base, $cfg['multiplo_salario_max'], 2);
            if (bccomp($capital, $tecto, 2) > 0) {
                throw new ErroNegocio("O valor excede o limite de {$cfg['multiplo_salario_max']}× o salário base (máximo {$tecto}).", 'CAPITAL_ACIMA_DO_LIMITE', 422, ['tecto' => $tecto]);
            }
        }
        if (EmprestimoColaborador::query()->where('colaborador_id', $c->id)->whereIn('estado', EmprestimoColaborador::EM_CURSO)->exists()) {
            throw new ErroNegocio('Já tem um empréstimo em curso ou por aprovar. Liquide-o antes de pedir outro.', 'EMPRESTIMO_EM_CURSO', 422);
        }
        $p = self::plano($capital, $n, $cfg['taxa_juro_padrao'], $inicio);

        return EmprestimoColaborador::create(['colaborador_id' => $c->id, 'numero' => $this->proximoNumero(), 'capital' => $capital, 'prestacoes' => $n,
            'taxa_juro' => $cfg['taxa_juro_padrao'], 'valor_prestacao' => $p['prestacao'], 'total_juros' => $p['total_juros'], 'total_a_pagar' => $p['total'],
            'inicio_desconto' => $inicio, 'motivo' => trim((string) $d['motivo']), 'estado' => $estado, 'criado_por' => Auth::user()?->nome_utilizador]);
    }

    /** Efeito da decisão do portal no empréstimo (aoDecidir, emprestimos.js:163) — na transacção da decisão. */
    public function aoDecidir(PedidoPortalColaborador $pedido, string $estado): void
    {
        $e = $pedido->emprestimo_id ? EmprestimoColaborador::query()->lockForUpdate()->find($pedido->emprestimo_id) : null;
        if (! $e) {
            return;
        }
        if (in_array($estado, ['RECUSADO', 'CANCELADO'], true)) {
            $e->forceFill(['estado' => $estado, 'decidido_em' => now()] + ($estado === 'CANCELADO' ? ['cancelado_em' => now(), 'cancelado_por' => Auth::user()?->nome_utilizador] : []))->save();

            return;
        }
        if (str_starts_with($estado, 'PENDENTE')) {
            $e->update(['estado' => $estado]);

            return;
        }
        if ($estado === 'APROVADO') {
            $this->gerarPlano($e);
            $e->forceFill(['estado' => 'APROVADO', 'aprovado_em' => now(), 'aprovado_por' => Auth::user()?->nome_utilizador, 'decidido_em' => now()])->save();
            $this->auditoria->registar('RH', 'Aprovar empréstimo', "{$e->numero}: {$e->capital} em {$e->prestacoes} prestações", 'emprestimos_colaboradores', $e->id);
        }
    }

    /** Gera (ou refaz) o plano — só enquanto nenhuma prestação estiver descontada (gerarPlano, emprestimos.js:181). */
    public function gerarPlano(EmprestimoColaborador $e): array
    {
        if (PrestacaoEmprestimo::query()->where('emprestimo_id', $e->id)->where('estado', 'DESCONTADO')->exists()) {
            throw new ErroNegocio('O plano já tem prestações descontadas e não pode ser refeito.', 'PLANO_COM_DESCONTOS', 422);
        }
        PrestacaoEmprestimo::query()->where('emprestimo_id', $e->id)->delete();
        $p = self::plano($e->capital, (int) $e->prestacoes, $e->taxa_juro ?? 0, (string) $e->inicio_desconto);
        foreach ($p['linhas'] as $l) {
            PrestacaoEmprestimo::create($l + ['emprestimo_id' => $e->id, 'colaborador_id' => $e->colaborador_id, 'estado' => 'PENDENTE']);
        }
        $e->update(['valor_prestacao' => $p['prestacao'], 'total_juros' => $p['total_juros'], 'total_a_pagar' => $p['total']]);

        return $p;
    }

    // ───────────── Consultas ─────────────

    /** @return array<string, mixed> */
    public function detalhe(EmprestimoColaborador $e): array
    {
        $ps = PrestacaoEmprestimo::query()->where('emprestimo_id', $e->id)->orderBy('numero')->get();

        return $e->toArray() + ['nome' => Colaborador::query()->withTrashed()->find($e->colaborador_id)?->nome_completo,
            'prestacoes_plano' => $ps->toArray(), 'em_divida' => self::emDivida($ps), 'descontadas' => $ps->where('estado', 'DESCONTADO')->count()];
    }

    /** Saldo em dívida = soma das prestações por descontar (saldo, emprestimos.js:222). */
    private static function emDivida($prestacoes): string
    {
        return $prestacoes->where('estado', 'PENDENTE')->reduce(fn (string $s, $p) => bcadd($s, (string) $p->total, 2), '0.00');
    }

    /** @return list<array<string, mixed>> */
    public function listar(?string $estado = null, ?int $colaborador = null): array
    {
        $lista = EmprestimoColaborador::query()->when($estado, fn ($q, $x) => $q->where('estado', $x))->when($colaborador, fn ($q, $x) => $q->where('colaborador_id', $x))
            ->orderByDesc('id')->get();
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $lista->pluck('colaborador_id')->unique()->all())->pluck('nome_completo', 'id');
        $prest = PrestacaoEmprestimo::query()->whereIn('emprestimo_id', $lista->pluck('id')->all())->get()->groupBy('emprestimo_id');

        return $lista->map(fn ($e) => $e->toArray() + ['nome' => $nomes[$e->colaborador_id] ?? null, 'em_divida' => self::emDivida($prest[$e->id] ?? collect()),
            'descontadas' => ($prest[$e->id] ?? collect())->where('estado', 'DESCONTADO')->count()])->all();
    }

    /** Prestações por descontar no mês do processamento (prestacoesDoMes, emprestimos.js:206). */
    public function prestacoesDoMes(string $mesAno): array
    {
        $mes = ServicoCalendarioRH::mes($mesAno);
        $aprovados = EmprestimoColaborador::query()->where('estado', 'APROVADO')->get()->keyBy('id');
        $ps = PrestacaoEmprestimo::query()->where('mes_ano', $mes)->where('estado', 'PENDENTE')->whereIn('emprestimo_id', $aprovados->keys()->all() ?: [0])->get();
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $ps->pluck('colaborador_id')->unique()->all())->pluck('nome_completo', 'id');

        return $ps->map(fn ($p) => $p->toArray() + ['nome' => $nomes[$p->colaborador_id] ?? "#{$p->colaborador_id}",
            'numero_emprestimo' => $aprovados[$p->emprestimo_id]->numero, 'prestacoes' => $aprovados[$p->emprestimo_id]->prestacoes])
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    // ───────────── Desconto no processamento ─────────────

    /**
     * «Importar Empréstimos» (lancarNoPeriodo, emprestimos.js:229; app_v2.js:5406).
     *
     * @return array{lancados: int, actualizados: int, ignorados: int, total: string, liquidados: list<string>}
     */
    public function lancarNoPeriodo(PeriodoProcessamentoSalarial $p, bool $substituir = false): array
    {
        $cfg = $this->definicoes();
        $rubrica = $cfg['infotipo_desconto_id'] ? InfotipoSalarial::query()->find($cfg['infotipo_desconto_id']) : null;
        if (! $rubrica) {
            throw new ErroNegocio('Configure primeiro a rubrica de desconto dos empréstimos (RH › Empréstimos › Configurações).', 'RUBRICA_EM_FALTA', 422);
        }
        if ($rubrica->tipo !== 'DESCONTO') {
            throw new ErroNegocio("A rubrica {$rubrica->nome} não é um desconto.", 'RUBRICA_NAO_DESCONTO', 422);
        }

        return DB::transaction(function () use ($p, $substituir, $rubrica) {
            $p = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->estado !== 'ABERTO') {
                throw new ErroNegocio("O processamento de {$p->mes_ano} não está aberto.", 'PERIODO_ESTADO_INVALIDO', 422);
            }
            $res = ['lancados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'total' => '0.00', 'liquidados' => []];
            $mes = ServicoCalendarioRH::mes($p->mes_ano);
            $aprovados = EmprestimoColaborador::query()->where('estado', 'APROVADO')->lockForUpdate()->get()->keyBy('id');
            $ps = PrestacaoEmprestimo::query()->where('mes_ano', $mes)->where('estado', 'PENDENTE')->whereIn('emprestimo_id', $aprovados->keys()->all() ?: [0])
                ->lockForUpdate()->get();
            foreach ($ps as $pr) {
                $linha = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->where('colaborador_id', $pr->colaborador_id)
                    ->where('infotipo_salarial_id', $rubrica->id)->first();
                if ($linha && ! $substituir) {
                    $res['ignorados']++;

                    continue;
                }
                if ($linha) {
                    $linha->update(['valor' => (string) $pr->total, 'horas' => null, 'origem' => self::ORIGEM_LANCAMENTO]);
                    $res['actualizados']++;
                } else {
                    $linha = LinhaFolhaSalarial::create(['periodo_processamento_salarial_id' => $p->id, 'colaborador_id' => $pr->colaborador_id,
                        'infotipo_salarial_id' => $rubrica->id, 'valor' => (string) $pr->total, 'origem' => self::ORIGEM_LANCAMENTO]);
                    $res['lancados']++;
                }
                $pr->forceFill(['estado' => 'DESCONTADO', 'linha_folha_salarial_id' => $linha->id, 'periodo_processamento_salarial_id' => $p->id,
                    'descontado_em' => now(), 'descontado_por' => Auth::user()?->nome_utilizador])->save();
                $res['total'] = bcadd($res['total'], (string) $pr->total, 2);
            }
            foreach ($ps->pluck('emprestimo_id')->unique() as $id) {
                if (! PrestacaoEmprestimo::query()->where('emprestimo_id', $id)->where('estado', 'PENDENTE')->exists()) {
                    $aprovados[$id]->forceFill(['estado' => 'LIQUIDADO', 'liquidado_em' => now()])->save();
                    $res['liquidados'][] = $aprovados[$id]->numero;
                }
            }
            $this->auditoria->registar('RH', 'Importar empréstimos no processamento', "{$p->mes_ano}: {$res['lancados']} lançados, {$res['actualizados']} actualizados, total {$res['total']}",
                'periodos_processamento_salarial', $p->id);

            return $res;
        });
    }

    /** Desfaz o desconto do período (anularDoPeriodo, emprestimos.js:264): retira os lançamentos e repõe as prestações. */
    public function anularDoPeriodo(PeriodoProcessamentoSalarial $p): int
    {
        return DB::transaction(function () use ($p) {
            $p = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->estado !== 'ABERTO') {
                throw new ErroNegocio("O processamento de {$p->mes_ano} não está aberto: reabra-o primeiro.", 'PERIODO_ESTADO_INVALIDO', 422);
            }
            $linhas = PrestacaoEmprestimo::query()->where('periodo_processamento_salarial_id', $p->id)->where('estado', 'DESCONTADO')->pluck('linha_folha_salarial_id')->filter()->all();
            $n = self::libertarPrestacoes($linhas, $p->id);
            LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->whereKey($linhas ?: [0])->delete();
            $this->auditoria->registar('RH', 'Anular empréstimos do processamento', "{$p->mes_ano}: {$n} prestação(ões) repostas", 'periodos_processamento_salarial', $p->id);

            return $n;
        });
    }

    /**
     * As prestações descontadas por estes lançamentos voltam a «por descontar» (o lançamento foi retirado do processamento)
     * e os empréstimos liquidados por elas voltam a APROVADO. Chamado por ServicoFolhaSalarial ao retirar lançamentos.
     */
    public static function libertarPrestacoes(array $linhas, ?int $periodo = null): int
    {
        $q = PrestacaoEmprestimo::query()->where('estado', 'DESCONTADO')->where(fn ($q) => $q->whereIn('linha_folha_salarial_id', $linhas ?: [0])
            ->when($periodo, fn ($q) => $q->orWhere(fn ($q) => $q->where('periodo_processamento_salarial_id', $periodo)->whereNull('linha_folha_salarial_id'))));
        $ps = $q->get();
        if ($ps->isEmpty()) {
            return 0;
        }
        $ps->each(fn ($p) => $p->forceFill(['estado' => 'PENDENTE', 'linha_folha_salarial_id' => null, 'periodo_processamento_salarial_id' => null,
            'descontado_em' => null, 'descontado_por' => null])->save());
        EmprestimoColaborador::query()->whereKey($ps->pluck('emprestimo_id')->unique()->all())->where('estado', 'LIQUIDADO')->get()
            ->each(fn ($e) => $e->forceFill(['estado' => 'APROVADO', 'liquidado_em' => null])->save());

        return $ps->count();
    }

    // ───────────── Conta corrente e cancelamento ─────────────

    /** D conta_emprestimo / C conta_contrapartida pelo capital (lancarContaCorrente, emprestimos.js:281). */
    public function lancarContaCorrente(EmprestimoColaborador $e, ?string $data = null, ?int $diarioId = null): EmprestimoColaborador
    {
        $cfg = $this->definicoes();
        if (! $cfg['conta_emprestimo'] || ! $cfg['conta_contrapartida']) {
            throw new ErroNegocio('Configure as contas dos empréstimos antes de lançar.', 'CONTAS_EM_FALTA', 422);
        }
        $diario = DiarioContabil::query()->find($diarioId ?? $cfg['diario_id'])
            ?? throw new ErroNegocio('Indique o diário onde o empréstimo é integrado, em RH › Empréstimos › Configurações.', 'DIARIO_EM_FALTA', 422);

        return DB::transaction(function () use ($e, $data, $diario, $cfg) {
            $e = EmprestimoColaborador::query()->lockForUpdate()->findOrFail($e->id);
            if (! in_array($e->estado, ['APROVADO', 'LIQUIDADO'], true)) {
                throw new ErroNegocio('Só os empréstimos aprovados são lançados na conta corrente.', 'EMPRESTIMO_NAO_APROVADO', 422);
            }
            if ($e->lancamento_referencia) {
                throw new ErroNegocio("Este empréstimo já foi lançado ({$e->lancamento_referencia}).", 'JA_LANCADO', 422);
            }
            $colab = Colaborador::query()->withTrashed()->find($e->colaborador_id);
            $nif = $colab?->nif ? ServicoColaboradores::normalizarNif((string) $colab->nif) : null;
            $terceiro = $nif ? Terceiro::query()->get(['id', 'nif'])->first(fn ($t) => $t->nif && ServicoColaboradores::normalizarNif((string) $t->nif) === $nif) : null;
            $descricao = 'Empréstimo a '.($colab?->nome_completo ?? 'colaborador')." — {$e->numero}";
            $linhas = [['codigo_conta' => $cfg['conta_emprestimo'], 'tipo_dc' => 'D', 'valor' => (string) $e->capital, 'terceiro_id' => $terceiro?->id, 'descricao' => $descricao],
                ['codigo_conta' => $cfg['conta_contrapartida'], 'tipo_dc' => 'C', 'valor' => (string) $e->capital, 'descricao' => $descricao]];
            $criadas = $this->lancamentos->criar(['diario_id' => $diario->id, 'data_documento' => $data ?: now()->toDateString(), 'numero_documento' => $e->numero,
                'referencia' => $e->numero, 'descricao' => $descricao, 'tipo_origem' => 'EMPRESTIMOS_RH', 'linhas' => $linhas]);
            $e->update(['lancamento_referencia' => $criadas->first()->numero_lan, 'lancado_em' => now(), 'lancado_por' => Auth::user()?->nome_utilizador]);

            return $e->refresh();
        });
    }

    public function cancelar(EmprestimoColaborador $e, string $motivo): EmprestimoColaborador
    {
        return DB::transaction(function () use ($e, $motivo) {
            $e = EmprestimoColaborador::query()->lockForUpdate()->findOrFail($e->id);
            if (in_array($e->estado, ['CANCELADO', 'RECUSADO', 'LIQUIDADO'], true)) {
                throw new ErroNegocio("O empréstimo está {$e->estado}: não pode ser cancelado.", 'ESTADO_INVALIDO', 422);
            }
            if (PrestacaoEmprestimo::query()->where('emprestimo_id', $e->id)->where('estado', 'DESCONTADO')->exists()) {
                throw new ErroNegocio('Há prestações já descontadas: não é possível cancelar.', 'PRESTACOES_DESCONTADAS', 422);
            }
            if ($e->lancamento_referencia) {
                throw new ErroNegocio("O capital já foi lançado na conta corrente ({$e->lancamento_referencia}): estorne primeiro esse lançamento na Contabilidade.", 'JA_LANCADO', 422);
            }
            $e->update(['estado' => 'CANCELADO', 'motivo_cancelamento' => trim($motivo), 'cancelado_em' => now(), 'cancelado_por' => Auth::user()?->nome_utilizador]);
            if ($e->pedido_portal_colaborador_id) {
                PedidoPortalColaborador::query()->whereKey($e->pedido_portal_colaborador_id)->where('estado', 'like', 'PENDENTE%')->update(['estado' => 'CANCELADO', 'cancelado_em' => now()]);
            }
            $this->auditoria->registar('RH', 'Cancelar empréstimo', "{$e->numero}: {$motivo}", 'emprestimos_colaboradores', $e->id);

            return $e->refresh();
        });
    }

    // ───────────── Auxiliares ─────────────

    /** «EMP AAAA/NNN» por empresa e ano, serializado (o legado contava os registos no navegador). */
    private function proximoNumero(): string
    {
        $ano = now()->format('Y');
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["emprestimos:numero:{$ano}"]);
        $ultimo = EmprestimoColaborador::query()->where('numero', 'like', "EMP {$ano}/%")->pluck('numero')
            ->map(fn ($n) => (int) substr((string) $n, strlen("EMP {$ano}/")))->max() ?? 0;

        return sprintf('EMP %s/%03d', $ano, $ultimo + 1);
    }

    private static function n(mixed $v): string
    {
        return is_numeric($v) ? number_format((float) $v, 6, '.', '') : '0';
    }

    /** Arredondamento a 2 casas, metade para cima (valores ≥ 0). */
    private static function r2(string $v): string
    {
        return bcadd(is_numeric($v) ? $v : '0', bccomp($v, '0', 12) >= 0 ? '0.005' : '-0.005', 2);
    }
}
