# Implementar.txt — partes II e III (segurança e desempenho do backend): estado, evidência e o que foi feito

Data: 2026-11-05 · ADR-085 · Âmbito: secções 4–9 do `Implementar.txt` (autenticação e sessões, criptografia e tokens, OWASP,
multi-tenant, backend e base de dados). A UI, a responsividade, a acessibilidade e o desempenho do frontend (secções 10–15) são de outra frente;
a conta root (ADR-084) também. Documentos que isto complementa e **não** repete: `arquitetura/SESSOES_E_COOKIES.md` (ADR-082),
`SEGURANCA_BLINDAGEM_2026-11.md` (ADR-083), `arquitetura/SEGURANCA_OWASP.md`, `arquitetura/AUDITORIA_AUTORIZACAO_2026-11.md`.
Novos: `SEGURANCA_CRIPTOGRAFIA.md`, `DESEMPENHO_BACKEND_2026-11.md`.

Legenda: **JÁ** = já cumprido antes desta ronda · **PARCIAL** = faltava parte · **FALTA** = não existia. A coluna «Feito» diz o que se fez.

## 1. Requisito → estado → evidência → o que foi feito

### 1.1 Autenticação e sessões (secção 4)

| # | Requisito | Estado inicial | Evidência | Feito |
|---|---|---|---|---|
| 1 | Sanctum SPA por cookies (first-party) | JÁ | ADR-082; `SessoesRotacaoTest`; token opaco Sanctum em cookie HttpOnly + CSRF (não o modo `statefulApi`, porque cada cliente tem o seu sub-domínio) | — |
| 2 | Multi-sessões permitidas | JÁ | limite de 10 por utilizador; `SessoesRotacaoTest` | — |
| 3 | Sessão independente por dispositivo + identificação do dispositivo | JÁ | cookie `erp_dev` assinado pelo servidor, rótulo «Chrome em Windows» | — |
| 4 | Revogação individual | JÁ | `DELETE /autenticacao/sessoes/{id}`; `MinhasSessoesEEmpresaActivaTest` | — |
| 5 | Logout global | FALTA | — | `POST /autenticacao/sair-de-todas` (+ auditoria); `SessoesPoliticaTest::varias_sessoes_por_dispositivo_e_logout_global…` |
| 6 | Controlo de sessões por empresa | FALTA | só a consola da plataforma via todas | `GET /sistema/sessoes-empresa`, `GET\|DELETE /sistema/utilizadores/{id}/sessoes` (404 fora do domínio do administrador; 403 sem `config_util_gerir`); `SessoesPoliticaTest` (3 testes) |
| 7 | Bloqueio de sessões comprometidas | PARCIAL | reutilização de token e mudança de navegador terminavam a sessão sozinhas | o administrador pode terminar sessões e **bloquear a conta** (`DELETE …/sessoes` com `bloquear=true`: desactiva + revoga + audita) |
| 8 | Estado da conta validado em cada pedido | JÁ | `Sanctum::authenticateAccessTokensUsing` (activo, não eliminado) | teste novo `o_estado_da_conta_e_validado_em_cada_pedido` |
| 9 | Inactividade de 15 min só em actividade legítima | PARCIAL | valor 15 min já era o configurado, mas o Guard do Sanctum renovava `ultimo_uso_em` em **todos** os pedidos, polling incluído | `PedidoAutomatico` + `TokenAcesso::setAttribute`: GET/HEAD com `X-Erp-Automatico: 1` não renova; o frontend já marca todos os `refetchInterval` (`obterAutomatico`, outra frente); testes de `Carbon::setTestNow` |
| 10 | Duração absoluta de 2 h, sem renovação | PARCIAL | era 12 h | `erp.sessao.validade_horas` = 2 (`.env.example`, `.env.prod.example`) **e** corte por `criado_em` (apanha as sessões antigas de 12 h) |
| 11 | Rotação do identificador não reinicia o limite | JÁ | a rotação muda o segredo na mesma linha | teste novo: 10 rotações e o `expira_em` não se move; 2 h 01 min → 401 |
| 12 | Expiração efectiva no backend | JÁ | `expira_em` + inactividade no Guard | `SessoesPoliticaTest` (6 testes com `setTestNow`) |
| 13 | CSRF obrigatório; HTTPS; cookies HttpOnly/Secure/SameSite | JÁ | CSRF ligado à sessão + `Origin`; `__Host-`, `SameSite=Strict`; `SessoesRotacaoTest` | — |
| 14 | MFA em operações críticas | PARCIAL | só mudar palavra-passe e desactivar MFA pediam a palavra-passe actual | **step-up**: `mfa_recente` (403 `MFA_REQUERIDO`), `POST /autenticacao/reautenticar`, TOTP de uso único; aplicado às operações da §2; `MfaStepUpTest` (9 testes) + `reautenticacao.test.ts` (6) |
| 15 | Auditoria obrigatória | JÁ | `ServicoAuditoria` em login, falhas, revogações | nova: reautenticação, logout global, sessões terminadas por administrador |
| 16 | Isolamento SaaS | JÁ | RLS + `ResolverEmpresaAtiva`; `IsolamentoRlsContratoTest` | — |
| 17 | Sem JWT/refresh tokens | JÁ | — | — |

### 1.2 Criptografia e tokens (secção 5)

| # | Requisito | Estado inicial | Evidência | Feito |
|---|---|---|---|---|
| 18 | Inventário dos dados sensíveis | FALTA | — | `SEGURANCA_CRIPTOGRAFIA.md` §2 (14 itens) + teste que garante que toda a coluna `encrypted` dos modelos está no inventário da rotação |
| 19 | AES-256-GCM/XChaCha20 para reversíveis | PARCIAL | AES-256-CBC+HMAC (cifra do Laravel; formato da produção) | `APP_CIPHER` (GCM opcional) + `EncrypterCompativel` (lê as duas cifras, sem janela de indisponibilidade). XChaCha20 **não** (o `Encrypter` do Laravel não a tem e não se faz cifra própria) |
| 20 | Argon2id ou bcrypt ≥ 12 + rehash | JÁ | `HASH_DRIVER=argon2id`; `modernizarHash` | **bug encontrado e corrigido**: com `argon2id`, `Hash::check` num hash bcrypt antigo lançava excepção (500 no login); passou a `password_verify`; `CriptografiaTokensTest` |
| 21 | SHA-256/HMAC para fingerprints de tokens | JÁ | `tokens_acesso.token`, `tokens_bi.hash_token`, `webhook_token_hash` | teste `as_chaves_de_integracao_e_as_sessoes_so_existem_na_base_como_sha256` |
| 22 | Segredos MFA protegidos | JÁ | cast `encrypted` | teste novo (segredo e códigos de recuperação nunca em claro); **TOTP de uso único** (antes o mesmo código valia 90 s e podia repetir-se): `mfa:passo:*` em cache atómica |
| 23 | Identificadores de sessão guardados hasheados | JÁ | `SessoesRotacaoTest::o_token_tem_256_bits…` | — |
| 24 | Rotação de chaves + versões de chave | PARCIAL | `APP_PREVIOUS_KEYS` existia, sem forma de recifrar | `erp:seguranca:rodar-chave` (`--estado`, `--simular`), versão da chave (HMAC curto), inventário MFA/AGT/gateways/IA, payload legado sem prefixo; `RotacaoChavesTest` (6) |
| 25 | Separação de segredos | PARCIAL | cookies/CSRF com sub-chaves da `APP_KEY` (rótulos distintos); chaves de IA/AGT cifradas por linha | documentado (§2/§7 do doc de criptografia); a separação em chaves independentes por finalidade **não** se fez (obrigaria a um cofre/KMS): ver «Fica por fazer» |
| 26 | Protecção de backups | PARCIAL | `backup.sh` só cifrava os segredos AGT; o dump ia em claro | cifra de dump/storage/redis (`openssl enc -aes-256-cbc -pbkdf2 -iter 200000`) com `ERP_COPIAS_CHAVE` e decifra verificada; `restaurar.sh` decifra. Sem chave: aviso explícito |
| 27 | Sem MD5/SHA-1 em credenciais | JÁ | só em chaves de cache/ETag; TOTP = HMAC-SHA1 por norma | `CriptografiaTokensTest::nenhum_codigo_de_credenciais_usa_md5_ou_sha1` |
| 28 | Nenhum segredo no repositório | JÁ | `.env` no `.gitignore`; `APP_KEY=` vazio nos exemplos | — |
| 29 | Políticas de tokens (recuperação, OTP, API keys, integrações) | PARCIAL | cada uma estava no código, não numa política | tabela no §5 do doc de criptografia. **A recuperação por ligação de uso único continua por decidir** (ADR-082): hoje a palavra-passe nova é gerada e enviada |
| 30 | Comparação em tempo constante | JÁ | `hash_equals` em tokens e TOTP | teste `as_comparacoes_de_segredos_sao_em_tempo_constante` |

### 1.3 OWASP e multi-tenant (secções 6 e 7)

| # | Requisito | Estado inicial | Evidência | Feito |
|---|---|---|---|---|
| 31 | SQL injection | JÁ | bindings + `SanitizarEntrada` + `EntradasMaliciosasListagensTest` (varrimento gerado) | — |
| 32 | XSS | JÁ | CSP e cabeçalhos (ADR-083) + filtro de entrada | — |
| 33 | Broken access control / privilege escalation | JÁ | `OwaspControloAcessoTest` (todas as rotas sem permissão), `SEM_PERMISSAO_ADMINISTRATIVA` | — |
| 34 | IDOR / acesso cruzado entre empresas e tenants numa amostra grande | PARCIAL | `AuditoriaEscaladaHorizontalTest` (poucos endpoints à mão) | `AcessoCruzadoGeradoTest`: **toda** a rota com empresa (GET/POST/PUT/DELETE, > 600) recusa 403 uma empresa do mesmo cliente, de outro cliente e inexistente; o corpo/`tenant_id` forjado não escolhe a empresa |
| 35 | SSRF | JÁ | `GuardaUrlSaida`; URLs fixas (A10-1/A10-2 de `SEGURANCA_OWASP.md`) | — |
| 36 | Security misconfiguration | JÁ | nginx/Caddy de produção, TLS/WAF (ADR-083) | — |
| 37 | Brute force / credential stuffing | JÁ | 5/min por utilizador+IP, 60/min por IP, nginx `limit_req`; `reautenticar` e MFA com limite próprio | — |
| 38 | Session fixation | JÁ | token novo em cada login | — |
| 39 | Mass assignment | JÁ | `$fillable` + `shouldBeStrict` | — |
| 40 | Upload inseguro | JÁ | `ValidadorUploadSeguro` | — |
| 41 | Exposição de informação sensível | JÁ | `OwaspSegurancaTest`, `$hidden`, respostas sem cache | — |
| 42 | Relatórios, ficheiros e exportações respeitam o isolamento | JÁ | RLS por tenant + `ResolverEmpresaAtiva`; `IsolamentoEmpresaTest`, `AuditoriaEscaladaHorizontalTest`, `IsolamentoRlsContratoTest` | exportações em massa agora exigem step-up (§2) |
| 43 | Administradores globais com permissões explícitas | JÁ | `ExigirPlataforma` + chaves do perfil; conta root (ADR-084, outra frente) | escritas da plataforma exigem step-up |
| 44 | Autorização por Policies/Gates | JÁ | `Gate::before` + chaves de ecrã; `exigir()` em todos os controladores (varrimento de `OwaspControloAcessoTest`) | — |

### 1.4 Desempenho do backend e da BD (secções 8 e 9)

| # | Requisito | Estado inicial | Evidência | Feito |
|---|---|---|---|---|
| 45 | Detectar/eliminar N+1 | PARCIAL | `shouldBeStrict` apanhava lazy loading | `ConsultasPorEndpointTest` (consultas por pedido em 323 rotas + comparação poucas/muitas linhas); corrigido o N+1 de `/sistema/utilizadores` (33 → 8) |
| 46 | Consultas duplicadas | FALTA | — | detector (> 6× a mesma consulta); corrigida `e_consolidacao` 18× no cubo (20 → 3) |
| 47 | Índices em falta | PARCIAL | — | análise com BD sintética de 1,5 M de linhas: 1 índice aplicado (360 → 0,25 ms); 63 FK sem índice **analisadas e não criadas** (sem medição que as justifique) — ver `DESEMPENHO_BACKEND_2026-11.md` |
| 48 | Cache Redis isolada e invalidada | JÁ | `CACHE.md`, `RegrasCacheTest` | nova chave `mfa:passo:*` inventariada |
| 49 | Filas, jobs, rate limiting | JÁ | `tries`/`backoff`, `after_commit`, limitadores nomeados | — |
| 50 | Serialização e paginação com tecto | PARCIAL | tectos por controlador | teste gerado: nenhuma listagem aceita mais de 1000 linhas por página |

**Contagens** (50 linhas): inicialmente **JÁ 33 · PARCIAL 13 · FALTA 4**; depois desta ronda **todas** têm resposta; ficam **3 decisões/limites assumidos** (itens 19 XChaCha20, 25 chaves por finalidade, 29 recuperação por ligação) listados em «Fica por fazer».

## 2. Operações críticas com step-up (`mfa_recente`)

| Grupo | Rotas |
|---|---|
| Identidade da conta | mudar palavra-passe (`PUT /autenticacao/palavra-passe`), mudar e-mail (`PUT /autenticacao/dados-pessoais` só se o e-mail muda), `POST /autenticacao/mfa/iniciar`, `POST /autenticacao/mfa/desactivar` |
| Utilizadores, perfis e permissões | `POST/PUT/DELETE` em `sistema/utilizadores/**` e `sistema/perfis/**` (excepto `perfis/avaliar`, só pré-visualização) |
| Empresas | `POST/PUT` em `sistema/empresas` e `…/estado` (inactivar/eliminar) |
| Exportações em massa e cópias | `GET sistema/copias/exportar`, `POST sistema/copias/importar\|clonar`, `GET rh/salarios/periodos/{id}/recibos-zip`, `GET plataforma/relatorios/clientes.csv\|empresas.csv` |
| Integrações e chaves | credenciais, chave privada e webhook AGT, tokens Power BI (`POST/revogar`) |
| Licença e pagamento | `POST/PUT licenca/planos`; na plataforma: planos, cupões, clientes (estado, arquivar, plano), definições, configuração de pagamentos, transferências bancárias, geral do SaaS |
| IA | fornecedores e chaves, atribuições por cliente (plataforma) |
| Plataforma | toda a escrita em `plataforma/**` (administradores, operadores, agentes, sessões…) excepto o tratamento diário de tickets (`plataforma.suporte.*`); isenções em `erp.sessao.stepup_isentas` |

Janela: `ERP_SESSAO_STEPUP_MINUTOS` (15). Interruptor de emergência: `ERP_SESSAO_STEPUP_ATIVO=false`. O login conta como autenticação: quem acaba de entrar não é incomodado.
Frontend: `sessao/PedidoReautenticacao.tsx` (pedido de código ou palavra-passe) e repetição única do pedido original em `api/cliente.ts` (também nas descargas de ficheiros).

## 3. Migrações novas (o coordenador ensaia antes da produção)

| Ficheiro | O que faz | Risco |
|---|---|---|
| `2026_11_29_090000_tokens_acesso_reautenticado_em` | acrescenta a coluna `tokens_acesso.reautenticado_em` (nula) | nenhum: aditiva, sem reescrever linhas, sessões abertas intactas |
| `2026_11_29_100000_indice_notificacoes_nao_lidas` | `CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_notificacoes_nao_lidas … WHERE lida = false` (3,7 MB em 600 000 linhas) | baixo: não bloqueia escritas; tem de correr **fora de transacção** (`$withinTransaction = false`); reverter com `DROP INDEX CONCURRENTLY` |

## 4. Passos de activação

1. Deploy do código e `php artisan migrate --force` (as duas migrações acima; ensaiar primeiro numa cópia da produção — memória do projecto: história divergente, nunca `force-push`).
2. `.env` do servidor: `ERP_SESSAO_VALIDADE_HORAS=2` (ou apagar a linha: a omissão passou a 2). **Efeito**: sessões com mais de 2 h terminam no primeiro pedido, um novo login.
3. Opcional: `ERP_COPIAS_CHAVE` (cofre/SSM, **fora do servidor**) para cifrar as cópias; sem ela o `backup.sh` avisa. Testar `restaurar.sh` numa base nova com a cópia cifrada.
4. Opcional: `APP_CIPHER=AES-256-GCM` e depois `php artisan erp:seguranca:rodar-chave` (e `--estado` para confirmar). Rotação de chave: procedimento no §4 de `SEGURANCA_CRIPTOGRAFIA.md`.
5. Frontend: `npm run build` (o `localhost:8081/erp_web_e2e` serve `frontend/dist`) para levar o pedido de reautenticação.
6. Avisar os administradores: a primeira operação crítica depois de 15 min de sessão pede o código/palavra-passe.

## 5. Fica por fazer / decisões do utilizador

- **XChaCha20-Poly1305**: não existe no `Encrypter` do Laravel; AES-256-GCM cumpre o pedido. Acrescentar uma biblioteca só se houver um requisito concreto.
- **Chaves por finalidade** (cifra de segredos, cookies, CSRF independentes): hoje derivam todas da `APP_KEY`. Separar exige cofre/KMS (AWS KMS ou SSM) — decisão de infra-estrutura.
- **Recuperação de palavra-passe por ligação de uso único**: continua por decidir (ADR-082).
- Aviso no ecrã antes do limite absoluto de 2 h (o frontend termina a sessão por inactividade, mas não avisa do limite absoluto).
- Ecrãs de administração para o controlo de sessões por empresa e o logout global (a API está pronta e testada; falta a interface — é da frente de UI).
- `pg_stat_statements` em produção e revisão das 63 FK sem índice com dados reais.
- `mfa_recente` corre antes da autorização do controlador: uma sessão com mais de 15 min vê `MFA_REQUERIDO` mesmo numa rota que não podia usar (inofensivo; pede o código e depois recebe 403).
- Cuidado com a conta root (ADR-084): as escritas de `plataforma/**` passaram a exigir step-up — as sessões de root com mais de 15 min pedem o código/palavra-passe nessas operações.
