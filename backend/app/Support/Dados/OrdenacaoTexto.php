<?php

namespace App\Support\Dados;

use Illuminate\Database\Query\Builder;

/**
 * Ordenação alfabética portuguesa das listas por nome (ADR-071).
 *
 * A base de dados foi criada com a ordenação `C`: maiúsculas antes de minúsculas e letras acentuadas no fim («Ávila»
 * depois de «Zulmira»). Os nomes migrados do legado trazem ainda espaços iniciais, finais ou duplicados, que partiam
 * a lista em blocos. A macro `ordenarPorNome()` ordena pela colação ICU `pt-PT-x-icu` (incluída na imagem oficial do
 * PostgreSQL 16) sobre o texto com os espaços normalizados, e desempata pela ordem natural da tabela.
 *
 * Uso: `Modelo::query()->ordenarPorNome()` ou `DB::table('empresas as e')->ordenarPorNome('e.nome')`.
 */
final class OrdenacaoTexto
{
    public const COLACAO = 'pt-PT-x-icu';

    public static function registar(): void
    {
        Builder::macro('ordenarPorNome', function (string $coluna = 'nome', string $direccao = 'asc'): Builder {
            /** @var Builder $this */
            return $this->orderByRaw(OrdenacaoTexto::expressao($this->getGrammar()->wrap($coluna)).' '.(strtolower($direccao) === 'desc' ? 'desc' : 'asc'));
        });
    }

    /** Expressão SQL de ordenação para uma coluna já citada pela gramática (nunca texto vindo do pedido). */
    public static function expressao(string $colunaCitada): string
    {
        return "btrim(regexp_replace({$colunaCitada}, '\\s+', ' ', 'g')) COLLATE \"".self::COLACAO.'"';
    }
}
