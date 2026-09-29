<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Numeração sequencial sem duplicados nem saltos (directiva, requisito 3):
 *   1. lock distribuído no Redis  erp:lock:numeracao:{empresa}:{chave}  (serializa entre processos/servidores);
 *   2. SELECT … FOR UPDATE na linha da sequência (serializa até ao COMMIT da transacção que usa o número).
 * Se a transacção do documento falhar, o incremento também é desfeito: nenhum número se perde.
 * Na primeira utilização a sequência parte do maior número já existente (dados migrados do legado).
 */
final class ServicoNumeracao
{
    /** @param  callable(): int  $maiorExistente  maior número já usado (semente, só na 1.ª vez) */
    public function proximo(int $empresaId, string $chave, callable $maiorExistente): int
    {
        try {
            return Cache::lock("lock:numeracao:{$empresaId}:{$chave}", 10)->block(5, fn () => DB::transaction(function () use ($empresaId, $chave, $maiorExistente) {
                $linha = DB::table('sequencias_documentos')->where('empresa_id', $empresaId)->where('chave', $chave)->lockForUpdate()->first();
                if ($linha === null) {
                    DB::table('sequencias_documentos')->insertOrIgnore(['empresa_id' => $empresaId, 'chave' => $chave, 'ultimo_numero' => $maiorExistente()]);
                    $linha = DB::table('sequencias_documentos')->where('empresa_id', $empresaId)->where('chave', $chave)->lockForUpdate()->first();
                }
                $numero = (int) $linha->ultimo_numero + 1;
                DB::table('sequencias_documentos')->where('id', $linha->id)->update(['ultimo_numero' => $numero, 'atualizado_em' => now()]);

                return $numero;
            }));
        } catch (LockTimeoutException) {
            throw new ErroNegocio('Numeração ocupada por outra operação. Tente novamente.', 'NUMERACAO_OCUPADA', 409);
        }
    }
}
