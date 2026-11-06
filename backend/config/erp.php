<?php

use App\Mail\BoasVindasEmpresaMail;
use App\Mail\ContaSuspensaMail;
use App\Mail\LicencaAVencerMail;
use App\Mail\LicencaExpiradaMail;
use App\Mail\NotificacaoRHMail;
use App\Mail\TicketCriadoMail;
use App\Mail\TicketRespostaMail;
use App\Mail\TicketSlaVioladoMail;
use App\Support\Outbox\Manipuladores\EmailEnviarManipulador;
use App\Support\Outbox\Manipuladores\NotificacaoRHManipulador;

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
        // Validade máxima ABSOLUTA da sessão (2 h), independentemente da actividade e SEM renovação: a rotação do token
        // (RotacionarToken) muda o segredo na mesma linha e nunca o `expira_em`/`criado_em` (ADR-085).
        'validade_horas' => (int) env('ERP_SESSAO_VALIDADE_HORAS', 2),
        // Pedidos automáticos (polling, heartbeat, actualização em segundo plano) NÃO contam como actividade do utilizador:
        // o frontend envia este cabeçalho (só GET/HEAD) e o servidor não renova `ultimo_uso_em` (ADR-085).
        'cabecalho_automatico' => 'X-Erp-Automatico',
        // Step-up (MFA/reautenticação recente) para operações críticas: janela, em minutos, em que a última autenticação
        // forte (login, código MFA ou palavra-passe de reautenticação) ainda vale. Ver ExigirMfaRecente.
        'stepup_minutos' => (int) env('ERP_SESSAO_STEPUP_MINUTOS', 15),
        // Nomes de rota (prefixos) isentos nos grupos `mfa_recente:,escrita`: tickets de suporte e avaliação de perfis (POST sem gravar).
        'stepup_isentas' => ['plataforma.suporte.', 'sistema.perfis.avaliar', 'sistema.empresa_ativa.'],
        'stepup_ativo' => (bool) env('ERP_SESSAO_STEPUP_ATIVO', true),
        // Tentativas de login por minuto (por nome de utilizador + IP).
        'tentativas_por_minuto' => (int) env('ERP_SESSAO_TENTATIVAS_POR_MINUTO', 5),
        // Tentativas de login por minuto a partir do mesmo IP, com qualquer nome de utilizador (contra password spraying).
        'tentativas_por_minuto_ip' => (int) env('ERP_SESSAO_TENTATIVAS_POR_MINUTO_IP', 60),
        // Cookie HttpOnly do token (Fase D, reforço de autenticação) e cookie/cabeçalho do padrão duplo-submit do
        // CSRF (CookiesSessao, VerificarCsrfDuploSubmit). Os atributos de segurança (secure/domain/samesite/path)
        // vêm de config/session.php — já preparados para produção (OWASP A02) — em vez de duplicados aqui.
        'cookie_token' => 'erp_token',
        'cookie_csrf' => 'XSRF-TOKEN',
        'cabecalho_csrf' => 'X-XSRF-TOKEN',
        // Cookie do identificador do dispositivo (emitido pelo servidor, HttpOnly, assinado — ADR-082).
        'cookie_dispositivo' => 'erp_dev',
        // Cookies seguros (Secure + prefixo __Host-): true/false força; vazio = automático (Secure quando o pedido chega por
        // HTTPS, directamente ou por X-Forwarded-Proto de um proxy de confiança). Em http://localhost fica sem Secure nem prefixo.
        'cookie_secure' => env('ERP_COOKIE_SECURE') === null || env('ERP_COOKIE_SECURE') === '' ? null : filter_var(env('ERP_COOKIE_SECURE'), FILTER_VALIDATE_BOOLEAN),
        // SameSite dos cookies de sessão: strict (omissão) | lax. Strict: o cookie nunca acompanha pedidos iniciados por outro
        // site; o regresso do Google e as ligações de e-mail abrem a SPA (HTML estático) e é a SPA, já dentro do site, que
        // chama a API com o cookie. Usar lax só se algum fluxo precisar de GET autenticado vindo de fora.
        'cookie_samesite' => env('ERP_COOKIE_SAMESITE', 'strict'),
        // Validade do cookie do dispositivo, em dias (máx. prático dos navegadores: 400).
        'dispositivo_dias' => (int) env('ERP_SESSAO_DISPOSITIVO_DIAS', 180),
        // Rotação do token (ADR-082): um pedido autenticado com um token com mais de N minutos recebe um token novo
        // (cookie novo); o anterior fica válido só durante a tolerância (pedidos paralelos em voo) e, depois, a sua
        // apresentação é tratada como roubo: revoga a sessão desse dispositivo e avisa o utilizador.
        'rotacao_minutos' => (int) env('ERP_SESSAO_ROTACAO_MINUTOS', 10),
        'rotacao_tolerancia_segundos' => (int) env('ERP_SESSAO_ROTACAO_TOLERANCIA_SEGUNDOS', 45),
        // Máximo de sessões completas simultâneas por utilizador (dispositivos); ao exceder, a mais antiga termina. 0 = sem limite.
        'max_sessoes_por_utilizador' => (int) env('ERP_SESSAO_MAX_POR_UTILIZADOR', 10),
        // Vincula o token ao navegador+sistema operativo do início da sessão: uma mudança de família (ex.: Chrome→Firefox)
        // termina a sessão. Versões novas do mesmo navegador não contam. Mudanças de IP nunca terminam a sessão (só auditoria).
        'vincular_agente' => (bool) env('ERP_SESSAO_VINCULAR_AGENTE', true),
        // Defesa em profundidade do CSRF: em pedidos que alteram estado, com sessão por cookie, rejeita Origin/Sec-Fetch-Site
        // de outro site. Origens extra aceites (além do próprio anfitrião do pedido), separadas por vírgulas.
        'verificar_origem' => (bool) env('ERP_SESSAO_VERIFICAR_ORIGEM', true),
        'origens_permitidas' => env('ERP_SESSAO_ORIGENS_PERMITIDAS', ''),
        // Recuperação de palavra-passe (POST /autenticacao/recuperar-password): pedidos por CONTA por hora, intervalo mínimo
        // entre pedidos da mesma conta (s) e duração mínima da resposta (ms) para o tempo não revelar se a conta existe.
        'recuperacao_max_por_hora' => (int) env('ERP_RECUPERACAO_MAX_POR_HORA', 3),
        'recuperacao_intervalo_segundos' => (int) env('ERP_RECUPERACAO_INTERVALO_SEGUNDOS', 120),
        'recuperacao_tempo_minimo_ms' => (int) env('ERP_RECUPERACAO_TEMPO_MINIMO_MS', 1200),
    ],

    'api' => [
        // Limite geral de pedidos à API por utilizador autenticado (ou por IP, sem sessão).
        'pedidos_por_minuto' => (int) env('ERP_API_PEDIDOS_POR_MINUTO', 300),
        // Pedidos que chamam sistemas externos a pedido do utilizador (BAI, relógio biométrico) — limitador «externo».
        'externo_por_minuto' => (int) env('ERP_API_EXTERNO_POR_MINUTO', 6),
        // Importações de folhas e ZIP de recibos — limitador «pesado».
        'pesado_por_minuto' => (int) env('ERP_API_PESADO_POR_MINUTO', 30),
        'ia_por_minuto' => (int) env('ERP_API_IA_POR_MINUTO', 20),             // assistente IA: envios por utilizador e minuto
        'ia_cliente_por_minuto' => (int) env('ERP_API_IA_CLIENTE_POR_MINUTO', 120),   // e por cliente (soma dos utilizadores)
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

    'imagens' => [
        // Disco PÚBLICO onde ficam as imagens (logótipos das empresas, logótipo do ecrã de entrada, imagens de produtos):
        // «imagens» = Cloudflare R2 (API S3, config/filesystems.php). A aplicação guarda só o URL público e o navegador
        // lê a imagem directamente da Cloudflare — nada de base64 na base de dados nem ficheiros no servidor.
        // Vazio = comportamento antigo (data URI na base / ficheiro privado), enquanto o R2 não estiver configurado.
        'disco' => env('ERP_IMAGENS_DISCO', ''),
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
    // Plano de contas modelo (ServicoPlanoContasModelo): empresa (do tenant da plataforma) cujo plano é copiado para empresas
    // novas ou sem contas. Vazio = a empresa mais antiga do tenant da plataforma.
    'plano_contas' => [
        'empresa_modelo' => env('PLANO_CONTAS_EMPRESA_MODELO'),
    ],

    'saas' => [
        'ativo' => (bool) env('SAAS_ATIVO', false),
        // Ligacao com DDL (dono da base) para comandos de manutencao (ex.: particoes de auditoria). null = ligacao por omissao.
        'ligacao_dono' => env('SAAS_LIGACAO_DONO'),
        // Palavra-passe da role erp_app (sujeita a RLS). Aqui e não com env() no código: com `artisan optimize` (config em cache,
        // como no deploy) env() fora de config/ devolve vazio e a palavra-passe da role nunca seria definida.
        'db_app_password' => env('DB_APP_PASSWORD', ''),
        // Simulação de pagamento (`subscricoes/{id}/simular-confirmacao`). Condicionada a esta flag e NÃO a APP_ENV: o servidor
        // de teste corre com APP_ENV=production e precisa da simulação; em produção real fica desligada.
        'simulacao_pagamento' => (bool) env('SAAS_SIMULACAO_PAGAMENTO', false),
        // Comandos de manutencao (sem utilizador, a atravessar tenants): correm na ligacao do dono, que ignora a RLS.
        'comandos_de_manutencao' => [
            'erp:produtos:converter-contas-categorias', 'erp:migracao:corrigir-dados', 'erp:saft:documentos-importados',
            'erp:migrar-backup-legado', 'erp:migracao:pos-carga', 'erp:tesouraria:diferencas-cambio',
            'erp:tesouraria:reconciliar-recibos', 'erp:e2e:preparar', 'erp:licencas:planos-iniciais', 'erp:seguranca:rodar-chave',
        ],
    ],

    // Transactional Outbox genérico (ADR-080): eventos gravados na MESMA transacção da escrita de negócio e entregues «pelo menos uma
    // vez» a manipuladores IDEMPOTENTES, por tipo. Publicação imediata após o COMMIT + agendador de minuto a minuto (erp:outbox:publicar).
    'outbox' => [
        'max_tentativas' => (int) env('ERP_OUTBOX_MAX_TENTATIVAS', 8),
        // recuo (segundos) entre tentativas, com jitter de ±20 %: 30 s, 2 min, 10 min, 30 min, 2 h, 6 h, 12 h (o último repete-se)
        'recuo_segundos' => [30, 120, 600, 1800, 7200, 21600, 43200],
        'reserva_segundos' => (int) env('ERP_OUTBOX_RESERVA_SEGUNDOS', 120),   // lease de um evento em processamento
        'aviso_atraso_segundos' => 600,                                          // /api/saude: AVISO se o pendente mais antigo exceder
        // tipo de evento => classe ManipuladorOutbox (resolvida no contentor)
        'manipuladores' => [
            'notificacao.rh' => NotificacaoRHManipulador::class,
            'email.enviar' => EmailEnviarManipulador::class,
        ],
        // lista branca dos Mailables que o evento `email.enviar` pode instanciar (nunca uma classe vinda da carga sem constar aqui)
        'emails_permitidos' => [
            BoasVindasEmpresaMail::class,
            ContaSuspensaMail::class,
            TicketCriadoMail::class,
            TicketRespostaMail::class,
            TicketSlaVioladoMail::class,
            LicencaAVencerMail::class,
            LicencaExpiradaMail::class,
            NotificacaoRHMail::class,
        ],
    ],

    // Notificações de RH / Método / Estrutura (Fase K, ADR-079): o centro de notificações é sempre usado; o e-mail é uma ponte
    // opcional (desligada por omissão) que envia, em fila, o mesmo texto ao e-mail do utilizador destinatário.
    'notificacoes' => [
        'email' => (bool) env('ERP_NOTIFICACOES_EMAIL', false),
    ],

    // Licença por empresa (ADR-074). Desligado: sem expiração nem período de teste (comportamento anterior).
    // Módulos que um plano pode incluir (registados na versão do plano; condicionam o menu e o Gate — ver ModulosPlano).
    'planos_catalogo' => [
        'modulos' => [
            'vendas' => 'Vendas', 'compras' => 'Compras', 'stock' => 'Stock', 'contab' => 'Contabilidade', 'teso' => 'Tesouraria',
            'rh' => 'Recursos Humanos', 'activos' => 'Activos', 'crm' => 'CRM', 'projectos' => 'Projectos', 'orcamento' => 'Orçamento', 'pos' => 'Ponto de venda',
            'acrescimos' => 'Acréscimos e Diferimentos', 'estrutura' => 'Estrutura Orgânica', 'metodo' => 'Método de Trabalho',
            'estrategia' => 'Planeamento Estratégico',
            'imobiliario' => 'Gestão Imobiliária',
        ],
        // Ciclos de facturação da subscrição SaaS e o desconto de cada um (CalculadoraPrecoPlano). Os códigos ficam
        // gravados em subscricoes_empresa.ciclo_codigo: mudar aqui a percentagem não invalida subscrições já pagas.
        'ciclos' => [
            'MENSAL' => ['meses' => 1, 'desconto_percentagem' => 0, 'rotulo' => 'Mensal'],
            'TRIMESTRAL' => ['meses' => 3, 'desconto_percentagem' => 5, 'rotulo' => 'Trimestral'],
            'SEMESTRAL' => ['meses' => 6, 'desconto_percentagem' => 10, 'rotulo' => 'Semestral'],
            'ANUAL' => ['meses' => 12, 'desconto_percentagem' => 20, 'rotulo' => 'Anual'],
        ],
    ],

    'licencas' => [
        'ativo' => (bool) env('LICENCAS_ATIVAS', false),
        // 30 dias: alinhado com a promessa comercial da landing page e do registo ("30 dias grátis"). Pode ser
        // alterado sem reiniciar a aplicação em Plataforma → SaaS → "Dias de Teste Padrão" (definicoes_plataforma).
        'dias_teste' => (int) env('LICENCAS_DIAS_TESTE', 30),
        // Janela de tolerância após expirar antes de passar a SUSPENSA (MaquinaEstadosLicenca) — extensão SaaS,
        // secção 10 (segurança.txt). Omissão 0: preserva o comportamento actual (ADR-074, bloqueio imediato no dia
        // seguinte, "sem tolerância nem dias perdidos") — decisão confirmada; o estado TOLERANCIA fica pronto na
        // máquina de estados mas só é atingido se este valor for aumentado.
        'dias_tolerancia' => (int) env('LICENCAS_DIAS_TOLERANCIA', 0),
    ],

    // Facturação AGT automática da subscrição SaaS (motor de precificação, Módulo D). empresa_id=0 desliga a
    // emissão automática (ServicoFaturacaoSaas sai sem fazer nada) — ex.: ambientes de teste/homologação AGT, onde
    // não há empresa de facturação da plataforma criada. Preenchido pelo comando erp:saas:criar-empresa-faturacao.
    'faturacao_saas' => [
        'empresa_id' => (int) env('SAAS_FATURACAO_EMPRESA_ID', 0),
        'produto_id' => (int) env('SAAS_FATURACAO_PRODUTO_ID', 0),
        'conta_cliente' => env('SAAS_FATURACAO_CONTA_CLIENTE', ''),
    ],

    'dominio' => [
        // Domínio base dos clientes: <slug>.<base> = cliente; admin.erp.<base> = área da plataforma;
        // portal.<base> = portal autónomo do colaborador (partilhado por todos os clientes). Vazio = sem restrição.
        'base' => env('DOMINIO_BASE', ''),
        // Tenant que opera a plataforma (acesso à área <prefixo_plataforma>.<base>).
        'plataforma_tenant_id' => (int) env('PLATAFORMA_TENANT_ID', 1),
        // Subdomínio da área da plataforma SaaS: <prefixo>.<base>. Configurável por DOMINIO_PLATAFORMA (ex.: gestao-admin).
        'prefixo_plataforma' => env('DOMINIO_PLATAFORMA', 'admin.erp'),
        // Subdomínios que não podem ser slugs de clientes («portal» tem área própria em DominioPedido; mantido aqui
        // também, em segunda linha de defesa, para nenhum cliente o poder reclamar como slug).
        'slugs_reservados' => array_values(array_unique(array_merge(
            ['admin', 'erp', 'www', 'api', 'app', 'mail', 'ftp', 'suporte', 'static', 'site', 'teste', 'portal'],
            [env('DOMINIO_PLATAFORMA', 'admin.erp')],
        ))),
        // Segredo opcional do primeiro acesso da plataforma (cabeçalho X-Setup-Token). Vazio = só o host da plataforma protege.
        'plataforma_setup_token' => env('PLATAFORMA_SETUP_TOKEN', ''),
    ],
];
