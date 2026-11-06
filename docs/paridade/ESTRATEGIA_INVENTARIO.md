# Planeamento Estratégico (e Documento do Plano) — inventário do legado e interligações

Data: 2026-11-06, ramo `paridade-bd-front`. Só análise: nenhum código do projecto nem do legado foi alterado. Complemento: `ESTRATEGIA_PLANO.md` (arquitectura alvo, fases, perguntas). Processo igual ao do POS (`POS_LEGADO_INVENTARIO.md` → matriz → plano → ondas).

Convenção de referências: `ficheiro:linha` relativo a `v_boss/New/v2/js/` salvo indicação. Siglas dos ficheiros: **DAD** = `modules/estrategia/estrategia_dados.js` (1536 linhas), **UI** = `modules/estrategia/estrategia_ui.js` (2107), **DOC** = `modules/estrategia/estrategia_documento.js` (302), **DUI** = `modules/estrategia/estrategia_documento_ui.js` (191), **CORP** = `shared/documento_corporativo.js` (269), **PERM** = `permissoes.js`, **DB** = `db_v2.js`.

## 0. O que foi lido e o que não foi

**Lido por inteiro, linha a linha:** DAD, UI, DOC, DUI, CORP (os cinco ficheiros nucleares, ~290 KB).
**Lido nos pontos de contacto (excertos com o contexto à volta):** `core/modulos_registo.js:20`; `permissoes.js:30-42,100-145`; `app_v2.js:1090-1106`; `db_v2.js:1177-1221`; `ui_dashboard.js:640-660,755-775`; `relatorio_contas.js:79,375,575-625`; `modules/estrutura/estrutura_manual.js:225-262,500-530` e a lista das suas chamadas a `Estrategia`; `modules/rh/portal_ui.js:933-975`; `modules/rh/avaliacao.js:405-435`; `modules/procedimentos/procedimentos_manual.js:120-135`; `fluxo_narrativa.js` (5 ocorrências de `estrategia_plano`); `index_arrumado.html:390-403,731-758`; `portal.html:181`.
**Lido só em cabeçalho/lista de funções (para delimitar fronteiras):** `modules/orcamento/orcamento_planeamento.js` (634 linhas; 80 de cabeçalho + índice de funções) e `projectos_metas.js` (40 linhas + helpers). Confirmou-se por `grep` que **nenhum dos dois referencia a estratégia**: `orcamento_planeamento*.js` é o planeamento *orçamental* (previsões deslizantes, cenários, excesso, top-down/bottom-up) e já está portado (ADR-046); a estratégia só **lê** do orçamento através de `window.Orcamento` (DAD:30,448-483,790-805,1496-1503).
**Não lido (e porquê):** `modules/orcamento/orcamento_planeamento_ui.js` (639 linhas, UI do planeamento orçamental; sem ligação à estratégia por `grep`); `print_docs.js`, `projectos_hierarquia.js`, `projectos_organigrama.js`, `projectos_orcamento_qtd.js`, `fluxo_*.js` (excepto narrativa), `css/` (sem ocorrências de `estrateg*`/`OKR`/`BSC`/`SWOT` por `grep -rIl`); `docs/` do legado (só `CENARIOS_RH.md`, `PORTAL_COLABORADOR.md` e `PROVA_FINAL_RH.md` têm a palavra «objectivo», todas sobre a avaliação de desempenho, não sobre o plano); `Regras/*.pdf` (6 PDF contabilísticos: apuramento de resultados, Relatório e Contas 2024 HDS — **não** contêm regras de estratégia). Não existe documento de especificação do planeamento estratégico no legado: **o código é a única fonte** (o cabeçalho de DAD:1-17 regista as decisões «acordadas com o utilizador em 2026-11-05»).
**Não confirmado:** (a) se a cópia de produção do legado (`producao_copia/`) e o backup usado pelo ETL já contêm as 11 tabelas `est_*` (Dexie ≥ 1285, criadas em 2026-11-05; o backup de migração conhecido é Dexie 1277 — ADR-071); (b) o texto das ajudas que vêm de `window.Dialogo` (componente do legado não lido); (c) o conteúdo exacto de `window.Orcamento` e `window.RelatoriosGestao` (só a interface usada pela estratégia foi lida).

---

## 1. Visão geral

O «Planeamento Estratégico» do legado é um módulo **novo e recente** (5-6 de Novembro de 2026), escrito em 5 fases, sobre **11 coleções Dexie** (`est_*`), com `window.Estrategia` (dados e regras, IIFE congelada, DAD:1505-1535) e uma camada de ecrãs (`renderEst*`, UI). Não usa servidor: tudo corre no navegador e **nenhuma regra é aplicada no servidor** (permissões, estados e validações são só do cliente).

Desenho acordado (DAD:4-8):
- **Balanced Scorecard** para o ciclo longo (plano de N anos, quatro perspectivas por omissão) **+ OKR trimestrais por cima**: um OKR é um objectivo com `horizonte = 'TRIMESTRE'` e `pai_id` para o objectivo estratégico que serve. «Um só modelo, duas cadências.»
- **Plano por empresa** (`est_company_id`); `grupo_id` fica aberto para plano de grupo (holding) mas **nunca é usado** por nenhuma regra ou ecrã.
- **Regra-mãe (DAD:10-15):** um indicador tem de dizer **de onde vem o valor** — cinco origens, quatro automáticas. A medição fica **gravada** (`est_measures`); não se recalcula ao desenhar os mapas, para o histórico não mudar quando o Diário mudar.

**As cinco fases** (comentários em DB:1177-1221): F1 fundação e indicadores (plano, perspectivas, objectivos, indicadores, metas, medições, painel); F2 mapa estratégico + iniciativas; F3 desdobramento pelas unidades (catchball) + pontes para pessoas (avaliação, portal); F4 revisões de acompanhamento; F5 diagnóstico (SWOT/PESTEL/partes interessadas/TOWS). A seguir foi acrescentado o **Documento do Plano** (DOC/DUI, com `DocumentoCorporativo`).

**Registo do módulo:** `core/modulos_registo.js:20` → `{ id:'estrategia', nome:'Planeamento Estratégico', rotaBase:'estrategia', pacotes:[], ecras:{ est_plano:'plano', est_diagnostico:'diagnostico', est_mapa_estrategico:'mapa', est_documento:'documento', est_indicadores:'indicadores', est_iniciativas:'iniciativas', est_cascata:'desdobramento', est_revisoes:'revisoes', est_painel:'painel' } }`. Dispatch dos ecrãs em `app_v2.js:1096-1104`. **Menu** (`index_arrumado.html:390-403`), grupo «Planeamento Estratégico» (ícone `fa-compass`), pela ordem: Painel Estratégico · Diagnóstico · Mapa Estratégico · Documento do Plano · Plano e Objectivos · Indicadores e Metas · Iniciativas · Desdobramento · Revisões. As permissões estão no módulo **«Geral»** do catálogo (PERM:37-39,106-135), não num módulo próprio.

**Nota sobre o prefixo `est_`:** o legado usa `est_` para *Estrutura Orgânica* (`est_estrutura`, `est_organigrama`, `est_mapa`, `est_manual`) **e** para os ecrãs da estratégia (`est_plano`…). O legado já tropeçou: `renderEstMapa` é o Mapa de Pessoal e o mapa estratégico teve de se chamar `renderEstMapaEstrategico` (UI:171). O sistema novo herdou `est_estrutura`, `est_organigrama`, `est_mapa` (`frontend/src/modulos/estrutura/ecras.ts`) e as tarefas `est_validar_entregas`, `est_multas_gerir`, `est_delegar`, `est_ver_salarios`. Os ids da estratégia **não colidem** com nenhum id existente (verificado).

**Estado no sistema novo:** inexistente. `grep -ril estrateg` em `backend/app`, `routes`, `resources`, `database/migrations`, `tests`, `frontend/src` e `docs` não devolve nenhum ficheiro. O catálogo `catalogo.json` (extraído de um `permissoes.js` anterior) só tem 4 ecrãs no módulo «Geral» e **nenhuma** das 9 vistas `est_*` da estratégia (tal como também não tem `imo_*`).

---

## 2. Estrutura do plano (modelo conceptual)

```
Plano (ciclo, ex. 2027-2031; missão, visão, valores; estado RASCUNHO|APROVADO|ENCERRADO; doc{…} = Documento do Plano)
 ├─ Perspectivas (4 BSC por omissão: Financeira, Clientes, Processos Internos, Aprendizagem e Crescimento; ordem, cor, ícone)
 │    └─ Objectivos (horizonte PLANO | TRIMESTRE=OKR; pai_id = posse; unit_id → unidade orgânica; responsavel (texto); estado; mapa_x)
 │         ├─ Indicadores (origem+fonte; polaridade; tipo; formato; frequência; linha de base; tolerâncias; dono; agregação)
 │         │    ├─ Metas (por período; `unit_id` nulo = meta da empresa)
 │         │    ├─ Contributos das unidades (as MESMAS `est_targets` com `unit_id`: PEDIDO→PROPOSTO→ACEITE|DEVOLVIDO; histórico)
 │         │    └─ Medições (por período e, opcionalmente, unidade; gravadas com origem, quem e quando)
 │         └─ Iniciativas (tipo PROJECTO|ENTREGAVEL|RUBRICA|LIVRE; aponta para a fonte; nunca guarda execução nem custo)
 ├─ Ligações causa-efeito (de_id → para_id entre objectivos; sem ciclos; ≠ pai_id)
 ├─ Revisões de acompanhamento (por período) → linhas (fotografia meta/valor/semáforo + causa + acção correctiva com dono e prazo)
 └─ Diagnóstico (SWOT, PESTEL, Partes interessadas, TOWS = linhas de contexto; objective_ids liga achado→objectivo)
```
Orçamento associado: **não existe como entidade da estratégia**; é lido (origem ORCAMENTO e iniciativa RUBRICA) e a iniciativa guarda um `orcamento` previsto só quando a fonte não o tem (UI:1112, DAD:742,769). **Riscos** como entidade: **não existem** (o diagnóstico TOWS/«ameaças» é a única aproximação). **EVM/valor ganho:** não existe (nem como cálculo; só `custo`/`orcado` por iniciativa PROJECTO, DAD:760-771). **Cascata para projectos:** só pela iniciativa tipo PROJECTO (leitura de execução, custo e orçamento do projecto). **Cascata para unidades:** objectivo.unit_id (responsável) + contributos por indicador/período/unidade (catchball).

---

## 3. Enumerações e vocabulário (DAD:32-99, 716-723, 868-877, 1100-1101, 1310-1348)

| Conjunto | Código → rótulo exacto |
|---|---|
| Estado do plano | `RASCUNHO` «Em elaboração» · `APROVADO` «Aprovado» · `ENCERRADO` «Encerrado» |
| Horizonte do objectivo | `PLANO` «Objectivo do plano» · `TRIMESTRE` «OKR do trimestre» |
| Estado do objectivo | `ACTIVO` «Activo» · `SUSPENSO` «Suspenso» · `CONCLUIDO` «Concluído» · `ABANDONADO` «Abandonado» |
| Polaridade | `SOBE` «Quanto maior, melhor» · `DESCE` «Quanto menor, melhor» · `ALVO` «Quanto mais perto da meta, melhor» |
| Tipo de indicador | `RESULTADO` «Resultado (lagging)» · `INDUTOR` «Indutor (leading)» |
| Frequência | `MENSAL` «Mensal» · `TRIMESTRAL` «Trimestral» · `SEMESTRAL` «Semestral» · `ANUAL` «Anual» |
| Formato | `kz` «Kwanzas» · `num` «Número» · `pct` «Percentagem» · `dias` «Dias» · `horas` «Horas» · `meses` «Meses» · `nota` «Nota (0-20)» |
| Origem do valor | `KPI_GESTAO` «Indicador de gestão» (auto) · `CONTA` «Contas do plano» (auto) · `ORCAMENTO` «Orçamento» (auto) · `MODULO` «Dados de um módulo» (auto) · `MANUAL` «Recolha manual» (não auto) |
| Natureza de conta | `PROVEITO` «Proveitos (crédito − débito)» · `CUSTO` «Custos (débito − crédito)» · `SALDO` «Saldo acumulado até ao fim do período» · `MOVIMENTO` «Movimento do período (débito − crédito)» |
| Medida de orçamento | `REALIZADO` «Realizado» · `ORCADO` «Orçado» · `EXECUCAO_PCT` «Execução (%)» · `DESVIO` «Desvio (realizado − orçado)» |
| Operação (origem MODULO) | `CONTAGEM` «Contar registos» · `SOMA` «Somar um campo» · `MEDIA` «Média de um campo» |
| Operador de filtro | `IGUAL` «=» · `DIFERENTE` «≠» · `CONTEM` «contém» · `MAIOR` «>» · `MENOR` «<» · `PREENCHIDO` «preenchido» · `VAZIO` «vazio» |
| Agregação (desdobramento) | `SOMA` «Soma dos contributos» · `MEDIA` «Média dos contributos» · `NENHUMA` «Não se agrega» (omissão: `pct`/`nota` → MEDIA, resto SOMA, DAD:877) |
| Tipo de iniciativa | `PROJECTO` «Projecto» · `ENTREGAVEL` «Entregável de unidade» · `RUBRICA` «Rubrica orçamental» · `LIVRE` «Sem ligação» |
| Estado da iniciativa | `PREVISTA` «Prevista» · `EM_CURSO` «Em curso» · `CONCLUIDA` «Concluída» · `SUSPENSA` «Suspensa» · `CANCELADA` «Cancelada» |
| Estado do contributo | `PEDIDO` «Contributo pedido» · `PROPOSTO` «Proposto pela unidade» · `DEVOLVIDO` «Devolvido para rever» · `ACEITE` «Aceite» |
| Estado da revisão | `ABERTA` «Em curso» · `FECHADA` «Fechada» |
| Estado da acção correctiva | `SEM_ACCAO` «Sem acção» · `ABERTA` «Em curso» · `CONCLUIDA` «Concluída» · `CANCELADA` «Cancelada» |
| Semáforo | `VERDE` «Cumprida» · `AMARELO` «Em risco» · `VERMELHO` «Não cumprida» · `SEM_META` «Sem meta» · `SEM_MEDIDA` «Por medir» |
| Tipo de contexto | `SWOT` «Análise SWOT» · `PESTEL` «Análise PESTEL» · `PARTE` «Partes interessadas» · `TOWS` «Cruzamento estratégico (TOWS)» (nome curto: SWOT, PESTEL, «partes interessadas», «cruzamento TOWS») |
| Categorias SWOT | `FORCA` Força (Interna·positivo) · `FRAQUEZA` Fraqueza (Interna·negativo) · `OPORTUNIDADE` Oportunidade (Externa·positivo) · `AMEACA` Ameaça (Externa·negativo) |
| Categorias PESTEL | `POLITICO` Político · `ECONOMICO` Económico · `SOCIAL` Social · `TECNOLOGICO` Tecnológico · `AMBIENTAL` Ambiental · `LEGAL` Legal |
| Categorias PARTE | `CLIENTE` Clientes · `COLABORADOR` Colaboradores · `ACCIONISTA` Accionistas · `FORNECEDOR` Fornecedores · `ESTADO` Estado e reguladores · `BANCA` Banca e financiadores · `COMUNIDADE` Comunidade · `OUTRA` Outra |
| Categorias TOWS | `FO` Forças × Oportunidades «Ofensiva — usar o que temos para agarrar o que aí vem» · `FA` Forças × Ameaças «Defensiva — usar o que temos para aguentar o embate» · `DO` Fraquezas × Oportunidades «Reorientação — corrigir o que falta para não perder a ocasião» · `DA` Fraquezas × Ameaças «Sobrevivência — reduzir a exposição» |
| Tratamento da parte (Mendelow) | «Gerir de perto» (Envolver nas decisões e informar primeiro.) · «Manter satisfeita» (Pouco interesse mas muito poder: não a surpreender.) · «Manter informada» (Interessada mas sem poder: comunicar com regularidade.) · «Acompanhar» (Esforço mínimo, mas não a perder de vista.) |
| Chave de período | texto ordenável: `2027-03` (mês) · `2027-T1` · `2027-S1` · `2027` (DAD:102-140) |

**Fontes da origem MODULO (lista fechada, DAD:84-96)** — id · tabela legado · campo empresa · campo data · campo unidade · valores · filtros:
1 `sales` «Vendas (documentos)» · `sales` · company_id · date · — · total_gross «Total com IVA», total_net «Total sem IVA» · doc_type, status, customer_id
2 `purchases` «Compras (facturas)» · `purchase_invoices` · company_id · date · — · total_gross, total_net · status, supplier_id
3 `projects` «Projectos» · `projects` · company_id · start_date · — · progress «Execução (%)» · status
4 `proj_tasks` «Tarefas de projecto» · `project_tasks` · company_id · end_date · — · progress · status, project_id
5 `crm_opp` «Oportunidades (CRM)» · `crm_opportunities` · company_id · close_date · — · valor, probabilidade · estado, pipeline_id
6 `crm_act` «Actividades (CRM)» · `crm_activities` · company_id · data · — · (sem valores) · tipo, estado
7 `entregaveis` «Entregáveis das unidades» · `org_task_executions` · org_company_id · data · `unit_id` · quantidade · validacao, unit_id, employee_id
8 `colaboradores` «Colaboradores» · `employees` · company_id · hire_date · `org_unit_id` · base_salary «Salário base» · status, department
9 `ausencias` «Ausências» · `rh_absences` · company_id · data · — · dias · tipo, estado
10 `manutencao` «Ordens de manutenção» · `asset_work_orders` · company_id · data_prevista · — · custo_real · estado, tipo
11 `formacao` «Formação» · `rh_trainings` · company_id · data · — · horas · estado

(11 fontes; só **duas** — `entregaveis` e `colaboradores` — têm campo de unidade e por isso medem sozinhas por unidade, DAD:1002-1014.) **Atenção:** vários nomes (`sales.date`, `employees.hire_date`, `org_units`, `rh_trainings`, `asset_work_orders`) não coincidem com as tabelas/colunas do sistema novo (ver `ESTRATEGIA_PLANO.md` §2.4) e as colunas `crm_opportunities.pipeline_id`/`close_date` existem no novo com outros nomes (`funil_vendas_crm_id`, `data_fecho_prevista`); `rh_trainings` **não tem tabela equivalente** no esquema novo (`esquema.json` não tem tabela de formação).

---

## 4. Ecrãs, separadores, modais e botões (rótulos exactos)

Os 9 ecrãs partilham: **barra** `.est-barra` (selector «Plano» — `{nome} · {ano_inicio}-{ano_fim} · {estado}`), cartões, tabelas `.est-tab`, modais em `div` próprio (`modal(id,largura,html)`, fecham ao clicar fora, UI:250-259), estado de módulo `st = {planId, ano, periodo, filtro}` partilhado entre ecrãs (UI:42), mensagens por `Notificacoes.mostrar`/`alert`. **Sem plano:** todos os ecrãs mostram «Ainda não há nenhum plano estratégico nesta empresa.» + «Um plano é um ciclo (por exemplo 2027-2031) com a missão, a visão, as perspectivas e os objectivos.» + botão «Criar o primeiro plano» (UI:176-180).

### 4.1 Plano e Objectivos (`est_plano`, UI:185-242) — título «Plano Estratégico», sub «O ciclo, a missão, as perspectivas e os objectivos.»
- **Barra:** selector Plano; «Editar plano» (`fa-pen`); conforme estado: RASCUNHO → «Aprovar plano»; APROVADO → «Reabrir» + «Encerrar»; ENCERRADO → nada; (espaço) «Imprimir o plano» (tooltip «Documento com tudo o que está registado no plano»); «Novo plano»; «Perspectivas»; «Novo objectivo».
- **Três cartões** «Missão», «Visão», «Valores» (texto, «por preencher» se vazio).
- **Grelha por perspectiva** (cartão com cor da perspectiva, cabeçalho nome+descrição): objectivos raiz (sem pai na mesma perspectiva) com filhos indentados recursivamente; OKR com ícone `fa-bullseye`, borda tracejada, «OKR · {rótulo do trimestre} · {n} indicador(es) · {responsável} · {estado se ≠ Activo}»; aviso «sem indicador — não é medido»; botão «Objectivo» por perspectiva; vazio «Sem objectivos.» Clicar no cartão abre o editor. Nota rodapé: «Um OKR é um objectivo de trimestre pendurado num objectivo do plano — aparece a tracejado e com alvo. Os indicadores definem-se no ecrã «Indicadores e metas».»
- **Modal «Novo/Editar plano estratégico»** (620 px): ver §5.1.
- **Modal «Perspectivas»** (700 px): ver §5.2.
- **Modal «Novo/Editar objectivo»** (640 px): ver §5.3.
- Confirmações: «Quer aprovar/reabrir/encerrar este plano?» (título «Confirmar»); «Apagar esta perspectiva?»; «Apagar este objectivo?».

### 4.2 Indicadores e Metas (`est_indicadores`, UI:405-730) — «De onde vem o valor de cada indicador e qual é a meta de cada período.»
- **Barra:** Plano; «Procurar» (placeholder «indicador, objectivo ou dono», filtra por nome, dono, origem, nome do objectivo); «Novo indicador».
- **Alertas** (caixa amarela): «{n} indicador(es) sem origem — não serão medidos.» e «{n} indicador(es) manuais sem dono.»
- **Tabela** colunas: Indicador (nome + descrição) · Objectivo (pastilha da perspectiva + nome) · Tipo (1.ª palavra: «Resultado»/«Indutor») · Origem do valor (pastilha + `descreverFonte`: «módulo · chave», «contas 61, 62», «rubrica {id} · {medida}», «{fonte} · {operação}», «entrega até {n} dias após o período» / «entregue por quem o recolhe») · Frequência · Polaridade · Linha de base (formatada) · Dono («em falta» a vermelho se MANUAL sem dono) · (botões «Metas por período» `fa-flag`, «Editar»). Linha inactiva a 55% de opacidade. Há uma segunda linha de filtros (input «filtrar…»). Vazio: «Sem indicadores. Comece pelos indicadores de gestão — o sistema já calcula dezenas a partir do Diário e dos documentos.»
- **Modal «Novo/Editar indicador»** (760 px): ver §5.4. **Modal «Metas · {indicador}»** (720 px): ver §5.5.
- Mensagem se não há objectivos: «Crie primeiro um objectivo: um indicador serve sempre um objectivo.»

### 4.3 Painel Estratégico (`est_painel`, UI:735-841) — «Como está cada indicador no período, com meta, realizado e semáforo.»
- **Barra:** Plano; **Período** (todos os períodos mensais, trimestrais, semestrais e anuais do ciclo, ordenados); «Medir este período»; (espaço) «Imprimir»; «Excel».
- **5 mosaicos** (`est-tile`): «Cumpridas» (verde), «Em risco» (amarelo), «Não cumpridas» (vermelho), «Por medir» (azul), «Sem meta» (cinza).
- **Grupos por objectivo** (cabeçalho: pastilha da perspectiva + nome do objectivo + «(OKR)»), linhas por indicador: nome + `{Frequência} · {rótulo período} · {origem} · {dono}`; **Meta**; **Realizado** + seta de tendência (`fa-arrow-trend-up` verde se valor > anterior, `…-down` vermelha se <, `fa-minus` cinza se =; sem seta se faltar um); **Cumpr.** (% inteira); pastilha do semáforo; para indicadores MANUAL o botão `fa-pen-to-square` «Entregar o valor».
- Vazio: «Este plano ainda não tem indicadores activos.» Nota: ««Medir este período» calcula os indicadores automáticos e grava o resultado. A medição fica gravada com a data e o autor — voltar a medir substitui o valor, mas o histórico dos outros períodos não muda.»
- **Acções:** «Medir este período» (`estMedirAgora`, mostra tarefa «A medir os indicadores do período»; mensagem «{n} de {total} indicador(es) medidos em {período}. {m} de recolha manual ficam a aguardar entrega. {k} falharam: {nome (erro)}…»); «Entregar valor» (2 diálogos: valor; «Origem do valor» — «De onde vem este valor? (inquérito, auditoria, relatório…)»); «Imprimir» (A4 vertical, tabela Perspectiva·Objectivo·Indicador·Meta·Realizado·Cumprimento·Estado); «Excel» (`Painel_estrategico_{período}.xlsx`, folha «Painel», 10 colunas: Perspectiva, Objectivo, Indicador, Período, Meta, Realizado, Cumprimento %, Estado, Origem, Dono).

### 4.4 Mapa Estratégico (`est_mapa_estrategico`, UI:850-1038) — «Os objectivos por perspectiva e as relações de causa-efeito. Lê-se de baixo para cima.»
- **Barra:** Plano; Período; «Ligar objectivos» (alterna; activo: «A ligar — pique a causa e depois o efeito»); «Imprimir».
- **Tela** (`est-mapa-tela`): uma **faixa por perspectiva** (rótulo à esquerda com nome+descrição, paleta de cartões à direita, cor da perspectiva) e **SVG** de setas por cima. **Cartão** (190 px): nome (+ ícone OKR), «{n} indicador(es) · {m} iniciativa(s)», borda esquerda com a cor do **pior** semáforo dos indicadores do objectivo; posição horizontal `mapa_x` (%; omissão `(índice × 22) % 78`); posição vertical calculada para não sobrepor.
- **Interacção:** arrastar (≥ 4 px) move o cartão e grava `mapa_x` (0-100); clique abre o editor do objectivo (ou, no modo ligar, 1.º clique = causa — avisa «Agora pique o objectivo que este ajuda a atingir.» — 2.º clique = efeito; clicar no mesmo cancela); clicar numa seta pede «Apagar esta relação de causa-efeito?». Setas: curva de Bézier entre as bordas, ponta triangular; erros do ciclo mostrados como aviso.
- **Legenda:** Cumprido / Em risco / Não cumprido / Sem meta / Por medir + «Arraste um cartão para o mover · pique numa seta para a apagar».
- **Imprimir** (A4 horizontal): «Mapa estratégico · {período}» com lista por perspectiva («{objectivo} — {estado em minúsculas} · {n} indicador(es) · {m} iniciativa(s)») e «Relações de causa-efeito» (`A → B`; «ainda não há ligações desenhadas»).

### 4.5 Iniciativas (`est_iniciativas`, UI:1045-1160) — «O que se vai fazer para mover cada objectivo. A execução e o custo vêm do projecto, do entregável ou da rubrica ligados.»
- **Barra:** Plano; «Nova iniciativa». **Tabela:** Iniciativa (nome+descrição) · Objectivo · Ligada a (pastilha do tipo + `fonte` + erro a vermelho) · Responsável · Período (início/fim em duas linhas) · Estado · **Execução** (barra: ≥100 verde, ≥60 azul, ≥30 âmbar, senão vermelho; «—» se nula) · Custo / Orçado (`custo` e «sem orçamento» ou orçado, em Kz) · (editar). Vazio: «Sem iniciativas. Uma iniciativa é o projecto, o entregável ou a verba que vai mover o objectivo.»
- **Modal «Nova/Editar iniciativa»** (700 px): §5.6. Confirmação de apagar: «Apagar esta iniciativa? O projecto ou entregável ligado não é afectado.»
- Se não há objectivos: «Crie primeiro um objectivo: uma iniciativa serve sempre um objectivo.»

### 4.6 Desdobramento (`est_cascata`, UI:1167-1349) — «A unidade propõe o que consegue, quem gere aceita ou devolve com um motivo. Uma meta imposta não é um compromisso.»
- **Barra:** Plano; Período; indicação «{unidade a que pertenço} · chefia {n} unidade(s)».
- **Um bloco por indicador activo** que tenha meta da empresa ou contributos **no período equivalente à sua frequência** (DAD:602-610): cabeçalho «{indicador} · {objectivo} · {período}»; **faixa de cobertura**: «Meta da empresa: {x}» · «{Soma|Média|Não se agrega}…: {assumido}» · aviso «falta {x} para a meta da empresa» (vermelho) / «a meta da empresa está coberta» (verde) / «sem meta da empresa para comparar»; fichas «{n} por propor» / «{n} por decidir»; botão «Pedir contributos».
- **Linhas** (colunas: Unidade · Estado · Meta assumida · Realizado · acções): unidade (+ motivo + «{n} movimento(s) · último por {quem}»), pastilha do estado, valor (aceite ou proposto), realizado. **Botões por linha:** «Propor» (só quem pode propor e se não ACEITE); «Aceitar» ✓ e «Devolver para rever» (se PROPOSTO); «Medir ou entregar o realizado da unidade» e «Reabrir a negociação» (se ACEITE); «Retirar a unidade» (sempre). Vazio: «Nenhuma unidade assumiu este indicador. Use «Pedir contributos».»
- **Modal «Pedir contributos»** (520 px): lista de unidades activas com caixa de selecção; as já pedidas ficam desactivadas «(já pedido)»; «(sem responsável definido)» a âmbar; botão «Pedir». Sem unidades: «Não há unidades orgânicas nesta empresa. Crie-as em Estrutura Orgânica.»
- **Diálogos:** «Propor contributo» («Que valor a unidade se compromete a atingir em «{indicador}»?») + «Fundamentação» («Em que assenta esta proposta? (opcional)»); «Aceitar contributo» («Aceitar o valor proposto pela unidade como meta assumida?»); «Devolver para rever» («Porque devolve? A unidade precisa de saber o que corrigir.», motivo obrigatório); «Reabrir» («Porque reabre a negociação deste contributo?»); «Retirar» («Retirar esta unidade do desdobramento deste indicador?»); medir unidade («{erro}\n\nValor da unidade em {período}»).
- Nota rodapé: «O realizado da unidade só se calcula sozinho quando a origem do indicador sabe de unidades (entregáveis, colaboradores). Nos indicadores de contas e de gestão, a contabilidade está organizada por centro de custo e não por unidade orgânica, por isso o valor da unidade é entregue à mão.»

### 4.7 Revisões (`est_revisoes`, UI:1358-1656) — duas vistas
**Lista** — «De período a período: olhar para cada desvio, escrever a causa e decidir o que se vai fazer.» Barra: Plano; «Abrir revisão de um período». Cartão **«Acções correctivas em curso · {n} · {m} com prazo ultrapassado»** (linhas: indicador — acção · responsável («sem responsável») · prazo («sem prazo»; «(vencida)» a vermelho) · período da revisão · botões «Marcar como concluída» ✓ e «Cancelar a acção» 🚫). Tabela de revisões: Período · Data · Estado («Em curso»/«Fechada») · Participantes · Desvios · Acções · (botões «Abrir»; lixo só se ABERTA). Vazio: «Sem revisões. Abra a primeira para o período que quer analisar.»
**Revisão aberta** — título «Revisão de {período}»; sub «Revisão fechada — é a acta daquela reunião e não muda.» / «Escreva a causa e a acção correctiva em cada desvio. A revisão só fecha quando não faltar nenhuma.» Barra: «Voltar»; «Data da reunião»; «Participantes» (placeholder «quem esteve na reunião»); (aberta) «Gravar» + «Fechar revisão»; (fechada) «Reabrir»; «Imprimir»; «Excel». Faixa: verde «Fechada por {quem} em {data}.» (+ «Já tinha sido reaberta: {motivo}») / âmbar «{n} desvio(s) ainda sem causa e acção correctiva. A revisão não fecha assim.» / verde «Todos os desvios têm causa e acção. Pode fechar a revisão.» «Notas da reunião» (textarea). **Linhas** (cabeçalho clicável: indicador [+ pastilha da unidade] · «{objectivo} · {período}» · Meta · Realizado · Cumpr. · semáforo [+ «falta explicar»]); expandida: «Causa do desvio *», «Acção correctiva *» (* só se AMARELO/VERMELHO), «Responsável», «Prazo» (data), «Estado da acção» (só leitura), nota, botões «Fechar» / «Gravar».
**Diálogos:** «Abrir revisão» → «De que período?» (escolher) com tarefa «A medir e a abrir a revisão»; «Fechar revisão» («Depois de fechada, a revisão fica selada: é a acta daquela reunião. Continuar?»); «Reabrir revisão» («Porque reabre uma revisão já fechada?», obrigatório); «Apagar revisão» («Apagar esta revisão e as suas linhas?»); «Concluir acção» («O que foi feito? (opcional)»); «Cancelar acção» («Porque cancela esta acção correctiva?», obrigatório).
**Saídas:** «Imprimir» (A4 horizontal; colunas Indicador·Âmbito·Meta·Realizado·Estado·Causa·Acção correctiva·Responsável / prazo; rodapé «Fechada por…»); «Excel» (`Revisao_{período}.xlsx`, folha «Revisão», 13 colunas).

### 4.8 Diagnóstico (`est_diagnostico`, UI:1665-1867) — «Onde estamos, antes de decidir para onde vamos. Cada achado pode virar objectivo do plano.»
- **Barra:** Plano; «{n} factor(es) · {m} já ligados a objectivos»; «Imprimir». Aviso âmbar: «{n} factor(es) de peso elevado ainda sem objectivo nenhum: …».
- **Quatro separadores** (`role=tablist`): «Análise SWOT», «Análise PESTEL», «Partes interessadas», «Cruzamento estratégico (TOWS)»; cada um com contador e, se houver factores sem objectivo, `⚠ n`.
- **SWOT/PESTEL/TOWS:** quadrantes por categoria (cabeçalho: ícone, nome, «Interna · positivo», contador, botão «+»); item: texto (negrito), detalhe, **peso** (classe `alto` ≥ 16, `medio` ≥ 9; tooltip «impacto × probabilidade»; oculto no TOWS), pastilha «sem objectivo» ou nomes dos objectivos ligados, «Resposta», botões «Transformar em objectivo do plano», «Editar», «Apagar».
- **Partes interessadas:** 4 quadrantes de Mendelow («Gerir de perto — Muito poder, muito interesse» / «Manter satisfeitas — Muito poder, pouco interesse» / «Manter informadas — Pouco poder, muito interesse» / «Acompanhar — Pouco poder, pouco interesse»); botão «Parte interessada».
- **Ajudas:** TOWS «O cruzamento é onde o SWOT deixa de ser uma lista: combine o que tem com o que aí vem e escreva a estratégia que daí resulta.»; Partes «As partes interessadas arrumam-se por poder e interesse (matriz de Mendelow)…»; outras «O peso é impacto × probabilidade (nos factores externos) ou só impacto (nos internos). Serve para não ficar com trinta pontos todos iguais.»
- **Modal «Novo/Editar · {tipo}»** (640 px): §5.7. **Diálogos** «Transformar em objectivo» («Escreva o objectivo como um resultado a atingir», omissão = texto do factor) + «Perspectiva» («Em que perspectiva entra este objectivo?»); «Apagar este factor do diagnóstico? Os objectivos que dele nasceram ficam.»
- **Imprimir** (A4 vertical): «Diagnóstico estratégico · {n} factores, {m} ligados a objectivos», missão/visão, secções SWOT, TOWS, PESTEL, PARTE (por categoria, «(peso n)» e «→ objectivos» ou «→ sem objectivo»), «Factores de peso elevado ainda sem objectivo».

### 4.9 Documento do Plano (`est_documento`, DUI:60-190) — ver §12.
Título «Documento do plano»; sub «O plano que está no ERP, com capa e pronto a levar ao Conselho de Administração. As perspectivas, os objectivos, os indicadores e as metas vêm do plano — aqui escreve-se o que o ERP não sabe.» Botões «Editar o plano» (→ `est_plano`), «Pré-visualizar», «Imprimir». Duas colunas (coluna de formulário ≤ 440 px + pré-visualização `iframe` sticky; abaixo de 1100 px empilha): cartões «O documento», «O que entra», «Texto que se escreve à mão»; botão «Gravar» + texto de estado («Há alterações por gravar.» / «Gravado no plano.»); cabeçalho da pré-visualização com contadores («{n} perspectivas», «objectivos», «indicadores», «metas»); caixa âmbar «O documento sai, mas há o que rever no plano:» + lista de avisos.

### 4.10 Janela «Imprimir o plano estratégico» (UI:1884-1918, a partir do ecrã Plano) — o segundo documento
Modal 560 px: «Sai um documento com tudo o que está registado. Pode deixar de fora o que não quiser.» «Período para a situação actual» (selector) + 9 caixas (todas marcadas): Missão, visão e valores · Diagnóstico (SWOT, TOWS, PESTEL, partes interessadas) · Perspectivas, objectivos e causa-efeito · Indicadores e como são medidos · Metas por período · Iniciativas · Desdobramento pelas unidades · Situação actual (painel do período) · Revisões de acompanhamento e acções. Botão «Imprimir» → tarefa «A reunir o plano estratégico» → `documentoPlano()` → janela A4 vertical.

---

## 5. Formulários (campos, tipos, obrigatoriedade, validações, valores por omissão)

### 5.1 Plano (UI:265-293, DAD:170-187)
| Campo | Tipo | Obrig. | Validação / omissão |
|---|---|---|---|
| Nome (`ep-nome`) | texto | sim | «Dê um nome ao plano (ex.: «Plano Estratégico 2027-2031»).»; omissão `Plano Estratégico {ano}-{ano+4}` |
| Ano de início | inteiro | sim | > 1900; omissão ano corrente |
| Ano de fim | inteiro | sim | ≥ início; «Indique o ano de início e um ano de fim igual ou posterior.»; omissão ano+4 |
| Missão | texto longo (2 linhas) | não | placeholder «Para que existimos»; ajuda «Para que existe a organização, em quem serve e com que propósito.» |
| Visão | texto longo | não | placeholder «Onde queremos chegar no fim do ciclo»; ajuda «Uma imagem concreta do fim do ciclo — deve ser possível dizer se foi atingida.» |
| Valores | texto longo | não | uma linha por valor (o documento converte em lista) |
| (oculto) `estado` | enum | — | novo = `RASCUNHO`; só muda por «Aprovar/Reabrir/Encerrar» |
| (oculto) `grupo_id` | inteiro | — | sempre nulo |
Ao criar: cria as 4 perspectivas por omissão (DAD:185,204-211); mensagem «Plano criado com as quatro perspectivas do Balanced Scorecard.»

### 5.2 Perspectivas (UI:302-348, DAD:213-229)
Tabela editável: Ordem (inteiro) · Nome (obrig.: «Dê um nome à perspectiva.») · Descrição · Cor (`input color`, omissão `#475569`) · apagar. «Perspectiva» pede o nome por diálogo («Nova perspectiva» / «Nome da perspectiva») e põe-na no fim. Apagar recusa se tem objectivos: «A perspectiva tem {n} objectivo(s). Mude-os de perspectiva antes de a apagar.» **Defeito do legado:** `estGravarPerspectivas` regrava `icone:'fa-bullseye'` em todas (UI:344), perdendo os ícones por omissão (`fa-coins`, `fa-handshake`, `fa-gears`, `fa-graduation-cap`).

### 5.3 Objectivo (UI:350-394, DAD:231-267)
Objectivo * (texto; placeholder «Ex.: Crescer a margem dos serviços»; ajuda «Escreva-o como um resultado a atingir, não como uma tarefa.») · Descrição · Perspectiva * (omissão: a escolhida no botão) · Horizonte (omissão `PLANO`) · Trimestre do OKR * (só se TRIMESTRE; lista de trimestres de ano−1 a ano+2; «Um OKR tem de dizer a que trimestre pertence.») · Depende de (lista de todos os objectivos do plano excepto o próprio; ajuda «Num OKR, aponte para o objectivo do plano que ele serve.»; «Um objectivo não pode depender de si próprio.» **só auto-referência; não deteta ciclos de 2+ níveis**) · Unidade responsável (unidades orgânicas da empresa; «— empresa —») · Responsável (texto livre) · Estado (omissão ACTIVO). Apagar recusa se há: indicadores («O objectivo tem {n} indicador(es). Apague-os ou mude-os de objectivo primeiro.»), objectivos dependentes («…objectivo(s) dependente(s) (OKR ou desdobramento).»), iniciativas; apaga as ligações causa-efeito do objectivo (DAD:262-266).

### 5.4 Indicador (UI:473-517, DAD:296-336)
| Campo | Tipo | Obrig. | Regra |
|---|---|---|---|
| Indicador | texto | sim | «Dê um nome ao indicador.» |
| Serve o objectivo | lista (todos os objectivos; «(OKR)») | sim | «Um indicador tem de servir um objectivo.» |
| Tipo | enum | — | omissão RESULTADO; ajuda «Os indutores avisam a tempo; os de resultado só contam a história depois.» |
| De onde vem o valor | enum de 5 | **sim** | «Escolha de onde vem o valor do indicador.»; ajuda «Sem origem o indicador não é medido — e o plano não pode ser aprovado.» |
| Configuração da origem | ver abaixo | sim | `validarOrigem` (DAD:270-294) |
| Polaridade / Frequência / Formato | enums | — | omissões SOBE / MENSAL / `num` |
| Linha de base | decimal(2) | não | «Onde estamos hoje.» |
| Período da base | texto | não | placeholder «2026» (sem validação de formato) |
| Verde a partir de (%) | número | — | omissão 100; > 0 |
| Amarelo a partir de (%) | número | — | omissão 90; > 0 e ≤ verde: «As tolerâncias têm de ser positivas e a amarela menor ou igual à verde.» |
| No desdobramento, os contributos das unidades | enum agregação | — | omissão por formato; ajuda «Kwanzas e contagens somam-se; percentagens e notas fazem média. É o que decide se as metas das unidades cobrem a da empresa.» |
| Dono | texto | só MANUAL | «Um indicador de recolha manual precisa de dono: quem o entrega.» (texto livre, **não** FK) |
| Prazo de entrega (dias) | inteiro | não | dias após o fim do período; **nunca usado para alertar** |
| Estado | Activo/Inactivo | — | omissão Activo |
| Descrição / fórmula em palavras | texto | não | |
**Configuração por origem:**
- `KPI_GESTAO`: Módulo (lista dos módulos dos Relatórios de Gestão, com contagem) + Indicador (lista dos KPIs do módulo). Ao escolher um KPI, **herda polaridade** (`desce`→DESCE, `sobe`→SOBE, outro→ALVO) **e formato** do KPI (UI:587-593). Texto: «{total} indicadores já calculados a partir do Diário e dos documentos. Escolher um aqui não dá trabalho nenhum de manutenção.»
- `CONTA`: Prefixos das contas * (texto separado por vírgulas; placeholder «61, 62»; «Apanha todas as contas que comecem por estes dígitos.») + Natureza * (4). Erros: «Indique pelo menos um prefixo de conta (ex.: 61, 62).» / «Escolha a natureza das contas.»
- `ORCAMENTO`: Rubrica * (todas as rubricas activas de todos os tipos de orçamento: `{código} {nome} ({tipo})`) + Medir * (4 medidas). Sem rubricas: «Ainda não há rubricas orçamentais. Crie-as em Gestão Orçamental › Rubricas.»
- `MODULO`: Dados de * (11 fontes) + Operação * (3) + Campo (desactivado se CONTAGEM; «— esta origem não tem campos numéricos —») + **filtros** (campo · operador · valor; botão «Filtro»).
- `MANUAL`: «O valor é escrito no ecrã «Painel estratégico», no fim de cada período, por quem for indicado como dono. Enquanto não for entregue, o indicador aparece como «por medir».»
**Botões:** «Testar medição» (mede o período actual da frequência do indicador; «Não deu para medir {período}: {erro}» / «{período}: {valor formatado} · {detalhe}»; MANUAL: «Indicador de recolha manual: o valor é escrito no painel, não há nada para testar.»), «Cancelar», «Gravar», «Apagar» (só editar). Apagar recusa se há medições: «O indicador já tem {n} medição(ões) gravadas. Desactive-o em vez de o apagar, para não perder o histórico.»; senão apaga também as metas.

### 5.5 Metas por período (UI:678-730, DAD:338-347)
Modal «Metas · {indicador}»: linha «{Frequência} · {Polaridade} · linha de base {x} ({período})»; para cada **ano do plano** (ano_inicio…ano_fim) uma grelha de entradas numéricas (passo 0,01, alinhadas à direita) **uma por período da frequência** (12/4/2/1), com o rótulo do período. Botão «Preencher em escada» (2 diálogos: «Valor do primeiro período»; «Crescimento por período (use % no fim para percentagem, ex.: 5%)») → preenche **todas as caixas do modal** a partir da primeira: `v₀`, depois `+p` ou `×(1+p/100)`, arredondado a 2 casas; «Cancelar»; «Gravar metas» (grava cada caixa; **vazio apaga a meta**). Só mexe nas metas da **empresa** (`unit_id` nulo). O serviço aceita ainda `minimo` e `nota` por meta (DAD:344) **sem UI**.

### 5.6 Iniciativa (UI:1092-1160, DAD:729-749)
Iniciativa * («Ex.: Implantar o novo centro de distribuição») · Descrição · Move o objectivo * · Estado (omissão PREVISTA) · Onde é executada * (tipo; ajuda «A execução e o custo são lidos na fonte. Não se escrevem aqui, para não divergirem.») → bloco dinâmico: PROJECTO/ENTREGAVEL/RUBRICA: «Ligar a *» (lista de candidatos; vazio: «Não há projectos|entregáveis|rubricas nesta empresa. Crie primeiro, ou escolha «Sem ligação».») ; LIVRE: «Execução declarada (%)» (0-100) · Início · Fim (validação «A data de fim é anterior à de início.») · Orçamento previsto (decimal; ajuda «Só se a fonte não o tiver.») · Responsável (texto) · Unidade (lista; «— empresa —»). Regras: «Uma iniciativa serve sempre um objectivo.»; «Escolha o projecto|o entregável|a rubrica a que esta iniciativa está ligada.» Candidatos: projectos da empresa não cancelados `{código · }{nome}({estado})`; entregáveis activos `{nome} · {tarefa}`; rubricas `{código} {nome} ({tipo})`.

### 5.7 Factor de diagnóstico (UI:1757-1813, DAD:1377-1396)
Categoria * (conforme o tipo) · Factor * / «Parte interessada *» (texto; mensagem «Escreva o factor.» / «Indique a parte interessada.»; placeholders «Ex.: Equipa técnica experiente», «Ex.: Clientes institucionais», «Ex.: Lançar a oferta de manutenção nos novos municípios») · «Detalhe» / «O que espera de nós» (PARTE) / «Como se concretiza» (TOWS) · SWOT e PESTEL: Impacto (1-5, omissão 3) e Probabilidade (1-5; **só visível** se a categoria SWOT é Externa — Oportunidade/Ameaça — ou se é PESTEL/TOWS; ajuda «Só conta nos factores externos.») · PARTE: Influência (1-5) e Interesse (1-5) + «Como respondemos» · TOWS: «Combina estes factores» (multi-selecção das linhas SWOT; «Segure Ctrl para escolher vários.» → `origem_ids`). Valores 1-5 forçados (`max(1,min(5,round(v)||3))`).

### 5.8 Diálogos reutilizados do legado (`window.Dialogo.confirmar/pedir/escolher`, não lido)
Todos os «pedir» devolvem texto livre sem validação de tipo no cliente (os números são validados no serviço).

---

## 6. Estados e transições

| Entidade | Transições permitidas pela UI | O que o serviço permite | Guardas |
|---|---|---|---|
| **Plano** | RASCUNHO → APROVADO; APROVADO → RASCUNHO («Reabrir»); APROVADO → ENCERRADO; ENCERRADO → (nada) | **qualquer** estado → qualquer estado (DAD:189-202 só valida que o estado existe) | aprovar exige: todos os indicadores activos com origem válida; os MANUAL com dono (DAD:194-200) |
| Objectivo | edição livre do estado | livre | apagar bloqueado por indicadores/filhos/iniciativas |
| Indicador | Activo ↔ Inactivo | livre | apagar bloqueado por medições |
| Iniciativa | livre | livre | tipo ≠ LIVRE exige `ref_id` |
| **Contributo** | (criado) PEDIDO → PROPOSTO («Propor») → ACEITE («Aceitar») ou DEVOLVIDO («Devolver», motivo); ACEITE → DEVOLVIDO («Reabrir», `valor` fica nulo); DEVOLVIDO → PROPOSTO | idem (`proporContributo` recusa ACEITE; `decidirContributo` exige PROPOSTO; `reabrirContributo` não tem guarda de estado) | propor: só chefe da unidade (ou membro se a unidade não tem responsável) — **verificado só na UI** (`podePropor`), não no serviço |
| **Revisão** | ABERTA → FECHADA (todos os desvios com causa e acção); FECHADA → ABERTA (motivo obrigatório) | idem | apagar só ABERTA |
| **Acção correctiva** | (criada ao gravar a acção) ABERTA → CONCLUIDA \| CANCELADA (motivo) ; ABERTA ← (reabrir não existe na UI; o serviço aceita `ABERTA`) | idem | exige responsável e prazo |
| Factor | — | — | — |

**Não existe** aprovação com decisor distinto do autor, congelamento do plano aprovado (objectivos, indicadores e metas continuam editáveis depois de aprovado), nem versões do plano. **«Em execução»** não é um estado: o plano APROVADO é o «em vigor» (`planoActivo` = primeiro APROVADO por ano_inicio desc, senão primeiro RASCUNHO, DAD:152-155).

---

## 7. Cálculos e regras de negócio (fórmulas)

| Id | Regra | Fonte |
|---|---|---|
| EST-C-01 | **Cumprimento SOBE:** `m = 0 → (v ≥ 0 ? 1 : 0)`; senão `v / m` | DAD:546-547 |
| EST-C-02 | **Cumprimento DESCE:** `v ≤ 0 → 1`; `m = 0 → 0`; senão `m / v` | DAD:537-541 |
| EST-C-03 | **Cumprimento ALVO:** `m = 0 → (v = 0 ? 1 : 0)`; senão `max(0, 1 − |v − m| / |m|)` | DAD:542-545 |
| EST-C-04 | **Semáforo:** sem meta → SEM_META; sem valor → SEM_MEDIDA; `pct = cumprimento×100`; `pct ≥ tol_verde(omissão 100)` → VERDE; `≥ tol_amarelo(omissão 90)` → AMARELO; senão VERMELHO | DAD:550-559 |
| EST-C-05 | **Cor do objectivo = a do pior indicador** (ordem VERMELHO < AMARELO < SEM_MEDIDA < SEM_META < VERDE); sem indicadores → SEM_META | DAD:844-851 |
| EST-C-06 | **Período equivalente** a um período para uma frequência: ANUAL→ano; SEMESTRAL→`ano-S1|S2` (mês ≤ 6); TRIMESTRAL→`ano-T⌈m/3⌉`; MENSAL→`ano-mm` | DAD:602-610 |
| EST-C-07 | **Período anterior** (tendência): índice−1 na lista do ano; para o 1.º, o último do ano anterior | DAD:569-576 |
| EST-C-08 | **Intervalo de um período** (ini/fim, último dia do mês via `Date.UTC`) | DAD:118-129 |
| EST-C-09 | **Medição CONTA:** `PROVEITO = −Σ(D−C)`; `CUSTO = Σ(D−C)`; `MOVIMENTO = Σ(D−C)`; `SALDO = saldoAte(fim)`; `Σ` sobre linhas do Diário no intervalo por prefixos | DAD:408-419 |
| EST-C-10 | **Medição ORCAMENTO:** só orçamentos `APROVADO` do ano; soma dos meses do período de `orçado` (linhas) e `realizado` (por rubrica); `ORCADO`, `REALIZADO`, `DESVIO = real − orç`, `EXECUCAO_PCT = orç ? real/orç×100 : nulo`; sem orçamento aprovado → erro «Não há orçamento aprovado para {ano}.»; rubrica ausente → «A rubrica não está no orçamento aprovado deste ano.» | DAD:448-483 |
| EST-C-11 | **Medição MODULO:** registos da tabela da fonte com a data no intervalo e que passam **todos** os filtros; CONTAGEM `n`; SOMA Σ campo; MEDIA `Σ/n` (nulo se n=0) | DAD:426-440 |
| EST-C-12 | **Medição KPI_GESTAO:** valor do KPI `chave` no módulo para o intervalo (calcula o módulo de Relatórios de Gestão, com cache por módulo+intervalo) | DAD:401-406,358-366 |
| EST-C-13 | **Cobertura do desdobramento:** SOMA → `Σ aceites`; MEDIA → `Σ/n aceites` (nulo se 0); NENHUMA → nulo; `difere = assumido − meta`; `chega = DESCE ? assumido ≤ meta : assumido ≥ meta` (**ALVO tratado como SOBE**); `porPropor` = PEDIDO+DEVOLVIDO; `porDecidir` = PROPOSTO | DAD:954-973 |
| EST-C-14 | **Agregação por omissão:** `pct`/`nota` → MEDIA, resto SOMA | DAD:877 |
| EST-C-15 | **Execução da iniciativa PROJECTO:** `pct = ProjMetas.progressoConjunto(tarefas, raiz)` (ponderado por quantidade quando todos os filhos partilham a unidade) ou `projects.execution_pct`; `custo = Σ |amount|` das linhas do extracto com `nature ∈ {custo_real, custo, C}`; `orcado = Σ amount` das linhas de orçamento do projecto (senão `iniciativa.orcamento`) | DAD:760-771 |
| EST-C-16 | **Execução ENTREGAVEL:** `meta>0 → round(Σ quantidade validada / meta × 100)`; sem meta → 100 se há entregas validadas, senão 0 (não limitado a 100) | DAD:773-788 |
| EST-C-17 | **Execução RUBRICA** (ano **corrente**): `round(custo/orçado×100)` com orçado e custo somados aos 12 meses (**é consumo de verba, não progresso**) | DAD:790-804 |
| EST-C-18 | **Execução LIVRE:** `execucao_manual` (0-100) | DAD:758 |
| EST-C-19 | **Prioridade de factor:** SWOT interno `impacto × 5`; SWOT externo (Oportunidade/Ameaça) `impacto × probabilidade`; PESTEL `impacto × probabilidade`; PARTE `influência × interesse`; TOWS `impacto × 5`. Classes: `alto` ≥ 16, `medio` ≥ 9 | DAD:1361-1370; UI:1676 |
| EST-C-20 | **Mendelow:** `alta = influência ≥ 4`, `muito = interesse ≥ 4` → Gerir de perto / Manter satisfeita / Manter informada / Acompanhar | DAD:1351-1357 |
| EST-C-21 | **Factores «por tratar»:** `prioridade ≥ 12` e sem objectivo vivo ligado, excluindo PARTE | DAD:1441-1443 |
| EST-C-22 | **Escada de metas:** `v₀`; passo absoluto `v += p`; passo `%` `v ×= 1 + p/100`; `round2` | UI:708-721 |
| EST-C-23 | **Objectivo de avaliação (RH):** para cada meta **ACEITE** da unidade do colaborador no intervalo, com `valor` ≠ nulo → `{chave EST-{indId}-{período}, peso 1, QUANTITATIVO, meta = valor aceite, sentido DESCE→MENOR senão MAIOR, atingido = medição da unidade ou ''}` | DAD:1022-1062 |
| EST-C-24 | **Resumo anual** (Relatório e Contas): por indicador, o período da frequência com medição mais recente do ano (senão o último); semáforo; contagens por cor; por perspectiva `total` e `cumpridos(VERDE)` | DAD:1255-1303 |
| EST-C-25 | **Cores da barra de execução da iniciativa:** ≥ 100 verde; ≥ 60 azul; ≥ 30 âmbar; senão vermelho | UI:1058 |

Arredondamento: `r2(v) = Math.round((v+EPSILON)×100)/100` (JS: **meio arredondado para +∞**; −2,5 → −2). O sistema novo usa PHP `round`/`bcmath` (meio **para longe do zero**): difere em valores negativos exactos a meio (desvios). Registado como EST-D-12.
**Não existem:** pesos de objectivos/indicadores (nenhuma agregação ponderada do plano), score global do plano, desvio percentual, EVM, previsão/tendência por extrapolação, meta mínima usada em cálculo (o campo `minimo` só se grava).

---

## 8. Relatórios, painéis, exportações e impressões

| Saída | Origem | Formato | Conteúdo |
|---|---|---|---|
| Painel (ecrã) | UI:735 | HTML | 5 mosaicos, grupos por objectivo, linhas com meta/realizado/tendência/semáforo |
| Imprimir painel | UI:2088 | A4 vertical, janela `window.open` + `print()` | resumo + tabela de 7 colunas |
| Excel do painel | UI:1647 | `XLSX` global (SheetJS) | 10 colunas |
| Mapa estratégico (ecrã/imprimir) | UI:850,1021 | SVG / A4 horizontal | faixas + setas / lista por perspectiva + relações |
| Imprimir revisão / Excel da revisão | UI:1591,1631 | A4 horizontal / XLSX | acta (8 colunas) / 13 colunas |
| Imprimir diagnóstico | UI:1840 | A4 vertical | SWOT, TOWS, PESTEL, partes + por tratar |
| **Imprimir o plano** (9 secções) | UI:1884-2086 | A4 vertical | ver §4.10 e §12.6 |
| **Documento do Plano** | DOC/CORP | A4 com capa, índice, rodapé, assinaturas | ver §12 |
| Cartões do Início/Dashboard | `ui_dashboard.js:652-654,755-772` | contadores | `estNaoCumpre` (vermelhos do mês), `estPorMedir`, `estAccoes` (acções com prazo ultrapassado) |
| Relatório e Contas — capítulo «Execução do Plano Estratégico» | `relatorio_contas.js:79,375,578-` | tabela | resumo, por perspectiva, diagnóstico, indicadores, acções por concluir |
| Portal do colaborador — separador «Estratégia» | `portal_ui.js:933-` | tabela | o que a minha unidade assumiu, por propor, por decidir |
| Dashboards/gráficos | — | — | **não existem** gráficos (nem linha de tendência: `serie()` DAD:613-621 existe e **não tem consumidor**) |

Não há exportação PDF no servidor, Word, nem HTML guardado. A «exportação» do plano é sempre `window.open` + `print()`.

---

## 9. Permissões e perfis (PERM:37-39,106-135)

Todos os ecrãs no módulo «Geral». Padrão do legado: `E(ecrã, rótulo, [tarefas], {nota})`, `T(chave, rótulo, [funções JS bloqueadas], sensível?)`. **9 ecrãs, 19 tarefas, 3 sensíveis** (`T(...,1)`):

| Ecrã (vista) | Tarefa (chave) — rótulo | Sensível |
|---|---|---|
| `est_plano` | `est_plano_editar` — Criar e editar planos, perspectivas e objectivos | |
| | `est_plano_aprovar` — Aprovar, reabrir e encerrar o plano | **sim** |
| `est_diagnostico` | `est_diag_editar` — Registar e editar factores do diagnóstico | |
| | `est_diag_objectivo` — Transformar um factor em objectivo do plano | |
| `est_mapa_estrategico` | `est_mapa_editar` — Desenhar o mapa: mover objectivos e ligar causa-efeito | |
| `est_documento` | `est_doc_editar` — Escrever os textos do documento e gravá-los no plano | |
| `est_iniciativas` | `est_inic_editar` — Criar e editar iniciativas | |
| `est_cascata` | `est_casc_pedir` — Pedir contributos às unidades | |
| | `est_casc_propor` — Propor o contributo da sua unidade | |
| | `est_casc_decidir` — Aceitar ou devolver contributos | **sim** |
| | `est_casc_medir` — Registar o realizado da unidade | |
| `est_revisoes` | `est_rev_abrir` — Abrir revisões de período | |
| | `est_rev_escrever` — Escrever causas e acções correctivas | |
| | `est_rev_fechar` — Fechar e reabrir revisões | **sim** |
| | `est_rev_accoes` — Concluir ou cancelar acções correctivas | |
| `est_indicadores` | `est_ind_editar` — Criar e editar indicadores | |
| | `est_metas_editar` — Definir metas por período | |
| `est_painel` | `est_medir` — Medir o período e gravar as medições | |
| | `est_entregar` — Entregar valores de recolha manual | |

Notas do legado: `est_plano`: «O plano usa indicadores calculados noutros módulos; quem o vê fica a ver esses valores.» (**o próprio catálogo admite que a visibilidade dos valores não é segregada**); `est_mapa_estrategico`: «Mostra o semáforo de cada objectivo no período escolhido.»; `est_documento`: «O documento lê o plano; aqui escreve-se a mensagem de abertura, o diagnóstico e as notas de aprovação, que ficam gravados no próprio plano.»; `est_iniciativas`: «A execução e o custo são lidos nos projectos, entregáveis e rubricas ligados.»; `est_cascata`: «Propor um contributo exige ser responsável da unidade; aceitar é de quem gere o plano.»; `est_revisoes`: «Uma revisão fechada é a acta daquela reunião: reabri-la exige motivo e fica registado.»
As funções bloqueadas por tarefa (`estGravarPlano`, `estMedirAgora`, …) são nomes de funções globais `window.est*` de UI: **33 de 34 existem com esse nome**; a excepção é `estDocGravar` (tarefa `est_doc_editar`), que **não existe** — a gravação do documento é uma função local de DUI (`gravar`, DUI:157), por isso o bloqueio por tarefa não a apanha no legado (defeito EST-D-25). As acções de leitura/edição de modais (`estEditar*`, `estMetas`, `estMetasEmEscada`, `estImprimir*`, `estExcel*`) não estão em nenhuma tarefa. **Perfis-modelo:** nenhum perfil-modelo do legado inclui tarefas `est_*` da estratégia (grep em PERM:539-581: sem ocorrências). **Segregação de funções** (PERM:514-535): nenhum par da estratégia.

---

## 10. Modelo de dados do legado (Dexie v1285-v1288; DB:1177-1221)

Convenção do legado: `est_company_id` (empresa), `is_master_data` (0), `criado_em`/`criado_por`/`actualizado_em`/`actualizado_por` (texto: nome do operador), ids autoincrementais `++id`. **Nenhuma chave estrangeira, nenhum UNIQUE, nenhum CHECK** (Dexie): a integridade é só código.

| # | Tabela | Índices Dexie | Campos (tipo lógico) |
|---|---|---|---|
| 1 | `est_plans` | `++id, est_company_id, grupo_id, estado, ano_inicio, [est_company_id+estado]` | nome, ano_inicio (int), ano_fim (int), missao, visao, valores (texto), grupo_id (int, nulo, **não usado**), estado, `doc` (objecto: titulo, versao, data_documento, elaborado_por, aprovado_por, aprovado_em, incluir_mapa/indicadores/iniciativas/unidades/medicoes, apresentacao, diagnostico, metodologia, monitorizacao, notas) |
| 2 | `est_perspectives` | `++id, est_company_id, plan_id, ordem, [est_company_id+plan_id]` | plan_id, ordem, nome, descricao, cor (`#rrggbb`), icone (`fa-…`) |
| 3 | `est_objectives` | `++id, est_company_id, plan_id, perspective_id, pai_id, unit_id, horizonte, estado, [est_company_id+plan_id], [est_company_id+perspective_id]` | plan_id, perspective_id, pai_id, unit_id (→ `org_units`), nome, descricao, responsavel (texto), horizonte, periodo (chave de trimestre ou ''), estado, ordem, mapa_x (0-100) |
| 4 | `est_indicators` | `++id, est_company_id, plan_id, objective_id, origem, frequencia, activo, [est_company_id+plan_id], [est_company_id+objective_id]` | plan_id, objective_id, nome, descricao, origem, `fonte` (objecto polimórfico: `{modulo,chave}` \| `{prefixos[],natureza}` \| `{rubric_id,medida}` \| `{fonte,operacao,campo,campo_data?,filtros[{campo,op,valor}]}`), polaridade, tipo, formato, unidade (texto), frequencia, linha_base (decimal), linha_base_periodo, dono (texto), prazo_dias, tol_verde, tol_amarelo, agregacao, activo (0/1), ordem |
| 5 | `est_targets` | `++id, est_company_id, indicator_id, periodo, unit_id, [est_company_id+indicator_id], [indicator_id+periodo]` | indicator_id, periodo, unit_id (nulo = meta da empresa), valor, minimo, nota, **desdobramento:** proposta, estado (PEDIDO/PROPOSTO/DEVOLVIDO/ACEITE; nulo nas metas da empresa), motivo, `historico[]` (`{quando, quem, accao, valor, motivo}`) |
| 6 | `est_measures` | `++id, est_company_id, indicator_id, periodo, unit_id, origem, [est_company_id+indicator_id], [indicator_id+periodo]` | indicator_id, periodo, unit_id (nulo = empresa), valor (decimal ou nulo), nota, detalhe, origem (`MANUAL` ou a do indicador), medido_em, medido_por |
| 7 | `est_links` | `++id, est_company_id, plan_id, de_id, para_id, [est_company_id+plan_id]` | plan_id, de_id, para_id (objectivos), nota, criado_em/por |
| 8 | `est_initiatives` | `++id, est_company_id, plan_id, objective_id, tipo, ref_id, estado, [est_company_id+plan_id], [est_company_id+objective_id]` | plan_id, objective_id, nome, descricao, tipo, ref_id (→ projects / org_deliverables / orc_rubrics conforme o tipo), responsavel, unit_id, inicio, fim (`AAAA-MM-DD`), orcamento, estado, execucao_manual |
| 9 | `est_reviews` | `++id, est_company_id, plan_id, periodo, estado, [est_company_id+plan_id], [est_company_id+estado]` | plan_id, periodo, estado, data, participantes, notas, fechada_em/por, reaberta_em/por, reabertura_motivo |
| 10 | `est_review_lines` | `++id, est_company_id, review_id, indicator_id, unit_id, accao_estado, accao_prazo, [est_company_id+review_id], [est_company_id+accao_estado]` | review_id, indicator_id, unit_id, indicador_nome, formato, polaridade, objective_id, periodo, meta, valor, semaforo, cumprimento, causa, accao, accao_responsavel, accao_prazo, accao_estado, accao_concluida_em, accao_nota |
| 11 | `est_context` | `++id, est_company_id, plan_id, tipo, categoria, [est_company_id+plan_id], [est_company_id+tipo]` | plan_id, tipo, categoria, texto, detalhe, impacto, probabilidade, influencia, interesse (1-5), resposta, `objective_ids[]`, `origem_ids[]`, ordem |

**Relações:** plan 1—N perspectivas/objectivos/indicadores/ligações/iniciativas/revisões/factores; perspectiva 1—N objectivo; objectivo N—1 objectivo (`pai_id`, posse) e N—N objectivo (`est_links`, influência); objectivo 1—N indicador, iniciativa; indicador 1—N meta, medição, linha de revisão; meta/medição N—1 unidade orgânica; revisão 1—N linha; factor N—N objectivo (`objective_ids`) e TOWS N—N SWOT (`origem_ids`). **Tabelas externas lidas:** `org_units`, `employees`, `projects`, `project_tasks`, `project_ledger`, `project_budget_lines`, `org_tasks`/`org_deliverables`/`org_task_executions` (via `EstruturaTarefas`), `orc_*` (via `Orcamento`), `journal_lines` (via `RelatoriosGestao.criarLeitor().diario()`), 11 fontes MODULO.

---

## 11. Funcionalidades atómicas (EST-F-001 …)

Coluna «Teste»: **U**=unitário de regra, **A**=API/feature, **E**=ecrã/componente. «Fonte» = `ficheiro:linha`.

| ID | Funcionalidade | Fonte | Teste |
|---|---|---|---|
| EST-F-001 | Criar plano (nome, ano início/fim, missão, visão, valores) em RASCUNHO | DAD:170-187 | A |
| EST-F-002 | Criar as 4 perspectivas BSC por omissão ao criar o plano (Financeira, Clientes, Processos Internos, Aprendizagem e Crescimento; cores `#1d4ed8/#0891b2/#7c3aed/#15803d`; ícones) | DAD:34-39,204-211 | A |
| EST-F-003 | Editar plano | UI:265-293 | A |
| EST-F-004 | Validar plano: nome obrigatório; ano_inicio > 1900; ano_fim ≥ ano_inicio | DAD:172-175 | U |
| EST-F-005 | Listar planos, seleccionar plano (selector), ordem por ano_inicio desc | DAD:151; UI:152-168 | A/E |
| EST-F-006 | Plano activo = primeiro APROVADO, senão primeiro RASCUNHO, senão nenhum | DAD:152-155 | U |
| EST-F-007 | Aprovar plano (RASCUNHO→APROVADO) com validação de origem em todos os indicadores activos | DAD:194-197 | U/A |
| EST-F-008 | Aprovar exige dono nos indicadores MANUAL activos | DAD:198-199 | U |
| EST-F-009 | Reabrir plano (APROVADO→RASCUNHO) | UI:213 | A |
| EST-F-010 | Encerrar plano (APROVADO→ENCERRADO) | UI:214 | A |
| EST-F-011 | Confirmação antes de aprovar/reabrir/encerrar | UI:295-300 | E |
| EST-F-012 | Cartões Missão/Visão/Valores com «por preencher» | UI:222-226 | E |
| EST-F-013 | Gerir perspectivas (modal): listar, renomear, descrição, cor, ordem | UI:302-348 | A/E |
| EST-F-014 | Criar perspectiva (nome por diálogo, ordem = n+1) | UI:322-328 | A |
| EST-F-015 | Apagar perspectiva (recusa se tem objectivos) | DAD:222-229 | U/A |
| EST-F-016 | Criar/editar objectivo (nome, descrição, perspectiva, horizonte, trimestre, pai, unidade, responsável, estado) | DAD:231-250 | A |
| EST-F-017 | Validar objectivo: nome, perspectiva obrigatórios | DAD:233-235 | U |
| EST-F-018 | Horizonte PLANO/TRIMESTRE; OKR exige trimestre; PLANO limpa `periodo` | DAD:236-244 | U |
| EST-F-019 | «Depende de» (pai) — recusa auto-referência | DAD:238 | U |
| EST-F-020 | Estado do objectivo (Activo/Suspenso/Concluído/Abandonado) | DAD:43,245 | A |
| EST-F-021 | Apagar objectivo (bloqueios: indicadores, dependentes, iniciativas; remove ligações) | DAD:252-267 | U/A |
| EST-F-022 | Vista do plano por perspectiva com OKR indentados sob o pai e contagem de indicadores | UI:227-239 | E |
| EST-F-023 | Aviso «sem indicador — não é medido» no cartão | UI:201 | E |
| EST-F-024 | Lista de trimestres (ano−1…ano+2) para o OKR | UI:354-355 | E |
| EST-F-025 | Criar/editar indicador (campos de §5.4) | DAD:296-327 | A |
| EST-F-026 | Origem obrigatória e `validarOrigem` por tipo (5 origens) | DAD:270-294 | U |
| EST-F-027 | Origem KPI_GESTAO: módulo + chave; herda polaridade e formato | DAD:273-277; UI:587-593 | U/E |
| EST-F-028 | Origem CONTA: ≥ 1 prefixo + natureza | DAD:278-282 | U |
| EST-F-029 | Origem ORCAMENTO: rubrica + medida | DAD:283-286 | U |
| EST-F-030 | Origem MODULO: fonte (11), operação (3), campo se ≠ contagem, filtros (7 operadores) | DAD:287-292,376-389 | U |
| EST-F-031 | Origem MANUAL: dono obrigatório; prazo (dias) | DAD:304,318 | U |
| EST-F-032 | Tolerâncias verde/amarelo (positivas; amarela ≤ verde; omissões 100/90) | DAD:305-307 | U |
| EST-F-033 | Polaridade, tipo, formato, frequência, unidade, linha de base e período da base | DAD:311-317 | A |
| EST-F-034 | Agregação das unidades (omissão por formato) | DAD:321 | U |
| EST-F-035 | Activar/inactivar indicador; apagar (bloqueado se tem medições; apaga metas) | DAD:329-336 | U/A |
| EST-F-036 | Procurar indicadores por nome/dono/origem/objectivo | UI:412-417 | E |
| EST-F-037 | Alertas «sem origem» e «manuais sem dono» | UI:418-430 | E |
| EST-F-038 | Catálogo de indicadores de gestão (módulos × KPIs) | DAD:648-666 | A |
| EST-F-039 | «Testar medição» (mede o período actual) antes de gravar | UI:653-661 | A/E |
| EST-F-040 | Metas por período e ano do plano (modal em grelha) | UI:678-705 | E |
| EST-F-041 | Gravar meta (arredonda a 2 casas; vazio apaga; período validado) | DAD:338-347 | U |
| EST-F-042 | Preencher metas em escada (absoluta ou %) | UI:708-721 | U/E |
| EST-F-043 | Meta com `minimo` e `nota` (sem UI) | DAD:344 | A |
| EST-F-044 | Medir um indicador num período (5 origens), sem lançar excepção | DAD:393-446 | U |
| EST-F-045 | Medição CONTA (4 naturezas) sobre o Diário | DAD:408-419 | U |
| EST-F-046 | Medição ORCAMENTO (4 medidas; só orçamento aprovado; soma dos meses do período) | DAD:449-483 | U |
| EST-F-047 | Medição MODULO (contagem/soma/média; data; filtros) | DAD:426-440 | U |
| EST-F-048 | Medição KPI_GESTAO com cache por módulo+período | DAD:358-366 | U |
| EST-F-049 | Gravar medição (valor, detalhe, origem, quem, quando); voltar a medir substitui | DAD:487-508 | U/A |
| EST-F-050 | Entregar valor manual (valor + origem do valor) | UI:828-841 | A/E |
| EST-F-051 | Medir plano num período (só indicadores cuja frequência contém o período; falhas não travam; devolve medidos/falhados/porEntregar/total) | DAD:512-528 | U/A |
| EST-F-052 | Painel por período: linhas por indicador com meta, valor, anterior, semáforo | DAD:562-599 | U/A |
| EST-F-053 | Período equivalente por frequência no painel | DAD:578,602-610 | U |
| EST-F-054 | Resumo do painel (5 contagens) e mosaicos | DAD:591-597; UI:748-795 | U/E |
| EST-F-055 | Tendência (seta) face ao período anterior | UI:753-755 | E |
| EST-F-056 | Série anual do indicador (meta × valor) | DAD:613-621 | U (sem UI) |
| EST-F-057 | Selector de período do painel (todos os períodos do ciclo) | UI:744-746 | E |
| EST-F-058 | Imprimir painel (A4) | UI:2088-2106 | E |
| EST-F-059 | Excel do painel (10 colunas) | UI:1647-1656 | E |
| EST-F-060 | Mapa por perspectiva com cartões e semáforo do pior indicador | DAD:835-859 | U/E |
| EST-F-061 | Arrastar cartão e gravar `mapa_x` (0-100) | DAD:707-710; UI:965-1002 | A/E |
| EST-F-062 | Posicionar cartões sem sobreposição | UI:910-925 | E |
| EST-F-063 | Ligar objectivos causa→efeito em dois cliques | UI:1004-1019 | E |
| EST-F-064 | Ligação recusa auto-ligação, duplicados e ciclos | DAD:693-701,677-691 | U |
| EST-F-065 | Apagar seta (confirmação) | UI:958-962 | A |
| EST-F-066 | Desenhar setas SVG (Bézier) entre bordas dos cartões | UI:927-956 | E |
| EST-F-067 | Imprimir mapa (A4 horizontal, lista + relações) | UI:1021-1038 | E |
| EST-F-068 | Criar/editar iniciativa (campos de §5.6) | DAD:729-749 | A |
| EST-F-069 | Validação: nome, objectivo, ref_id se tipo ≠ LIVRE, fim ≥ início | DAD:731-736 | U |
| EST-F-070 | Execução da iniciativa PROJECTO (pct, custo, orçado) | DAD:760-771 | U |
| EST-F-071 | Execução da iniciativa ENTREGAVEL | DAD:773-788 | U |
| EST-F-072 | Execução da iniciativa RUBRICA | DAD:790-804 | U |
| EST-F-073 | Execução da iniciativa LIVRE (declarada 0-100) | DAD:744,758 | U |
| EST-F-074 | Listas de alvos por tipo (projectos não cancelados; entregáveis activos; rubricas activas) | DAD:813-832 | A |
| EST-F-075 | Apagar iniciativa (a fonte não é afectada) | DAD:751; UI:1156-1160 | A |
| EST-F-076 | Tabela de iniciativas com barra de execução e custo/orçado | UI:1071-1088 | E |
| EST-F-077 | Pedir contributos a unidades (cria PEDIDO por unidade, ignora já pedidas) | DAD:890-908 | U/A |
| EST-F-078 | Propor contributo (valor obrigatório; fundamentação) | DAD:910-921 | U |
| EST-F-079 | Aceitar contributo (valor = proposta) | DAD:923-937 | U |
| EST-F-080 | Devolver contributo (motivo obrigatório) | DAD:928 | U |
| EST-F-081 | Reabrir contributo (valor limpo) | DAD:939-948 | U |
| EST-F-082 | Retirar unidade do desdobramento | DAD:950 | A |
| EST-F-083 | Histórico de movimentos do contributo (PEDIDO/PROPOSTO/ACEITE/DEVOLVIDO/REABERTO) | DAD:884-888 | U |
| EST-F-084 | Cobertura: soma/média dos aceites vs meta da empresa; chega/falta | DAD:954-973 | U |
| EST-F-085 | Identificar a minha unidade e as que chefio | DAD:976-987 | A |
| EST-F-086 | Quem pode propor (chefe; membro se unidade sem responsável) | DAD:991-997 | U |
| EST-F-087 | Medir o realizado da unidade (auto em fontes com unidade; senão manual) | DAD:1002-1014; UI:1327-1347 | U/E |
| EST-F-088 | Bloco por indicador no período equivalente à frequência (só com meta ou contributos) | UI:1184-1197 | E |
| EST-F-089 | Abrir revisão (mede o plano, fotografa linhas por indicador e por unidade com contributo aceite) | DAD:1112-1139 | U/A |
| EST-F-090 | Uma revisão por período (recusa duplicada) | DAD:1115-1116 | U |
| EST-F-091 | Cabeçalho da revisão (data, participantes, notas) | DAD:1193-1201 | A |
| EST-F-092 | Linha da revisão: causa, acção, responsável, prazo | DAD:1160-1176 | A |
| EST-F-093 | Regra «exige explicação» (AMARELO/VERMELHO) | DAD:1158 | U |
| EST-F-094 | Acção correctiva exige responsável e prazo | DAD:1167-1168 | U |
| EST-F-095 | Fechar revisão (só sem desvios por explicar; devolve nº de acções abertas) | DAD:1205-1218 | U/A |
| EST-F-096 | Reabrir revisão (motivo obrigatório; guarda quem/quando/porquê) | DAD:1220-1226 | U |
| EST-F-097 | Apagar revisão (só ABERTA; apaga linhas) | DAD:1228-1236 | U |
| EST-F-098 | Lista de acções correctivas abertas com `vencida` (prazo < hoje) | DAD:1240-1251 | U |
| EST-F-099 | Concluir acção (nota opcional; data) | DAD:1178-1191 | A |
| EST-F-100 | Cancelar acção (motivo obrigatório) | DAD:1184 | U |
| EST-F-101 | Imprimir revisão (A4 horizontal) | UI:1591-1618 | E |
| EST-F-102 | Excel da revisão (13 colunas) | UI:1631-1645 | E |
| EST-F-103 | Registar factor de diagnóstico SWOT (4 categorias) | DAD:1317-1322,1377-1396 | A |
| EST-F-104 | Registar factor PESTEL (6 categorias) | DAD:1323-1330 | A |
| EST-F-105 | Registar parte interessada (8 categorias; influência, interesse, resposta) | DAD:1331-1340,1363 | A |
| EST-F-106 | Registar cruzamento TOWS (4 categorias; origens SWOT) | DAD:1342-1348; UI:1782 | A |
| EST-F-107 | Impacto/probabilidade/influência/interesse limitados a 1-5 (omissão 3) | DAD:1384 | U |
| EST-F-108 | Prioridade do factor (fórmulas EST-C-19) e classes de peso | DAD:1361-1370 | U |
| EST-F-109 | Tratamento da parte interessada (Mendelow) | DAD:1351-1357 | U |
| EST-F-110 | Transformar factor em objectivo (cria objectivo com descrição «Nasceu do diagnóstico: …» e liga factor↔objectivo) | DAD:1402-1418 | U/A |
| EST-F-111 | Ligar/desligar factor a objectivo existente (**serviço sem UI**) | DAD:1420-1428 | U |
| EST-F-112 | Análise: totais por tipo, ligados, «por tratar» | DAD:1432-1444 | U |
| EST-F-113 | Separadores do diagnóstico com contadores e alerta de por-tratar | UI:1731-1746 | E |
| EST-F-114 | Apagar factor (os objectivos ficam) | DAD:1398 | A |
| EST-F-115 | Imprimir diagnóstico (A4) | UI:1840-1867 | E |
| EST-F-116 | Definições do Documento do Plano gravadas no plano (`doc`) | DOC:73-99 | A |
| EST-F-117 | Metadados do documento (título, versão, data, elaborado por, aprovado por/em) | DOC:58-67 | A |
| EST-F-118 | 5 textos livres (apresentação, diagnóstico, metodologia, monitorização, disposições finais) | DOC:35-56 | A |
| EST-F-119 | 5 opções «o que entra» (mapa, indicadores+metas, iniciativas, unidade responsável, último valor) | DOC:62-66 | A |
| EST-F-120 | Recolha do documento: objectivos vivos (ACTIVO/SUSPENSO) com indicadores, metas, última medição e iniciativas | DOC:103-154 | U |
| EST-F-121 | Avisos de lacunas (missão, visão, objectivos sem indicador/meta/unidade/iniciativa, plano não aprovado) | DOC:156-176 | U |
| EST-F-122 | Capa, índice numerado só do que entra, capítulos, banner «Documento em …», assinaturas | DOC:225-285 | E |
| EST-F-123 | Pré-visualização em iframe + contadores + avisos | DUI:166-182 | E |
| EST-F-124 | Imprimir o documento (grava antes se houver alterações) | DUI:184-190; DOC:287-291 | E |
| EST-F-125 | «Imprimir o plano» (modal 9 secções com período) | UI:1884-1918 | E |
| EST-F-126 | `documentoPlano`: reúne tudo (perspectivas, objectivos, indicadores, ligações, metas, medições, iniciativas com execução, desdobramento, revisões+linhas, acções, diagnóstico, painel, unidades) | DAD:1449-1494 | U/A |
| EST-F-127 | Documento completo A4 com 9 secções numeradas e cabeçalho (empresa, NIF, estado, emitido por/em) | UI:1920-2086 | E |
| EST-F-128 | Alertas do Início: indicadores não cumpridos do mês, por medir, acções vencidas | ui_dashboard.js:755-772 | A |
| EST-F-129 | Função `alertas()` (5 tipos) **sem consumidor** | DAD:624-639 | — |
| EST-F-130 | Estrutura Orgânica › Manual: herda missão/visão/valores do plano (só onde o manual está vazio) e lista objectivos por unidade | estrutura_manual.js:228-251,506-518 | A |
| EST-F-131 | Avaliação RH: objectivos `EST-…` das metas aceites da unidade | DAD:1022-1062; avaliacao.js:416-432 | A |
| EST-F-132 | Portal: «meus objectivos» (assumidos, por propor, por decidir) | DAD:1065-1094; portal_ui.js:933- | A |
| EST-F-133 | Relatório e Contas: capítulo «Execução do Plano Estratégico» (`resumoAnual`) | DAD:1255-1303; relatorio_contas.js:578- | A |
| EST-F-134 | Manual de Procedimentos/Fluxos: ligação `estrategia_plano` (5 narrativas) | procedimentos_manual.js:129; fluxo_narrativa.js | E |
| EST-F-135 | Permissões: 9 ecrãs, 19 tarefas (3 sensíveis) | PERM:37-39,106-135 | A |
| EST-F-136 | Ordenar objectivos por `ordem` e nome (pt) | DAD:157 | U |
| EST-F-137 | Isolamento por empresa em todas as leituras | DAD:144-149 | A |
| EST-F-138 | Tarefa «A medir os indicadores…» com progresso (feedback de operação longa) | UI:813,1491,1909 | E |
| EST-F-139 | Mensagens de erro em português claro para cada regra (§5-§7) | DAD/UI | A |
| EST-F-140 | Contributos por unidade entram nas linhas da revisão (uma linha extra por unidade com contributo aceite) | DAD:1127-1135 | U |

**Total: 140 funcionalidades atómicas** (EST-F-001…140), das quais 1 sem consumidor (129) e 3 sem UI (043, 056, 111). **Ecrãs: 9** (+ 1 vista «revisão aberta» e 1 vista «Imprimir o plano»). **Modais próprios: 9** (plano, perspectivas, objectivo, indicador, metas, iniciativa, pedir contributos, factor de diagnóstico, imprimir plano) + **27 diálogos** `Dialogo.*` (confirmar/pedir/escolher). **Fórmulas/regras de cálculo: 25** (EST-C-01…25). **Tabelas do legado: 11.** **Tarefas de permissão: 19** (3 sensíveis) em **9 ecrãs**.

---

## 12. Documento do Plano (submódulo)

### 12.1 O que é
Documento corporativo com capa, índice, capítulos A4 e assinaturas, gerado **no cliente** a partir do plano, para levar ao Conselho de Administração, assinar e arquivar (DOC:1-13). Reutiliza o `DocumentoCorporativo` (`shared/documento_corporativo.js`) — a mesma casca (capa, índice numerado, rodapé, assinaturas) do Manual da Organização e do Manual de Procedimentos. **No sistema novo não existe nenhum equivalente** (`DocumentoCorporativo`, Manual da Organização `est_manual` e Manual de Procedimentos não estão portados — verificado por `grep` em `frontend/src` e `backend/app`).

**Há dois documentos, não um:** (A) o **Documento do Plano** (`est_documento`; capa+índice; narrativo e seleccionável; para o Conselho) e (B) o **«Imprimir o plano»** (modal do ecrã Plano; sem capa; 9 secções técnicas incluindo diagnóstico, desdobramento, situação e revisões). Têm opções, cabeçalhos e CSS diferentes e **duplicam** a recolha de dados (A usa `EstrategiaDocumento.recolher`, B usa `Estrategia.documentoPlano`).

### 12.2 Capítulos do documento A (DOC:230-270) — numerados dinamicamente pelo índice (só entra o que existe)
| # (chave) | Capítulo | Quando entra | Origem dos dados |
|---|---|---|---|
| — | **Capa**: logótipo (ou nome) da empresa; título (`titulo` ou nome do plano); subtítulo «Perspectivas, objectivos, indicadores e metas / para o período de {ano_inicio} a {ano_fim}»; ficha: Período «{ini} – {fim} ({n} ano(s))», Estado, Versão, Data, Elaborado por, Aprovado por («{nome} · {data}»), Conteúdo («{n} objectivo(s), {n} indicador(es), {n} meta(s)») | sempre | plano, `doc`, totais |
| — | **Índice** (lista numerada dos capítulos incluídos; **sem números de página**) | sempre | — |
| 1 `apresentacao` | «Mensagem de abertura» | se o texto não está vazio | `doc.apresentacao` |
| 2 `identidade` | «Missão, visão e valores» (Missão e Visão em caixas; Valores em lista se há >1 linha) | se há missão, visão ou valores | `plano.missao/visao/valores` |
| 3 `diagnostico` | «Diagnóstico e contexto» | se o texto não está vazio | `doc.diagnostico` (**texto escrito à mão, não lê a tabela `est_context`**) |
| 4 `metodologia` | «Como foi construído» | se texto | `doc.metodologia` |
| 5 `mapa` | «Mapa estratégico» — parágrafo «O mapa mostra as {n} perspectivas… Lê-se de baixo para cima…» + faixas (perspectivas em ordem inversa, objectivos como caixas com a unidade) | `incluir_mapa` e há perspectivas com objectivos | perspectivas/objectivos |
| 6 `objectivos` | «Objectivos, indicadores e metas» — «O plano tem {n} objectivo(s), medidos por {n} indicador(es) com {n} meta(s) definidas e {n} iniciativa(s).» + por perspectiva (barra azul com nome/descrição) e por objectivo numerado `{cap}.{i}`: nome, descrição, «Unidade responsável … · Responsável …» (se `incluir_unidades` ou há responsável), tabela de indicadores (Indicador · Unidade · Frequência · Sentido [· Último valor] · Metas «período: valor»), tabela de iniciativas (Iniciativa · Período · Responsável · Estado) | sempre | objectivos vivos (ACTIVO/SUSPENSO) |
| 7 `monitorizacao` | «Como se acompanha» | se texto | `doc.monitorizacao` |
| 8 `notas` | «Disposições finais» + **assinaturas** (Elaborado por / Aprovado por · data) | se texto | `doc.notas` |

Excluídos de A: objectivos CONCLUIDO/ABANDONADO (DOC:123), indicadores inactivos, metas/medições por unidade, desdobramento, revisões, SWOT estruturado, acções.
**Banner** «Documento em {estado}. Este plano ainda não está aprovado: o que se segue é uma proposta.» quando estado ≠ APROVADO. **Avisos de lacunas** (não impedem a saída): sem missão; sem visão; sem objectivos; objectivos sem indicador; indicadores sem meta; (se `incluir_unidades`) objectivos sem unidade; (se `incluir_iniciativas`) sem iniciativa; plano não aprovado.

### 12.3 Documento B («Imprimir o plano», UI:1920-2086) — 9 secções numeradas `{n}. Título` apenas se marcadas e com conteúdo
1 Missão, visão e valores · 2 Diagnóstico (SWOT, TOWS, PESTEL, partes interessadas, por tratar, **lidos de `est_context`**) · 3 Perspectivas e objectivos (tabela por perspectiva + relações causa-efeito) · 4 Indicadores e como são medidos · 5 Metas por período (por indicador: período, meta, realizado, estado) · 6 Iniciativas (com execução e custo lidos na fonte) · 7 Desdobramento pelas unidades (por indicador e período: meta da empresa, assumido, cobertura, linhas por unidade) · 8 Situação em {período} (resumo + tabela do painel) · 9 Revisões de acompanhamento (acta por revisão com linhas + acções por concluir). Cabeçalho: nome do plano, empresa, NIF, ciclo, estado, «Emitido em … por …». Sem capa, índice, rodapé nem assinaturas.

### 12.4 Modelo editável e variáveis de dados
**Modelo:** vive no próprio plano (`est_plans.doc`, JSON): `{titulo, versao('1.0'), data_documento, elaborado_por, aprovado_por, aprovado_em, incluir_mapa, incluir_indicadores, incluir_iniciativas, incluir_unidades, incluir_medicoes, apresentacao, diagnostico, metodologia, monitorizacao, notas}` (DOC:58-99). Omissões: tudo vazio, `versao='1.0'`, `incluir_*` = true excepto `incluir_medicoes = false` («num plano que se apresenta, costuma ficar de fora»). Os textos são **texto puro**: parágrafos separados por linha em branco, `\n` → `<br>`; **HTML nunca é aceite** (`par()` escapa, CORP:33-35); um parágrafo de linhas começadas por `-`/`•`/`*`/`–` vira lista (`comoLista`).
**Variáveis (não há motor de variáveis/placeholders):** os dados entram por código — `empresa{name, logo, nif, address, commune, municipality, province, phone, email, website, crc, capital_social}` (capa e rodapé), `plano{nome, ano_inicio, ano_fim, estado, missao, visao, valores}`, `totais{perspectivas, objectivos, indicadores, metas, iniciativas, anos}`, por objectivo `{nome, descricao, unidade(nome via unit_id), responsavel, indicadores[{nome, unidade, frequencia, polaridade, metas[{periodo, valor}], ultima{valor, periodo}}], iniciativas[{nome, inicio, fim, responsavel, estado}]}`, `opcoes{…}` e `data = data_documento || hoje`.

### 12.5 Edição, versões, aprovação, exportação
- **Edição:** formulário no ecrã `est_documento`; «Gravar» escreve `doc` no plano; «Imprimir» grava antes se houver alterações pendentes (`st.sujo`); mudar de plano no selector **descarta** alterações por gravar sem aviso (UI/DUI:142). Permissão `est_doc_editar`.
- **Versões:** apenas o campo de texto `versao` (omissão «1.0»). **Não há histórico, número sequencial, imutabilidade, emissão, nem comparação de versões**: o documento imprimido hoje e o de amanhã são calculados ao vivo sobre o estado corrente do plano.
- **Aprovação:** os campos «Aprovado por» e «Aprovado em» são **texto livre**, independentes do estado `APROVADO` do plano (um plano em RASCUNHO pode sair com «Aprovado por»). Sem assinatura digital, sem anexos.
- **Exportação:** **só impressão do navegador** (`window.open('','_blank')` + `document.write` + `window.print()` após 500 ms; o utilizador escolhe «Guardar como PDF»). **Não há** geração de PDF no servidor, **Word**, nem ficheiro HTML guardado. A paginação é feita por um script no próprio HTML (CORP:165-222) que estima páginas e escreve o total no rodapé («n páginas»; `counter(page)` só funciona no Chrome) — **incompatível com a CSP do sistema novo** (`script-src 'self'`, sem scripts no documento).
- **Pré-visualização:** `iframe` com `contentDocument.write(html)`.
- **Segurança:** todo o texto livre é escapado; `esc()` em todos os valores; a URL do logótipo (`e.logo`) entra em `src` com `esc()` apenas.

### 12.6 Lacunas funcionais do Documento (dívida a decidir no novo)
(1) o capítulo «Diagnóstico» de A é texto manual e ignora o SWOT/PESTEL registado; (2) A não inclui desdobramento, situação nem revisões (só B); (3) dois documentos com recolhas duplicadas; (4) objectivos CONCLUIDO/ABANDONADO excluídos (decisão defensável mas não documentada no ecrã); (5) as metas por unidade não entram; (6) sem índice com páginas; (7) sem versões nem imutabilidade; (8) sem aprovação formal ligada ao estado.

---

## 13. Interligações com os módulos do sistema novo

Legenda das colunas: **Legado** = evento/dados e o que se grava de cada lado; **Proposta** = ligação no sistema novo; **Duplicação / fronteira**.

| # | Módulo (novo) | Legado: ligação | Proposta no novo | Duplicação / fronteira proposta |
|---|---|---|---|---|
| I-01 | **Orçamento** (`ServicoExecucaoOrcamental`, `OrcamentoAnual`, `RubricaOrcamental`, `LinhaOrcamento`) | **Leitura** por `window.Orcamento`: `orcamentos(cid)` (só `status='APROVADO'` e `ano`), `linhas(id)`, `realizado(cid, orc).porRubrica`, `rubricas(cid, tipo)` (DAD:448-483,790-805,1496-1503). Gatilhos: medir indicador ORCAMENTO; execução de iniciativa RUBRICA; lista de rubricas no editor. **Nada se grava no orçamento.** O orçamento não sabe da estratégia (a narrativa de fluxo diz só «As metas do orçamento devem servir os objectivos do plano», `fluxo_narrativa.js:237`). | Serviço `FontesIndicadoresEstrategicos` chama `ServicoExecucaoOrcamental::realizado()` e lê `linhas_orcamento` (estado `APROVADO`). Rubrica por `rubrica_orcamental_id`. Dados do orçamento nunca copiados. | **Sem duplicação de dados.** Fronteira: Orçamento = valores monetários por rubrica/mês (planeamento financeiro: previsões, cenários, excesso — ADR-044/045/046, já portado); Estratégia = resultados/indicadores. Cruzamento único: origem `ORCAMENTO` + iniciativa `RUBRICA` (só leitura). **Atenção:** `ServicoPlaneamentoOrcamental` e o desdobramento partilham o padrão «pedir → propor → consolidar» (`ServicoOrcamentos::pedirContributos`): reutilizar a **UX e os estados**, não as tabelas. Ligação inversa (orçamento mostrar o objectivo que serve) → fase posterior, só leitura. |
| I-02 | **Projectos** (`ServicoMetasProjetos`, `RegrasMetasProjetos::somaRamo/metaDoRamo`, `ServicoFinanceiroProjetos`, `ServicoOrcamentoProjetos`) | Iniciativa PROJECTO **lê**: `projects`, `project_tasks` (progresso ponderado por meta quando todas as folhas têm a mesma unidade, `ProjMetas.progressoConjunto`), `project_ledger` (custo real) e `project_budget_lines` (orçado) (DAD:760-771). Origens MODULO `projects`/`proj_tasks`. Nada se grava no projecto; `fluxo_narrativa` não liga projecto↔estratégia. | Iniciativa `PROJECTO` guarda `projeto_id`; o serviço lê `percentagem_execucao`/progresso (`RegrasMetasProjetos::somaRamo`), custo real e orçado nos serviços financeiros existentes (nunca SQL cru). Candidatos: projectos não cancelados. | **Metas de projectos ≠ metas estratégicas.** `tarefas_projeto.meta_unidade/quantidade/realizado` medem **execução física** de uma tarefa (m² betonados); `metas_indicadores` medem **resultado de negócio** por período. Estratégia **nunca escreve** em projectos e **não reimplementa** o cálculo de progresso (chama `RegrasMetasProjetos`/`ServicoExecucaoProjetos`). Hierarquia/organigrama do projecto (`NoOrganigramaProjeto`) **não** entram (o legado também não os usa). Orçamento do projecto: leitura. |
| I-03 | **Estrutura Orgânica** (`UnidadeOrganica`, `ServicoEstruturaOrg`, `ServicoEntregaveis`, `ServicoDelegacoesChefia`) | (a) `objective.unit_id`/`initiative.unit_id`/`target.unit_id` → `org_units`; (b) `EstruturaOrg.unidades/colaboradores`, `EstruturaTarefas.meuColaboradorId/entregaveis/execucoes/estadoVal` (DAD:976-987,773-788); (c) responsável da unidade = `responsavel_employee_id` decide quem propõe (DAD:991-997); (d) fonte MODULO `entregaveis` (execuções validadas, campo `unit_id`) e `colaboradores` (`org_unit_id`); (e) **Manual da Organização** herda missão/visão/valores e mostra «Orientação estratégica» (objectivo → unidade responsável) (`estrutura_manual.js:228-262`). Nada se grava na estrutura. | `unidade_organica_id` (FK) em objectivo, iniciativa, contributo, medição. Quem pode propor = `colaborador_responsavel_id` da unidade ou, sem responsável, membro (`unidades_organicas.colaborador_responsavel_id`); **chefia por delegação**: `ServicoDelegacoesChefia` com âmbito novo `ESTRATEGIA` (decisão de contributos) — extensão à delegação sem cadeia (ADR-079). Execução de iniciativa ENTREGAVEL por `ServicoEntregaveis::resumoPeriodo` (meta/validadas). Fonte `entregaveis`: `execucoes_entregavel` com `estado=VALIDADA`. Responsável do objectivo: FK `colaborador_id` (nome livre só para externos). | **Duplicação potencial:** contributo de unidade (meta assumida por indicador/período) vs meta do **entregável** (`entregaveis_tarefa_unidade.meta_quantidade`, por tarefa). São níveis distintos (resultado estratégico vs entregável operacional) e a estratégia só os liga por iniciativa ENTREGAVEL ou por fonte `entregaveis`. Fronteira: a estrutura define *quem*; a estratégia define *o quê e quanto*. O Manual da Organização (não portado) será consumidor, não produtor. |
| I-04 | **Recursos Humanos** (`ServicoAvaliacao`, `ServicoPortalColaborador`, `ServicoAusencias`) | (a) **Avaliação de desempenho**: `Estrategia.objectivosAvaliacao(employeeId, de, ate)` acrescenta objectivos quantitativos `EST-{indId}-{período}` (peso 1) das metas ACEITES da unidade (DAD:1022-1062; `avaliacao.js:416-432`); o valor atingido pode ser corrigido à mão na avaliação (`atingido_auto` vs `atingido`). (b) **Portal**: separador «Estratégia» (`meusObjectivos`). (c) Fontes `ausencias`, `formacao`, `colaboradores` (headcount). | (a) Estender o mecanismo ENT-\* de `ServicoAvaliacao` (ADR-078) com `ambito='ESTRATEGIA'` e chave `EST-{indicador}-{período}`, sem gravar em `criterios_avaliacao_rh`. (b) Separador no portal (`ServicoPortalColaborador`) com a lista do colaborador. (c) Fontes mapeadas para `ausencias_faltas_colaboradores` e `colaboradores`; **«Formação» não tem tabela no novo** (confirmar; não inventar). | **Duplicação:** objectivos individuais da avaliação (`criterios_avaliacao_rh`, `ITEM OBJECTIVO`) vs objectivos estratégicos: a ponte é **virtual** (não copia), como a dos entregáveis. Fronteira: a avaliação continua a ser a única dona da nota do colaborador; a estratégia só fornece meta e medição da unidade. Sem salário/privacidade na estratégia. |
| I-05 | **Contabilidade** (`ModuloFinancas::diario`, `ServicoDemonstracoesFinanceiras`, `ServicoRelatorioContas`) | Origem CONTA lê o Diário: `RG.criarLeitor(cid).diario()`, `somaDR(linhas, p, prefixos)`, `saldoAte(linhas, fim, prefixos)` (DAD:408-419). Origem KPI_GESTAO lê os módulos de Relatórios de Gestão (financas, vendas, compras, tesouraria, rh, activos, projectos, servicos, stock…). **Relatório e Contas** lê `resumoAnual` (capítulo próprio, `relatorio_contas.js:79,375,578`). | `FontesIndicadoresEstrategicos::conta()` sobre `lancamentos_contabeis` (decimais com `bcmath`; classe 9 e apuramento excluídos como em `ModuloFinancas`). KPI_GESTAO por `ServicoRelatoriosGestao::modulo()` com catálogo estático. **Relatório e Contas**: endpoint `…/estrategia/resumo-anual/{ano}` consumido por `ServicoRelatorioContas` (capítulo opcional, **só leitura**, com fotografia na conclusão — mesma regra do relatório). | Sem duplicação. **Segurança:** valores financeiros do plano só para quem vê a origem (ver `ESTRATEGIA_PLANO.md` §5.3) — o legado admite o contrário («quem o vê fica a ver esses valores»). |
| I-06 | **Tesouraria** | Sem ligação directa. Só via KPI_GESTAO `tesouraria` e contas 43/45 (origem CONTA). | Idem I-05; opcionalmente fonte MODULO «Documentos de tesouraria» (fase posterior). | — |
| I-07 | **Vendas / CRM** (`OportunidadeVendaCRM`, `AtividadeComercialCRM`, `Venda`) | Fontes MODULO `sales`, `crm_opp`, `crm_act`; narrativa: «A carteira comercial alimenta os indicadores da perspectiva financeira» (`fluxo_narrativa.js:253`). **Não existem «metas comerciais» no CRM** do legado a que o plano se ligue. | Fontes mapeadas para `vendas` (`data_emissao`, `total_bruto`, `total_liquido`, `tipo_documento`, `estado`, `terceiro_id`), `oportunidades_venda_crm` (`valor`, `probabilidade`, `data_fecho_prevista`, `estado`, `funil_vendas_crm_id`), `atividades_comerciais_crm` (`data_prevista`, `tipo`, `concluida`). | Sem duplicação. Fronteira: CRM não passa a ter metas estratégicas; o indicador estratégico **mede** vendas/CRM. |
| I-08 | **Painel de módulos / Início / KPIs** (`ServicoInicio::pendentes`, `ServicoPaineis`, `PainelEstrutura` como modelo) | 3 contadores no Dashboard (`estNaoCumpre`, `estPorMedir`, `estAccoes`), cada um pela vista do ecrã (`est_painel`/`est_revisoes`); o cálculo mede o painel do mês corrente (ui_dashboard.js:755-772). **Não há painel de módulo** «Planeamento Estratégico» em `ui_painel_modulos.js` (grep). | Itens em `ServicoInicio::pendentes()` (3, com permissão por vista) **lendo medições gravadas** (nunca recalculando — evita ler o Diário no Início). Novo `PainelEstrategia` em `ServicoPaineis` (KPIs: semáforos, objectivos sem indicador, acções vencidas, cobertura do desdobramento). | Duplicação evitada: um só serviço de resumo (`ServicoPainelEstrategico`) consumido por Início, Painel e Portal. |
| I-09 | **Notificações** (`ServicoNotificacoes`, `NotificadorRH`, Outbox ADR-080) | Nenhuma notificação: só contadores no Dashboard; `Estrategia.alertas()` existe mas **ninguém a chama**. A narrativa «Fluxo» não notifica. | `NotificadorEstrategia` (como `NotificadorRH`; `DB::afterCommit` + Outbox `notificacao.estrategia`, idempotente `chave_origem`): contributo pedido / proposto / decidido / devolvido; acção correctiva atribuída e a vencer; indicador MANUAL por entregar (prazo_dias); indicador passou a VERMELHO; revisão por abrir. Categoria nova `estrategia` mapeada a `est_painel`/`est_cascata`/`est_revisoes`. | Sem duplicação; **o legado não tinha** (funcionalidade nova, marcada como melhoria). |
| I-10 | **Auditoria** (`Auditavel`, `ServicoAuditoria`) | Legado: hooks Dexie genéricos «Criou/Atualizou/Eliminou: <tabela>» (`db_v2.js:317-337`, citado no cabeçalho de `Auditavel`) para todas as tabelas; **sem** auditoria específica (quem aprovou o plano só fica em `actualizado_por`). | Modelos com `Auditavel` (`moduloAuditoria='Estratégia'`); eventos de domínio explícitos com motivo: aprovar/reabrir/encerrar plano, fechar/reabrir revisão, aceitar/devolver contributo, emitir/anular versão do documento. Medições em lote registam 1 evento por corrida (não por linha). | — |
| I-11 | **Documentos / impressão / PDF** (`componentes/impressao`, `ServicoPdfA4POS`, `DocumentoAnexo`) | `DocumentoCorporativo` + `window.open/print`; Excel por `XLSX` global. | Ver §12 e `ESTRATEGIA_PLANO.md` §7. | O Relatório e Contas (`ServicoRelatorioContas`, RASCUNHO→APROVADO com fotografia) é o **precedente** do ciclo de vida do documento. |
| I-12 | **Fluxo de Processos** (`ServicoFluxos`, `narrativas.json`) | 5 narrativas de outros processos apontam para `estrategia_plano`: avaliação («Critérios e objectivos»), orçamento («Preparação»), CRM («Oportunidade»), entregáveis («Definir a tarefa e o entregável»), fecho («Relatório e Contas») (`fluxo_narrativa.js:71,237,253,291,369`); o Manual de Procedimentos tem o externo `estrategia_plano` (`procedimentos_manual.js:129`). O processo «Planeamento Estratégico» **não existe** como fluxo. | Acrescentar o processo «Planeamento Estratégico» ao catálogo de fluxos (narrativa + passos) e as 5 ligações cruzadas em `narrativas.json`. | — |
| I-13 | **Método de Trabalho** (`AssuntoMetodoTrabalho`) | **Sem ligação no legado.** Mas a «acção correctiva» de uma revisão (causa → acção → responsável → prazo → estado) é, na prática, um assunto 5W1H em miniatura. | Decisão (pergunta Q9): manter a acção leve em `linhas_revisao_estrategica` **ou** oferecer «Abrir assunto no Método de Trabalho» (ligando `assunto_metodo_trabalho_id`). | **Duplicação potencial** (acção correctiva vs assunto 5W1H). Recomendação: manter a acção leve (paridade) e acrescentar só o atalho opcional. |
| I-14 | **Activos / Compras / Consolidação (holding)** | Fontes MODULO `manutencao` (`asset_work_orders`), `purchases`; `grupo_id` reservado. | Fontes mapeadas a `ordens_manutencao_ativos`/`faturas_compra`; `grupo_id`: **não criar** agora (YAGNI; a consolidação ADR-057 trata de empresas, não de planos). | — |

**Resumo das interligações:** 14 linhas. A estratégia **só lê** de Orçamento, Projectos, Estrutura/Entregáveis, Contabilidade/Gestão, Vendas/CRM, RH (fontes) e Activos/Compras: **nenhuma escrita em módulos alheios** (no legado nem na proposta). É **consumida** por Início/Dashboard, Estrutura › Manual da Organização, RH › Avaliação, RH › Portal, Contabilidade › Relatório e Contas e (texto) Manual de Procedimentos/Fluxo. Ligações **novas** no sistema novo (sem equivalente no legado): Notificações (I-09), Auditoria de domínio (I-10), Painel de módulo (I-08), Método de Trabalho (I-13, opcional) e delegação de chefia (I-03).

---

## 14. Defeitos, discrepâncias e riscos do legado (a corrigir/decidir no novo)

| Id | Constatação | Evidência | Proposta |
|---|---|---|---|
| EST-D-01 | **Nenhuma regra é aplicada no servidor** (permissões, estados, quem pode propor): tudo em JS | todo o módulo | regras nos serviços Laravel + `exigir()` em cada acção |
| EST-D-02 | `mudarEstadoPlano` aceita qualquer transição (ENCERRADO→APROVADO via consola) | DAD:189-202 | máquina de estados com transições explícitas |
| EST-D-03 | Plano APROVADO continua editável (objectivos, indicadores, metas): a aprovação não «congela» | sem guarda em `gravar*` | pergunta Q5 (congelar / versões do plano) |
| EST-D-04 | Podem existir vários APROVADO sobrepostos; `planoActivo` escolhe o de `ano_inicio` maior | DAD:152-155 | pergunta Q4 (um APROVADO por empresa) |
| EST-D-05 | `est_targets` mistura meta da empresa e contributo de unidade (campos `proposta/estado/motivo/historico` nulos na primeira); sem UNIQUE pode duplicar (indicador, período, unidade) em concorrência | DAD:338-347,890-908 | duas tabelas + UNIQUE |
| EST-D-06 | `est_measures` sem UNIQUE: «voltar a medir substitui» por *procurar e actualizar* (corrida) | DAD:504-507 | UNIQUE (indicador, período, unidade) com `COALESCE` + upsert |
| EST-D-07 | **Revisão:** o comentário do esquema diz que as linhas guardam meta/realizado «do momento do fecho», mas o código fotografa na ABERTURA e `fecharRevisao` não refresca | DB:1208-1211 vs DAD:1112-1154,1205-1218 | decidir: fotografar também ao fechar (recomendado) |
| EST-D-08 | `pai_id` só recusa auto-referência: ciclos A→B→A de posse não se detectam | DAD:238 | detectar ciclos também no pai |
| EST-D-09 | `estGravarPerspectivas` regrava `icone:'fa-bullseye'` em todas as perspectivas | UI:344 | não tocar no ícone |
| EST-D-10 | `ALVO` tratado como `≥` na cobertura (`chega`) | DAD:966 | `ALVO`: `|assumido − meta| ≤ tolerância` |
| EST-D-11 | Execução de iniciativa RUBRICA = % de consumo da verba, apresentada como «Execução» ao lado de projectos (progresso) | DAD:804 | rotular «Consumo» ou separar |
| EST-D-12 | `r2` em JS arredonda meios para +∞; PHP para longe do zero (difere em negativos a meio) | DAD:25 | `round()`/`bcmath` com teste de negativos |
| EST-D-13 | `serie()`, `alertas()` e `ligarObjectivo()` sem UI/consumidor; `minimo`/`nota` da meta sem UI; `grupo_id` e `prazo_dias` nunca usados | §11 | decidir caso a caso: ligar ou não portar |
| EST-D-14 | Dono do indicador e responsável de objectivo/iniciativa/acção são **texto livre** (não FK) | DAD:239-246,318,742 | FK `colaborador_id` (+ texto para externos) |
| EST-D-15 | Fontes MODULO listam tabelas/colunas **do legado** (nomes que não existem no novo); `rh_trainings` sem equivalente confirmado | DAD:84-96 | allowlist de fontes com mapeamento explícito |
| EST-D-16 | `catalogoKpis()` **calcula** os ~116 KPIs de todos os módulos só para listar nomes (lento e dependente dos dados) | DAD:648-666 | catálogo estático por módulo |
| EST-D-17 | O Início carrega o plano **inteiro** (objectivos, indicadores, metas, medições) só para obter 3 contadores; o comentário «medir o painel lê o Diário» (`ui_dashboard.js:755`) é impreciso: `painel()` só lê medições gravadas | DAD:562-599 | contadores por consulta SQL agregada |
| EST-D-18 | Mudar de plano no ecrã do documento descarta alterações por gravar sem avisar | DUI:142 | aviso de alterações por gravar |
| EST-D-19 | O documento A não usa o diagnóstico estruturado (texto manual) | DOC:230-258 | capítulo automático opcional |
| EST-D-20 | Paginação do documento por script inline — incompatível com a CSP do novo | CORP:165-222 | `dompdf` com «Página X de Y» (como `ServicoPdfA4POS`) |
| EST-D-21 | `reabrirContributo` não tem guarda de estado (pode reabrir um PEDIDO) | DAD:939-948 | guarda de estado |
| EST-D-22 | `abrirRevisao` aceita qualquer período válido, também fora do ciclo do plano; `gravarMeta` idem | DAD:1114,338 | validar dentro do ciclo |
| EST-D-23 | `est_casc_decidir` não impede quem propôs de aceitar o próprio contributo (sem segregação) | PERM:117 | `AUTO_DECISAO` (como ADR-079) |
| EST-D-24 | Valores com 2 casas e `float` JS; indicadores `pct`/`dias`/`nota` precisam de mais precisão nas médias | DAD:25 | `numeric(20,4)` (ADR-022 só cobre dinheiro) |
| EST-D-25 | A tarefa `est_doc_editar` bloqueia `estDocGravar`, função que não existe | PERM:110 | no novo a tarefa é verificada no endpoint |

| EST-D-26 | **Comparação do semáforo em vírgula flutuante:** `8,1 / 9 × 100 = 89,99999999999999` em JS, logo uma meta atingida a exactamente 90 % (limite do AMARELO) sai VERMELHO; o mesmo em qualquer fronteira de tolerância | DAD:556-558 | aritmética decimal (`bcmath`, escala 6) + teste de fronteira |

(Total: 26 constatações, EST-D-01…26.)
