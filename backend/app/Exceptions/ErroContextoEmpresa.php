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

    public static function empresaDeOutroTenant(int $empresaId): self
    {
        // Não revela de que tenant é a empresa: a resposta é igual à de uma empresa inexistente.
        return new self('Não tem acesso à empresa indicada.', 'EMPRESA_SEM_ACESSO', 403);
    }

    public static function tenantNaoDefinido(): self
    {
        // Erro de programação: criação de dados de tenant fora de um pedido, trabalho ou teste com contexto.
        return new self('Operação sobre dados de cliente sem cliente (tenant) activo definido.', 'CONTEXTO_TENANT_EM_FALTA', 500);
    }

    public static function tenantSuspenso(): self
    {
        return new self('A conta desta organização está suspensa. Contacte o suporte.', 'TENANT_SUSPENSO', 403);
    }

    public static function dominioDesconhecido(): self
    {
        return new self('Endereço inexistente.', 'DOMINIO_DESCONHECIDO', 404);
    }

    public static function clienteDesconhecido(): self
    {
        return new self('Endereço inexistente.', 'CLIENTE_DESCONHECIDO', 404);
    }

    public static function contaDeOutroCliente(): self
    {
        // Mesma mensagem do login falhado: não revela a que cliente a conta pertence.
        return new self('Nome de utilizador ou palavra-passe incorrectos.', 'TENANT_NAO_CORRESPONDE', 403);
    }

    public static function areaReservadaAPlataforma(): self
    {
        return new self('Esta área é reservada à administração da plataforma.', 'AREA_RESERVADA', 403);
    }

    public static function licencaExpirada(): self
    {
        return new self('A licença desta empresa expirou: está em modo só de leitura até à renovação.', 'LICENCA_EXPIRADA', 403);
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
