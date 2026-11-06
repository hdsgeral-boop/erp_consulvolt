<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\MovimentoCaixaPOS;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Suprimento e sangria da caixa (F1b; o legado não tinha). Só com a sessão ABERTA e com motivo (≥ 3 caracteres, como o
 * Dialogo.pedir do legado). Entram no numerário esperado (fundo + vendas + suprimentos − sangrias), no Z, na prestação de contas
 * (o item de numerário passa a ser vendas + suprimentos − sangrias + desvio) e na integração contabilística:
 *   - suprimento: D transitória do numerário / C conta de liquidação do meio (caixa 45);
 *   - sangria:    D conta de liquidação do meio (caixa/cofre 45) / C transitória do numerário.
 * A sangria é, na prática, uma prestação antecipada de numerário; por isso a prestação final só entrega o que resta.
 * Anular não apaga: o movimento fica ANULADO e deixa de contar (só com a sessão aberta).
 */
final class ServicoMovimentosCaixaPOS
{
    /** @return array{suprimentos: string, sangrias: string} soma dos movimentos activos da sessão */
    public static function totaisDaSessao(int $sessaoId): array
    {
        $l = MovimentoCaixaPOS::query()->where('sessao_pos_id', $sessaoId)->where('estado', 'ATIVO')->selectRaw('tipo, COALESCE(SUM(valor),0) AS total')->groupBy('tipo')->pluck('total', 'tipo');

        return ['suprimentos' => number_format((float) ($l['SUPRIMENTO'] ?? 0), 2, '.', ''), 'sangrias' => number_format((float) ($l['SANGRIA'] ?? 0), 2, '.', '')];
    }

    /** Efeito líquido no numerário (suprimentos − sangrias). */
    public static function liquidoDaSessao(int $sessaoId): string
    {
        $t = self::totaisDaSessao($sessaoId);

        return bcsub($t['suprimentos'], $t['sangrias'], 2);
    }

    /** @return array{movimentos: list<array<string, mixed>>, suprimentos: string, sangrias: string} */
    public function listar(SessaoPOS $s): array
    {
        $m = MovimentoCaixaPOS::query()->where('sessao_pos_id', $s->id)->orderBy('id')->get()->map(fn (MovimentoCaixaPOS $x) => $x->toArray())->all();

        return ['movimentos' => $m] + self::totaisDaSessao($s->id);
    }

    public function registar(SessaoPOS $s, string $tipo, string|float $valor, string $motivo): MovimentoCaixaPOS
    {
        if (! in_array($tipo, [MovimentoCaixaPOS::SUPRIMENTO, MovimentoCaixaPOS::SANGRIA], true)) {
            throw new ErroNegocio('Tipo de movimento inválido.', 'MOVIMENTO_INVALIDO', 422);
        }
        $valor = number_format((float) $valor, 2, '.', '');
        $motivo = trim($motivo);
        if (bccomp($valor, '0', 2) <= 0) {
            throw new ErroNegocio('O valor do movimento tem de ser positivo.', 'VALOR_INVALIDO', 422);
        }
        if (mb_strlen($motivo) < 3) {
            throw new ErroNegocio('Indique o motivo (pelo menos 3 caracteres).', 'MOTIVO_EM_FALTA', 422);
        }

        return DB::transaction(function () use ($s, $tipo, $valor, $motivo) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
            if ($s->estado !== 'ABERTA') {
                throw new ErroNegocio('Só se registam suprimentos e sangrias com a sessão aberta.', 'SESSAO_NAO_ABERTA', 422);
            }
            $t = TerminalPOS::query()->withTrashed()->findOrFail($s->terminal_pos_id);
            $meio = collect($t->meios_pagamento ?? [])->first(fn ($m) => $m['tipo'] === 'NUMERARIO' && ! empty($m['ativo']) && ! empty($m['conta_transitoria']));
            if (! $meio || empty($meio['conta_liquidacao'])) {
                throw new ErroNegocio('O terminal precisa de um meio de numerário activo com conta transitória e conta de liquidação.', 'SEM_NUMERARIO', 422);
            }
            if ($tipo === MovimentoCaixaPOS::SANGRIA) {
                $esperado = app(ServicoSessoesPOS::class)->totais($s)['numerario_esperado'];
                if (bccomp($valor, $esperado, 2) > 0) {
                    throw new ErroNegocio("A sangria ({$valor}) excede o numerário que a caixa deve ter ({$esperado}).", 'SANGRIA_EXCEDE_NUMERARIO', 422, ['numerario_esperado' => $esperado]);
                }
            }
            $u = Auth::user();

            return MovimentoCaixaPOS::create(['sessao_pos_id' => $s->id, 'tipo' => $tipo, 'valor' => $valor, 'motivo' => mb_substr($motivo, 0, 300), 'data' => now()->toDateString(),
                'meio_id' => $meio['id'], 'conta_transitoria' => $meio['conta_transitoria'], 'conta_contrapartida' => $meio['conta_liquidacao'],
                'operador_id' => $u?->id, 'nome_operador' => $u?->nomeApresentacao(), 'estado' => 'ATIVO']);
        });
    }

    public function anular(SessaoPOS $s, MovimentoCaixaPOS $m, string $motivo): MovimentoCaixaPOS
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new ErroNegocio('Indique o motivo da anulação (pelo menos 3 caracteres).', 'MOTIVO_EM_FALTA', 422);
        }

        return DB::transaction(function () use ($s, $m, $motivo) {
            $s = SessaoPOS::query()->lockForUpdate()->findOrFail($s->id);
            $m = MovimentoCaixaPOS::query()->where('sessao_pos_id', $s->id)->lockForUpdate()->findOrFail($m->id);
            if ($s->estado !== 'ABERTA') {
                throw new ErroNegocio('Só se anulam movimentos com a sessão aberta.', 'SESSAO_NAO_ABERTA', 422);
            }
            if ($m->estado !== 'ATIVO') {
                throw new ErroNegocio('O movimento já está anulado.', 'MOVIMENTO_JA_ANULADO', 422);
            }
            $m->update(['estado' => 'ANULADO', 'anulado_em' => now(), 'anulado_por' => Auth::user()?->nome_utilizador, 'motivo_anulacao' => mb_substr($motivo, 0, 300)]);

            return $m;
        });
    }
}
