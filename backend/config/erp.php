<?php

/*
|--------------------------------------------------------------------------
| Configuração específica do ERP Consulvolt
|--------------------------------------------------------------------------
*/

return [

    'sessao' => [
        // Paridade com o legado (js/app_v2.js:704-723): logout automático após 15 minutos sem actividade.
        'inatividade_minutos' => (int) env('ERP_SESSAO_INATIVIDADE_MINUTOS', 15),
        // Validade máxima absoluta de um token, independentemente da actividade.
        'validade_horas' => (int) env('ERP_SESSAO_VALIDADE_HORAS', 12),
        // Tentativas de login por minuto (por nome de utilizador + IP).
        'tentativas_por_minuto' => (int) env('ERP_SESSAO_TENTATIVAS_POR_MINUTO', 5),
    ],

    'password' => [
        // Tamanho mínimo (paridade: js/data/servicos.js:43).
        'minimo' => 8,
        // Formato legado (js/data/senhas.js): PBKDF2-HMAC-SHA256, 32 bytes, salt 16 bytes, Base64.
        'legado' => [
            'prefixo_algoritmo' => 'PBKDF2-SHA256-',
            'iteracoes_por_omissao' => 120000,
            'bytes_derivados' => 32,
        ],
    ],

    'tenancy' => [
        // Cabeçalho HTTP com a empresa activa em cada pedido.
        'cabecalho' => 'X-Empresa-Id',
    ],

    'cache' => [
        // TTL (segundos) das leituras em cache — chaves erp:{empresa_id}:{modulo}:{chave}.
        'ttl' => [
            'empresas_utilizador' => 3600,
            'permissoes_utilizador' => 3600,
            'plano_contas' => 86400,
            'catalogo_produtos' => 21600,
            'taxas_cambio' => 43200,
        ],
    ],

    'auditoria' => [
        // Anos com partição criada antecipadamente em logs_auditoria (além da partição DEFAULT).
        'anos_particoes' => [2024, 2025, 2026, 2027, 2028],
    ],

];
