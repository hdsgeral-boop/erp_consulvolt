<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\PlanoFeriasColaborador;
use Illuminate\Support\Facades\DB;

/**
 * Férias (js/modules/rh/ferias.js). Paridade: direito por colaborador e ano (22 dias úteis por omissão; ao gravar
 * aplica-se a todos os períodos desse ano), saldo = direito − dias marcados (não cancelados), estados PEDIDO
 * (portal) / PLANEADO / APROVADO / GOZADO / CANCELADO, nada se altera enquanto o pedido do portal estiver pendente.
 * Correcções (ADR-039):
 *   - dias úteis pelo calendário da empresa (o legado contava segunda a sexta fixos e ignorava os feriados);
 *   - sobreposição com outras férias ou com ausências é recusada (no RH o legado só pedia confirmação);
 *     exceder o direito continua a exigir confirmação explícita (`confirmar_excesso`);
 *   - GOZADO só depois de terminadas; PEDIDO só pelo portal;
 *   - o direito do ano lê-se sempre do mesmo modo (o legado usava registos diferentes no portal e no RH);
 *   - um período de um pedido do portal aprovado não se elimina (ficava o pedido a apontar para o vazio): cancela-se.
 * Decisão 7 do utilizador (2026-10-06) — regra da Lei Geral do Trabalho (Lei n.º 12/23), parâmetros em ServicoConfiguracaoRH:
 *   - direito do ano = o gravado no plano de férias (prevalece sempre, como no legado); sem registo, é calculado:
 *       base = 22 dias úteis; no ano de admissão = 2 dias úteis × meses completos de serviço até 31/12 (máx. 22);
 *       + transporte = saldo não gozado do ano anterior (direito − dias marcados não cancelados), até ao limite
 *         configurado (por omissão 22 dias), se o transporte estiver activo e o ano anterior tiver plano de férias;
 *   - antes de 6 meses completos de serviço o gozo dá um aviso (o RH decide; o portal recusa).
 */
final class ServicoFerias
{
    public const DIREITO_PADRAO = 22;

    public const ESTADOS_RH = ['PLANEADO', 'APROVADO', 'GOZADO', 'CANCELADO'];

    public function __construct(
        private readonly ServicoCalendarioRH $calendario,
        private readonly ServicoConfiguracaoRH $configRH,
    ) {}

    public function direito(int $colaborador, int $ano): int
    {
        $gravado = PlanoFeriasColaborador::query()->where('colaborador_id', $colaborador)->where('ano', $ano)->whereNotNull('direito')
            ->orderByDesc('atualizado_em')->orderByDesc('id')->value('direito');

        return $gravado !== null ? (int) $gravado : $this->calculoDireito($colaborador, $ano)['total'];
    }

    /**
     * Direito calculado pela Lei Geral do Trabalho (decisão 7), sem o valor gravado no plano.
     *
     * @return array{base: int, transporte: int, total: int, ano_admissao: bool, meses_servico: ?int}
     */
    public function calculoDireito(int $colaborador, int $ano): array
    {
        $admissao = Colaborador::query()->withTrashed()->whereKey($colaborador)->value('data_admissao');
        $anteriores = PlanoFeriasColaborador::query()->where('colaborador_id', $colaborador)->where('ano', $ano - 1)->orderByDesc('atualizado_em')->orderByDesc('id')->get();

        return self::calcular($admissao ? substr((string) $admissao, 0, 10) : null, $ano, $this->configRH->config(), self::anoAnterior($anteriores));
    }

    /** Resumo do ano anterior a partir dos seus registos do plano (ordenados do mais recente para o mais antigo). */
    private static function anoAnterior($registos): ?array
    {
        if ($registos->isEmpty()) {
            return null;   // ano não gerido no plano: sem registos não se sabe o que foi gozado, nada transita
        }
        $gravado = $registos->first(fn ($r) => $r->direito !== null)?->direito;

        return ['direito' => $gravado !== null ? (int) $gravado : null, 'marcados' => (int) $registos->where('estado', '<>', 'CANCELADO')->sum('dias')];
    }

    /**
     * Regra pura (sem consultas): base do ano (22, ou 2 dias × meses completos no ano de admissão) + saldo transportado
     * do ano anterior (direito desse ano − dias marcados), limitado ao máximo; o direito do ano anterior é o gravado ou a
     * base desse ano (o transporte não se acumula de anos mais antigos para lá do limite).
     *
     * @param  array{direito: ?int, marcados: int}|null  $anterior
     * @return array{base: int, transporte: int, total: int, ano_admissao: bool, meses_servico: ?int}
     */
    public static function calcular(?string $admissao, int $ano, array $cfg, ?array $anterior): array
    {
        $base = fn (int $a) => match (true) {
            $admissao === null || $a > (int) substr($admissao, 0, 4) => self::DIREITO_PADRAO,
            $a < (int) substr($admissao, 0, 4) => 0,
            default => min(self::DIREITO_PADRAO, self::mesesCompletos($admissao, "{$a}-12-31") * (int) $cfg['ferias_dias_mes_admissao']),
        };
        $anoAdmissao = $admissao !== null && $ano === (int) substr($admissao, 0, 4);
        if ($admissao !== null && $ano < (int) substr($admissao, 0, 4)) {
            return ['base' => 0, 'transporte' => 0, 'total' => 0, 'ano_admissao' => false, 'meses_servico' => 0];
        }
        $transporte = 0;
        if (! empty($cfg['ferias_transporte_saldo']) && $anterior !== null && ! $anoAdmissao) {
            $direitoAnterior = $anterior['direito'] ?? $base($ano - 1);
            $transporte = max(0, min((int) $cfg['ferias_transporte_max_dias'], $direitoAnterior - $anterior['marcados']));
        }
        $b = $base($ano);

        return ['base' => $b, 'transporte' => $transporte, 'total' => $b + $transporte, 'ano_admissao' => $anoAdmissao,
            'meses_servico' => $anoAdmissao ? self::mesesCompletos($admissao, "{$ano}-12-31") : null];
    }

    /** Meses civis completos de serviço entre a admissão e a data (inclusive). */
    public static function mesesCompletos(string $admissao, string $ate): int
    {
        $ini = new \DateTimeImmutable($admissao);
        $fim = (new \DateTimeImmutable($ate))->modify('+1 day');
        if ($fim <= $ini) {
            return 0;
        }
        $d = $ini->diff($fim);

        return $d->y * 12 + $d->m;
    }

    /** Aviso da LGT: gozo de férias antes de 6 meses completos de serviço. */
    public function avisoAntiguidade(int $colaborador, string $inicio): ?string
    {
        $minimo = (int) (($this->configRH->config())['ferias_meses_minimos_gozo']);
        $admissao = Colaborador::query()->withTrashed()->whereKey($colaborador)->value('data_admissao');
        if (! $admissao || $minimo <= 0) {
            return null;
        }
        $meses = self::mesesCompletos(substr((string) $admissao, 0, 10), date('Y-m-d', strtotime($inicio.' -1 day')));

        return $meses < $minimo ? "O colaborador só tem {$meses} mês(es) completo(s) de serviço: a lei só permite o gozo de férias depois de {$minimo} meses." : null;
    }

    /** @return list<array<string, mixed>> por colaborador activo: direito, marcados, gozados, saldo */
    public function resumo(int $ano): array
    {
        $periodos = PlanoFeriasColaborador::query()->where('ano', $ano)->where('estado', '<>', 'CANCELADO')->get()->groupBy('colaborador_id');
        // Desempenho (Fase 6): o direito de todos os colaboradores numa só consulta (antes, uma por colaborador — N+1),
        // com a mesma regra de direito(): o registo do ano com direito mais recente (atualizado_em, id), senão 22.
        $direitos = PlanoFeriasColaborador::query()->where('ano', $ano)->whereNotNull('direito')
            ->orderByDesc('atualizado_em')->orderByDesc('id')->get(['colaborador_id', 'direito'])->unique('colaborador_id')->pluck('direito', 'colaborador_id');

        // decisão 7: o direito calculado (LGT) também sem consultas por colaborador — o ano anterior lê-se de uma vez
        $cfg = $this->configRH->config();
        $anteriores = PlanoFeriasColaborador::query()->where('ano', $ano - 1)->orderByDesc('atualizado_em')->orderByDesc('id')->get()->groupBy('colaborador_id');

        return Colaborador::query()->where('estado', 'ACTIVO')->orderBy('nome_completo')->get()->map(function ($c) use ($periodos, $direitos, $ano, $cfg, $anteriores) {
            $meus = $periodos[$c->id] ?? collect();
            $calculo = isset($direitos[$c->id]) ? null
                : self::calcular($c->data_admissao ? substr((string) $c->data_admissao, 0, 10) : null, $ano, $cfg, isset($anteriores[$c->id]) ? self::anoAnterior($anteriores[$c->id]) : null);
            $direito = (int) ($direitos[$c->id] ?? $calculo['total']);
            $marcados = (int) $meus->sum('dias');

            return ['colaborador_id' => $c->id, 'nome' => $c->nome_completo, 'direito' => $direito, 'marcados' => $marcados,
                'direito_gravado' => isset($direitos[$c->id]), 'transporte' => $calculo['transporte'] ?? null, 'ano_admissao' => $calculo['ano_admissao'] ?? false,
                'aprovados' => (int) $meus->whereIn('estado', ['APROVADO', 'GOZADO'])->sum('dias'), 'gozados' => (int) $meus->where('estado', 'GOZADO')->sum('dias'),
                'pedidos' => (int) $meus->where('estado', 'PEDIDO')->sum('dias'), 'saldo' => $direito - $marcados];
        })->all();
    }

    /** @param  array<string, mixed>  $d  colaborador_id, data_inicio, data_fim, estado?, direito?, observacoes?, confirmar_excesso?, confirmar_antiguidade? */
    public function gravar(array $d, ?PlanoFeriasColaborador $p = null): PlanoFeriasColaborador
    {
        $colab = Colaborador::query()->findOrFail($d['colaborador_id']);
        $p && $this->exigirSemPedidoPendente($p);
        [$ini, $fim] = [substr($d['data_inicio'], 0, 10), substr($d['data_fim'], 0, 10)];
        if ($fim < $ini) {
            throw new ErroNegocio('A data de fim não pode ser anterior à de início.', 'DATAS_INVALIDAS', 422);
        }
        $estado = $d['estado'] ?? $p?->estado ?? 'PLANEADO';
        if (! in_array($estado, self::ESTADOS_RH, true) && ! ($p && $estado === $p->estado)) {
            throw new ErroNegocio('Estado inválido: os pedidos (PEDIDO) só se criam pelo portal.', 'ESTADO_INVALIDO', 422);
        }
        if ($estado === 'GOZADO' && $fim > now()->toDateString()) {
            throw new ErroNegocio('Só se marcam como gozadas as férias já terminadas.', 'FERIAS_NAO_TERMINADAS', 422);
        }
        $dias = $this->calendario->diasUteis($ini, $fim);
        if ($dias < 1) {
            throw new ErroNegocio('O período não tem dias úteis.', 'SEM_DIAS_UTEIS', 422);
        }
        $ano = (int) substr($ini, 0, 4);
        $direito = (int) ($d['direito'] ?? $this->direito($colab->id, $ano));
        if ($estado !== 'CANCELADO' && ! $p && empty($d['confirmar_antiguidade']) && ($aviso = $this->avisoAntiguidade($colab->id, $ini))) {
            throw new ErroNegocio("{$aviso} Confirme para gravar.", 'ANTIGUIDADE_INSUFICIENTE', 422);
        }

        return DB::transaction(function () use ($d, $p, $colab, $ini, $fim, $estado, $dias, $ano, $direito) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["ferias:{$colab->id}"]);
            if ($estado !== 'CANCELADO') {
                $sobre = PlanoFeriasColaborador::query()->where('colaborador_id', $colab->id)->where('estado', '<>', 'CANCELADO')->when($p, fn ($q) => $q->whereKeyNot($p->id))
                    ->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $ini)->first();
                $aus = AusenciaFaltaColaborador::query()->where('colaborador_id', $colab->id)->whereNotIn('estado', ['CANCELADO', 'RECUSADO', 'POR_JUSTIFICAR'])
                    ->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $ini)->exists();
                if ($sobre || $aus) {
                    throw new ErroNegocio($sobre ? "Sobrepõe-se às férias de {$sobre->data_inicio->toDateString()} a {$sobre->data_fim->toDateString()}." : 'Sobrepõe-se a uma ausência do colaborador.',
                        'SOBREPOSICAO', 422);
                }
                $outros = (int) PlanoFeriasColaborador::query()->where('colaborador_id', $colab->id)->where('ano', $ano)->where('estado', '<>', 'CANCELADO')
                    ->when($p, fn ($q) => $q->whereKeyNot($p->id))->sum('dias');
                if ($outros + $dias > $direito && empty($d['confirmar_excesso'])) {
                    throw new ErroNegocio("Excede o direito de {$direito} dias úteis em {$ano} (já marcados {$outros}; este período {$dias}). Confirme para gravar.",
                        'SALDO_EXCEDIDO', 422, ['direito' => $direito, 'marcados' => $outros, 'dias' => $dias]);
                }
            }
            $dados = ['colaborador_id' => $colab->id, 'ano' => $ano, 'data_inicio' => $ini, 'data_fim' => $fim, 'dias' => $dias, 'direito' => $direito, 'estado' => $estado,
                'observacoes' => $d['observacoes'] ?? $p?->observacoes];
            $p ? $p->update($dados) : $p = PlanoFeriasColaborador::create($dados);
            // o direito é do colaborador no ano: aplica-se a todos os períodos desse ano
            PlanoFeriasColaborador::query()->where('colaborador_id', $colab->id)->where('ano', $ano)->where('direito', '<>', $direito)->update(['direito' => $direito]);

            return $p->refresh();
        });
    }

    /**
     * Pedido pelo portal: início não passado, sem sobreposição e SEM exceder o direito (no portal o excesso é erro,
     * como no legado); fica PEDIDO até à decisão.
     */
    public function criarPedido(int $colaborador, string $ini, string $fim, ?string $observacoes): PlanoFeriasColaborador
    {
        if ($ini < now()->toDateString()) {
            throw new ErroNegocio('As férias pedidas não podem começar no passado.', 'DATA_PASSADA', 422);
        }
        if ($fim < $ini) {
            throw new ErroNegocio('A data de fim não pode ser anterior à de início.', 'DATAS_INVALIDAS', 422);
        }
        $dias = $this->calendario->diasUteis($ini, $fim);
        if ($dias < 1) {
            throw new ErroNegocio('O período não tem dias úteis.', 'SEM_DIAS_UTEIS', 422);
        }
        $ano = (int) substr($ini, 0, 4);
        $direito = $this->direito($colaborador, $ano);
        if ($aviso = $this->avisoAntiguidade($colaborador, $ini)) {
            throw new ErroNegocio($aviso, 'ANTIGUIDADE_INSUFICIENTE', 422);
        }
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["ferias:{$colaborador}"]);
        if (PlanoFeriasColaborador::query()->where('colaborador_id', $colaborador)->where('estado', '<>', 'CANCELADO')->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $ini)->exists()
            || AusenciaFaltaColaborador::query()->where('colaborador_id', $colaborador)->whereNotIn('estado', ['CANCELADO', 'RECUSADO', 'POR_JUSTIFICAR'])
                ->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $ini)->exists()) {
            throw new ErroNegocio('O período sobrepõe-se a férias ou ausências já marcadas.', 'SOBREPOSICAO', 422);
        }
        $marcados = (int) PlanoFeriasColaborador::query()->where('colaborador_id', $colaborador)->where('ano', $ano)->where('estado', '<>', 'CANCELADO')->sum('dias');
        if ($marcados + $dias > $direito) {
            throw new ErroNegocio("Excede o saldo de férias de {$ano}: direito {$direito}, já marcados {$marcados}, pedidos {$dias}.", 'SALDO_EXCEDIDO', 422);
        }

        return PlanoFeriasColaborador::create(['colaborador_id' => $colaborador, 'ano' => $ano, 'data_inicio' => $ini, 'data_fim' => $fim, 'dias' => $dias,
            'direito' => $direito, 'estado' => 'PEDIDO', 'observacoes' => $observacoes]);
    }

    public function alterarEstado(PlanoFeriasColaborador $p, string $estado): PlanoFeriasColaborador
    {
        return $this->gravar(['colaborador_id' => $p->colaborador_id, 'data_inicio' => $p->data_inicio->toDateString(), 'data_fim' => $p->data_fim->toDateString(),
            'estado' => $estado, 'direito' => $p->direito, 'confirmar_excesso' => true, 'confirmar_antiguidade' => true], $p);
    }

    public function eliminar(PlanoFeriasColaborador $p): void
    {
        $this->exigirSemPedidoPendente($p);
        if ($p->pedido_portal_colaborador_id) {
            throw new ErroNegocio('Este período veio de um pedido do portal: cancele-o em vez de o eliminar.', 'PERIODO_DO_PORTAL', 422);
        }
        $p->delete();
    }

    private function exigirSemPedidoPendente(PlanoFeriasColaborador $p): void
    {
        if ($p->pedido_portal_colaborador_id && str_starts_with((string) DB::table('pedidos_portal_colaborador')->where('id', $p->pedido_portal_colaborador_id)->value('estado'), 'PENDENTE')) {
            throw new ErroNegocio('O pedido do portal está em aprovação: decida-o primeiro em «Pedidos do Portal».', 'PEDIDO_PENDENTE', 422);
        }
    }
}
