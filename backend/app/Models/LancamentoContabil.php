<?php

namespace App\Models;

use App\Models\Base\LancamentoContabilBase;
use Illuminate\Database\Eloquent\Builder;

/**
 * lancamentos_contabeis — linha de um lançamento (partida dobrada). /api/contabilidade/lancamentos.
 *
 * Um lançamento (documento) é o conjunto de linhas com o mesmo diário e a mesma CHAVE: numero_lan; nas linhas
 * antigas do legado sem numero_lan o número do lançamento estava em `referencia` (o próprio legado lia
 * "lan_number || reference", js/app_v2.js:14) e só na falta desta se usa o numero_documento.
 * Verificado nos dados reais: por LAN 0 grupos desequilibrados; por referência 19 (vs 1 832 por documento).
 * Estorno (ADR-016): as linhas nunca se apagam; o estorno cria linhas inversas ligadas por estorno_de_id.
 */
class LancamentoContabil extends LancamentoContabilBase
{
    public const ORIGEM_MANUAL = 'MANUAL';

    public const ORIGEM_ESTORNO = 'ESTORNO';

    /** Expressão SQL da chave do lançamento (alias opcional da tabela). */
    public static function chaveSql(string $alias = ''): string
    {
        $a = $alias !== '' ? "{$alias}." : '';

        return "COALESCE(NULLIF({$a}numero_lan, ''), NULLIF({$a}referencia, ''), {$a}numero_documento)";
    }

    public function chave(): ?string
    {
        foreach ([$this->numero_lan, $this->referencia, $this->numero_documento] as $v) {
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /** Linhas do mesmo lançamento (documento) que esta. */
    public function scopeDoMesmoLancamento(Builder $q, self $linha): Builder
    {
        return $q->where('empresa_id', $linha->empresa_id)->where('diario_id', $linha->diario_id)
            ->whereRaw(self::chaveSql().' = ?', [$linha->chave()]);
    }

    public function estaEstornado(): bool
    {
        return $this->estornado_por_id !== null;
    }

    public function eEstorno(): bool
    {
        return $this->estorno_de_id !== null;
    }
}
