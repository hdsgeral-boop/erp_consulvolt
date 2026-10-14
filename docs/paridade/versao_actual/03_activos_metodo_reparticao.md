# Análise v3 — Activos, Método de Trabalho, Repartição UN/CC, Cubo e Relatórios de Gestão

Comparação **LEGADO NOVO** (`Versões/Versão Actual/ERP`) × **LEGADO ANTIGO** (`payroll_system_web`) × **SISTEMA NOVO** (`erp_laravel`).
Análise só de leitura (2026-10-13). Caminhos do legado relativos a `Versões/Versão Actual/ERP/`; do sistema novo relativos a `erp_laravel/`.

## Tabela-resumo

| # | Funcionalidade | Ecrã (data-target / separador) | Estado no erp_laravel | Esforço |
|---|---|---|---|---|
| A1 | Tipo de activo BEM/EDIFÍCIO + localização Edifício › Andar › Porta na ficha | `activos` (ficha do activo) | FALTA | Médio |
| A2 | Edifícios e Locais: cartões, planta (andares/portas), portas em série, arrastar bens, impressão da planta | `activos` › separador «Edifícios e Locais» (permissão `activos_edificios`) | FALTA | Alto |
| A3 | Folhas de inventário físico por edifício/andar/porta (contagem, extras, comparação, aplicar, fechar, imprimir) | `activos` › «Edifícios e Locais» › «Folhas de Inventário» | FALTA | Alto |
| A4 | Planos de manutenção (tempo ou contador, por activo ou categoria) | `activos_manutencao` › «Planos» | FALTA (só existe tabela vazia `planos_manutencao_ativos` com 2 colunas) | Médio |
| A5 | Ordens de manutenção (geração automática a partir dos planos, estados, conclusão, histórico, próxima data) | `activos_manutencao` › «Ordens de manutenção» | FALTA | Alto |
| A6 | Necessidades da ordem → requisição ao armazém / pedido de compra (repartição por stock) | `activos_manutencao` › ordem › «Requisitar» | FALTA | Alto |
| A7 | Requisições ao armazém (entrega, saída de stock, custo médio na ordem, cancelamento) | `activos_manutencao` › «Requisições ao armazém» | FALTA | Médio/Alto |
| A8 | Histórico de manutenções (registo simples) | `activos_manutencao` › «Histórico» | EXISTE (versão antiga) | — |
| A9 | Categorias: contas de ganho/perda restritas às classes 68/78; gasto 73; acumulada 18; mapeamento antigo fora da classe fica visível | `activos_categorias` | PARCIAL (valida 6/7, não 68/78) | Baixo |
| A10 | Cubo «Ativos»: novas dimensões (tipo de activo, CC, UN, fornecedor, ano de aquisição) e medidas | Cubo/BI | PARCIAL | Baixo |
| M1 | Método de Trabalho — Registo de Assunto 5W1H (CRUD, validações, numeração, histórico, alertas, vistas) | `mt_assuntos` (novo grupo de menu «Método de Trabalho») | FALTA | Alto |
| M2 | Deliberação pelo nível acima no organigrama (aprovação automática quando não há chefia) | `mt_assuntos` | FALTA | Médio |
| M3 | Integrações do assunto: pedido de compra (com deliberação de compras) e projecto (novo ou existente) com fase, tarefas, equipa e orçamento; bloqueios de encerramento | `mt_assuntos` | FALTA | Médio/Alto |
| R1 | Critérios de repartição por UN (6 formas: %, partes iguais, colaboradores, volume de negócios, área, personalizado) | `config` › Unidades de Negócio › «Critérios de repartição» (`#un-chaves`) | FALTA | Médio |
| R2 | Componente AlocacaoUN (alocar várias UN num objecto, com pré-visualização) em colaborador, pedido de compra, factura de compra directa, venda, recibo | fichas/formulários respectivos | FALTA | Médio |
| R3 | Repartição na contabilização: salários, facturas de compra (herança factura→encomenda→pedido), vendas | integrações contabilísticas | FALTA | Alto |
| R4 | Repartir uma linha pontual por UN (lançamento manual e tesouraria) | `lancamentos`, tesouraria (botão na linha) | FALTA (só se escolhe 1 UN por linha) | Baixo/Médio |
| R5 | UN e Centro de custo na LINHA de compras e vendas, com precedência linha › repartição do documento › cabeçalho | formulários de compra e venda | PARCIAL (colunas só em `itens_compra`; não usadas na contabilização; `itens_venda` não tem) | Médio |
| C1 | Cubo — novas dimensões/medidas em todos os cubos + cache de mapas por nome | Cubo/BI (`ui_cubo.js`) | PARCIAL (cubos base existem, sem as novas dimensões) | Médio |
| C2 | Cubo novo «RH — Rubricas (infotipos)» | Cubo/BI | FALTA | Médio |
| C3 | Caixa de filtro do pivot presa ao campo (correcção de UI) | Cubo/BI | N/A (frontend React próprio) | — |
| G1 | Relatório de gestão «Projectos»: venda contratada, venda global, facturado acumulado, por facturar, % facturado | `relatorios_gestao` › Projectos | FALTA (versão antiga em `ModuloProjetos.php`) | Baixo/Médio |
| G2 | Painel de módulos «Projectos»: KPIs de venda, margem prevista, NC abatem facturação, carteira com Venda Global/Por Facturar | Painel de módulos › Projectos | PARCIAL (NC já abatem; faltam os KPIs de venda) | Baixo |
| G3 | Consolidação: limpar `account_client`/`account_supplier` dos terceiros unidos | Consolidação | FALTA (depende da conta cliente/fornecedor separada, V1276) | Baixo (depende) |
| G4 | `ui_dashboard.js` | — | Só espaços em branco — sem alteração funcional | — |

---

## 1. ACTIVOS

### A1. Tipo de activo (BEM / EDIFÍCIO) e localização física
1. **O que faz**: na ficha do activo surge o campo «Tipo de Activo» (Bem ou Edifício/Local — não muda depois de criado). Um bem pode ser localizado com três selects encadeados Edifício › Andar › Porta. Na lista de activos aparece a coluna «Localização» (ou o selo EDIFÍCIO). Gravar exige porta se tiver escolhido edifício e andar.
   - `js/ui_assets.js:298-329` (campos), `:429-438` (`showAssetFormTipo`), `:465-480` (gravação), `:98-99`/`:217` (coluna); `js/activos_edificios.js:973-1028` (`assetLocalSelects`, `assetLocalMudou`, `assetLocalTexto`).
2. **Dados**: `fixed_assets.asset_type` ('BEM'|'EDIFICIO'), `building_id`, `floor_id`, `room_id` (Dexie v1268, `js/db_v2.js:867-873`). Um edifício tem sempre `building_id/floor_id/room_id = null`. O edifício é um activo normal (categoria, amortização, abate) — **não há fórmula de amortização própria** para edifícios.
3. **erp_laravel**: **FALTA** — `grep asset_type|building_id|room_id` sem resultados em `backend/` e `frontend/src`; `ativos_imobilizados` sem estes campos (`backend/database/migrations/2026_09_30_101000_criar_tabelas_activos.php`).
4. **Esforço**: Médio. Migração (3 FKs + enum), ajustes em `ServicoAtivos`, `ModalActivo.tsx`, lista `Activos.tsx`. Depende de A2 (tabelas de andares/portas).

### A2. Edifícios e Locais (planta)
1. **O que faz**: novo separador «Edifícios e Locais» em Activos (`js/ui_assets.js:41,59`). Cartões por edifício com nº de andares, portas, bens dentro e valor; «Planta» em diagrama (telhado, andares, portas como cartões); criar/editar/eliminar andares; criar portas em série (prefixo + intervalo «de/até», máx. 200 de cada vez, ignora números repetidos); editar porta (nome, tipo, capacidade); **arrastar bens** da lista «sem localização» para uma porta e entre portas; detalhe da porta; imprimir planta. Eliminar andar/porta liberta os bens (põe localização a null).
   - `js/activos_edificios.js:99` (`renderAssetBuildings`), `:158` (`assetBuildingPlan`), `:251-296` (arrasto e `assetMoverBem`), `:333-381` (andares), `:387-500` (portas), `:503` (impressão).
2. **Dados**: `asset_floors` {company_id, building_id, numero, nome, ordem}; `asset_rooms` {company_id, building_id, floor_id, numero, nome, tipo, capacidade, ordem}. Valor de uma porta/andar/edifício = Σ `acquisition_value` dos bens lá localizados.
3. **erp_laravel**: **FALTA** — nenhuma referência a `asset_floors`/`asset_rooms`; `frontend/src/modulos/activos/ecras.ts` não tem ecrã de edifícios; catálogo não tem `activos_edificios`.
4. **Esforço**: Alto (UI de planta com drag-and-drop em React). Permissões novas: `activos_edificios` com tarefas `activos_locais_gerir` e `activos_inventario_fisico` (`js/permissoes.js:384-387`).

### A3. Folhas de inventário físico
1. **O que faz**: gerar folha por âmbito EDIFÍCIO / ANDAR / PORTA com data, responsável e notas; a folha lista os bens esperados. Na contagem marca-se cada bem como Encontrado / Em falta / Noutro local (com a porta onde foi encontrado) / acrescentam-se bens Não previstos (EXTRA). «Marcar todos encontrados», comparação (esperado × encontrado), **aplicar** (move os bens DESLOCADOS para a porta onde foram encontrados), **fechar** (estado FECHADO, quem e quando), eliminar, imprimir folha em branco (para contar) ou de comparação com 3 assinaturas.
   - `js/activos_edificios.js:534-611` (geração), `:612` (lista), `:680` (folha), `:784-830` (contagem/extras), `:832` (comparação), `:888-915` (aplicar/fechar), `:930` (impressão).
2. **Dados**: `asset_inventories` {company_id, building_id, ambito, floor_id, room_id, data, responsavel, notas, estado ABERTO|FECHADO, esperados, valor_esperado, contado_em, aplicado_em, fechado_em/por}; `asset_inventory_lines` {inventory_id, asset_id, code, description, room_id, floor_id, valor, resultado ENCONTRADO|EM_FALTA|DESLOCADO|EXTRA, room_encontrada_id, notas} (fotografia do bem no momento da geração).
3. **erp_laravel**: **FALTA**. Nota: existe `frontend/src/modulos/activos/inventario/` (cartão do activo, reconciliação contabilística — `regrasInventario.ts`, `AlertaReconciliacao.tsx`), que **não** é inventário físico por local.
4. **Esforço**: Alto. Depende de A1/A2.

### A4. Planos de manutenção
1. **O que faz**: plano com código automático `PM-AAAA/0001`, nome, aplica-se a **um activo ou a toda uma categoria** (excluindo abatidos), tipo (Preventiva, Correctiva, Inspecção legal, Calibração/aferição, Melhoria), periodicidade por **TEMPO** (dias/semanas/meses/anos) ou por **CONTADOR** (horas de trabalho / km), antecedência em dias, duração prevista, responsável, instruções, lista de tarefas e itens previstos (material/serviço, artigo, quantidade). Situação: Vencido / A vencer / Em dia / Sem data / Inactivo. Não se elimina plano que já gerou ordens (desactiva-se).
   - `js/activos_manutencao.js:117-158` (planos, situação, validação, gravação, eliminação); UI `js/activos_manutencao_ui.js:108` (`areaPlanos`).
2. **Dados / regras**: `asset_maintenance_plans` {codigo, nome, asset_id, category_id, tipo, periodicidade{modo, intervalo, unidade}, proxima_data, proximo_contador, contador_actual, antecedencia_dias, duracao_horas, responsavel, instrucoes, tarefas[], itens[], activo, ultima_data, execucoes} (Dexie v1277).
   - Situação TEMPO: `d = proxima_data − hoje`; `d<0` vencido; `d ≤ antecedencia_dias` a vencer.
   - Situação CONTADOR: `falta = proximo_contador − contador_actual`; `falta ≤ 0` vencido; `falta ≤ max(1, 10% × intervalo)` a vencer.
3. **erp_laravel**: **FALTA**. Existe só a tabela migrada `planos_manutencao_ativos` com `empresa_id` e `ativo_imobilizado_id` (`2026_09_30_101000_criar_tabelas_activos.php:106-115`, modelo `app/Models/Base/PlanoManutencaoAtivoBase.php`), sem serviço, rota nem ecrã.
4. **Esforço**: Médio (alterar a tabela + serviço + ecrã).

### A5. Ordens de manutenção
1. **O que faz**: ordem `OM AAAA/0001` sobre um activo (manual ou gerada pelo plano), prioridade (Baixa…Urgente), estados PLANEADA → AGUARDA_MATERIAL → EM_CURSO → CONCLUIDA | CANCELADA, checklist de tarefas, necessidades. Botão «Gerar ordens dos planos»: para cada plano vencido/a vencer e cada activo alvo, cria ordem (prioridade ALTA se vencido) — **um plano com ordem aberta para o mesmo activo não gera outra**. Conclusão exige descrição do trabalho, avisa se há necessidades por receber (pode forçar), regista horas, custo de mão-de-obra, custo de serviços (sugerido pelas facturas de compra ligadas à ordem ou, sem factura, Σ qtd × preço das linhas do pedido de compra), leitura do contador. Cancelamento exige motivo (requisições/pedidos já emitidos não se apagam).
   - `js/activos_manutencao.js:220-332`, `:568-583` (`custoServicosDaOrdem`); UI `js/activos_manutencao_ui.js:320` (`areaOrdens`), `:641-680` (conclusão).
2. **Dados / regras**: `asset_maintenance_orders` {numero, asset_id, plan_id, tipo, prioridade, estado, data_prevista, data_inicio, data_fim, descricao, responsavel, tarefas[{texto, feito}], horas, custo_mao_obra, custo_materiais, custo_servicos, resolucao, leitura_contador, motivo_cancelamento…}; `asset_maintenance_order_items` {order_id, tipo MATERIAL|SERVICO, product_id, descricao, quantidade, quantidade_recebida, estado POR_PEDIR|REQUISITADO|EM_COMPRA|RECEBIDO|CANCELADO, destino ARMAZEM|COMPRA|AMBOS, requisicao_id, purchase_request_id, qtd_armazem, qtd_compra, custo}.
   - **Custo total = materiais + serviços + mão-de-obra.**
   - Ao concluir: escreve em `asset_maintenance_records` (histórico que a ficha do activo lê, com `order_id`/`order_numero`) e avança o plano: TEMPO → `proxima_data = data_fim + intervalo` (a partir da execução real, não da data teórica); CONTADOR → `contador_actual = leitura`, `proximo_contador = leitura + intervalo`; `execucoes += 1`, `ultima_data = data_fim`.
   - Nota: a manutenção **não gera lançamento contabilístico**; o custo de material sai pelo movimento de stock e o de serviços pelo circuito de compras.
3. **erp_laravel**: **FALTA**. `app/Services/Ativos/ServicoManutencaoAtivos.php` só tem `registar` e `executar` (modelo antigo de registo único); rotas `routes/api/ativos.php:33-36` (manutencoes index/store/executar/destroy).
4. **Esforço**: Alto. Depende de A4 e de Compras/Stock.

### A6. Necessidades → requisição ao armazém / pedido de compra
1. **O que faz**: pré-visualização linha a linha de para onde vai cada necessidade e porquê; depois cria **no máximo uma requisição ao armazém e um pedido de compra** por accionamento.
   - `js/activos_manutencao.js:337-457` (`ehMaterial`, `stockDisponivel`, `planoDeRequisicao`, `requisitar`).
2. **Regras de repartição**:
   - SERVIÇO (ou artigo sem `is_inventory`, ou linha sem artigo) → pedido de compra pela quantidade toda;
   - MATERIAL: `paraArmazem = min(qtd, max(0, stock))`, `paraCompra = qtd − paraArmazem` (stock do armazém escolhido; sem armazém, Σ `warehouse_stock` do artigo; em último caso `products.stock_qty`).
   - Requisição `RQ AAAA/0001` em `maint_requisitions` + `maint_requisition_lines`. Pedido de compra em `purchase_requests` (status PENDENTE, `maintenance_order_id`, `asset_id`, **herda CC e UN do activo**) + `purchase_items` (parent_type REQUEST, `maintenance_order_id`, `maintenance_item_id`).
   - Ordem PLANEADA passa a AGUARDA_MATERIAL. Permissão própria `activos_manut_requisitar`.
3. **erp_laravel**: **FALTA** (sem `maint_requisitions`; `pedidos_compra` sem `ordem_manutencao_id`/`ativo_imobilizado_id`).
4. **Esforço**: Alto. Depende de A5, `ServicoProcessoCompras`, `stock_armazem`.

### A7. Requisições ao armazém
1. **O que faz**: lista de requisições; entrega total ou parcial por linha (valida falta e stock disponível), cancelamento com motivo (proibido se já houve entrega).
   - `js/activos_manutencao.js:460-565`; UI `js/activos_manutencao_ui.js:691` (`areaRequisicoes`).
2. **Regras**: custo unitário = custo médio (`calculateAverageCosts`) ou `cost_price`/`unit_price`; baixa em `products.stock_qty` e `warehouse_stock`; movimento `inventory_movements` tipo SAIDA com referência à requisição e à ordem; `custo_materiais` da ordem += Σ qtd × custo; necessidade passa a RECEBIDO quando o armazém entregou tudo e não há parte em compra; requisição SATISFEITA/PARCIAL; ordem AGUARDA_MATERIAL → EM_CURSO quando completa (marca `data_inicio`). Cancelar devolve as necessidades a POR_PEDIR.
3. **erp_laravel**: **FALTA**.
4. **Esforço**: Médio/Alto (reutilizar o serviço de saídas de stock e custo médio já existentes).

### A8. Histórico de manutenções
- Legado novo mantém `asset_maintenance_records` como separador «Histórico» (`js/activos_manutencao_ui.js:806`); o ecrã antigo foi retirado de `ui_assets.js` (só ficou `deleteMaintenanceRecord`).
- erp_laravel: **EXISTE** (equivalente ao antigo) — `ServicoManutencaoAtivos.php`, `frontend/src/modulos/activos/Manutencao.tsx`, tabela `registos_manutencao_ativos`. Faltará acrescentar `ordem_manutencao_id`/`numero_ordem` quando A5 existir.
- Permissões novas: `activos_manut_gerir`, `activos_manut_requisitar`, `activos_manut` (`js/permissoes.js:394-398`); o catálogo do erp_laravel só tem `activos_manut` (`backend/resources/permissoes/catalogo.json:1719-1728`).

### A9. Categorias — classes de contas
1. **O que faz**: na categoria, ganho na venda só lista contas da classe **68**, perda/sinistro só **78**, gasto **73**, amortização acumulada **18** (comparação ignora pontos: 68.1.1 = 681001). Uma conta antiga fora da classe aparece assinalada «fora da classe (mapeamento anterior)» para não se perder ao gravar. `js/ui_assets.js:985-1033`, `:1136-1150`.
2. **erp_laravel**: **PARCIAL** — `app/Services/Ativos/ServicoCategoriasAtivos.php:24-25` valida `conta_venda` classe 6 e `conta_perda` classe 7.
3. **Esforço**: Baixo (mudar prefixos 68/78 e o selector no `Categorias.tsx`; decidir o que fazer com dados migrados fora da classe).

### A10. Cubo de Ativos
- Novas dimensões: Tipo de ativo, Centro de custo, Unidade de negócio, Fornecedor, Ano de aquisição; medidas Valor de aquisição e «Amortizações» (contagem) — `js/ui_cubo.js:464-483`.
- erp_laravel: **PARCIAL** — `app/Services/Gestao/Paineis/Cubo/CatalogoCubo.php` (função `ativos()`) só tem activo, categoria, estado, contabilizada, amortização. Esforço Baixo (depois de A1 para «Tipo de ativo»).

---

## 2. MÉTODO DE TRABALHO (`js/modules/metodo`)

«Método» é o novo módulo **Método de Trabalho — Registo de Assunto 5W1H**: cada assunto/problema é registado respondendo às seis perguntas (What, Who, When, Where, Why, How) e percorre um ciclo com aprovação. Menu próprio «Método de Trabalho» › «Registo de Assuntos (5W1H)» (`index_arrumado.html:268-277`, `data-target="mt_assuntos"`, roteado em `js/app_v2.js:1073`).

### M1. Registo de Assunto 5W1H
1. **O que faz**: lista com 6 vistas (Todos, Por departamento, Por cliente, Por responsável, Por prazo, Por custo), caixa de alertas (prazo ultrapassado, a vencer em ≤ 48 h, bloqueados por pedido de compra não aprovado), formulário em 6 blocos, histórico de alterações campo a campo, anexos por URL.
   - Lógica `js/modules/metodo/assuntos.js` (`validar` :52, `gravar` :111, `alertas` :466, `permissoes` :483); UI `js/modules/metodo/assuntos_ui.js:52` (`renderAssuntos`), `:186-187`, `:533` (histórico).
2. **Dados / regras**: `mt_assuntos` (Dexie v1274) {numero `AS AAAA/001` (maior nº + 1, por ano), titulo ≤120, descricao, responsaveis[{tipo COLABORADOR|EXTERNO|FORNECEDOR, ref_id, nome}], responsavel_principal (chave `tipo:id`), data_inicio, data_fim, local_tipo INTERNO (departamento_id da estrutura orgânica) | EXTERNO (cliente_id, encomenda, morada), fundamentacao ≥ 50 caracteres, causa_raiz, recursos, custo_estimado > 0, moeda, metodo_resolucao ACCAO|PROJECTO, projecto_modo NOVO|EXISTENTE, project_id, gerar_pedido_compra, purchase_request_id, anexos[{nome,url}], deliberacao{…}, estado}; `mt_assunto_log` {assunto_id, accao, detalhe, por, em}.
   - Estados: RASCUNHO → EM_CURSO → CONCLUIDO; RASCUNHO/EM_CURSO → CANCELADO (com motivo); CANCELADO → RASCUNHO.
   - Em rascunho só o título é obrigatório. Responsável principal tem de estar na lista. Prazo < 48 h é aviso. Fornecedor em WHO ⇔ gerar pedido de compra (erro se pedir PC sem fornecedor; aviso no inverso).
   - Permissões: criador edita em rascunho; responsável a partir de «Em curso»; `mt_aprovar` = gestor (vê/edita tudo). Catálogo legado: módulo `metodo`, ecrã `mt_assuntos`, tarefas `mt_criar`, `mt_aprovar` (`js/permissoes.js:190-196`).
3. **erp_laravel**: **FALTA** — `grep mt_assuntos|5W1H` sem resultados em `backend/` e `frontend/src`; sem módulo `metodo` no catálogo nem pasta em `frontend/src/modulos/`.
4. **Esforço**: Alto (módulo novo: 2 tabelas, serviço, rotas, catálogo, ecrã com 6 vistas e formulário).

### M2. Deliberação (aprovação pelo nível acima)
1. **O que faz**: o assunto submete-se a aprovação; o aprovador é a chefia do colaborador ligado ao utilizador no organigrama (`EstruturaOrg.chefiaDe`), com o utilizador responsável da unidade que ele chefia. Sem chefia acima (ou sem colaborador ligado) a aprovação é **automática e fica registada como tal**. Aprovar põe EM_CURSO; recusar exige motivo. Superadmin e `mt_aprovar` podem decidir sempre.
   - `js/modules/metodo/assuntos.js:181-259`.
2. **Dados**: `mt_assuntos.deliberacao` {estado PENDENTE|APROVADO|RECUSADO, aprovador_employee_id, aprovador_nome, aprovador_utilizador, motivo_aprovador, automatica, pedida_em/por, decidida_em, decidido_por, nota}.
3. **erp_laravel**: **FALTA**. Dependência disponível: `unidades_organicas` tem `unidade_organica_pai_id`, `colaborador_responsavel_id`, `utilizador_responsavel` (`2026_09_30_100700_criar_tabelas_rh.php:823-851`); módulo `frontend/src/modulos/estrutura/` existe. É preciso um serviço «chefia de» no backend e a ligação utilizador ↔ colaborador por empresa.
4. **Esforço**: Médio.

### M3. Integrações (pedido de compra e projecto)
1. **O que faz**: ao passar a EM_CURSO (já aprovado e com validação completa):
   - se `gerar_pedido_compra`: cria `purchase_requests` (descrição `nº — título`, entrega = data_fim, `valor_estimado` = custo estimado, `mt_assunto_id`) e arranca a deliberação por valor de Compras (`ComprasDeliberacao.iniciar`);
   - se método PROJECTO/NOVO: cria `projects` (código `PRJ-<nº>`, INTERNO com UN = departamento ou EXTERNO com cliente, `origem METODO_TRABALHO`), uma fase (`project_milestones`) com o prazo do assunto, **uma tarefa por linha de «Recursos»**, equipa com os responsáveis (principal = «Responsável principal») e linha de orçamento DIVERSOS = custo estimado;
   - se PROJECTO/EXISTENTE: liga a um projecto da empresa não preso a outro assunto.
   - Encerramento bloqueado enquanto o projecto não estiver ENCERRADO/CANCELADO e enquanto o pedido de compra não estiver APROVADO. A ficha do projecto mostra o assunto de origem (`js/projectos_dashboard.js:402`).
   - `js/modules/metodo/assuntos.js:261-451`.
2. **erp_laravel**: **FALTA**. Dependências existentes: `ServicoProcessoCompras`, `ServicoDeliberacaoCompras`, tabelas `projetos`, `tarefas_projeto`, `equipas_projeto` (`2026_09_30_101100_criar_tabelas_projectos.php`). Faltam colunas `pedidos_compra.assunto_mt_id` e `projetos.assunto_mt_id`/`origem`.
4. **Esforço**: Médio/Alto.

---

## 3. REPARTIÇÃO / ALOCAÇÃO por Unidades de Negócio e Centros de Custo

Modelo (corrigido no legado): o **critério** guarda só a **forma** de repartir; o **objecto** (colaborador, pedido, venda, linha) guarda que unidades e, se a forma o pedir, o peso de cada uma (`unidades_alocadas = {criterio_id, unidades:[{business_unit_id, peso}]}`).

### R1. Critérios de repartição
1. **O que faz**: em Configurações › Unidades de Negócio aparece a secção «Critérios de repartição» (`js/modules/configuracoes/unidades_negocio.js:73-81,118`; UI `js/modules/configuracoes/reparticao_ui.js`). CRUD de critérios: código (A-Z0-9._-, ≤20, único), nome, forma, indicador, período de/até (para volume de negócios), notas, activo.
2. **Dados / fórmulas** (`js/modules/configuracoes/reparticao.js`): `bu_allocation_keys` {company_id, codigo, nome, criterio, indicador, periodo_de, periodo_ate, notas, activo} (Dexie v1275).
   - Indutor por forma: PARTES_IGUAIS = 1; COLABORADORES = nº de colaboradores activos com `business_unit_id` = UN; VOLUME_NEGOCIOS = Σ `total_liquido` (ou `total`) das vendas não anuladas da UN no período; PERCENTAGEM/AREA/PERSONALIZADO = peso escrito no objecto.
   - `percentagem_i = indutor_i / Σ indutor × 100` (4 casas) — normalizado a 100 % qualquer que seja a escala.
   - `repartir(valor)`: `parcela_i = round2(valor × pct_i/100)`; **a última parcela com peso = valor − acumulado** (absorve o arredondamento; a soma bate ao cêntimo).
   - Validação da alocação: ≥ 2 unidades, sem repetidas, sem pesos negativos, e nas formas manuais pelo menos um peso > 0.
3. **erp_laravel**: **FALTA** — sem `bu_allocation_keys`/`unidades_alocadas`; `frontend/src/modulos/config/UnidadesNegocio.tsx` é só o CRUD das UN. (As «repartições» existentes — `ServicoOrcamentos::repartirTopDown`, `ServicoAnaliticoProjetos::repartir` — são outra coisa: orçamento e curva S.)
4. **Esforço**: Médio (tabela, serviço com BCMath, ecrã, testes de arredondamento).

### R2. Componente de alocação no objecto
1. **O que faz**: bloco «Unidades de negócio» com select do critério (vazio = uma só UN, a do campo normal), linhas UN + peso (ou «parte igual»/«automático») e pré-visualização das percentagens e valores. Montado em: ficha do colaborador (`js/app_v2.js:9598`, gravado em `employees.unidades_alocadas` :9673/9679), pedido de compra (`js/ui_compras_v2.js:976`, :1073), factura de compra directa (`:1837`, :2075), venda (`js/ui_sales.js:1207`, :1902) e recibo (herda da 1.ª venda, `:3784-3797`).
   - `js/modules/configuracoes/alocacao_un.js`.
2. **Dados**: campo JSON `unidades_alocadas` em `employees`, `purchase_requests`, `purchase_orders` (lido), `purchase_invoices`, `sales`, `receipts`.
3. **erp_laravel**: **FALTA** (nenhuma coluna `unidades_alocadas`/equivalente).
4. **Esforço**: Médio (componente React reutilizável + colunas JSONB + validação no backend). Depende de R1.

### R3. Repartição na contabilização
1. **O que faz**:
   - **Salários** (`js/app_v2.js:6478-6505`): o custo de cada colaborador com alocação é dividido pelas UN (fracção = pct/100), última parcela absorve o arredondamento; sem alocação vai todo para a sua UN.
   - **Facturas de compra** (`js/ui_compras_v2.js:3500-3570`): a alocação herda factura → encomenda → pedido (como UN/CC); só se repartem as linhas de gasto — **não** se repartem contas começadas por 32 (fornecedor), 34 (IVA) e 22.
   - **Vendas** (`js/ui_sales.js:3360-3384`): repartem-se proveitos e CMV; **não** se repartem 31 (cliente) e 34 (IVA); linhas com UN própria (`_linhaPropria`) ficam como estão; descrição recebe «xx,xx% <UN>».
2. **erp_laravel**: **FALTA** — `ServicoContabilizacaoCompras.php:71` e `ServicoContabilizacaoVendas.php:115/171` usam só a UN/CC do cabeçalho; `ServicoFolhaSalarial.php:553` agrega por uma única UN/CC do resultado.
4. **Esforço**: Alto (três integrações, testes de equilíbrio, impacto em relatórios por UN). Depende de R1/R2.

### R4. Repartir uma linha pontual
1. **O que faz**: botão na linha do lançamento manual (`js/ui_lancamentos.js:1495-1512,1612`, `repartirLinhaUN`) e na linha de tesouraria (`js/ui_tesouraria.js:1113-1135`, `tesoRepartirLinha`): escolhe-se um critério/UN numa janela (`ReparticaoPagina.escolher`) e a linha é substituída por N linhas (uma por UN) com o mesmo total.
2. **erp_laravel**: **FALTA** — o lançamento aceita `linhas.*.unidade_negocio_id` (`app/Http/Requests/Contabilidade/CriarLancamentoRequest.php:49`), mas não há acção de repartir.
3. **Esforço**: Baixo/Médio (só frontend, reutiliza o serviço de R1 para calcular as parcelas).

### R5. UN e CC na linha de compras e vendas (`js/shared/linha_un_cc.js`)
1. **O que faz**: duas colunas opcionais (só aparecem se houver UN ou CC) «Un. Negócio» / «C. Custo» em cada linha, com «— cabeçalho —» por omissão. Precedência na contabilização: **linha › repartição do documento (`unidades_alocadas`) › cabeçalho**. Usado em `ui_compras_v2.js` e `ui_sales.js` (15 chamadas).
2. **erp_laravel**: **PARCIAL** — `itens_compra` já tem `unidade_negocio_id`/`centro_custo_id` (vêm do legado antigo, mostrados em `ServicoListagensCompras.php:38-41`) mas a contabilização (`ServicoContabilizacaoCompras.php:71-110`) agrega por conta+projecto e aplica a UN do cabeçalho; `itens_venda` não tem as colunas (`2026_09_30_100400_criar_tabelas_vendas.php:128`).
3. **Esforço**: Médio (colunas em `itens_venda`, campos na linha dos formulários, agregação por conta+projecto+UN+CC nas duas contabilizações).

---

## 4. CUBO / BI

### C1. Novas dimensões e medidas (`js/ui_cubo.js`)
- Correcção: a cache de mapas auxiliares passa a ser pelo **nome** do mapa e não pela tabela (antes, duas leituras de `products` devolviam uma pela outra) — `:71-117`.
- **Contabilidade**: Conta (3 díg.), Descrição da linha, Nº de lançamento, Origem (tipo de documento), medida «Linhas».
- **Vendas**: NIF/Província/Conta do cliente, Contabilizado, Liquidação (Pago/Parcial/Por receber), UN e CC (**a da linha manda sobre a do cabeçalho**), Código/Categoria/Natureza do artigo (Produto/Serviço), Taxa de IVA, Preço unitário, medida «Documentos».
- **Compras**: NIF/Conta do fornecedor, Código/Categoria/Natureza (Imobilizado/Existências/Serviço), Taxa de IVA, UN/CC da linha, Preço unitário, medida «Facturas».
- **Tesouraria**: Meio de pagamento, Classe da contrapartida, Contabilizado, Entradas/Saídas (Kz), «Movimentos».
- **Armazém**: Movimento (tipo), Código/Categoria do artigo, Unidade de medida, Documento, Entradas/Saídas (qtd), «Movimentos».
- **RH (processamentos)**: dimensões do colaborador (Função, Tipo de órgão, UN, CC, Estado, Vínculo, Aposentado, Género…), Estado do período, Dias contratados/trabalhados, Horas extra/falta, Base INSS, Adiantamentos, Subsídios de alimentação/transporte, Valor horas extra, Valor das faltas, «Colaboradores».
- **Ativos**: ver A10.
- erp_laravel: **PARCIAL** — os 8 cubos base existem (`app/Services/Gestao/Paineis/Cubo/CatalogoCubo.php`, `ServicoCubo.php`, `CuboController.php`, frontend `modulos/geral/comum/AnaliseDinamica.tsx`/`pivot.ts`), mas com as dimensões do legado antigo (ex.: contabilidade tem Conta 2 díg. e não 3; vendas sem NIF/categoria/natureza/taxa; RH sem função/órgão/dias/horas). Nota: o NIF é dimensão no legado — avaliar se convém expor (dados pessoais).
- Esforço: Médio (SQL por dimensão; UN/CC da linha de vendas depende de R5).

### C2. Cubo «Recursos Humanos — Rubricas (infotipos)»
- Uma linha por rubrica de cada recibo dos períodos FECHADO/VALIDADO: Infotipo, Natureza (Vencimento/Desconto), Entra no INSS, Entra no IRT, Falta, Horas, Detalhe do cálculo; medidas Valor, Vencimentos, Descontos, Valor com sinal (+venc/−desc) — `js/ui_cubo.js:385-428`.
- erp_laravel: **FALTA** (`grep rh_rubricas` sem resultados). Depende de as rubricas calculadas por colaborador/período estarem guardadas (no legado são recalculadas com `calculatePeriodData`).
- Esforço: Médio.

### C3. Caixa de filtro presa ao campo
- Correcção de posicionamento da PivotTable.js (`js/ui_cubo.js:586-708,800`). **N/A** no erp_laravel (pivot próprio em React).

---

## 5. RELATÓRIOS DE GESTÃO / PAINÉIS

### G1. Relatório de gestão — Projectos (`js/modules/gestao/relatorios_gestao.js:470-542`)
1. **Novo**: por projecto e no total — **Venda contratada** (encomenda associada ao projecto, sem IVA), **Aditamentos**, **Venda global** = venda + aditamentos aprovados, **Facturado acumulado** até ao fim do período (FT + FR das encomendas − NC), **Por facturar** = venda global − facturado acumulado, **% facturado** = facturado / venda global. Os proveitos do período passam a chamar-se «Proveitos do período». Tabela renomeada «Venda, facturação e rentabilidade por projecto». Venda/facturado são «situação actual» (flag `actual`).
2. **erp_laravel**: **FALTA** — `app/Services/Gestao/Relatorios/Modulos/ModuloProjetos.php:38-73` reproduz a versão antiga (sem venda/por facturar).
3. **Esforço**: Baixo/Médio (acrescentar ao `ServicoAnaliticoProjetos::rentabilidade` a venda da encomenda `vendas.id = projetos.encomenda_venda_id` e o facturado acumulado).

### G2. Painel de módulos — Projectos (`js/ui_painel_modulos.js:445-526`)
1. **Novo**: KPIs Venda Contratada, Venda Global (c/ aditamentos), Faturado (% da venda global), Por Faturar, Margem Prevista = venda global − orçamento (e %); NC que rectificam facturas da encomenda abatem ao facturado; gráfico com série «Venda global»; top-8 por venda global + orçamento + custo; carteira com colunas Venda Global, Faturado, Por Faturar.
2. **erp_laravel**: **PARCIAL** — `app/Services/Gestao/Paineis/PainelProjetos.php:51-59` tem Orçamento, Custo, Faturado, Margem Real, Aditamentos; o abatimento das NC já é feito em `ServicoAnaliticoProjetos::rentabilidade` (comentário em `ModuloProjetos.php`). Faltam os KPIs de venda e margem prevista.
3. **Esforço**: Baixo (partilha o cálculo de G1).

### G3. Consolidação (`js/consolidacao.js:441`)
- Ao unir terceiros na holding, limpa também `account_client` e `account_supplier` (novas contas separadas de cliente/fornecedor, Dexie v1276). erp_laravel: **FALTA**, mas só faz sentido depois de existir a separação `conta_cliente`/`conta_fornecedor` em `terceiros` (hoje só `codigo_conta` e `conta_compra_transitoria`). Fora do âmbito desta área; esforço Baixo depois dessa dependência.

### G4. `js/ui_dashboard.js`
- Diferenças só de espaços/linhas em branco (linhas 123, 355, 693-694). Nada a migrar.

---

## Dependências e ordem sugerida
1. **R1 → R2 → R5 → R3 → R4** (repartição): critérios primeiro, depois o componente de alocação, UN/CC na linha, e só então as três contabilizações (testes de equilíbrio ao cêntimo).
2. **A1 → A2 → A3** (edifícios e inventário físico); **A9** e **A10** são independentes e rápidas.
3. **A4 → A5 → A6/A7** (manutenção), dependem de Compras (`ServicoProcessoCompras`) e Stock (`stock_armazem`, custo médio). Alterar `planos_manutencao_ativos` (hoje 2 colunas) em vez de criar outra tabela.
4. **M1 → M2 → M3** (Método de Trabalho), dependem de Estrutura orgânica (`unidades_organicas`), Compras (deliberação) e Projectos.
5. **G1/G2** podem ir já (Baixo); **C1/C2** depois de R5 (UN da linha) e A1 (tipo de activo).

## Notas para o mapeamento
- Novas tabelas Dexie a acrescentar ao `backend/database/legado/esquema.json`/`mapa_de_para.json`: `asset_floors`, `asset_rooms`, `asset_inventories`, `asset_inventory_lines` (v1268), `mt_assuntos`, `mt_assunto_log` (v1274), `bu_allocation_keys` (v1275), `asset_maintenance_plans` (redefinida), `asset_maintenance_orders`, `asset_maintenance_order_items`, `maint_requisitions`, `maint_requisition_lines` (v1277); campos novos `fixed_assets.asset_type/building_id/floor_id/room_id`, `*.unidades_alocadas`, `purchase_requests.maintenance_order_id/asset_id/mt_assunto_id/valor_estimado`, `purchase_items.maintenance_order_id/maintenance_item_id`, `purchase_invoices.maintenance_order_id` (lido, nunca escrito no legado), `projects.mt_assunto_id/origem`, `asset_maintenance_records.order_id/order_numero`.
- Novas entradas de menu/permissões: grupo `metodo` (`mt_assuntos`, `mt_criar`, `mt_aprovar`); `activos_edificios` (`activos_locais_gerir`, `activos_inventario_fisico`) — é separador dentro de `activos`, não item de menu; `activos_manut_gerir`, `activos_manut_requisitar`.
- Observação sobre o legado: `mt_assuntos` entra no backup por empresa (`js/company_backup.js:31`), mas as tabelas de edifícios (`asset_floors`, `asset_rooms`, `asset_inventories*`), as novas de manutenção (ordens, itens, requisições — só `asset_maintenance_plans` e `asset_maintenance_records` lá estão) e `bu_allocation_keys` não aparecem nessa lista — confirmar no backup real antes da migração definitiva.
