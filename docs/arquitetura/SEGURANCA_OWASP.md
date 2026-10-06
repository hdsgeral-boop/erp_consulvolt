# Segurança — auditoria OWASP Top 10

> Auditoria de 2026-10-06 (backend Laravel 12, nginx, Redis, frontend React), sobre o código da ronda 2 (ADR-068).
> Referência: OWASP Top 10 **2021**, com a correspondência para a edição **2025** no fim. Testes:
> `OwaspControloAcessoTest` (varrimento A01), `OwaspSegurancaTest` (A03/A04/A05/A07/A10), `CompressaoCacheRedisTest`,
> `RegrasCacheTest`, e os existentes `SegurancaAutorizacaoTest`, `IsolamentoEmpresaTest`, `AutenticacaoTest`,
> `PowerBIFeedTest`, `SaudeTest`, `AssistenteIATest`.

Legenda do estado: **Corrigido** (alterado nesta auditoria, com teste) · **OK** (controlo existente verificado) ·
**Decidir** (precisa de decisão do utilizador) · **Aceite** (risco residual documentado).

## Matriz

| # | Risco | Controlos existentes | Achados | Correcção | Teste | Estado |
|---|---|---|---|---|---|---|
| A01-1 | Controlo de acesso — `exigir` em todas as rotas | `Controller::exigir` (Gate + `ServicoPermissoes`), `ResolverEmpresaAtiva` (X-Empresa-Id ∈ empresas acessíveis), global scope `PertenceEmpresa` | Varrimento de **todas** as rotas com empresa (centenas de pedidos, GET/POST/PUT/DELETE) com um utilizador sem permissões: **`PUT /api/rh/configuracao` com corpo vazio devolvia 200** (e a configuração de RH). As restantes recusam (403/404), validam antes de autorizar mas autorizam logo a seguir (422 + `exigir` na acção — aceitável), ou são pessoais por desenho (portal do colaborador, autoavaliação, preferências, menu, identidade, moedas) | `ConfiguracaoRHController::gravarConfig` exige `config_empresas_gerir` **ou** `rh_ferias_edit` antes de validar | `OwaspControloAcessoTest` (falha se uma rota nova não recusar e não estiver na lista justificada) | Corrigido |
| A01-2 | IDOR entre empresas | `PertenceEmpresa` (scope + `empresa_id` automático), `Rule::exists(...)->where('empresa_id')` nas validações novas (ex.: classificação de lançamentos), `ServicoComparacaoEmpresas` valida cada empresa pedida | Nenhum novo. Feed OData: empresa vem do token, nunca do pedido | — | `IsolamentoEmpresaTest`, `PowerBIFeedTest` | OK |
| A01-3 | Rotas de leitura sem permissão de ecrã | — | `POST /api/orcamento/verificar` (simulação do controlo orçamental) não exigia permissão: revelava orçado/consumido de uma rubrica a quem tinha acesso à empresa. Nenhum ecrã do frontend a chama (o controlo corre na gravação dos documentos) | Exige a mesma lista *any-of* de `POST /api/orcamento/pedidos-excesso` (`compras_ped_criar`, `compras_enc_criar`, `compras_fact_registar`, `teso_doc_emitir`, `lancamentos_post`, `orc_alertas_view`) — constante `OrcamentoController::PERMISSOES_DOCUMENTOS_CONTROLADOS`; retirada da lista justificada (ADR-069) | `OrcamentoControloTest` (403 só com `dashboard_view`), `OwaspControloAcessoTest` | Corrigido |
| A01-4 | Mass assignment | 170 models com `$fillable`, nenhum `$guarded = []`, nenhum `$request->all()`; `Model::shouldBeStrict` fora de produção | — | — | — | OK |
| A01-5 | Cache como bypass | Chaves com empresa/utilizador; invalidação depois do commit | `gestao:comparacao` verificava o acesso às empresas dentro do `remember` (até 120 s de dados para quem perdeu o acesso) | Verificação movida para antes do `remember` (`ServicoComparacaoEmpresas::garantirAcesso`, chamada pelo controlador e de novo no serviço) — ADR-069; CACHE.md §2 | `GestaoPaineisTest::comparacao_em_cache_deixa_de_ser_vista_logo_que_o_acesso_e_retirado`, `InvalidacaoCacheTest` | Corrigido |
| A02-1 | Palavras-passe | Argon2id (`HASH_DRIVER=argon2id`), PBKDF2 legado migrado no 1.º login | — | — | `AutenticacaoTest` | OK |
| A02-2 | Tokens | Sanctum: SHA-256 em `personal_access_tokens`; BI: `erpbi_` + 48 aleatórios, só o SHA-256 na BD, prefixo para identificação, expiração e revogação | — | — | `PowerBIFeedTest` | OK |
| A02-3 | Segredos fora do Git | `.env`, `.segredos/`, `*credenciais*` no `.gitignore`; chaves AGT em volume só de leitura; `git grep` sem chaves/palavras-passe | — | — | — | OK |
| A02-4 | HTTPS / HSTS | TLS no reverse proxy; nginx envia HSTS quando `X-Forwarded-Proto=https`; `HTTPS=on` para o Laravel | — | — | — | OK |
| A02-5 | Cookies (sessão passa a `cookie`) | Nenhuma rota inicia sessão (API Bearer, `sanctum.guard=[]`, sem rotas web); cookie cifrado com `APP_KEY`, `HttpOnly`, `SameSite=Lax` | `secure` dependia só de `SESSION_SECURE_COOKIE` | `config/session.php`: `secure` = `true` por omissão quando `APP_ENV=production` | — | Corrigido |
| A03-1 | SQL bruto / `whereRaw` / ordenação | Interpolações em `DB::select` vêm de definições internas (cubo, painéis, validações de dados, relatórios); valores por *bindings*; nenhuma ordenação vinda do pedido; OData `$select` validado contra a lista de propriedades, `$top/$skip` só dígitos, `$filter/$orderby/$expand…` recusados (501) | Nenhum explorável (amostragem de todos os `DB::select("…{$…}")`) | — | `PowerBIFeedTest` | OK |
| A03-2 | Injecção de fórmulas em folhas (CSV/Excel) | Frontend: escritor .xlsx próprio grava texto como `inlineStr` (nunca fórmula) | Backend: o *binder* por omissão do PhpSpreadsheet transformava em **fórmula** qualquer texto começado por `=` escrito com `setCellValue/fromArray` (ex.: descrição de uma nota em `ServicoSaldosHistoricos::modeloExcel`, folhas de referência de `ServicoImportacaoRH`) | `ValorSemFormulas` registado globalmente (`Cell::setValueBinder`): textos começados por `= + - @ TAB CR` (não numéricos) ficam texto; `paraCsv()` para CSV | `OwaspSegurancaTest::a03_*` | Corrigido |
| A03-3 | XSS (frontend e impressão) | React escapa; documentos impressos com `esc()` em todos os campos; `renderToStaticMarkup` só num `<template>` inerte; logótipos só `data:image/(png|jpeg|gif|webp)` (SVG recusado); CSP `script-src 'self'` em produção | — | — | testes do motor de impressão (frontend) | OK |
| A03-4 | Importações Excel/CSV | `mimes/extensions` + `max` (5–20 MB) em todas; leitura com PhpSpreadsheet (sem macros); importações transaccionais com simulação | — | — | testes de importação | OK |
| A04-1 | Limites de taxa nas rotas sensíveis novas | `throttle:api` 300/min; login por utilizador+IP e por IP; OData 120/min por token; assistente IA 20/min | Pedidos a sistemas externos a pedido do utilizador (BAI, relógio) e operações pesadas (importações, ZIP de recibos) só tinham o limite geral | Limitadores `externo` (6/min) e `pesado` (30/min) no `AppServiceProvider`, aplicados às rotas da ronda 2 | `OwaspSegurancaTest::a04_*` | Corrigido |
| A04-2 | IA — prompt injection a partir de dados | O assistente só **propõe**: nunca grava; o sistema instrui a ignorar instruções no documento; `validar()` no servidor (contas de movimento, equilíbrio, diário); a gravação passa pelo formulário normal (nova validação) | — | — | `AssistenteIATest` | OK |
| A05-1 | APP_DEBUG / erros | `.env.prod.example` `APP_DEBUG=false`; `ManipuladorExcecoesApi` sem detalhes internos | — | — | — | OK |
| A05-2 | Cabeçalhos / CSP / listagem | Produção: CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, CORP, `server_tokens off`, ficheiros ocultos bloqueados, sem `autoindex`, `fastcgi_hide_header X-Powered-By` | Desenvolvimento sem CSP (aceitável) | — | — | OK |
| A05-3 | CORS | — | Sem `config/cors.php`: valia o `*` do Laravel em `api/*` | `config/cors.php`: nenhuma origem por omissão (`ERP_CORS_ORIGENS`) | `OwaspSegurancaTest::a05_*` | Corrigido |
| A05-4 | `/api/saude` sem detalhes internos | Erros só em depuração | Mostrava a quem quer que fosse as versões exactas do PostgreSQL e do Redis | Versões só com token Sanctum válido ou em depuração | `OwaspSegurancaTest::a05_*`, `SaudeTest` | Corrigido |
| A06 | Componentes vulneráveis | `composer audit` e `npm audit --omit=dev --audit-level=high` no CI (ADR-069) | `composer audit`: **0**. `npm audit --omit=dev`: **2 moderadas** (`react-router`/`react-router-dom` 6.x — open redirect com `\` em destinos de `<Link>`/`navigate` vindos do utilizador, que o frontend não usa; deserialização em SSR, que não usamos); correcção só com a 7.x (quebra) | Migração para o react-router 7 **adiada** (moderadas, sem vector explorável no uso actual; mudança com quebras em todo o roteamento — ADR-069). O CI falha a partir de «high» | CI | Aceite |
| A07-1 | Limites de login / expiração / revogação | 5/min por utilizador+IP, 60/min por IP (+ `limit_req` 20/min no nginx); token 12 h absoluto + 15 min de inactividade; `sair` revoga; utilizador inactivo perde logo o acesso | — | — | `AutenticacaoTest`, `SegurancaAutorizacaoTest` | OK |
| A07-2 | Enumeração de utilizadores | Mensagem única `CREDENCIAIS_INVALIDAS` | **Por tempo**: sem utilizador não se calculava nenhum hash (~ms) contra ~100 ms do Argon2id; utilizador inactivo também saía antes do hash | Verificação contra um hash Argon2id fictício com os parâmetros de produção; a credencial é sempre verificada | `OwaspSegurancaTest::a07_*` | Corrigido |
| A08-1 | Uploads / desserialização | Sem `unserialize` de dados do utilizador; `CacheComprimida` com `allowed_classes=false`; cópias de empresa em JSON | — | — | `CompressaoCacheRedisTest` | OK |
| A08-2 | Assinaturas AGT / CI | JWS RS256 com chaves fora do Git; SAF-T com hash encadeado; CI com Pint, testes e build | CI sem `composer audit`/`npm audit` e com *actions* fixadas por etiqueta (não por SHA) | `.github/workflows/ci.yml`: `composer audit` (backend), `npm audit --omit=dev --audit-level=high` (frontend) e as 6 *actions* fixadas pelo SHA de commit da tag oficial, com a versão em comentário (ADR-069) | CI | Corrigido |
| A09 | Registo e monitorização | `Auditavel` nos models (inclui `ConfiguracaoRH`, tokens BI, câmbios BAI, regras IA); falhas de login auditadas; logs JSON; nenhum `Log::` com tokens/palavras-passe | — | — | `AuditoriaTest` | OK |
| A10-1 | SSRF — relógio biométrico (URL do utilizador) | Só http(s), sem credenciais, sem redireccionamentos, tempo limite, literal `169.254.*` recusado | Contornável: `http://2852039166/` (= 169.254.169.254), `0x7f.0.0.1`, `[::ffff:169.254.169.254]`, `localhost`, serviços internos (`redis`, `postgres`, `app:9000`), nomes que resolvem para metadados, DNS rebinding; corpo lido todo para a memória antes de medir | `GuardaUrlSaida` (esquema, credenciais, formas numéricas, loopback/link-local/multicast/metadados/IPv4 mapeado, serviços e portas internas, **ligação presa aos IP verificados** com `CURLOPT_RESOLVE`); corpo lido em *stream* e cortado a 5 MB. Redes privadas aceites (o relógio está na LAN) | `OwaspSegurancaTest::a10_*` | Corrigido |
| A10-2 | SSRF — BAI, AGT, Anthropic | URL fixo no código (BAI) ou na configuração do servidor (AGT, `ERP_IA_URL`) — nunca do pedido | — | — | testes com `Http::fake` | OK |

## Decisões tomadas (ADR-069, 2026-10-06)

1. **A01-3** — `POST /api/orcamento/verificar` passou a exigir a lista *any-of* do pedido de excesso (corrigido).
2. **A06** — migração do `react-router-dom` para 7.x **adiada**: as 2 vulnerabilidades são moderadas e não exploráveis
   no uso actual; o CI regista-as e só falha a partir de «high».
3. **A08-2** — `composer audit`, `npm audit --omit=dev --audit-level=high` e *actions* por SHA no CI (corrigido).
4. **Proxy** — o nginx de produção confia no `X-Forwarded-For` vindo de redes privadas: PRODUCAO.md §5 explica como
   restringir `set_real_ip_from` ao endereço do reverse proxy (acção de instalação, não alterada no código porque o
   endereço depende do servidor).

## Nota de ambiente (desenvolvimento)

Na montagem Windows do Docker Desktop, o `DirectoryIterator` do PHP salta entradas, de forma intermitente, em pastas
grandes (medições: `tests/Feature` 45 de 89 e 48 de 90; `app/Models` 130 de 177 numa medição, completo noutra), enquanto
`scandir`/`glob`/`readdir` vêem tudo: o PHPUnit com `<directory>` descobre só parte dos testes (~44; «No tests found»
com `--filter`). Só afecta o desenvolvimento (o CI e as imagens de produção não usam a montagem). Contorno oficial:
`sh ferramentas/testes/correr_backend.sh` (lista explícita de ficheiros; argumentos extra seguem para o PHPUnit) — ver
README e ADR-069. O código da aplicação lista pastas com `scandir`/`glob` (`ChavesAgt`, `RegrasCacheTest`).

## Correspondência OWASP Top 10 2021 → 2025

| 2021 (linhas da matriz) | 2025 |
|---|---|
| A01 Controlo de acesso; A10 SSRF | A01:2025 Broken Access Control (o SSRF passou a fazer parte do A01) |
| A05 Configuração insegura | A02:2025 Security Misconfiguration |
| A06 Componentes vulneráveis; A08-2 CI | A03:2025 Software Supply Chain Failures |
| A02 Falhas criptográficas | A04:2025 Cryptographic Failures |
| A03 Injecção | A05:2025 Injection |
| A04 Desenho inseguro | A06:2025 Insecure Design |
| A07 Autenticação | A07:2025 Authentication Failures |
| A08-1 Integridade de dados | A08:2025 Software or Data Integrity Failures |
| A09 Registo e monitorização | A09:2025 Security Logging and Alerting Failures |
| (tratamento de erros: A05-1, `ManipuladorExcecoesApi`; Redis em baixo: CACHE.md §4.9) | A10:2025 Mishandling of Exceptional Conditions |
