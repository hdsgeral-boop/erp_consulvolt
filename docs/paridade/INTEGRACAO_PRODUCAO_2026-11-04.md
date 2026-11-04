# Integração da produção no teste — análise e plano (2026-11-04)

Fonte: cópia só-de-código da produção (`C:\xampp\htdocs\ERP_CONSULVOLT_MEU\producao_copia`, snapshot de 2026-10-26, Laravel 12 / PHP 8.3 / React 18 / antd 5, sem git, sem segredos) comparada com este repositório (Laravel 13 / PHP 8.5 / React 19 / antd 6, ramo `teste-deploy`). Relatórios detalhados da análise (fora do git): `analise_api.md`, `analise_dados.md`, `analise_frontend.md`, `analise_regras.md` na pasta de trabalho da sessão.

## 1. O que a análise concluiu

- A produção é, em ~90 % dos ficheiros comuns, o estado deste repositório de 23–26 de Outubro, mais um conjunto de funções desenvolvido à parte (nunca existiu no historial git do teste). A diferença real é pequena e delimitada.
- **Esquema:** as 68 migrações com o mesmo nome são byte-idênticas. A produção tem 13 migrações só dela; o teste tem 34 que a produção não tem. Não há conflito de nomes de tabela.
- **Permissões:** o catálogo do teste é um superconjunto do da produção (17 módulos / 141 ecrãs / 245 tarefas contra 16 / 134 / 233). Não falta nenhum ecrã nem perfil. A única chave só da produção (`notificacoes_centro_ver`) foi descontinuada de propósito (ADR-077): não reintroduzir.
- No frontend, nos ficheiros comuns o teste está quase sempre à frente; a maioria das 447 diferenças é ruído do upgrade (antd 6, react-router 8).
- As duas linhas divergem em decisões de arquitectura (ver §4). Essas NÃO se portam sem decisão de produto.

## 2. Âmbito a integrar (sem decisão pendente)

Regras para TODAS as peças: portar lendo o código da produção e adaptando às convenções do teste (nunca copiar ficheiros por cima); migrações só aditivas, idempotentes (`hasTable`/`hasColumn`), com datas novas ≥ 2026_11_14 e sem reaplicar CHECKs/dados já alterados no teste; `config()` em vez de `env()` fora de `config/`; respeitar a RLS do teste (login e leituras de `utilizadores` sem tenant na sessão usam as funções SQL `login_localizar_utilizador`, `tenant_do_token`, `plataforma_utilizadores_por_tenant`); respeitar cookie HttpOnly + CSRF, MFA, login Google, troca obrigatória de palavra-passe (`ExigirTrocaPassword`); rotas do frontend com prefixo `/m/`; antd 6 / react-router 8 / React 19; levar os testes da produção correspondentes, adaptados; Pint, tsc, vitest e a suite completa verdes.

### Bloco A — Correcções e endurecimento (backend)
1. **Bug do teste:** `env('DB_APP_PASSWORD')` fora de `config/` em `app/Console/Commands/AplicarRlsSaasCommand.php`, `2026_10_17_160000_saas_rls_base.php`, `2026_10_19_100000_saas_rls_utilizadores.php` (com `artisan config:cache` a palavra-passe da role `erp_app` fica vazia). Passar a `config('erp.saas.db_app_password')` e trazer `SemEnvForaDaConfigTest`. Não alterar migrações já corridas além de trocar a leitura do valor (comportamento idêntico).
2. `IsolamentoRls::estado()` e a lógica nova de `prepararRole` (diagnóstico de RLS efectivo, NOSUPERUSER/NOBYPASSRLS separado do LOGIN PASSWORD).
3. **Webhook de pagamento seguro** (tabela `webhooks_pagamento`; HMAC com `hash_equals`, janela anti-replay, idempotência por evento, conferência de valor e moeda, estado/gateway, auditoria de todas as tentativas) — evoluir o `ProcessadorPagamentos` existente sem perder o que o teste já tem; trazer os 11 testes da produção (o teste tem 5).
4. **Segredos do gateway cifrados** (`SegredosGateway`, prefixo `enc:`, leitura cifrada e migração de dados que cifra o que está em claro; depende da `APP_KEY`; sem `APP_KEY` não cifrar nem quebrar) e a limpeza dos dados de exemplo de `configuracoes_gateway`.
5. **`simular-confirmacao` do pagamento:** NÃO condicionar a `APP_ENV` (o servidor de teste corre com `APP_ENV=production`); condicionar a `config('erp.saas.simulacao_pagamento')` (env `SAAS_SIMULACAO_PAGAMENTO`, omissão `false`) e acrescentar `true` ao exemplo/`.env.prod` do teste na documentação.
6. **`trustProxies` seguro** (só IP e protocolo; só proxies de redes privadas; nunca `X-Forwarded-Host`) e middleware `CacheCondicional` (ETag/304) nos GET do logótipo de login, empresa e identidade.
7. **Moedas e câmbios partilhados só pela plataforma** (`CAMBIO_PARTILHADO`, `ServicoMoedas`): correcção de isolamento entre clientes.
8. **Utilizadores:** reactivar utilizador eliminado (`UTILIZADOR_ELIMINADO`, `reactivar:true`), limite de utilizadores do plano na criação, login por e-mail com `EMAIL_AMBIGUO`, força de palavra-passe (`PALAVRA_PASSE_FRACA`) — sem trazer a «dispensa da troca» (`palavra-passe/dispensar`).
9. **Plataforma:** `PUT /plataforma/clientes/{c}/utilizadores/{u}` (editar nome, e-mail, papel, estado), `POST` e `PUT /plataforma/planos` (novo/editar plano; módulos do plano; manter `dias_teste`), `PUT` da identidade do sistema por cliente (nome e logótipo; coluna `tenants.logotipo_sistema`), função SQL `plataforma_empresas_licencas()`, `GET /licenca/dados-bancarios` (só gestor da licença), painel da plataforma com séries para gráficos (clientes novos 12 meses, receita, clientes por plano, acessos), filtros de clientes (`plano`, estado, pesquisa).
10. **Primeiro acesso da plataforma:** `GET /plataforma-inicial/estado` e `POST /plataforma-inicial/criar-admin` (só no host da plataforma, bloqueio consultivo PostgreSQL, `PLATAFORMA_SETUP_TOKEN` opcional via `X-Setup-Token`, resposta única «indisponível», 2 throttles). A criação do administrador continua a obedecer à política do teste (palavra-passe gerada pelo servidor + troca obrigatória) — adaptar.
11. `GET /sistema/dominio-permitido` (HTTPS a pedido do Caddy; aceitar também a área `portal.<base>`) e `DOMINIO_PLATAFORMA` configurável.
12. **Comandos:** `erp:planos:criar-interno`, `erp:licencas:planos-iniciais` (só o comando — NÃO a migração que activa licenças/atribui planos de clientes reais), `erp:empresas:invalidar-cache`.
13. **PGCA oficial:** `database/dados/pgca_angola.json` (755 contas), `pgca/simular` e `pgca` (sincronizar), plano-modelo configurável (`PLANO_CONTAS_EMPRESA_MODELO`); comparar com o plano de contas já alterado por migrações do teste antes de sincronizar (a simulação deve mostrar diferenças sem aplicar).
14. **Efectividade:** importação do picómetro e modelo de efectividade já preenchido (`ServicoAssiduidade::picometroParaLinhas`, `nomeNormalizado`).
15. **Acréscimos:** `POST /acrescimos/proposta/contabilizar` (reutilizando o job idempotente `ContabilizarPropostaAcrescimoJob` do teste).
16. Operação/documentação: `ferramentas/operacao/verificar_saas.sh`, lições do `RUNBOOK_ATIVACAO.md` (o `backend/.env` tem de ser legível pelo grupo do PHP-FPM; a cache de empresas pode ficar vazia sob RLS — confirmar se o teste tem o mesmo bug e cobri-lo com o equivalente do `LoginEmpresasSobRlsTest`), `docs/` de referência da produção (DEPLOY_AWS, instalar/atualizar, Caddyfile, docker-compose.aws, `desempenho.ini`/`PHP_OPCACHE_VALIDAR`) guardados como referência, NÃO activados (colidem com o nginx do host do teste).

### Bloco B — Assistente de IA (backend + frontend)
- Backend: `ServicoAgenteIA`, `ServicoFerramentasIA`, `AssistenteChatController` (9 rotas `/api/ia/*`), `IAPlataformaController` (8 rotas `/api/plataforma/ia/*`), `ServicoConfiguracaoIA`, fornecedores Anthropic/OpenAI/Gemini (`Fornecedores/*`), migrações `ia_*` (7 tabelas com RLS; reescrever com `hasTable`) e `ia_regras_cliente`; chaves dos fornecedores cifradas e configuradas na plataforma (o teste hoje só lê a chave do ambiente: manter o ambiente como reserva); atribuição por cliente, quota de tokens, consumo e custo; `ClienteClaude` multi-fornecedor mantendo compatibilidade com o `ServicoAssistenteIA` (assistente de lançamentos) já existente; permissões novas no catálogo editado à mão. Testes `AssistenteIAChatTest`, `ChavesFornecedoresIATest`.
- Frontend: `Markdown.tsx` (seguro, sem dependências), `AssistenteChatFlutuante.tsx` (chat, histórico, acções com confirmação, regras, quota), `plataforma/PlataformaIA.tsx` como ecrã do painel `/admin` (fornecedores, atribuição por cliente, consumo). Atenção ao nome igual ao «Assistente IA» dos lançamentos.

### Bloco C — Frontend sem backend novo
1. **POS Lavandaria:** `Recepcao.tsx` reescrita (684 linhas: cartões por peça, tecido/cor, valor declarado, cliente com pesquisa e «Novo cliente», adiantamento mínimo, painel de pagamentos, talão e etiquetas), `FrenteLavandaria.tsx` e o ramo em `FrenteCaixa` (hoje só um Alert). O backend de lavandaria é idêntico nos dois lados.
2. **Bug do teste:** `Utilizadores` ao Gravar com «acesso a todas as empresas» (`v.empresas.map` rebenta; a produção tem fallback).
3. Reactivar utilizador eliminado + `sugerirNomeUtilizador` + `problemaPalavraPasse` (só validação de força; sem campo «Gerar»).
4. Consola da plataforma: `UtilizadoresCliente.tsx`, `ModalNovoPlano.tsx`, identidade do sistema por cliente (`useIdentidadeSistema`, `LogotipoSistema`, logótipo reduzido no browser), clientes (pesquisa, filtro por estado, coluna Plano, `?novo=1`, `?q=`), painel com gráficos, primeiro acesso da plataforma.
5. Navegação: botão flutuante «Voltar», «Expandir/Recolher tudo», empresas recentes primeiro, «Repetir tutorial», `voltarAoInicio` ao sair/largar empresa, `RodapeEntrada`. `lerCaminhoEcra` de `caminhos.ts` só se for útil sem mudar os caminhos `/m/`.
6. Levar os testes só da produção correspondentes (`Recepcao.test`, `ModalNovoPlano.test`, `palavraPasse.test`, `voltarAoInicio.test`, `TourBoasVindas.test`).

## 3. Ordem e verificação
1. Branch `integrar-producao` a partir de `teste-deploy`; trabalho por blocos com commits pequenos.
2. Bloco A (backend) e Bloco C (frontend) em paralelo; Bloco B depois do A (usa as mesmas tabelas/permissões).
3. Cada bloco: testes da produção portados + testes novos; suite completa do backend (um `artisan test` de cada vez), vitest completo, `tsc`, Pint, `npm run build`.
4. Só depois: deploy no TESTE por `actualizar-teste.sh`, migrações no servidor de teste, verificação de saúde. A produção não é tocada.

## 4. NÃO portar sem decisão de produto (contradizem decisões do teste)
| Tema | Produção | Teste | Porque não se porta |
|---|---|---|---|
| Palavras-passe | o utilizador escolhe; recuperação por ligação com token (60 min); «dispensar troca»; primeiro acesso opcional («Agora não»); campo «Gerar» | palavra-passe sempre gerada pelo servidor, `ExigirTrocaPassword` bloqueia a API, recuperação envia palavra-passe nova por e-mail; MFA; login Google | filosofias exclusivas; trocar uma pela outra muda a segurança de todo o sistema |
| Licença | por cliente (`tenants.licenca_*`) | por empresa, com máquina de estados, ciclos e cupões | muda o modelo de dados e a facturação |
| Perfis e auditoria por cliente | `perfis_utilizador.tenant_id`, `logs_auditoria.tenant_id` (+ backfill) | perfis globais, auditoria particionada | reescreve dados e FKs; risco alto |
| Caminhos | `/{modulo}/{ecra}` sem prefixo, SPA `/plataforma` separada, token em `localStorage`, login por código entre domínios | `/m/{modulo}/{ecra}`, `/admin` `/suporte` `/app`, cookie HttpOnly | 244 referências a `/m/` em 117 ficheiros; contradiz a segurança de sessão |
| Servir o frontend | Blade por host (`FrontendController`, `/build/`) | `index.html` pelo nginx; `/auth/google` | colide com o roteamento actual |
| Marca | «MEU ERP» | «CONSULVOLT ERP» | decisão de marca |

Decisões que o utilizador pode querer tomar: (a) adoptar a recuperação de palavra-passe por ligação (como opção adicional ou em substituição); (b) licença por cliente; (c) caminhos sem `/m/`; (d) marca.
