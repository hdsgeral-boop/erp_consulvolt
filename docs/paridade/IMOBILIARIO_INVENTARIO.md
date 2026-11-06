# Gestão Imobiliária — inventário do legado (v_boss/New/v2) e interligações

Data: 2026-11-06 · ramo `paridade-bd-front` · documento **só de análise** (nenhum código alterado, nem do projecto nem do legado).
Complemento: `IMOBILIARIO_PLANO.md` (arquitectura alvo, fases, perguntas). Método: o do POS (`POS_LEGADO_INVENTARIO.md` → matriz → plano → ondas).

## 0. Como este inventário está organizado e o que foi realmente lido

O legado tem **23 ficheiros** em `js/modules/imobiliario/` (12 850 linhas, 887 674 bytes). O detalhe campo a campo, enumeração a enumeração, com `ficheiro:linha`, está em cinco anexos (notas de leitura integral por blocos, texto original dos leitores) e numa lista renumerada de funcionalidades. Este documento é a **síntese consolidada**: sub-módulos, ecrãs, modelo de dados, regras/fórmulas essenciais, permissões, interligações e fronteiras. Para qualquer dúvida de detalhe, o anexo manda.

| Anexo | Ficheiros legado lidos (inteiros) | Conteúdo |
|---|---|---|
| `imobiliario/ANEXO_A_CADASTRO.md` | `imobiliario_dados.js` (D), `_parque.js` (P), `_parque_ui.js` (PU), `_areas.js` (A), `_areas_ui.js` (AU), `_ficha_ui.js` (F) | imóveis, fracções, vagas, áreas comuns, reservas, ficha da fracção; 22 enumerações; 30 fórmulas/regras; 112 funcionalidades |
| `imobiliario/ANEXO_B_CONTRATOS.md` | `_contratos.js` (CT), `_contratos_ui.js` (CU), `_templates.js` (TP), `_subarrendamento.js` (SB), `_subarrendamento_ui.js` (SU) | contratos, rendas, modelos, subarrendamento; 13 enumerações; 16 fórmulas; 61 variáveis de modelo e 8 modelos por omissão; 94 funcionalidades |
| `imobiliario/ANEXO_C_NEGOCIOS_FINANCAS.md` | `_transaccoes.js` (TR), `_transaccoes_ui.js` (TRU), `_reservas_fin.js` (RF), `_planos.js` (PL), `_planos_ui.js` (PLU), `_rentabilidade.js` (RE), `_rentabilidade_ui.js` (REU) | compra e venda, reservas pagas e caução, planos, rentabilidade; 143 funcionalidades |
| `imobiliario/ANEXO_D_OBRAS_FISCAL.md` | `_obras.js` (OB), `_obras_ui.js` (OBU), `_fiscal.js` (FI), `_fiscal_ui.js` (FIU), `_ui.js` (UI) | apetrechamento/obras, fiscalidade, ecrã Imóveis e Mapa de Ocupação; 58 funcionalidades |
| `imobiliario/ANEXO_E_FORA_DO_MODULO.md` | `modulos_registo.js`, `permissoes.js`, `db_v2.js`, `app_v2.js`, `index_arrumado.html`, `relatorio_contas.js`, `formularios_linhas_config.js`, `fluxo_narrativa.js`, `procedimentos_manual.js`, `activos_*.js` e os restantes pontos de contacto | registo, menu, permissões, 17 tabelas Dexie, ligações a outros módulos |
| `imobiliario/ANEXO_F_FUNCIONALIDADES.md` | gerado dos anexos A–D | **407 funcionalidades atómicas `IMO-F-001…IMO-F-407`**, com a origem `IMO-A/B/C/D-nnn` |

### 0.1 Lacunas de leitura declaradas (não finjo completude)

1. A leitura dos 23 ficheiros foi feita por **cinco leitores** (um por bloco) e eu **não reli** cada linha: validei por amostragem as secções de fórmulas, enumerações, permissões e ligações contra o texto dos anexos. Os `ficheiro:linha` são os dos leitores.
2. **Não lidos**: o conteúdo dos PDFs de `Regras/`, os ficheiros de `docs/` do legado (só `DATA_MODEL_CURRENT.md`/`inventario_dados.json` via grep), `css/` (o CSS do módulo é injectado por JS; não há CSS próprio em ficheiro), e o corpo de `facturacao_agt.js`, `impressao.js`, `window.Dialogo`, `logMovement`, `FacturaAGT.numerar` (só as chamadas). Isto afecta: comportamento exacto da numeração AGT, do selo, e da auditoria.
3. **Não confirmado** (declarado pelos leitores): o que `satisfazerRequisicao` faz com `order_id` nulo; se a recepção de compras/armazém actualiza `imo_work_items`; quem chama a caducidade e o cancelamento das reservas pagas (RF não tem UI) e a chave de permissão do cancelamento; se o módulo passa `AS`/`M14` ao selar rendas; que verificações impedem apagar um edifício ligado a `imo_properties`; perfis reais da produção.
4. **As taxas fiscais** (IP 25% sobre 60%, selo 0,1%/0,4%, IVA 0%/7%, detenção por escalões 0,1%/5 000/0,5%, terreno 0,6%, transmissão 2%+0,3%+2 000 Kz) foram transcritas do legado, que declara «confirmados em 2026-11-06 (Lei 20/20 e Lei 14/23)» (`imobiliario_contratos.js`, `TAXAS_BASE`). **Não verifiquei a lei.** As skills `ao-*` do projecto são rascunhos sem verificação; a validação final é de contabilista/jurista (pergunta P9).
5. O legado **não tem dados exportáveis na produção**: as 17 tabelas `imo_*` usam `imo_company_id` e ficam **fora** de `tableModuleMap`, do backup (`company_backup.js:18-33`) e da eliminação de empresa. Não existe `imo_*` em `backend/database/legado/esquema.json` nem em `producao_copia/`. Ver P1 do plano.

## 1. Visão geral e posicionamento

Módulo `imobiliario` («Gestão Imobiliária»), ícone `fa-city`, entre «Estrutura Orgânica» e «Planeamento Estratégico» (`index_arrumado.html:365-386`). Registo em `modulos_registo.js:19` com `pacotes: []` (não é pacote lazy; 23 `<script>` fixos, `index_arrumado.html:734-756`). Não aparece em `modulos_manifesto.js`, no painel de módulos (`ui_painel_modulos.js`), no dashboard, no cubo, no orçamento nem nos relatórios de gestão: **o legado não tem KPIs imobiliários no painel**.

Duas decisões de desenho do legado (`imobiliario_dados.js:1-22`) que se mantêm:

1. **O papel manda.** A empresa pode ser PROPRIETÁRIA (`PROPRIO`: arrenda ou vende), INQUILINA (`ARRENDADO`: arrenda a terceiros para subarrendar) ou MEDIADORA (`MEDIACAO`: comissão sem possuir). Só o imóvel próprio se liga ao activo imobilizado.
2. **O contrato prende-se sempre a uma FRACÇÃO.** Imóvel sem divisões recebe a fracção «Única». Vagas de estacionamento e arrecadações são fracções com `natureza` própria.

## 2. Sub-módulos e ecrãs (13 entradas de menu, 4 modais-ficha)

Ordem real do menu (`index_arrumado.html:372-384`), rota `#/imobiliario/<sub-rota>`:

| # | Ecrã (id permissão) | Rótulo exacto | Função principal | Anexo |
|---|---|---|---|---|
| 1 | `imo_imoveis` | Imóveis | lista/detalhe de imóveis e fracções; editores; gerar fracções por piso; importar portas do edifício do Activos; fracção única | D §5.5 |
| 2 | `imo_ocupacao` | Mapa de Ocupação | grelha por imóvel/piso/fracção com estado; mudar estado | D §5.6 |
| 3 | `imo_parque` | Parqueamento | vagas, geração em massa, afectação a fracção, veículos do contrato, mapa impresso | A §2-3 |
| 4 | `imo_areas` | Áreas Comuns | espaços, calendário, reservas, painel «Dinheiro» (factura, recibo, caução, vistoria) | A §3-4, C §2 |
| 5 | `imo_contratos` | Contratos | lista, detalhe (conta corrente, avisos, filhos), formulário 900 px, activar, terminar/denunciar, actualizar renda, documentos | B §5.1-5.4 |
| 6 | `imo_rendas` | Rendas do Período | gerar, recalcular, aprovar, anular, facturar (senhorio), pagar (inquilino), desligar pagamento | B §5.5 |
| 7 | `imo_sub` | Subarrendamento | margem por fracção, cadeia mãe/filhos, obras a debitar ao inquilino | B §5.7 |
| 8 | `imo_negocios` | Compra e Venda | processo por fases, encargos, impostos da transmissão, conclusão, factura da venda, mais-valia | C §1 |
| 9 | `imo_rentabilidade` | Rentabilidade | previsto × executado por fracção, rendimento anual, avisos | C §4 |
| 10 | `imo_fiscal` | Fiscalidade | resumo do ano, detenção, retenções, selo, Modelo 1, entregas, calendário | D §5.4 |
| 11 | `imo_modelos` | Modelos e Taxas | 8 modelos de documentos (editor com variáveis) e taxas por ano | B §5.6, §7 |
| 12 | `imo_obras` | Apetrechamento e Manutenção | ordens de trabalho, necessidades, pedido de material, recepção, planos | D §5.3, C §3 |
| 13 | `imo_fichas` | Fichas de Apetrechamento | ficha-tipo (kit) por tipologia/finalidade | D §5.1 |

Modais/ecrãs-ficha transversais: **Ficha da fracção** (separadores Resumo, Contratos, Rendas, Manutenção, Inventário, Rentabilidade, Negócios; `imobiliario_ficha_ui.js`), **Bens da fracção** (`imoBensFraccao`), **Planos de manutenção e vistoria** (`imoPlanos`), 11 modais de parque/áreas (A §6.3). Total contado nos anexos: **13 ecrãs de menu + 3 ecrãs-ficha/modais principais + ~35 modais (estimativa a partir de A 11 e B 9 + C/D) e diálogos + 9 separadores** (A: 9 separadores, 11 modais, 3 impressões; B: 4 ecrãs, 9 modais; D: 5 ecrãs). Rótulos, colunas, filtros, KPIs e botões exactos de cada um estão nos anexos (secções «Ecrã…» de A, B, C, D).

`imobiliario_ui.js` **não** é um shell com separadores/dashboard/pesquisa: é o ecrã Imóveis + Mapa de Ocupação. A navegação do módulo é o grupo do menu lateral.

## 3. Modelo de dados do legado — 17 tabelas Dexie (`db_v2.js`, versões 1289-1298)

Todas com `++id` e `imo_company_id` (não `company_id`; decisão deliberada para não activar hooks de exercício encerrado/dados mestre, `db_v2.js:786`). Consequência: sem auditoria por hook (o módulo chama `logMovement` à mão em D e grava `audit_logs` directamente em P e A — dois mecanismos).

| Tabela legado | Sub-módulo | Chaves/relações principais | Campos e enums |
|---|---|---|---|
| `imo_properties` | cadastro | `building_id`→`fixed_assets`(EDIFICIO), `proprietario_id`→`third_parties` | A §1.2 (códigos `PR0001`, papel, tipo, pisos, áreas, VPT, `isencao_detencao`, documentos[]) |
| `imo_units` | cadastro/parque | `property_id`, `room_id`→`asset_rooms`, `park_unit_id` | A §1.2 (código `PR0001-NN`, natureza, tipologia, finalidade, áreas, estado, `park_*`) |
| `imo_amenities` | áreas comuns | `property_id` | A §3.1 (regime, preço, horas, prazo de pagamento 48 h, `cancel_dias` 7, `cancel_pct` 50) |
| `imo_bookings` | reservas | `amenity_id`, `unit_id`, `lease_id`, `sale_id`, `receipt_id` | A §3.1, C §2.2 (estados, caução, vistoria) |
| `imo_leases` | contratos | `unit_id`, `property_id`, `terceiro_id`, `parent_lease_id` | B §1.1 (tipo, posição, âmbito, unidades[], encargos[], actualização, caução, IP/selo/IVA, `park_veiculos[]`) |
| `imo_rents` | rendas | `lease_id`, `sale_id`, `treasury_id` | B §1.2 (período, base, IP, selo, IVA, totais, extras[]) |
| `imo_templates` | modelos | — | B §1.3 (8 tipos, 61 variáveis) |
| `imo_settings` | taxas | 1 linha/empresa, `taxas[ano]` | B §1.4, D §1.7 |
| `imo_deals` / `imo_deal_costs` | compra e venda | `property_id`, `unit_id`, `terceiro_id`, `sale_id` | C §1.2 |
| `imo_work_plans` | planos | `property_id`, `unit_id` | C §3.1 |
| `imo_tax_filings` | fiscal | `property_id` | D §1.6 |
| `imo_fitout` / `imo_fitout_items` | fichas de apetrechamento | `product_id` | D §1.1-1.2 |
| `imo_unit_assets` | inventário da fracção | `unit_id`, `product_id`, `asset_id` | D §1.3 |
| `imo_works` / `imo_work_items` | obras | `unit_id`, `plan_id`, `requisition_id` | D §1.4-1.5 |

Campos do imobiliário em tabelas de outros módulos (`maint_requisitions.imo_work_id`, `maint_requisition_lines.imo_work_item_id`, `purchase_requests.imo_work_id/imo_unit_id`, `purchase_items.imo_work_id/imo_work_item_id`, `sales.imo_lease_id/imo_rent_id/imo_deal_id/imo_booking_id/imo_amenity_id/imo_caucao`, `treasury_documents.imo_lease_id/imo_rent_id`, `treasury_items`): Anexo E §3.

**Numeração/séries (do legado, sem lock — corrida possível):** contratos `AR|SB|AT AAAA/NNNN`, negócios `CP|VD AAAA/NNNN`, obras `AP|OB AAAA/NNNN`, imóvel `PR0001`, fracção `PR0001-NN`, vaga `PR0001-<prefixo>NNN`; reservas sem série (só id). O próximo número deriva do máximo existente (D, B, C).

## 4. Regras e fórmulas essenciais (contagem: 30 em A e 16 em B, contadas pelos leitores; 15 em C e 12 em D estimadas por mim ao consolidar = **≈ 73 regras/fórmulas**; as de maior risco abaixo, transcritas dos anexos)

`cent(v)=round(v*100)/100` (arredondamento JS sobre float: no novo, **bcmath/decimal**, ver plano §10). Datas ISO; períodos `AAAA-MM`.

1. **Renda** (`calcular`, CT:655-685): `base = renda + encargos(não incluídos) − desconto`; `ip = base × ip_base% × ip_taxa%` (25% × 60% = 15% efectivo; isenção TOTAL → 0, METADE → /2); `selo = base × (HABITACAO ? 0,1% : 0,4%)` (0 se isento; **não** entra na factura); `iva = base × (ALOJAMENTO ? 7% : 0%)`; `total_facturar = base + iva`; `retido = retencao_ip ? ip : 0`; `total_receber = total_facturar − retido`; `custo_senhorio = (selo se encargo do senhorio) + (ip se não retido)`.
2. **Actualização** (`rendaNoPeriodo`, CT:693-703): `n = floor(meses decorridos desde actualizacao_base|inicio / actualizacao_meses)`; PERC `renda×(1+v/100)^n` (composto); VALOR `renda + v×n`; NAO → renda base.
3. **Períodos**: `ate` = último dia do último mês do período; vencimento = dia (limitado ao fim do mês) do mês de início, ou do mês final se «no fim do período». **Sem pro-rata** (primeiro período sempre inteiro).
4. **Caução**: máx. 6 × renda; recebida ≤ acordada; em poder = recebida − devolvida − retida. **Não há lançamento nem movimento de tesouraria** (lacuna a fechar).
5. **Mora** (CT:1010-1018): `divida = total_receber − recebido`; `atraso` em dias se renda já em documento, `divida>0,009`, vencimento < hoje; `juros = divida × mora%/100 × (atraso/30)` (simples, mês de 30 dias, taxa do **ano corrente**; `mora_mensal` por contrato nunca é gravado).
6. **Máquina de estados do contrato**: RASCUNHO→ACTIVO (e SUSPENSO→ACTIVO); ACTIVO|SUSPENSO→TERMINADO|DENUNCIADO (anula rendas futuras, liberta fracções, cascata aos filhos com `cascata=true`); **SUSPENSO nunca é atribuído**; eliminar só RASCUNHO/TERMINADO/DENUNCIADO sem rendas FACTURADA/PAGA.
7. **Estados da renda**: PREVISTA→APROVADA→FACTURADA (senhorio) | PAGA (inquilino); PREVISTA|APROVADA→ANULADA; PAGA→APROVADA (desligar, motivo obrigatório, recusa se doc de tesouraria INTEGRADO). Gerar não regenera períodos com renda (nem ANULADA).
8. **Pagamento (lado inquilino)**: documento de tesouraria `PAGAMENTO` PENDENTE com D conta de custo = base e C conta de retenção = IP (se retém); `saida = base − ip retido` deve bater com `total_receber` (±0,02); **recusa renda com IVA**.
9. **Margem do subarrendamento**: por fracção `recebe − paga` sobre `total_facturar` (antes de retenção) dos 12 meses; `taxa = (recebe−paga)/paga` (1 casa); **sem mensalizar periodicidades** (distorção conhecida).
10. **Obra debitada ao inquilino**: obra CONCLUIDA, debitável; entra como `extras[]` na renda não-documento; `total_facturar = base + iva` **sem recalcular IP, selo nem IVA** (incoerência a corrigir).
11. **Transmissão** (TR:88-102): `base = max(preço, VPT)`; IP 2% (TOTAL → 0; METADE → /2; só o IP é isento); selo 0,3%; escritura 2 000 Kz; `total = ip + selo + escritura`. Sugestão de isenção só para habitação por escalões do ano.
12. **Custo de aquisição** = `preço + impostos + encargos capitalizáveis`; **mais-valia** (venda) = `preço − valor contabilístico − encargos de venda` (todos, mesmo os não capitalizáveis); valor contabilístico = aquisição − amortização acumulada (só imóvel ligado ao imobilizado); quota da fracção por `area_util` depois `area_bruta`.
13. **Detenção** (FI:70-114): terreno `vpt×0,6%`; `vpt ≤ 5 M`: `0,1%`; `≤ 6 M`: 5 000 fixos; acima: `(vpt−5 M)×0,5%`; agravamento 50% se desocupado ≥ 12 meses; isenção TOTAL/METADE. Continuidade: 5 M→5 000; 6 M→5 000.
14. **Reservas pagas** (RF): prazo de pagamento `max(1,round(horas||48))` limitado ao início do evento; cancelamento: `faltam ≥ cancel_dias → devolve 100%`, senão `cancel_pct`; caução devolvida = `recebida − retido`; só a parte retida por danos (com vistoria) é receita e factura-se à parte.
15. **Rentabilidade** (RE): previsto e executado nunca se somam; `resultado = receita − (renda_paga + selo + IP próprio + manutenção + investimento − recuperado)`; `margem = (r−c)/r`; rendimento anual = `resultado_médio_mensal×12 / (valor_aquisicao|VPT × quota da fracção)`; multi-fracção repartido por `area_util` → `area_bruta` → 1/n; obras por `quem_paga` (SENHORIO 1, PARTILHADO 0,5, INQUILINO 0).
16. **Ocupação/área**: taxa = `ocupadas/principais×100` (denominador: todas as fracções principais; o comentário diz o contrário); parque = `ocupadas/arrendáveis`; condomínio: `principais − n_habitacoes`; limite mensal de reservas por fracção; dívida > 0,009 exige autorização gravada com o nome.

(Fórmulas 17 a 73 — geração de fracções e vagas, `porteCabe`, `calcularValor` das reservas, conflitos de horário, janelas de 12 meses, dados do Modelo 1, mapa do selo, etc. — estão em A §6.2, B §3, C §1.4/§2.4/§3.3/§4.2, D §3.)

### 4.1 Defeitos e incoerências do legado (a decidir no porte; lista completa nos anexos: A §6.7, B §10, C §5, D §9, E §6)

Resumo dos 18 que mais pesam: (a) `SUSPENSO` nunca atribuído; (b) renovação automática só gera aviso; (c) `mora_mensal` por contrato nunca gravado; (d) sem pro-rata; (e) sem lançamento da caução; (f) margem do subarrendamento sem mensalização; (g) `recalcularRenda` ignora `extras` e a actualização manual rebenta a meio se houver rendas PAGA; (h) transacções **sem qualquer contabilização** (sinal, preço, impostos, comissão de mediação inexistente); (i) série de números sem atomicidade; (j) `mapaSelo` inclui rendas previstas, os outros mapas só em documento; (k) prazo do Modelo 1 como 31 de Janeiro; (l) valores por omissão da UI divergem do backend (prioridade/estado dos bens); (m) editar uma vaga recalcula o código e perde bloco/número/documentos; (n) veículos perdem cor/dístico ao gravar; (o) reserva paga com aprovação salta «a aguardar pagamento»; (p) verificação em directo da reserva conta a própria reserva; (q) recepção de material totalmente desligada de Compras/Armazém; (r) modelos criados pelo utilizador gravados como `is_master_data:1`.

## 5. Permissões do legado (`permissoes.js:41-101`, anexo E §2)

13 ecrãs (`imo_*_view`), **35 tarefas, 14 sensíveis**, ~100 funções globais protegidas. Estão dentro do módulo «Geral» (não num módulo próprio). Nenhum dos 17 perfis-modelo tem chaves `imo_*`; Auditor/Consulta e Direcção ficam só com as 13 consultas; não há par de segregação de funções para o imobiliário (as notas do catálogo pedem separar quem regista de quem conclui, e aprovar de facturar). Tarefas sensíveis: `imo_reserva_aprovar`, `imo_reserva_devedor`, `imo_reserva_caucao`, `imo_obra_pedir`, `imo_contrato_activar`, `imo_contrato_terminar`, `imo_renda_aprovar`, `imo_renda_facturar`, `imo_renda_pagar`, `imo_sub_debitar`, `imo_neg_fase`, `imo_neg_concluir`, `imo_fiscal_entregar`, `imo_taxas_editar`. Lista completa por ecrã em Anexo E §2.

## 6. Estados e transições (resumo)

| Entidade | Estados | Transições implementadas |
|---|---|---|
| Imóvel | ACTIVO, INACTIVO, ALIENADO | edição; ALIENADO por venda concluída |
| Fracção | DISPONIVEL, RESERVADA, ARRENDADA, VENDIDA, EM_OBRA, INDISPONIVEL | `TRANSICOES` em `mudarEstadoUnidade` (A §5.9); contrato activo→ARRENDADA; terminar→DISPONIVEL; venda→VENDIDA |
| Contrato | RASCUNHO, ACTIVO, SUSPENSO, TERMINADO, DENUNCIADO | ver regra 6 |
| Renda | PREVISTA, APROVADA, FACTURADA, PAGA, ANULADA | ver regra 7 |
| Negócio | NEGOCIACAO, PROMESSA, ESCRITURA, REGISTADO, CONCLUIDO, CANCELADO | avanço sequencial com bloqueios por fase (notário para escritura, etc.; C §1.9) |
| Reserva | PEDIDA, APROVADA, AGUARDA_PAGAMENTO, CONFIRMADA, REALIZADA, FALTOU, RECUSADA, CANCELADA (+caução: NAO_APLICA, POR_RECEBER, EM_PODER, DEVOLVIDA, RETIDA_PARCIAL, RETIDA_TOTAL) | `TRANSICOES` (A §3.3) |
| Obra | ABERTA, AGUARDA_MATERIAL, EM_CURSO, CONCLUIDA, CANCELADA | EM_CURSO só por recepção; sem matriz formal |
| Necessidade | POR_PEDIR, REQUISITADO, PEDIDO_COMPRA, RECEBIDO, CANCELADO | — |
| Entrega fiscal | POR_ENTREGAR, ENTREGUE, DISPENSADO | gravação manual |
| Plano | datas POR_GERAR / EM_FALTA / (estado da ordem) | geração manual de ordens |

## 7. Relatórios, exportações, impressões, notificações

Só **impressão por janela HTML** (`window.print()` após 350 ms): mapa de parqueamento, reservas de áreas comuns, ficha da fracção, conta corrente do contrato (A4), subarrendamento (A4 horizontal), rentabilidade (REU:266-304), 8 modelos de documentos (A4 vertical, margens 22/20 mm, Georgia 11,5 pt). **Não há PDF no servidor, nem Excel, nem CSV.** Notificações: apenas toasts; os avisos (`avisos()` de contratos, planos, negócios, fiscal, imóveis) são **calculados ao abrir o ecrã**; não há alertas persistentes nem agendados. Relatório e Contas lê `Imobiliario.resumo`, `ImobiliarioFiscal.resumoAnual`, `mapaDetencao` (`relatorio_contas.js:80-81,376-387,540-575`). Vista em linhas: 6 formulários (`formularios_linhas_config.js:622-768`). Fluxo de Processos: só narrativa (`fluxo_narrativa.js:295-316`), sem painel.

## 8. INTERLIGAÇÕES (módulo a módulo): legado → novo

Legenda: **Reutilizar** = serviço existente; **Novo** = a criar; **Duplicação** = sobreposição a evitar. Serviços citados existem em `backend/app/Services` (verificados).

| # | Módulo novo | Ligação no legado (evento · dados · validações · o que se grava) | Proposta no novo | Duplicação / fronteira |
|---|---|---|---|---|
| 1 | **Activos** (`AtivoImobilizado` tipo `EDIFICIO`, `AndarEdificio`, `PortaEdificio`, `ServicoLocaisAtivos`) | `imo_properties.building_id`→`fixed_assets`(EDIFICIO); `imo_units.room_id`→`asset_rooms`; `importarDoEdificio` traz portas para fracções; venda: abate (`asset_disposals`, estado ABATIDO) e leitura de `asset_depreciations` para valor contabilístico; compra conclui com custo ao imobilizado | `imoveis.ativo_edificio_id` e `fracoes.porta_id` (FK); importação idempotente de portas; compra: `ServicoAquisicoesAtivos`; venda: `ServicoAbatesAtivos` + leitura de `AmortizacaoAtivo` | **Duplicação real**: planta (andares/portas), inventário físico e manutenção já existem em Activos. O Imobiliário **não** cria planta nem inventário físico; guarda só o cadastro comercial. Activos já modela bem alheio (`propriedade=ALHEIO`, `regime_alheio=ARRENDADO`, `contrato_referencia/inicio/fim`, `renda_mensal`): o **contrato tomado** do Imobiliário passa a ser a fonte; esses campos de Activos ficam como referência sincronizada (ver plano §3.2) |
| 2 | **Vendas / Faturação / AGT** (`ServicoDocumentosVenda::emitir`, `ServicoSeries`, `ServicoSelagemAgt`, `ServicoRecibosVenda`, `CatalogoAgt` com `M14`) | `facturarRenda`: `FacturaAGT.numerar` + `sales`/`sale_items` com `imo_lease_id/imo_rent_id`, cliente = `terceiro_id`, `related_doc_id`=nº do contrato, vencimento; renda→FACTURADA; recebimento feito em Vendas (o contrato só lê `receipt_items`); venda de imóvel: factura (`facturarVenda`); reserva paga: factura+recibo+factura da retenção | `ServicoDocumentosVenda::emitir` com origem `IMOBILIARIO`, isenção M14 para arrendamento (a confirmar com a AGT/contabilista); recibos por `ServicoRecibosVenda::criar`; ligações por colunas FK nas rendas (`venda_id`) | Não criar série nem selagem própria; **uma** emissão por renda (idempotente por `renda_id`); sob `after_commit`/outbox (ADR-080) para envio AGT |
| 3 | **Tesouraria** (`ServicoDocumentosTesouraria::gravar/integrar/anular`, folha de caixa, reconciliação) | renda do inquilino: `pagarRenda` cria `treasury_documents` PAGAMENTO PENDENTE + 1-2 linhas; desligar pagamento recusa se INTEGRADO; **caução** nunca é movimentada (RF cria documento RECEBIMENTO para caução de reserva paga) | Reutilizar `gravar` (linhas D custo / C retenção); caução de contrato e de reserva: documento próprio com `tipo_origem=IMOBILIARIO_CAUCAO`; reconciliação por `renda_id` | Sem tabela de caixa própria |
| 4 | **Contabilidade** (`ServicoLancamentos::criar`, `ServicoContabilizacaoVendas`, `ServicoRotinasContabeis*`, outbox) | nenhum lançamento directo no legado: faturas contabilizadas por Vendas, pagamentos por Tesouraria; transacções, caução, mais-valia, juros de mora, selo: **sem contabilização** | Rendas e vendas: herdam de Vendas/Tesouraria. **Novo**: caução (passivo a devolver) e mais-valia/menos-valia, via `ServicoLancamentos::criar` em job repetível com chave de idempotência (ADR-080); contas parametrizáveis por empresa, nunca hard-coded | Não lançar IVA/IP/selo duas vezes (já vão em Vendas/Tesouraria) |
| 5 | **Terceiros** (`ServicoTerceiros`, `ServicoConsultaNifAgt`) | `terceiro_id` (inquilino, senhorio, vendedor, comprador, proprietário); morada por NIF nos modelos | FK `terceiro_id`; consulta NIF; dados pessoais sob a política de `DadosPessoaisTest` | — |
| 6 | **Projectos** (`ServicoProjetos`, `ServicoRequisicoesProjetos`, `ServicoFinanceiroProjetos`) | nenhuma no legado (obras imobiliárias não ligam a projectos) | **Opcional**: `ordens_trabalho_imo.projeto_id` para obras grandes (decisão P6) | evitar duplicar autos de medição |
| 7 | **Orçamento** (`Services/Orcamento`) | nenhuma no legado; `custo_estimado` é número livre | opcional: obras e rendas como compromisso/realizado por rubrica (P6) | — |
| 8 | **RH** | nenhuma | nenhuma (utilizadores/responsáveis vêm de `Utilizador`/Estrutura) | — |
| 9 | **Impostos** (IP, Selo, IVA, retenção — `ServicoRetencaoFonte`, `ServicoRotinasContabeisSelo`, mapas fiscais de Activos) | IP/selo/IVA calculados na renda; mapas de detenção, retenções, selo, Modelo 1; entregas manuais | **Novo** `ServicoFiscalImobiliario` sobre `taxas_imobiliario` por ano; reutilizar `ServicoRotinasContabeisSelo` para o selo se já contabiliza; retenção de IP (a entregar à AGT) coerente com `ServicoRetencaoFonte` | Verificar sobreposição de selo/retenção com Contabilidade antes de F7 |
| 10 | **Moedas / câmbios** (`ServicoMoedas`, `ServicoCambios`) | **nenhuma**: tudo em Kz | rendas em Kz; moeda estrangeira só se P7 disser sim (campos `moeda`, `cambio` nos contratos) | — |
| 11 | **Stock / Compras / Armazém** (`ServicoRequisicoesManutencao`, Compras, Logística) | obras: `maint_requisitions` com `imo_work_id` (fila de Manutenção de activos), `purchase_requests/items` com `imo_work_*`; recepção manual desligada | **Novo**: `necessidades_ordem_imo` liga a `requisicoes_manutencao`/pedidos de compra; recepção de compras e saída de armazém actualizam a necessidade (fecha o buraco do legado); custo real da obra = linhas de compra recebidas | Reutilizar `ServicoRequisicoesManutencao` e Compras; **não** duplicar requisições |
| 12 | **Documentos / Anexos** (`DocumentoAnexo`, `/api/sistema/documentos`, validador de uploads ADR-083) | `documentos[]` embebidos só com URL (sem upload); `docs` do contrato sem UI | anexos reais por entidade (polimórfico em `documentos_anexos`), com o validador central; modelos de contrato geram PDF no servidor | — |
| 13 | **Notificações** (`ServicoNotificacoes::criar` com `chave_origem`, comandos agendados em `routes/console.php`) | toasts apenas; `avisos()` calculados à vista | **Novo** comando diário `erp:imobiliario:alertas` (padrão `erp:rh:alertas-prazos`): fim de contrato, renda em atraso, caução por receber, seguro/vistoria, obra parada, prazos fiscais; `chave_origem` evita duplicados | — |
| 14 | **Auditoria** (`Auditavel`, `ServicoAuditoria`) | dois mecanismos manuais (`logMovement`, `audit_logs`) | todos os modelos com `Auditavel` e `moduloAuditoria='Imobiliário'`; acções sensíveis (activar, terminar, facturar, pagar, concluir negócio, autorizar devedor, caução) com registo explícito | — |
| 15 | **Painel de módulos / KPIs** | inexistente no legado | **Novo**: painel «Imobiliário» (ocupação, renda mensal prevista/facturada, em dívida, contratos a expirar, margem de subarrendamento, obras abertas, entregas fiscais) e entrada no `ServicoInicio`/«pendentes» | funcionalidade nova (não paridade) |
| 16 | **Permissões / perfis** (`CatalogoPermissoes`, `catalogo.json`) | 13 ecrãs, 35 tarefas, 14 sensíveis, sem perfis, sem segregação | módulo `imobiliario` próprio no catálogo (editado à mão), perfis-modelo e pares de segregação | — |
| 17 | **Pesquisa** (`ferramentas/pesquisa`, `indicePesquisa.gerado.ts`) | — | entradas dos 13 ecrãs; regenerar o índice | — |
| 18 | **Fluxo de Processos** | narrativa `imobiliario` sem painel | opcional: painel do processo (contrato→renda→factura→recebimento) | baixa prioridade |
| 19 | **Relatório e Contas / Consolidação** | `Imobiliario.resumo`, `resumoAnual`, `mapaDetencao` em `relatorio_contas.js` | expor a mesma síntese em `ServicoRelatorioContas` (nota de gestão) | — |
| 20 | **Impressão / PDF** (`componentes/impressao`, `ServicoPdfA4POS`/dompdf) | janelas HTML | PDF no servidor para contrato, recibo, avisos, conta corrente, mapas | reutilizar o padrão de dompdf do POS (sem rede, sem ficheiros) |
| 21 | **CRM** | nenhuma | opcional: lead→inquilino (fora do âmbito) | — |

Contagens: **21 interligações** (14 com ligação real no legado: Activos, Vendas/AGT, Tesouraria, Terceiros, Obras↔Manutenção de Activos, Compras, Contabilidade (indirecta), Relatório e Contas, Vista em linhas, Permissões, Auditoria, Impressão, Fluxo (narrativa), Documentos (URL); 7 novas no novo: Projectos, Orçamento, Notificações agendadas, Painel/KPIs, Pesquisa, PDF servidor, CRM).

## 9. Fronteiras com módulos existentes (resumo)

- **Activos é dono de**: edifício como bem, andares, portas, inventário físico, manutenção planeada e requisições de manutenção, amortizações, abates, contas contabilísticas do bem. **Imobiliário é dono de**: cadastro comercial (imóvel/fracção/vaga/áreas comuns), contratos e rendas, negócios de compra e venda, rentabilidade, fiscalidade predial, planos e ordens de trabalho do imóvel.
- **Contrato tomado × bem alheio de Activos**: hoje Activos guarda `regime_alheio=ARRENDADO` + `contrato_*` + `renda_mensal` como texto/valores livres. No novo, o contrato tomado do Imobiliário é a fonte de verdade; o bem alheio mantém-se mas passa a referenciar `contrato_id` (sincronização unidirecional, sem duplicar a renda).
- **Manutenção**: ordens de trabalho do imóvel (`imo_works`) ≠ ordens de manutenção de bens móveis. Reaproveitar `RequisicaoManutencao` apenas como canal de material; a ordem é do Imobiliário (tem `quem_paga`, débito ao inquilino).
- **Vendas/Tesouraria/Contabilidade são donos de** numeração, selagem, recibos, movimentos e lançamentos; o Imobiliário só pede (serviço) e guarda a referência.

## 10. Contagens finais do inventário

- Sub-módulos: **12** (cadastro/imóveis, fracções e mapa de ocupação, parqueamento, áreas comuns e reservas, reservas pagas/caução, contratos, rendas, subarrendamento, modelos e taxas, compra e venda, planos e obras/apetrechamento, rentabilidade, fiscal) — agrupados em 13 ecrãs de menu.
- Ecrãs: 13 de menu + ficha da fracção + bens da fracção + planos (16 ecrãs principais); ~35 modais (estimativa a partir de A 11 e B 9 + C/D); 9 separadores de ficha/áreas; 8 impressões.
- Enumerações: 22 (A) + 13 (B) + ~12 (C) + 8 (D) ≈ **55** (rótulos nos anexos).
- Regras/fórmulas: **≈ 73** (30+16 contadas, 27 estimadas); defeitos/incoerências assinalados: **≈ 70** (A 10, B 25, C 17, D ≈ 12, E 8).
- Tabelas legado: **17** (+ 9 tabelas de outros módulos tocadas e 11 campos em tabelas alheias).
- Funcionalidades atómicas: **407** (`IMO-F-001…407`).
- Permissões legado: 13 ecrãs, 35 tarefas, 14 sensíveis.
- Interligações: **21**.
