# Continuar o trabalho — guia para uma conta nova do Claude

Este ficheiro permite a uma sessão nova do Claude Code (conta nova, sem memória) retomar o ERP_CONSULVOLT sem reconstruir o contexto. Ler tudo antes de agir. **Não contém segredos**: as credenciais ficam no perfil AWS `erp-cli`, no `.env` e nos segredos do GitHub, nunca neste ficheiro.

Última actualização: 2026-11-07 (F3–F6 publicadas; ver secção 8).

## 1. Como arrancar a sessão nova

1. Abrir o Claude Code na pasta `C:\xampp\htdocs\ERP_CONSULVOLT_MEU\erp_laravel` (ramo `paridade-bd-front`).
2. Primeira mensagem sugerida: «Lê `docs/CONTINUAR_TRABALHO.md` e continua pelo ponto indicado na secção 5. Responde sempre em português (pré-acordo ortográfico).»
3. A memória da conta antiga **não** passa para a conta nova. As regras importantes estão copiadas na secção 3.

## 2. O projecto

- Backend Laravel 13 / PHP 8.5; frontend React 19 + antd 6 + react-router 8; PostgreSQL com RLS multi-tenant (papel `erp_app` para a aplicação, `pgsql_owner` para migrações).
- Código legado de referência (só leitura): `v_boss/` (não vai para o git). Cópia local da produção em `producao_copia/` (código paralelo, não é versão antiga).
- Remotos git: `origin` = hdsgeral-boop/erp_consulvolt, `teste` = hdsgeral-boop/erp_consulvolt-teste, `producao` = git@github.com:meu-consulvolt/meu-erp.git. Publicar com `git push <remoto> HEAD:main`. **Nunca force-push.**
- Ficheiros pessoais do utilizador que **nunca** se fazem `git add`: `Implementar.txt`, `Logotipo_3Cores.jpeg`, `segurança.txt`, `Mapa Remuneração 2026 - Agosto.xlsx`, `v_boss/`. Usar sempre `git add` com caminhos explícitos.

## 3. Regras permanentes (do utilizador)

- Responder em português (pré-acordo ortográfico).
- Nunca tocar no ODK (sistema separado na AWS). Não apagar nem rodar a conta/chaves `erp-cli`. Manter os dados da base de produção. Nunca expor segredos nem dados pessoais.
- Não introduzir as credenciais do utilizador para entrar em páginas; o utilizador entra por si.
- Autorização permanente do utilizador para actualizar **teste e produção** com cada entrega, com salvaguardas: snapshot RDS + ensaio das migrações numa cópia restaurada + nunca force-push.
- Entregar por ondas fechadas (testes verdes + commit + relatório). Não deixar tarefas a meio. Medir o esforço quando pedido.
- Catálogo de permissões (`catalogo.json`): **editado à mão**. Nunca regenerar a partir do `v_boss`; acrescentar permissões por edição directa.

## 4. Como testar (obrigatório antes de publicar)

- Backend: `docker exec erp_app_e2e php artisan test --filter=...`. O `php` do XAMPP é 8.0 e não serve. **Nunca dois testes em paralelo** (colidem na mesma base e dão falhas em cascata «current transaction is aborted»). A suite completa demora cerca de 19 minutos.
- Varredores que apanham regressões que os testes filtrados não vêem: `OwaspControloAcessoTest`, `AcessoCruzadoGeradoTest`, `ConsultasPorEndpointTest` (tectos de consultas), `EntradasMaliciosasListagensTest`, `ContaRootTest`, mais `FkPerfisModelosTest`, `CatalogoModulosPlanoTest`, `MenuConfigContaSubscricaoTest`, `IsolamentoRlsContratoTest`.
- Frontend (pasta `frontend`): `npx tsc --noEmit`, `npx vitest run --maxWorkers=3`, `npm run build`. O ambiente local `localhost:8081/erp_web_e2e` serve de `frontend/dist`: depois de testes massivos correr sempre `npm run build`.
- Estilo: `vendor/bin/pint --test` (ficheiros com fim de linha LF).
- Índice de pesquisa: `node ferramentas/pesquisa/gerar_indice_pesquisa.mjs` depois de mexer em ecrãs.
- Nunca confiar no código de saída de um comando com `| tail`; ler o conteúdo real do resultado.
- Armadilhas conhecidas: `JsonResource::collection()` exige construtores `mixed`; no antd 6 o `onFinish` perde os subcampos de `Form.List` sem `Form.Item` (ler com `form.getFieldValue`); testes que dependem da hora usam `travelTo`; `semAutenticacaoNoNavegador.test.ts` tem uma lista de ficheiros autorizados a usar `localStorage.setItem`.

## 5. Estado do trabalho e o que falta

### Já em produção e no ambiente de teste (versão `teste-20261106-2310`)
Paridade do esquema, conta root, sessões/MFA, caixa multimoeda, Projectos e Financeiro, assiduidade, suporte, exportação IRT (AGT), POS (Ondas 1–3), auditoria de login/registo, camada de mensagens de erro, Manual de Procedimentos Onda 1, **Gestão Imobiliária Onda 1** (cadastro, modelos, taxas), **Planeamento Estratégico Onda 1** (plano, objectivos, indicadores e metas).

### Gestão Imobiliária — implementado localmente, commits ainda por publicar (confirmar com `git log` e `git status`)
Planos e relatórios por fase em `docs/paridade/IMOBILIARIO_*.md`.

| Fase | Conteúdo | Commit local | Migração |
|---|---|---|---|
| F3 | Contratos, rendas, facturação, caução, subarrendamento | `a414e1b` | `2026_12_17_090000_imobiliario_onda2_contratos_rendas` |
| F4 | Parqueamento, áreas comuns, reservas | `ac34800` | `2026_12_18_090000_imobiliario_onda3_parqueamento_reservas` |
| F5 | Obras e apetrechamento | `3f3f784` | `2026_12_19_090000_imobiliario_onda4_obras_apetrechamento` |
| F6 | Compra e venda | `56816ba` | `2026_12_20_090000_imobiliario_onda5_compra_venda` |

**Se estes commits ainda não estão nos 3 remotos, a primeira tarefa é publicá-los** (secção 6). Ver o relatório da fase para o que ficou fora de âmbito.

### Ainda por fazer — por esta ordem
1. **Gestão Imobiliária F7** (Rentabilidade e Fiscal): rentabilidade previsto × executado, rendimento; detenção, retenções, selo, Modelo 1, resumo anual, entregas, calendário; integração no Relatório e Contas. Ver `IMOBILIARIO_PLANO.md` §7.
2. **Gestão Imobiliária F8** (Transversais): alertas agendados (`erp:imobiliario:alertas`), painel/KPIs, exportações Excel, PDFs dos modelos, emissão dos modelos sobre contrato real, vista em linhas, Fluxo.
3. **Gestão Imobiliária F9** (ETL e fecho): ETL só se existirem dados reais exportáveis do legado (as tabelas `imo_*` do legado não têm exportador nem backup), testes ponta a ponta, desempenho, varrimento OWASP, documentação (incluir as tabelas no `DICIONARIO_DADOS.md`).
4. **Planeamento Estratégico F2b–F9**: painel e medição agendada, origens de indicador `KPI_GESTAO`/`CONTA`/`ORCAMENTO`, mapa e iniciativas, desdobramento, revisões, diagnóstico, Documento do Plano/PDF, integrações e notificações. Plano em `docs/paridade/ESTRATEGIA_PLANO.md`; estado em `ESTRATEGIA_ONDA1_RELATORIO.md`.
5. **Manual de Procedimentos Ondas 2–3**, Manual da Organização, Documento do Plano. Plano em `docs/paridade/PROCEDIMENTOS_PLANO.md`.
6. **POS** F8 (UX, zoom, atalhos) e F9 (testes de paridade ponta a ponta). Recuperação do backup da lavandaria (`erp:lavandaria:completar-migracao` precisa do backup Dexie legado; evitar editar peças com várias opções até lá).
7. Pendências menores: ecrã de activação do MFA para `admin`; cerca de 57 alertas «Não foi possível carregar X» sem descrição; 2 empresas de teste na base local e2e.

### Decisões em aberto (perguntar ao utilizador quando relevar)
O plano do Imobiliário (§8) tem 12 perguntas P1–P12; foram usadas as recomendações como decisões. Pendentes de validação por contabilista/jurista: taxas fiscais (IP, selo, IVA, detenção), isenção M14, contas contabilísticas da caução e da mais-valia, tratamento do IP retido. O módulo Imobiliário e o Estratégia ainda não estão em todos os planos de licença (o Estratégia foi acrescentado aos planos com Orçamento ou RH). Cada empresa tem de configurar, em Modelos e Taxas › «Facturação e contas», o artigo «Renda», o artigo das reservas e as contas da caução/imposto retido.

## 6. Como publicar (resumo; ensaio sempre antes)

### 6.1 Ensaio das migrações (obrigatório quando há migrações novas)
1. Criar snapshot RDS de `meuerp-db` (região `eu-north-1`, perfil AWS `erp-cli`): `aws rds create-db-snapshot ...` e esperar `available`.
2. Restaurar para uma instância temporária `meuerp-ensaio-tmp` (`db.t4g.micro`, sub-rede `erp-db-subnets`, grupo de segurança `sg-084bb9425626df8c0`, não pública).
3. Criar um tarball com os ficheiros alterados de `backend/app`, `backend/resources`, `backend/config` e as migrações novas, enviar para `s3://meuerp-transferencia-962367498111/ensaio/`.
4. Pelo SSM, na EC2 de produção (`i-021a07e6f24932c8a`), descarregar o tarball, copiar para o contentor `erp_app` e correr um script PHP que: sobrepõe o autoload ao tarball, **muda o host da base para a cópia e aborta se o host contiver `meuerp-db.`**, corre `migrate --database=pgsql_owner --path=/tmp/ov/backend/database/migrations --realpath`, compara contagens de tabelas antes/depois, confirma RLS e GRANT a `erp_app` nas tabelas novas e corre uma 2.ª vez (tem de dar «Nothing to migrate»).
5. Apagar a cópia (`aws rds delete-db-instance --db-instance-identifier meuerp-ensaio-tmp --skip-final-snapshot --delete-automated-backups`) e o objecto do S3.

### 6.2 Produção
`git push origin HEAD:paridade-bd-front`, `git push teste HEAD:main`, `git push producao HEAD:main`. O CI e o workflow «Deploy AWS» no repositório `meu-consulvolt/meu-erp` fazem o resto (`deploy/aws/atualizar.sh`: composer, migrações, RLS, saúde, reversão só do código). Depois verificar: `https://meuerp.ao/api/saude` e `https://consulvolt.meuerp.ao/api/saude` com 200, 0 migrações pendentes, contagem de erros do log estável (122), e correr `php artisan erp:perfis:actualizar-modelos` no contentor `erp_app` para propagar perfis novos. Garantir que o scheduler corre (comandos agendados `erp:imobiliario:caducar-reservas`, `erp:imobiliario:obras`).

### 6.3 Ambiente de teste (EC2 `i-00eb77058b5a5296e`)
Sem git nem AWS CLI no servidor. Construir localmente as imagens com `docker build -f docker/php/Dockerfile.prod --target app|web|copias`, `docker save`, enviar para `s3://meuerp-transferencia-962367498111/teste-deploy/` (também `git archive` do código), gerar URLs pré-assinadas e lançar pelo SSM o script `ferramentas/operacao/actualizar-teste.sh <versão> <url_codigo> <url_imagens>`. Verificar o log em `/var/log/actualizar-<versão>.log` e `docker ps`.

## 7. Notas de custo
Cada onda de implementação gasta cerca de 0,6 a 0,7 milhões de tokens num subagente. Trabalhar por ondas fechadas, um agente por tarefa, e correr a suite completa (19 minutos) apenas antes de publicar.

## 8. Ponto exacto onde o trabalho ficou (2026-11-07, actualizado após o deploy)

**Publicado** nos 3 remotos, na produção e no ambiente de teste: commit `5248052` (inclui Imobiliário F3–F6, correcção do registo e login único). Teste em `teste-20261107-1312`. Verificado: saúde 200 em `meuerp.ao` e `consulvolt.meuerp.ao`, 0 migrações pendentes, perfis actualizados, contagem de erros do log estável (122). Suites antes de publicar: backend 2022 passados; vitest 1954 passados (1 teste da lavandaria `TabelasOpcoes` falhou por tempo esgotado sob carga e passa isolado); tsc limpo. Ensaio das 4 migrações (`2026_12_17` a `2026_12_20`) em cópia restaurada: sem problemas.

**Login:** único em `meuerp.ao` por omissão; a morada própria `<slug>.meuerp.ao` continua activa e funcional como opção (registo noutra morada devolve `endereco_entrada` = login único e `endereco_empresa` = morada própria).

**Próximo passo:** seguir a lista da secção 5 pela ordem: Imobiliário F7 (Rentabilidade e Fiscal), F8 (Transversais), F9 (ETL e fecho); depois Estratégia F2b–F9; Procedimentos Ondas 2–3; POS F8/F9. Cada onda: subagente, testes, commit, relatório; publicar com o ensaio da secção 6.

Notas das ondas F3–F6: cada empresa tem de configurar em Modelos e Taxas › «Facturação e contas» o artigo «Renda», o artigo das reservas, o artigo da venda de imóveis (IVA 0 %), a contrapartida da compra e as contas de caução, mais-valia e menos-valia. As taxas fiscais e o IP retido estão «por confirmar» pelo contabilista. Os perfis Gestor Imobiliário e Técnico de Rendas mudaram (segregação). Comandos agendados novos: `erp:imobiliario:caducar-reservas` (15 em 15 min) e `erp:imobiliario:obras` (06:30); confirmar que o scheduler corre em produção.

## 9. Prompt para colar na conta nova do Claude

Copiar o bloco seguinte para a primeira mensagem da sessão nova:

```
Estás a continuar um projecto existente: ERP_CONSULVOLT (Laravel 13 + React 19, PostgreSQL com RLS, AWS), na pasta C:\xampp\htdocs\ERP_CONSULVOLT_MEU\erp_laravel, ramo paridade-bd-front. Responde SEMPRE em português (pré-acordo ortográfico).

1. Lê por inteiro docs/CONTINUAR_TRABALHO.md. É a fonte de verdade: regras permanentes (secção 3), como testar (4), estado e lista do que falta (5 e 8), como publicar (6).
2. Corre `git status` e `git log --oneline -12` para ver o que já está commitado e o que está publicado (o último commit publicado nos remotos é 5248052).
3. Retoma pelo ponto da secção 8 e pela lista da secção 5. Não recomeces trabalho já feito: confirma pelo git e pelos relatórios em docs/paridade/.
4. Restrições que nunca se quebram: não tocar no ODK; não apagar nem rodar a conta/chaves erp-cli; manter os dados da produção; nunca force-push; nunca expor segredos ou dados pessoais; não fazer git add dos ficheiros pessoais (Implementar.txt, Logotipo_3Cores.jpeg, segurança.txt, Mapa Remuneração*.xlsx, v_boss/); nunca regenerar catalogo.json (editar à mão); nunca correr dois PHPUnit em paralelo; não introduzir credenciais do utilizador para fazer login.
5. Trabalha por ondas fechadas (testes verdes + commit + relatório em docs/paridade/). Um subagente por tarefa; usa-os para implementações grandes e mantém o contexto principal pequeno. Antes de publicar: suite completa do backend, vitest completo, tsc, pint, npm run build, snapshot RDS + ensaio das migrações numa cópia restaurada. Depois publica nos 3 remotos e nos 2 ambientes AWS (autorização permanente do utilizador, com estas salvaguardas).
6. No fim de cada entrega mostra ao utilizador o que ficou feito e o que falta. Se os tokens estiverem a acabar, pára numa fronteira limpa e actualiza a secção 8 deste ficheiro com o ponto exacto onde ficaste.

Começa por confirmar que leste o ficheiro, resume-me em 5 linhas o estado e continua pela lista da secção 5 (próximo: Imobiliário F7).
```

### Acesso e credenciais (sem segredos)
A conta nova do Claude precisa de: acesso à pasta do projecto no PC; perfil AWS `erp-cli` já configurado na máquina (`aws sts get-caller-identity` com `AWS_PROFILE=erp-cli`); autenticação `gh`/git para os remotos (chave SSH para `meu-consulvolt/meu-erp`); Docker Desktop com os contentores `erp_app_e2e` e `erp_postgres` a correr. Nenhuma credencial vai para este ficheiro nem para o git.
