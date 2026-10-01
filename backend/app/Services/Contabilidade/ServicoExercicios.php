<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoSistema;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueio de exercício encerrado (paridade com o hook global do Dexie, js/db_v2.js:339-390):
 * um exercício está encerrado quando configuracoes_sistema tem chave "closed_year_<empresa>_<ano>" = 'true'.
 * O encerramento grava a chave (js/ui_closing.js:1579-1587) e a reabertura apaga-a (js/ui_closing.js:1827-1829).
 * As regras de quando se pode encerrar ou reabrir estão em ServicoEncerramento; aqui fica só o estado.
 */
final class ServicoExercicios
{
    private const PREFIXO = 'closed_year_';

    public static function chave(int $empresaId, int $ano): string
    {
        return self::PREFIXO."{$empresaId}_{$ano}";
    }

    /**
     * Lido sempre da base: uma memória por instância ficava desactualizada quando outra instância (outro controlador do mesmo
     * processo, trabalhos de fila) encerrava ou reabria o exercício. A tabela tem poucas linhas.
     */
    public function encerrado(int $empresaId, int $ano): bool
    {
        return DB::table('configuracoes_sistema')
            ->where('chave', self::chave($empresaId, $ano))
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

    /**
     * Anos encerrados da empresa, por ordem crescente. A chave tem o id da empresa no meio: compara-se o prefixo
     * exacto em PHP (um LIKE 'closed_year_6_%' também apanharia a empresa 61, porque '_' é um caráter universal).
     *
     * @return list<int>
     */
    public function anosEncerrados(int $empresaId): array
    {
        $prefixo = self::PREFIXO."{$empresaId}_";

        return DB::table('configuracoes_sistema')->where('chave', 'like', self::PREFIXO.'%')->whereRaw("lower(trim(valor)) IN ('true', '1')")
            ->pluck('chave')
            ->filter(fn ($c) => str_starts_with($c, $prefixo) && ctype_digit(substr($c, strlen($prefixo))))
            ->map(fn ($c) => (int) substr($c, strlen($prefixo)))
            ->unique()->sort()->values()->all();
    }

    /** Grava o cadeado do exercício (uma só linha por chave; o chamador serializa com lock da empresa). */
    public function encerrar(int $empresaId, int $ano): void
    {
        $registo = ConfiguracaoSistema::query()->where('chave', self::chave($empresaId, $ano))->orderBy('id')->first();
        if ($registo) {
            $registo->update(['valor' => 'true']);
        } else {
            ConfiguracaoSistema::create(['chave' => self::chave($empresaId, $ano), 'valor' => 'true']);
        }
    }

    /** Retira o cadeado do exercício (como o legado: a chave é apagada; o rasto fica na auditoria). */
    public function reabrir(int $empresaId, int $ano): void
    {
        ConfiguracaoSistema::query()->where('chave', self::chave($empresaId, $ano))->get()->each->delete();
    }
}
