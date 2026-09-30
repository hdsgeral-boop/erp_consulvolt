<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PeriodoProdutividadeRH;
use App\Models\RegistoProdutividadeRH;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Subsídio de produtividade (js/modules/rh/produtividade.js). Paridade: catálogo de itens (código único, métrica,
 * preço > 0, rubrica de VENCIMENTO, mínimo/máximo), períodos de medição por mês (janela ≤ 93 dias, sem
 * sobreposição, fechar/reabrir com motivo), registos só de colaboradores com o item no contrato, preço do contrato
 * (se > 0) ou do item fixado na data do registo, lançamento no processamento (período de produtividade FECHADO,
 * processamento ABERTO), item em uso não se elimina (desactiva-se).
 * Correcções (ADR-039):
 *   - o mínimo e o máximo aplicam-se ao TOTAL do colaborador no item e no período (o legado aplicava-os a cada
 *     registo: dividir a quantidade em registos diários contornava o tecto e mudava o efeito do mínimo);
 *   - um registo por (colaborador, item, data) também na introdução manual (só a importação verificava);
 *   - elegibilidade: colaborador ACTIVO com contrato válido na janela do período (o legado usava o primeiro contrato
 *     ACTIVO, sem datas, e ignorava o estado do colaborador);
 *   - relançar retira os lançamentos de produtividade que deixaram de ter valor (o legado deixava-os ficar).
 */
final class ServicoProdutividade
{
    public const METRICAS = ['QUANTIDADE', 'HORAS', 'OBJECTIVO', 'PONTOS', 'TAREFAS'];

    public const ORIGEM_LANCAMENTO = 'PRODUTIVIDADE';

    // ───────────── Itens ─────────────

    /** @param  array<string, mixed>  $d */
    public function gravarItem(array $d, ?ItemProdutividadeRH $i = null): ItemProdutividadeRH
    {
        $d['codigo'] = mb_strtoupper(trim((string) $d['codigo']));
        if (! preg_match('/^[A-Z0-9._-]{1,20}$/', $d['codigo'])) {
            throw new ErroNegocio('Código inválido: 1 a 20 caracteres (letras, números, «.», «_» ou «-»).', 'CODIGO_INVALIDO', 422);
        }
        if (ItemProdutividadeRH::query()->whereRaw('upper(codigo) = ?', [$d['codigo']])->when($i, fn ($q) => $q->whereKeyNot($i->id))->exists()) {
            throw new ErroNegocio("Já existe o item {$d['codigo']}.", 'CODIGO_DUPLICADO', 422);
        }
        if (InfotipoSalarial::query()->findOrFail($d['infotipo_salarial_id'])->tipo !== 'VENCIMENTO') {
            throw new ErroNegocio('A rubrica do item tem de ser um vencimento.', 'RUBRICA_NAO_VENCIMENTO', 422);
        }
        if (isset($d['minimo'], $d['maximo']) && (float) $d['maximo'] < (float) $d['minimo']) {
            throw new ErroNegocio('O máximo não pode ser inferior ao mínimo.', 'LIMITES_INVALIDOS', 422);
        }
        $user = Auth::user()?->nome_utilizador;
        $i ? $i->update($d + ['atualizado_por' => $user]) : $i = ItemProdutividadeRH::create($d + ['metrica' => 'QUANTIDADE', 'ativo' => true, 'criado_por' => $user]);

        return $i->refresh();
    }

    public function eliminarItem(ItemProdutividadeRH $i): void
    {
        $registos = RegistoProdutividadeRH::query()->where('item_produtividade_id', $i->id)->exists();
        $contratos = ContratoTrabalho::query()->whereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(produtividade, '[]'::jsonb)) x WHERE (x->>'item_id')::bigint = ?)", [$i->id])->exists();
        if ($registos || $contratos) {
            throw new ErroNegocio("O item {$i->codigo} está em uso (".($registos ? 'registos' : 'contratos').'): desactive-o em vez de o eliminar.', 'REGISTO_EM_USO', 422);
        }
        $i->delete();
    }

    // ───────────── Períodos ─────────────

    /** @param  array<string, mixed>  $d  mes (AAAA-MM), data_inicio, data_fim, observacoes? */
    public function gravarPeriodo(array $d, ?PeriodoProdutividadeRH $p = null): PeriodoProdutividadeRH
    {
        $mes = ServicoCalendarioRH::mes($d['mes']);
        [$ini, $fim] = [substr($d['data_inicio'], 0, 10), substr($d['data_fim'], 0, 10)];
        if ($p?->estado === 'FECHADO') {
            throw new ErroNegocio('O período está fechado: reabra-o para alterar.', 'PERIODO_FECHADO', 422);
        }
        if ($fim < $ini) {
            throw new ErroNegocio('A data de fim não pode ser anterior à de início.', 'DATAS_INVALIDAS', 422);
        }
        if (count(ServicoCalendarioRH::dias($ini, $fim)) > 93) {
            throw new ErroNegocio('A janela de medição não pode exceder 93 dias.', 'JANELA_EXCESSIVA', 422);
        }

        return DB::transaction(function () use ($d, $p, $mes, $ini, $fim) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['produtividade_periodos']);
            if (PeriodoProdutividadeRH::query()->where('mes', $mes)->when($p, fn ($q) => $q->whereKeyNot($p->id))->exists()) {
                throw new ErroNegocio("Já existe o período de produtividade de {$mes}.", 'PERIODO_EXISTENTE', 422);
            }
            if (PeriodoProdutividadeRH::query()->when($p, fn ($q) => $q->whereKeyNot($p->id))->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $ini)->exists()) {
                throw new ErroNegocio('A janela de medição sobrepõe-se à de outro período.', 'JANELA_SOBREPOSTA', 422);
            }
            if ($p && RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->whereNotNull('data')->where(fn ($q) => $q->where('data', '<', $ini)->orWhere('data', '>', $fim))->exists()) {
                throw new ErroNegocio('Há registos fora da nova janela de medição.', 'REGISTOS_FORA_DA_JANELA', 422);
            }
            $dados = ['mes' => $mes, 'data_inicio' => $ini, 'data_fim' => $fim, 'observacoes' => $d['observacoes'] ?? $p?->observacoes, 'atualizado_por' => Auth::user()?->nome_utilizador];
            if ($p) {
                $p->update($dados);
                RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->update(['mes' => $mes]);
            } else {
                $p = PeriodoProdutividadeRH::create($dados + ['estado' => 'ABERTO', 'criado_por' => Auth::user()?->nome_utilizador]);
            }

            return $p->refresh();
        });
    }

    public function fecharPeriodo(PeriodoProdutividadeRH $p): PeriodoProdutividadeRH
    {
        if ($p->estado === 'FECHADO') {
            throw new ErroNegocio('O período já está fechado.', 'PERIODO_FECHADO', 422);
        }
        $q = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id);
        $p->update(['estado' => 'FECHADO', 'fechado_em' => now(), 'fechado_por' => Auth::user()?->nome_utilizador, 'total_fecho' => (clone $q)->sum('valor'), 'registos_fecho' => $q->count()]);

        return $p->refresh();
    }

    public function reabrirPeriodo(PeriodoProdutividadeRH $p, string $motivo): PeriodoProdutividadeRH
    {
        if ($p->estado !== 'FECHADO') {
            throw new ErroNegocio('O período não está fechado.', 'PERIODO_NAO_FECHADO', 422);
        }
        $sal = PeriodoProcessamentoSalarial::query()->where('mes_ano', ServicoCalendarioRH::mesSalarial($p->mes))->first();
        if ($sal && $sal->estado !== 'ABERTO') {
            throw new ErroNegocio('O processamento salarial do mês já foi encerrado: reabra-o primeiro.', 'PERIODO_SALARIAL_ENCERRADO', 422);
        }
        $p->update(['estado' => 'ABERTO', 'reaberto_em' => now(), 'reaberto_por' => Auth::user()?->nome_utilizador, 'motivo_reabertura' => $motivo]);

        return $p->refresh();
    }

    // ───────────── Registos ─────────────

    /** Colaboradores ACTIVOS com contrato válido na janela e com o item; preço aplicado: o do contrato (> 0) ou o do item. */
    public function elegiveis(PeriodoProdutividadeRH $p): array
    {
        $itens = ItemProdutividadeRH::query()->where('ativo', true)->get()->keyBy('id');
        $saida = [];
        foreach (Colaborador::query()->where('estado', 'ACTIVO')->orderBy('nome_completo')->get() as $c) {
            $contrato = ContratoTrabalho::query()->where('colaborador_id', $c->id)->where('estado', 'ACTIVO')
                ->where(fn ($q) => $q->whereNull('data_inicio')->orWhere('data_inicio', '<=', $p->data_fim))
                ->where(fn ($q) => $q->whereNull('data_fim')->orWhere('data_fim', '>=', $p->data_inicio))->orderBy('id')->first();
            $meus = [];
            foreach ((array) ($contrato?->produtividade ?? []) as $x) {
                $item = $itens[(int) ($x['item_id'] ?? 0)] ?? null;
                if ($item) {
                    $meus[$item->id] = ['item_id' => $item->id, 'codigo' => $item->codigo, 'preco' => (float) ($x['preco_unitario'] ?? 0) > 0 ? (string) $x['preco_unitario'] : (string) $item->preco_unitario];
                }
            }
            if ($meus) {
                $saida[$c->id] = ['colaborador_id' => $c->id, 'nome' => $c->nome_completo, 'itens' => $meus];
            }
        }

        return $saida;
    }

    /** @param  array<string, mixed>  $d  colaborador_id, item_produtividade_id, quantidade, data?, observacoes? */
    public function gravarRegisto(PeriodoProdutividadeRH $p, array $d, ?RegistoProdutividadeRH $r = null, string $origem = 'MANUAL'): RegistoProdutividadeRH
    {
        return DB::transaction(function () use ($p, $d, $r, $origem) {
            $p = PeriodoProdutividadeRH::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->estado !== 'ABERTO') {
                throw new ErroNegocio('O período de produtividade está fechado.', 'PERIODO_FECHADO', 422);
            }
            $el = $this->elegiveis($p)[(int) $d['colaborador_id']] ?? throw new ErroNegocio('O colaborador não é elegível: tem de estar ACTIVO e ter o item no contrato.', 'NAO_ELEGIVEL', 422);
            $itemEl = $el['itens'][(int) $d['item_produtividade_id']] ?? throw new ErroNegocio('O item não está no contrato do colaborador (ou está inactivo).', 'ITEM_FORA_DO_CONTRATO', 422);
            $item = ItemProdutividadeRH::query()->findOrFail($itemEl['item_id']);
            $data = ! empty($d['data']) ? substr($d['data'], 0, 10) : null;
            if ($data && ($data < $p->data_inicio->toDateString() || $data > $p->data_fim->toDateString() || $data > now()->toDateString())) {
                throw new ErroNegocio('A data tem de estar dentro da janela de medição e não pode ser futura.', 'DATA_FORA_DA_JANELA', 422);
            }
            $q = (float) $d['quantidade'];
            if ($q < 0 || ($item->metrica === 'OBJECTIVO' && $q > 1000)) {
                throw new ErroNegocio('Quantidade inválida.', 'QUANTIDADE_INVALIDA', 422);
            }
            $dup = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->where('colaborador_id', $d['colaborador_id'])
                ->where('item_produtividade_id', $item->id)->when($data, fn ($x) => $x->where('data', $data), fn ($x) => $x->whereNull('data'))
                ->when($r, fn ($x) => $x->whereKeyNot($r->id))->exists();
            if ($dup) {
                throw new ErroNegocio('Já existe um registo deste item para o colaborador nessa data.', 'REGISTO_DUPLICADO', 422);
            }
            $dados = ['periodo_produtividade_id' => $p->id, 'mes' => $p->mes, 'colaborador_id' => (int) $d['colaborador_id'], 'item_produtividade_id' => $item->id, 'data' => $data,
                'quantidade' => $q, 'observacoes' => $d['observacoes'] ?? null, 'origem' => $origem];
            $user = Auth::user()?->nome_utilizador;
            // preço fixado na data do registo (mantém-se numa edição da quantidade)
            $r ? $r->update($dados + ['atualizado_por' => $user]) : $r = RegistoProdutividadeRH::create($dados + ['preco_unitario' => $itemEl['preco'], 'criado_por' => $user]);
            $this->recalcular($p->id, $r->colaborador_id, $item->id);

            return $r->refresh();
        });
    }

    public function eliminarRegisto(RegistoProdutividadeRH $r): void
    {
        DB::transaction(function () use ($r) {
            if (PeriodoProdutividadeRH::query()->lockForUpdate()->findOrFail($r->periodo_produtividade_id)->estado !== 'ABERTO') {
                throw new ErroNegocio('O período de produtividade está fechado.', 'PERIODO_FECHADO', 422);
            }
            $r->delete();
            $this->recalcular($r->periodo_produtividade_id, $r->colaborador_id, $r->item_produtividade_id);
        });
    }

    /**
     * Mínimo/máximo sobre o total do colaborador no item e no período; a quantidade considerada reparte-se pelos
     * registos na proporção da quantidade (valor = considerada × preço do registo).
     */
    public function recalcular(int $periodo, int $colaborador, int $item): void
    {
        $i = ItemProdutividadeRH::query()->findOrFail($item);
        $regs = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $periodo)->where('colaborador_id', $colaborador)->where('item_produtividade_id', $item)->orderBy('id')->get();
        $total = (float) $regs->sum('quantidade');
        $considerada = self::considerar($total, $i->minimo !== null ? (float) $i->minimo : null, $i->maximo !== null ? (float) $i->maximo : null);
        foreach ($regs as $r) {
            $qc = $total > 0 ? round((float) $r->quantidade * $considerada / $total, 3) : 0.0;
            $r->update(['quantidade_considerada' => $qc, 'valor' => round($qc * (float) $r->preco_unitario, 2)]);
        }
    }

    public static function considerar(float $q, ?float $minimo, ?float $maximo): float
    {
        if ($minimo !== null && $q < $minimo) {
            return 0.0;
        }

        return $maximo !== null && $maximo > 0 && $q > $maximo ? $maximo : $q;
    }

    // ───────────── Lançamento no processamento ─────────────

    /** @return array{lancados: int, ignorados: int, removidos: int, total: string} */
    public function lancarNoPeriodo(PeriodoProcessamentoSalarial $sal, bool $substituir = true): array
    {
        return DB::transaction(function () use ($sal, $substituir) {
            $sal = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($sal->id);
            if ($sal->estado !== 'ABERTO') {
                throw new ErroNegocio("O processamento de {$sal->mes_ano} não está aberto.", 'PERIODO_ESTADO_INVALIDO', 422);
            }
            $p = PeriodoProdutividadeRH::query()->where('mes', ServicoCalendarioRH::mes($sal->mes_ano))->lockForUpdate()->first()
                ?? throw new ErroNegocio("Não há período de produtividade de {$sal->mes_ano}.", 'SEM_PERIODO_PRODUTIVIDADE', 422);
            if ($p->estado !== 'FECHADO') {
                throw new ErroNegocio('Feche primeiro o período de produtividade.', 'PERIODO_NAO_FECHADO', 422);
            }
            $infotipos = ItemProdutividadeRH::query()->pluck('infotipo_salarial_id', 'id');
            $somas = [];
            foreach (RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->get() as $r) {
                $k = $r->colaborador_id.'|'.$infotipos[$r->item_produtividade_id];
                $somas[$k] = bcadd($somas[$k] ?? '0.00', (string) $r->valor, 2);
            }
            $res = ['lancados' => 0, 'ignorados' => 0, 'removidos' => 0, 'total' => '0.00'];
            $manter = [];
            foreach ($somas as $k => $valor) {
                [$colab, $infotipo] = explode('|', $k);
                if (bccomp($valor, '0', 2) <= 0) {
                    continue;
                }
                $existente = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $sal->id)->where('colaborador_id', $colab)->where('infotipo_salarial_id', $infotipo)->first();
                if ($existente && ! $substituir) {
                    $res['ignorados']++;
                    $manter[] = $existente->id;

                    continue;
                }
                $l = LinhaFolhaSalarial::query()->updateOrCreate(['periodo_processamento_salarial_id' => $sal->id, 'colaborador_id' => (int) $colab, 'infotipo_salarial_id' => (int) $infotipo],
                    ['valor' => $valor, 'horas' => null, 'dias_trabalhados' => null, 'origem' => self::ORIGEM_LANCAMENTO]);
                $manter[] = $l->id;
                $res['lancados']++;
                $res['total'] = bcadd($res['total'], $valor, 2);
            }
            $res['removidos'] = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $sal->id)->where('origem', self::ORIGEM_LANCAMENTO)
                ->whereNotIn('id', $manter ?: [0])->delete();
            $p->update(['lancado_em' => now(), 'lancado_por' => Auth::user()?->nome_utilizador, 'periodo_processamento_salarial_id' => $sal->id]);

            return $res;
        });
    }
}
