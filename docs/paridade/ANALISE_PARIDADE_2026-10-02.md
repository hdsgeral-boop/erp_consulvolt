# Análise de Paridade Funcional — Legado × Sistema Novo (2026-10-02)

> **Âmbito.** Comparação, item a item, entre o ERP legado (`payroll_system_web`, SPA JS + Dexie) e o sistema novo (`erp_laravel`, Laravel 12 + React).
> **Método.** O inventário foi construído a partir do código do legado e não só do inventário da Fase 1: ecrãs `E(...)` e tarefas `T(...)` com as funções protegidas (`js/permissoes.js`), o menu (`index_arrumado.html`, 97 entradas), funções `window.*` chamadas por `onclick`, `js/modulos_manifesto.js` e as pastas auxiliares (`ai_proxy/`, `powerbi_api/`, `servico_agt/`, `js/shared`, `js/core`).
> Cada item foi procurado no sistema novo: rota (lista completa obtida com `php artisan route:list` no contentor E2E, **767 rotas**), controller/serviço, ecrã React e chave de permissão. Todos os itens PARCIAL e EM FALTA foram **confirmados no código**.
> A análise foi só de leitura: nenhuma base de dados foi alterada e não se usaram credenciais reais.
> **Estados:**
> - **MIGRADO** — a funcionalidade está completa;
> - **PARCIAL** — falta uma parte, descrita na coluna «O que falta»;
> - **EM FALTA** — não existe no sistema novo;
> - **N/A** — não aplicável, com justificação (ADR, ecrã morto ou substituição por algo melhor).
> **Decisões que não contam como falha:**
> - rotinas destrutivas não portadas (ADR-015);
> - descontabilizar e anular = estorno (ADR-016);
> - ecrã SGD morto (ADR-063/064);
> - e-mails do CRM registados sem envio até haver SMTP (ADR-054);
> - AGT com os drivers desligado/direto/intermedio (ADR-030).

---

## Resumo executivo

**Conclusão.** A migração está completa ao nível de **ecrãs, regras de negócio e motor**: os 116 ecrãs e as 196 tarefas do catálogo existem e o backend cobre quase todo o âmbito do legado, com melhorias documentadas (estorno com rasto, numeração segura, AGT no servidor, SAF-T com hash real, segregação efectiva).
**Ainda não está tudo construído.** Ficam por fazer três tipos de lacuna:
1. **Frontend em falta sobre backend que já existe** (o servidor aceita, o ecrã não envia). É a classe mais barata e das mais importantes. Exemplos:
   - condições de pagamento e moeda nos documentos de venda;
   - pagar e receber facturas na folha de caixa;
   - saldos históricos;
   - pedido de excesso orçamental;
   - ecrã de Validações de Dados;
   - unidades de negócio;
   - textos do Relatório de Gestão;
   - avaliação da equipa pela chefia no portal;
   - erros AGT no detalhe;
   - acções do organigrama do projecto.
2. **Funções de produtividade do legado não portadas**, sobretudo nas importações Excel e nas acções em lote. Exemplos:
   - RH: importar colaboradores, contratos e cálculo; copiar o mês anterior; contratos em massa; ZIP de recibos;
   - Vendas: importar produtos; copiar documento; contabilizar em lote;
   - Tesouraria: importar documentos; rascunhos de reconciliação.
3. **Integrações e utilitários transversais inexistentes:**
   - **Power BI/OData** e **Assistente IA**: **EM FALTA**, sem ADR que os exclua;
   - ajuda contextual (F1);
   - gestor de operações em segundo plano;
   - exportação `.xlsx` genérica;
   - favoritos e pesquisa CTRL+K;
   - visões gravadas do cubo;
   - mesas do POS restaurante.

**Contagens por estado** (linhas das tabelas; cada linha agrupa as funções legadas relacionadas, o que dá cerca de 280 itens agregados sobre cerca de 600 funções do legado):

| Módulo | MIGRADO | PARCIAL | EM FALTA | N/A | Total |
|---|---:|---:|---:|---:|---:|
| 1. RH e Salários + Estrutura | 25 | 19 | 13 | 1 | 58 |
| 2. Projectos | 10 | 5 | 0 | 0 | 15 |
| 3. CRM | 7 | 4 | 0 | 0 | 11 |
| 4. Geral (início, painéis, cubo, BI, relatórios de gestão, fluxos) | 6 | 3 | 6 | 2 | 17 |
| 5. Contabilidade | 15 | 13 | 4 | 2 | 34 |
| 6. Acréscimos e Diferimentos | 5 | 0 | 0 | 0 | 5 |
| 7. Gestão Orçamental | 7 | 2 | 0 | 0 | 9 |
| 8. Tesouraria | 8 | 8 | 6 | 1 | 23 |
| 9. Compras, Armazém, Inventário, POS armazém, Activos | 16 | 11 | 2 | 3 | 32 |
| 10. Vendas, AGT, POS, Lavandaria, Hotelaria | 18 | 16 | 8 | 3 | 45 |
| 11. Sistema, Configurações e Integrações | 13 | 7 | 6 | 4 | 30 |
| **Total** | **130** | **88** | **45** | **16** | **279** |

> Leitura das contagens:
> - Muitas linhas PARCIAL dizem respeito só à exportação `.xlsx` (o novo tem impressão/PDF e, em vários mapas, CSV). Uma única peça comum resolve-as todas (lacuna M-01).

**Prioridades** (detalhe na secção 12):
- **ALTA:** 12 lacunas, todas de uso diário ou com impacto contabilístico, fiscal ou salarial. Cerca de metade é só frontend.
- **MÉDIA:** 20 lacunas.
- **BAIXA:** as restantes, indicadas nas tabelas com «(baixa)».

---

## 0. Verificação de cobertura ao nível do catálogo

- O catálogo novo (`backend/resources/permissoes/catalogo.json`) foi **gerado a partir do próprio `js/permissoes.js`**: tem 116 ecrãs e 196 tarefas, iguais ao legado. Os 116 ecrãs estão registados no React (`src/modulos/*/ecras.ts`) e abrem nos testes E2E (ADR-063/065).
- Procurámos cada chave de tarefa no código do backend (`exigir`) e do frontend (`pode`). As **13 chaves sem uso** são:

| Chave | Tarefa legada | Backend | Frontend | Conclusão |
|---|---|:-:|:-:|---|
| `colaboradores_import` | Importar colaboradores (Excel) | ✗ | ✗ | **EM FALTA** |
| `contratos_import` | Importar contratos (Excel) | ✗ | ✗ | **EM FALTA** |
| `lancamentos_editar` | Criar, copiar e editar lançamentos | ✗ | ✗ | **PARCIAL**: copiar existe (frontend); editar não existe |
| `lancamentos_bulk_notes` | Notas em massa | ✗ | ✗ | **PARCIAL**: só pela rotina «Actualização em massa» |
| `pos_armazem_acerto` | Acertar stock do armazém (POS armazém) | ✗ | ✗ | **PARCIAL**: o acerto faz-se em Armazém › Ajustes (`armazem_ajuste`) |
| `pos_print_config` | Gravar configuração de impressão | ✗ | ✓ | **PARCIAL**: preferências só no navegador (ADR-063) |
| `rh_recibos_emitir` | Emitir recibos (PDF e massa) | ✓ | ✗ | MIGRADO (impressão individual/todos); sem ZIP — ver RH |
| `lancamentos_saldos` | Importar saldos históricos | ✓ | ✗ | **PARCIAL**: o backend existe, mas nenhum ecrã o usa |
| `rh_portal_usar` | Usar o portal | ✓ | ✗ | MIGRADO (portal automático; o servidor valida) |
| `rh_limpeza_massa` | Limpar todos os processamentos | ✗ | ✗ | N/A — ADR-015 |
| `teso_reparar` | Ferramentas de reparação da tesouraria | ✗ | ✗ | N/A — ADR-015 (Sistema › Validações) |
| `acao_limpeza_massa` | Limpeza em massa e reparações de vendas | ✗ | ✗ | N/A — ADR-015 |
| `compras_rec_contabilizar` | Contabilizar recepções | ✗ | ✗ | N/A — ADR-066 (contabiliza na validação do armazém) |

> A cobertura ao nível de ecrã é total. As lacunas estão ao nível das **funções dentro dos ecrãs**, das **importações e exportações** e das **integrações** (Power BI, assistente IA).

---

## 1. RH e Salários

| Item legado | Legado (ficheiro / função) | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Colaboradores: criar, editar, ficha (agregado, habilitações, documentos) | `app_v2.js:3833`, `modules/rh/ficha_colaborador.js` | `GET/POST/PUT/DELETE /api/rh/colaboradores`, `ColaboradorController`, `Colaboradores.tsx` (ficha imprimível com agregado e habilitações) | MIGRADO | — |
| Eliminar colaborador | `deleteEmployee` | `DELETE /api/rh/colaboradores/{id}` (só sem utilizações) | MIGRADO | — |
| **Importar colaboradores (Excel, chave NIF, actualizar existentes)** | `importEmployeesExcel`, `modules/rh/importar_colaboradores.js`, template `Template_Colaboradores.xlsx` | — (as entidades de `ServicoMigracaoDados::ENTIDADES` são só plano_contas, diarios, terceiros, bancos, cargos, infotipos) | **EM FALTA** | Endpoint de importação, modelo `.xlsx` e ecrã; a tarefa `colaboradores_import` não é usada |
| Exportar colaboradores (Excel) | `app_v2.js` (XLSX) | Só impressão da lista | PARCIAL | Exportação CSV/XLSX da lista |
| Contratos: criar, editar, terminar, eliminar | `saveContract`, `editContract`, `deleteContract` | `/api/rh/contratos` (+`terminar`), `Contratos.tsx` | MIGRADO | — |
| Eliminar contratos seleccionados | `deleteSelectedContracts` | Eliminação individual | PARCIAL | Selecção múltipla (baixa) |
| **Contratos em massa / aplicar rubricas em massa** | `contratosEmMassa`, `aplicarRubricasEmMassa` (`modules/rh/contratos_massa.js`) | — | **EM FALTA** | Acção que acrescenta/substitui rubricas com o mesmo valor em N contratos e cria contratos para quem não tem |
| **Importar contratos (Excel vertical)** | `importContractsExcel`, `Template_Contratos_Vertical.xlsx` | — | **EM FALTA** | Endpoint, modelo e ecrã; a tarefa `contratos_import` não é usada |
| **Simular massa salarial a partir dos contratos** e imprimir o relatório de contratos | `ui_simulate_contracts.js` (`simulateContractsPayroll`, `printContractsReport`) | — (não há «simula» em `Services/RH` nem em `modulos/rh`) | **EM FALTA** | Simulação sem período: mapas e recibos simulados a partir dos contratos activos |
| Simular um colaborador no cálculo | `simularColaborador` (`app_v2.js:5570`) | Separador «Resultados» do período aberto (`GET /rh/salarios/periodos/{id}`, cálculo vivo) | MIGRADO | — |
| Funções e categorias | `saveRole`, `deleteRole` | `/api/rh/cargos`, `/api/rh/tipos-organizacao`, `Funcoes.tsx` | MIGRADO | — |
| Rubricas (infotipos), incl. importação | `saveInfotype`, `importInfotypesExcel` | `/api/rh/infotipos`, importação em `sistema/migracao/importar/infotipos` | MIGRADO | — |
| Coordenadas bancárias e bancos (incl. importação) | `saveBank`, `saveEmployeeIBAN`, `importBanksExcel` | `/api/rh/bancos`, `/api/rh/colaboradores/{id}/coordenada-bancaria`, importação de bancos na Migração | MIGRADO | Importação de IBAN dos colaboradores em massa (baixa) |
| Calcular: abrir período, lançar e editar rubrica | `editEntry`, `saveEditedEntry` | `/rh/salarios/periodos` (+`lancamentos`), `Calcular.tsx` | MIGRADO | — |
| Lançar em lote | `applyBatchEntries` | «Lançar em lote» em `Calcular.tsx` (um pedido por colaborador) | MIGRADO | — |
| Editar em lote os lançamentos seleccionados | `bulkEditSelectedEntries` | — | PARCIAL | Alterar o valor de N lançamentos seleccionados de uma vez |
| **Copiar lançamentos do mês anterior** | `copyPreviousEntries` | — (sem rota nem botão; o menu «Importar» tem contratos, efectividade e produtividade) | **EM FALTA** | `POST /rh/salarios/periodos/{id}/copiar-anterior` |
| Importar dos contratos / efectividade / produtividade | `importFromContracts`, botão «Importar Efectividade», `prodLancarPeriodo` | `importar-contratos`, `importar-efectividade`, `importar-produtividade` | MIGRADO | — |
| **Importar o cálculo de Excel** | `importCalculoExcel` | — | **EM FALTA** | Importação colaborador × rubrica × valor a partir de `.xlsx` |
| Eliminar lançamentos / seleccionados | `deleteEntry`, `deleteSelectedPayrollEntries` | `DELETE .../lancamentos/{id}` | PARCIAL | Eliminação múltipla (baixa) |
| Eliminar período aberto | `deleteOpenPeriod` | — (não há `DELETE /rh/salarios/periodos/{id}`) | EM FALTA | Eliminar um período aberto sem lançamentos validados (baixa) |
| Fechar cálculo, validar, reabrir, integrar, descontabilizar | `closeCalculationPeriod`, `validarProcessamento`, `reopenPeriod`, `integratePayrollToJournal` | `encerrar`, `validar`, `reabrir`, `contabilizar`, `descontabilizar` (estorno) | MIGRADO | — |
| Limpar todos os processamentos | `clearPayrollData` | — | N/A | ADR-015 |
| Pagamento dos salários na Tesouraria e cartas | `folha_salarios.js` | `/rh/salarios/periodos/{id}/cartas`, `/rh/salarios/cartas/{id}/pagamento` | MIGRADO | — |
| Folhas separadas Colaboradores/Avençados e mapa detalhado com todas as rubricas | `folha_salarios.js` | `Mapas.tsx` (`MapaRemuneracoes` com colunas por rubrica; grupos) | MIGRADO | — |
| Mapa de remunerações, IRT, INSS, salários a pagar, ordem bancária | `app_v2.js:7013` | `relatorios/Mapas.tsx` (impressão A4/A3 e CSV) | MIGRADO | Exportação `.xlsx` (o legado exportava a tabela para Excel); CSV cobre o essencial |
| Recibos: individual, seleccionados, todos | `generatePDFRecibo`, `generateSelectedPDFRecibos`, `generateAllPDFRecibos` | `MapaRecibos` (individual ou todos, um por página, PDF pelo navegador) | PARCIAL | **Ficheiro ZIP com um PDF por colaborador** (`js/shared/janela_processo.js`), em processo de fundo |
| Mapeamento contabilístico de salários (editar, copiar, recuperar do histórico) | `saveAllAccountingMapos`, `executeCopyAccountingMapos`, `recoverMaposFromHistory` | `GET/PUT /rh/mapeamentos-contabeis`, `contab/MapeamentoSalarios.tsx` | PARCIAL | Copiar o mapeamento para outra empresa (há cópia de estrutura no clone de empresa) e recuperar do histórico |
| Efectividade: registo manual, ficheiro, fecho/reabertura, configuração | `assidGravarRegisto`, `assidImportar`, `assidFecharMes`, `assidGravarConfig` | `/rh/assiduidade/*`, `Assiduidade.tsx` | MIGRADO | — |
| **Importar directamente do relógio biométrico (URL)** | `assidImportarRelogio` (`assiduidade.js:436`, `fetch(url)`) | O URL é guardado (`ServicoCalendarioRH`), mas nenhum código o lê | PARCIAL | Leitura do relógio pelo servidor (HTTP), com o mesmo parser da importação de ficheiro |
| Ausências (registar, justificar, decidir, cancelar) | `modules/rh/ausencias.js` | `/rh/assiduidade/ausencias*` | MIGRADO | — |
| Produtividade (itens, períodos, registos, importação Excel) | `prodGravarItem`, `prodGravarPeriodo`, `prodImportar` | `/rh/produtividade/*`, `Produtividade.tsx` | MIGRADO | — |
| Férias (planear, aprovar, estados) | `modules/rh/ferias.js` | `/rh/ferias` (+`estado`), `Ferias.tsx` | MIGRADO | — |
| Avaliação de desempenho e itens | `avaliacao.js` | `/rh/avaliacao/avaliacoes`, `/itens` | MIGRADO | — |
| Ciclos 360º, parecer, bonificações (calcular, aprovar, lançar, anular) | `aval360_*.js` | `/rh/avaliacao/ciclos/*`, `bonificacoes/*` | MIGRADO | — |
| Portal do colaborador (férias, ausências, documentos, agregado, autoavaliação, ascendente, recibos) | `portal_*.js` | `/rh/portal/*`, `Portal.tsx`, `PortalAvaliacao.tsx` | MIGRADO | — |
| Pedidos do portal (decidir, emitir, modelos, ligar utilizadores) | `portalAprovarEmitir`, `portalGravarModelo`, `portalLigarUtilizador` | `/rh/portal/pedidos/*`, `/modelos`, `/ligacoes`, `PortalGestao.tsx` | MIGRADO | — |
| Fluxo RH no Fluxo de Processos | `fluxo_rh.js` | `/gestao/fluxos/{fluxo}` | MIGRADO | — |

**Achados complementares (confirmados no código):**

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| **Recibo de salário: 2 vias (Original/Duplicado), valor por extenso, forma de pagamento/IBAN** | `app_v2.js:7623, 7655, 7780` | `rh/comum/ReciboSalario.tsx`: só o mês por extenso; sem vias, sem valor por extenso, sem IBAN. O mesmo componente serve o portal | PARCIAL | Duas vias por página, líquido por extenso, banco/IBAN e forma de pagamento |
| Ficha do colaborador: definir UN e CC | `emp-bu`, `emp-cc` (`app_v2.js:9003-9068`) | O backend aceita-os (`ColaboradorController.php:100`) e a ficha mostra-os, mas o formulário não tem os campos | PARCIAL | Campos UN/CC no formulário (afectam a imputação analítica dos salários) |
| Corrigir mapeamentos em falta e repetir a integração | `showPayrollMappingFixer`, `saveAndRetryPayrollIntegration` (`app_v2.js:10326`) | O backend devolve `MAPEAMENTO_EM_FALTA` com a lista `em_falta` (`ServicoFolhaSalarial.php:327`); o frontend só mostra a mensagem | PARCIAL | Assistente que lista as rubricas/contas em falta, permite mapeá-las e volta a contabilizar |
| Pré-visualizar o lançamento dos salários / ir para o Diário | `app_v2.js:6548-6630` | `Processamento.tsx` mostra só o n.º | PARCIAL | Ligação ao lançamento (baixa) |
| Lançamento em lote com várias rubricas e rubricas de horas | `applyBatchEntries` (`app_v2.js:5224`) | Uma rubrica por lote; as rubricas EXTRA/FALTA são excluídas (`Calcular.tsx:240`) | PARCIAL | Várias rubricas e horas no mesmo lote |
| Imprimir a simulação de um colaborador / do período com todas as rubricas | `folha_salarios.js:206, 221` | Impressão da folha só com totais; o detalhe está no Mapa de remunerações | PARCIAL | Impressão individual da simulação (baixa) |
| Contrato de trabalho em PDF (cláusulas) | `generateContractPDF` (`app_v2.js:4722`) | — (os modelos do portal são só declarações e certificado) | EM FALTA | Modelo de contrato imprimível |
| Produtividade: importar Excel e modelo | `prodImportar` (`produtividade_ui.js:241`) | — (ADR-039: «fica para depois») | EM FALTA | Importação dos registos do período |
| Copiar funções/infotipos de outra empresa (já existente) | `openCopySimpleTableModal` (`app_v2.js:4850`) | Só o clone para empresa nova ou vazia | EM FALTA | Cópia selectiva (baixa) |
| Relatórios separados Colaboradores/Avençados (remunerações, INSS, salários a pagar) | `app_v2.js:7065`, `folha_salarios.js:262-298` | Só o IRT separa os grupos A/B; o CSV do IRT só exporta o grupo A | PARCIAL | Filtro de grupo em todos os mapas; CSV do grupo B |
| Mapa IRT (colunas por rubrica, «Período», «Outras deduções», «A pagar/Reembolso»); INSS com base dividida; remunerações com UN/CC | `app_v2.js:7280-7420` | Colunas reduzidas | PARCIAL | Colunas em falta. Nem o legado nem o novo geram o ficheiro de declaração AGT/INSS |
| Salários a pagar: exportar | `view-salarios-pagar` (Excel) | Só impressão | PARCIAL | CSV/XLSX (baixa) |
| Chefia avalia a equipa e regista reuniões de acompanhamento no portal | `avaliacaoGravarChefia`, `aval360RegistarFeedback` | O backend aceita (`AvaliacaoController.php:76, 227`; `POST /rh/avaliacao/feedbacks`), mas não há ecrã (só «confirmar») | **EM FALTA (frontend)** | Separador «A minha equipa» no portal: avaliar e registar reuniões |
| Pedidos do Portal (RH): separadores «Autoavaliações» e «Avaliação das chefias» (anónima) | `portal_ui.js:615` | `PortalGestao.tsx` só tem Pedidos, Ligações e Modelos; `GET /rh/avaliacao/ascendente/{colaborador}` e `/autoavaliacao` não são usados pelo RH | EM FALTA (frontend) | Os dois separadores, sobre rotas que já existem |
| Férias, avaliação, mapa de pessoal: exportar Excel | `ferias.js:589`, `avaliacao.js:1033`, `estrutura_ui.js:714` | Impressão/PDF ou CSV | PARCIAL | `.xlsx` (baixa) |

### 1.1 Estrutura Orgânica

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Unidades e cargos (criar, editar, eliminar), afectar colaboradores | `estGravarUnidade`, `estGravarCargo`, `estAfectar`, `estEliminar*` | `/rh/estrutura/unidades`, `/postos`, `/afectacao`, `/rh/cargos`, `Estrutura.tsx` | MIGRADO | — |
| Criar várias unidades de uma vez | `estGravarUnidades` | Uma de cada vez | PARCIAL | Criação múltipla (baixa) |
| Criar estrutura base | `estCriarBase` | — | EM FALTA | Modelo de estrutura de partida (baixa) |
| Organigrama funcional/nominal, impressão A4 horizontal | `estrutura_ui.js` | `Organigrama.tsx` (impressão A4/A3) | PARCIAL | Disposição em coluna e reordenar ramos (`estDisposicao`, `estArrumar`) (baixa) |
| Mapa de pessoal com massa salarial (`est_ver_salarios`) | `est_mapa` | `GET /rh/estrutura/mapa`, `MapaPessoal.tsx` | MIGRADO | — |

---

## 2. Projectos

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Carteira, criar/editar (INTERNO/EXTERNO, cliente → encomenda), estado | `ui_projects.js:210-396`, `changeProjectState` | `/projetos` (+`estado`), `Carteira.tsx`, `ModalProjecto.tsx` | MIGRADO | — |
| Ficha com separadores e resumo (KPIs, curva S, alertas, impressão) | `viewProjectDetails`, `projectos_dashboard.js` | `/projetos/{id}`, `/resumo`, `DetalheProjecto.tsx`, `Resumo.tsx` | MIGRADO | — |
| WBS: milestones, tarefas/subtarefas, % execução, eliminar com bloqueios | `saveMilestone`, `saveTask`, `updateTaskExecution`, `projEliminar*` | `/marcos`, `/tarefas`, `/tarefas/{t}/execucao`, `Planeamento.tsx` | MIGRADO | — |
| WBS: arrastar e reordenar tarefas | `projMoverTarefa`, `projLigarArrastoWBS` | Backend `POST /projetos/{id}/tarefas/{t}/mover` existe, mas `Planeamento.tsx` não o usa | PARCIAL | Arrastar/reordenar no ecrã |
| WBS: expandir/recolher tudo | `expandAllWBS`, `collapseAllWBS` | Expansor por linha | PARCIAL | Botões globais (baixa) |
| Gantt do projecto, Kanban com colunas configuráveis | `renderProjectPlaneamento`, `saveKanbanColumns` | `Gantt.tsx`, `/kanban` (+`mover`, `colunas`), `Kanban.tsx` | MIGRADO | — |
| Equipa: membro, remover/alterar em massa | `saveTeamMember`, `removeTeamMembersBulk`, `updateTeamMembersBulk` | `/equipa/membros*`, `Equipa.tsx` | MIGRADO | — |
| Equipa: adicionar em massa com filtros e terceiros | `projEquipaEmMassa`, `saveTeamMembersBulk` | `POST /equipa/membros/massa` aceita TERCEIRO; o ecrã envia sempre INTERNO | PARCIAL | Terceiros em massa e filtros por função/UN/CC/tipo |
| Organigrama: posições, subir/descer, eliminar, alocar, modelo base | `projOrgGravarPosicao`, `projOrgArrumar`, `projOrgAlocar`, `projOrgModeloBase` | `/organigrama/*`, `Organigrama.tsx` | MIGRADO | — |
| Organigrama: várias posições em lote; associar tarefas; mapear orçamento; disposição | `projOrgGravarPosicoes`, `projOrgAssociarTarefas`, `projOrgMapearOrcamento`, `projOrgDisposicao` | Backend existe (`posicoes/lote`, `posicoes/{p}/tarefas`, `organigrama/orcamento`, `organigrama/disposicao`), sem ecrã | PARCIAL | 4 acções de ecrã sobre rotas já feitas |
| Organigrama: diagrama em caixas, orientação vertical/horizontal, detalhe da posição | `projOrgOrientacao`, `projOrgDetalhe` | Árvore indentada (`Tree`) | PARCIAL | Diagrama gráfico, orientação e detalhe com tarefas e orçamento |
| Orçamento base, orçado × executado, aditamentos | `saveBudgetLine`, `saveChangeOrder` | `/orcamento`, `/custos-tarefas`, `/aditamentos`, `Orcamento.tsx` | MIGRADO | — |
| Requisições de material, folha de horas, equipamentos | `saveRequisition`, `saveTimesheet`, `saveEquipmentLog` | `/requisicoes`, `/horas`, `/equipamentos` | MIGRADO | — |
| Revisão de preços / auto de medição (simular, executar, imprimir, facturar) | `executeReview`, `printReview`, `billReview`, `confirmAutoBill` | `/revisoes*`, `Revisoes.tsx` | MIGRADO | — |
| Extracto analítico, Gantt global, fluxo | `renderProjectExtract`, `renderGlobalGantt`, `fluxo_projectos.js` | `/projetos/extracto`, `/projetos/gantt`, `/gestao/fluxos/projetos` | MIGRADO | — |

## 3. CRM

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Pipeline Kanban, mover etapa (motivo de perda), oportunidade com linhas, ficha | `crm_ui.js:119-314` | `/crm/funis/{f}/quadro`, `/crm/oportunidades*`, `Pipeline.tsx`, `Oportunidade.tsx` | MIGRADO | — |
| E-mail a partir de modelo; campanhas um a um/Bcc | `crmEnviarEmail`, `crm_ui_gestao.js:355` | `/crm/emails*`, `/crm/campanhas/enviar` | MIGRADO | Sem SMTP (ADR-054, decisão) |
| Converter em proposta/encomenda/factura; prospect → cliente | `crmConverter` | `/oportunidades/{o}/conversao`, `/contas/{c}/converter-em-cliente` | MIGRADO | — |
| Contas, contactos, ficha 360º | `crm_ui_gestao.js:25-129` | `/crm/contas*`, `Contas.tsx` | MIGRADO | — |
| Contas: filtro «Só com facturas em atraso» | `crm_ui_gestao.js:34` | `ContasCRMController@index` só filtra tipo, responsável e texto | PARCIAL | Filtro de atraso (baixa) |
| Agenda (atraso, hoje, próximos N dias) | `renderCrmAgenda` | `/crm/agenda`, `Agenda.tsx` | MIGRADO | — |
| Agenda: «As minhas» / «Minha equipa» (estrutura orgânica) | `crm_ui_gestao.js:194-205` | Texto livre do responsável | PARCIAL | Filtros por subordinados (baixa) |
| Previsão e indicadores | `renderCrmPrevisao` | `/crm/previsao`, `/crm/indicadores`, `Previsao.tsx` | MIGRADO | — |
| Previsão: exportar Excel (4 folhas) | `exportarPrevisao` | Só impressão/PDF | PARCIAL | Exportação (baixa) |
| Campanhas: exportar CSV personalizado | `crm_ui_gestao.js:379` | CSV com Conta, Tipo, Email e Assunto | PARCIAL | Contacto e mensagem personalizada; registo no histórico (baixa) |
| Configuração: funis, etapas, motivos, origens, modelos, sequências | `renderCrmConfig` | `/crm/funis`, `/crm/configuracao`, `/crm/modelos-email`, `/crm/sequencias`, `ConfigCRM.tsx` | MIGRADO | — |

## 4. Geral (início, painéis, cubo, BI, relatórios de gestão, fluxos)

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Página inicial: empresa, 16 pendentes, dica do dia, comunicado 360º, grelha de módulos | `ui_dashboard.js:621-860` | `/gestao/inicio`, `Inicio.tsx` | MIGRADO | — |
| Favoritos (estrela, barra de favoritos) | `ui_dashboard.js:576-942`, `toggleFavorite` | — | EM FALTA | Favoritos por utilizador (persistidos no servidor) |
| Reordenar módulos | `ui_dashboard.js:942` (Sortable) | — | EM FALTA | Ordem personalizada (baixa) |
| Pesquisa global de ecrãs (CTRL+K) | `ui_dashboard.js:1008` | — | EM FALTA | Pesquisa de ecrãs com atalho |
| Ecrã cheio (painel, BI, cubo) e apresentação dos fluxos (← →, N) | `toggleFullscreen`, `fluxo_narrativa.js:8` | — | EM FALTA | Modo ecrã cheio/apresentação (baixa) |
| Painéis de 13 módulos, filtros, KPIs clicáveis, comparação de empresas | `ui_painel_modulos.js` | `/gestao/paineis*`, `Dashboard.tsx` | MIGRADO | — |
| Análise dinâmica (cubo) | `ui_cubo.js:350-485` | `/gestao/cubo/*`, `AnaliseDinamica.tsx` (no servidor) | MIGRADO | — |
| Cubo: guardar/aplicar/apagar visões; visão padrão | `cuboGuardarVisao`, `cuboAplicarVisao`, `cuboApagarVisao` | — | EM FALTA | Visões gravadas no servidor por utilizador (risco 14 do inventário) |
| Cubo: exportar Excel | `cuboExportar` | CSV, impressão, PDF | PARCIAL | `.xlsx` (baixa) |
| BI contabilístico | `ui_bi.js` | `/gestao/bi*`, `AccountingBI.tsx` | MIGRADO | — |
| Relatórios de gestão A×B, resumo, 9 módulos, imprimir separador | `relatorios_gestao.js:662` | `/gestao/relatorios*`, `RelatoriosGestao.tsx` | MIGRADO | — |
| Relatórios de gestão: «Imprimir todos» | `imprimir([...todos])` | Rota `GET /gestao/relatorios/todos` sem uso no ecrã | PARCIAL | Botão no ecrã (P) |
| Relatórios de gestão: exportar Excel | `exportarExcel` (`:876`) | — | EM FALTA | Exportação CSV/XLSX |
| Fluxo de Processos: 14 fluxos, funil, detalhe, narrativas | `fluxo_processos.js`, `fluxo_*.js` | `/gestao/fluxos*`, `FluxoProcessos.tsx` | MIGRADO | — |
| Imprimir workflow completo com narrativa | `fluxo_narrativa.js:278` | Impressão por partes | PARCIAL | Documento único (baixa) |
| Gestão documental (`sgd`) | `app_v2.js:1166` | — | N/A | Ecrã morto (ADR-063/064) |
| Dashboard de salários antigo; carregar/libertar o cubo | `ui_dashboard.js:7`; `cuboLibertar` | — | N/A | Substituídos (ADR-059) |

---

## 5. Contabilidade

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Criar lançamento manual (D = C, diário, notas DEMO/fluxo, UN, CC, terceiro) | `saveJournalEntry` | `POST /contabilidade/lancamentos`, `NovoLancamento.tsx` (gravar bloqueado enquanto desequilibrado) | MIGRADO | — |
| Copiar lançamento | `copyJournalEntry` | «Copiar» em `DetalheLancamento.tsx` (abre o formulário pré-preenchido) | MIGRADO | — |
| **Editar lançamento gravado (cabeçalho e linha: conta, terceiro, notas, descrição)** | `editFullJournalEntry`, `editJournalLine`, `saveJournalLine` (`ui_lancamentos.js:2087-2160`) | Não há `PUT /contabilidade/lancamentos/{id}`. A correcção faz-se por estorno + novo lançamento, ou pela rotina «Actualização em massa» a partir de ficheiro (`ServicoRotinasContabeis::CAMPOS_ACTUALIZAVEIS`: descrição, conta, terceiro, diário, notas) | PARCIAL | Edição dos campos **não financeiros** (descrição, notas DEMO/fluxo, UN, CC, terceiro) na própria ficha, com auditoria. A tarefa `lancamentos_editar` não é usada |
| **Notas em massa sobre as linhas filtradas/seleccionadas (DEMO, fluxo, UN, CC)** | `applyBulkNotes` (`ui_lancamentos.js:2618`) | Só a rotina «Actualização em massa» (ficheiro, tarefa `contab_rotinas_exec`), sem UN nem CC | PARCIAL | Seleccionar linhas na lista de lançamentos ou em «movimentos sem nota» e aplicar nota/UN/CC (tarefa `lancamentos_bulk_notes`). **Afecta directamente o Balanço, a DR e o Fluxo de Caixa**, que são construídos por notas |
| Importar lançamentos (Excel) | `importJournalExcel` | `POST /contabilidade/lancamentos/importar` (simulação + gravação) | MIGRADO | — |
| **Saldos históricos (por ano: editar, importar Excel, totais)** | `showImportHistoryModal`, `saveHistoricalBalances` (`ui_lancamentos.js:1186-1409`) | Backend `GET/PUT /contabilidade/saldos-historicos/{ano}` (+`importar`, `ServicoSaldosHistoricos`); **nenhum ecrã chama estas rotas** | PARCIAL | Ecrã (modal no Diário ou separador) e modelo. Os saldos históricos alimentam o comparativo N-1 e o Balanço das empresas com histórico anterior ao sistema |
| **Lançamento manual em moeda estrangeira** | `moedas_lancamentos.js:24-284` | `ServicoLancamentos.php:81-83` grava `codigo_moeda`/`valor_moeda`/`taxa_cambio`, mas `CriarLancamentoRequest.php` não os aceita e o `EditorLinhasDC.tsx` não tem os campos | EM FALTA | Moeda, câmbio e valor na moeda por linha, com equilíbrio na moeda (contas da classe 4 com moeda) |
| Filtros avançados da lista (período fiscal, data de criação, referência, linhas sem nota/CC/UN, colar lista, vistas) | `ui_lancamentos.js:38, 2459, 2499` | Filtros base em `ListaLancamentos.tsx` | PARCIAL | Filtro «sem nota/CC/UN» (necessário para as notas em massa) e os restantes (baixa) |
| Estorno em lote dos seleccionados | `unpostSelectedLines` | Estorno documento a documento | PARCIAL | Selecção e estorno em lote (baixa) |
| Ligação do lançamento ao documento de origem | `goToSourceDocument` (`ui_lancamentos.js:2386`) | Só a etiqueta `tipo_origem` | PARCIAL | Navegação ao documento (baixa) |
| Estornar lançamento | `reverseJournalEntry` | `POST /lancamentos/{id}/estornar` (com motivo) | MIGRADO | — |
| **Transferir lançamento para outra empresa** | `transferJournalEntry`, `confirmTransferJournalEntry` (`ui_lancamentos.js:1022`) | — | **EM FALTA** | Estorno na origem + criação na empresa destino (com mapeamento de diário/terceiros), numa operação auditada |
| Anular/eliminar linhas, limpar lançamentos | `unpostJournalEntry`, `unpostSelectedLines`, `clearJournalLines` | Estorno | N/A | ADR-016 |
| Reciclagem (restaurar, eliminar, esvaziar) | `restoreRecycledJournal`, `emptyRecycleBin` | `/contabilidade/reciclagem*` | MIGRADO | — |
| Encerramento: 5 apuramentos (período 13), validações, reabrir, cancelar | `processarApuramento*`, `abrirExercicio`, `cancelarApuramento` | `/contabilidade/encerramento/{ano}/*`, `Encerramento.tsx` | MIGRADO | — |
| Extracto de conta corrente com compensação e regularização | `compensacteSelectedLines`, `applyRegularization` | `/relatorios/extrato`, `/compensacoes` (+`regularizar`), `MapaExtrato.tsx` | MIGRADO | — |
| Reverter compensações (uma ou em lote) | `deleteAccountingReconciliation`, `deleteBatchReconciliations` | `DELETE /compensacoes/{codigo}` | PARCIAL | Reverter várias de uma vez (baixa) |
| Balancete, razão, IVA, balanço (com comparativo), DR, fluxo de caixa, evolução mensal | `ui_reports.js` | `/contabilidade/relatorios/*`, `mapas/*.tsx` (impressão A4/A3, CSV, filtro de contas `21, 24-26, *x` em `FiltroMapas.php`) | MIGRADO | Exportação `.xlsx` formatada (o legado usava `table_to_book`); o CSV cobre os dados (baixa) |
| Alerta de desequilíbrio e «Movimentos por mapear» com drill-down | `ui_reports.js:381-408, 1049-1080` | `/relatorios/desequilibrios`, `/relatorios/movimentos-sem-nota`, `/relatorios/notas/{tipo}/{nota}` | MIGRADO | — |
| Reconciliação de IVA com a AGT: importar e comparar | `processReconciliacaoAGT` | `POST /relatorios/iva/reconciliacao-agt` (`ServicoReconciliacaoIvaAgt`), em `MapaBalancete.tsx` | MIGRADO | — |
| Reconciliação AGT: guardar, histórico, eliminar, imprimir | `saveReconciliacao`, `deleteReconciliacao`, `printReconciliacao` (histórico no `localStorage`) | O resultado não fica guardado | PARCIAL | Gravar as reconciliações por mês no servidor (histórico consultável e imprimível) |
| Relatório e Contas (configurar, calcular, gravar, concluir, reabrir; rácios) | `relatorio_contas.js` | `/contabilidade/relatorio-contas/{ano}*`, `RelatorioContas.tsx` | MIGRADO | — |
| Rotinas: capitalização de obras, compensação identificada, actualização em massa (Excel), transferência de saldos, anular | `executarCapitalizacaoObras`, `executarCompensaçãoIdentificada`, `processarRotinaAtualizacação`, `processarTransferenciaSaldos`, `anularRotina` | `/contabilidade/rotinas/*`, `Rotinas.tsx` | MIGRADO | — |
| Limpar reconciliações da tesouraria | `limparReconciliacoesTesouraria` | `GET /rotinas/limpeza-reconciliacoes` (relatório) | N/A | ADR-015 |
| Imposto de Selo | `gerarLancamentoSelo` | `/rotinas/imposto-selo` (+histórico), `ImpostoSelo.tsx` | MIGRADO | — |
| Consolidação (grupos, executar, mapa, eliminações, conversão cambial) | `consolidacao.js` | `/consolidacao/*`, `Consolidacao.tsx` | MIGRADO | — |
| Tabelas auxiliares: diários, notas DEMO/fluxo, centros de custo (CRUD, importar, copiar, enviar a outra empresa, sincronizar CC) | `saveJournal`, `saveDemoNote`, `saveCashflowNote`, `saveCostCenter`, `importAuxExcel`, `executeCopyTable`, `sendAuxToOtherCompany`, `syncCostCentersFromAccounts` | `/contabilidade/tabelas/{tabela}*` (+`importar`, `copiar`, `{id}/enviar`), `/tabelas/centros-custo/sincronizar`, `TabelasAux.tsx` | MIGRADO | — |
| Terceiros | `saveThirdParty` | `/terceiros`, `GestaoTerceiros.tsx` | MIGRADO | Copiar/enviar terceiros para outra empresa (as rotas `tabelas/{t}/copiar|enviar` excluem terceiros) (baixa) |
| **Unidades de negócio (gestão)** | `saveBusinessUnit`, `modules/configuracoes/unidades_negocio.js` | Backend `/sistema/unidades-negocio` (CRUD); no frontend só existe o selector (`contab/comum/dados.ts:44`) | PARCIAL | Ecrã de gestão (em Tabelas auxiliares ou Configurações) |
| **Relatório e Contas: Relatório de Gestão (textos automáticos editáveis, repor, gráficos)** | `relatorio_contas.js:993-1128` (`rcMarcarEditado`, `rcReporAuto`) | O backend guarda `textos` (`ServicoRelatorioContas.php:84-95`); `RelatorioContas.tsx` não tem editor nem separador | EM FALTA (frontend) | Separador «Relatório de Gestão» com textos gerados, edição e reposição, incluídos na impressão |
| Demonstrações: colunas AV%/AH%; mostrar linhas a zero | `ui_reports.js:376, 1026-1108` | Só as colunas ano e anterior | PARCIAL | Análise vertical e horizontal (baixa) |
| Holding: quebra por empresa de origem nos mapas; restrição do menu na holding | `ui_reports.js:235`, `consolidacao.js:74-342` | `empresa_origem_id` existe mas não é usado; o menu não se restringe | PARCIAL | Filtro/coluna por empresa de origem e menu da holding (baixa) |
| Modelos de importação (lançamentos, notas, centros de custo); actualização em massa por `.xlsx` | `downloadJournalTemplate`, `downloadAuxTemplate`, `downloadRotinaTemplate` | Importação sem modelo; a actualização em massa é por texto colado | PARCIAL | Modelos `.xlsx` (baixa) |
| Mapeamento contabilístico de salários | ver §1 | `/rh/mapeamentos-contabeis` | PARCIAL | Copiar para outra empresa / recuperar do histórico |
| Agente IA nos lançamentos | `ui_lancamentos.js:456` → `openAIDocumentAgent` | — | **EM FALTA** | Ver §11 (Integrações) |

## 6. Acréscimos e Diferimentos

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Registos: criar, editar, regularizar, terminar, eliminar, desfazer pedido | `adGravarItem`, `adRegularizar`, `adTerminar`, `adEliminarItem`, `adDesfazerPedido` | `/acrescimos/itens*` | MIGRADO | — |
| Definições (contas 37 e diário) | `adGravarDefinicoes` | `/acrescimos/definicoes` | MIGRADO | — |
| Proposta mensal, contabilizar e descontabilizar | `adContabilizar`, `adDescontabilizar` | `/acrescimos/proposta` (+`contabilizar`), `/lancamentos/{id}/descontabilizar` (estorno) | MIGRADO | — |
| Recolher documentos (compras, vendas, Diário, tesouraria) | `ad_recolher` | `/acrescimos/recolha`, `/recolha/acrescimos-abertos` | MIGRADO | — |
| Reconciliação com as contas 37 | `ad_ui.js` | `/acrescimos/reconciliacao` | MIGRADO | — |

## 7. Gestão Orçamental

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Rubricas (ligação às contas, rubricas base) | `orcGravarRubrica`, `orcCriarRubricasBase` | `/orcamento/rubricas` (+`base`) | MIGRADO | — |
| Orçamentos: criar, valores, nova versão, eliminar rascunho, submeter, aprovar, devolver | `orcNovoOrcamento`, `orcGravarValores`, `orcNovaVersao`, `orcSubmeter`, `orcAprovar`, `orcDevolver` | `/orcamento/orcamentos*` | MIGRADO | — |
| Top-down, contributos bottom-up, consolidar | `orcRepartirTopDown`, `orcPedirContributos`, `orcGravarContributo`, `orcConsolidar` | `repartir`, `contributos`, `consolidar` | MIGRADO | — |
| Controlo orçado × realizado, análise de desvios | `orc_controlo`, `orcAnalisarDesvio` | `/controlo`, `/desvios/{rubrica}` | MIGRADO | — |
| Controlo nos documentos (`OrcControlo.validar` em compras, lançamentos e tesouraria) | `orcamento_planeamento.js:135, 483` | O controlo é feito no servidor (`ServicoControloOrcamental`, ADR-045); `/alertas`, `/monitor`, decisão em `Alertas.tsx` | MIGRADO | — |
| **Pedir aprovação de excesso no acto do documento** | `pedirAprovacao` (`orcamento_planeamento.js:534`; diálogo em `orcamento_planeamento_ui.js:90-97`) | O backend devolve `ORCAMENTO_EXIGE_APROVACAO` e existe `POST /orcamento/pedidos-excesso`, mas nenhum ecrã de documento o chama (só `Alertas.tsx`, que lê e decide) | PARCIAL | Diálogo comum «Pedir aprovação» quando o erro é `ORCAMENTO_EXIGE_APROVACAO` (pedidos, encomendas, facturas de compra, tesouraria, lançamentos). **Sem isto, um documento acima da dotação fica bloqueado sem via de saída no ecrã** |
| Exportar orçamento/previsão para Excel | `orcamento_ui.js:680`, `orcamento_planeamento_ui.js:415` | Impressão/PDF | PARCIAL | CSV/XLSX (baixa) |
| Previsões deslizantes a 12 meses, revisões, publicar | `renderOrcPrevisoes` | `/orcamento/previsoes*` | MIGRADO | — |
| Cenários what-if (gerar versão) | `renderOrcCenarios` | `/orcamento/cenarios*` | MIGRADO | — |

---

## 8. Tesouraria

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Pagamentos/recebimentos: lista, emitir, editar (pendente), liquidar facturas em aberto, multi-moeda | `ui_tesouraria.js:166-2079`, `saveTesouraria`, `moedas_tesouraria.js` | `/tesouraria/documentos*`, `/tesouraria/pendentes`, `pagamentos/*.tsx` | MIGRADO | `url_documento` e `projeto_id` existem na API mas não no formulário (baixa) |
| Copiar documento | `copyTesourariaDocument` (`:1089`) | — | EM FALTA | Botão «Copiar» (pré-preenche o formulário) (baixa) |
| **Importar documentos de tesouraria (Excel + modelo)** | `processtreasuryImport` (`:2566`), `downloadtreasuryTemplate` (`app_v2.js:10259`) | — (o único `importar` é o do extracto bancário) | **EM FALTA** | Importação com simulação e modelo `.xlsx` |
| Eliminar/anular documento não integrado | `deletetreasuryDoc` | `POST /documentos/{id}/anular` | MIGRADO | — |
| Anular seleccionados; anular/estornar em lote | `deleteSelectedtreasury`, `unpostSelectedtreasury` | Individual; «todos» passa pela Manutenção (`TESOURARIA_*`) | PARCIAL | Acções em lote sobre a selecção (baixa) |
| Integrar (individual e em lote); desintegrar (estorno) | `integratetreasuryDoc`, `integrateSelectedtreasury`, `unposttreasuryDoc` | `/documentos/{id}/integrar`, `/documentos/integrar`, `/documentos/{id}/desintegrar` | MIGRADO | — |
| Ferramentas de reparação | `repairtreasuryInconsistencies`, `_executeRescue`, `repairOrphanedtreasuryDocs` | Validações `documentos_tesouraria_sem_data`, `reconciliacoes_tesouraria_orfas` (API) | N/A | ADR-015 (mas ver «Validações de Dados» em §11: falta o ecrã) |
| Extractos e disponibilidades; mapa de pendentes | `renderMapasTesouraria` | `/tesouraria/extrato-conta`, `/disponibilidades`, `Mapas.tsx`, `MapaPendentes.tsx` | MIGRADO | Drill-down do movimento e exportação Excel (baixa) |
| Reconciliação: importar extracto, sugestões automáticas, emparelhamento manual N:M, confirmar, anular | `processExternalBankStatement`, `runReconciliationAlgorithm`, `confirmManualMatch`, `deleteReconciliation` | `/tesouraria/extrato/importar`, `/reconciliacao/sugestoes`, `POST /reconciliacao`, `/reconciliacao/{codigo}/anular`, `Conciliacao.tsx` | MIGRADO | — |
| **Rascunhos de reconciliação (gravar, listar, carregar, eliminar)** | `saveReconciliationDraft`, `loadReconciliationDraft` (`:6249-6466`) | — (ADR-060: «fica para depois») | **EM FALTA** | Rascunho persistido por conta e período |
| Modelo do extracto | `downloadExternalReconTemplate` | — | EM FALTA | Descarregar o modelo (baixa) |
| Editar linha de extracto; eliminar em massa | `saveBankStatementLine`, `deleteBulkBankStatementLines` | Só `extrato/{id}/anular` (uma a uma) | PARCIAL | Editar e anular em massa (baixa) |
| Histórico e detalhe da reconciliação; reabrir em lote / reprocessar | `renderReconciliationHistory`, `viewReconciliationDetails`, `reopenBulkReconciliations` | `GET /reconciliacao` (só activas, até 500, sem as linhas) | PARCIAL | Filtros, detalhe das linhas emparelhadas, reabertura em lote |
| Divergências acumuladas (relatório e Excel) | `renderDivergenciasAcumuladas`, `exportDivergenciasToExcel` | `/reconciliacao/mapa` (impressão/PDF) | PARCIAL | Excel e filtros (baixa) |
| Conferência de caixa: rascunho, finalizar, reabrir, assinar, imprimir | `_confSaveRascunho`, `submitConferenciaCaixa`, `_confReopenAudit`, `_confAssinarGerente` | `/tesouraria/conferencias*`, `Conferencia.tsx` | PARCIAL | Eliminar rascunho (não há DELETE) (baixa) |
| Meios de pagamento (CRUD, predefinido) | `saveMeioPagamento`, `setMeioPagamentoPadrão` | `/tesouraria/meios-pagamento` | MIGRADO | — |
| Folha de caixa: abrir, movimento, eliminar movimento, fechar, contabilizar, imprimir | `ui_folha_caixa.js` | `/tesouraria/caixa/sessoes*`, `FolhaCaixa.tsx` | MIGRADO | — |
| **Folha de caixa: pagar/receber facturas** | `confirmarPagamentoFaturas`, `confirmarRecebimentoFaturas` (`:844-1120`) | O backend aceita `venda_id`/`fatura_compra_id` (`ServicoCaixa:62-101`), mas `FolhaCaixa.tsx` não tem selector de facturas | PARCIAL | Selector de facturas em aberto no modal do movimento |
| Folha de caixa: grelha de várias linhas e colar do Excel; importar folha (modelo) | `_caixaColarExcel`, `handleFolhaCaixaImport` | — | EM FALTA | Registo em grelha e importação |
| Folha de caixa: notas DEMO/fluxo nas linhas; produto | `atribuirNotasLinhasCaixa`, `_onCashProductSelect` | Colunas existem; não estão no formulário | PARCIAL | Campos no formulário (afecta a DR e o fluxo de caixa) |
| Folha de caixa: eliminar sessão fechada não contabilizada | `eliminarSessaoCaixa` | `DELETE` só para sessões abertas sem movimentos | PARCIAL | Reabrir ou eliminar uma sessão fechada por erro |
| Caixa em moeda estrangeira | `moedas_tesouraria.js:338-429` | Recusado explicitamente (ADR-034, adiado) | EM FALTA | Decisão adiada no ADR-034 |
| Fluxos Bancos/Folha de caixa | `fluxo_tesouraria.js` | `/gestao/fluxos/{fluxo}` | MIGRADO | — |

## 9. Compras, Armazém, Inventário, POS de armazém e Activos

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Pedidos internos com deliberação por escalões (4 níveis), escalões, imprimir, anular | `ui_compras_v2.js:886-1161`, `compras_deliberacao.js` | `/compras/pedidos*`, `/compras/deliberacao/escaloes`, `pedidos/*.tsx` | MIGRADO | — |
| Propostas (preço, IVA por linha, moeda), quadro comparativo, propor, adjudicar (gera encomenda), desfazer | `savePurchaseQuote`, `renderQuadroAvaliacação`, `aprovarAdjudicacação` | `/compras/propostas*`, `/pedidos/{id}/comparacao`, `QuadroComparativo.tsx` | MIGRADO | — |
| **Editar o IVA da proposta/encomenda depois de criada** | `comprasEditarIVA`, `comprasGravarIVAProposta`, `comprasGravarIVAEncomenda` (`:1186-1252`) | Não há `PUT` de propostas nem de encomendas; o IVA só se indica ao criar (na encomenda, só ao facturar) | PARCIAL | Edição do IVA por linha antes da adjudicação ou da facturação (impacto fiscal) |
| Sugestão da moeda do fornecedor | `comprasFxSugerirMoedaFornecedor` | O backend sugere-a, mas os formulários enviam sempre AOA | PARCIAL | Pré-preencher a moeda do fornecedor (P) |
| Encomendas: ver, imprimir, anular; recepções parciais; guia de recepção | `viewPurchaseOrder`, `savePartialDelivery`, `imprimirRececação` | `/compras/encomendas*`, `/encomendas/{id}/rececoes`, `Rececoes.tsx` | MIGRADO | — |
| Facturas de fornecedor: da encomenda e directa; contabilizar; descontabilizar (estorno); imprimir | `gerarFaturaDeCompra`, `saveDirectPurchaseInvoice`, `postPurchaseInvoiceToAccounting` | `/compras/faturas*`, `NovaFatura.tsx`, `DetalheFatura.tsx` | MIGRADO | — |
| Pré-visualização do lançamento da factura | `showAccountingPreviewTooltip` (`:3557`) | — | EM FALTA | Simulação D/C antes de contabilizar (baixa) |
| Criar activo a partir da factura | `openAssetCreationModalFromJournal` | Só em Activos › Pendentes | PARCIAL | Atalho na factura (baixa) |
| Fornecedores; encomendas de clientes → pedido; contratos com marcos e encomendas | `saveSupplier`, `generatePurchaseRequestFromSales`, `savePurchaseContract` | `/terceiros`, `/compras/encomendas-clientes*`, `/compras/contratos*` | MIGRADO | Atalho «usar total das encomendas» no contrato (baixa) |
| Contabilizar recepção | `postPurchaseDeliveryToAccounting` | Contabiliza na validação | N/A | ADR-066 |
| Stock: níveis, custo médio, ajuste manual, imprimir; transferências (novo) | `ui_warehouse.js:106-1594` | `/logistica/stock`, `/ajustes`, `/transferencias`, `NiveisStock.tsx` | MIGRADO | — |
| Validar entradas; cancelar/reverter | `confirmarValidaçãoReceção`, `cancelarReceção`, `eliminarValidaccação` | `/compras/rececoes/{id}/validar|anular|reverter-validacao`, `ValidarEntradas.tsx` | MIGRADO | — |
| Encomendas por receber no ecrã do armazém | `armazemRenderEncomendasPorReceber` | Só em Compras › Recepções | PARCIAL | Lista e registo no ecrã do armazém (baixa) |
| Guias de saída (consumo), imprimir, anular, (des)contabilizar | `saveGuiaSaida`, `anularGuiaEntrega`, `postGuiaSaidaToAccounting` | `/logistica/guias-saida*`, `Guias.tsx` | MIGRADO | — |
| Movimentos; abrir documento de origem | `openMovementDocument` | `Movimentos.tsx` abre o extracto do artigo | PARCIAL | Navegação ao documento de origem (baixa) |
| Recalcular valorizações; armazéns | `recalcularValorizacoesStock`, `saveWarehouse` | `/logistica/stock/recalcular-valorizacoes`, `/logistica/armazens` | MIGRADO | — |
| POS de armazém: balcão, picking, expedir (GR), guia | `finalizePOSWhSale`, `startOrderPicking`, `finalizeOrderPicking` | `/pos/armazem/*`, `PosArmazem.tsx` | MIGRADO | — |
| POS de armazém: talão térmico após a venda; visto por linha no picking; grelha/filtro de stock | `printPOSWhReceipt`, `checkPickingCompletion`, `setPOSWhLayoutMode` | Guia A4 só no separador de vendas; confirmação global | PARCIAL | Talão 80 mm e visto por linha (baixa) |
| POS de armazém: senhas com voz; acerto de stock | `renderPOSSenhasTab`, `posWhAplicarAcerto` | — | N/A | ADR-050/042 |
| Inventário: sessões, contagem cega, revisão, voltar à contagem, aprovar (segregação), reabrir, anular | `ui_inventory.js` | `/logistica/inventarios*`, `Inventario*.tsx` | MIGRADO | — |
| Inventário: leitura de código de barras na contagem | `handleInvBarcodeScan` (`:478`) | Pesquisa só por código/nome | PARCIAL | Procurar pelo código de barras (P) |
| Inventário: preencher contas em falta dos produtos na aprovação | `ui_inventory.js:990-1003` | A aprovação é recusada sem contas | PARCIAL | Atalho para a ficha do produto (baixa) |
| Activos: cadastro, edição em massa, eliminar, transferir, histórico, afectações, categorias, manutenções | `ui_assets.js` | `/ativos/*`, `activos/*.tsx` | MIGRADO | — |
| Activos: importação Excel e modelo | `downloadAssetExcelTemplate`, `handleAssetExcelUpload` (XLSX) | `POST /ativos/bens/importar` com `Upload accept=".csv,.txt"` | PARCIAL | Aceitar `.xlsx` (como em `ServicoLeituraFolha`) e descarregar o modelo |
| Amortizações: calcular, pré-visualizar, integrar (período, activo, ano), meses em falta, reabrir, quotas manuais | `processAmortizationCalculations`, `executeAmortizationPosting`, `setManualAmortizationValue` | `/ativos/amortizacoes*`, `Amortizacoes.tsx` | MIGRADO | — |
| Mapa de amortizações (ecrã, impressão) | `renderAmortizationMap` | `/ativos/mapas/amortizacoes`, `Mapa.tsx` | MIGRADO | — |
| Mapa de amortizações: exportar Excel | `exportAmortizationMapToExcel` (`:3080`) | — | EM FALTA | Exportação (baixa; o PDF cobre a entrega) |
| Mapa fiscal | `renderFiscalAssetMap` | `/ativos/mapas/fiscal` | PARCIAL | Colunas de reavaliação e taxa corrigida (vazias também no legado); modelo oficial em nenhum dos dois |
| Abates e vendas (mais/menos-valia) | `saveDisposal` | `/ativos/abates*` | MIGRADO | — |
| Aquisições pendentes: inventariar uma linha (repartida), ligar a lançamentos | `finalizeAssetCreation`, `saveUnlinkedAssetsLinks` | `/ativos/aquisicoes-pendentes*`, `Pendentes.tsx` | MIGRADO | — |
| Inventariar várias linhas num só activo («Combinar») | `inventorySelectedPendingMovements` (`:3279`) | Só uma linha por pedido | PARCIAL | Juntar várias linhas 11/12 num activo (M) |
| Reparações ao abrir (fornecedores em falta, duplicados) | `fixMissingAssetSuppliers` | — | N/A | ADR-015 |

---

## 10. Vendas e Facturação, AGT, POS, Lavandaria e Hotelaria

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Clientes: listar, criar/editar, eliminar | `ui_sales.js:395, 2050`, `saveCustomer`, `deleteCustomer` | `/terceiros?papel=CLIENTE`, `Clientes.tsx` → `GestaoTerceiros.tsx` | MIGRADO | — |
| Edição em massa de clientes/produtos (contas, moeda, preço, IVA, bloqueio) | `mapeamento_massa.js` | `POST /sistema/migracao/edicao-massa/{entidade}`, `config/Migracao.tsx` | PARCIAL | Só em Configurações › Migração (outra permissão de ecrã), sem selecção nas listas; a categoria do produto não é enviada pelo ecrã |
| Importar clientes (Excel) | `openImportModal('clientes')` | Migração › `terceiros` (NIF, Nome, Tipo, Conta), tarefa `aux_gerir` | PARCIAL | Morada e e-mail no modelo; atalho no ecrã Clientes com `vendas_clientes_gerir` |
| **Importar produtos e categorias (Excel)** | `openImportModal('produtos'|'categorias')` (`ui_sales.js:5428, 5558`) | — (`ServicoMigracaoDados::ENTIDADES` não tem produtos nem categorias) | **EM FALTA** | Importação com simulação e modelo (12 colunas do legado) |
| Produtos: CRUD, copiar, bloquear, categorias | `saveProduct`, `copyProduct`, `toggleBlockProduct`, `saveCategory` | `/logistica/produtos*`, `/logistica/categorias-produtos`, `Produtos.tsx` | MIGRADO | — |
| Imagem do produto | `handleProductImageUpload` | `GuardarProdutoRequest` aceita `imagem_base64`; sem campo no ecrã nem imagens no POS | PARCIAL | Carregar imagem e mostrá-la na grelha do POS (baixa) |
| Emitir OR/PF/NE/GR/FT/FR/NC; NC com origem e motivo | `saveSale`, `fillNCFromInvoice` | `POST /vendas/documentos`, `EmitirDocumento.tsx` | MIGRADO | — |
| **Condições de pagamento: validade/vencimento, modalidade PRONTO/PRAZO (30-120 dias)/MARCOS (%)** | `condicoes_pagamento_vendas.js` | O backend aceita e valida `modo_pagamento`, `plano_pagamentos`, `valido_ate` (`ServicoDocumentosVenda.php:368-398`); o ecrã só tem `dias_validade` e a impressão não mostra as prestações | PARCIAL | Campos no `EmitirDocumento.tsx` e plano de prestações no documento A4 |
| **Documento em moeda estrangeira (moeda do cliente, câmbio, contravalor em Kz)** | `moedas_vendas.js`, `moedas_documentos.js` | O backend trata `codigo_moeda`/`taxa_cambio` (`ServicoDocumentosVenda::resolverMoeda`, ADR-030); o ecrã não tem selector de moeda nem de câmbio | PARCIAL | Selector de moeda/câmbio (sugerir a moeda do cliente) e rodapé com o contravalor em Kz |
| FR com várias contas de pagamento | `addPaymentAccountRow` | Uma conta e um meio por FR | PARCIAL | Vários meios/contas (baixa) |
| **Copiar documento** | `copySale` (`ui_sales.js:876`) | — | **EM FALTA** | Botão «Copiar» que abre a emissão pré-preenchida (como nos lançamentos) |
| Converter documento | `executeConversion` | `POST /vendas/documentos/{id}/converter` | PARCIAL | O modal não envia `armazem_id` nem `ignorar_validade` (um documento expirado é recusado sem confirmação) |
| Logística na conversão (data/local de entrega) | `toggleConversionLogistics` | Colunas existem em `VendaBase`; não estão no pedido nem no serviço | EM FALTA | Campos de entrega (baixa) |
| Factura a partir de várias guias | `fillInvoiceFromGuia` | Conversão GR→FT de uma guia de cada vez | PARCIAL | Juntar várias GR numa FT (M) |
| Encomenda → pedido de compra | `convertToPurchaseRequest` | `compras/EncomendasClientes.tsx` | MIGRADO | Sem atalho no detalhe da NE (baixa) |
| Eliminar documento não selado | `deleteSale` | `POST /vendas/documentos/{id}/anular` | MIGRADO | — |
| Limpezas/reparações em massa | `deletePendingSales`, `forceClearSale`, `clearAllTransactions`, `repairSalesAccountingIntegrity` | — | N/A | ADR-015 (sugere-se uma validação «vendas contabilizadas sem lançamento») |
| Processo do documento (cadeia origem/destino, recibos); atalho «Liquidar» | `showDocumentWorkflow`, `showReciboForm(saleId)` | `DetalheDocumento.tsx` sem ligações | PARCIAL | Documentos relacionados e atalhos (baixa) |
| Contabilizar / descontabilizar (estorno) | `postDocumentToAccounting`, `unpostSaleToAccounting` | `/vendas/documentos/{id}/contabilizar|descontabilizar` | MIGRADO | Sem pré-visualização das linhas (baixa) |
| **Contabilizar/descontabilizar seleccionados (facturas e recibos)** | `postSelectedSales`, `unpostSelectedSales`, `postSelectedReceipts`, `unpostSelectedReceipts` | Só por documento | PARCIAL | Selecção e acção em lote, como em Tesouraria › Integração (cada documento na sua transacção) |
| Recibos: emitir (várias facturas), anular, contabilizar, imprimir | `saveRecibo`, `emitBatchReceipt`, `deleteReceipt`, `printRecibo` | `/vendas/recibos*`, `Recibos.tsx`, `documentoRecibo.ts` | MIGRADO | — |
| Recibo directo / adiantamento de cliente | `showDirectReceiptForm` | — (o recibo exige alocação a facturas) | EM FALTA | Recibo sem factura com conta de adiantamentos (M) |
| Impressão A4 (factura, guia, recibo) com QR, hash e extenso | `printInvoice`, `print_docs.js` | `impressao/documentoComercial.ts`, `documentoVenda.ts` | MIGRADO | Prestações e contravalor (acima) |
| Contas de vendas | `saveSalesConfig` | `/vendas/configuracao/contas` | MIGRADO | — |
| Relatórios de vendas (KPIs, evolução, clientes, pendentes) | `renderRelatoriosVendasTab` | `/vendas/relatorios/resumo`, `Relatorios.tsx` | MIGRADO | Alerta de stock baixo no painel (baixa) |
| SAF-T(AO): gerar XML | `downloadSAFTFile` | `GET /vendas/saft` (hash RSA real, ADR-030), `/vendas/saft/validar` | MIGRADO | Grelha de pré-visualização e exportação Excel (baixa) |
| AGT: regime, estabelecimentos, séries, pedido de série, envio, estados, revalidar, pedido assinado, QR, resumo | `facturacao_agt*.js` | `/vendas/faturacao-eletronica/*`, `/vendas/configuracao/series*`, `FaturacaoEletronica.tsx`, `DetalheDocumento.tsx` | MIGRADO | — |
| AGT: erros/avisos do validador por documento; lista «Documentos com erros AGT» | `facturacao_agt_ui.js:62-126, 265` | O backend guarda `fe.erros`/`fe.avisos`; o detalhe não os mostra e a lista não filtra por estado AGT | PARCIAL | Mostrar os erros no detalhe e filtrar a lista por estado AGT (P), **necessário para corrigir rejeições** |
| AGT: ligação/credenciais no ecrã; serviço Node | `feGravarLigacao`, `servico_agt/` | Credenciais no ambiente; drivers desligado/direto/intermedio | N/A | ADR-030 |
| POS: sessão, grelha, carrinho, desconto/preço, pagamento misto, talão, X, Z com contagem, reimpressão | `pos_gestao.js`, `ui_sales.js:6400-8000` | `/pos/terminais/*`, `/pos/sessoes/*`, `FrenteCaixa.tsx`, `PainelVenda.tsx`, `FechoZ.tsx` | MIGRADO | — |
| **POS restaurante: mesas, carrinho por mesa, suspender, talão de consulta de mesa** | `setPOSTable`, `suspendPOSTable`, `saveNewPOSTable`, `printPOSConsultation` (`ui_sales.js:6720-8467`; `pos_gestao.js:454`) | Só existe o tipo de terminal RESTAURANTE | **EM FALTA** | Mesas por terminal, contas abertas por mesa (no servidor, não no navegador), suspender/retomar, talão de consulta e fecho por mesa |
| POS: criar cliente rápido | `showPOSCreateCustomerModal` | — | N/A | Decisão (ADR-063): o operador não cria fichas |
| POS: modos de ecrã (exclusivo, dispositivo, layout, imagens) | `enterExclusivePOS`, `setPOSDeviceMode` | Layout responsivo | PARCIAL | Ecrã inteiro (baixa) |
| Relatórios POS, terminais e meios, definições, configuração de impressão | `renderPOSRelatoriosTab`, `posGravarTerminal`, `savePOSPrintConfig` | `/pos/relatorios`, `/pos/terminais*`, `/pos/definicoes`, `ConfigImpressao.tsx` (preferências no navegador, como no legado) | MIGRADO | — |
| Integração das sessões; IVA em falta; descontabilizar | `posConfirmarIntegracao`, `posGravarIvaEmFalta`, `posDescontabilizarSessao` | `/pos/sessoes/{s}/contabilizar|descontabilizar`, `Integracao.tsx` | PARCIAL | Pré-visualização, lote e atalho «IVA em falta» (baixa) |
| Desvios e prestação de contas | `posConfirmarDeliberacao`, `posLiquidarItem`, `posConfirmarTPA` | `/pos/sessoes/{s}/deliberacao*`, `/pos/prestacao*`, `/pos/liquidacoes*` | MIGRADO | — |
| Lavandaria: recepção, linhas, orçamentos, materiais, danos/indemnizações, estados em massa, atribuição, pagamento, facturação, entrega com armazenagem, anular, tabelas, definições | `lavandaria.js` | `/pos/lavandaria/*`, `pos/lavandaria/*.tsx` | MIGRADO | — |
| Lavandaria: imprimir OS/talão na recepção, etiquetas, recibo RC-LAV e factura | `lavImprimirOS`, `lavImprimirEtiquetas`, `lavImprimirRecibo`, `lavImprimirFactura` | Só a OS em A4 no detalhe | EM FALTA | Talão e etiquetas térmicas; impressão do recibo e da factura (ADR-049: «fica para depois») |
| Lavandaria: orçamento em massa | `lavConfirmarOrcMassa` | O backend aceita várias linhas; o ecrã envia uma | PARCIAL | Decisão em massa (baixa) |
| Lavandaria: importação de tabelas (peças/serviços) | `lavImportar`, `lavConfirmarImportacao` | — (ADR-049: «fica para depois») | EM FALTA | Importação com modelo |
| Lavandaria: relatório Excel | `lavRelatorioExcel` | Imprimir/PDF | PARCIAL | CSV/XLSX (baixa) |
| Hotelaria: check-in (regras de horário), alterar, consumos, anular, check-out com rateio | `hotelaria.js`, `finalizePOSHotelPayment` | `/pos/hotelaria/*`, `Estadia.tsx`, `Checkout.tsx` | MIGRADO | — |
| Hotelaria: talão do check-out | `printPOSThermalReceipt` (hotel) | `Checkout.tsx` não imprime | EM FALTA | Talão/factura no fim do check-out (P) |
| Hotelaria: criar quartos com `hotel_quartos` | `saveNewPOSRoom` | Criação pelo formulário de produto (`vendas_produtos_gerir`); tarifas com `hotel_quartos` | PARCIAL | Criação de quarto com a permissão própria (baixa) |
| Fluxos de vendas, POS e lavandaria | `fluxo_vendas.js`, `fluxo_lavandaria.js` | `/gestao/fluxos/*` | MIGRADO | — |

---

## 11. Sistema, Configurações e Integrações

| Item legado | Legado | Equivalente novo | Estado | O que falta |
|---|---|---|---|---|
| Login, palavras-passe PBKDF2 → Argon2id, escolha/troca de empresa, inactividade de 15 min, sair | `app_v2.js:210-723`, `data/senhas.js` | `/autenticacao/*`, `Entrar.tsx`, `EscolherEmpresa.tsx`, `SessaoContexto.tsx` | MIGRADO | — |
| Primeiro arranque (criar Super Admin e 1.ª empresa) | `app_v2.js:528-676` | — (só o seeder E2E e a ETL) | EM FALTA | Comando `erp:instalar` ou assistente para uma instalação limpa (P). Não bloqueia a migração definitiva, que parte dos dados do legado |
| Menu por permissões, rotas, guardas, carregamento por pacotes | `rotas.js`, `permissoes.js`, `carregador.js` | `GET /sistema/menu`, React Router, `lazy()` | MIGRADO | — |
| Empresas (CRUD, logótipo, INSS, horas extra, holding), desactivar/eliminar por pedido | `saveCompany`, `deleteCompany` | `/sistema/empresas*`, `/sistema/gestao-empresas`, `Empresas.tsx`, Manutenção | MIGRADO | — |
| Utilizadores (CRUD, empresas, colaborador, perfil), repor palavra-passe | `saveUser`, `deleteUser` | `/sistema/utilizadores*`, `Utilizadores.tsx` | MIGRADO | — |
| Alterar a própria palavra-passe | — (não existe no legado) | — | N/A | Sem paridade a manter; **recomenda-se acrescentar** (lacuna nos dois sistemas) |
| Perfis v2: editor, modelos, matriz, segregação de funções, duplicar | `permGravarPerfil`, `permCriarModelos`, `permMatriz` | `/sistema/perfis/*`, `Perfis.tsx` | MIGRADO | — |
| Logs/auditoria com filtros (antes/depois) | `renderAuditLogs` | `/sistema/logs`, `Logs.tsx` | MIGRADO | — |
| Registo de navegação (acesso a cada ecrã) | `logMovement('Navegação', …)` (`app_v2.js:1277`) | — | PARCIAL | Registar o acesso a ecrãs (baixa; a auditoria cobre as alterações) |
| Manutenção de dados com aprovação dupla | `manutencao.js` | `/sistema/manutencao/*`, `Manutencao.tsx` (5 acções portadas, 8 substituídas/não portadas — ADR-058) | MIGRADO | — |
| **Validações de Dados (substituto das rotinas destrutivas, ADR-015)** | rotinas de reparação de `ui_tesouraria.js`, `reparar_texto.js`, `ui_assets.js`, `ui_sales.js` | API `GET /sistema/validacoes` (+`/{codigo}`, ≈46 validações, `ServicoValidacoesDados`), **sem ecrã React** (não está em `config/ecras.ts`) | PARCIAL | Ecrã «Sistema › Validações de Dados» com a lista, a contagem e o drill-down para o registo. **O ADR-015 só fica cumprido com este ecrã** |
| Cópias por empresa: exportar, importar (empresa nova ou vazia), clonar estrutura | `company_backup.js`, `exportCompanyDatabase`, `importCompanyDatabase` | `/sistema/copias/*` | MIGRADO | — |
| Exportar/restaurar/apagar a BD inteira | `exportDatabase`, `importDatabase`, `wipeDatabase` | Cópias `pg_dump` agendadas (`docs/PRODUCAO.md`) | N/A | ADR-058/065 (infraestrutura) |
| Copiar tabelas para uma empresa existente (plano de contas, infotipos, funções, terceiros) | `executeConfigCopy` (`app_v2.js:8917`) | Só diários, notas e centros de custo (`tabelas/{t}/copiar`) | PARCIAL | Cópia selectiva das restantes tabelas (M) |
| Reparar texto corrompido; recuperar mapeamento de notas; sincronizar infotipos WSTB | `repararTextoCorrompidoBD`, `recoverDataMapping`, `syncInfotypesFromWSTB` | Validação `texto_corrompido_lancamentos`; limpeza na ETL | N/A | ADR-015 / rotina específica de uma empresa |
| Moedas, moeda funcional, câmbios (CRUD, consulta, histórico), BAI | `moedas.js` | `/sistema/moedas*`, `/sistema/cambios*` (+`bai`), `Moedas.tsx` | MIGRADO | — |
| Câmbios: importar Excel | `importarCambiosExcel` | `POST /sistema/cambios/importar` | MIGRADO | Descarregar o modelo; exportar para Excel (baixa) |
| Aviso de câmbio do dia em falta | `verificarCambioDoDia` (`moedas.js:800`) | — | EM FALTA | Aviso no início/topo quando falta o câmbio do dia das moedas em uso (P) |
| Substituir conta (simular, executar) | `substituir_conta.js` | `/sistema/plano-contas/substituir*` (sem reescrever o Diário, ADR-058) | MIGRADO | Exportar a lista de utilização e o registo (baixa) |
| Plano de contas (CRUD, edição/eliminação em massa, importar) | `saveAccount`, `_executeBulkEditAccounts`, `importChartOfAccountsExcel` | `/contabilidade/plano-contas*`, `PlanoContas.tsx`, Migração | MIGRADO | — |
| Migração de dados (modelos, importação, edição em massa, simulação) | `app_v2.js:9609-10128` | `/sistema/migracao/*`, `Migracao.tsx` | PARCIAL | Faltam as entidades colaboradores, contratos, variáveis do cálculo, produtos, categorias, tesouraria e folha de caixa (ver os módulos) |
| Unidades de negócio | `unidades_negocio.js` | Backend `/sistema/unidades-negocio` sem ecrã | PARCIAL | Ver §5 |
| **Ajuda contextual (botão «Ajuda» e F1 em todos os ecrãs)** | `ajuda.js` (431 linhas: o que o ecrã faz, passos, regras, atalhos e pesquisa, por módulo e ecrã) | — | **EM FALTA** | Painel lateral de ajuda com o conteúdo de `ajuda.js` (convertido para JSON), aberto por botão e F1 e indexado pelo id do ecrã do catálogo |
| Gestor de operações demoradas (progresso, segundo plano, painel no canto) | `tarefas.js`, `shared/janela_processo.js` | Progressos locais (ex.: `Integracao.tsx`); filas no servidor em casos pontuais | PARCIAL | Componente comum de operações em segundo plano (necessário para o ZIP de recibos e para os lotes) |
| Favoritos, grupos de menu personalizados, reordenar módulos, pesquisa CTRL+K, modo Zen, selector/redimensionamento de colunas | `app_v2.js:1387-11500`, `ui_dashboard.js:576-1042` | Menu recolhível | EM FALTA | Ver §4 (prioridade baixa; o CTRL+K é citado na própria «Dica do dia») |
| Exportação Excel genérica das tabelas (`exportTableToExcel`) | `app_v2.js:6903-6946`, `export_setup.js` | CSV em alguns mapas; impressão/PDF em todos (ADR-066) | PARCIAL | Exportação `.xlsx` comum no `TabelaApi`/`BotoesExportar` (resolve de uma vez as cerca de 25 linhas «Excel» deste relatório) |
| **Power BI: exportação dos dados e servidor OData** | `exportPowerBIData`, `checkPBIServer` (`app_v2.js:8721-8777`); `powerbi_api/server.js` (`/api/{tabela}`, `/api/payroll_results`, `/api/$metadata`, `/api/upload`) | — (nenhuma ocorrência de powerbi/odata no backend ou no frontend; nenhum ADR o exclui) | **EM FALTA** | Feed de dados para o Power BI sobre a API, com token próprio de leitura por empresa e permissão `config_backup` (ver proposta) |
| **Assistente IA (documentos → proposta de lançamentos; regras da empresa; motor de regras internas)** | `ui_ai_agent.js` (`openAIDocumentAgent`, `sendAIChat`, `_execAI`, `openInternalRulesModal`), `ai_proxy/` (OpenRouter, Gemini como alternativa), botão em `ui_lancamentos.js:456` | Só os dados: campo `regras_ia` da empresa (`Empresas.tsx:247`) e a tabela `regras_internas_ia` (model `RegraInternaIA`, migrada e clonada) **sem consumidor**; não há serviço, rota nem ecrã (nenhum ADR o exclui) | **EM FALTA** | Serviço no backend com a chave no servidor, motor de regras internas e propostas validadas pelo `ServicoLancamentos` antes de gravar (ver proposta) |
| Logótipo do ecrã de entrada; identidade nos documentos | `handleSystemLogoUpload` | `/sistema/configuracoes/logotipo-login`, `/sistema/identidade` | MIGRADO | — |
| Gestão documental (`sgd`) | `renderSGD` inexistente | — | N/A | Ecrã morto (ADR-063/064) |

---

## 12. Lacunas priorizadas e proposta de implementação

> **Critérios de prioridade:**
> - **ALTA:** uso diário ou impacto contabilístico, fiscal ou salarial.
> - **MÉDIA:** produtividade relevante ou integração usada pela direcção.
> - **BAIXA:** conforto ou exportações.
>
> **Dimensão:** **P** = até 1 dia; **M** = 2 a 5 dias; **G** = mais de 1 semana (backend + frontend + testes PHPUnit/Vitest/E2E).
>
> Todas as propostas seguem as regras já estabelecidas:
> - `exigir()` com chaves do catálogo (sem chaves novas, salvo indicação);
> - transacções e auditoria;
> - simulação antes de gravar nas importações (`ServicoLeituraFolha` para `.xlsx`);
> - estorno em vez de apagar (ADR-016).

### 12.1 ALTA

| ID | Lacuna | Proposta de implementação | Permissão | Dim. |
|---|---|---|---|:-:|
| **A-01** | **Ecrã «Validações de Dados»**: a API existe, mas sem ecrã o ADR-015 fica por cumprir (as rotinas destrutivas foram substituídas por relatórios que ninguém consegue ver) | Ecrã `config_validacoes` em `frontend/src/modulos/config/Validacoes.tsx`, sobre `GET /api/sistema/validacoes` e `/{codigo}`: lista com contagem por gravidade e drill-down para o registo (ligação ao ecrã de origem). Registar em `config/ecras.ts` como separador de `config_manutencao`, para não mexer no catálogo | `config_manutencao_view` (a que a API já exige) | P |
| **A-02** | **Pedir aprovação de excesso orçamental no acto do documento**: hoje o documento fica bloqueado sem via de saída | Componente comum `src/componentes/orcamento/DialogoExcesso.tsx`, accionado pelo `notificarErro` quando `codigo === 'ORCAMENTO_EXIGE_APROVACAO'`: mostra os detalhes (rubrica, dotação, excesso), pede a justificação e chama `POST /api/orcamento/pedidos-excesso`. Ligar em pedidos, encomendas e facturas de compra, tesouraria e lançamentos | As que o servidor já aceita (`compras_ped_criar`, …, `lancamentos_post`) | P |
| **A-03** | **Vendas: condições de pagamento e moeda estrangeira no ecrã de emissão** (o backend já trata ambos) | `EmitirDocumento.tsx`: (a) modalidade PRONTO/PRAZO/MARCOS com plano de prestações (presets 30/60/90/120 e 30/70, 50/50, 30/40/30) e `valido_ate`; (b) selector de moeda (por omissão a do cliente) e câmbio (por omissão o de `GET /api/sistema/cambios/consultar`). `impressao/documentoVenda.ts`: tabela de prestações e rodapé com o contravalor em Kz. Testes Vitest dos totais na moeda | `vendas_fat_emitir` | M |
| **A-04** | **AGT: erros/avisos do validador visíveis e filtro por estado AGT** (necessário para corrigir rejeições) | `DetalheDocumento.tsx`: bloco «Facturação electrónica» com `fe.erros`/`fe.avisos` e o estado. `DocumentoVendaController@index`: filtro `estado_fe`. `ListaDocumentos.tsx`: filtro «Estado AGT» e atalho a partir dos contadores de `FaturacaoEletronica.tsx` | `vendas_faturacao_view` | P |
| **A-05** | **Lançamentos: notas em massa e edição dos campos não financeiros** (DEMO, fluxo, UN, CC, descrição, terceiro), essenciais para o Balanço, a DR e o Fluxo de Caixa | Backend: `POST /api/contabilidade/lancamentos/classificacao` (`ServicoClassificacaoLancamentos`), que recebe `linhas[]` + campos e só altera colunas não financeiras (nunca conta, valor, D/C nem data), recusa linhas de exercício encerrado e audita antes/depois; acrescentar `centro_custo_id` e `unidade_negocio_id` a `CAMPOS_ACTUALIZAVEIS`. Frontend: selecção de linhas em `ListaLancamentos.tsx` e em «Movimentos sem nota», filtro «sem nota/CC/UN», acção «Classificar» e edição na ficha | `lancamentos_bulk_notes` (massa) e `lancamentos_editar` (ficha): reaproveita as duas chaves sem uso | M |
| **A-06** | **Saldos históricos: ecrã** (o backend existe) | Modal «Saldos históricos» em `ListaLancamentos.tsx` (ou separador em Tabelas auxiliares): ano, grelha conta/D/C com totais, importação `.xlsx` (`POST /saldos-historicos/importar`) e modelo | `lancamentos_saldos` | P |
| **A-07** | **Lançamento manual em moeda estrangeira** | `CriarLancamentoRequest`: aceitar `codigo_moeda`, `valor_moeda` e `taxa_cambio` por linha (só contas com moeda da classe 4, regra do `db_v2.js`); validar o equilíbrio na moeda funcional. `EditorLinhasDC.tsx`: colunas opcionais de moeda/câmbio/valor na moeda, com cálculo do Kz | `lancamentos_post` | M |
| **A-08** | **RH, cálculo do período:** copiar o mês anterior; importar o cálculo (Excel); eliminar período aberto; edição/eliminação em lote; lote com várias rubricas e horas | `ServicoFolhaSalarial`: `copiarAnterior(periodo, opções)` (só rubricas variáveis, sem duplicar colaborador × rubrica), `importarVariaveis(periodo, ficheiro, simular)` com o modelo colaborador(NIF) × rubrica × valor/horas e `eliminarPeriodoAberto`. Rotas `POST /rh/salarios/periodos/{id}/copiar-anterior`, `/importar-variaveis`, `DELETE /rh/salarios/periodos/{id}`, `POST .../lancamentos/lote`. `Calcular.tsx`: menu Importar com as novas opções e selecção de linhas | `calcular_folha`, `calcular_bulk`, `rh_lanc_del` | M |
| **A-09** | **RH, cadastros:** importar colaboradores e contratos (Excel); contratos/rubricas em massa | Acrescentar as entidades `colaboradores` (chave NIF; agregado, habilitações, IBAN, UN/CC/unidade) e `contratos` (formato vertical do legado) a `ServicoMigracaoDados`, com simulação, actualização dos existentes sem duplicar (regra do `import_mestre.js`) e modelo `.xlsx`. `POST /rh/contratos/massa` (`ServicoContratosTrabalho::aplicarRubricasEmMassa`): acrescenta ou substitui rubricas com o mesmo valor em N colaboradores e cria contratos para quem não tem. Botões em `Colaboradores.tsx`/`Contratos.tsx` | `colaboradores_import`, `contratos_import`, `contratos_new` (as duas primeiras passam a ter uso) | G |
| **A-10** | **RH, recibos e integração:** recibo com 2 vias, líquido por extenso, banco/IBAN e forma de pagamento; recibos seleccionados e **ZIP** com um PDF por colaborador; UN/CC na ficha; **assistente de mapeamentos em falta** | `ReciboSalario.tsx`: 2 vias por página, extenso (já existe em `documentoComercial`), IBAN. ZIP: job `GerarRecibosZip` no backend (PDF pelo Chromium/wkhtml do contentor, ou HTML impresso para PDF por lote) com descarga em `GET /rh/salarios/periodos/{id}/recibos.zip`, e progresso pelo componente de operações (M-05). `Colaboradores.tsx`: campos UN/CC. `Processamento.tsx`: quando a resposta é `MAPEAMENTO_EM_FALTA`, abrir um modal com `em_falta`, gravar em `PUT /rh/mapeamentos-contabeis` e repetir `contabilizar` | `rh_recibos_emitir`, `colaboradores_detail`, `processamento_integrate` + `contab_mapeamento` | M |
| **A-11** | **Folha de caixa: pagar/receber facturas e notas nas linhas** (o backend aceita `venda_id`/`fatura_compra_id`) | `FolhaCaixa.tsx`: no modal do movimento, «Liquidar facturas» com o selector de `GET /api/tesouraria/pendentes` (já usado em `FormularioDocumento.tsx`) e campos de nota DEMO/fluxo/UN/CC. Permitir reabrir uma sessão fechada não contabilizada (`POST /caixa/sessoes/{id}/reabrir`) | `teso_caixa_operar`, `teso_caixa_fechar` | P |
| **A-12** | **Tesouraria: importar documentos (Excel)**: 5 781 documentos no legado, em grande parte carregados por modelo | Entidade `tesouraria` em `ServicoMigracaoDados` (ou `POST /api/tesouraria/documentos/importar`) com o modelo do legado (`Template_Importação_Tesouraria`), simulação, criação em PENDENTE e ligação opcional a facturas por número; botão em `ListaDocumentos.tsx` | `teso_doc_emitir` | M |

### 12.2 MÉDIA

| ID | Lacuna | Proposta de implementação | Permissão | Dim. |
|---|---|---|---|:-:|
| **M-01** | Exportação `.xlsx` genérica (cerca de 25 linhas «Excel» deste relatório: mapas contabilísticos, RH, IVA, amortizações, cubo, relatórios de gestão, previsão CRM, orçamento, lavandaria, câmbios) | `BotoesExportar` com a opção «Excel», gerado no navegador a partir das colunas de impressão já definidas (`valorImpressao`), com uma biblioteca leve e compatível com a CSP (ex.: `write-excel-file`), números como números e cabeçalho da empresa. `TabelaApi impressao` reaproveita a recolha paginada | As do próprio ecrã | M |
| **M-02** | **Power BI / OData** | `GET /api/bi/odata/{conjunto}` e `/$metadata` (`ServicoFeedBI`) sobre os mesmos 8 conjuntos da lista branca do cubo (lançamentos, vendas, compras, tesouraria, stock, salários da fotografia, activos, orçamento), paginação `$top/$skip` e filtro de período. Autenticação por **token de leitura por empresa** (tabela `tokens_bi`, criado em Configurações › Geral, revogável, guardado só em hash) e limite de pedidos. Corrige os bugs do legado (empresas misturadas, campos errados) | `config_backup` para gerir os tokens; o token só lê a empresa a que pertence | G |
| **M-03** | **Assistente IA** (documento → proposta de lançamentos; regras da empresa; regras internas por palavras-chave) | `ServicoAssistenteIA` com a chave do fornecedor no `.env` (nunca no navegador; risco 13 do inventário). `POST /api/contabilidade/assistente/propor` (texto e/ou ficheiro PDF/imagem; contexto: plano de contas, diários e notas da empresa, `empresas.regras_ia`) devolve propostas em JSON validadas por `ServicoLancamentos` **sem gravar**. O utilizador revê-as em `NovoLancamento.tsx` pré-preenchido e grava pelo fluxo normal. CRUD de `regras_internas_ia` (`/api/contabilidade/assistente/regras`) como motor local sem IA externa. Registo de uso para auditoria e custo | `lancamentos_post` (propor/gravar), `aux_gerir` (regras) | G |
| **M-04** | Ajuda contextual (F1) | Converter o conteúdo de `ajuda.js` para `frontend/src/ajuda/conteudo.json`, indexado pelo id do ecrã do catálogo; `PainelAjuda` (Drawer) com o que o ecrã faz, os passos, as regras e a pesquisa, aberto pelo botão no `CabecalhoPagina` e pela tecla F1 | — (todos) | M |
| **M-05** | Gestor de operações em segundo plano | `src/componentes/operacoes` (contexto + painel no canto: progresso, minimizar, concluir/falhar), usado nos lotes (integração, contabilização em lote, ZIP de recibos, importações); no backend, `lotes_trabalhos` (já existe) com `GET /api/sistema/operacoes/{id}` | — | M |
| **M-06** | Vendas: copiar documento; contabilizar/descontabilizar facturas e recibos em lote | «Copiar» em `DetalheDocumento.tsx` → `EmitirDocumento` com `location.state.copia` (como em lançamentos/CRM). `POST /api/vendas/documentos/contabilizar` e `/vendas/recibos/contabilizar` (cada um na sua transacção, como em `tesouraria/documentos/integrar`); `rowSelection` nas listas | `vendas_fat_emitir`, `vendas_fat_contabilizar`, `vendas_fat_descontab`, `vendas_fat_unpost` | M |
| **M-07** | Vendas: importar produtos e categorias (Excel) | Entidades `produtos` (12 colunas do legado; contas validadas como de movimento) e `categorias_produto` em `ServicoMigracaoDados`; botão «Importar» em `Produtos.tsx` | `vendas_produtos_gerir` | P |
| **M-08** | Reconciliação bancária: rascunhos; detalhe e histórico com filtros; editar linha de extracto | Tabela `rascunhos_reconciliacao` (conta, período, pares em jsonb, autor); `POST/GET/DELETE /api/tesouraria/reconciliacao/rascunhos`; `GET /reconciliacao/{codigo}` com as linhas emparelhadas; `PUT /extrato/{id}` (só pendentes) | `teso_conc_confirmar`, `teso_conc_importar`, `teso_conc_anular` | M |
| **M-09** | Transferir lançamento para outra empresa | `POST /api/contabilidade/lancamentos/{id}/transferir` (`ServicoTransferenciaLancamentos`): estorno na origem + criação na empresa destino (mapeia o diário pelo código e o terceiro pelo NIF), numa transacção, com ligação cruzada e auditoria; exige acesso às duas empresas | `contab_lanc_transferir` (nas duas empresas) | M |
| **M-10** | Relatório e Contas: Relatório de Gestão (textos automáticos editáveis, repor, gráficos) | Separador «Relatório de Gestão» em `RelatorioContas.tsx` sobre `textos` (já guardados por `PUT /relatorio-contas/{ano}`); geração dos textos automáticos no `ServicoRelatorioContas` (portando `relatorio_contas.js:993-1128`); incluídos na impressão | `rc_editar` | M |
| **M-11** | RH, portal: a chefia avalia a equipa e regista reuniões; o RH vê as autoavaliações e os resultados das chefias (anónimos) | Separador «A minha equipa» em `Portal.tsx` (rotas existentes `POST /rh/avaliacao/avaliacoes`, `/feedbacks`); separadores «Autoavaliações» e «Avaliação das chefias» em `PortalGestao.tsx` (`/rh/avaliacao/autoavaliacao`, `/ascendente/{colaborador}`) | `rh_portal_usar`, `rh_portal_aprovar` | M |
| **M-12** | RH: leitura directa do relógio biométrico | `ServicoAssiduidade::importarDoRelogio()`: o servidor lê o URL configurado (HTTP com timeout e limite de tamanho, sem credenciais no URL, já validado), reutiliza o parser da importação de ficheiro; `POST /rh/assiduidade/registos/importar-relogio`; agendamento opcional diário | `rh_assid_registar` | M |
| **M-13** | RH: produtividade (importar Excel); contrato de trabalho em PDF; mapas IRT/INSS completos com grupos A/B | Importação em `FeriasProdutividadeController` com o modelo do legado. Modelo «Contrato de trabalho» em `ServicoDocumentosRH` (cláusulas do `generateContractPDF`). Colunas em falta e filtro de grupo nos mapas | `rh_prod_registar`, `contratos_new`, consulta dos mapas | M |
| **M-14** | Unidades de negócio: ecrã de gestão | Separador em `TabelasAux.tsx` sobre `/api/sistema/unidades-negocio` (CRUD já existente) | `aux_gerir` | P |
| **M-15** | POS restaurante: mesas | Tabela `mesas_pos` (terminal, nome, ordem) e `contas_mesa` (carrinho em jsonb, estado ABERTA/FECHADA, operador), no servidor (o legado guardava-as no `localStorage`). Rotas `/pos/terminais/{t}/mesas`, `/pos/sessoes/{s}/mesas/{m}/conta` (gravar/suspender/consultar/fechar → venda FR existente). `PainelVenda.tsx`: grelha de mesas quando o terminal é RESTAURANTE; talão de consulta | `pos_venda`, `pos_terminais_gerir` | G |
| **M-16** | Lavandaria e hotelaria: impressões operacionais (talão/etiquetas da OS, recibo RC-LAV, factura; talão do check-out) e importação de tabelas | Reutilizar `htmlTalaoVenda`/`imprimir` do POS em `Recepcao.tsx`, `DetalheOrdem.tsx` e `Checkout.tsx`; etiquetas com o n.º da OS e da peça. `POST /pos/lavandaria/importar` (peças/serviços) com simulação | `lav_ordens`, `lav_tabelas`, `hotel_checkout` | M |
| **M-17** | Compras: editar o IVA da proposta e da encomenda (linhas por facturar); sugerir a moeda do fornecedor | `PUT /api/compras/propostas/{id}/iva` e `/encomendas/{id}/iva` (só linhas por adjudicar/facturar; recalcula os totais; audita). Formulários sem `codigo_moeda: 'AOA'` fixo | `compras_new_proposal`, `compras_enc_criar` | P |
| **M-18** | Vendas: recibo de adiantamento (sem factura); factura a partir de várias guias | `CriarReciboRequest` com `tipo=ADIANTAMENTO` e conta de adiantamentos configurável em `/vendas/configuracao/contas`, mais a alocação posterior a facturas. `converter` com várias GR do mesmo cliente | `vendas_recibos`, `vendas_fat_emitir` | M |
| **M-19** | Cubo: visões gravadas; favoritos e pesquisa CTRL+K | Tabela `preferencias_utilizador` (utilizador, empresa, tipo, nome, jsonb) com `GET/PUT/DELETE /api/sistema/preferencias/{tipo}`; aplicar a visões do cubo, favoritos e ordem dos módulos; pesquisa de ecrãs pelo menu já devolvido por `/sistema/menu` | — | M |
| **M-20** | Projectos: acções do organigrama sem ecrã (posições em lote, associar tarefas, mapear orçamento, disposição) e arrastar tarefas na WBS | Ecrãs em `Organigrama.tsx`/`Planeamento.tsx` sobre as rotas já existentes (`organigrama/posicoes/lote`, `posicoes/{p}/tarefas`, `organigrama/orcamento`, `organigrama/disposicao`, `tarefas/{t}/mover`) | `proj_gerir` | M |

### 12.3 BAIXA (resumo)

Estão marcadas com «(baixa)» nas tabelas:
- **Exportações e modelos:** modelos de importação em falta (lançamentos, extracto, câmbios); pré-visualizações do lançamento (factura de compra, venda, POS).
- **Acções em lote:** estornos e anulações em lote em Tesouraria/Contabilidade; reverter compensações em lote.
- **Atalhos e navegação:** atalhos (documento de origem, liquidar, processo do documento); filtros adicionais (CRM atraso/equipa, lançamentos avançados); colunas AV%/AH%.
- **Cópias entre empresas:** copiar tabelas e mapeamentos de salários para uma empresa existente.
- **Activos:** importação `.xlsx` e «Combinar» linhas.
- **POS de armazém:** talão e visto no picking; código de barras no inventário.
- **Estrutura orgânica:** criar a estrutura base, várias unidades, disposição.
- **Sistema e interface:** primeiro arranque (`erp:instalar`); aviso do câmbio do dia; registo de navegação; ecrã cheio e modo Zen; imagens dos produtos.

### 12.4 Sequência recomendada

1. **Antes da migração definitiva (dia D):**
   - A-01 (Validações), para poder corrigir os dados no arranque;
   - A-06 (Saldos históricos), A-05 (Classificação) e A-02 (Excesso orçamental), sem as quais há bloqueios ou mapas incorrectos;
   - A-04 (Erros AGT).
   São quase só frontend, com cerca de 1,5 semanas no total.
2. **Primeiro mês:**
   - A-03 (Vendas: moeda e condições) e A-11 (Folha de caixa);
   - A-08 (Cálculo RH) e A-10 (Recibos), antes do primeiro processamento salarial no sistema novo;
   - A-12 (Importar tesouraria) e A-07 (Lançamento em moeda);
   - A-09 (Importações RH).
3. **Seguinte:** M-01 (Excel comum) e M-05 (operações), que destravam vários PARCIAL; depois M-06, M-07, M-08, M-10, M-11 e M-14 a M-18.
4. **Integrações:** M-02 (Power BI) e M-03 (Assistente IA). Convém registar um ADR para cada uma, com fornecedor, custos e dados enviados a terceiros, antes de as implementar.

### 12.5 Notas de catálogo e documentação

- Há chaves do catálogo sem uso: `colaboradores_import`, `contratos_import`, `lancamentos_editar`, `lancamentos_bulk_notes` e `pos_armazem_acerto` passam a ter uso com A-05, A-09 e (opcional) M-15. Convém anotar no editor de perfis que `rh_limpeza_massa`, `teso_reparar`, `acao_limpeza_massa`, `compras_rec_contabilizar` e `pos_armazem_acerto` (se não for portado) estão «sem efeito no sistema novo (ADR-015/050/066)».
- A cópia de lançamentos exige agora `lancamentos_post`; no legado era `lancamentos_editar`. Os perfis migrados com só `lancamentos_editar` perdem o «Copiar». A-05 resolve-o, se o botão aceitar qualquer das duas chaves.
- A «Dica do dia» refere o atalho CTRL+K, que não existe: rever o texto ou implementar M-19.
- Itens «fica para depois» já registados nos ADR e confirmados aqui como pendentes: ADR-037 (importações e contratos em massa, mapeamentos entre empresas), ADR-038 (relógio), ADR-039 (importação da produtividade), ADR-049 (impressões e importação da lavandaria), ADR-034 (caixa em moeda estrangeira) e ADR-060 (rascunho de reconciliação bancária).
