# Anexo E — Tudo o que está fora da pasta do módulo (registo, permissões, BD Dexie, pontos de contacto)

> Anexo de `docs/paridade/IMOBILIARIO_INVENTARIO.md`. Notas de leitura integral do legado `v_boss/New/v2/js/modules/imobiliario` (evidência `ficheiro:linha`; abreviaturas definidas no próprio texto). Produzidas em 2026-11-06 por leitura, sem alterar código. Texto original dos leitores, não reescrito; as dúvidas e lacunas declaradas aqui são as oficiais.

# Imobiliário no legado: tudo o que está FORA de js/modules/imobiliario/

Raiz: C:\xampp\htdocs\ERP_CONSULVOLT_MEU\v_boss\New\v2 (abreviado `v2/`). Só leitura. Método: grep -rIn -i (imobili, imo_, imovel, arrend, inquilin, subarrend, fracc, parque, `imobiliario_`) em js, css, docs, Regras, Estruturas as Notas, ferramentas, index*.html, README, portal.html. Muitos acertos de "imobili" são IMOBILIZADO (activos fixos, contas 11/12): falsos positivos listados no fim.

## 1. Registo do módulo

| Item | Valor | Onde |
|---|---|---|
| id | `imobiliario` | v2/js/core/modulos_registo.js:19 |
| rótulo | «Gestão Imobiliária» | modulos_registo.js:19 |
| rotaBase | `imobiliario` | modulos_registo.js:19 |
| pacotes (carregamento lazy) | `[]` (vazio): NÃO é pacote do carregador; os 23 ficheiros carregam-se como `<script>` fixos no arranque | modulos_registo.js:19; index_arrumado.html:734-756; ausente de v2/js/modulos_manifesto.js (só tem fluxos/vendas/…; hits de "imobiliz" nas linhas 32 e 102 são do fluxo do imobilizado) |
| ecrãs -> sub-rota | imo_imoveis->imoveis, imo_ocupacao->ocupacao, imo_parque->parque, imo_areas->areas, imo_fichas->fichas, imo_obras->obras, imo_contratos->contratos, imo_rendas->rendas, imo_modelos->modelos, imo_sub->subarrendamento, imo_negocios->negocios, imo_rentabilidade->rentabilidade, imo_fiscal->fiscal | modulos_registo.js:19 |
| URLs resultantes | `#/imobiliario/<sub-rota>`; as rotas antigas `#/imo_xxx` também resolvem (rotas.js:30-37 usa ModuleRegistry.alvoDaRota; docs/ROUTING.md) | rotas.js:25-37 |
| Mapa de vistas (alvo -> render) | 13 entradas `'imo_xxx': (...a) => window.renderImoXxx(...a)`: Imoveis, Ocupacao, Parque, Areas, Fichas, Obras, Contratos, Rendas, Modelos, Sub, Negocios, Rentabilidade, Fiscal | v2/js/app_v2.js:1083-1095 (objecto `views`, exposto em `window._views` app_v2.js:1205) |
| Ícone do grupo | `fas fa-city` | index_arrumado.html:368 |
| Posição no menu | grupo `nav-group` entre «Estrutura Orgânica» e «Planeamento Estratégico» (comentário `<!-- Gestão Imobiliária -->` linha 365, fecha ~386, o seguinte começa na 388) | index_arrumado.html:365-386 |
| Dependências declaradas | nenhuma formal (sem `pacotes`, sem campo de dependências). Dependências reais em tempo de execução: `window.db`, `window.FacturaAGT` (carregado pelo pacote `vendas`; o imobiliário chama `window.carregarPacote('vendas')` se faltar), `window.Seletores`, `window.Dialogo`, `window.Notificacoes`, `window.tarefaIniciar`, `window.logMovement`, `window.getLocalDate`, `window.activeCompanyId`, `window.currentUser` | imobiliario_contratos.js:882-883, imobiliario_reservas_fin.js:155-156 e 471-472, imobiliario_transaccoes.js:534-535 |
| Ordem de scripts | dados -> obras -> planos -> obras_ui -> planos_ui -> contratos -> templates -> subarrendamento -> transaccoes -> rentabilidade -> fiscal -> contratos_ui -> subarrendamento_ui -> transaccoes_ui -> rentabilidade_ui -> fiscal_ui -> parque -> parque_ui -> areas -> reservas_fin -> areas_ui -> ficha_ui -> ui. Todos com cache-buster `?v=20261106imoNN` (imo10..imo18). Vêm ANTES de `permissoes.js` (index:780) e depois de `formularios_linhas_config.js` (index:711) | index_arrumado.html:734-756 |
| `window.Imobiliario` | API pública congelada: `Object.freeze({...})` | imobiliario_dados.js:631 (consumida por relatorio_contas.js:380) |

### 1.1 Menu lateral: 13 itens (ordem real, `data-target` -> rótulo -> ícone FontAwesome)
index_arrumado.html:372-384:
1. imo_imoveis -> Imóveis -> fa-building (372)
2. imo_ocupacao -> Mapa de Ocupação -> fa-table-cells (373)
3. imo_parque -> Parqueamento -> fa-square-parking (374)
4. imo_areas -> Áreas Comuns -> fa-building-columns (375)
5. imo_contratos -> Contratos -> fa-file-signature (376)
6. imo_rendas -> Rendas do Período -> fa-money-bill-wave (377)
7. imo_sub -> Subarrendamento -> fa-link (378)
8. imo_negocios -> Compra e Venda -> fa-handshake (379)
9. imo_rentabilidade -> Rentabilidade -> fa-chart-line (380)
10. imo_fiscal -> Fiscalidade -> fa-landmark (381)
11. imo_modelos -> Modelos e Taxas -> fa-file-lines (382)
12. imo_obras -> Apetrechamento e Manutenção -> fa-screwdriver-wrench (383)
13. imo_fichas -> Fichas de Apetrechamento -> fa-clipboard-list (384)

Visibilidade (comportamento geral): superadmin vê tudo; os outros: cada `.nav-item` com `!window.hasView(target)` fica `display:none` (app_v2.js:1370-1378) e o grupo inteiro esconde-se se todos os itens ficarem escondidos (app_v2.js:1381-1386). Navegação por rota: guarda de sessão, empresa e permissão antes de abrir (rotas.js:1-15 cabeçalho).

## 2. Permissões (v2/js/permissoes.js)

Estrutura: o catálogo é `CATALOGO` (módulo > ecrã > tarefa). Os 13 ecrãs do imobiliário estão dentro do módulo de permissões **`geral`** (id 'geral', nome «Geral», ícone fa-home, permissoes.js:34), NÃO num módulo próprio `imobiliario`, entre `est_plano` (37-40) e `est_diagnostico` (101). Cada ecrã E(vista, nome, tarefas, {nota}); cada tarefa T(chave, rótulo, funçõesGlobais[], sensível=1).
Chave de consulta de um ecrã = `<ecra>_view` (permissoes.js:647), p.ex. `imo_contratos_view`. Tarefa = a própria chave (p.ex. `imo_renda_aprovar`). As funções listadas em cada T são `window.<função>` que `aplicarGuardas()` (permissoes.js:852) envolve com verificação: mesmo chamadas directamente falham sem permissão; botões que as chamam ficam "Sem permissão". Consequência para o porte: os nomes das funções do módulo (imoGravarImovel, …) são contrato com o catálogo.

| Ecrã (vista -> `_view`) | Linha | Tarefa (chave) [sensível=S] | Funções protegidas |
|---|---|---|---|
| imo_imoveis | 41-44 | imo_imovel_editar | imoGravarImovel, imoApagarImovel |
| | | imo_fraccao_editar | imoGravarUnidade, imoApagarUnidade, imoImportarPortas, imoFraccaoUnica |
| | | nota: «Um imóvel próprio liga-se ao edifício do imobilizado; os de terceiros exigem proprietário.» | |
| imo_ocupacao | 45-47 | imo_estado_mudar | imoMudarEstado |
| imo_parque | 48-52 | imo_vaga_editar | imoParqueVaga, imoParqueGravarVaga, imoParqueApagar, imoParqueGerar, imoParqueGerarOk |
| | | imo_vaga_afectar | imoParqueAfectar, imoParqueAfectarOk, imoParqueLibertar |
| | | imo_vaga_veiculos | imoParqueVeiculos, imoParqueGravarVeic |
| imo_areas | 53-60 | imo_area_editar | imoAreaEditar, imoAreaGravar, imoAreaApagar |
| | | imo_reserva_marcar | imoReservaEditar, imoReservaGravar, imoResApagar |
| | | imo_reserva_aprovar **S** | imoResMudar |
| | | imo_reserva_devedor **S** | imoReservaAutorizarDevedor |
| | | imo_reserva_facturar | imoResFacturar, imoResReceber, imoResRecOk |
| | | imo_reserva_caucao **S** | imoResCaucao, imoResCauOk, imoResVistoria, imoResVisOk |
| | | nota: área comum não é fracção; autorizar devedor fica gravado com o nome; caução nunca é proveito (só a parte retida por danos, com vistoria) | |
| imo_fichas | 61-63 | imo_ficha_editar | imoGravarFicha, imoApagarFicha |
| imo_obras | 64-72 | imo_obra_editar | imoGravarObra, imoApagarObra, imoEditarNecessidades, imoGravarNecessidades, imoAbrirApetrechamento |
| | | imo_obra_pedir **S** | imoPedirMaterial, imoConfirmarPedido |
| | | imo_obra_receber | imoReceber |
| | | imo_obra_fechar | imoFecharObra, imoCancelarObra |
| | | imo_bens_editar | imoGravarBem, imoApagarBem, imoImportarBens |
| | | imo_plano_editar | imoPlanoGravar, imoPlanoEliminar |
| | | imo_plano_gerar | imoPlanoGerar, imoPlanoGerarTodos |
| imo_contratos | 73-78 | imo_contrato_editar | imoArrGravar, imoArrEditar |
| | | imo_contrato_activar **S** | imoArrActivar, imoArrConfirmarAct |
| | | imo_contrato_terminar **S** | imoArrConfirmarSai, imoArrEliminar |
| | | imo_contrato_docs | imoArrVerDoc, imoArrDoc, imoArrImprimirCC |
| imo_rendas | 79-84 | imo_renda_gerar | imoArrGerar, imoArrGerarTudo |
| | | imo_renda_aprovar **S** | imoRendasAprovar, imoArrAprovar, imoArrAnular |
| | | imo_renda_facturar **S** | imoRendasFacturar, imoArrFacturar |
| | | imo_renda_pagar **S** | imoConfirmarPagar, imoConfirmarPagarUma, imoArrDesligar |
| imo_sub | 85-87 | imo_sub_debitar **S** | imoConfirmarDeb |
| imo_negocios | 88-92 | imo_neg_editar | imoNegGravar, imoNegGravarCustos, imoNegEliminar |
| | | imo_neg_fase **S** | imoNegAvancar, imoNegCancelar, imoNegConfirmarC |
| | | imo_neg_concluir **S** | imoNegConcluir, imoNegConfirmarConcl |
| | | nota: concluir leva o custo ao imobilizado (compra) ou apura mais-valia e abate o activo (venda); separar quem regista de quem conclui | |
| imo_rentabilidade | 93-94 | (sem tarefas; só consulta) | nota: junta rendas dos dois lados, impostos e custos das obras: quem vê, vê o negócio todo |
| imo_fiscal | 95-97 | imo_fiscal_entregar **S** | imoFiscalGravar, imoFiscalApagar |
| imo_modelos | 98-101 | imo_modelo_editar | imoModeloGravar, imoModeloEliminar, imoArrImportarModelos, imoArrImportarModelos2 |
| | | imo_taxas_editar **S** | imoGravarTaxas |

Contagens: 13 ecrãs (13 chaves `*_view`), 35 tarefas, 14 sensíveis, ~100 funções globais protegidas. Chaves de tarefa (35): imo_imovel_editar, imo_fraccao_editar, imo_estado_mudar, imo_vaga_editar, imo_vaga_afectar, imo_vaga_veiculos, imo_area_editar, imo_reserva_marcar, imo_reserva_aprovar, imo_reserva_devedor, imo_reserva_facturar, imo_reserva_caucao, imo_ficha_editar, imo_obra_editar, imo_obra_pedir, imo_obra_receber, imo_obra_fechar, imo_bens_editar, imo_plano_editar, imo_plano_gerar, imo_contrato_editar, imo_contrato_activar, imo_contrato_terminar, imo_contrato_docs, imo_renda_gerar, imo_renda_aprovar, imo_renda_facturar, imo_renda_pagar, imo_sub_debitar, imo_neg_editar, imo_neg_fase, imo_neg_concluir, imo_fiscal_entregar, imo_modelo_editar, imo_taxas_editar.

Mecânica relevante (permissoes.js): `hasView(key)` 769-785 (superadmin/`all===true` passam; perfil novo `_v2`: `ecraVisivel` = tem `_view` OU alguma tarefa do ecrã; perfil antigo cai em `app_v2.hasView`); `canEdit/canDelete` 787-798 (`canDelete` só conta tarefas sensíveis). Atribuir uma tarefa implica a consulta do ecrã (permsDoModelo 727-732 liga `consulta(t.ecra)`).

### 2.1 Perfis-modelo (MODELOS, permissoes.js:703-725)
- NENHUM dos 17 perfis-modelo nomeia explicitamente chaves `imo_*` nem tem perfil «Gestor imobiliário».
- Auditor / Consulta (~linha 714): `ver` = TODOS os ecrãs excepto config_utilizadores, config_perfis, config_manutencao, config_migração -> recebe as 13 `imo_*_view` automaticamente; `tarefas: []` (só leitura).
- Direcção (~715): `ver` = o mesmo conjunto -> 13 `imo_*_view`; `tarefas` = lista fixa SEM nenhuma chave `imo_*` (só consulta; não activa, aprova, factura nem conclui).
- Contabilista, Tesoureiro, Técnico de RH, Responsável de Salários, Comercial/Facturação, Operador POS, Comprador, Fiel de Armazém, Controller, Resp. Departamento, Comercial CRM, Director Comercial, Colaborador portal, Gestor Estrutura, Directores/Gestor/Encarregado/Controller de Projectos: sem imobiliário.
- SEGREGACAO (permissoes.js:678-697): 19 pares, nenhum com chaves imo_*. As próprias notas pedem separar «quem regista de quem conclui» (imo_neg_*), «aprova vs factura» (imo_renda_*), mas NÃO há regra de conflito codificada: lacuna a decidir no porte.
- `fluxo_processos` visibilidade (hasView 772-774): lista de vistas que dão acesso ao painel de fluxos NÃO inclui nenhuma `imo_*`: um utilizador só imobiliário não vê o Fluxo de Processos.
- Painel de módulos (ui_painel_modulos.js:40-54, `permitido` 55-59): lista de painéis (vendas, compras, …, activos «Ativos Fixos», projetos, orcamento, crm, acrescimos, estrutura, operacoes, cubo, grupo) NÃO tem painel imobiliário nem inclui `imo_*` nas views de «Pendentes e Alertas». CARROSSEL_ORDEM (1290) também não. Nada de KPIs imobiliários no dashboard.

## 3. Base de dados: coleções/stores do imobiliário (v2/js/db_v2.js, Dexie)

Convenção: todas usam `imo_company_id` (NÃO `company_id`); decisão deliberada análoga a `ad_company_id` (db_v2.js:786: «para não activar os hooks de exercício encerrado/dados mestre»). Consequências: (a) `checkYearLock`/hooks de exercício encerrado (db_v2.js:~338-360) não se aplicam; (b) tabelas ausentes de `tableModuleMap` (db_v2.js:315-336) -> sem log de auditoria automático por hook (o módulo chama `window.logMovement('Gestão Imobiliária', …)` à mão: imobiliario_dados.js:627, imobiliario_obras.js:472; logMovement em app_v2.js:2189 grava em `audit_logs` com `user:'admin'` fixo); (c) ver 6.3 (backup/eliminar empresa).

Dexie: cada `version(n).stores({...})` declara só as tabelas alteradas. Versões do imobiliário (1289-1298); as seguintes (1299, 1300) são org_manual e proc_*. Versão final do ficheiro: 1300 (db_v2.js:1393).

17 tabelas finais (keyPath `++id`; índices simples e compostos na ÚLTIMA versão que as declara):

| Tabela | Versão(ões) | Índices (além de `++id`) | Linhas |
|---|---|---|---|
| imo_properties | 1289 | imo_company_id, codigo, papel, estado, building_id, proprietario_id, [imo_company_id+papel], [imo_company_id+codigo] | db_v2.js:1234 |
| imo_units | 1289; 1296 | imo_company_id, property_id, room_id, codigo, estado, finalidade, **natureza, park_unit_id** (1296), [imo_company_id+property_id], [imo_company_id+estado], [imo_company_id+natureza] | 1235; 1335 |
| imo_fitout | 1290 | imo_company_id, tipologia, finalidade, activo, [imo_company_id+activo] | 1247 |
| imo_fitout_items | 1290 | imo_company_id, fitout_id, product_id, [imo_company_id+fitout_id] | 1248 |
| imo_unit_assets | 1290 | imo_company_id, unit_id, product_id, asset_id, estado, [imo_company_id+unit_id] | 1249 |
| imo_works | 1290 | imo_company_id, numero, property_id, unit_id, tipo, estado, quem_paga, [imo_company_id+estado], [imo_company_id+unit_id] | 1250 |
| imo_work_items | 1290 | imo_company_id, work_id, product_id, estado, [imo_company_id+work_id] | 1251 |
| imo_leases | 1291; 1292 | imo_company_id, numero, unit_id, property_id, terceiro_id, estado, tipo, **posicao, parent_lease_id** (1292), [imo_company_id+estado], [imo_company_id+unit_id], [imo_company_id+posicao] | 1264; 1280 |
| imo_rents | 1291; 1292 | imo_company_id, lease_id, periodo, estado, vencimento, sale_id, **treasury_id, posicao** (1292), [imo_company_id+periodo], [imo_company_id+estado], [lease_id+periodo], [imo_company_id+posicao] | 1265; 1281 |
| imo_templates | 1291 | imo_company_id, tipo, activo, [imo_company_id+tipo] | 1266 |
| imo_settings | 1291 | imo_company_id (1 linha por empresa; `taxas[ano]` dentro) | 1267 |
| imo_deals | 1293 | imo_company_id, numero, tipo, estado, property_id, unit_id, terceiro_id, data_escritura, [imo_company_id+tipo], [imo_company_id+estado] | 1296 |
| imo_deal_costs | 1293 | imo_company_id, deal_id, tipo, [imo_company_id+deal_id] | 1297 |
| imo_work_plans | 1294 | imo_company_id, property_id, unit_id, tipo, activo, [imo_company_id+activo] | 1311 |
| imo_tax_filings | 1295 | imo_company_id, tipo, ano, periodo, property_id, estado, [imo_company_id+tipo], [imo_company_id+ano] | 1325 |
| imo_amenities | 1297; 1298 (repetida igual) | imo_company_id, property_id, tipo, regime, activo, [imo_company_id+property_id], [imo_company_id+activo] | 1357; 1369 |
| imo_bookings | 1297; 1298 (repetida igual) | imo_company_id, amenity_id, property_id, unit_id, lease_id, data, estado, [imo_company_id+data], [amenity_id+data], [imo_company_id+estado] | 1358; 1370 |

Versões e migrações (db_v2.js):
- 1289 (1224-1236) cadastro: properties, units. Liga a fixed_assets/asset_rooms: `imo_properties.building_id` -> fixed_assets (asset_type EDIFICIO), `imo_units.room_id` -> asset_rooms (comentário 1228). Regra: contrato prende-se SEMPRE a fracção; prédio sem divisões recebe fracção «Única».
- 1290 (1238-1252) apetrechamento/manutenção: fitout, fitout_items, unit_assets, works, work_items. Reaproveita `maint_requisitions` (campo `imo_work_id`, sem `order_id`) e `purchase_requests`. `imo_works` guarda `quem_paga` (senhorio/inquilino).
- 1291 (1254-1268) arrendamento: leases, rents, templates, settings. Taxas por ano em `imo_settings.taxas[ano]` (isenções IP vêm da lei do OGE, anual, não podem ficar no código). IP retido pelo inquilino; Selo encargo do senhorio.
- 1292 (1270-1286) subarrendamento: `posicao` SENHORIO|INQUILINO; `parent_lease_id`; upgrade: `posicao='SENHORIO'` em leases e rents existentes (1283-1286). Lado SENHORIO -> renda vira factura de venda (sale_id); lado INQUILINO -> documento de tesouraria de pagamento (treasury_id).
- 1293 (1288-1298) compra e venda: deals, deal_costs. SISA acabou; transmissão = IP 2% + selo 0,3% do ADQUIRENTE (comentário 1294-1295).
- 1294 (1300-1317) planos de manutenção: `imo_work_plans`; ordens geradas apontam `plan_id` (campo sem índice). Upgrade: `ambito='FRACCAO'` e `unidades=[unit_id]` em contratos existentes. Campos `ambito`, `unidades` sem índice.
- 1295 (1319-1326) fiscalidade: `imo_tax_filings` (registo do acto de entregar: Modelo 1, retenções, selo; cálculo refaz-se sempre dos dados).
- 1296 (1328-1345) estacionamento: a vaga é uma linha de `imo_units` com `natureza:'ESTACIONAMENTO'` (+`park_unit_id`, `park_afectacao`). Upgrade 1338: natureza = ESTACIONAMENTO se finalidade==='ESTACIONAMENTO' ou tipologia==='GARAGEM'; ARRECADACAO se tipologia==='ARRECADACAO'; senão HABITACIONAL; `park_afectacao='AUTONOMA'` nas vagas; `park_unit_id=null`.
- 1297 (1347-1359) áreas comuns e reservas: tabelas próprias (a área comum NÃO é fracção: não arrenda, não vende, não conta na ocupação).
- 1298 (1361-1382) reservas pagas, caução e danos: só campos novos; upgrade: `prazo_pagamento_horas=48`, `cancel_dias=7`, `cancel_pct=50` em amenities sem esses valores. Regra: a caução nunca é proveito; só a parte retida por danos com vistoria.

Campos do imobiliário em tabelas de OUTROS módulos (sem índice, não declarados no stores; só aparecem no código do módulo):
- `maint_requisitions.imo_work_id` (+ `order_id:null`, `asset_id:null`): imobiliario_obras.js:334-340. Tabela declarada em db_v2.js:1090.
- `maint_requisition_lines.imo_work_item_id` (`order_item_id:null`): imobiliario_obras.js:341-345.
- `purchase_requests.imo_work_id`, `.imo_unit_id`: imobiliario_obras.js:352-358 (declarada db_v2.js:36).
- `purchase_items.imo_work_id`, `.imo_work_item_id` (parent_type 'REQUEST'): imobiliario_obras.js:360-367; lidos em imobiliario_rentabilidade.js:81 (custo real da obra).
- `imo_rents.sale_id` -> sales; `imo_rents.treasury_id` -> treasury_documents (db_v2.js:1265, 1281). Também tocados: sale_items, treasury_items, products, warehouses, third_parties, companies, asset_depreciations, fixed_assets, asset_rooms, asset_floors (contagem por grep nos ficheiros do módulo).

## 4. Pontos de contacto com outros módulos (fora da pasta)

### 4.1 Relatório e Contas (v2/js/relatorio_contas.js)
- Secção do relatório: `{ id:'imobiliario', titulo:'Carteira Imobiliária', tabela:'imobiliario', soSeHouver:'imobiliario' }` relatorio_contas.js:80-81 (entre `estrategia` 78 e `liquidez` 82). Só aparece se `d.imobiliario` existe.
- Gatilho: ao montar os dados do relatório de um ano (`d`): relatorio_contas.js:376-387.
- Dados que passam: `window.Imobiliario.resumo()` (cadastro: imoveis, unidades, taxaOcupacao, areaBruta); se `!cad.imoveis` devolve null (secção some-se); depois `window.ImobiliarioFiscal.resumoAnual(ano)` e `.mapaDetencao(ano)`. Resultado `{ cadastro, fiscal, detencao, ano }`. Cada chamada com `.catch(()=>null)`; só monta se `window.ImobiliarioFiscal && window.Imobiliario` existem.
- Renderização `TABELAS.imobiliario`: relatorio_contas.js:540-575. Campos consumidos:
  - cadastro: `imoveis, unidades, taxaOcupacao (null -> «—»), areaBruta`.
  - fiscal: `rendas_recebidas, rendas_pagas, detencao, selo, retencao_a_entregar, retencao_por_outros, transmissoes{compras, vendas, mais_valias, menos_valias, impostos}, modelo1_entregues, modelo1_total, retencoes_por_entregar`.
  - detencao: `linhas[{nome, matriz, calculo{conhecido, vpt, total}}], total, sem_vpt`.
- Texto fixo: «Os três impostos acima têm naturezas distintas e não se somam…», «Os impostos da transmissão … são encargo do adquirente».
- Validações: nenhuma (só tolerância a null). Nada se grava de ambos os lados (só leitura).
- Permissão: a do Relatório e Contas (`relatorio_contas`/`rc_editar`), não há chave imo. Narrativa do processo `fecho`: fluxo_narrativa.js:368.
- Contrato implícito para o porte: `Imobiliario.resumo()`, `ImobiliarioFiscal.resumoAnual(ano)`, `ImobiliarioFiscal.mapaDetencao(ano)` com as formas acima.

### 4.2 Vendas / Facturação AGT (v2/js/facturacao_agt.js, ui_sales.js)
- O módulo factura rendas, reservas e vendas de imóveis chamando `window.FacturaAGT.{numerar, numerarLocal, libertar, selar}` (API pública: facturacao_agt.js:567-570: TIPOS, OPERACOES, ISENCOES, tipoAGT, codigoTaxa, emRegime, config, gravarConfig, series, gravarSerie, eliminarSerie, numerar, numerarLocal, reservarNumero, libertar, construir, validar, prevalidar, selar, revalidar, saldoCreditavel, diagnostico). Chamadas no módulo: 4 numerar, 4 numerarLocal, 6 libertar, 8 selar (grep na pasta).
- Se a API não existir, o módulo carrega o pacote `vendas` (`window.carregarPacote('vendas')`; v2/js/carregador.js:125; pacote `vendas` em modulos_manifesto.js:428-443 inclui facturacao_agt*.js): imobiliario_contratos.js:882, imobiliario_reservas_fin.js:155 e 471, imobiliario_transaccoes.js:534.
- Contratos de dados: `numerar(docType, data, opcoes{origem:'GERAL'|'T<id>', origemNome, terminalCodigo})` facturacao_agt.js:197-199 -> reserva o número seguinte, devolve null se a empresa não está no regime (numeração antiga); `numerarLocal` 240-245 (numeração antes do regime, mesma série); `libertar(reserva)` 281 (devolve o número se a gravação falhar); `selar(saleId, opcoes)` 502 (gera JSON, valida e sela; alinha totais do cabeçalho com os da AGT; revalida se `venda.fe_selado_em`).
- Tipos aceites: FT Factura, FR Factura-Recibo, NC Nota de Crédito (facturacao_agt.js:40, `tipoAGT` 41-47). Os seletos (selados) não se corrigem: NC.
- **Código de operação `AS` «Arrendamento e subarrendamento»** existe em OPERACOES (facturacao_agt.js:51); validação E03 falha para operationType fora desta lista (facturacao_agt.js:419). Não há código `AS` automático fora do módulo: confirmar no porte se o módulo o passa.
- **Isenção M14** «Art.º 12.º CIVA — locação de imóveis para habitação» (facturacao_agt.js:57) e M15 «operações sujeitas a SISA» (a SISA já não existe; comentário db_v2.js:1294): motivos de isenção de IVA aplicáveis às rendas habitacionais e à venda de imóveis.
- Narrativa: «As rendas aprovadas são facturadas por este mesmo caminho» fluxo_narrativa.js:89 (processo `vendas`, etapa Factura); «A renda factura-se pelo mesmo caminho das vendas, com série e numeração AGT» fluxo_narrativa.js:~307 (processo `imobiliario`, cruza `vendas`).
- Em ui_sales.js não há nenhuma referência ao imobiliário (os 5 acertos do grep em ui_sales.js são «Imobilizado»/multimoeda). O elo é `imo_rents.sale_id` (db_v2.js:1265).

### 4.3 Tesouraria
- `imo_rents.treasury_id` (db_v2.js:1281): lado INQUILINO (arrendamento tomado/subarrendamento) a renda vira documento de tesouraria (pagamento); tabelas tocadas: `treasury_documents`, `treasury_items` (grep na pasta). Contas de custo/retenção/disponibilidades no contrato: campos da vista em linhas il-cconta, il-cret, il-cfin (formularios_linhas_config.js:705-711).
- Narrativa: «os contratos tomados pagam-se na Tesouraria» fluxo_narrativa.js:~303 (etapa Recebimento, intervenientes Tesoureiro); `bancos`: «O recebimento entra pela conta bancária» (cruza, ~308).
- Não há referência ao imobiliário em nenhum ficheiro ui_tesouraria*/teso_* (grep).

### 4.4 Activos / Imobilizado / Edifícios
- Ligação: `imo_properties.building_id` -> `fixed_assets` (asset_type EDIFICIO); `imo_units.room_id` -> `asset_rooms`; (db_v2.js:1228; fixed_assets/asset_floors/asset_rooms declarados db_v2.js:947-955: fixed_assets inclui `asset_type, building_id, floor_id, room_id`; asset_floors `[building_id, ordem]`; asset_rooms `[building_id, floor_id, ordem]`).
- Importação de portas da planta: botão «trazer as portas da planta» imobiliario_ui.js:481 (`imoImportarPortas` na tarefa imo_fraccao_editar, permissoes.js:43) lê asset_floors/asset_rooms.
- O lado dos activos NÃO sabe do imobiliário: js/activos_edificios.js (asset_floors 87, 105; asset_rooms 88, 106; «máximo 200 portas» 426) e ui_assets.js não têm nenhuma referência a `imo_*`. A ligação é só de sentido único (imo -> activos). Eliminar um edifício ou porta no módulo de activos não valida `imo_properties.building_id`/`imo_units.room_id` (não verificado em activos_edificios.js; ver «não confirmado»).
- Permissões: catálogo `activos` (permissoes.js:484) separado; nota do ecrã imo_imoveis permissoes.js:44.
- Negócios (compra/venda): «Concluir leva o custo ao imobilizado (compra) ou apura mais-valia e abate o activo (venda)» permissoes.js:92; tabelas `fixed_assets`, `asset_depreciations`, `asset_disposals`(a confirmar) tocadas por imobiliario_transaccoes.js (grep: fixed_assets 5 refs, asset_depreciations 1).
- Narrativa `imob` (aquisição de imobilizado) e `manutencao`: x('imobiliario','Abertura da ordem','As fracções arrendadas usam este processo para o apetrechamento e as reparações.') fluxo_narrativa.js:347; x('imob','Plano de manutenção', …) 344.

### 4.5 Manutenção de activos / Armazém (requisições)
- Ao «pedir material» (tarefa imo_obra_pedir, imoPedirMaterial) o módulo cria: (1) `maint_requisitions` numerada `RQ AAAA/NNNN` com `order_id:null, asset_id:null, imo_work_id, warehouse_id, requerente:'<quem> (Imobiliária <numero> — ref)', estado:'PENDENTE'`; (2) `maint_requisition_lines` com `imo_work_item_id, order_item_id:null, quantidade, quantidade_entregue:0`; (3) `purchase_requests` PENDENTE com `imo_work_id, imo_unit_id` e `purchase_items` (parent_type 'REQUEST', `imo_work_id`, `imo_work_item_id`). imobiliario_obras.js:326-370.
- O lado Armazém/Manutenção: a fila de requisições é a mesma (`activos_manutencao.js:520` `requisicoes(c)` devolve TODAS as `maint_requisitions` da empresa; o ecrã em activos_manutencao_ui.js:749). A entrega `satisfazerRequisicao` (activos_manutencao.js:528-615+): valida armazém, stock disponível, actualiza `products.stock_qty`, `warehouse_stock`, `inventory_movements` (type 'SAIDA', reference «Requisição de manutenção <numero> (ordem …)») e `maint_requisition_lines.quantidade_entregue/estado/custo`; depois lê `asset_maintenance_orders.get(req.order_id)` (com `order_id` null nas ordens imobiliárias). A requisição só tem actualização do estado do item do imobiliário se `l.order_item_id` existir (535-575); para `imo_work_item_id` não há código de retorno ao imobiliário em activos_manutencao.js (grep `imo_work` só dá db_v2.js:1242): a entrega no armazém NÃO actualiza `imo_work_items`; o módulo tem a sua própria `imoReceber` (permissoes.js:67) para registar entregas.
- `purchase_requests`/`purchase_items`: o fluxo de compras (ui_compras_v2.js, fluxo_compras.js) não referencia `imo_work_id`; a recepção da compra não actualiza `imo_work_items` (a confirmar). O custo real volta ao imobiliário por leitura de `purchase_items` com `imo_work_id` (imobiliario_rentabilidade.js:49, 81).
- Narrativa: processo `imobiliario` cruza `manutencao` (planos/ordens) e `compras` (apetrechamento, obras abrem pedidos de compra) fluxo_narrativa.js:~309-310; processo `stock` e `compras` (narrativa do armazém 325-331): não citam o imobiliário (só `manutencao`: «As necessidades das ordens de manutenção pedem material ao armazém» 329).

### 4.6 Vista em linhas (FormularioLinhas), v2/js/shared/formularios_linhas_config.js:622-768
- Helper `imo = (chave, cfg) => FL.registar(chave, {cabecalho:'h3', alargar:true, reabrirSempre:true, ...cfg})` (627). Motor: v2/js/shared/formulario_linhas.js (`registar` linha 569; contagem antes/depois via `contarTabela(cfg.tabela)` 508-511). Config `<chave>`: `{titulo, tabela, contentor, campos[{id,rotulo,obrigatorio,largura}], abrir(), gravar(), nota, campoId?}`; `abrir()` abre o modal do módulo, `gravar()` é a função que grava; o modal é criado ao abrir e removido ao gravar (por isso `reabrirSempre`). Todos os modais do módulo usam `<h3>`.
- 6 registos (chave -> tabela -> contentor -> abrir/gravar):
  - `imo_imoveis` (627) -> imo_properties -> `#imo-prop-modal` -> imoEditarImovel(null) / imoGravarImovel(null). 16 campos (ip-codigo, ip-nome*, ip-tipo, ip-papel, ip-dono, ip-npisos, ip-pisoini, ip-nhab, ip-morada, ip-municipio, ip-provincia, ip-matriz, ip-abruta, ip-autil, ip-vpt, ip-estado). Nota: código e artigo matricial não repetem; útil<=bruta; papel arrendado/mediação exige proprietário.
  - `imo_fraccoes` (652) -> imo_units -> `#imo-unit-modal` -> imoEditarUnidade(null) / imoGravarUnidade(null). 15 campos (iu-codigo, iu-desig*, iu-bloco, iu-piso, iu-tipologia, iu-finalidade, iu-mobilada, iu-abruta, iu-autil, iu-quartos, iu-cb, iu-estado, iu-renda, iu-venda, iu-matriz). Nota: alternativa «Gerar fracções por piso».
  - `imo_contratos` (676) -> imo_leases -> `#imo-lease-modal`, `campoId:'il-id'` -> imoArrEditar(null) / imoArrGravar(). 32 campos (il-tipo, il-unidade*, il-inq*, il-doc, il-mor, il-cont, il-inicio*, il-fim, il-renda*, il-period, il-dia, il-cauc, il-caucr, il-fin, il-selo, il-isen, il-ret, il-reg, il-aviso, il-renov, il-renovm, il-ant, il-act, il-actv, il-actm, il-doct, il-mae, il-cconta, il-cret, il-cfin, il-sub, il-notas). Regras na nota: contratos nascem em rascunho, activação (marca fracção arrendada) um a um; dois contratos do mesmo lado não podem ocupar a mesma fracção em datas cruzadas; tomada e subarrendamento da mesma fracção cruzam-se de propósito; as 4 últimas colunas (il-mae…il-sub) só se aplicam ao arrendamento tomado.
  - `imo_fichas` (720) -> imo_fitout -> `#imo-ficha-modal` -> imoEditarFicha(null) / imoGravarFicha(null). 5 campos (if-nome*, if-tipologia, if-finalidade, if-activo, if-notas); artigos da ficha são lista dentro do formulário.
  - `imo_obras` (734) -> imo_works -> `#imo-obra-modal` -> imoEditarObra(null) / imoGravarObra(null). 12 campos (io-tipo, io-prio, io-imovel*, io-unidade, io-desc*, io-paga, io-debitavel, io-custo, io-dped, io-dprev, io-resp, io-pedidopor). Regra: se paga o senhorio, «debitável ao inquilino» é forçado a não.
  - `imo_bens` (755) -> imo_unit_assets -> `#imo-bem-modal` -> imoNovoBem(window.imoUnidadeAberta()) / imoGravarBem(window.imoUnidadeAberta(), null). 6 campos (ib-prod, ib-desc*, ib-qtd, ib-estado, ib-data, ib-notas). Contexto (fracção aberta) vem do ecrã, não da linha. Pressupõe a função `window.imoUnidadeAberta`.
- Dependência: o ficheiro de config carrega ANTES dos do módulo (index:711 vs 734+) e as lambdas só resolvem no momento do clique.
- Nota de integração: o comentário dos campos mostra que `il-ret` rótulo é declarado à mão «para não herdar o rótulo do <label>, que traz o texto de ajuda atrás» (formularios_linhas_config.js:696): a vista em linhas lê o rótulo do `<label>` do modal se não for declarado.

### 4.7 Fluxo de Processos / Manual de Procedimentos (narrativas)
- v2/js/fluxo_narrativa.js: processo `imobiliario` definido em 295-316 (comentário de cabeçalho 273-277: «Processos acrescentados em 2026-11-06 … cinco buracos: entregáveis, arrendamento, armazém, manutenção de activos e encerramento do exercício»).
  - titulo «Arrendamento de imóvel»; objectivo «Do contrato à renda recebida: arrendar a fracção, gerar as rendas de cada período, facturá-las e cobrá-las, com os impostos do arrendamento.»
  - intervenientes: Gestor imobiliário, Facturação, Contabilista, Tesoureiro.
  - 6 etapas `et(nome, quem, descrição, controlo, resultado)` 299-304: (1) Cadastro do imóvel [Gestor imobiliário; controlo: contrato prende-se sempre a fracção; imóvel sem divisões = fracção «Única»; resultado: fracções no mapa de ocupação]; (2) Contrato [âmbito fracção/imóvel inteiro/parcial, renda, periodicidade, caução, actualização, regime fiscal; vagas afectas; controlo: fracção não está em dois contratos activos; vaga afecta a fracção arrendada não se arrenda à parte]; (3) Rendas do período [gera previstas e aprova com encargos e IP; controlo: aprovada antes de facturada; factura selada não se corrige]; (4) Facturação [Facturação; estacionamento em linha própria; retenção do IP pelo inquilino]; (5) Recebimento [Tesoureiro; recebe ou compensa conta do inquilino; contratos tomados pagam-se na Tesouraria; juros de mora só sobre renda que já é documento]; (6) Manutenção e obrigações [Gestor imobiliário / Contabilista; planos de manutenção, debitar ao inquilino, retenções, Selo, Modelo 1; controlo: obra debitável identificada antes de facturar; entregas dentro do prazo legal].
  - `cruza` 306-311: vendas/Facturação, bancos/Recebimento, manutencao/Manutenção e obrigações, compras/Manutenção e obrigações.
  - Referências cruzadas DE outros processos PARA o imobiliário: `vendas` (fluxo_narrativa.js:89, etapa Factura), `manutencao` (347, etapa Abertura da ordem), `fecho` (368, etapa Relatório e Contas).
- v2/js/modules/procedimentos/procedimentos_manual.js:271 `POR_MODULO.imobiliario = { nome:'Gestão Imobiliária', processos:['imobiliario'] }` (cobertura de narrativas por módulo; função `cobertura()` 253-290; lista `paineis` 258-259 dos separadores do Fluxo de Processos NÃO inclui `imobiliario`: o módulo tem narrativa mas NÃO tem painel em v2/js/fluxo_processos.js). Ver comentário 250-252: é assim que se vê um módulo com narrativa e sem painel.
- v2/js/fluxo_processos.js: nenhum separador imobiliário (os acertos 570, 588, 619 são `imob` = imobilizado). v2/js/fluxo_imobilizado.js: só imobilizado (compra a amortização), sem ligação.
- ferramentas/gerar_manifesto.js:16 lista `fluxos` (inclui fluxo_imobilizado.js): irrelevante para o imobiliário; o manifesto não inclui os scripts do imobiliário.

### 4.8 Permissões do Fluxo, dashboards, relatórios, cubo, orçamento
- v2/js/ui_painel_modulos.js, ui_dashboard.js, ui_cubo.js, modules/gestao/relatorios_gestao.js, modules/orcamento/orcamento_dados.js, ui_reports.js, ui_closing.js, ui_lancamentos.js, ui_compras_v2.js, mapeamento_massa.js, manutencao.js, ajuda.js, data/repositorios.js, rotas.js: NENHUMA referência ao imobiliário (todos os acertos são imobilizado/multimoeda/«fraccao» de rateios BU). Ver 6.
- Em particular: nenhum KPI do imobiliário em Relatórios de Gestão por módulo (relatorios_gestao.js:437 é «Investimento, amortizações, abates e manutenção do imobilizado»); sem rubricas/centros de custo imobiliários no Orçamento; sem painel no Dashboard; sem ajuda contextual (ajuda.js, 0 chaves `imo_*`; o resto dos módulos novos também não tem: 0 acertos de est_plano/crm_pipeline/orc_orcamentos).
- Contabilização: o módulo NÃO tem referência em ui_lancamentos.js/ui_closing.js/mapeamento_massa.js (sem mapeamento contábil próprio fora do módulo; as contas escolhem-se no contrato: il-cconta/il-cret/il-cfin; `window.Seletores.varrer/validarConta` valida contas totalizadoras v2/js/shared/seletores.js:274, 322-326).
- `window.Notificacoes.mostrar` (22+ usos) e `window.Dialogo.{confirmar 20, pedir 9, escolher 3, alerta 1}` são dependências de UI partilhadas.
- `window.tarefaIniciar(titulo,{total})` (v2/js/tarefas.js:237) usado em «Pagamento de rendas» (imobiliario_contratos_ui.js:1195), «Facturação de rendas» (1216), «A trazer as portas da planta» (imobiliario_ui.js:481).

### 4.9 Navegação entre ecrãs do próprio módulo (para o router do porte)
`window.location.hash = '#/imobiliario/<sub>'`: contratos (imobiliario_contratos_ui.js:1138; ficha_ui.js:227; subarrendamento_ui.js:158 com `setTimeout(imoArrAbrir(id),250)`), obras (ficha_ui.js:359,364,417,425; obras_ui.js:213; planos_ui.js:131), rentabilidade (ficha_ui.js:472), negocios (ficha_ui.js:501). `renderImoOcupacao` re-renderiza se `/ocupacao/.test(location.hash)` (ficha_ui.js:524).

### 4.10 Empresa/holding
- `window.activeCompanyId` + `imo_company_id` em todas as tabelas (isolamento por empresa manual, sem hook). Painéis têm flag `semHolding` (ui_painel_modulos.js:44-50); não há equivalente para o imobiliário (nenhum painel). Consolidação: nenhuma leitura de `imo_*` (grep).

## 5. CSS e docs

- css/styles.css e css/tela_noturna.css: ZERO regras imobiliárias (0 acertos de `imo-`). O CSS é injectado por JS via `document.createElement('style')` em: imobiliario_areas_ui.js:61, contratos_ui.js:48, ficha_ui.js:44, parque_ui.js:51, transaccoes_ui.js:64; classes `.imo-*` também em imobiliario_ui.js. Folhas de impressão inline com `@page`: areas_ui.js:784 (A4 portrait, margem 16/14mm), contratos_ui.js:634 (A4 landscape, 12mm), ficha_ui.js:542 (A4 portrait, 18/15mm). Sem tema nocturno específico: confirmar no porte se as classes `.imo-*` usam variáveis do tema.
- docs/DATA_MODEL_CURRENT.md, docs/inventario_dados.json, docs/ARCHITECTURE.md, MODULES.md, PERMISSIONS.md, ROUTING.md, REPOSITORIES.md, workflows/*.md, README.md: ZERO menções ao imobiliário (os 2 acertos em DATA_MODEL_CURRENT.md:634,1194 e 2 em inventario_dados.json:2603,5131 são imobilizado/fluxo_imobilizado). Os docs não foram actualizados com as 17 tabelas imo_* nem com o catálogo (ver 6).
- Regras/*.pdf (6 PDFs, apuramento de resultados, R&C 2024) e Estruturas as Notas/extracted_docx.txt: não tratam de arrendamento; os acertos são «imobilizações corpóreas/imobilizado» do R&C da House of Drink. Os PDFs não foram lidos (binários): só pelos nomes; nenhum fala de imobiliário.
- Comentários de cabeçalho dos db_v2.js 1224-1382 são a melhor documentação das regras de negócio (taxas anuais, IP retido pelo inquilino, Selo encargo do senhorio, SISA extinta, caução nunca é proveito, vaga=fracção).

## 6. Anomalias e lacunas verificadas

1. **Backup e eliminação de empresa não cobrem `imo_*`**: `company_backup.js:18-33` usa lista fixa `directTables` filtrada por `{company_id}` (nenhuma tabela imo_*, est_*, ad_*, orc_* …); eliminar empresa app_v2.js:2923-2929 só apaga tabelas com índice `company_id`: as 17 tabelas com `imo_company_id` ficam órfãs. Restaurar backup deixa de fora o imobiliário. Para o porte: decidir se `company_id` volta a ser o nome.
2. **Sem hooks/auditoria automática**: tabelas imo_* fora do `tableModuleMap` (db_v2.js:315-336) e sem `company_id` (sem `checkYearLock`, sem `is_master_data`); `logMovement` manual grava `user:'admin'` fixo (app_v2.js:2189).
3. Sem perfil-modelo imobiliário; Direcção e Auditor só têm `_view`; sem regras de segregação; `fluxo_processos` e `dashboard` ignoram o módulo.
4. Narrativa `imobiliario` sem painel em Fluxo de Processos (procedimentos_manual.js:258 não a lista em `paineis`).
5. Sem pacote lazy: 23 ficheiros (~12 850 linhas) carregam sempre no arranque; `modulos_registo.js:19` com `pacotes: []`.
6. O registo no catálogo de permissões põe os ecrãs no módulo «Geral» (permissoes.js:34), não num módulo «Imobiliário».
7. Requisições do imobiliário caem na fila de requisições da Manutenção de activos com `order_id:null`; `satisfazerRequisicao` só actualiza `asset_maintenance_order_items` via `order_item_id` (nulo) e lê a ordem por `req.order_id` (activos_manutencao.js:~563 `asset_maintenance_orders.get(req.order_id)`): comportamento com `null` no Dexie não confirmado.

## 7. Tabela: módulo externo -> ligação

| Módulo externo | Ligação (sentido) | Ficheiro:linha | Dados |
|---|---|---|---|
| Relatório e Contas | imo -> R&C (leitura) | relatorio_contas.js:80-81, 376-387, 540-575 | resumo(), resumoAnual(ano), mapaDetencao(ano) |
| Vendas / Facturação AGT | imo -> vendas (escrita) | facturacao_agt.js:197-199, 245, 281, 502, 567-570; imobiliario_contratos.js:882; reservas_fin.js:155,471; transaccoes.js:534 | sales + sale_items; `imo_rents.sale_id`; numerar/numerarLocal/libertar/selar; operação AS; isenção M14/M15 |
| Tesouraria | imo -> teso (escrita) | db_v2.js:1281 (treasury_id); formularios_linhas_config.js:705-711 | treasury_documents/items; pagamento de rendas tomadas |
| Activos / Edifícios | imo -> activos (leitura + escrita nos negócios) | db_v2.js:947-955, 1228; imobiliario_ui.js:481 | building_id -> fixed_assets(EDIFICIO); room_id -> asset_rooms; fixed_assets e asset_depreciations nas compras/vendas |
| Manutenção de activos / Armazém | imo -> manut. (escrita), manut. entrega material | activos_manutencao.js:520-615; imobiliario_obras.js:326-370 | maint_requisitions(+lines) com imo_work_id; movimentos SAIDA |
| Compras | imo -> compras (escrita) | imobiliario_obras.js:352-367; imobiliario_rentabilidade.js:49,81 | purchase_requests/purchase_items com imo_work_id/imo_work_item_id; custo real volta por leitura |
| Armazém / stock | imo -> products/warehouses (leitura) | formularios_linhas_config.js:755-767 (ib-prod); grep na pasta | products, warehouse_stock |
| Terceiros | imo -> third_parties | imo_leases.terceiro_id (db_v2.js:1264), imo_deals.terceiro_id (1296), imo_properties.proprietario_id (1234) | inquilino/adquirente/proprietário |
| Permissões | catálogo `geral`; hasView/canEdit | permissoes.js:41-101, 647, 769-798, 852 | 13 `_view`, 35 tarefas, 14 sensíveis |
| Menu / Rotas | registo e router | modulos_registo.js:19; rotas.js:25-37; app_v2.js:1083-1095, 1370-1386; index_arrumado.html:365-386 | 13 alvos, `#/imobiliario/*` |
| Vista em linhas | imo -> FL (6 registos) | formularios_linhas_config.js:622-768 | imo_imoveis, imo_fraccoes, imo_contratos, imo_fichas, imo_obras, imo_bens |
| Fluxo de Processos / Manual de procedimentos | narrativa | fluxo_narrativa.js:89, 295-316, 347, 368; procedimentos_manual.js:271 | processo `imobiliario` (6 etapas); sem painel |
| Backup/Eliminar empresa | ausente | company_backup.js:18-33; app_v2.js:2923-2929 | tabelas imo_* ignoradas |
| Auditoria | manual | app_v2.js:2189; imobiliario_dados.js:627; obras.js:472 | audit_logs módulo 'Gestão Imobiliária' |
| Dashboard / Painel de módulos / Cubo / Orçamento / Relatórios de gestão / Ajuda / Fecho / Contabilidade (lançamentos, mapeamento) | nenhuma | ver 4.8 | - |
| CRM, RH, Estrutura, Projectos, POS, Lavandaria, Acréscimos/Diferimentos | nenhuma ligação encontrada | grep | - |

## 8. O que não consegui confirmar

- Comportamento exacto de `asset_maintenance_orders.get(null)` em `satisfazerRequisicao` quando a requisição vem do imobiliário (order_id null) (activos_manutencao.js:~563) e se a entrega de material do armazém actualiza o estado das necessidades `imo_work_items` (parece que não; só leitura de código fora da pasta, não lida a lógica interna do módulo).
- Se a recepção de compras (fluxo_compras.js/ui_compras_v2.js) actualiza `imo_work_items` via `purchase_items.imo_work_item_id`: sem referência fora da pasta.
- Se o módulo passa `operationType:'AS'` e a isenção M14 ao selar rendas: está na pasta do módulo (não fui lá); só confirmei que a AGT aceita AS/M14.
- Que verificações do módulo de activos impedem apagar edifício/porta ligados a `imo_properties/imo_units` (js/activos_edificios.js não referencia imo; não li o ecrã de eliminação por completo).
- Formato exacto devolvido por `Imobiliario.resumo()`, `ImobiliarioFiscal.resumoAnual()`, `mapaDetencao()` na origem (apenas os campos que o R&C consome).
- Que perfis reais (BD de produção) têm as chaves `imo_*`: só vi o código; os perfis gravados na BD (`users.permissions`) não existem na pasta do legado.
- Conteúdo dos 6 PDFs de Regras (não lidos; nomes não sugerem imobiliário) e dos .docx/.pdf/.pptx em docs/.
- Se `.imo-*` tem tema nocturno (tela_noturna.css não tem regras; CSS injectado por JS não revisto).
- Falsos positivos descartados (imobilizado, não imobiliário): ajuda.js:28,46,274,279; app_v2.js:107-114,3329,6511-6589 (`fraccao` = fracção de rateio por unidade de negócio, NÃO fracção imobiliária); fluxo_imobilizado.js; fluxo_processos.js:570,588,619; manutencao.js:132; mapeamento_massa.js:57; ui_closing.js:113,1501; ui_compras_v2.js:3464,3769; ui_cubo.js:254; ui_dashboard.js:444; ui_lancamentos.js:398-404,1827-1870,2213,3179-3194; ui_sales.js:546-562,2397-2423; ui_reports.js (balanço, «imobilizacoesCorporeas»); relatorios_gestao.js:18,437; orcamento_dados.js:141; seletores.js:82,158; modulos_manifesto.js:32,102; DATA_MODEL_CURRENT.md; inventario_dados.json.
