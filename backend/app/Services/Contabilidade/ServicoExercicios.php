<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueio de exercício encerrado (paridade com o hook global do Dexie, js/db_v2.js:339-390):
 * um exercício está encerrado quando configuracoes_sistema tem chave "closed_year_<empresa>_<ano>" = 'true'.
 */
final class ServicoExercicios
{
    /** @var array<string, bool> */
    private array $memoria = [];

    public function encerrado(int $empresaId, int $ano): bool
    {
        return $this->memoria["{$empresaId}|{$ano}"] ??= DB::table('configuracoes_sistema')
            ->where('chave', "closed_year_{$empresaId}_{$ano}")
            ->whereRaw("lower(trim(valor)) IN ('true', '1')")
            ->exists();
    }

    public function exigirAberto(int $empresaId, string $data): void
    {
        $ano = (int) substr($data, 0, 4);
        if ($this->encerrado($empresaId, $ano)) {
            throw new ErroNegocio("O exercício de {$ano} está encerrado: não são permitidos movimentos com essa data.", 'EXERCICIO_ENCERRADO', 422, ['ano' => $ano]);
        }
    }
}
