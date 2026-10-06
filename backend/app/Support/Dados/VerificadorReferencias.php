<?php

namespace App\Support\Dados;

use App\Exceptions\ErroNegocio;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Onde é que um registo está a ser usado, lido das chaves estrangeiras REAIS do PostgreSQL (não de listas
 * mantidas à mão, que ficam desactualizadas quando o esquema cresce). Serve para bloquear a eliminação de dados
 * mestre em uso — o legado apagava e deixava órfãos (ex.: colaboradores com contratos e lançamentos salariais).
 */
final class VerificadorReferencias
{
    /** @return list<array{tabela: string, coluna: string}> */
    public function referenciasPara(string $tabela): array
    {
        // Chave versionada pela última migração (regra de ouro: versionar em vez de esperar pelo TTL): depois de uma
        // migração que crie uma FK nova, a verificação de «em uso» vê-a logo, e não só ao fim de 1 h.
        $versao = (string) DB::table(config('database.migrations.table', 'migrations'))->max('migration');

        return Cache::remember("fk_referencias:{$versao}:{$tabela}", 3600, fn () => array_map(fn ($r) => ['tabela' => $r->tabela, 'coluna' => $r->coluna], DB::select(<<<'SQL'
            SELECT cl.relname AS tabela, a.attname AS coluna
              FROM pg_constraint c
              JOIN pg_class cl ON cl.oid = c.conrelid
              JOIN pg_class ref ON ref.oid = c.confrelid
              JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
             WHERE c.contype = 'f' AND ref.relname = ? AND array_length(c.conkey, 1) = 1
             ORDER BY 1, 2
            SQL, [$tabela])));
    }

    /**
     * Contagem das utilizações (ignora as tabelas indicadas — dependentes que se eliminam com o registo — e as
     * linhas com eliminação lógica).
     *
     * @param  list<string>  $ignorar
     * @param  array<string, string>  $extra  tabela => coluna, para ligações sem FK (ex.: tabelas novas)
     * @return array<string, int> "tabela.coluna" => n.º de linhas
     */
    public function emUso(string $tabela, int $id, array $ignorar = [], array $extra = []): array
    {
        $refs = $this->referenciasPara($tabela);
        foreach ($extra as $t => $c) {
            $refs[] = ['tabela' => $t, 'coluna' => $c];
        }
        $uso = [];
        foreach ($refs as $r) {
            if (in_array($r['tabela'], $ignorar, true) || ($r['tabela'] === $tabela && $r['coluna'] === 'id')) {
                continue;
            }
            $q = DB::table($r['tabela'])->where($r['coluna'], $id);
            if ($r['tabela'] === $tabela) {
                $q->where('id', '<>', $id);
            }
            if (DB::getSchemaBuilder()->hasColumn($r['tabela'], 'eliminado_em')) {
                $q->whereNull('eliminado_em');
            }
            if ($n = $q->count()) {
                $uso["{$r['tabela']}.{$r['coluna']}"] = $n;
            }
        }

        return $uso;
    }

    /** Lança ErroNegocio (422, REGISTO_EM_USO) se o registo estiver em uso. */
    public function exigirLivre(string $tabela, int $id, string $descricao, array $ignorar = [], array $extra = [], string $sugestao = ''): void
    {
        $uso = $this->emUso($tabela, $id, $ignorar, $extra);
        if ($uso) {
            $onde = implode(', ', array_map(fn ($k, $n) => str_replace('_', ' ', explode('.', $k)[0])." ({$n})", array_keys($uso), $uso));
            throw new ErroNegocio("Não é possível eliminar {$descricao}: está em uso em {$onde}.".($sugestao ? " {$sugestao}" : ''), 'REGISTO_EM_USO', 422, ['utilizacoes' => $uso]);
        }
    }
}
