<?php

namespace App\Exceptions;

final class ErroContextoEmpresa extends ErroNegocio
{
    public static function naoIndicado(string $cabecalho): self
    {
        return new self("Indique a empresa activa no cabeçalho {$cabecalho}.", 'EMPRESA_NAO_INDICADA', 422);
    }

    public static function invalido(string $cabecalho): self
    {
        return new self("O cabeçalho {$cabecalho} tem de ser um identificador numérico de empresa.", 'EMPRESA_INVALIDA', 422);
    }

    public static function semAcesso(): self
    {
        return new self('Não tem acesso à empresa indicada.', 'EMPRESA_SEM_ACESSO', 403);
    }

    public static function inativa(): self
    {
        return new self('A empresa indicada está inactiva.', 'EMPRESA_INATIVA', 403);
    }

    public static function naoDefinido(): self
    {
        // Erro de programação (consulta multi-empresa sem contexto): nunca deve chegar ao utilizador final.
        return new self('Operação sobre dados de empresa sem empresa activa definida.', 'CONTEXTO_EMPRESA_EM_FALTA', 500);
    }

    public static function empresaDiferente(int $esperada, int $recebida): self
    {
        return new self(
            "Tentativa de gravar dados da empresa {$recebida} com a empresa {$esperada} activa.",
            'EMPRESA_CRUZADA',
            403,
        );
    }
}
