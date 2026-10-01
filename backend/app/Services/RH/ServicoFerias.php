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
 */
final class ServicoFerias
{
    public const DIREITO_PADRAO = 22;

    public const ESTADOS_RH = ['PLANEADO', 'APROVADO', 'GOZADO', 'CANCELADO'];

    public function __construct(private readonly ServicoCalendarioRH $calendario) {}

    public function direito(int $colaborador, int $ano): int
    {
        return (int) (PlanoFeriasColaborador::query()->where('colaborador_id', $colaborador)->where('ano', $ano)->whereNotNull('direito')
            ->orderByDesc('atualizado_em')->orderByDesc('id')->value('direito') ?? self::DIREITO_PADRAO);
    }

    /** @return list<array<string, mixed>> por colaborador activo: direito, marcados, gozados, saldo */
    public function resumo(int $ano): array
    {
        $periodos = PlanoFeriasColaborador::query()->where('ano', $ano)->where('estado', '<>', 'CANCELADO')->get()->groupBy('colaborador_id');
        // Desempenho (Fase 6): o direito de todos os colaboradores numa só consulta (antes, uma por colaborador — N+1),
        // com a mesma regra de direito(): o registo do ano com direito mais recente (atualizado_em, id), senão 22.
        $direitos = PlanoFeriasColaborador::query()->where('ano', $ano)->whereNotNull('direito')
            ->orderByDesc('atualizado_em')->orderByDesc('id')->get(['colaborador_id', 'direito'])->unique('colaborador_id')->pluck('direito', 'colaborador_id');

        return Colaborador::query()->where('estado', 'ACTIVO')->orderBy('nome_completo')->get()->map(function ($c) use ($periodos, $direitos) {
            $meus = $periodos[$c->id] ?? collect();
            $direito = (int) ($direitos[$c->id] ?? self::DIREITO_PADRAO);
            $marcados = (int) $meus->sum('dias');

            return ['colaborador_id' => $c->id, 'nome' => $c->nome_completo, 'direito' => $direito, 'marcados' => $marcados,
                'aprovados' => (int) $meus->whereIn('estado', ['APROVADO', 'GOZADO'])->sum('dias'), 'gozados' => (int) $meus->where('estado', 'GOZADO')->sum('dias'),
                'pedidos' => (int) $meus->where('estado', 'PEDIDO')->sum('dias'), 'saldo' => $direito - $marcados];
        })->all();
    }

    /** @param  array<string, mixed>  $d  colaborador_id, data_inicio, data_fim, estado?, direito?, observacoes?, confirmar_excesso? */
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
            'estado' => $estado, 'direito' => $p->direito, 'confirmar_excesso' => true], $p);
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
