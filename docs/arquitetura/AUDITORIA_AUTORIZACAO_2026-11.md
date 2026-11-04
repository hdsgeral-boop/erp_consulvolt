# Auditoria de autorização — Novembro de 2026

Âmbito: backend (Laravel 13 / PostgreSQL com RLS) e frontend (React 19). Pedido: impedir escalada de privilégios e o acesso a módulos ou ecrãs não permitidos, em qualquer rota. Ramo `endurecimento-seguranca`.

## 1. Método

1. **Inventário** com `php artisan route:list --json` (1366 entradas método+URI). Classificação pelos middlewares reais de cada rota (não há `can:`; a autorização está nos controladores: `$this->exigir(...)`, `Gate`, serviços e middlewares de área).
2. **Varrimentos automáticos** (testes): uma rota nova sem classificação faz falhar a suite (secção 3).
3. **Leitura dirigida** dos pontos de escalada: `ServicoPerfis`, `ServicoUtilizadores`, `PerfilController`, `UtilizadorController`, `ExigirPlataforma`/`ExigirSuporte`/`ExigirAdministradorCliente`/`ExigirAreaPortal`/`ResolverEmpresaAtiva`, delegações da chefia, decisões do portal, IA.
4. **Testes que falham sem a correcção** (verificado ao repor o código antigo).

## 2. Inventário

| Classe | Rotas | Controlo |
|---|---|---|
| Públicas | 19 | lista branca `PUBLICAS` (entrar, recuperar, registar, plataforma-inicial, saúde, definições/planos/logotipo/domínio públicos, webhooks AGT e pagamentos, feed OData com token próprio, Google OAuth, CSRF, `up`) |
| Só autenticadas, sem empresa | 11 | lista branca `SO_AUTENTICADAS` (dados da própria conta, MFA, selector de empresas) |
| Área restrita (`plataforma` 85, `suporte` 13, `admin_cliente` 9) | 107 | middleware de área + chave `plataforma_*` / categoria de suporte |
| Autenticadas com empresa activa | ~1230 | `ResolverEmpresaAtiva` (X-Empresa-Id validado contra as empresas do utilizador) + `exigir()`; varrimento com um utilizador sem permissões |

`storage/{path}` (GET/PUT, rota pública do disco local do Laravel) estava activa sem uso: desligada (`serve => false`).

## 3. Achados e correcções

### A1 — ALTA: escalada vertical por perfis (corrigido)
- **O quê / onde:** `ServicoUtilizadores::perfilAtribuivel()` só recusava perfis de acesso total (`all`). Um administrador sem acesso total (`config_util_gerir`) podia **atribuir-se a si próprio ou a outrem qualquer outro perfil**, por mais poderoso que fosse (mesmo com `config_perfis_gerir`, manutenção, etc.). `ServicoPerfis::guardar()` deixava quem tem `config_perfis_gerir` **criar ou alargar um perfil com permissões que ele próprio não tem** (e depois atribuí-lo).
- **Correcção:** `ServicoPermissoes::excedentes()` (permissões pedidas que o actor não tem; o acesso total não tem excedentes). `perfilAtribuivel()` recusa (403 `SEM_PERMISSAO_ADMINISTRATIVA`) um perfil com excedentes; `guardar()` recusa **acrescentar** permissões que o actor não tem (retirar ou manter continua permitido). Reatribuir o perfil que o utilizador já tem não dispara a regra.
- **Teste:** `AuditoriaEscaladaVerticalTest` (3 testes falham sem a correcção); `SistemaUtilizadoresPerfisTest` ajustado (o «gestor de acessos» de teste passou a ter `lancamentos_view`, que concede a outros).
- **Decisão de desenho:** é a regra pedida («ninguém dá o que não tem»). Consequência operacional: um administrador de acessos só consegue montar perfis dentro do seu próprio âmbito; quem precisa de conceder mais tem de ter acesso total.

### A2 — MÉDIA: operadores da plataforma sem chave acediam a leituras e a acções sensíveis (corrigido)
- **O quê / onde:** o middleware `plataforma` deixa entrar qualquer operador (só exige a chave nas operações de escrita de alguns controladores). Sem nenhuma chave, um operador conseguia: **terminar qualquer sessão** (`DELETE /api/plataforma/sessoes/{id}`, incluindo a de um super-administrador), listar sessões (IPs de todos os clientes), ler a auditoria de todos os clientes, ver e **confirmar transferências bancárias** (`POST /transferencias/{subscricao}/confirmar` — prolonga a licença e emite factura), descarregar comprovativos, ler administradores/operadores/agentes, planos, cupões, definições, estado SaaS, relatórios CSV de clientes/empresas, criar e **eliminar tickets** (`POST/DELETE`).
- **Correcção:** `exigir()` com a chave `*_view` / `*_gerir` do ecrã correspondente em cada acção (sessões, auditoria, códigos, administradores, operadores, agentes, planos, cupões, definições, SaaS, transferências, relatórios, criar/eliminar ticket). Super-administradores não são afectados (acesso total).
- **Teste:** `AuditoriaPlataformaVarrimentoTest` varre `api/plataforma/*` com um operador sem chaves (excepções de desenho listadas e justificadas).

### A3 — BAIXA: chaves da plataforma/suporte anunciadas em perfis de cliente (corrigido)
- `ServicoPermissoes::efectivas()` devolvia ao frontend chaves `plataforma_*`/`suporte_*` gravadas num perfil de cliente, embora `tem()`/o Gate já as negassem. Passam a ser filtradas. Teste em `AuditoriaEscaladaVerticalTest` (também confirma 403 em `/api/plataforma/*` e `/api/suporte/*`).

### A4 — BAIXA: rota pública `storage/{path}` (GET e PUT) (corrigido)
- Rota do disco local do Laravel (`serve => true`), sem uso (não há URLs temporários). Desligada em `config/filesystems.php`. O varrimento `toda_a_rota_esta_classificada…` falha se reaparecer.

### A5 — BAIXA: sem limite específico na criação de contas e na reposição de passwords (corrigido)
- `POST /sistema/utilizadores` e `POST /sistema/utilizadores/{id}/palavra-passe` passam a `throttle:pesado` (por utilizador). Login (`entrar`), recuperação (6/min), password (`palavra-passe`), IA (`ia-chat`) e MFA já tinham limite.

### A6 — varrimento de classificação (novo)
- `OwaspControloAcessoTest::toda_a_rota_esta_classificada_…` falha se surgir uma rota **pública** fora de `PUBLICAS`, uma rota autenticada sem empresa nem área fora de `SO_AUTENTICADAS`, ou uma entrada obsoleta nas listas brancas. O varrimento existente (utilizador sem permissões → 403/404) mantém `SEM_PERMISSAO_POR_DESENHO` e passou a incluir o método `PATCH`, que ignorava (a rota `PATCH contabilidade/assistente/ficheiros-regras/{regra}` nunca era testada; verificada: recusa com 403).

### Verificado sem achado (com testes)
- **X-Empresa-Id** (`AuditoriaEscaladaHorizontalTest`): empresa alheia, outro cliente (tenant), valores malformados/inexistentes e «lista» → recusados; fichas de empresa de outro cliente → 404; corpo do pedido com `empresas[].empresa_id` alheio → 403. RLS: `IsolamentoRls*` já cobre o isolamento na base.
- **Papel / acesso global**: só o super-administrador define `papel`; `acesso_todas_empresas` exige acesso total (testado, create e update).
- **Self-service** (`DadosPessoaisController`): só `nome_completo`, `telefone`, `email` validados; nunca `papel`/`perfil`.
- **Mass assignment**: nenhum `$request->all()` em controladores (excepto o corpo bruto do webhook assinado); os modelos têm `$fillable` e `PertenceEmpresa` lança erro se `empresa_id` não for o da empresa activa.
- **Auto-aprovação**: portal RH (`AUTO_APROVACAO`, `SEGREGACAO_ETAPAS`), ausências, compras, orçamento, multas e entregáveis têm a regra no serviço.
- **Delegações**: o portal só cria delegações em nome do próprio colaborador (`delegante_colaborador_id` forçado) e só o delegante as revoga; o âmbito é validado (`DelegacaoChefia::AMBITOS`) e a decisão em nome de outrem só vale para o aprovador designado.
- **IA**: conversas só do próprio utilizador (teste existente `AssistenteIAIntegracaoTest`), ferramentas validam a permissão do ecrã (ADR-081).
- **Cabeçalhos/CORS** (`AuditoriaCabecalhosCorsTest`): CSP (sem `unsafe-eval`, `frame-ancestors 'self'`, `object-src 'none'`, `https://*.r2.dev` em `img-src`), `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, CORP, HSTS condicional; CORS sem origens por omissão e sem `*`.
- **Depuração**: nenhuma rota de debug/telescope/phpinfo/tinker.

## 4. Parte 1 — menu e frontend

- `ia_chat` (ecrã que só transporta `ia_chat_view`/`ia_chat_regras`) sai do menu (`ServicoPermissoes::ECRAS_SEM_MENU`); a permissão mantém-se para o Gate e os perfis. Não existia rota, ecrã nem entrada de pesquisa no frontend (`ecras.ts`, `registo.tsx`, `menuLegado.ts`, `indicePesquisa.gerado.ts`).
- `/m/geral/ia_chat` (e qualquer `/m/*/ia_chat`) reencaminha para `/inicio` com aviso (`paginas/guardaEcra.ts`, `Ecra.tsx`). `Assistente IA (regras)` (`ia_assistente`, Contabilidade) mantém-se.
- Guarda de ecrãs `decidirEcra()` (testada em `guardaEcra.test.ts`): só abre o que o menu do servidor (já filtrado por perfil, plano e holding) traz sob esse módulo; módulo trocado, ecrã inventado ou `plataforma_*` → «Sem acesso». `/admin` só para super-administrador, `/suporte` para super-administrador ou agente de suporte, `/portal` e `/app` nunca para agente de suporte (`App.tsx`); o servidor é sempre a autoridade (`ExigirPlataforma`, `ExigirSuporte`, `ExigirAreaPortal`, `exigir()`).
- Ecrãs sem dono: nenhum encontrado (todos os ecrãs registados no frontend existem no catálogo ou no menu legado).

## 5. Riscos residuais e observações

1. **[Mitigado em ADR-082, 2026-11-19: limite por conta e intervalo mínimo, resposta de tempo uniforme, a palavra-passe actual só muda depois de a nova ser entregue, aviso ao titular com hora/IP/dispositivo; a alternativa por ligação de uso único não foi implementada — ver `DECISOES.md`]** Recuperação de password reinicia a conta do alvo (`/autenticacao/recuperar-password`): quem conhece um utilizador pode forçar a troca da sua password por uma gerada (e enviada por email) — negação de serviço contra uma conta, limitada a 6/min por IP. A solução (ligação com token de uso único em vez de reposição imediata) toca na emissão de credenciais/sessão e fica para a tarefa seguinte.
2. Um operador da plataforma com `plataforma_sessoes_gerir` pode terminar a sessão de um super-administrador; aceite (acção de moderação), mas convém registar em auditoria.
3. `GET /plataforma/clientes`, `/empresas` e as listas de suporte ficam abertas a qualquer operador por desenho (lista filtrada pelas categorias do perfil no suporte).
4. O frontend mostra «Sem acesso» enquanto o menu ainda não carregou (instante de arranque); sem impacto de segurança.
5. Operadores de plataforma entram pelo servidor mas o frontend só abre `/admin` a super-administradores (UX mais restritiva do que o servidor, no sentido seguro).
6. [Tratado em `SESSOES_E_COOKIES.md`/ADR-082] Notas para a tarefa seguinte (tokens/sessões): não foram tocados `sessao/armazenamento.ts`, `api/cliente.ts`, cookies, emissão/rotação de tokens, multi-sessão.
