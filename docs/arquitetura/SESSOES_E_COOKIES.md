# Sessões, tokens e cookies (ADR-082)

Data: 2026-11-19 · Ramo `endurecimento-seguranca` · Complementa `SEGURANCA_OWASP.md` e `AUDITORIA_AUTORIZACAO_2026-11.md`.

Objectivo: um utilizador pode ter conta aberta em vários dispositivos ao mesmo tempo, cada um com **o seu** token, único e
**não reutilizável**; **nada de autenticação fica no navegador** que um atacante (ou quem aceda ao PC) possa copiar e usar; o
que ficar tem de ser inútil sem o servidor.

## 1. Modelo

| Peça | Onde vive | Quem a escolhe | Conteúdo |
|---|---|---|---|
| **Token de sessão** | cookie `erp_token` (HttpOnly) e, no servidor, a linha de `tokens_acesso` | servidor | `id\|segredo`; segredo = 256 bits do CSPRNG (`random_bytes(32)`, base64url, 43 caracteres). Na base só o **SHA-256** do segredo (`token`), comparado com `hash_equals` |
| **Dispositivo** | cookie `erp_dev` (HttpOnly) e `tokens_acesso.dispositivo_id` | **servidor** (o cliente já não o gera nem o envia) | `id.assinatura` — id aleatório de 128 bits + HMAC-SHA256 com sub-chave derivada da `APP_KEY`. Assinatura inválida = sem cookie = dispositivo novo |
| **CSRF** | cookie `XSRF-TOKEN` (legível por JS, por desenho) | servidor | `nonce.HMAC(id da sessão, nonce)` — ligado à sessão |
| **Empresa activa** | coluna `tokens_acesso.empresa_ativa_id` | utilizador (`PUT /autenticacao/empresa-activa`) | só o id; validado a cada pedido |
| Dispositivos conhecidos | `dispositivos_conhecidos(utilizador_id, dispositivo_id)` | servidor | para o aviso de «novo dispositivo» |

Uma **sessão** é uma linha de `tokens_acesso` com habilidade `*` (o token limitado `mfa:pendente`, de 5 minutos, é outro). Há
**no máximo uma sessão completa por dispositivo**: um novo login no mesmo dispositivo (mesmo cookie `erp_dev`) substitui a
sessão desse dispositivo e nunca a de outro. Sem o cookie (navegador novo, cookies apagados) o servidor cria um dispositivo novo.
O campo `dispositivo` do corpo de `POST /autenticacao/entrar` é **ignorado** (o cliente já não escolhe nada).

## 2. Ciclo de vida

1. **Login** (`/autenticacao/entrar`, MFA `/autenticacao/mfa/verificar`, Google `/auth/google/callback`, registo de empresa): resolve o
   dispositivo (cookie assinado ou novo) → apaga a sessão anterior **desse** dispositivo → cria token novo (nunca reaproveita um
   existente: sem fixação de sessão; o 2.º passo do MFA apaga o token limitado e emite um completo novo) → aplica o limite de sessões →
   regista o dispositivo → devolve os 3 cookies. O corpo da resposta nunca leva o token.
2. **Pedido autenticado**: `Sanctum::getAccessTokenFromRequestUsing` lê o cookie (ou `Authorization: Bearer`, para clientes que não são
   o SPA); `TokenAcesso::findToken` compara o hash em tempo constante; `authenticateAccessTokensUsing` aplica a expiração por
   inactividade e a vinculação ao dispositivo (§5).
3. **Rotação** (`RotacionarToken`, na resposta): se o token (cookie) tem mais de `rotacao_minutos` (10), é trocado por um novo na
   **mesma linha** (mesmo id de sessão — a empresa activa e o CSRF mantêm-se) e o `Set-Cookie` novo vai na resposta. O hash anterior
   fica em `tokens_anteriores` (só hash). A troca é atómica (*compare-and-swap* sobre o hash actual): de dois pedidos paralelos só um roda.
   Clientes com `Authorization: Bearer` não rodam.
4. **Tolerância**: o token anterior continua válido `rotacao_tolerancia_segundos` (45) — cobre pedidos já em voo do mesmo navegador. Não gera nova rotação.
5. **Detecção de reutilização**: um token já rodado apresentado **depois** da tolerância é tratado como roubo: apaga todas as linhas
   desse dispositivo (incluindo o token novo), regista em auditoria («Reutilização de token detectada»), escreve no log e envia e-mail
   ao titular (`AvisoSessaoMail`, só rótulo do dispositivo, IP aproximado e hora — nunca segredos). Quem ficou com o cookie antigo
   fica sem sessão; o utilizador legítimo é reencaminhado para o login no pedido seguinte.
6. **Expiração**: inactividade `inatividade_minutos` (15, medida em `ultimo_uso_em`) e absoluta `validade_horas` (12, `expira_em`);
   o cookie tem `Max-Age` igual ao tempo que resta da validade absoluta.
7. **Logout**: apaga a linha (revogação no servidor) e expira `erp_token` e `XSRF-TOKEN`. O cookie do dispositivo mantém-se (identifica o navegador, não a sessão).
8. **Outras revogações**: mudança de palavra-passe termina as **outras** sessões (a actual mantém-se); reposição pelo administrador e
   recuperação terminam todas; desactivar/eliminar utilizador termina todas; limite `max_sessoes_por_utilizador` (10) termina a mais antiga.
9. **Utilizador vê e controla** (`Gerir Dados Pessoais › As minhas sessões`): `GET /autenticacao/sessoes`, `DELETE /autenticacao/sessoes/{id}`,
   `POST /autenticacao/sessoes/terminar-outras` — só as do próprio utilizador (as consultas partem de `$utilizador->tokens()`; um id alheio dá 404).
   Mostra rótulo («Chrome em Windows»), IP aproximado (sem último octeto), criada, última actividade e «Esta sessão». O ecrã «Sessões» da
   Plataforma (`Plataforma\SessoesController`) continua a ler `tokens_acesso` (a coluna `nome` é agora `web-<8 caracteres do dispositivo>`).
10. **Avisos**: e-mail + auditoria quando entra um dispositivo novo (o primeiro dispositivo de uma conta não gera aviso) e quando uma
    reutilização de token revoga uma sessão. Canal: e-mail (fila `EmailBase`) — as notificações internas do ERP são por empresa, e uma conta pode não ter nenhuma activa.

## 3. Cookies — atributos e porquê

| Atributo | Valor | Razão |
|---|---|---|
| `HttpOnly` | token e dispositivo | JavaScript (XSS) nunca os lê. O `XSRF-TOKEN` não é HttpOnly por desenho do duplo-submit, mas **não autentica**: sozinho não dá acesso a nada |
| `Secure` | quando o pedido chega por HTTPS (directo ou `X-Forwarded-Proto` de proxy de confiança), ou forçado por `ERP_COOKIE_SECURE=true` | o cookie nunca viaja em claro. Em `http://localhost` (desenvolvimento) fica **sem** `Secure` e **sem** prefixo — os navegadores recusam `Secure` em HTTP (excepção do Chrome/Firefox para `localhost`, não fiável) |
| prefixo `__Host-` | `__Host-erp_token`, `__Host-erp_dev`, `__Host-XSRF-TOKEN` quando `Secure` | força `Secure`, `Path=/` e **sem `Domain`**: um sub-domínio vizinho (ou um sítio HTTP) não consegue sobrepor/plantar estes cookies. O servidor, em HTTPS, **só aceita o nome com prefixo** |
| `Domain` | nunca definido | cookie **só do anfitrião** que o emitiu. Cada cliente (`<slug>.<base>`), `portal.<base>` e a consola `admin.erp.<base>` têm os seus cookies; não se partilham entre sub-domínios. Compatível com `__Host-` |
| `Path` | `/` | exigido por `__Host-` |
| `SameSite` | **Strict** (omissão; `ERP_COOKIE_SAMESITE=lax` para recuar) | ver abaixo |
| Validade | token: até ao fim da validade absoluta da sessão; dispositivo: 180 dias (`ERP_SESSAO_DISPOSITIVO_DIAS`) | alinhada com a expiração; o dispositivo vive mais para reconhecer o navegador entre logins |

**Lax ou Strict?** Escolheu-se **Strict**. O que Lax acrescentaria é o cookie em *navegações de topo com GET vindas de outro site*.
(a) O regresso do **login Google** é uma redirecção GET de topo para `/auth/google/callback`: esse pedido não precisa do cookie de sessão
(é ele que o **emite**; um `Set-Cookie` Strict numa resposta a navegação de topo é aceite) e termina em `/app`, HTML estático da SPA;
é a SPA, já dentro do site, que chama `/api/autenticacao/eu` com o cookie. (b) As **ligações de e-mail** abrem páginas da SPA (HTML estático,
sem cookie); a autenticação acontece nas chamadas à API que a página faz, de mesma origem. (c) Nenhum GET da API altera estado. Logo, Strict
não quebra nenhum fluxo e elimina a classe de ataques por navegação cross-site. Os cookies do handshake OAuth do Laravel (`state` do
Socialite, `config/session.php`) continuam `Lax` — são outro cookie, e é aí que Lax é necessário. Nota: `SameSite` não distingue sub-domínios
(`acme.base` e `portal.base` são o mesmo *site*) — por isso há também o CSRF ligado à sessão e a verificação de `Origin` (§4).

## 4. CSRF e origem

- **Duplo-submit ligado à sessão** (`VerificarCsrfDuploSubmit`, métodos que alteram estado, só com sessão por cookie): o cookie `XSRF-TOKEN` e o
  cabeçalho `X-XSRF-TOKEN` têm de ser iguais **e** o valor tem de ser `nonce.HMAC(id da sessão, nonce)`. Um sub-domínio que plante um cookie
  igual ao cabeçalho não consegue forjar o HMAC (não tem a chave) nem usar o de outra sessão. O CSRF não muda com a rotação (liga-se ao id da linha).
- **Origem** (defesa em profundidade): `Origin` tem de ser do **próprio anfitrião** do pedido (cada cliente fala com a API no seu sub-domínio) ou estar em
  `ERP_CORS_ORIGENS`/`ERP_SESSAO_ORIGENS_PERMITIDAS`; `Origin: null` e `Sec-Fetch-Site: cross-site` são recusados (403 `ORIGEM_INVALIDA`). Pedidos sem estes
  cabeçalhos (não navegadores) passam por esta camada. Sub-domínios vizinhos **não** são aceites. O proxy do anfitrião tem de preservar o cabeçalho `Host`
  (`proxy_set_header Host $host`); caso contrário listar a origem em `ERP_SESSAO_ORIGENS_PERMITIDAS`. Desligável com `ERP_SESSAO_VERIFICAR_ORIGEM=false`.
- Pedidos com `Authorization: Bearer` não são enviados automaticamente por um navegador: ficam isentos (como antes).

## 5. Vinculação ao dispositivo

A sessão guarda `agente_assinatura = SHA-256(navegador|sistema operativo)` (não o User-Agent completo; guarda-se só o rótulo «Chrome em Windows»).
Se o token for apresentado por outra família (ex.: Chrome → Firefox, Windows → Linux) a sessão é terminada, auditada e o titular é avisado.
Versões novas do mesmo navegador **não** contam. **Mudança de IP nunca termina a sessão** (redes móveis mudam de IP): fica só em auditoria
(«Mudança de IP da sessão»). `ERP_SESSAO_VINCULAR_AGENTE=false` desliga a vinculação.

## 6. Respostas sem cache

`CabecalhosSemCache` (primeiro middleware do grupo `api`) põe `Cache-Control: no-store, private`, `Pragma: no-cache`, `Expires: 0` em **todas** as
respostas da API (incluindo erros 401/403/419): autenticação, `eu`, menu, dados de negócio e ficheiros. Excepção: as rotas com `cache_condicional`
(logótipo de entrada, identidade, ficha de empresa — ETag/304, sem dados pessoais), que definem o seu próprio `Cache-Control`.
Não existe Service Worker, PWA nem Cache Storage na aplicação (verificado e guardado por teste).

## 7. O que fica no navegador

**Nada de autenticação.** Cookies: os três acima (token e dispositivo HttpOnly; CSRF sem valor próprio). `localStorage`/`sessionStorage`:
só **preferências de interface**, sem token, hash, e-mail, nome, permissões, menu em cache nem respostas da API. As chaves que levavam o id do
utilizador passam a levar um **identificador opaco** (`u` + hash de 53 bits do id com sal fixo da aplicação, `sessao/chaveOpaca.ts`). Não é um segredo
(o sal está no código e os ids são pequenos), apenas deixa de haver ids/nomes legíveis nas chaves.

Removido nesta tarefa: `erp.dispositivo` (agora cookie HttpOnly do servidor), `erp.empresa` (agora estado da sessão: `PUT /autenticacao/empresa-activa`,
devolvida por `/eu`; no navegador só existe em memória), `erp.empresas_recentes.<id>` (substituída por `ultima_empresa_id` no servidor, `/eu`) e o valor do
cookie CSRF que o aviso de licença guardava (agora só um hash curto). Ao arrancar, `limparChavesAntigas` apaga as três primeiras e converte as chaves antigas
para as opacas (`main.tsx`). Ao sair, a empresa em memória é limpa; sessionStorage de sessão (`erp_recarga_fragmento`) só guarda um caminho de página.

Chaves que **ficam** (todas preferências de interface, sem valor para um atacante):

| Chave (localStorage) | Conteúdo | Porquê fica |
|---|---|---|
| `erp.aspecto.<opaco>` | tema claro/escuro e estilo do menu | aplicar o tema antes da 1.ª pintura (sem clarão); há cópia no servidor |
| `erp.menu.largura.<opaco>`, `erp.menu.modo.<opaco>`, `erp.plataforma.menu.modo.<opaco>` | largura e modo (lateral/topo) do menu | só local; síncrono no arranque |
| `erp.<chave>.posicao.<opaco>` | posição da janela flutuante de pesquisa | idem |
| `erp.licenca.avisoVisto.<opaco>` | hash curto do identificador de sessão em que o aviso de licença foi dispensado | não reabrir o pop-up ao recarregar |
| `erp.tour_boas_vindas_v1:<opaco>` | tour visto (`1`) | evita reabrir; há cópia no servidor |
| `erp.pos.frente.<opaco>`, `erp.pos.impressao.<opaco>`, `erp.pos.terminal.<…>.<opaco>` | preferências da frente de caixa, impressão e terminal escolhido | conveniência de posto (o id é de terminal/empresa, hash opaco) |
| `erp.colunas`, `erp.vistas`, `erp.formLinhas.colunas.*`, `erp.impressao.pagina:*` | colunas visíveis, vistas e página de impressão por ecrã | só UI |
| `est_org_orientacao`, `proj_org_orientacao`, `fluxo.narrativa`, `rotinas.seccao`, `lancamentos.filtrosOcultos`, `pos_wh_*`, `erp_alertas_dispensados` | orientação de organigramas, modos, secções ocultas, alertas dispensados (ids de alerta) | só UI |

O teste `sessao/semAutenticacaoNoNavegador.test.ts` falha se algum ficheiro novo gravar no armazenamento, se alguém usar IndexedDB/Cache/Service Worker,
ou se voltar a existir uma chave de token/dispositivo/empresa/e-mail.

## 8. Riscos residuais (o que NÃO se consegue impedir)

- Quem copia o **perfil do navegador com o cookie HttpOnly válido** (ou o rouba com malware no PC) pode usá-lo **até ao fim da validade** da sessão — HttpOnly
  protege de XSS, não de acesso ao disco. Mitigações: inactividade de 15 min (uma cópia fica inútil se o utilizador não estiver activo e o atacante demorar),
  expiração absoluta de 12 h, **rotação** a cada 10 min (a cópia apanha um token que o navegador legítimo já trocou) com **detecção de reutilização**
  (quando um dos dois usa o token velho, a sessão cai e o titular é avisado), **vinculação** ao navegador+SO, lista de sessões e «terminar», logout que revoga no servidor.
  Se o atacante usar a cópia **antes** do utilizador legítimo, é o legítimo quem perde a sessão (o seu token passa a ser o «antigo») — e é avisado.
- Falsos positivos da reutilização: um navegador que não recebeu o `Set-Cookie` novo (resposta perdida) e volta a pedir **depois** da tolerância
  (45 s) perde a sessão e entra de novo. Ajustável (`ERP_SESSAO_ROTACAO_TOLERANCIA_SEGUNDOS`).
- Um atacante com o **mesmo** navegador, SO e janela de tempo (ex.: sessão aberta e PC desbloqueado) não é distinguível: usar o bloqueio do posto.
- A vinculação é por família de navegador/SO, não por impressão digital forte (não se quer guardar mais que o necessário).
- **Re-autenticação para acções sensíveis** já existe onde importa: alterar a palavra-passe e desactivar o MFA exigem a palavra-passe actual; o resto não pede nova autenticação.
- O `XSRF-TOKEN` é legível por JavaScript por desenho; um XSS pode fazer pedidos **dentro** da página (CSRF não protege de XSS) mas nunca levar o token.

## 9. Configuração (`config/erp.php` → `sessao`)

| Variável | Omissão | Efeito |
|---|---|---|
| `ERP_SESSAO_INATIVIDADE_MINUTOS` | 15 | expiração por inactividade |
| `ERP_SESSAO_VALIDADE_HORAS` | 12 | expiração absoluta |
| `ERP_SESSAO_ROTACAO_MINUTOS` | 10 | idade a partir da qual o token roda (0 = sem rotação) |
| `ERP_SESSAO_ROTACAO_TOLERANCIA_SEGUNDOS` | 45 | tolerância do token anterior |
| `ERP_SESSAO_MAX_POR_UTILIZADOR` | 10 | sessões simultâneas por utilizador (0 = ilimitado) |
| `ERP_SESSAO_VINCULAR_AGENTE` | true | vinculação ao navegador+SO |
| `ERP_SESSAO_DISPOSITIVO_DIAS` | 180 | validade do cookie do dispositivo |
| `ERP_COOKIE_SECURE` | (automático) | `true`/`false` força `Secure` e o prefixo `__Host-`; vazio = só com HTTPS |
| `ERP_COOKIE_SAMESITE` | strict | `strict` ou `lax` |
| `ERP_SESSAO_VERIFICAR_ORIGEM` | true | verificação de `Origin`/`Sec-Fetch-Site` |
| `ERP_SESSAO_ORIGENS_PERMITIDAS` | (vazio) | origens extra, separadas por vírgulas |
| `ERP_RECUPERACAO_MAX_POR_HORA` | 3 | recuperações por conta por hora |
| `ERP_RECUPERACAO_INTERVALO_SEGUNDOS` | 120 | intervalo mínimo entre recuperações da mesma conta |
| `ERP_RECUPERACAO_TEMPO_MINIMO_MS` | 1200 | duração mínima da resposta de recuperação |

## 10. Recuperação de palavra-passe (resumo)

Experiência igual (palavra-passe gerada pelo sistema, troca obrigatória). Endurecimento: limite **por conta** e intervalo mínimo; resposta sempre genérica e de
tempo mínimo fixo; a palavra-passe actual **só deixa de valer depois de a nova ser entregue** (envio síncrono; se falhar, nada muda); o e-mail diz quando, de
que tipo de dispositivo e de que IP aproximado foi pedido; tudo em auditoria. Alternativa não implementada: ver ADR-082.

## 11. Testes

Backend: `SessoesRotacaoTest` (tokens, dispositivo, rotação, tolerância, reutilização, vinculação, limite, cookies Secure/`__Host-`, no-store, CSRF e origem),
`MinhasSessoesEEmpresaActivaTest` (lista, IDOR, empresa activa), `RecuperacaoPasswordEndurecidaTest`, e os já existentes `AutenticacaoTest`, `MfaTest`,
`AutenticacaoGoogleTest`, `AuditoriaEscaladaHorizontalTest`. Frontend: `sessao/armazenamento.test.ts`, `sessao/semAutenticacaoNoNavegador.test.ts`, `paginas/MinhasSessoes.test.tsx`.
