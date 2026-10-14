# Projectos e produtividade ligada a projectos: o que o legado novo tem a mais e o que falta no erp_laravel

**Comparação:** `payroll_system_web` (legado antigo) contra `Versões/Versão Actual/ERP` (legado novo), e verificação no `erp_laravel`.
Quase todo o código novo é de **2026-10-11**. A excepção é `contratos_produtividade.js`, de 2026-09-23.
Os caminhos do legado são relativos a `Versões/Versão Actual/ERP/js/`.

## Em resumo

- **Menus:** não mudam. Continuam a existir só `projectos_carteira`, `projectos_extracto`, `projectos_gantt` e `rh_produtividade` (`index_arrumado.html`). O que é novo aparece dentro do detalhe do projecto: um separador novo, **Execução**, mais vistas, botões e janelas modais nos separadores Planeamento, Equipa, Orçamento e Organigrama, e um separador novo no RH › Produtividade.
- **Dexie (`db_v2.js`):** não há tabelas nem índices novos para projectos. Os dados novos são **campos sem índice** em tabelas que já existiam (lista na secção 0).
- **Permissões (`permissoes.js`):**
  - há uma tarefa nova, `proj_orcamento_qtd` (nível 1);
  - `proj_gerir` e `proj_execucao` cobrem as funções novas;
  - `rh_prod_registar` passa a cobrir `prodProjectosAssistente` e `prodProjectosEnviar`;
  - `contratos_new` passa a cobrir `contratosProdutividadeEmMassa` e `aplicarProdutividadeEmMassa`.
- **erp_laravel:** procurei com grep `meta_quantidade`, `meta_execucoes`, `mapas_qtd`, `depends_on`, `predecessora`, `dependencia`, `origem_chave`, `Mapa de quantidades`, `Carga de trabalho`, `Folha de medi` e `calendario` (em Projetos). **Não há nenhuma ocorrência em `backend/app`, `backend/routes`, `backend/database/migrations` nem em `frontend/src`.** O módulo Projectos do erp_laravel corresponde ao legado antigo.

## Tabela-resumo

| # | Funcionalidade | Ecrã / separador | Estado no erp_laravel | Esforço |
|---|---|---|---|---|
| 1 | Calendário de trabalho do projecto (dias, ½ dia, horas/dia) | Ficha do projecto (modal) | FALTA | Baixo |
| 2 | Meta por quantidade na tarefa (unidade, meta, realizado → % calculada) | Planeamento › modal Tarefa | FALTA | Médio |
| 3 | Meta por quantidade da fase e data de início da fase | Planeamento › modal Fase/Milestone | FALTA | Baixo |
| 4 | Registo de execução por quantidade com histórico (`meta_execucoes`) e conclusão automática | Planeamento (linha da tarefa) | FALTA | Médio |
| 5 | Folha de medição (várias tarefas de uma vez, colar do Excel) | Planeamento e Execução | FALTA | Médio |
| 6 | Assistente «Tarefas por quantidade» (reparte uma meta total por N tarefas e responsáveis) | Planeamento | FALTA | Médio |
| 7 | Progresso da árvore ponderado pela quantidade | Planeamento, Gantt, Resumo | FALTA (só média simples / %) | Baixo |
| 8 | Dependências entre tarefas (FS/SS/FF, desfasamento, anti-ciclo, data mínima) | Modal Tarefa, coluna «Precedência», setas no Gantt | FALTA | Alto |
| 9 | Datas dentro do nível acima (subtarefa ⊂ tarefa ⊂ fase), com encaixe automático | Gravar tarefa ou fase, mover | FALTA | Médio |
| 10 | Mudar de nível e converter fase ↔ tarefa (arrastar fases) | Planeamento | PARCIAL (só mover tarefas) | Médio |
| 11 | Duplicar fase, tarefa ou subtarefa com deslocamento de datas | Planeamento | FALTA | Médio |
| 12 | Ordenação automática (data + precedência) | WBS e Gantt | FALTA (só ordem manual) | Baixo (depende do #8) |
| 13 | Coluna «Estrutura orgânica» (posição do organigrama) na WBS e no Gantt, e campo posição no modal Tarefa | Planeamento | PARCIAL (associação existe no Organigrama) | Baixo |
| 14 | Gantt com escala escolhida (dia…ano), filtro por período, árvore recolhível, quantidades por período | Planeamento › Gantt | PARCIAL (Gantt simples, escala automática, linha de hoje) | Alto |
| 15 | Mapa de quantidades (vistas Quantidades, Horas, Valor; acumulado; níveis; impressão) | Planeamento › «Mapa de quantidades» | FALTA | Alto |
| 16 | Orçamento mapeado pelas quantidades (preço unitário, distribuição por tarefa, fase, área e pessoa) | Orçamento | FALTA | Alto |
| 17 | Organigrama: valores somam as subposições e incluem o orçamento mapeado por quantidade | Organigrama | PARCIAL | Médio |
| 18 | Carga de trabalho da equipa (por responsável e área, horas previstas e lançadas) | Equipa › botão «Carga de trabalho» | FALTA | Alto |
| 19 | Ordem de trabalho impressa por pessoa | Equipa › Carga de trabalho | FALTA | Médio |
| 20 | Separador «Execução» (horas, quantidades e tarefas concluídas por colaborador e período) | Detalhe › Execução | FALTA | Médio |
| 21 | Ponte Execução → RH Produtividade (assistente, mapeamento para itens, sem duplicar) | Execução e RH › Produtividade › «Execução de projectos» | FALTA | Alto |
| 22 | Data de conclusão da tarefa (`concluida_em`) | Modal Tarefa, Kanban, metas | FALTA | Baixo |
| 23 | Folhas de horas gravam o membro da equipa (`team_member_id`) | Horas | PARCIAL (equivalente seguro, mas recusa externos) | Baixo |
| 24 | Autos e revisões só nos meses que o projecto ocupa | Revisões › modal | FALTA | Baixo |
| 25 | Painel do projecto: equipamentos alocados (agregado) e origem «Método de Trabalho» | Resumo/Dashboard | PARCIAL / FALTA | Baixo / Médio |
| 26 | Contratos: aplicar itens de produtividade em massa | RH › Contratos | FALTA (o massa só trata rubricas) | Médio |

**Totais:** 0 EXISTE, 6 PARCIAL e 20 FALTA. Se todos os itens forem reproduzidos, o esforço global é Alto. A base de tudo são os itens 1–4 e 22: esquema e regras das metas.

---

## 0. Dados novos no Dexie (todos sem índice) e o que falta no esquema do erp_laravel

| Tabela Dexie (legado) | Campo novo | Tabela erp_laravel | Em falta |
|---|---|---|---|
| `projects` | `calendario` = `{ horas_dia, dias: {0..6: 0, 0.5 ou 1} }` | `projetos` | coluna `calendario jsonb` |
| `project_tasks` | `meta_unidade`, `meta_quantidade`, `meta_realizado`, `meta_execucoes` = `[{q, data, nota, por, em}]`, `depends_on_task_id`, `dependency_type` (FS/SS/FF), `dependency_lag_days`, `concluida_em` | `tarefas_projeto` | todas estas colunas |
| `project_milestones` | `date_start`, `meta_unidade`, `meta_quantidade` | `marcos_projeto` (só tem `data`) | `data_inicio`, `meta_unidade`, `meta_quantidade` |
| `project_budget_lines` | `mapas_qtd` = `[{unidade, escopo:{tipo:'UNIDADE'/'FASE'/'TAREFAS', ids}, valor, base:'META'/'EXECUTADO', preco_unitario, quantidade_mapeada, criado_em, criado_por}]`. O campo antigo `mapa_qtd`, de um só objecto, continua a ser lido. | `linhas_orcamento_projeto` | `mapas_qtd jsonb` |
| `project_timesheets` | `team_member_id` | `folhas_horas_projeto` (só tem `colaborador_id`) | `membro_equipa_projeto_id` |
| `project_org_nodes` | `tarefas`, que já existia | `nos_organigrama_projeto.tarefas jsonb` | já existe |
| `rh_productivity` (registos) | `origem_chave`, `fonte` (json), `origem='PROJECTO'` | `registos_produtividade_rh` (tem `origem varchar(10)`) | `origem_chave` com índice único por período, `fonte jsonb` |

Para as linhas dos registos de execução, recomendo uma tabela filha em vez de jsonb, por exemplo `execucoes_meta_tarefa` (tarefa, data, quantidade, nota, autor). Facilita a ponte para o RH (#21) e as consultas por período.

---

## 1. Calendário de trabalho do projecto
1. **O que faz:** na ficha do projecto escolhe-se um modelo: Segunda a sexta (defeito), +½ sábado, Segunda a sábado, Todos os dias, ou Personalizado dia a dia (0, ½ ou 1). Indicam-se também as horas por dia (defeito 8). É a base das horas previstas e da repartição das quantidades pelo tempo.
   - `ui_projects.js:246-334` (formulário) e `:335` `projCalendarioModelo`
   - gravação em `ui_projects.js:~429` (`saveNewProject`)
   - regras em `projectos_metas.js:127-185`
2. **Dados:** `projects.calendario`. Fórmulas:
   - `diasUteis(de, ate) = Σ factor(dia da semana)`
   - `horasPrevistas(t) = diasUteis(início, fim) × horas_dia`
   - validação: horas por dia entre 0,5 e 24; pelo menos um dia com factor > 0.
3. **erp_laravel: FALTA.** `projetos` não tem a coluna. `ModalProjecto.tsx` não tem o campo. Há `ServicoCalendarioRH.php`, mas é do RH (feriados/escala) e não serve aqui.
4. **Esforço:** Baixo. Migração, validação no `ServicoProjetos` e campo no `ModalProjecto`. Os itens #14, #15 e #18 dependem deste.

## 2. Meta por quantidade na tarefa
1. **O que faz:** no modal da tarefa há um bloco «Meta por quantidade», com a unidade (lista sugerida: un, m, m², m³, kg, ton, km, L, h, dia, visita, ensaio, lote, peça, %), a quantidade a atingir e o já realizado. Com meta, a % de execução deixa de se escrever à mão. Também mostra o **ritmo exigido** pelo prazo.
   - `ui_projects.js:2226-2245` (modal) e `:2398-2434` (`saveTask`)
   - `projectos_metas.js:45-50, 335-352` (`ritmo`)
2. **Regras:**
   - `pct = clamp(round(realizado / meta × 100), 0, 100)`
   - com meta, é obrigatório indicar a unidade
   - CONCLUÍDA com realizado < meta pede confirmação
   - com subtarefas, o «realizado» fica só de leitura
   - ritmo por dia = `meta / dias úteis`; por semana = por dia × Σ factores da semana; por mês = por semana × 4,33; por hora = `meta / (dias × horas_dia)`.
3. **erp_laravel: FALTA.** `ServicoPlaneamentoProjetos::guardarTarefa` só trata `percentagem_execucao`. `tipos.ts` (TarefaWbs) não tem campos de meta.
4. **Esforço:** Médio. Migração, serviço e validação, modal React e testes. É a base dos itens #4–7, #15, #16, #18, #20 e #21.

## 3. Meta da fase e data de início da fase
1. **O que faz:** a fase passa a ter **data de início**, além da data alvo, e uma meta total (unidade e quantidade). O realizado da fase sobe das tarefas. A WBS mostra «feito / meta» e avisa: «faltam repartir X» ou «tarefas X acima da meta».
   - `ui_projects.js:2481-2560` (modal e `saveMilestone`)
   - aviso na WBS em `ui_projects.js:690-706` (`metaDaFase`)
2. **Regras:**
   - a data alvo não pode ser anterior à de início
   - ao apertar o intervalo da fase, avisa sobre as tarefas que ficam fora
   - a soma vem de `somaRamo`, sem contar duas vezes o pai que tem filhos com meta.
3. **erp_laravel: FALTA.** `marcos_projeto` só tem `data`. `guardarMarco` grava nome e data.
4. **Esforço:** Baixo.

## 4. Registo de execução por quantidade, com histórico
1. **O que faz:** um botão na linha da tarefa abre uma janela pequena para registar a quantidade executada numa data, com nota. Aceita negativos para corrigir. O histórico fica guardado. Ao chegar a 100 % a tarefa fica CONCLUÍDA e guarda a data; acima de 0 % passa de PENDENTE a EM_CURSO. Fica registado no log de movimentos.
   - `projectos_metas.js:355-393` (`aplicar` e `registar`) e `:405` (`projMetaRegistar`)
2. **Regras:**
   - `novo = realizado + q` (não pode ficar negativo)
   - entrada `{q, data, nota, por, em}` acrescentada a `meta_execucoes`
   - não se regista numa tarefa cujas subtarefas têm meta
   - permissão `proj_execucao` ou `proj_gerir`, verificada também dentro do módulo.
3. **erp_laravel: FALTA.** Só existe `atualizarExecucao(TarefaProjeto, int $pct)`.
4. **Esforço:** Médio. Tabela de execuções, endpoint, auditoria e testes de concorrência (`lockForUpdate`).

## 5. Folha de medição em lote
1. **O que faz:** uma linha por tarefa com meta, com o que falta, uma data comum e a opção de **colar duas colunas** de uma folha de cálculo (código ou nome da tarefa e quantidade; separadores tab, «|» ou «;»; aceita 1.234,56 e 1 234,56). Lista as linhas não reconhecidas. Cada linha passa pela mesma regra do #4.
   - `projectos_metas.js:468-628` (`projMetasRegistarEmMassa`) e `:631-676` (`colarMedicoes`)
   - botões em `ui_projects.js:779` (Planeamento) e no separador Execução
2. **Dados:** os mesmos do #4.
3. **erp_laravel: FALTA.**
4. **Esforço:** Médio. Depende do #4.

## 6. Assistente «Tarefas por quantidade»
1. **O que faz:** a partir de uma meta total (por exemplo 1 200 m²), cria N tarefas, ou subtarefas de uma tarefa, dentro de uma fase. Cada uma tem a sua parte da meta e um responsável (membro da equipa ou posição do organigrama). Opcionalmente reparte o prazo em sequência.
   - `projectos_metas.js:683-839` (`projMetasEmMassa`) e botão em `ui_projects.js:778`
2. **Regras:** prazo repartido = `(fim − início + 1)` dias, dividido em partes sequenciais; as quantidades são as indicadas por parte; o destino é a raiz da fase ou uma subtarefa.
3. **erp_laravel: FALTA.**
4. **Esforço:** Médio. Depende dos itens #2 e #13.

## 7. Progresso ponderado pela quantidade
1. **O que faz:** quando todos os filhos têm meta e a mesma unidade, o progresso do pai é `Σ min(feito, meta) / Σ meta`. Caso contrário é a média dos filhos (recursiva).
   - `projectos_metas.js:51-110` (`progresso`, `somaRamo`, `progressoConjunto`)
   - usado em `ui_projects.js:670-688`
2. **erp_laravel: FALTA.** `ServicoPlaneamentoProjetos::wbs` calcula a execução do marco por média simples.
4. **Esforço:** Baixo. Depende do #2.

## 8. Dependências entre tarefas
1. **O que faz:** no modal da tarefa escolhe-se a predecessora, o tipo de ligação e o desfasamento em dias.
   - Tipos: **Fim→Início** (FS, por defeito), **Início→Início** (SS) e **Fim→Fim** (FF).
   - Validação ao gravar: impede ciclos e calcula a data mínima.
   - Coluna «Precedência» na WBS, com o número de tarefas que dependem desta.
   - Setas de precedência desenhadas no Gantt.
   - Ligar por arrasto entre barras, com janela de confirmação do tipo. Remover a ligação.
   - Ficheiros: `projectos_hierarquia.js:35-41, 127-172, 220-262, 413-680` e `ui_projects.js:2188-2203, 2420-2450, 717-727`
2. **Regras:**
   - FS: `início ≥ fim(pred) + 1 + desf`
   - SS: `início ≥ início(pred) + desf`
   - FF: `fim ≥ fim(pred) + desf`
   - ciclo: percorrer `depends_on_task_id` até ao fim da cadeia; não podem ser candidatas a própria tarefa nem as suas descendentes.
3. **erp_laravel: FALTA.** Não há colunas nem lógica. O Gantt (`comum/Gantt.tsx`) é CSS simples, sem ligações.
4. **Esforço:** Alto. Esquema e validação no backend são Médio. As setas e a ligação por arrasto no Gantt React aumentam o esforço.

## 9. Datas dentro do nível acima
1. **O que faz:** uma subtarefa tem de caber nas datas da tarefa-mãe e uma tarefa nas da fase. Verifica-se ao gravar, ao mover e ao mudar de nível, com a opção de **encaixar** as datas automaticamente. Avisa sobre as subtarefas que ficam fora quando se mudam as datas do pai.
   - `projectos_hierarquia.js:63-125` (`intervaloDoPai`, `validarDatas`, `encaixar`, `conflitosAbaixo`) e `ui_projects.js:2441-2453`
3. **erp_laravel: FALTA.** `guardarTarefa` só valida fim ≥ início.
4. **Esforço:** Médio. A regra é simples, mas toca na gravação, no mover e nas mensagens de confirmação.

## 10. Mudar de nível e converter fase ↔ tarefa
1. **O que faz:**
   - botão «Mudar de nível»: tarefa ↔ subtarefa ↔ fase; as subtarefas vão sempre com o pai
   - arrastar uma **fase** para cima de uma tarefa transforma-a em subtarefa; para cima de outra fase, transforma-a em tarefa principal
   - converter uma fase em tarefa, ou uma tarefa em fase.
   - Ficheiros: `projectos_hierarquia.js:682-839` (`projMudarNivel`, `projConverterFase`, `converterFaseEmTarefa`, `converterTarefaEmFase`) e `ui_projects.js:3539+`
3. **erp_laravel: PARCIAL.** `ServicoPlaneamentoProjetos::mover` (ANTES, DEPOIS, DENTRO ou MARCO, com controlo de ciclos) e `Planeamento.tsx` (arrastar, M-20) só movem **tarefas**. Não há conversão fase ↔ tarefa nem validação de datas no movimento.
4. **Esforço:** Médio.

## 11. Duplicar camada
1. **O que faz:** copia uma fase com as suas tarefas, ou uma tarefa com as suas subtarefas. Dá-lhe um nome novo e desloca as datas N dias. A cópia nasce a 0 % e PENDENTE; horas, custos, autos e requisições não são copiados. As precedências internas ao bloco ligam as cópias entre si.
   - `projectos_hierarquia.js:275-411`
3. **erp_laravel: FALTA.** Não há «duplicar» em `separadores/*.tsx` nem nos serviços de Projetos.
4. **Esforço:** Médio.

## 12. Ordenação automática
1. **O que faz:** entre irmãos, ordena por data de início sem nunca pôr uma tarefa antes da sua predecessora. Sem datas, vale a ordem manual. A ordem é a mesma na WBS, no Gantt, na carga de trabalho e na ordem de trabalho.
   - `projectos_hierarquia.js:174-219` e `ui_projects.js:709-710`
3. **erp_laravel: FALTA.** Só existe a coluna `ordem` (manual).
4. **Esforço:** Baixo, depois do #8.

## 13. Posição do organigrama na tarefa e coluna «Estrutura orgânica»
1. **O que faz:** o modal da tarefa tem o campo «Posição no organigrama do projecto». A ligação é guardada no nó (`project_org_nodes.tarefas`). A WBS e o Gantt mostram a coluna «Estrutura orgânica».
   - `ui_projects.js:2252-2258, 2460-2472, 712-737`
3. **erp_laravel: PARCIAL.** A associação existe no lado do organigrama (`OrganigramaAccoes.tsx` › `ModalAssociarTarefas`; `nos_organigrama_projeto.tarefas`). Não existe no modal da tarefa nem como coluna em `Planeamento.tsx`.
4. **Esforço:** Baixo.

## 14. Gantt melhorado
1. **O que faz:**
   - escala à escolha: Dia, Semana, Mês, Trimestre, Semestre ou Ano
   - filtro por período (de/até), que mantém os pais das tarefas que ficam
   - hierarquia visível: guias por nível, numeração WBS e a fase como cabeçalho com barra-resumo e losango da data alvo
   - abrir e fechar a árvore (tudo, só fases, fases e tarefas) e recolher uma fase
   - **quantidades por período** em cada linha, com cor de atraso
   - linha de hoje
   - colunas Precedência e Estrutura orgânica, e setas de precedência.
   - Ficheiros: `ui_projects.js:868-922` (escalas e janela, partilhadas com o mapa), `:1700-2060` (render), `:5252-5400` (`ganttEscala`, `ganttPeriodo`, `ganttQuantidades`, `ganttArvore`, `toggleGanttFase`) e `projectos_metas.js:186-246` (`periodosDaEscala`)
2. **Regras:** colunas alinhadas à unidade (semana a começar à segunda, trimestre em Jan/Abr/Jul/Out, semestre em Jan/Jul). A quantidade prevista por coluna vem de `repartirNoTempo`, proporcional aos dias úteis, com arredondamento cumulativo para a soma fechar.
3. **erp_laravel: PARCIAL.** `comum/Gantt.tsx` e `regras.ts` (`escalaGantt`, `marcasGantt` com meses ou semanas automáticos, `posicaoHoje`) dão barras, progresso e linha de hoje. Não há escala à escolha, filtro por período, recolha da árvore, quantidades, precedências nem colunas extra.
4. **Esforço:** Alto. Provavelmente compensa adoptar uma biblioteca de Gantt, ou reescrever o componente.

## 15. Mapa de quantidades
1. **O que faz:** uma tabela por unidade de medida, com fase › tarefa › subtarefa nas linhas e os períodos da escala do Gantt nas colunas. Mostra o **previsto** (meta repartida pelo calendário) e o **executado** (medições), com rodapé de totais. Tem três vistas:
   - **Quantidades**
   - **Horas**: as previstas (dias úteis × horas/dia) contra as lançadas nas folhas de horas, e o rendimento
   - **Valor**: o dinheiro do orçamento mapeado por quantidade (#16), período a período.

   Tem ainda o modo **acumulado** (curva acumulada desde o início da janela), abrir e fechar níveis, ver só tarefas, e impressão A4 em janela própria.
   - `ui_projects.js:925-1477` (`mapaQuantidades`) e `:5278-5356` (`mapaAplicarNiveis`, `mapaNo`, `mapaNiveis`, `mapaSoTarefas`, `mapaAcumulado`, `mapaVista`, `mapaQuantidadesImprimir`)
   - `projectos_metas.js:249-333` (`repartirNoTempo`, `horasPrevistasNoTempo`, `realizadoNoTempo`, `planoNoTempo`)
2. **Regras:**
   - só contam as tarefas com meta própria e sem filhos com meta, para não contar duas vezes
   - no quadro das horas entram todas as tarefas com prazo e sem filhos com prazo, e ainda as que têm horas lançadas
   - acumulado: `s_i = Σ_{k≤i} v_k`
   - se o prazo não tiver dias úteis, reparte pelos dias de calendário.
3. **erp_laravel: FALTA.**
4. **Esforço:** Alto. Depende dos itens #1, #2, #4, #14 (escala) e #16. Recomendo um endpoint backend que devolva a matriz já calculada.

## 16. Orçamento mapeado pelas quantidades
1. **O que faz:** cada rubrica do orçamento base pode ser mapeada, **numa ou em várias partes**, sobre o trabalho medido:
   - a unidade
   - o âmbito: todas as tarefas da unidade, uma fase, ou tarefas escolhidas
   - a base: a meta ou o já executado
   - o valor (só até ao **saldo por mapear**).

   Daí sai um **preço unitário**, e o valor reparte-se pelas tarefas e, através delas, pelas fases, pelas áreas do organigrama e pelas pessoas. O botão da rubrica mostra «✓» quando está toda mapeada, ou a percentagem mapeada. Por baixo da tabela aparece o quadro «Repartição do orçamento mapeado». Um mapeamento pode ser retirado, e o seu valor volta ao saldo. **O valor da rubrica não muda.**
   - `projectos_orcamento_qtd.js:1-23` (contrato dos dados), `:43-147` (regras), `:155` (`orcMapearQuantidade`), `:372` (`orcDistribuicaoQtd`) e `ui_projects.js:600-645`
2. **Regras:**
   - `preço = valor / Σ qtd(tarefas do âmbito na unidade)`, com qtd = meta ou `min(realizado, meta)`
   - `valor(tarefa) = qtd × preço`; o resto do arredondamento vai para a linha maior
   - `saldo = amount − Σ mapas.valor`
   - por área: divide em partes iguais pelas áreas da tarefa
   - por pessoa: o responsável directo leva tudo; sem ele, reparte pelos membros da área na proporção das `allocated_hours`, ou em partes iguais
   - permissão `proj_orcamento_qtd`.
3. **erp_laravel: FALTA.** `ServicoOrcamentoProjetos.php` e `separadores/Orcamento.tsx` não têm mapeamento por quantidade. O catálogo não tem `proj_orcamento_qtd`.
4. **Esforço:** Alto. Depende do #2 e do #4 (base EXECUTADO). Afecta o #17.

## 17. Organigrama: valores do ramo e orçamento por quantidade
1. **O que faz:**
   - o valor de cada posição passa a **somar as subposições** («uma chefia responde pelo que está abaixo»), usando a união das tarefas e linhas para não contar duas vezes
   - o cartão e o detalhe mostram os valores «desta posição» e «com as subposições»
   - a parte mapeada por quantidade chega à posição pelas tarefas; a parte não mapeada fica na linha directa
   - o contador «linhas sem posição» ignora o que já está mapeado.
   - Ficheiros: `projectos_organigrama.js:134-162, 172-234, 262-287, 591-653`
3. **erp_laravel: PARCIAL.** `ServicoOrganigramaProjetos.php` calcula o orçamento e o executado **só da própria posição** (tarefas + linhas directas, sem duplicar). Já existe `descendentes()`, mas não é usado para somar o ramo. Não há orçamento por quantidade.
4. **Esforço:** Médio. A soma do ramo é Baixo; a parte por quantidade depende do #16.

## 18. Carga de trabalho da equipa
1. **O que faz:** no separador Equipa, o botão «Carga de trabalho» abre uma árvore por colaborador ou entidade › fase › tarefa › subtarefa. Mostra a quantidade atribuída, as horas previstas (pelo prazo e calendário) e as lançadas. Os ancestrais com outro responsável aparecem a cinzento, só como contexto. Há também um grupo «Sem responsável». Abrir e fechar por membro ou para todos.
   - `ui_projects.js:2637-2662, 2663-2673` (`projCargaTrabalho`), `:2683-2934` (`renderCargaTrabalho`) e `:3064-3075` (`cargaAbrir`, `cargaAbrirTudo`)
2. **Regras:**
   - uma tarefa conta para um só responsável
   - se for de uma **área** do organigrama sem responsável directo, reparte-se pelos membros da área na proporção das horas/dia alocadas, ou em partes iguais
   - áreas sem gente ficam assinaladas
   - só contam as tarefas sem filhos com prazo ou meta.
3. **erp_laravel: FALTA.** `separadores/Equipa.tsx` só lista membros, horas/dia e adição em massa.
4. **Esforço:** Alto. Depende dos itens #1, #2 e #13.

## 19. Ordem de trabalho
1. **O que faz:** gera um documento de obra impresso, uma folha por pessoa, com o que há para fazer, prazos, quantidade e ritmo, agrupado por fase e com espaço para assinaturas.
   - `ui_projects.js:2935-3063` (`cargaOrdemTrabalho`)
3. **erp_laravel: FALTA.**
4. **Esforço:** Médio. Pode reaproveitar o motor de impressão e PDF já existente, como o `ImpressaoSeparador`. Depende do #18.

## 20. Separador «Execução» do projecto
1. **O que faz:** separador novo no detalhe do projecto. Para o intervalo escolhido mostra:
   - cartões: horas registadas, quantidade medida por unidade e tarefas concluídas, cada um com a parte atribuível a colaboradores internos
   - uma tabela por colaborador
   - um alerta para a execução sem dono
   - o detalhe das folhas de horas e das medições
   - botões «Folha de medição» e «Enviar para Produtividade (RH)».
   - Ficheiros: `ui_projects.js:539` (separador) e `:5715-5880` (`renderProjectExecucao`, `execFiltrar`)
3. **erp_laravel: FALTA.** `DetalheProjecto.tsx:105-113` tem Resumo, Planeamento, Kanban, Equipa, Organigrama, Orçamento, Horas, Requisições e Revisões, mas não tem Execução.
4. **Esforço:** Médio. Depende dos itens #4, #21 (recolha) e #22.

## 21. Ponte Execução de projectos → RH Produtividade
1. **O que faz:** recolhe as **horas** (folhas de horas), as **quantidades** (das `meta_execucoes`, atribuídas ao responsável da tarefa) e as **tarefas concluídas** (pela `concluida_em`) num intervalo. Agrupa por tipo, unidade e colaborador. Sugere o item de produtividade (mesma métrica e unidade equivalente, por exemplo m² = m2 = metros quadrados). O utilizador escolhe o período de produtividade e envia; um reenvio actualiza os registos em vez de duplicar. Aparece como separador «Execução de projectos» no RH › Produtividade e como assistente a partir do projecto.
   - `modules/rh/produtividade_projectos.js:57-159` (`recolher`), `:161-180` (`grupos`, `sugerir`), `:187-229` (`analisar`), `:231-244` (`enviar`), `:251-386` (UI e `prodProjectosAssistente`)
   - `produtividade_ui.js:66, 95`
   - `produtividade.js:286-287` (`origem_chave`)
2. **Regras:**
   - só entram os colaboradores elegíveis (com o item no contrato activo)
   - o período tem de estar aberto
   - a data do registo é a última medição, sem passar de hoje e ajustada ao intervalo do período
   - chave `PRJ|tipo|unidade|colaborador|item|período`
   - execução de externos ou sem responsável fica de fora, com o motivo
   - permissão `rh_prod_registar`, verificada também no `enviar`.
3. **erp_laravel: FALTA.** `ServicoProdutividade.php` já tem as métricas QUANTIDADE, HORAS, OBJECTIVO, PONTOS e TAREFAS, `elegiveis()` e `gravarRegisto(..., $origem)`. Falta a recolha a partir dos projectos, a chave de idempotência (`origem_chave`, campo `fonte`) e o ecrã. `Produtividade.tsx` não tem o separador.
4. **Esforço:** Alto. Depende dos itens #4, #22 e #23. A infra-estrutura de produtividade já existe, o que reduz o risco.

## 22. Data de conclusão da tarefa
1. **O que faz:** grava `concluida_em` quando a tarefa passa a CONCLUÍDA (no modal, no Kanban ou ao atingir a meta) e apaga-a se a tarefa sair desse estado. Reeditar uma tarefa já concluída mantém a data original.
   - `ui_projects.js:2088-2092, 2396, 2434` e `projectos_metas.js:385-387`
3. **erp_laravel: FALTA.** `tarefas_projeto` não tem a coluna. `colunasEstado` só trata `estado` e `estado_original`.
4. **Esforço:** Baixo.

## 23. Folhas de horas com o membro da equipa
1. **O que faz:** grava `team_member_id`. Antes guardava «employee_id ou id do membro» no mesmo campo, e os dois podiam colidir. Agora `employee_id` só é preenchido quando o membro é interno, o que impede que as horas de uma subempreitada entrem no subsídio de alguém.
   - `ui_projects.js:5068-5125`
3. **erp_laravel: PARCIAL.** `ServicoExecucaoProjetos::registarHoras` grava `colaborador_id` e **só aceita colaboradores internos** da equipa. A ambiguidade não existe, mas também não é possível lançar horas de membros externos ou de entidades, coisa que o legado novo passou a permitir (para a carga de trabalho e o mapa de horas). Falta `membro_equipa_projeto_id`. Atenção na migração: as folhas antigas têm o campo ambíguo.
4. **Esforço:** Baixo.

## 24. Autos e revisões só nos meses do projecto
1. **O que faz:** o selector de mês do auto ou da revisão mostra apenas os meses entre o início da primeira tarefa e o fim da última (sem tarefas, usa as datas do projecto). O valor por defeito é o mês do projecto mais próximo de hoje. A gravação volta a verificar a regra.
   - `ui_projects.js:4162-4190` (`projMesesAuto`), usado em `:4201` e `:4438`
3. **erp_laravel: FALTA.** `ServicoRevisoesProjetos::simular` e `validarMes` só verificam se o mês e o ano são válidos.
4. **Esforço:** Baixo.

## 25. Painel do projecto: equipamentos e origem
1. **O que faz:**
   - (a) cartão «Equipamentos alocados ao projecto»: por equipamento (lido do razão, rubrica `CUSTOS_EQUIPAMENTO`, documento `MAQ-<código>`), mostra categoria e estado do activo, horas (extraídas da descrição), custo por hora médio, custo imputado, período de uso, tarefas e peso no custo total do projecto
   - (b) «Origem: Método de Trabalho — assunto nº…», quando o projecto nasceu de um assunto (`projects.mt_assunto_id`, tabela `mt_assuntos`).
   - Ficheiros: `projectos_dashboard.js:381-407, 498-537, 555-558`
3. **erp_laravel:**
   - (a) **PARCIAL**: `ServicoExecucaoProjetos::equipamentos` devolve os usos linha a linha e as afectações (separador «Horas e equipamentos»), mas não o agregado por equipamento com custo por hora e percentagem do custo
   - (b) **FALTA**: não há `mt_assunto` nem Método de Trabalho no erp_laravel.
4. **Esforço:** (a) Baixo; (b) Médio, porque depende de migrar o módulo Método de Trabalho, que está fora desta área.

## 26. Contratos: itens de produtividade em massa
1. **O que faz:** um modal no RH › Contratos aplica um ou mais itens de produtividade, com o preço do item ou um preço próprio, aos contratos seleccionados (parte dos que estão marcados na lista; tem filtros por função, UN e CC). Há três modos: **acrescentar**, **substituir a lista** ou **retirar**, e uma opção para quando o contrato já tem o item (substituir ou manter o preço). Os contratos inactivos e os colaboradores sem contrato ficam de fora.
   - `modules/rh/contratos_produtividade.js:53` (modal), `:184-233` (plano e confirmação), `:235-269` (`aplicarProdutividadeEmMassa`, `aplicarRegra`)
2. **Dados:** `contracts.produtividade = [{item_id, preco_unitario | null}]`, o formato que já existia.
3. **erp_laravel: FALTA.** `ServicoContratosTrabalho.php:57-64` já grava `produtividade` contrato a contrato, mas `rh/comum/ContratosMassa.tsx` e a rota `contratos/massa` (`ronda2_g2.php:41`) só tratam **rubricas**.
4. **Esforço:** Médio. Endpoint em lote numa transacção, modal React e permissão `contratos_new`.

---

## Notas e dependências

- **Ordem sugerida:**
  1. Esquema e regras: #1, #2, #3, #22, #23 e a tabela de execuções do #4
  2. #4, #5, #7 e #9
  3. #8 e #12
  4. #16 e #17
  5. #20 e #21
  6. #14, #15, #18 e #19
  7. Os restantes: #6, #10, #11, #13, #24, #25 e #26
- **Migração de dados:** os campos novos vivem em objectos Dexie sem índice, por isso o `esquema.json` gerado do backup antigo não os conhece. O `ServicoMigracaoLegado` e o mapeamento de Projetos têm de ser actualizados para:
  - `calendario`, `meta_*`, `meta_execucoes` (para linhas), `depends_on_task_id`, `dependency_*` e `concluida_em`
  - `date_start` nas fases
  - `mapas_qtd` e o formato antigo `mapa_qtd`
  - `team_member_id` nas folhas de horas
  - `origem_chave` e `fonte` nos registos de produtividade.
- **Regras permanentes do projecto:** invalidar a cache Redis depois do commit nas gravações de tarefas, orçamento e produtividade, e aplicar OWASP: autorização no serviço e não só no botão, como o legado novo já faz em `ProjMetas.registar` e `ProdProjectos.enviar`.
- **Fora do âmbito, mas relacionado:** `modules/rh/produtividade.js` do legado novo também acrescenta `base_calculo` (UNITARIO ou PREMIO, para a métrica OBJECTIVO) e textos de fórmula por métrica. O `ServicoProdutividade.php` não tem `base_calculo`. Convém confirmar isto no relatório de RH.
