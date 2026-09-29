# Inventário Funcional do Legado — Catálogo de Paridade

> Levantamento do ERP legado `payroll_system_web` (Vanilla JS + Dexie) feito a 2026-09-29.
> **Objectivo:** garantir que a reescrita (Laravel + React) não perde nenhuma view nem funcionalidade.
> Todas as referências `ficheiro:linha` são relativas a `payroll_system_web/` e foram confirmadas no código.
> Legenda: **[M]** no menu · **[H]** vista escondida (só `_views`/rota/separador) · **[B]** bug ou comportamento suspeito.
> Estado de paridade de cada linha é controlado em `MATRIZ_PARIDADE.md` (a criar na Fase 5).

---

## 0. Fontes de verdade

| Fonte | Conteúdo | Localização |
|---|---|---|
| Menu lateral (97 `data-target`) | Ecrãs visíveis | `index_arrumado.html:82-395` |
| Mapa de vistas `window._views` | Alvo → função render (inclui escondidas e aliases) | `js/app_v2.js:1052-1172` |
| Rotas legíveis `#/modulo/sub` | Alvo → rota; pacote a carregar | `js/core/modulos_registo.js:14-56` |
| Router + guardas (sessão → empresa → permissão → holding) | | `js/rotas.js:51-61, 102-130` |
| Catálogo de permissões (116 ecrãs `E(...)`, ≈195 tarefas `T(...)`) | Melhor lista de ecrãs/tarefas | `js/permissoes.js:32-466` |
| Pacotes lazy-load | vendas, compras, tesouraria, contabilidade, activos, projectos, paineis, fluxos, ia | `js/modulos_manifesto.js`, `js/carregador.js` |
| Schema Dexie (`WSTB_PayrollDB` v1225→1267) + hooks globais | | `js/db_v2.js:5-859` |

**A ordem de carregamento altera o comportamento** — a versão a portar é a que carrega por último:
- `pos_gestao.js`, `pos_prestacao.js:808`, `lavandaria.js:2351`, `hotelaria.js:147` envolvem `renderPOS`/`renderPOSTab`/`postDocumentToAccounting`/`finalizePOSPayment` de `ui_sales.js`.
- `permissoes.js:605-643` substitui `hasView`/`canEdit`/`can` de `app_v2.js:725-888`.
- `relatorio_contas.js:927` substitui `renderRelatorioContas` de `ui_reports.js:4165` (esta tem valores demo fixos — **não portar**).
- `consolidacao.js:205-249` envolve `renderRelatoriosContabeis`.

---

## 1. Autenticação, sessão, empresa e permissões

### 1.1 Hash de palavras-passe — `js/data/senhas.js`

| Item | Valor | Ref. |
|---|---|---|
| Algoritmo | PBKDF2-HMAC-**SHA-256** (Web Crypto) | `senhas.js:19-22` |
| Iterações | **120000** | `senhas.js:13` |
| Tamanho derivado | **32 bytes** | `senhas.js:21` |
| Salt | **16 bytes** aleatórios | `senhas.js:33` |
| Codificação | `password_hash` e `password_salt` em **Base64 standard** | `senhas.js:15, 35` |
| `password_algo` | `"PBKDF2-SHA256-120000"`; iterações lidas do sufixo | `senhas.js:14, 41` |
| Comparação | tempo constante | `senhas.js:24-29, 43` |
| Legado em texto simples | aceite e re-hash no login | `senhas.js:45-49`; `data/servicos.js:26-31` |
| Tamanho mínimo | 8 caracteres | `servicos.js:43`; `app_v2.js:626` |

Equivalente PHP: `base64_encode(hash_pbkdf2('sha256', $pwd, base64_decode($salt), 120000, 32, true))` + `hash_equals`.
**Implementado na Fase 1** em `App\Services\Autenticacao\VerificadorPasswordLegado` com re-hash para Argon2id no primeiro login.

### 1.2 Login e sessão

| Passo | Ref. |
|---|---|
| Primeiro arranque (sem utilizadores): Super Admin + 1.ª empresa (`inss_patronal:8`, `inss_trabalhador:3`) + funções base | `app_v2.js:901-906, 528-673` |
| Ecrã de login; guarda rota pedida em `sessionStorage.rotaPendente` | `app_v2.js:210-286` |
| `handleLogin` → `SessaoServico.autenticar` (username exacto) | `app_v2.js:342-413`; `servicos.js:17-33` |
| Permissões: `user_profiles.permissions` do `profile_id`; sem perfil → superadmin `{all:true}`, outros `{}` | `servicos.js:34-40` |
| Logout por inactividade: **15 min** | `app_v2.js:704-723` |
| Empresas autorizadas: superadmin ou `allowed_companies` vazio = todas | `servicos.js:56-64` |
| 0 empresas → erro; 1 → entra; >1 → selector (pesquisa se >6, "Última utilizada", badge Consolidação) | `app_v2.js:392-407, 416-514` |
| Troca de empresa com máquina de estados; bloqueia com operações em curso | `js/core/contexto_empresa.js:19-200` |
| Aprovação com palavra-passe de outro admin (Manutenção, inventário) | `manutencao.js:602`; `ui_inventory.js:1021` |

### 1.3 Permissões

- `users`: `{ username, password_hash, password_salt, password_algo, role, profile_id, allowed_companies:[id], colaboradores:{[company_id]: employee_id}, allowed_modules }`.
- `allowed_modules` é carregado mas **nunca verificado** (morto) — `app_v2.js:377`.
- `user_profiles.permissions` — dois formatos:
  - **v2** (`_v2:true`): `<ecra>_view:true`; tarefas `<chave>:true` (`permissoes.js:10-11, 19, 483`). Modelos incluem sempre `dashboard_view`, `fluxo_processos_view`, `rh_portal_view`, `rh_portal_usar` (`:584`).
  - **Legado**: `{all:true}`; `<modulo>[_view|_edit|_modificar|_delete]`; mapa de compatibilidade e herança por prefixo (`app_v2.js:725-888`).
- Portal automático para utilizadores ligados a colaborador (`permissoes.js:597-599`).
- Guardas nas funções protegidas + botões desactivados (`permissoes.js:674+`); API `Permissoes.pode/exigir` (`js/core/permissoes_api.js`).
- Separadores protegidos (POS, Compras, Activos, Armazém, Inventário, Vendas) — `permissoes.js:666-673`.
- **Segregação de funções**: 19 pares incompatíveis (avisa, não bloqueia) — `permissoes.js:514-535`.
- **Perfis-modelo** (≈24) — `permissoes.js:539-581`.
- **Holding** (`is_consolidation`): navegação restrita a mapas/R&C/dashboard/consolidação/administração — `consolidacao.js:290-312`; `rotas.js:56-58`.

---

## 2. Regras transversais escondidas (hooks Dexie — `db_v2.js`) → passam para o servidor

| Regra | Ref. |
|---|---|
| **Bloqueio de exercício encerrado** em C/U/D de tabelas com data (`system_config['closed_year_<cid>_<ano>']`). As tabelas com `rh_company_id`, `pos_company_id`… usam esse prefixo **para fugir ao hook** | `db_v2.js:339-390, 463-541` |
| **Auditoria automática** em `audit_logs` | `db_v2.js:317-337, 463-541` |
| **Dados mestre** placeholder por tabela e empresa; apagar proibido (não migram) | `db_v2.js:238-312` |
| Só contas de **Movimento** (não `T`) em mapeamentos (produtos, terceiros, bancos, POS, RH, …) | `db_v2.js:392-449, 799-827` |
| Só contas da **classe 4** podem ter `currency` | `db_v2.js:695-698, 787-797` |
| **Selagem AGT** de FT/FR/NC em regime: não se apaga/altera/anula; exige série | `db_v2.js:861-906` |
| Remoção única de contas por omissão (3112, 71.1/34.5) | `ui_sales.js:366-393` |
| N.º de lançamento `<Diário><Ano><seq6>` (ex.: SAL2026000001) por diário | `app_v2.js:1-24`; Tesouraria `ui_tesouraria.js:1369-1389` |
| Filtro multi-termo (`,`/`*` = OU) | `engine_v2.js:10-19` |
| Filtro de contas `21, 24-26, *x, x*` | `ui_reports.js:27-60` |

---

## 3. Inventário por módulo

### 3.1 Geral
| Vista | Entrada | Funcionalidade |
|---|---|---|
| `welcome` [M] | `ui_dashboard.js:621` | Cabeçalho empresa, cartões, Dica do Dia, comunicado Aval360 |
| `dashboard` [M] | `ui_painel_modulos.js:966` | Painéis por módulo + **Cubo/Análise Dinâmica** (`ui_cubo.js:350-570`) + Comparação de Empresas (holding) |
| `accounting_bi` [H] | `ui_bi.js:2` | BI contabilístico (não detalhado) |
| `relatorios_gestao` [M] | `modules/gestao/relatorios_gestao.js:662` | Período A vs B; 8 módulos; NC abatem; sem IVA; exclui classe 9 e períodos 13/14 |
| `fluxo_processos` [M] | `fluxo_processos.js:602` | 13 fluxos (funil, tabela, narrativa, impressão) |
| `sgd` [H][B] | `app_v2.js:1166` | `renderSGD` inexistente — ecrã morto |

### 3.2 Logística
| Vista | Entrada | Acções | Regras |
|---|---|---|---|
| `armazem_stock` [M] | `ui_warehouse.js:100/135` | Ajuste manual | |
| `armazem_rececoes` [M] | `:262` | Validar/cancelar/reverter recepção | Contabilizada só na validação: D21/C328, D26/C21 |
| `armazem_guias` [M] | `:534` | Emitir, anular, (des)contabilizar | |
| `armazem_movimentos` [M] | `:1148` | Recalcular valorizações | |
| `armazem_armazens` [M] | `:1378` | CRUD | |
| `pos_armazem` [M] | `ui_pos_armazem.js:3` (Balcão, Picking, Senhas) | Venda, picking, expedição; talão `:580`; guia `:625` | |
| `inventario_sessoes` [M] | `ui_inventory.js:35` | Iniciar/anular/reabrir/regularizar | Aprovação por palavra-passe de outro utilizador (`:1021`) |
| `inventario_contagem` [M] | `:315` | Contagem (cega) | |
| `inventario_revisao` [M] | `:604` | Revisão, pré-visualização da regularização | |

### 3.3 Vendas, POS, Hotelaria, Lavandaria
| Vista | Entrada | Funcionalidade / regras |
|---|---|---|
| `vendas_relatorios` [M] | `ui_sales.js:4018` | KPIs |
| `vendas_clientes` [H] | `:395` | CRUD clientes, edição em massa |
| Categorias [H] | `:5392` | CRUD |
| `vendas_produtos` [M] | `:478` | Produtos (contas, FE, quartos, serviços lavandaria) |
| `vendas_faturacao` [M] | `:594`, form `:927`, `saveSale:1708` | Orçamentos/Encomendas/Faturas-NC/Guias; converter `:2893`; recibos `:3655`; (des)contabilizar `:3038/:3293`; A4 `printInvoice:4692` + QR AGT; recibo `:5356`. **Numeração** fora de regime `"<XX> <ano>/<seq>"`; em regime `"<FT|FR|NC> <série>/<n>"` atómico (`facturacao_agt.js:168-213`). NC exige referência, motivo, saldo. **Contabilização**: diários FC/GR/GR-D/GEPOS; D cliente (fallback 31.1); C 61/62; IVA (fallback 34.5.3); guias D71/C26 custo médio |
| SAF-T [H] | `:9927` | Gera XML SAF-T(AO). **[B] Hash falso** (`:10359-10369`) e M10 fixo — **não portar**: reimplementar com RSA real |
| Facturação electrónica [H] | `facturacao_agt_ui.js`, `facturacao_agt.js`, `facturacao_agt_envio.js` | Regime, estabelecimentos, séries, validador E01/E02/E22-E24, envio, estado, reenvio, pedido de série |
| `pos` [M] | `pos_gestao.js` (abrir `:192`, pagar `:441`, Z `:624`) | Série por terminal; conta transitória por meio de pagamento; talão/Z; documentos POS não se contabilizam individualmente |
| Hotelaria | `hotelaria.js:147-259` | Check-in/out, quartos; diária 14:00→12:00, bloqueio 21:00-08:00 |
| Lavandaria | `lavandaria.js` | Ordens, orçamentos, entregas, danos, peças; 11 regras (`:3-15`) |
| `pos_relatorios`, `pos_terminais`, `pos_config_print` [M] | `pos_gestao.js:1093/:702`; `ui_sales.js:9821` | Relatórios, terminais e contas, impressão |
| Integração/Desvios/Prestação [H] | `pos_prestacao.js:802-823` | Integração em massa, deliberação de desvios, liquidação TPA/transferência/numerário; validação de desequilíbrio bloqueante |

### 3.4 Compras (`ui_compras_v2.js`)
| Vista | Acções | Regras |
|---|---|---|
| `compras_pedidos` [M] | `savePurchaseRequest:1036` | **Deliberação por valor** até 4 escalões; nível 1 = responsável da unidade; ninguém aprova o próprio pedido |
| `compras_prospeccao` [M] | Propostas, adjudicação, quadro de avaliação `:2589` | Aprovar gera encomenda |
| `compras_encomendas` [M] | Criar, gerar factura `:1478` | |
| `compras_rececoes` [M] | `savePartialDelivery:2330` | Contadores FX por linha |
| `compras_faturacao` [M] | `:1631`, directa `:1950`, (des)contabilizar | **Lançamento** `:3270-3468`: troca do 1.º dígito 6→1/2/7; fallbacks 11/21/72; D 328 para mercadoria de encomenda; IVA dedutível obrigatório; dif. câmbio 7621/6621; C fornecedor (fallback 321); diário FF. Descontabilizar **apaga** linhas (bloqueios em `ui_lancamentos.js:2959-3078`) |
| `compras_fornecedores` [M] | `saveSupplier` | |
| `compras_encomendas_clientes` [M][B] | `:3909` | Menu chama `'encomendas_clientes'`, separador usa `'vendas'` → cartão vazio |
| `compras_contratos` [M] | `:4666` | Várias encomendas por contrato |

### 3.5 Salários e RH
| Vista | Entrada | Funcionalidade |
|---|---|---|
| `colaboradores` [M] | `app_v2.js:3833`; `modules/rh/ficha_colaborador.js` | CRUD, importar/exportar Excel (chave NIF), ficha |
| `rh_portal` [M] | `modules/rh/portal_ui.js:115` | Férias, ausências, documentos, agregado, autoavaliação, avaliação ascendente (anónima, mín. 3) |
| `rh_portal_gestao` [M] | `portal_ui.js:611` | Decisão, emissão por modelos, ligação utilizador↔colaborador |
| `rh_ferias` [M] | `modules/rh/ferias.js:147` | 22 dias úteis; PLANEADO/APROVADO/GOZADO/CANCELADO |
| `rh_assiduidade` [M] | `assiduidade_ui.js:51` (5 separadores) | Manual/ficheiro/relógio; fecho mensal; Lei 12/23 art. 219-230 |
| `rh_produtividade` [M] | `produtividade_ui.js:56` | qtd × preço com mínimo e tecto |
| `rh_avaliacao` [M] | `avaliacao.js:203` | Critérios 1-5 ponderados |
| `rh_avaliacao_config`/`_ciclo` [M] | `aval360_ui.js:87/:312` | 360º, bónus → processamento |
| `contratos` [M] | `app_v2.js:4261`; `contratos_massa.js` | CRUD, rescisão, massa; simulação `ui_simulate_contracts.js` |
| `funcoes`, `infotipos` [M] | `app_v2.js:3999/:4130` | CRUD; infotipo `irt` true/false/`conditional_30k` |
| `calcular` [M] | `app_v2.js:4943` | Copiar mês, importar contratos/Excel, fechar |
| `processamento` [M] | `app_v2.js:5733`, `:5787-6016` | Validar, reabrir, integrar `:6018`, pagamento na Tesouraria |
| `bancario` [M] | `app_v2.js:9314` | Bancos/IBAN |
| `relatorios` [M] | `app_v2.js:7013` | Mapa de Remunerações, **Mapa Fiscal IRT**, Mapa INSS, Salários a Pagar, Ordem de Pagamento, Recibos (PDF/ZIP) |

**Motor salarial** (`engine_v2.js:21-97`, `app_v2.js:5787-6016`) — **fonte de verdade para o PayrollService**:
- INSS 3%/8% **fixos no código**; `companies.inss_*` só aparecem em descrições. Reformado = 0.
- **IRT**: 11 escalões com parcela fixa + taxa sobre o excesso (`engine_v2.js:44-81`); avençado 6,5% sobre o bruto, sem INSS (`:85-97`). *(A tabela do plano director está errada — não usar.)*
- Base IRT = bruto − INSS trab. − min(sub. alimentação, 30 000) − min(sub. transporte, 30 000) − faltas; **identifica subsídios pelo nome** e ignora `infotypes.irt`.
- Pro-rata; horas extra +50% até 30h, +75% acima.
- Integração: diário SAL; contas por `[infotipo][tipo_org]` (avençado = coluna `org_type_id -1`); contas de sistema NET_PAY_CREDIT, IRT_CREDIT, IRT_AVENCADO_CREDIT, INSS_FUNC_CREDIT, INSS_EMP_DEBIT/CREDIT, ROUNDING_DIFF (≤10 Kz).

### 3.6 Tesouraria (`ui_tesouraria.js`)
| Vista | Funcionalidade / regras |
|---|---|
| `teso_gestao_pagamentos`/`_recebimentos` [M] | Emitir, copiar, importar, liquidar documentos `:1576`; moeda da conta 43/45; dif. câmbio 6621/7621 |
| `teso_folha_caixa` [H] | `ui_folha_caixa.js:21` — sessões, linhas, fecho com contagem, diário CX, impressão |
| `teso_contab_integracao` [M] | Integração; diário BD (43) ou CX; nota DEMO 10 |
| `teso_contab_historico` [M] | Descontabilizar; reparação |
| `teso_gestao_mapas` [M] | Extractos e disponibilidades |
| `teso_gestao_conciliacao` [M] | Importar extracto, emparelhamento auto/manual, divergências, histórico |
| Conferência de Caixa [H] | `:7063` — rascunho, finalizar, assinar |
| `teso_meios_pagamento` [M] | CRUD, meio padrão |

**[B]** `renderTesouraria` apaga automaticamente documentos sem data (`:275-286`) — **não portar**.

### 3.7 Contabilidade
| Vista | Funcionalidade / regras |
|---|---|
| `lancamentos` [M] | `ui_lancamentos.js:66` — CRUD, importar, estornar, reciclagem, **Agente IA** (`:456`), validação de desequilíbrio, bloqueios de descontabilização |
| `relatorios_contabeis` [M] | `ui_reports.js:62` — Extracto, Balancete, Razão, Balanço, DR, Fluxo, Evolução, IVA; compensações; **Reconciliação AGT**; **alerta de desequilíbrio e "Movimentos por Mapear"** com drill-down (`:381-408, 1049-1080`) ← **destino do relatório de desequilíbrios da migração** |
| `encerramento` [M] | `ui_closing.js` — 5 passos de apuramento (período 13); validações (D=C, classes 6/7 a zero, amortizações, armazém vs 22+26, balanço histórico) |
| `relatorio_contas` [M] | `relatorio_contas.js:927` — R&C, ROE/ROA/ROS |
| `contab_rotinas` [M] | `ui_rotinas.js:22` — 7 rotinas incl. **Imposto de Selo 1%**. **[B]** grava em `routine_logs` inexistente |
| `consolidacao` [M] | `consolidacao.js:755` — conversão cambial, eliminações intragrupo por NIF |
| `tabelas_aux` [M] | `ui_aux.js:100` — diários, terceiros, notas DEMO/fluxo, CC, UN, reciclagem |
| `contabilidade` (Mapeamento RH) [M] | `app_v2.js:2352` |
| `ad_registos`/`ad_propostas`/`ad_recolher` [M] | `modules/acrescimos/ad_ui.js` — contas 37.3-37.6 |

### 3.8 CRM, Estrutura, Orçamento, Projectos, Activos
- **CRM**: pipeline Kanban (converter em proposta/encomenda/factura), contas 360º, agenda, previsão, campanhas, config.
- **Estrutura**: estrutura, organigrama (A4 horizontal), mapa (massa salarial só com `est_ver_salarios`).
- **Orçamento**: rubricas, orçamentos com versões (RASCUNHO→SUBMETIDO→APROVADO→SUBSTITUIDO), controlo, previsões, cenários, alertas. **`OrcControlo.validar` é chamado por Compras, Lançamentos e Tesouraria antes de gravar.**
- **Projectos** (`ui_projects.js`): carteira, detalhe com 8 separadores (WBS/Kanban, equipa, organigrama, autos/revisões, aditamentos, requisições, orçamento), extracto, Gantt.
- **Activos** (`ui_assets.js`): cadastro, categorias, manutenção, amortizações, mapa, fiscal, abates, pendentes. **[B]** menu "Cadastro" → `'dashboard'` sem ramo. **[B]** "correcção" de amortizações em todas as empresas a cada abertura — **não portar**.

### 3.9 Sistema
| Vista | Funcionalidade |
|---|---|
| `config_geral` [M] | Backups, reparações, clonar empresa, Power BI |
| `config_empresas` [M] | CRUD (horas extra, INSS, logótipo, holding) |
| `config_plano` [M] | Plano de contas; **substituir conta em todas as tabelas** |
| `config_moedas` [M] | Moedas, câmbios (âmbito 0 = todas), BAI |
| `config_utilizadores` [M] | CRUD, empresas, perfil |
| `config_perfis` [M] | Editor v2, modelos, segregação |
| `config_logs` [M] | Auditoria |
| `config_manutencao` [M] | 13 acções destrutivas com **aprovação dupla** por outro admin (24h) |
| `config_migração` [M] | Templates e importação |

---

## 4. Integrações externas
| Integração | Detalhe | Ref. |
|---|---|---|
| AGT (Node `127.0.0.1:8790`) | JWS RS256; RSA ≥2048; chave produtor + por contribuinte; Bearer ≥32; `facturas/registar` (≤30), `estado`, `consultar`, `listar`, `series/*`, `saude` | `servico_agt/servidor.js:31-343` |
| Power BI (Express `:3001`) | upload + OData. **[B]** `accounting_maps` vs `accounting_mapos`; `payroll_results` lê campo errado; exporta todas as empresas | `powerbi_api/server.js` |
| IA | Proxy OpenRouter `:3002`; fallback Gemini; `internal_rules`. **[B]** proxy Python em 0.0.0.0 com CORS `*` | `ai_proxy/*`, `js/ui_ai_agent.js` |

---

## 5. Contagens
- Ecrãs no menu: **97** · vistas escondidas/aliases: **≈12** · separadores-ecrã: **≈60**.
- Permissões: **116 ecrãs**, **≈195 tarefas**, **≈260 funções protegidas**.
- Tabelas Dexie ≈150; nunca usadas: `payment_letters`, `documents`, `document_types`; referenciadas mas inexistentes: `routine_logs`, `delivery_note_items`.

## 6. Riscos de paridade
1. Hash PBKDF2 legado + migração de texto simples → **resolvido na Fase 1**.
2. Hooks globais (lock de exercício, auditoria, contas de movimento, moeda classe 4, selagem AGT) → Observers/Services no servidor.
3. Prefixos de tenant usados para fugir ao lock → decidir explicitamente que tabelas ficam sujeitas ao lock de exercício.
4. Descontabilizar **apaga** `journal_lines` → no novo sistema: estorno com rasto (lançamento inverso + ligação).
5. Cálculo salarial com regras implícitas (INSS fixo, isenção 30k por nome, base IRT ignora flag) → reproduzir exactamente e corrigir só com decisão explícita.
6. SAF-T com hash falso → reimplementar assinatura RSA conforme AGT.
7. Bugs de navegação (Encomendas Clientes, Activos Cadastro, `sgd`, `routine_logs`) → corrigir.
8. Rotinas destrutivas ao abrir ecrãs → **não portar** (substituir por validações explícitas/relatórios).
9. Funções sobrepostas pela ordem de carregamento → portar a última versão.
10. Contas/diários de fallback fixos (31.1, 34.5.3, 321, 328, 72, 21, 11; FC, FF, SAL, BD, CX, GR, GEPOS, AP-O) → configuração por empresa com os mesmos defaults.
11. Consolidação sem hooks; restrições da holding.
12. Power BI com bugs → reimplementar sobre a API.
13. Proxy IA inseguro → integrar no backend com segredos no servidor.
14. Estado só no browser (token AGT, chaves IA, visões do Cubo, favoritos) → persistir no servidor.
15. Segregação de funções e aprovação dupla → backend.
16. Numeração `max+1` → locks Redis + sequência transaccional.
