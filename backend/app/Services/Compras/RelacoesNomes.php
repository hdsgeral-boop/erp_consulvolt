<?php

namespace App\Services\Compras;

/**
 * Eager loading dos nomes de referência nas respostas de Compras, Tesouraria e Armazém (ADR-064):
 * `fornecedor`/`terceiro` = {id, nome, nif} e `produto` = {id, codigo, nome}. Aditivo: os *_id mantêm-se.
 * Incluem fichas eliminadas (soft delete) para o documento histórico continuar a mostrar o nome.
 */
final class RelacoesNomes
{
    /** @return array<string, \Closure> */
    public static function terceiro(string $relacao = 'terceiro'): array
    {
        return [$relacao => fn ($q) => $q->withTrashed()->select(['id', 'nome', 'nif'])];
    }

    /** @return array<string, \Closure> */
    public static function fornecedor(): array
    {
        return self::terceiro('fornecedor');
    }

    /** @return array<string, \Closure> */
    public static function produto(): array
    {
        return ['produto' => fn ($q) => $q->withTrashed()->select(['id', 'codigo', 'nome'])];
    }
}
