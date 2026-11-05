# Blindagem de segurança — Novembro de 2026

Auditoria pedida pelo utilizador: «validar login no servidor e não depender do frontend/localStorage; navegação pela URL só com acesso; sem ficheiros nem SQL arbitrários em pesquisas/filtros; certificado SSL actual e profissional; pontos críticos de ataque». Ramo `paridade-bd-front`. Decisão: **ADR-083** (`docs/arquitetura/DECISOES.md`). Base já existente (não duplicada): ADR-069 (OWASP), ADR-082 (sessões/cookies/CSRF), `docs/arquitetura/SEGURANCA_OWASP.md`, `AUDITORIA_AUTORIZACAO_2026-11.md`, `SESSOES_E_COOKIES.md`.

Verificações em teste/produção: **apenas leitura** (`openssl s_client`, `curl -I`, `aws … describe/list/get` com o perfil `erp-cli`, eu-north-1). Nada foi alterado na AWS nem nos servidores; nenhum `.env` foi lido.

## 1. Resumo

| Severidade | Achados | Corrigidos no repositório | Pendentes (acção do coordenador / decisão) |
|---|---|---|---|
| Crítica | 0 | — | — |
| Alta | 4 | 3 (A-1, A-2, A-3; A-1 ainda **por implantar**) | 1 (A-4: CloudFront/WAF) |
| Média | 6 | 2 (M-6, M-7) | 4 (M-1 a M-4) |
| Baixa / informativa | 7 | 0 (todas aceites ou já resolvidas) | recomendações |

(«Corrigidos» = código/configuração alterados e testados no repositório; a produção e o teste só mudam depois do deploy do coordenador.)

## 2. Pontos pedidos — conclusão

1. **Autenticação/autorização no servidor — já cumpridas, verificadas.**
   - (a) `localStorage`/`sessionStorage` só guardam preferências de interface (modo de menu, colunas, alertas dispensados, orientação, tour); **nenhum** token, perfil, permissão, papel ou empresa activa. Teste: `sessao/semAutenticacaoNoNavegador.test.ts` e `sessao/armazenamento.test.ts` (ADR-082). A empresa activa vive em `tokens_acesso.empresa_ativa_id` (servidor).
   - (b) Toda rota `api/*` tem `auth:sanctum` excepto a lista branca `PUBLICAS` (12 rotas, justificadas). Teste de inventário: `OwaspControloAcessoTest::toda_a_rota_esta_classificada_publica_so_autenticada_de_area_ou_com_empresa` — falha se aparecer uma rota nova sem autenticação fora da lista, ou sem empresa/área, e se uma entrada da lista ficar obsoleta.
   - (c) Permissão por recurso reavaliada em cada pedido (`Controller::exigir` → `ServicoPermissoes`, a partir da BD/cache invalidada; nunca de dados do cliente). Teste: `OwaspControloAcessoTest::todas_as_rotas_com_empresa_recusam_um_utilizador_sem_permissoes` percorre **todas** as rotas com empresa (centenas de pedidos GET/POST/PUT/DELETE) com um utilizador sem permissões — 403/404 em todas excepto as pessoais justificadas.
   - (d) Isolamento por empresa: RLS + `PertenceEmpresa` + `X-Empresa-Id` validado contra as empresas do utilizador (`ResolverEmpresaAtiva`); 170 modelos com `$fillable`, nenhum `$guarded = []`, nenhum `$request->all()`/`Model::unguard`; `forceFill` só no próprio utilizador (MFA, Google). Testes: `IsolamentoEmpresaTest`, `AuditoriaEscaladaHorizontalTest`.
   - (e) Escalada de privilégios: `AuditoriaEscaladaVerticalTest` (não se atribui perfil superior, nem a si próprio, nem papéis/acesso global).
   - (f) Revogação: sair, mudar palavra-passe, desactivar utilizador e mudar permissões invalidam já (`SegurancaAutorizacaoTest`, `SessoesRotacaoTest`).
   - (g) Expiração absoluta (12 h) e por inactividade (15 min) no servidor, rotação de token com detecção de reutilização, limites de login por utilizador+IP e por IP, MFA TOTP (`BloquearTokenMfaPendente`), troca obrigatória de palavra-passe.
2. **Navegação pela URL — cumprida; teste novo.** `Ecra.tsx` só monta o componente se `decidirEcra` o encontrar no **menu devolvido pelo servidor**; `/admin/*`, `/suporte/*` e `/portal/*` têm guarda própria em `App.tsx` (super-administrador/operador, agente de suporte, empresa escolhida); a API devolve 403 independentemente. Novo: `frontend/src/paginas/navegacaoPorUrl.test.tsx` (6 testes, gerado a partir do catálogo de permissões): menu vazio → nenhum ecrã do catálogo é permitido; perfil restrito só abre o seu ecrã; `?sep=`, `../`, módulo trocado e ecrã inventado dão «Sem acesso» **sem montar o componente** (nenhum pedido de dados).
3. **Entrada de dados — lacuna principal, corrigida.**
   - (a) **Ficheiros:** os controladores já tinham listas brancas (`mimes`/`extensions`) e as imagens/anexos/comprovativos já usavam `finfo` + nome UUID + disco privado; faltavam a rede central e várias verificações (dupla extensão, polyglot, PDF activo, zip bomb/macros, XXE). Novo `ValidadorUploadSeguro` + middleware `ValidarUploadsSeguros` (todas as rotas `api`) e perfis estritos em `ServicoImagemProduto`, `ServicoAnexosSuporte`, `ServicoComprovativosPagamento`, `ServicoLeituraFolha`.
   - (b) **Pesquisa/filtros/ordenação:** inventário de `DB::raw`/`whereRaw`/`orderByRaw`: todos com bindings ou expressões fixas; **nenhuma coluna de ordenação vem do pedido**; `LIKE` escapado (`addcslashes`/`FiltroMapas::like`) onde há texto livre. Novo `SanitizarEntrada` (segunda linha): 422 para padrões evidentes de injecção, listas/objectos em parâmetros escalares, chaves `$…`, comprimento > 300, bytes de controlo; normalização Unicode. Falsos positivos testados (`O'Brien`, `D'Avila`, `select`, `union`, `Pedro and Maria`, `A-B--C`, `Fatura -- Janeiro`…).
   - XSS: React escapa; há um único `dangerouslySetInnerHTML` real (`SeparadorGestao.tsx`): SVG de gráficos gerado **no próprio cliente** por `graficosRC.ts`, com todo o texto escapado por `esc()` e sem scripts nem atributos de eventos (`Markdown.tsx` só o menciona num comentário). Impressões com `esc()`; CSP `script-src 'self'` sem `unsafe-eval`.
   - SSRF: `GuardaUrlSaida` (ADR-069 A10). Sem `unserialize` de dados do utilizador (`CacheComprimida` com `allowed_classes=false`), sem `eval/exec/shell_exec/proc_open/system` na aplicação, sem `redirect($request->…)`, sem `Storage::url` com caminho do cliente. Lado de saída: `ValorSemFormulas` (CSV/Excel injection).
4. **TLS/certificado:** certificados Let's Encrypt válidos, ECDSA P-256, TLS 1.2/1.3, HTTP→HTTPS, mas **a produção não enviava HSTS/CSP/Permissions-Policy** (nginx de desenvolvimento no `docker-compose.aws.yml`) e **não existe CloudFront, WAF nem CAA** — ver `deploy/aws/SEGURANCA_TLS_WAF.md` (estado, configuração preparada e passos do coordenador).
5. **Outros pontos:** `composer audit` e `npm audit --omit=dev`: **0 vulnerabilidades**; segredos (`client_secret_*.json`, `credenciais*`, `*.sql`, `*.dump`, `wstb_payroll_backup_*`, `.env`) todos no `.gitignore` e não rastreados; Redis com `--requirepass`; RDS privado, cifrado, backups 7 dias, protecção contra eliminação; S3 com Block Public Access; IMDSv2 obrigatório; SG de produção só 80/443.

## 3. Achados por severidade (antes → depois)

### Alta
| # | Achado | Antes | Depois |
|---|---|---|---|
| A-1 | **Produção sem HSTS, CSP, Permissions-Policy, CORP** (só `nosniff`/`X-Frame-Options`/`Referrer-Policy`), `Server` duplicado, 100 MB de corpo em todas as rotas, sem `limit_req` no login — o `web` do `docker-compose.aws.yml` usava `docker/nginx/default.conf` (desenvolvimento) | Verificado em `https://meuerp.ao` (curl -I) | **Corrigido no repositório**: `web` usa `docker/nginx/producao/*`; Caddy sem `Server`. **Falta implantar** (§6) |
| A-2 | Sem rede central para uploads: um endpoint novo que esquecesse a validação aceitaria `.php.jpg`, polyglots, PDF com JS, XLSX com macros/zip bomb | Só validação por controlador | `ValidadorUploadSeguro` + middleware global (testado) |
| A-3 | Sem filtro central de entrada: confiança só nos bindings; `q[]=x`/`filtro[$ne]` chegavam aos controladores (risco de 500/TypeError) | — | `SanitizarEntrada` + varrimento gerado de `route:list` (`EntradasMaliciosasListagensTest`) |
| A-4 | Sem WAF nem CloudFront (IP de origem exposto, sem limite de taxa na borda) | — | Configuração preparada (`waf-regras.json`, `cloudfront-response-headers.json`); **activar** (§6) |

### Média
| # | Achado | Estado |
|---|---|---|
| M-1 | Sem registos **CAA** no Route53 | Pendente (coordenador; valores em `SEGURANCA_TLS_WAF.md` §3.2) |
| M-2 | Teste (nginx nativo): HSTS sem `preload`, sem COOP, `server_tokens` visível (`nginx/1.24.0 (Ubuntu)`) | Pendente (coordenador, §3.1) |
| M-3 | SG `launch-wizard-2` com **SSH aberto a 0.0.0.0/0** (não está nas instâncias do ERP); SSH aberto a /32 nos SG do teste/`erp-web` | Pendente: apagar o SG; migrar para SSM |
| M-4 | Volume EBS `vol-012b0aab0a15e096f` (20 GB) **sem cifra** | Pendente (confirmar dono; pode ser do ODK — não tocar) |
| M-6 | HSTS de 1 ano sem `preload`; sem COOP | Corrigido no repositório (`00-http.conf`, `cabecalhos-seguranca.conf`) |
| M-7 | Caddy sem tempos limite de leitura (slowloris) nem limite de corpo; sem HTTP/3 explícito | Corrigido no repositório (`Caddyfile.aws`) |

### Baixa / informativa
| # | Achado | Estado |
|---|---|---|
| B-1 | RDS: CA `rds-ca-rsa2048-g1`, retenção 7 dias, sem *Performance Insights* | Recomendação (§3.4 do documento AWS) |
| B-2 | `/vendor/…`, `/storage/…`, `/telescope`, `/horizon` respondem 200 com o `index.html` do SPA (não há ficheiros reais; 4719 bytes) | Aceite; no teste `/.env` e `/.git` dão 403 (o nginx de produção também) |
| B-3 | OCSP stapling inexistente | Aceite: a Let's Encrypt terminou o OCSP em 2025 (certificados de 6/90 dias, renovação automática) |
| B-4 | CSV/XLSX de entrada com `=…` fica como texto na base | Aceite: a saída é neutralizada (`ValorSemFormulas`, `paraCsv`) |
| B-5 | `react-router` (2 moderadas) já resolvido: agora **0** vulnerabilidades em `npm audit --omit=dev` | Corrigido (react-router 8.x já instalado) |
| B-6 | `pg_hba`/Redis só na rede Docker | OK |
| B-7 | `SeparadorGestao.tsx`: `dangerouslySetInnerHTML` com SVG gerado no cliente (`graficosRC.ts`, texto escapado, sem scripts) | Aceite; não usar para HTML vindo da API sem DOMPurify |

## 4. Correcções de código (ficheiros principais)

- `backend/app/Support/Seguranca/DetectorInjecao.php`, `backend/app/Http/Middleware/SanitizarEntrada.php`
- `backend/app/Support/Seguranca/ValidadorUploadSeguro.php`, `backend/app/Http/Middleware/ValidarUploadsSeguros.php`; aplicação do perfil estrito em `ServicoImagemProduto`, `ServicoAnexosSuporte`, `ServicoComprovativosPagamento`, `ServicoLeituraFolha`
- `backend/bootstrap/app.php` (ambos os middlewares em `api`, antes de autenticação/limites)
- `docker-compose.aws.yml`, `deploy/caddy/Caddyfile.aws`, `docker/nginx/producao/00-http.conf`, `docker/nginx/producao/cabecalhos-seguranca.conf`
- `deploy/aws/SEGURANCA_TLS_WAF.md`, `deploy/aws/waf-regras.json`, `deploy/aws/cloudfront-response-headers.json`

## 5. Testes

- Backend (`backend/tests/Feature/Seguranca/`): `SanitizarEntradaTest` (27 payloads maliciosos × 9 variantes de parâmetro, 34 textos legítimos, listas/operadores, Unicode), `ValidadorUploadSeguroTest` (nomes perigosos, MIME incoerente, polyglots, PDF activo, XLSX macros/XXE/zip bomb, vazio/grande, nomes aleatórios, cabeçalhos de download, middleware global), `EntradasMaliciosasListagensTest` (todas as rotas GET com empresa: injecções → 422; texto estranho e paginação/ordenação absurdas → nunca 5xx; pesquisa com aspas não altera resultados).
- Frontend: `src/paginas/navegacaoPorUrl.test.tsx` (6/6).
- Existentes que continuam a valer: `OwaspControloAcessoTest`, `OwaspSegurancaTest`, `AuditoriaEscalada*Test`, `SegurancaAutorizacaoTest`, `AuditoriaCabecalhosCorsTest`, `SessoesRotacaoTest`.
- Resultados: ver §7.

## 6. O que o coordenador deve activar / implantar

**Produção (`i-021a07e6f24932c8a`, `meuerp.ao`)**
1. `git pull` e `docker compose -f docker-compose.yml -f docker-compose.aws.yml up -d web caddy` (o `web` passa a usar o nginx de produção na porta interna 8080 e o Caddy a encaminhar para `web:8080`). Reiniciar também `app` (PHP carrega os novos middlewares): `docker compose … up -d app worker scheduler` (OPcache).
2. Confirmar: `curl -sI https://meuerp.ao/ | grep -iE 'strict|content-security|permissions|cross-origin'` deve listar HSTS (`max-age=63072000; includeSubDomains; preload`), CSP, Permissions-Policy, CORP e COOP; um `Server` apenas. Reverter = commit anterior + mesmo comando.
3. Route53: registos **CAA** (`SEGURANCA_TLS_WAF.md` §3.2).
4. Opcional/recomendado: CloudFront + ACM (`TLSv1.2_2021`) + WAF (`waf-regras.json`, em *Count* 48 h) + SG só com o prefixo da CloudFront.

**Teste (`i-00eb77058b5a5296e`, `teste.consulvolt.meuerp.ao`)**
1. Deploy do código (middlewares) e `php artisan optimize:clear`/reinício do PHP-FPM.
2. nginx nativo: HSTS com `preload`, COOP, `server_tokens off`, `ssl_protocols TLSv1.2 TLSv1.3` (§3.1 do documento AWS).

**Conta AWS:** apagar o SG `launch-wizard-2`; SSM em vez de SSH; cifrar o volume `vol-012b0aab0a15e096f` (se não for do ODK); retenção RDS 14–35 dias e CA mais forte; GuardaDuty/Security Hub.

**Efeitos visíveis aos utilizadores:** nenhuma funcionalidade muda. Pesquisas com um padrão evidente de SQL/script/traversal (ex.: `x; select …`) passam a dar «O valor indicado não é válido para este campo de pesquisa/filtro» (422). Um upload com dupla extensão, SVG/HTML, PDF com JavaScript ou XLSX com macros é recusado com código `FICHEIRO_*`.

## 7. Números dos testes

(Preenchido no fim da ronda — ver o commit final.)
