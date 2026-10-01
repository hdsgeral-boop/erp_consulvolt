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
        // Tentativas de login por minuto a partir do mesmo IP, com qualquer nome de utilizador (contra password spraying).
        'tentativas_por_minuto_ip' => (int) env('ERP_SESSAO_TENTATIVAS_POR_MINUTO_IP', 60),
    ],

    'api' => [
        // Limite geral de pedidos à API por utilizador autenticado (ou por IP, sem sessão).
        'pedidos_por_minuto' => (int) env('ERP_API_PEDIDOS_POR_MINUTO', 300),
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

    /*
    | Facturação electrónica AGT (ADR-030). Substitui o serviço intermédio do legado (servico_agt/servidor.js):
    | o próprio backend guarda as credenciais e as chaves e assina os pedidos (JWS RS256). Nada disto vai para
    | a base de dados nem para o browser. As chaves ficam em /run/segredos/agt (volume só de leitura, fora do Git).
    |   driver: desligado (por omissão) | direto (o backend fala com a AGT) | intermedio (reutiliza o serviço do legado)
    */
    'agt' => [
        'driver' => env('AGT_DRIVER', 'desligado'),
        'ambiente' => env('AGT_AMBIENTE', 'homologacao'),
        'urls' => [
            'homologacao' => 'https://sifphml.minfin.gov.ao/sigt/fe/v1/',
            'producao' => 'https://sifp.minfin.gov.ao/sigt/fe/v1/',
        ],
        'url_base' => env('AGT_URL_BASE'),               // só se a AGT mudar o endereço (ex.: /sigt/fe/ws/v1/)
        'utilizador' => env('AGT_UTILIZADOR'),
        'palavra_passe' => env('AGT_PALAVRA_PASSE'),
        'jws_typ' => env('AGT_JWS_TYP', 'JWT'),           // a confirmar na homologação (erros E08/E40)
        'timeout' => (int) env('AGT_TIMEOUT', 30),
        'software' => [
            'productId' => env('AGT_PRODUCT_ID', ''),
            'productVersion' => env('AGT_PRODUCT_VERSION', ''),
            'softwareValidationNumber' => env('AGT_SOFTWARE_VALIDATION', ''),
            'productCompanyTaxId' => env('AGT_PRODUTOR_NIF', ''),
        ],
        'pasta_chaves' => env('AGT_PASTA_CHAVES', '/run/segredos/agt'),
        'chave_produtor' => env('AGT_CHAVE_PRODUTOR', 'produtor_privada.pem'),      // relativo à pasta das chaves
        'pasta_contribuintes' => env('AGT_PASTA_CONTRIBUINTES', 'contribuintes'),   // <NIF>.pem
        // Assinatura SAF-T(AO) dos documentos (Hash): chave privada do produtor certificado e versão (HashControl)
        'chave_saft' => env('AGT_CHAVE_SAFT', 'saft_privada.pem'),
        'versao_chave_saft' => env('AGT_VERSAO_CHAVE_SAFT', '1'),
        // Driver "intermedio": serviço do legado (o token nunca vai para a base de dados)
        'intermedio_url' => env('AGT_INTERMEDIO_URL'),
        'intermedio_token' => env('AGT_INTERMEDIO_TOKEN'),
        'max_documentos' => 30,
        'qr_consulta' => 'https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe',
    ],

    'auditoria' => [
        // Anos com partição criada antecipadamente em logs_auditoria (além da partição DEFAULT).
        'anos_particoes' => [2024, 2025, 2026, 2027, 2028],
    ],

];
