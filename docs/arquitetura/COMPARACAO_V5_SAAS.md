# Comparação sistema actual × v5 — componente SaaS e mapeamento de compatibilidade

Documento vivo. Serve para anotar as comparações entre o sistema actual (`erp_laravel/`) e a v5 (`erp_laravel/v5/erp_laravel/`) e para preparar, mais tarde, a implementação da componente SaaS **sempre compatível** com o sistema actual.

- Primeira comparação: **2026-10-17**.
- Estado: **só análise. Nada da v5 foi copiado para o sistema actual, nenhuma migração foi aplicada, a v5 não foi executada nem testada.**
- Marcas usadas: **[verificado]** = confirmado agora no código ou nos ficheiros; **[doc v5]** = afirmado pela documentação da v5 (não reexecutado); **[proposta]** = decisão a tomar ou plano.

## 0. Como a comparação foi feita

1. Comparação ficheiro a ficheiro (tamanho e SHA-256) das duas árvores, sem `node_modules`, `vendor`, `dist`, `storage`, `.git`, `.env`, `.segredos`, logs e dumps.
2. Leitura da documentação SaaS da v5: `docs/arquitetura/SAAS.md`, `SAAS_ARQUITETURA.md` e `docs/SAAS_REGISTO_DE_ALTERACOES.md`.
3. Leitura do código central: migração de tenants e RLS, middleware, contexto de tenant, rotas da plataforma.
4. Diff, ficheiro a ficheiro, dos ficheiros partilhados que a v5 alterou para suportar SaaS.
5. Verificação de sobreposições com o sistema actual (tabelas, colunas, ADRs, rotas públicas, comandos sem sessão).

Limitações: a análise é estática. Não há histórico git comum, por isso não é possível separar «o que a v5 mudou» de «o que o actual mudou» em ficheiros com muitas diferenças. Nestes casos usou-se a leitura do diff. A pasta `v5/` aparece como não versionada no git (`?? v5/`) [verificado]; convém ignorá-la no `.gitignore` ou movê-la para fora do repositório.

## 1. Números

| Medida | Valor |
|---|---|
| Ficheiros comparados no sistema actual | 2 211 |
| Ficheiros comparados na v5 | 1 812 |
| Só na v5 | **100** (a componente SaaS e extras) |
| Só no actual | **499** (evolução posterior à v5) |
| Existem nos dois mas diferem | **661** |
| Desses, com marcas SaaS na v5 | **38** (ver §4) |

Só na v5, por pasta: `backend/app/Http` 18, `frontend/src/plataforma` 16, `backend/tests/Feature` 16, `backend/database/migrations` 11, `frontend/src/modulos` 10, `backend/app/Models` 7, `backend/app/Services` 7, `frontend/e2e` 4, `docs` 3, restantes 8.

Só no actual, por pasta: `frontend/src/modulos` 197, `backend/app/Services` 57, `backend/tests/Feature` 53, `backend/app/Models` 47, `backend/app/Http` 32, `frontend/src/componentes` 31, `backend/database/migrations` 25, `backend/routes/api` 23, `frontend/src/layout` 12, restantes 22.

Diferenças nos 661 ficheiros partilhados: `frontend/src` 292, `backend/app` 285, `backend/tests` 33, `backend/database` 16, `backend/routes` 8, `docs` 7, restantes 20. **A esmagadora maioria não é SaaS**: é divergência normal entre uma versão mais antiga e o sistema actual.

## 2. Conclusão principal

1. **A v5 é uma versão anterior do sistema com a camada SaaS acrescentada.** O sistema actual evoluiu muito depois (as 25 migrações exclusivas do actual vão de 2026-10-10 a 2026-10-17: RH, projectos, activos, repartição, vendas, AGT por empresa, etc.). [verificado]
2. **Por isso não se substitui nem se copia a v5 por cima do actual.** Perder-se-iam 499 ficheiros e as alterações em muitos dos 661 partilhados. A SaaS tem de ser **portada** para o actual, peça a peça.
3. O núcleo SaaS é pequeno e isolado: 3 modelos de tenant/plano, 1 middleware, 2 classes de contexto, ~16 controllers e serviços da plataforma, 11 migrações, 16 ecrãs de plataforma e 7 de «Minha conta». As alterações em ficheiros partilhados são **cirúrgicas** (§4).
4. O sistema actual **não tem nenhuma noção de tenant** (3 ocorrências da palavra, nenhuma funcional). [verificado]
5. A parte difícil não é copiar código: é **garantir que tudo o que o actual construiu depois continua a funcionar sob RLS fail-closed** (§5).

## 3. Modelo SaaS da v5 (resumo)

- **Hierarquia:** tenant → empresa → dados. Tenant = grupo de empresas. Tenant 1 = CONSULVOLT (operador). [doc v5]
- **Isolamento em 3 camadas:** `EscopoEmpresa` na aplicação, contexto de sessão (`app.tenant_id`) e RLS no PostgreSQL. Fail-closed. O tenant nunca vem do cliente: é o do utilizador autenticado. [doc v5, confirmado no código]
- **Sem `tenant_id` em ~164 tabelas**: a RLS chega ao tenant por `empresa_id IN (SELECT id FROM empresas)`, com `empresas.tenant_id`. [verificado na migração]
- **Roles de base de dados:** `erp_app` (aplicação, sem superuser e sem BYPASSRLS) e dono da base (`pgsql_owner`, só migrações). [verificado]
- **Endereços por cliente:** `admin.erp.<base>` (plataforma), `<slug>.<base>` (cliente), base/`www` (site), outro (livre). [doc v5]
- **Plataforma:** painel, operação, clientes, sessões, crescimento, planos com versões (preço, módulos, limites), suporte com conversa e email por categoria, auditoria, administradores, definições, manual de códigos, IA Tokens. [doc v5]
- **Cliente:** «Minha conta» (plano, planos, facturação, suporte, empresa), identidade por cliente no login, indicadores de plano e AGT no cabeçalho, foto de perfil. [doc v5]
- **Não feito na v5 (segundo a própria documentação):** facturação e pagamentos de subscrições, self-service de registo, cofre de segredos AGT por tenant, impersonação de suporte, backups por tenant, trigger contra ligações cruzadas entre tenants. [doc v5]

## 4. Ficheiros partilhados que a v5 alterou para SaaS (pontos de contacto)

Linhas = `+acrescentadas/−removidas` de actual → v5. «−» grande não significa trabalho da v5: o actual tem mais código.

| Ficheiro | Linhas | O que a v5 muda | Risco ao portar |
|---|---|---|---|
| `backend/bootstrap/app.php` | +3 | `ResolverTenantAtivo` no grupo `api` (append) | Baixo. Atenção à ordem com o middleware de ids opacos (ronda r4_ids). |
| `backend/app/Support/Tenancy/ContextoEmpresa.php` | +35 | Recebe `ContextoTenant`; deduz/valida o tenant da empresa (`app_tenant_da_empresa`) | Médio: uma consulta SQL em cada `definir()` e `executarComo()`. |
| `backend/app/Providers/AppServiceProvider.php` | +37/−9 | `ContextoTenant` singleton; `configurarFilas` (limpa o tenant entre trabalhos). **O diff também mostra que o actual tem** `ClienteAgtPorEmpresa` e `OrdenacaoTexto` que a v5 não tem | **Alto se for copiado inteiro.** Só se acrescentam os blocos de tenant. |
| `backend/app/Exceptions/ErroContextoEmpresa.php` | +38 | Erros novos (`empresaDeOutroTenant`, `tenantSuspenso`, `dominioDesconhecido`, …) | Baixo (aditivo). |
| `backend/app/Models/Empresa.php`, `Utilizador.php` | +6 cada | `creating`: `tenant_id` do contexto; fora do `fillable` | Baixo. |
| `backend/app/Services/Autenticacao/ServicoAutenticacao.php` | +6/−1 | Login recusa conta que não pertence ao endereço | Baixo. |
| `backend/app/Http/Controllers/Api/Autenticacao/AutenticacaoController.php` | +3 | `perfilSessao` define o tenant antes de listar empresas | Baixo. |
| `backend/app/Services/Sistema/ServicoGestaoEmpresas.php`, `ServicoUtilizadores.php` | +17/−1, +4/−2 | Limite do plano ao criar empresa/utilizador; nome do cliente segue a empresa principal | Médio: o actual tem mudanças próprias (ex.: conversão de contas, ADR-071). |
| `backend/app/Http/Controllers/Api/Sistema/ConfiguracaoSistemaController.php` | +70/−12 | Logótipo e nome do sistema por tenant (`login_logo:<tenant>`, `sistema_nome:<tenant>`); leitura pública por endereço | Médio: a rota pública `sistema/logotipo-login` existe nos dois. |
| `backend/config/database.php` | +18 | Ligação `pgsql_owner` | Baixo. |
| `backend/config/erp.php` | +74/−16 | Secções `dominio`, `planos_catalogo`, `codigos_erro` (a v5 removeu `planos` ao passar para tabela) | Médio: o actual acrescentou `agt.*` (r4_agt). Fundir, não substituir. |
| `backend/routes/api.php` | +11/−34 | `require plataforma.php` no grupo autenticado sem empresa. **Os «−34» são rotas que só o actual tem** | **Alto se for copiado.** Acrescentar apenas a linha da plataforma. |
| `backend/routes/console.php` | +5/−2 | Expiração de contratos por tenant (`paraCadaTenant`) | Baixo. |
| `backend/app/Console/Commands/CicloAgtCommand.php` | +12/−16 | Ciclo AGT por tenant. **Conflita** com a versão r4_agt do actual | **Alto.** Reaplicar a ideia por cima da versão actual. |
| `backend/app/Console/Commands/CriarParticoesAuditoria.php` | +9/−5 | DDL pela ligação `pgsql_owner`; `REVOKE UPDATE/DELETE` nas partições novas | Baixo. |
| `backend/tests/TestCase.php` | +53/−2 | `migrateFreshUsing` com `pgsql_owner`; `setUp` define o tenant 1; `criarComTenant` | **Alto**: afecta todos os testes e as bases de teste por agente (§5.6). |
| `backend/tests/Feature/SistemaEmpresasMoedasTest.php` | +2/−1 | Logótipo por tenant | Baixo. |
| `docker-compose.yml` | +7/−2 | `DB_USERNAME=erp_app`, variáveis `DB_APP_*` e `DB_OWNER_*` | Médio: muda o utilizador da base em `app`, `worker` e `scheduler`. |
| `frontend/src/App.tsx` | +24/−5 | Área de plataforma, rotas «Minha conta» e IA Tokens. **A v5 não tem a rota `portal/*` (`PortalAutonomo`) que o actual tem** | **Alto se for copiado:** perde-se o portal do colaborador. Acrescentar só as rotas novas. |
| `frontend/src/layout/LayoutPrincipal.tsx` (+ teste) | — | Avatar, indicadores de plano e AGT, botão de recolher o menu | Médio: o actual tem 12 ficheiros de layout novos (ronda r3). |
| `frontend/src/modulos/geral/ecras.ts`, `backend/routes/api/gestao_paineis.php` | +1, +6 | Ecrã e rotas do **BI contabilístico** | **Conflito de decisão** (§7.1). |
| `README.md`, `docs/arquitetura/DECISOES.md` | — | Instruções `--database=pgsql_owner`; ADRs | Ver §5.9. |
| Migrações-base `2026_09_30_1001…1009*` | várias | **Não são SaaS**: a v5 tem versões anteriores do esquema gerado | **Não copiar.** O actual regenera-as (§5.8). |
| `ferramentas/gerador/*`, `database/legado/mapa_de_para.json`, `docs/dicionario/*` | — | Ficheiros gerados/de levantamento, sem lógica SaaS | Não copiar. |

## 5. Riscos de compatibilidade específicos do sistema actual

Este é o ponto que decide se a SaaS fica «sempre compatível».

### 5.1 Caminhos sem sessão ficam cegos com RLS fail-closed
Com a RLS, uma consulta sem `app.tenant_id` devolve **zero linhas**. A v5 só define o tenant a partir do utilizador autenticado. No actual há caminhos novos, depois da v5, que não têm utilizador: [verificado nos ficheiros]

| Caminho | Onde | Efeito sem tratamento |
|---|---|---|
| Webhook AGT por empresa | `routes/api/r4_agt_publico.php`, `ServicoWebhookAgt` (usa `semIsolamento` para achar a empresa pelo token) | A empresa não é encontrada: o webhook falha. |
| Feed OData do Power BI | `routes/api/bi_odata.php` (token de leitura por empresa, fora do Sanctum) | Zero linhas. |
| Ecrã de entrada | `sistema/logotipo-login` (público) | A v5 resolve pelo endereço; no actual não existe domínio por cliente. |
| Job e comando do ciclo AGT | `CicloAgt`, `CicloAgtCommand`, `ProcessarWebhookAgt` | Sem tenant. |
| Comandos de manutenção | `DocumentosImportadosSaftCommand`, `ConverterContasProdutosCategorias`, `ReconciliarRecibosTesouraria`, `MigrarBackupLegado` | Sem tenant (CLI). |
| Agendador | `routes/console.php` | A v5 já trata a expiração de contratos; os restantes não. |

**Proposta:** (a) funções `SECURITY DEFINER` mínimas para resolver tenant/empresa por token (como `app_tenant_da_empresa`); (b) um helper `ContextoTenant::paraCadaTenant` já existente na v5, usado em todos os comandos e jobs; (c) um teste que enumera os comandos/jobs e falha se algum acede a tabelas de negócio sem definir tenant.

### 5.2 Tabelas criadas depois da v5 não têm política RLS
A migração da v5 lê as tabelas com `empresa_id` **no momento em que corre**. As 25 migrações exclusivas do actual criam tabelas novas (por exemplo `agt_ligacoes_empresa`, `agt_comunicacoes`, `agt_submissoes`, `agt_webhook_eventos`, tabelas de activos, repartição, RH, projectos) que ficariam **sem RLS**: o isolamento só estaria na aplicação. [verificado: o actual tem 197 tabelas com `empresa_id`]

**Proposta:** comando idempotente «aplicar RLS às tabelas sem política» e um **teste de contrato** (extensão do `EsquemaContratoTest`) que falha se existir tabela com `empresa_id` sem política. Passa a ser requisito de qualquer migração futura.

### 5.3 Mudança de utilizador da base de dados
Passar a aplicação a `erp_app` (sem DDL) muda: o utilizador em `app`, `worker` e `scheduler`; **quem corre `migrate` no arranque do contentor** (hoje o actual aplicou as migrações ao reiniciar, [verificado em conversa anterior]); `ALTER DEFAULT PRIVILEGES`; `GRANT` em todas as tabelas; partições de auditoria novas. Requer uma palavra-passe nova (`DB_APP_PASSWORD`) e a ligação `pgsql_owner`.

### 5.4 Tabela de migrações e regra do projecto
A tabela de migrações do actual chama-se `migracoes` (`config('database.migrations')`) [verificado]. A regra do projecto proíbe `migrate` na base de desenvolvimento com dados reais sem cópia e decisão. **A migração de SaaS tem de ser ensaiada numa cópia restaurada do backup**, nunca primeiro na base real.

### 5.5 Dados reais
O registo da v5 descreve operações **feitas na base daquela cópia** (apagar 13 empresas e 3 utilizadores, criar clientes de teste). **Nada disso se repete.** Só se porta o esquema e o código. O actual tem de passar a ter o tenant 1 com os dados existentes.

### 5.6 Testes e bases de teste por agente
O actual usa uma base de teste por agente (`erp_agente_<agente>_testes`) e um `phpunit_<agente>.xml`. Com a v5, o `TestCase` cria o esquema pelo dono (`pgsql_owner`) e corre os testes como `erp_app`: cada base de teste precisa da role `erp_app` e dos privilégios, e o `phpunit.xml` das variáveis `DB_OWNER_*`. Sem isto, todos os testes do actual partem. **Proposta:** variável de configuração que mantém o modo actual (sem RLS) por omissão e liga o modo SaaS em testes específicos (`Tenancy/*`).

### 5.7 Controlo de acesso por permissões do catálogo
A plataforma da v5 autoriza por «super-administrador do tenant da plataforma» (`exigirPlataformaDe`), **não** por permissão do catálogo (`catalogo.json`), que é a regra do actual. E o `OwaspControloAcessoTest` do actual só varre rotas com o middleware `empresa`; as rotas `plataforma/*` e `minha-conta/*` ficariam fora do varrimento. **Proposta:** permissões novas no catálogo (por exemplo `plataforma_*`, `minha_conta_*`) e um varrimento próprio para estas rotas.

### 5.8 Esquema gerado
O actual gera migrações-base e modelos a partir de `database/legado/esquema.json` com `ferramentas/gerador` (modelos `Base/*Base.php`; «não editar»). As tabelas SaaS da v5 são migrações manuais. **Proposta:** declará-las no `esquema_extra.mjs`, ou documentá-las como exceção, para o `EsquemaContratoTest` não falhar.

### 5.9 Documentação e numeração de ADRs
A v5 propõe ADR-040 a 045; o actual chegou ao **ADR-071** (e a ronda r4_ids prevê o 072). Renumerar as decisões SaaS a partir do 073. A v5 não tem os ADRs 066–071 do actual (o seu `DECISOES.md` acaba no 070).

### 5.10 Interacção com o trabalho em curso
- **Ids opacos (ronda r4_ids):** a v5 usa `whereNumber` nas rotas da plataforma (`{cliente}`, `{ticket}`, `{administrador}`). O middleware de ids opacos reescreve o URL antes do roteamento. É compatível, desde que se registem os tipos e se ordene o middleware antes de `ResolverTenantAtivo`. A tarefa dos ids opacos está **incompleta** (codificador e testes unitários feitos, sem middleware).
- **AGT por empresa (r4_agt):** o actual já guarda chaves e credenciais por empresa (`agt_ligacoes_empresa`, cifradas). Isto **antecipa** a pendência «cofre AGT por empresa» da v5 (ADR-044 proposto). O que falta no actual é o prefixo de tenant e a cifra com chave de cofre em vez da `APP_KEY`.
- **BI contabilístico:** ver §7.1.

## 6. Mapeamento de dados (v5 → sistema actual)

| Tabela v5 | Conteúdo | Já existe no actual? | Integração |
|---|---|---|---|
| `tenants` | id, nome, slug (único), estado (ATIVO/SUSPENSO/ARQUIVADO), plano, subscricao_inicio/fim, ia_limite_mensal | Não | Criar. Tenant 1 = CONSULVOLT com os dados existentes. |
| `empresas.tenant_id`, `utilizadores.tenant_id` | FK obrigatória | Não | Aditiva: nula → preencher com 1 → `NOT NULL` → FK e índice. |
| Funções `app_tenant_id()`, `app_tenant_da_empresa(bigint)` | Contexto e resolução | Não | Criar. |
| Políticas RLS `isolamento_tenant` | Uma por tabela com `empresa_id` | Não | Aplicar a **todas as tabelas actuais** (§5.2). Anuláveis (`taxas_cambio`, `pedidos_manutencao_equipamentos`): visíveis se `empresa_id IS NULL`. |
| Role `erp_app` | Aplicação | Não | Criar com cuidado (§5.3). |
| `planos`, `plano_versoes`, `plano_versao_modulos`, `plano_versao_limites` | Catálogo de planos | Não | Criar. Catálogo de módulos e limites em `config/erp.php` (`planos_catalogo`): confrontar com os módulos reais do actual. |
| `tickets_suporte`, `mensagens_suporte`, `definicoes_plataforma` | Suporte | Não | Criar. |
| `ia_recargas`, `tenants.ia_limite_mensal` | Créditos IA | Não | Criar. |
| `utilizacoes_assistente_ia` | Registo de pedidos à IA | **Sim** (migração-base da contabilidade) | A migração da v5 tem `hasTable` e **não faz nada**: seguro. [verificado] |
| `utilizadores.foto` | Foto de perfil | Não | Aditiva. Não é SaaS (§7). |
| `logs_auditoria` | Auditoria | Sim | Sem RLS por decisão (ADR-009); só revogar UPDATE/DELETE a `erp_app`. |
| Tabelas sem RLS | `tenants`, `utilizadores`, `personal_access_tokens` | — | Igual à v5. O isolamento de utilizadores é da aplicação. |

**Guardas:** a regra do projecto exige `Schema::hasTable/hasColumn`. Das migrações da v5, só a de `utilizacoes_assistente_ia` as tem [verificado]. Todas as outras têm de ser reescritas com guardas ao serem portadas.

## 7. Divergências de decisão (a resolver antes de portar)

1. **BI contabilístico.** A v5 inclui `BIController`, `ServicoBI`, `AccountingBI.tsx` e rotas `gestao/bi/*`. O actual **retirou** o BI contabilístico a pedido do utilizador em 17/10/2026 (comentário em `gestao_paineis.php` e migração `r3_layout_retirar_bi_contabilistico_dos_perfis`). **Não importar.**
2. **Nome do produto.** A v5 chama-se «MEU ERP» e «GESTÃO EMPRESARIAL»; o actual usa «ERP Consulvolt». Decidir por cliente (a v5 já permite nome do sistema por tenant).
3. **Portal do colaborador.** O actual tem `portal/*` (`PortalAutonomo`), a v5 não. Preservar.
4. **Foto de perfil, IA Tokens e indicadores do cabeçalho.** Funcionalidades que não são SaaS em si. Decidir se entram nesta fase.
5. **Indicador AGT do cabeçalho.** A v5 liga a `/m/vendas/vendas_faturacao/agt`; o actual tem ecrãs novos (ligação por empresa e monitor). Reapontar.
6. **Endereços por cliente** (`<slug>.<base>`): dependem de DNS, `hosts` e do Nginx (`server_name _`). Decisão de infraestrutura.

## 8. Invariantes de compatibilidade (lista de verificação)

A implementação SaaS só é aceite se, **sem nenhum cliente adicional**, o sistema actual se comportar exactamente como hoje:

1. Com um único tenant (CONSULVOLT), todas as rotas e ecrãs do actual funcionam e devolvem os mesmos dados.
2. Os testes existentes do actual continuam a passar sem alteração (ou com ajuste só no `TestCase`).
3. Nenhuma tabela com `empresa_id` fica sem política RLS (teste de contrato).
4. Nenhum comando, job, webhook ou rota pública acede a negócio sem tenant definido (teste de enumeração).
5. Migrações aditivas, com guardas, ensaiadas numa cópia restaurada; dados existentes preservados; relatório de contagens antes e depois.
6. Nenhuma permissão perdida; permissões novas atribuídas no catálogo e na migração (como em `r4_agt`).
7. O `X-Empresa-Id` continua validado dentro do tenant; mensagem igual para «não existe» e «sem acesso».
8. Interruptor de funcionalidade (flag) para desligar o modo SaaS: sem ele o actual corre como hoje.
9. Desempenho: medir `EXPLAIN` e tempo das consultas mais pesadas (lançamentos, vendas, auditoria) antes e depois da RLS, por causa do subselect em cada tabela.
10. E2E (`frontend/e2e`) intactos.

## 9. Plano proposto (por fases, cada uma entregável e reversível)

| Fase | Conteúdo | Critério de saída |
|---|---|---|
| **0 — Preparação** | Ignorar `v5/` no git. Cópia restaurada da base num ambiente de ensaio. Inventário de caminhos sem sessão (§5.1). Decidir §7. | Lista fechada e decisões registadas. |
| **1 — Contexto sem RLS** | `ContextoTenant`, `tenants`, `tenant_id` (aditivo), funções, tenant 1; `ResolverTenantAtivo`; flag desligada. | Actual idêntico com a flag desligada; testes verdes. |
| **2 — RLS** | Role `erp_app`, políticas em **todas** as tabelas, comando idempotente, teste de contrato, ajuste do `TestCase` e das bases de teste. | Teste de contrato verde; ensaio completo na cópia; medições de desempenho. |
| **3 — Caminhos sem sessão** | Webhook AGT, BI OData, jobs, comandos e agendador com tenant explícito. | Teste de enumeração verde. |
| **4 — Plataforma** | Modelos, serviços, controllers, rotas e ecrãs de `admin`, com permissões no catálogo e varrimento OWASP próprio. | Matriz de acesso verde; auditoria das acções da plataforma. |
| **5 — Cliente** | «Minha conta», planos com versões e limites, suporte, identidade por cliente. | Limites aplicados nas criações de empresa e utilizador. |
| **6 — Operação** | Domínios por cliente, facturação de subscrições, cofre AGT, backups por tenant, impersonação. | Fora do âmbito da v5: precisam de decisão. |

Cada fase com cópia de segurança antes, relatório e **autorização expressa do utilizador** para tocar na base real.

## 10. Decisões pendentes (do utilizador)

1. Modelo de isolamento (a v5 usa base partilhada com RLS): confirmar.
2. O que entra primeiro: só isolamento (fases 1 a 3) ou também plataforma e cliente?
3. BI contabilístico, foto de perfil e IA Tokens: entram ou ficam de fora?
4. Nome do produto por cliente.
5. Domínio base e política de endereços por cliente.
6. Quem emite as facturas (enquadramento fiscal AGT de um operador que factura por terceiros): a v5 deixa em aberto.
7. Gateway de pagamentos e facturação de subscrições.

## 11. Registo de comparações (acrescentar no fim)

Formato: data · área · o que se comparou · conclusão · ficheiros · pendências.

- **2026-10-17 · visão geral** · árvores completas e documentação SaaS da v5 · a v5 é uma versão anterior com SaaS; portar, não substituir · §1, §2 · decisões da §10.
- **2026-10-17 · ficheiros partilhados** · diff dos 38 ficheiros com marcas SaaS · pontos de contacto e riscos · §4 · rever `CicloAgtCommand`, `AppServiceProvider`, `App.tsx`, `routes/api.php` ao portar.
- **2026-10-17 · compatibilidade** · caminhos sem sessão, tabelas pós-v5, roles, testes · seis riscos principais · §5 · testes de contrato propostos (§8).

## Anexo A — Ficheiros que só existem na v5 (100) e destino proposto

Caminhos relativos a `v5/erp_laravel/` e ao sistema actual. Acção: **PORTAR** = copiar e adaptar; **ADAPTAR** = reescrever sobre a versão actual; **NÃO** = não importar.

### A.1 Núcleo de tenancy (backend)
| Ficheiro v5 | Acção | Notas |
|---|---|---|
| `backend/app/Models/Tenant.php` | PORTAR | Estados ATIVO/SUSPENSO/ARQUIVADO. |
| `backend/app/Support/Tenancy/ContextoTenant.php` | PORTAR | `set_config` na sessão; `executarComo`, `paraCadaTenant`. |
| `backend/app/Support/Tenancy/DominioPedido.php` | PORTAR (fase 6) | Depende de DNS. |
| `backend/app/Http/Middleware/ResolverTenantAtivo.php` | ADAPTAR | Coordenar com o middleware de ids opacos e com `DominioPedido`. |
| `backend/database/migrations/2026_10_14_100000_criar_tenants_e_isolamento_rls.php` | ADAPTAR | Guardas; cobrir todas as tabelas actuais; comando idempotente. |
| `…_110000_tenants_slug_por_dominio.php` | ADAPTAR | Guardas. |
| `…_160000_subscricao_datas_tenants.php` | ADAPTAR | Guardas. |

### A.2 Plataforma e planos (backend)
| Ficheiro v5 | Acção |
|---|---|
| `Api/Plataforma/{Administradores,AuditoriaPlataforma,Clientes,Codigos,Definicoes,Painel,Planos,PlanoVersoes,Sessoes,Suporte}Controller.php` | PORTAR (permissões no catálogo) |
| `Api/Plataforma/{ConversaSuporteCliente,ConversaSuportePlataforma,IaCreditosPlataforma,MinhaConta}Controller.php` | PORTAR |
| `Api/Integracoes/IaCreditosController.php` | PORTAR (fase 5) |
| `Services/Plataforma/{ServicoClientes,ServicoPlanos,ServicoVersoesPlano,ServicoConversaSuporte,ServicoNotificacaoSuporte}.php` | PORTAR |
| `Services/Integracoes/IA/ServicoCreditosIA.php` | PORTAR (fase 5) |
| `Models/{Plano,PlanoVersao,PlanoVersaoLimite,PlanoVersaoModulo,TicketSuporte,MensagemSuporte}.php` | PORTAR (com `PertenceEmpresa`/RLS onde aplicável) |
| `Mail/PedidoSuporteMail.php`, `resources/views/emails/suporte-pedido.blade.php` | PORTAR |
| `routes/api/plataforma.php` | ADAPTAR (permissões, ids opacos) |
| `Api/Sistema/CabecalhoEstadoController.php` | ADAPTAR (reapontar o indicador AGT, §7.5) |

### A.3 Migrações (todas ADAPTAR, com guardas)
`…_120000_painel_plataforma.php`, `…_130000_foto_perfil_utilizador.php`, `…_140000_tabela_planos.php`, `…_150000_categoria_tickets_suporte.php`, `…_170000_mensagens_suporte.php`, `…_180000_ia_creditos.php`, `…_180100_utilizacoes_assistente_ia.php` (já tem guarda), `2026_10_15_100000_planos_versoes.php`. Os nomes têm de passar ao padrão do actual (`2026_10_17_<hhmmss>_…`) e a data posterior a todas as existentes.

### A.4 BI (conflito)
`Api/Gestao/BIController.php`, `Services/Gestao/Paineis/Cubo/ServicoBI.php`, `frontend/src/modulos/geral/AccountingBI.tsx` → **NÃO** (§7.1).

### A.5 Frontend
| Ficheiro v5 | Acção |
|---|---|
| `src/plataforma/*` (16 ficheiros: `PlataformaApp`, `Painel`, `Empresas`, `GerirCliente`, `Planos`, `PlanoVersoesDrawer`, `Suporte`, `Sessoes`, `Operacao`, `Crescimento`, `Relatorios`, `Auditoria`, `Codigos`, `Administradores`, `Definicoes`, `EmPreparacao`) | PORTAR (tema nocturno, `CartaoKpi`, `ModalFormulario`, regras do actual) |
| `src/modulos/config/minhaConta/*` (7) | PORTAR (fase 5) |
| `src/modulos/config/IaTokens.tsx` | PORTAR (decisão §7.4) |
| `src/componentes/suporte/ConversaSuporte.tsx` | PORTAR |
| `src/layout/{AvatarUtilizador,IndicadoresCabecalho}.tsx` | ADAPTAR sobre o layout actual |

### A.6 Testes e E2E
`tests/Feature/Tenancy/*` (15 ficheiros) e `Autenticacao/FotoPerfilTest.php` → ADAPTAR ao `TestCase` actual e às bases de teste por agente. `frontend/e2e/{diag-planos.cjs,diag-planos.png,planos-versoes.cjs,plataforma-fluxos.cjs}` → NÃO importar tal e qual: são scripts de diagnóstico baseados no navegador; o actual proíbe Playwright em ronda e tem E2E próprios.

### A.7 Documentação
`docs/arquitetura/SAAS.md`, `SAAS_ARQUITETURA.md`, `docs/SAAS_REGISTO_DE_ALTERACOES.md` → PORTAR para `docs/arquitetura/`, com os ADRs renumerados (§5.9) e removendo dados e caminhos locais da máquina da v5.

## Anexo B — Pontos a confirmar na implementação

1. O `ResolverTenantAtivo` da v5 corre depois do `auth:sanctum` das rotas? A v5 diz que resolve o token por conta própria (`$request->user('sanctum')`). Confirmar com os testes.
2. Custo do `app_tenant_da_empresa` em cada `definir()`.
3. Comportamento de `Queue::before/after` com filas síncronas dentro de um pedido.
4. Compatibilidade do `ClienteAgtPorEmpresa` (actual) com a ideia de ciclo AGT por tenant.
5. Impacto da RLS na ordenação portuguesa (`OrdenacaoTexto`) e em consultas com `lockForUpdate`.

### 2026-10-17 — Fase 1 implementada (só código + base de teste)

- Criados: migração 2026_10_17_150000_saas_tenants_base (aditiva, com guardas, tenant_id com DEFAULT 1), ContextoTenant, DominioPedido, ResolverTenantAtivo, modelo Tenant, erros de tenant, config erp.saas e erp.dominio, hooks em Empresa/Utilizador, limpeza de tenant entre jobs.
- Flag SAAS_ATIVO=false por omissão: comportamento igual ao anterior. Sem RLS e sem role erp_app (fase 2).
- Verificado: suite completa 813 testes OK na base erp_agente_saas_testes. Migração NÃO aplicada na base de desenvolvimento.
- Pendente: ADR-073, aplicar na cópia/base real com autorização, fases 2 a 5.

### 2026-10-17 — Fase 2 implementada

- Migração fase 1 aplicada na base de desenvolvimento (backup backup_antes_saas.dump; contagens antes/depois iguais, só +tenants e +1 em migracoes; empresas 17 e utilizadores 4 no tenant 1).
- Migração 2026_10_17_160000_saas_rls_base aplicada: 191 tabelas com política isolamento_tenant, role erp_app (sem superuser, sem bypassrls). Inerte enquanto a aplicação ligar como superuser.
- Criados: IsolamentoRls, comando erp:saas:aplicar-rls, ligação pgsql_owner, SAAS_LIGACAO_DONO, plumbing no docker-compose (omissões iguais às anteriores), ADR-073, SAAS_ATIVACAO.md, IsolamentoRlsContratoTest.
- Verificado: 817 testes OK; prova de isolamento com SET ROLE erp_app (sem tenant = 0 linhas; escrita cruzada recusada; auditoria imutável).
- NÃO feito: mudar a aplicação para erp_app (depende da fase 3).

### 2026-10-17 — Licenças por empresa e fase 3

- Licença por empresa (ADR-074): colunas licenca_origem/inicio/fim; 17 empresas actuais SEM_LIMITE. Expirada = só leitura (GET/HEAD/OPTIONS), sem tolerância, só nessa empresa; super-administradores isentos; empresa nova = 7 dias de teste. Flag LICENCAS_ATIVAS=false por omissão.
- Fase 3: funções SQL app_empresa_por_webhook_token e app_empresa_por_token_bi; webhook AGT e token BI resolvem a empresa sem sessão; ciclo AGT e contratos agendados por tenant (porTenant); comandos de manutenção na ligação do dono.
- Verificado: 828 testes OK. Migrações 170000 e 180000 aplicadas na base de desenvolvimento. App continua como superuser, SAAS_ATIVO=false.
- Pendente: facturação por plano (cobrança), ecrã de licença, aviso no frontend, fase 4 (plataforma).
