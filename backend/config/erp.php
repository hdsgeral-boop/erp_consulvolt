<?php

/*
|--------------------------------------------------------------------------
| Configuração específica do ERP Consulvolt
|--------------------------------------------------------------------------
*/

return [

    /*
    | Taxas de IVA legais (Angola): 14 normal, 7 intermédia, 5 reduzida, 0 isento — as únicas aceites em produtos e
    | documentos de compra (M3; as do legado, js/ui_sales.js:2319-2324 e js/ui_compras_v2.js:1174).
    */
    'fiscal' => [
        'taxas_iva' => [0, 5, 7, 14],
    ],

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
        // Pedidos que chamam sistemas externos a pedido do utilizador (BAI, relógio biométrico) — limitador «externo».
        'externo_por_minuto' => (int) env('ERP_API_EXTERNO_POR_MINUTO', 6),
        // Importações de folhas e ZIP de recibos — limitador «pesado».
        'pesado_por_minuto' => (int) env('ERP_API_PESADO_POR_MINUTO', 30),
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
        // TTL (segundos) das leituras em cache — chaves erp:{empresa_id}:{modulo}:{chave}. O TTL é só a rede de
        // segurança: a frescura vem das invalidações explícitas (eventos dos models + InvalidacaoCache, depois do
        // commit). TTL mais curto = dados frios de empresas pouco consultadas saem mais cedo do Redis (docs/arquitetura/CACHE.md).
        'ttl' => [
            'empresas_utilizador' => 1800,
            'permissoes_utilizador' => 1800,   // reservado: as permissões ainda não estão em cache (lidas da BD por pedido)
            'plano_contas' => 14400,
            'catalogo_produtos' => 7200,
            'taxas_cambio' => 21600,           // reservado: as taxas de câmbio ainda não estão em cache
            'definicoes_plataforma' => 3600,   // DefinicoesPlataforma: lida em todos os pedidos com empresa activa
            'planos_modulos' => 3600,          // ModulosPlano: lida em todos os pedidos autenticados (menu, Gate)
        ],
        // Conjuntos grandes (plano de contas, catálogo) comprimidos pela aplicação acima deste tamanho serializado
        // (CacheComprimida). NÃO usar Redis::OPT_COMPRESSION na ligação: partiria o INCRBY de Cache::add+increment.
        'comprimir_acima_de_bytes' => (int) env('ERP_CACHE_COMPRIMIR_ACIMA_DE', 8192),
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
        // r4_agt: transporte — tempo de ligação, retentativas só em falhas transitórias (recuo exponencial com jitter)
        'timeout_ligacao' => (int) env('AGT_TIMEOUT_LIGACAO', 10),
        'retentativas' => (int) env('AGT_RETENTATIVAS', 3),
        'recuo_base_ms' => (int) env('AGT_RECUO_BASE_MS', 500),
        'recuo_max_ms' => (int) env('AGT_RECUO_MAX_MS', 8000),
        // domínios aceites no endereço da AGT configurado por empresa (anti-SSRF)
        'hosts_permitidos' => env('AGT_HOSTS_PERMITIDOS', 'minfin.gov.ao'),
        // consultarFactura: a tabela diz documentNo e o exemplo invoiceNo (a confirmar na homologação)
        'campo_consulta_documento' => env('AGT_CAMPO_CONSULTA_DOCUMENTO', 'documentNo'),
        // webhook (a AGT anuncia o callback para «próximas versões»): janela anti-replay e tamanho máximo
        'webhook_janela_segundos' => (int) env('AGT_WEBHOOK_JANELA', 300),
        'webhook_max_bytes' => (int) env('AGT_WEBHOOK_MAX_BYTES', 65536),
        // alerta de documentos por comunicar (a AGT marca «P — válida com penalização» os enviados com mais de 24 h)
        'alerta_horas_por_comunicar' => (int) env('AGT_ALERTA_HORAS', 20),
        'software' => [
            'productId' => env('AGT_PRODUCT_ID', ''),
            'productVersion' => env('AGT_PRODUCT_VERSION', ''),
            'softwareValidationNumber' => env('AGT_SOFTWARE_VALIDATION', ''),
            'productCompanyTaxId' => env('AGT_PRODUTOR_NIF', ''),
            // «signatureVersion — Número de assinatura a aplicar» (registarFactura); vazio = não enviar
            'signatureVersion' => env('AGT_SIGNATURE_VERSION', '1'),
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

    // SaaS (ADR-073). Com `ativo=false`, o sistema comporta-se como antes: sem tenant na sessão e sem restrições por endereço.
    'saas' => [
        'ativo' => (bool) env('SAAS_ATIVO', false),
        // Ligacao com DDL (dono da base) para comandos de manutencao (ex.: particoes de auditoria). null = ligacao por omissao.
        'ligacao_dono' => env('SAAS_LIGACAO_DONO'),
        // Comandos de manutencao (sem utilizador, a atravessar tenants): correm na ligacao do dono, que ignora a RLS.
        'comandos_de_manutencao' => [
            'erp:produtos:converter-contas-categorias', 'erp:migracao:corrigir-dados', 'erp:saft:documentos-importados',
            'erp:migrar-backup-legado', 'erp:migracao:pos-carga', 'erp:tesouraria:diferencas-cambio',
            'erp:tesouraria:reconciliar-recibos', 'erp:e2e:preparar',
        ],
    ],

    // Licença por empresa (ADR-074). Desligado: sem expiração nem período de teste (comportamento anterior).
    // Módulos que um plano pode incluir (registados na versão do plano; condicionam o menu e o Gate — ver ModulosPlano).
    'planos_catalogo' => [
        'modulos' => [
            'vendas' => 'Vendas', 'compras' => 'Compras', 'stock' => 'Stock', 'contab' => 'Contabilidade', 'teso' => 'Tesouraria',
            'rh' => 'Recursos Humanos', 'activos' => 'Activos', 'crm' => 'CRM', 'projectos' => 'Projectos', 'orcamento' => 'Orçamento', 'pos' => 'Ponto de venda',
        ],
    ],

    'licencas' => [
        'ativo' => (bool) env('LICENCAS_ATIVAS', false),
        // 30 dias: alinhado com a promessa comercial da landing page e do registo ("30 dias grátis"). Pode ser
        // alterado sem reiniciar a aplicação em Plataforma → SaaS → "Dias de Teste Padrão" (definicoes_plataforma).
        'dias_teste' => (int) env('LICENCAS_DIAS_TESTE', 30),
    ],

    'dominio' => [
        // Domínio base dos clientes: <slug>.<base> = cliente; admin.erp.<base> = área da plataforma. Vazio = sem restrição.
        'base' => env('DOMINIO_BASE', ''),
        // Tenant que opera a plataforma (acesso à área admin.erp.<base>).
        'plataforma_tenant_id' => (int) env('PLATAFORMA_TENANT_ID', 1),
        // Subdomínios que não podem ser slugs de clientes.
        'slugs_reservados' => ['admin', 'erp', 'www', 'api', 'app', 'mail', 'ftp', 'suporte', 'static', 'site', 'teste'],
    ],
];
