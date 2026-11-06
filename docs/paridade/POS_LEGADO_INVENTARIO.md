# POS_LEGADO_INVENTARIO — Inventário funcional do módulo POS do sistema legado

Origem: `C:\xampp\htdocs\ERP_CONSULVOLT_MEU\v_boss\payroll_system_web` (HTML/JS, base de dados no browser: Dexie/IndexedDB, `db_v2.js`). Análise só de leitura, feita a partir do código; as referências `ficheiro:linha` são ao legado (`js/…`). Destino: reconstrução no ERP novo (Laravel 13 / PostgreSQL / React 19 / antd 6) «igualzinho». Data do inventário: 2026-11-06.

**Convenções**: «[sic]» = erro de ortografia/codificação do legado reproduzido tal e qual; «BUG?» = comportamento aparente de erro a decidir; os rótulos em português estão transcritos tal como no código (incluindo acentuação inconsistente do legado). Valores monetários mostram-se com `Intl.NumberFormat('pt-AO', currency AOA, 2 decimais)` com «AOA» substituído por «Kz» (`engine_v2.js:101-114`). Todas as datas gravadas são `AAAA-MM-DD` (`getLocalDate`) ou ISO completo (`toISOString`).

**Aviso de completude**: ver o «Índice de ficheiros-fonte lidos» no fim — os ficheiros pedidos foram lidos na íntegra, excepto `js/scratch/fix_pos_payments.js` (lido 1-120 + pesquisa), `ui_folha_caixa.js` (secções de listagem de faturas em aberto 1030-1240 e de impressão/importação 1610-2020 só em amostragem) e as partes de `ui_sales.js` obsoletas (marcadas). Nada foi inventado: onde só se confirmou por pesquisa, diz-se.

---

## 1. VISÃO GERAL

### 1.1 Sub-módulos do POS e a quem se destinam

| # | Sub-módulo | Ficheiros | Destinatário | Documento fiscal | Caixa/sessão |
|---|-----------|-----------|--------------|------------------|--------------|
| A | **POS comercial** (terminais `loja` e `restaurante`): frente de caixa, terminais, Z, desvios, integração, prestação de contas, relatórios, impressão | `ui_sales.js` (base), `pos_gestao.js`, `pos_prestacao.js` | Operador de caixa (supermercado, loja, restaurante/esplanada) e tesoureiro/contabilista (back-office) | **Factura-Recibo (FR)** por venda | Sessão de caixa por terminal (`pos_sessions`) |
| B | **POS de Armazém (Logística)**: balcão com Guia de Saída, fila de picking de encomendas, monitor de senhas | `ui_pos_armazem.js` | Operador de armazém / logística | **Não fiscal** (Guia de Saída «GE POS»/«GE PICK») | Sem sessão, sem terminal |
| C | **POS Hotelaria** (terminal `hotelaria`): mapa de quartos, check-in/estadias/consumos/check-out | `hotelaria.js` (+ `ui_sales.js`, `pos_gestao.js`) | Recepção de hotel/pensão | FR por quarto ou FR única | Sessão de caixa do terminal |
| D | **POS Lavandaria e Alfaiataria** (terminal `lavandaria`) + backoffice «Lavandaria» | `lavandaria.js`, `fluxo_lavandaria.js` | Balcão de lavandaria/alfaiataria e gestor | **FR** (venda directa) ou **FT + recibo RC** (conta corrente) | Sessão de caixa do terminal |
| E | **Folha de Caixa** (Tesouraria) | `ui_folha_caixa.js`, `moedas_tesouraria.js` | Operador de caixa/tesoureiro | Não é documento de venda | Sessão única por empresa (conta classe 45) |

Áreas administrativas transversais do POS comercial (separadores da vista «Ponto de Venda (POS)»): **Relatórios POS**, **Terminais POS** (+ Definições de caixa), **Config. Impressão**, **Integração contabilística**, **Desvios de caixa**, **Prestação de contas**, e **Lavandaria** (`lav_config`). Há ainda os **Fluxos de Processos** «POS» (uma sessão = um processo, `fluxo_vendas.js:319-492`) e «Lavandaria» (`fluxo_lavandaria.js`), e os indicadores do **Painel de módulos** e dos **Relatórios de gestão**.

### 1.2 Tipos de terminal e o que os distingue
`pos_terminals.type` ∈ `loja` (Loja / Supermercado), `restaurante` (Restaurante), `hotelaria` (Hotelaria), `lavandaria` (Lavandaria / Alfaiataria) (`pos_gestao.js:715,789`). Distinção feita só por esse campo (+ regras `hotel_*` nos terminais hotelaria):
- `loja`: grelha de produtos, carrinho único, desconto global, checkout.
- `restaurante`: acrescenta **Mapa de Mesas** (carrinho por mesa persistido em `localStorage`), **Suspender**, **Consulta** (conta provisória), **Criar Mesa**; `sales.table_name` = mesa.
- `hotelaria`: Mapa de Quartos (produtos `is_room`), painel de check-in/estadia, consumos por quarto na BD, check-out com recálculo de saída tardia; produtos quarto só aqui.
- `lavandaria`: ecrã próprio de ordens de serviço (substitui a frente de caixa quando há caixa aberta).
Configuração comum a todos (por terminal): **código** (série `FR-POS/<código>/<ano>` e sessões `<código>-<ano>-NNNN`), nome, fundo de maneio habitual, **armazém de saída de stock**, cliente por omissão, unidade de negócio, centro de custo e **lista de meios de pagamento com contas transitória/liquidação/comissão** (§2.A.12). Parâmetros por empresa: `pos_settings` (contas de sobras/faltas/operador, tolerância, diário `GEPOS`), `lav_settings`, impressão em `localStorage`.
O POS de Armazém e a Folha de Caixa **não usam terminais**.

### 1.3 Como se chega a cada sub-módulo (menu, rotas, permissões)
Menu lateral (`index_arrumado.html:156-205`):
- **Logística e Armazém › «POS de Armazém»** (`data-target="pos_armazem"`, ícone fa-cash-register) → `renderPOSArmazém()` (`app_v2.js:1150`); rota `#/stock/pos-armazem` (`core/modulos_registo.js:22`, módulo «Stock e Armazém», pacote `compras`); vista sujeita a `hasView('armazém')` (`app_v2.js:789`); entrada também na lista de módulos `app_v2.js:3276`.
- **Vendas › Ponto de Venda (POS)** (grupo com 4 entradas): **Frente de Caixa** (`pos` → `renderPOS('dashboard')`, `#/vendas/pos`), **Relatórios POS** (`pos_relatorios`, `#/vendas/pos/relatorios`), **Terminais POS** (`pos_terminais`, `#/vendas/pos/terminais`), **Config. Impressão** (`pos_config_print`, `#/vendas/pos/impressao`). As abas Integração contabilística / Desvios de caixa / Prestação de contas / Lavandaria **não têm item de menu**: abrem como separadores internos de `renderPOS` (`pos_integracao`, `pos_desvios`, `pos_prestacao`, `lav_config`) e os atalhos dos Fluxos chamam `renderPOS(<aba>)` (`fluxo_vendas.js:463-468`; `fluxo_lavandaria.js:230-288`).
- **Tesouraria › Folha de Caixa** (`teso_folha_caixa`, `#/tesouraria/folha-caixa`).
- Hotelaria: não tem menu; é um tipo de terminal.
- Painel de módulos: «Vendas e Faturação» agrupa «Ponto de Venda (POS)» (`app_v2.js:3289`); «Logística» agrupa «POS de Armazém».
- Perfil «Operador POS / Lavandaria»: ver §6. O módulo `vendas` herda `pos*` (`app_v2.js:769-772`: se `vendas`/`vendas_view` for false/true decide para `pos`/`pos_*`).
- Terminal activo por posto e empresa: `localStorage pos_active_terminal_<empresa>` (guarda o `id` do terminal); troca em «Trocar Terminal POS».
- Pré-requisitos para vender: terminal activo com ≥1 meio de pagamento activo com conta transitória; caixa (sessão) aberta; cliente seleccionado (por omissão «Consumidor Final» criado automaticamente); para lavandaria: peças e serviços criados; para hotelaria: produtos `is_room`.

### 1.4 Visão do fluxo contabilístico (decisões de 2026-09-15, `pos_gestao.js:2-9`)
1. Terminais, sessões e fechos na BD (vários computadores, auditável). 2. TPA com conta transitória e liquidação com comissão. 3. Série documental própria por terminal. 4. Desvios de caixa só lançados depois de deliberados. 5. **A venda não gera tesouraria nem contabilidade**: o recebimento fica na **conta transitória do meio de pagamento** (D transitória / C proveito + IVA, feito em massa por sessão e dia na «Integração contabilística»); a «Prestação de contas» regulariza as transitórias: numerário → Folha de Caixa (D conta 45 / C transitória), TPA e transferências → Tesouraria (`RECEBIMENTO` pendente: D banco 43 / C transitória, com comissão).

---

## 2. POR SUB-MÓDULO (ecrãs, campos, acções, impressão, estados)

Ordem: **2.A POS comercial (inclui administração, integração, desvios e prestação)**, **2.B POS de Armazém**, **2.C Hotelaria**, **2.D Lavandaria e Alfaiataria**, **2.E Folha de Caixa**.

## 2.A POS CORE — «Ponto de Venda (POS)» (Frente de Caixa, Terminais, Sessões, Z, Integração, Desvios, Prestação)

Ficheiros: `js/ui_sales.js` (base, depois sobreposta), `js/pos_gestao.js` (terminais/sessões/pagamentos na BD; carregado DEPOIS de ui_sales.js e substitui funções — `pos_gestao.js:10`), `js/pos_prestacao.js` (integração contabilística, desvios, prestação de contas; carregado depois de pos_gestao — `pos_prestacao.js:9`).

### 2.A.1 Entrada e separadores (`window.renderPOS`, `ui_sales.js:135-183`)
Vista «Ponto de Venda (POS)» (ícone `fa-cash-register`), subtítulo «Administre as frentes de caixa, consulte relatórios e configure impressoras termicas.» (`ui_sales.js:152-153`). Barra de separadores (botões `.tab-btn`, `ui_sales.js:141-144`) por ordem:
1. **Frente de Caixa** (`dashboard`, verde #10b981) — mostra cartão «Frente de Caixa Imersiva» com texto «Inicie o ponto de venda dedicado para o registo Rápido de vendas. Perfeitamente otimizado para ecras tateis, computadores, tablets ou dispositivos móveis.» e botão **«Iniciar Ponto de Venda (POS)»** → `enterExclusivePOS()` (`ui_sales.js:160-174`).
2. **relatórios POS** (`relatorios`, roxo #7c3aed) → `renderPOSRelatoriosTab` (sobreposto por pos_gestao `:1096`).
3. **Terminais POS** (`terminais`, laranja #d97706) → `renderPOSTerminaisTab` (`pos_gestao.js:705`).
4. **Config. Impressão** (`config_print`, #1e293b) → `renderPOSConfigPrintTab` (`ui_sales.js:9932`).
5-7. Acrescentados por `pos_prestacao.js:802-823` (botões com `data-pos-extra`): **Integração contabilística** (`pos_integracao`, ícone fa-book), **Desvios de caixa** (`pos_desvios`, fa-balance-scale), **Prestação de contas** (`pos_prestacao`, fa-hand-holding-usd). Inserem-se no fim da barra `.pos-tabs-container > div`.
Os separadores 2-7 renderizam em `#sales-content` dentro de `#pos-admin-content` (`ui_sales.js:176-181`). Observação: o botão «Frente de Caixa» e os restantes de `pos_*` não têm o separador «Hotelaria/Lavandaria» próprios: o tipo do terminal activo decide o ecrã.

**Modo imersivo** (`enterExclusivePOS`, `ui_sales.js:185-188`, `renderExclusivePOSLayout` `:9347-9397`): põe `body.pos-exclusive-mode`; cabeçalho escuro (#1e293b, altura 60px) «Frente de Caixa (POS)» / «Ambiente Imersivo Dedicado»; seletor de dispositivo **PC / Tablet / Mobile** (`setPOSDeviceMode`, guarda `localStorage.pos_device_mode`, omissão `pc`; `.pos-viewport-frame.mode-<x>` emula o tamanho); botão **«Voltar ao Office ERP»** (`exitExclusivePOS`, `:9404`; versão de pos_gestao `:222-227` pergunta, se a caixa estiver aberta: «A caixa <sessão> continua aberta. Pode sair e voltar mais tarde, mas feche a caixa no fim do turno para conferir os valores. Sair do POS?»).

### 2.A.2 Tipos de terminal (`pos_terminals.type`)
`loja` («Loja / Supermercado»), `restaurante` («Restaurante»), `hotelaria` («Hotelaria»), `lavandaria` («Lavandaria / Alfaiataria») — `pos_gestao.js:715,789`. O ecrã de venda é decidido por `terminal.type` (`window._posMode = activeTerminal.type`, `ui_sales.js:6554`; lavandaria com caixa aberta → `lavRenderFrente()`, `pos_gestao.js:139`; hotelaria → hotelaria.js). Distinguem-se: modo `restaurante` mostra «Mapa de Mesas», botões Suspender/Consulta/Criar Mesa; `hotelaria` mostra mapa de quartos + painel de hospedagem; `loja` só a grelha de produtos. Produtos `is_room` só aparecem em terminal hotelaria (`ui_sales.js:7020-7022`, `addPOSProduct :7158-7161` alerta «O artigo de hospedagem/quarto "X" so esta disponível no POS do tipo Hotelaria.»).

### 2.A.3 Abertura de caixa (ecrã «Abertura de Caixa»)
Ecrã quando não há sessão aberta (`ui_sales.js:6457-6496` + `pos_gestao.js:155-190`):
- Título «Abertura de Caixa»; subtítulo «Selecione o terminal e o fundo de maneio inicial.».
- Campo **«Terminal POS / Caixa:»** `<select id=pos-terminal-select>` com os terminais activos; `onchange` grava `localStorage.pos_active_terminal_<empresa>` e re-renderiza.
- Campo **«Fundo de Maneio / Valor Inicial (Kz):»** `<input type=number id=pos-opening-cash step=0.01>`; `pos_gestao.ajustarEcraPOS` preenche com `terminal.default_float` (2 casas) (`:163`).
- Bloco informativo `.pos-abertura-info` (`:169-173`): «Operador: <nome>», «Série: FR-POS/<código>/<ano>», «Meios de pagamento: <nomes dos meios activos com conta transitória>» ou «nenhum meio com conta transitória configurado» a vermelho. Se não há terminal ou meios activos o botão fica `disabled` com título «Configure os meios de pagamento do terminal em Terminais POS» (`:174`).
- Aviso `avisoSessoesAntigas` (`:147-153`) em laranja: «Existe 1 sessão de caixa aberta» / «Existem N sessões de caixa abertas» «desde dias anteriores: <session_code> (<terminal>, <data>). Feche-as para garantir a conferência diária.» (sessões abertas com data de abertura < hoje).
- Botão **«Abrir Caixa & Iniciar Vendas»** → `openPOSRegister` (`pos_gestao.js:192-219`). Validações e mensagens: tabelas em falta «Actualize a página (Ctrl+F5) para criar as tabelas do POS.»; «Seleccione um terminal válido.»; «O terminal "X" não tem meios de pagamento com conta transitória.\n\nConfigure-os em POS → Terminais POS antes de abrir a caixa.»; «O fundo de maneio não pode ser negativo.»; sessão já aberta no terminal: «O terminal já tem a sessão <código> aberta por <operador> desde <data/hora>.» e sincroniza. Efeito: `session_counters[ano]++` atómico (`proximoContador` em transação Dexie `pos_gestao.js:111-119`), cria `pos_sessions` com `session_code = <TERM>-<ANO>-<NNNN>` (4 dígitos), `status 'ABERTA'`, `opening_float = r2(fundo)`, operador (id/nome do `currentUser`), `posting_status 'PENDENTE'`, `settlement_status 'PENDENTE'`, `deviation_status 'N/A'`.
- Versão antiga em localStorage (`ui_sales.js:8602-8628`): `session_id = POS_SESS_<ts>`, estado em `pos_register_state_<emp>_<terminal>` — SUBSTITUÍDA; migração dos terminais em `migrarTerminaisLegados` (`pos_gestao.js:54-74`: terminais `pos_terminals_<emp>` do localStorage → `pos_terminals`, códigos T01, T02…, meios padrão; terminal por omissão `T_DEFAULT` «Terminal Principal» tipo loja).

### 2.A.4 Ecrã de venda (loja/restaurante) — disposição (`renderPOSTab`, `ui_sales.js:6448-6806`)
Contentor `.pos-wrapper` (fonte Outfit). Três faixas:
**(a) Barra superior `.pos-topbar`** (fundo #f8fafc, raio 15px): crachá de modo («Modo: Loja» azul / «Modo: Restaurante» verde / «Modo: Hotelaria» laranja — `:6556-6563`), crachá do terminal activo (roxo, ícone fa-desktop), botão `fa-exchange-alt` «Trocar de Terminal Ativo» → modal «Trocar Terminal POS» (`switchPOSTerminal :8720-8752`, label «Selecione o Terminal Ativo:», botão «Ativar Terminal»), **widget de saldo** `#pos-register-balance-widget` (pílulas Fundo / <cada meio> / Vendas — `pos_gestao.js:254-265`; cores por tipo de meio: Numerário #059669, TPA #2563eb, Transferência #7c3aed, Fundo #475569, Vendas #0f172a), botão vermelho **«Fechar Caixa»** (`showPOSCloseRegisterModal`), bloco `.pos-sessao-info` com `#<session_code>`, operador e botão **«Relatório X»** (`pos_gestao.js:177-187`). À direita: **«Cliente:»** `<select id=pos-customer>` (Select2; opções «Nome (NIF: …)») + botão `fa-user-plus` «Criar Novo Cliente». Cliente por omissão: «Consumidor Final» NIF `99999999` (procura por `nif==='99999999'||'999999999'` ou nome contendo «consumidor»; se não existir **cria-o automaticamente**, tipo CLIENTE, address «Consumidor Final», sem conta contabilística — `ui_sales.js:6526-6548`).
**(b) Painel «Mapa de Mesas / Consumo em Aberto»** (só restaurante; hotelaria: «Mapa de Quartos / Hospedagem em Aberto») `#pos-tables-panel`: grelha `#pos-mapa-grelha` de cartões `.table-card` (tamanho ajustável com `GrelhaZoom` chaves `pos_mesas`/`pos_quartos`, padrão 140, min 90, max 260). Cartão: nome, «Livre» ou «Ocupado: <total>» (cinzento / laranja #f59e0b; activo com borda azul); em hotelaria também «diaria: <valor>». Botão verde **«Criar Mesa»** (`showCreatePOSTableModal :8550`: campo «Nome da Mesa / área:» placeholder «Ex: Mesa 9, Esplanada 3», botão «Adicionar»; erros «Por favor, introduza um nome para a mesa.» / «Uma mesa com esse nome já existe.»; acrescenta « ???» se o nome não tiver emoji [artefacto de codificação — as mesas por omissão têm sufixos `???`/`?` corrompidos, ver §8]; persistência `localStorage.pos_custom_tables`).
 Mesas por omissão (`ui_sales.js:6308-6322`): `Balcao`, `Mesa 1…Mesa 8`, `Terrasso 1`, `Terrasso 2`, `Take-Away`. O carrinho de cada mesa persiste em `localStorage.pos_custom_table_carts` (objeto id→itens), gravado a cada `renderPOSCart` (`:6905-6908,6949-6953`).
**(c) Painel principal** `.pos-main-layout` (grid `420px 1fr`):
 *Esquerda — Carrinho*: cabeçalho «Carrinho» + crachá com a mesa/quarto activa (restaurante/hotelaria) + botão **«Limpar»** (`clearPOSCart`); em hotelaria o painel de hospedagem; lista `#pos-cart-items` (zona de largar *drag&drop*: borda tracejada azul ao arrastar; `drop` lê `dataTransfer text/plain` = id do produto → `addPOSProduct`, micro-animação `scale(1.015)` 150 ms — `:6774-6798`); rodapé: **«Desconto (%):»** `<input id=pos-discount type=number min=0 max=100>`; resumo «Subtotal Líquido:», «IVA / Impostos:», linha «Desconto Aplicado:» (oculta se 0, vermelha), **«Total Geral:»** (1.4rem azul). Botões: restaurante → **«Suspender»** (`suspendPOSTable`), **«Consulta»** (`printPOSConsultation`), e **«Checkout»** (`showPOSCheckout`); loja → só **«Checkout»**.
 Cartão do item no carrinho (`renderPOSCart :6913-6944`): nome, input de **preço unitário editável** (step 0.01 min 0, «Kz»), controlos `-` / input quantidade (min 1) / `+`, total da linha, ícone lixo. Carrinho vazio: «Carrinho Vazio» / «Selecione produtos na grelha é direita para faturar.».
 *Direita — Catálogo*: caixa de pesquisa `#pos-search` placeholder «Procurar produtos por nome, Código ou categoria...» (filtra por `name` e `code` com `matchGlobalFilter`, `:7031-7033`), grupo **Todos / Com Stock / Sem Stock** (`setPOSStockFilter`, persiste `localStorage.pos_stock_filter`: `all|in|out`), checkbox **«Ocultar Imagens»** (`pos_hide_images`), alternador grelha/lista (`pos_layout_mode`: `grid|list`) + zoom `pos_vendas` (padrão 170, min 110, max 300), barra horizontal de **categorias** com contagem (botão «Todos» + uma por `product_categories`; ícone por nome via `getPOSCategoryIcon :6370-6378`: bebida/sumo/vinho/cerveja→fa-glass-martini-alt; refeição/prato/almoão/jantar/comida→fa-utensils; sobremesa/doce/gelado/bolo→fa-ice-cream; cafetaria/café/chá→fa-coffee; entrada/sopa/petisco→fa-cookie; resto fa-box), e grelha `#pos-products-grid`.
 Cartão de produto (`renderPOSProductsGrid :6993-7150`): imagem `image_base64` ou ícone da categoria, nome (2 linhas), «Cd: <código>», preço `unit_price`, crachá canto: «Bloqueado» (`is_blocked`), «Stock: N» (verde; amarelo se <5; vermelho se ≤0) para `is_inventory`, ou «Serviao» [sic] para serviços. Produtos bloqueados ou inventariados sem stock ficam a 65% de opacidade, `cursor:not-allowed`, não arrastáveis. Estado vazio «Nenhum Produto Encontrado» / «Tente outra categoria ou outro termo na pesquisa.».
 Filtros de lista: exclui `is_master_data`; exclui `is_room` fora de hotelaria; filtro stock `in`: `is_inventory === false || stock_qty>0`; `out`: inventariáveis com `stock_qty<=0`.
 **Não existe leitor de código de barras dedicado nem atalhos de teclado no POS**: só o campo de pesquisa por nome/código (§7).

### 2.A.5 Regras do carrinho (`ui_sales.js:7152-7235`)
- `addPOSProduct`: bloqueios por ordem — quarto fora de hotelaria; `is_blocked` («O artigo "X" esta bloqueado para venda.»); inventariável com `stock_qty<=0` («O artigo X encontra-se fora de stock.»); se já no carrinho e inventariável com `quantity>=stock_qty`: «Não é possível faturar mais unidades deste produto do que o stock existente (N).»; senão +1. Serviços (não inventariáveis) sem limite.
- `updatePOSCartQty(i,delta)`: nova qtd ≤0 remove o item; inventariável com `newQty>stock_qty`: «Quantidade limite de stock disponível para X atingida (N).».
- `updatePOSCartPrice(i,v)`: `v` NaN ou <0 repõe `product.unit_price`; guarda `item.price` (preço de linha editável pelo operador **sem permissão específica**).
- `updatePOSDiscount(v)`: percentagem global do carrinho, limitada a 0–100.
- `setPOSTable(id)`: guarda carrinho da mesa actual, muda, em hotelaria chama `initializePOSRoomStayIfNeeded(id)` (se id≠'Balcao'), restaura carrinho da nova mesa. `suspendPOSTable`: guarda, esvazia, volta a `Balcao`, modal «Conta Suspensa» / «Os consumos da mesa foram registados e guardados com sucesso.». `setPOSMode`: loja limpa carrinho; outro carrega o da mesa activa.

### 2.A.6 Totais (`updatePOSTotals`, `ui_sales.js:6847-6889`) — FÓRMULA
Para cada linha: `price = item.price ?? product.unit_price` (preço **com IVA incluído**); `rowGross = price*qtd`; `taxRate = product.tax_rate||0`; `rowNet = rowGross/(1+taxRate/100)`; `rowTax = rowGross-rowNet`. Soma `totalGross/totalNet/totalTax`. Desconto: `discountVal = totalGross*(pct/100)`; `finalTotal = totalGross-discountVal`. `_posTotals = {net,tax,discount: discountVal, gross: finalTotal}`. **Sem arredondamento intermédio** (formatMoney só na apresentação; `r2` só ao gravar a venda). **Quirk:** `net` e `tax` são os valores ANTES do desconto, só `gross` reflecte o desconto (logo `net+tax ≠ gross` com desconto; o talão mostra «Subtotal: net+tax», «Desconto: -x», «TOTAL A PAGAR: gross»). A contabilização reparte o `total_gross` pelas linhas proporcionalmente (`factor`, `pos_prestacao.js:139`), corrigindo isto.

### 2.A.7 Checkout e pagamentos mistos (`pos_gestao.js:267-476`)
Botão «Checkout» → `showPOSCheckout` (versão pos_gestao, `:337`): carrinho vazio → «O carrinho está vazio. Adicione produtos antes de finalizar.»; caixa fechada → «Abra a caixa antes de vender.»; terminal sem meios → «O terminal não tem meios de pagamento configurados (Terminais POS).». Modal «Finalizar venda» (`#pos-checkout-overlay`, 560px): caixa «TOTAL A PAGAR» (valor 1.8rem), linha pequena «<terminal> · Sessão <código> · <operador>», uma linha por meio de pagamento activo do terminal (`meiosActivos` = `active!==false && transit_account`): ícone+nome, input numérico (`#pos-pm-i`, valor inicial = total no primeiro meio e 0 nos restantes, min 0 step 0.01), botão **«Tudo»** (põe tudo neste meio e 0 nos outros), e para TPA/Transferência um input de referência (`#pos-ref-i`; placeholders «N.º do talão / autorização do TPA (opcional)» e «N.º do comprovativo da transferência (obrigatório)»). Resumo: «Total recebido:», «Troco (numerário):» (verde; vermelho «Falta X» quando pago<total) e `#pos-erro`. Botões **«Cancelar»** e **«Confirmar e emitir»**.
`calcularPagamentos` (`:296-322`): `total=r2(gross)`; cada linha `valor=r2(input)`; negativos → «Os valores não podem ser negativos.»; `pago=Σ`; `numerario=Σ(kind NUMERARIO)`; `outros=pago-numerario`; `troco=max(0,pago-total)`. Erros por ordem: `pago < total-0.005` → «Falta cobrar X.»; `outros > total+0.005` → «O TPA e as transferências não podem exceder o total a pagar (só há troco em numerário).»; transferência >0 sem referência → «Indique o n.º do comprovativo de "<nome>".». O troco abate-se **só ao numerário** (por ordem das linhas): `pagamentos[]` gravados com `amount` já líquido de troco e `received` = valor entregue.
Estrutura de cada pagamento gravado: `{pm_id, kind, name, amount, received, reference, transit_account, settlement_account, tpa_code}`.

### 2.A.8 Emissão da venda (`finalizePOSPayment :444-476`, `criarVendaPOS :389-432`)
1. Desactiva o botão, spinner «A emitir o documento...». `sessaoValida()` (`:376-387`): caixa aberta; sessão ainda `ABERTA` na BD (se não: sincroniza e erro «A sessão de caixa já foi fechada (possivelmente noutro computador). Volte a abrir a caixa.»); terminal existe («O terminal da sessão não existe.»). Cliente obrigatório («Nenhum cliente seleccionado para emitir o documento.»).
2. **Numeração**: se existe `window.FacturaAGT`: `FacturaAGT.numerar('Factura-Recibo', data, {origem:'T<id>', origemNome:'POS <cod> — <nome>', terminalCodigo})` → série própria do terminal «FR <TERMINAL><ANO>/N» (facturação electrónica AGT); se fora do regime, `numerarLocal` (série atribuída em Vendas › Séries, do terminal ou geral); sem `FacturaAGT`: `numeroDocumento(term)` = `FR-POS/<código>/<ano>/<n 5 dígitos>` com contador `counters[ano]` do terminal, até 50 tentativas a verificar duplicado em `sales.doc_number` («Não foi possível obter um número de documento livre para o terminal.»). Versão antiga: `FR-POS/<ano>/<seq 4 dígitos>` por contagem (`ui_sales.js:7497-7501`).
3. Grava `sales`: `doc_type 'Factura-Recibo'`, `status 'PAGO'`, `is_posted false`, `total_gross=r2(gross)`, `total_net=r2(net)`, `total_tax=r2(tax)`, `discount=r2(discount)`, `pos_session_id`, `pos_terminal_id`, `pos_terminal_code`, `pos_payments` (array acima), `pos_change=r2(troco)`, `pos_operator`, `payment_method` = nome do meio único ou «Misto (A + B)», `business_unit_id`/`cost_center_id` do terminal, `table_name` (mesa se restaurante), + campos FE da reserva AGT. Se o INSERT falha, **liberta a reserva** de numeração (`FacturaAGT.libertar`).
4. Linhas `sale_items`: `quantity=billed_qty=delivered_qty`, `unit_price = preço (com IVA)`, `tax_rate = product.tax_rate`, `total = preço*qtd`, `description = item.description||product.name`.
5. Stock (só `is_inventory`): `products.stock_qty = max(0, stock-qtd)`; `updateWarehouseStock(armazem, produto, -qtd)` (armazém = `terminal.warehouse_id || _lastActiveWhId || 1`); `inventory_movements` tipo `'SAÍDA'` com `reference 'Venda POS: <doc>'`, `unit_price`, `third_party_id`. **Não há movimento de CMV nem custo na venda POS** (ver §4).
6. Se há reserva FE: `FacturaAGT.selar(saleId)`; se `fe_estado==='COM_ERROS'` notificação «<doc>: o documento tem erros para a AGT (ver Vendas › Facturação).».
7. **Impressão** `imprimirTalao :434-442`: respeita `localStorage.pos_print_auto_checkout_<emp>` (omissão `yes`), `pos_print_format_<emp>` (`thermal`|`a4`; A4 → `printInvoiceA4` = `printInvoice`); térmico → `printPOSThermalReceipt(...)` com somas por tipo `{cash,card,bank}`.
8. Restaurante: esvazia carrinho da mesa activa e grava `pos_custom_table_carts`. Limpa `_posCart` e `_posDiscount`; fecha modal; modal de sucesso (`showPOSSucessoModal`, `ui_sales.js:305-365`) «Venda concluída» / «Documento <n> emitido. O recebimento fica na conta transitória do meio de pagamento até à integração e à prestação de contas.» com botões «Imprimir A4» e «Descarregar PDF» e «Concluir (OK)». Erros: alert «Erro ao gravar a venda: …» (reactiva o botão).
**Decisão de arquitectura (cabeçalho `pos_gestao.js:5-9`): a venda POS NÃO gera tesouraria** (a versão antiga criava `treasury_documents` RECEBIMENTO + `treasury_items` contrapartida cliente por meio — `ui_sales.js:7597-7634`, descontinuada). O recebimento fica na **conta transitória do meio**, regularizada pela integração em massa + prestação de contas.
Contabilização individual **bloqueada** para docs POS: `postDocumentToAccounting` mostra «O documento <b>X</b> é do POS e é contabilizado pela integração em massa do POS (conta transitória do meio de pagamento).» (título «Documento do POS») e `_executeSalePosting` devolve false para `pos_terminal_id` (`pos_gestao.js:1138-1152`).

### 2.A.9 Consulta de mesa (restaurante) (`printPOSConsultation`, `ui_sales.js:8168-8546`)
Carrinho vazio → «O carrinho esta vazio. Adicione produtos antes de gerar a consulta de mesa.». Configuração `pos_print_consultation_behavior_<emp>`: `preview` (omissão) → modal «Pré-visualização da Consulta» (papel simulado) com botões **Fechar / Imprimir / Pagar / Checkout**; `direct` → imprime logo. Conteúdo do talão (78 mm, Courier New): «*** CONSULTA DE MESA ***», «DOCUMENTO Não FISCAL», empresa (maiúsculas), NIF, município/província, «Tipo Doc: Consulta de Mesa / Conta provisória», Data (`toLocaleString`), «Operador: CAIXA POS» [fixo], Mesa, Cliente, tabela Artigo/Subtotal (`qtd x preço`), «Subtotal:» (net+tax), «Desconto:», **«TOTAL ESTIMADO:»**, caixa «DOCUMENTO PROVISÓRIO PARA conferência / Não SERVE DE FATURA / Não FISCAL» e rodapé configurável.

### 2.A.10 Talão térmico de venda (`printPOSThermalReceipt`, `ui_sales.js:8000-8166`)
Janela 350x600, `body onload print+close`, largura 78 mm, Courier New. Estrutura: nome da empresa (maiúsculas), «NIF: …», «município, província»; linha ---; «Talão nº: <doc>», «Data: <agora>», «Operador: CAIXA POS» [fixo, ignora operador real — quirk], «Cliente:», «NIF Cliente:» (999999999 se vazio); tabela «Artigo / Subtotal» (cada linha `nome` + `qtd x preço` e total); «Subtotal:» (net+tax), «Desconto:» se >0, **«TOTAL A PAGAR:»**; «Meios de Pagamento:» «- Numerário: x», «- Multicaixa: x», «- Transferência: x», «Valor Entregue (Dinheiro):» e «Troco:» (se houve numerário); QR AGT quando o documento está selado (`fe_regime && fe_documento`: o total passa a ser `documentTotals.grossTotal` da AGT, QR via `FacturaAGTQR.doDocumento`, imagem 38×38 mm, textos «Facturação electrónica · Série X», «Consulte este documento no portal da AGT»); rodapé: «Coordenadas Bancárias:» (meios `payment_methods` activos com `iban` + `SWIFT`), mensagem `pos_print_footer_msg_<emp>` (omissão «Obrigado pela sua visita!») e «Processado por Computador (Software ERP)». Bug de CSS: `border-collapose` (typo) — sem efeito.

### 2.A.11 Relatório X, fecho de caixa, Relatório Z (`pos_gestao.js:229-285,480-685`)
**Totais da sessão** `totaisSessao` (`:230-251`): vendas = `sales` da empresa com `pos_session_id==sessão`, sem `lav_order_id` (os da lavandaria vêm de `window.posFontesSessao`), sem `is_master_data`, status ≠ `ANULADO`/`CANCELADO`. Agrega `pos_payments` por `pm_id` → `{pm_id,kind,name,transit_account,tpa_code,amount (r2 acumulado),count}`; `transferencias[]` (`sale_id, doc_number, amount, reference, pm_id`); `bruto=Σ total_gross`; `numerario=Σ amount dos meios NUMERARIO`; **`esperadoCaixa = opening_float + numerario`**.
**Relatório X (parcial)**: botão «Relatório X» (`posRelatorioX`) abre janela (`abrirImpressao`, `:481-489`, 78 mm, botão «Imprimir», mensagem «Permita janelas pop-up para imprimir o relatório.»). Corpo (`corpoRelatorioSessao :491-507`): empresa + NIF; «RELATÓRIO X (PARCIAL)» ou «RELATÓRIO Z · <z_number>»; tabela Terminal / Sessão / Operador / Abertura / Emitido|Fecho; linhas por meio «Nome (n)» e valor (ou «Sem vendas»); «TOTAL VENDAS (n doc.)»; «Fundo de maneio», «Numerário das vendas», **«Esperado em caixa»**, e no Z «Contado» e «Desvio»; bloco TPA «TPA <nome> · lote <ref>» com talão e «Diferença» («sem talão» se não indicado); «Justificação: …»; linhas de assinatura «Operador» e «Supervisor».
**Modal «Fecho de caixa · <código>»** (`showPOSCloseRegisterModal :516-587`, 760 px): cabeçalho com Terminal/Operador/Abertura/Fundo; «Vendas da sessão por meio de pagamento» (tabela com «N pag.» e total, «Total (N documentos)»); **«Contagem do numerário»**: 11 denominações `[5000,2000,1000,500,200,100,50,20,10,5,1]` (Kz) cada com input inteiro ≥0 (`#pos-den-<d>`); cartões «Esperado em caixa (fundo + numerário)», «Contado», «Desvio (tolerância X)»; se há TPA: «Fecho do TPA (talão de fecho do terminal Multicaixa)» com por TPA inputs «Valor do talão», «N.º op.», «N.º lote / fecho» e diferença; se há transferências: «Transferências recebidas (comprovativos)» lista `doc · ref · valor`; «Justificação» textarea «Obrigatória quando existe desvio de caixa ou diferença no TPA». Botões **Cancelar / Relatório X / «Fechar caixa e emitir Z»**.
Recalculo (`calcularFecho :589-606`, `posFechoRecalcular :608-625`): `contado = Σ denom*floor(qtd≥0)`; `desvio = r2(contado - esperado)`; texto `+x (sobra)`, `x (falta)` ou «(dentro da tolerância)» se `|desvio| ≤ tolerância+0.004` (verde #059669, senão vermelho). TPA: `difference = r2(talão - sistema)` (0 se talão vazio).
**Confirmar fecho** (`posConfirmarFecho :627-677`): se (desvio fora de tolerância OU diferença TPA ≥0.005) e sem justificação → «Existe desvio de caixa ou diferença no TPA. Escreva a justificação antes de fechar.». TPA com movimento sem talão: confirmação «Não indicou o valor do talão de fecho de: … Fechar mesmo assim?». Confirmação final «Fechar a caixa <código>? Contado: x · Desvio: y. Depois de fechada não é possível emitir mais vendas nesta sessão.». Recalcula com dados frescos da BD («A sessão já não está aberta.»), obtém `z_counters[ano]++`, e actualiza a sessão: `status 'FECHADA'`, `closed_at`, `closed_by`, **`z_number = Z-<TERMINAL>-<ANO>-<NNNN>`**, `sales_count`, `total_sales`, `totals_by_method[]`, `transfers[]`, `cash_sales`, `cash_expected`, `cash_counted`, `cash_counts` (denominação→qtd), `deviation`, `deviation_status = 'PENDENTE'` se fora de tolerância senão `'SEM_DESVIO'`, `tpa_closes[]` (`{pm_id,name,tpa_code,transit_account,system_amount,system_count,receipt_amount,receipt_count,batch_ref,difference}`), `justification`, `posting_status` e `settlement_status = 'PENDENTE'` se houve vendas, senão `'SEM_MOVIMENTO'`. Abre o Z para impressão e alerta «Caixa fechada (Z-…).» (+ «Desvio de x registado para deliberação.»). Reemissão: `reprintZReport(id)` (botão `fa-print` em «Fechos de caixa»), «Fecho de caixa não encontrado.».
Versão antiga do Z (`ui_sales.js:8871-9340`): relatório A4 paisagem roxo (#7c3aed) com tolerância zero e `deviation` por total de tesouraria — SUBSTITUÍDA.

### 2.A.12 Terminais POS (configuração) (`renderPOSTerminaisTab`, `pos_gestao.js:705-757`)
Cabeçalho «Terminais POS» / «Série documental, armazém, cliente por omissão e meios de pagamento com a conta transitória de cada meio.»; botões **«Definições de caixa»** e **«Novo terminal»**; avisos de sessões antigas e, se faltam `account_surplus/account_shortage`, «Defina as contas de sobras e faltas de caixa em Definições de caixa para permitir a deliberação dos desvios.». Tabela: Código | Terminal (nome + «<Tipo> · Série FR-POS/<código>/<ano>») | Meios de pagamento (chips; vermelho se sem conta transitória, «N meio(s) sem conta transitória») | Caixa («Aberta/Fechada/Inactivo» com sessão, operador, desde) | acções `fa-cog` Configurar e toggle Activar/Desactivar. Terminais inactivos a 55% de opacidade.
**Editor** (`posEditarTerminal :763-824`, modal 980 px): campos — **Código** (`pte-code`, maxlength 10; normalizado a maiúsculas `[A-Z0-9-]`; desactivado se já emitiu documentos), **Nome do terminal** (placeholder «Ex.: Caixa 1 — Loja»), **Tipo de negócio** (select), **Fundo de maneio habitual (Kz)**, **Armazém de saída de stock** (omissão «— armazém por omissão —»), **Cliente por omissão** («— Consumidor Final —», só terceiros CLIENTE/AMBOS), **Unidade de negócio**, **Centro de custo**. Código novo por omissão `T<nn>` (contagem+1). Bloco «Regras da hotelaria» (só tipo hotelaria): `hotel_checkin_time` 14:00, `hotel_checkout_time` 12:00, `hotel_late_tolerance_min` 60 (min 0 step 5), `hotel_hour_block` (checkbox «Bloquear no horário abaixo», omissão ligado), `hotel_hour_block_from` 21:00, `hotel_hour_block_to` 08:00, nota «Diária: a saída prevista é às 12:00 do dia seguinte a cada diária. Depois da tolerância o check-out propõe o recálculo e o operador confirma.». Secção «Meios de pagamento aceites» com botões «Copiar de outro terminal» e «+ Numerário / + Multicaixa (TPA) / + Transferência bancária».
 Cada meio: Designação, **Conta transitória \*** (select do plano; só contas de movimento `type 'M'` ou sem tipo), **Conta de liquidação** (filtra `45*` para Numerário; `43*` para os restantes), TPA: **N.º do TPA**, **Conta de comissões bancárias** (filtra `7*`), **Comissão (%)** (0-100), **Tratamento da comissão** («Deduzida no valor creditado» / «Não deduzida (cobrada à parte)»), checkbox Activo, botão remover (confirmação «Remover este meio de pagamento? (Os documentos já emitidos mantêm as suas contas.)»).
 **Meios por omissão** `meiosPadrao` (`:44-52`): `pm_num` Numerário (liquidação = 1.ª conta `45*` de `payment_methods`), `pm_tpa` Multicaixa (TPA) (liquidação = 1.ª `43*`, comissão 0%), `pm_trf` Transferência bancária — com transitória vazia (obrigatório preencher).
 **Copiar de outro terminal** (`:879-975`): modal com origem agrupada por empresa («Esta empresa — X» / «Outra empresa — Y»), Modo «Substituir os meios deste terminal» / «Acrescentar aos meios deste terminal», lista com checkboxes; contas inexistentes no plano da empresa a vermelho; confirma se faltam contas; Substituir reaproveita os `id` dos meios do mesmo tipo (para as sessões fechadas continuarem a achar contas); Acrescentar ignora meios cuja transitória já existe («Não foram acrescentados (a conta transitória já existe neste terminal): …»).
 **Validações ao gravar** (`posGravarTerminal :977-1037`): código e nome obrigatórios; código único por empresa («Já existe um terminal com o código X.»); ≥1 meio activo; designação de todos; contas existentes no plano da empresa («as contas … não existem no plano de contas desta empresa. Corrija-as antes de gravar.»); não totalizadoras (`type==='T'`); transitória obrigatória; liquidação obrigatória; Numerário→liquidação classe 45; outros→classe 43; transitória ≠ liquidação; TPA com comissão>0 exige conta de comissões; **transitórias não repetidas entre meios activos** («Cada meio deve ter a sua conta transitória para ser regularizado separadamente.»). Desactivar terminal: bloqueado com sessão aberta («O terminal tem a sessão X aberta. Feche a caixa antes de o desactivar.»), confirmação «Desactivar o terminal T01 — nome? O histórico de vendas e fechos mantém-se.» (não se apagam terminais).
**Definições de caixa** (`posDefinicoesCaixa :1051-1093` → tabela `pos_settings`, 1 registo por empresa): **Conta de sobras de caixa (proveito)** (contas `6*`), **Conta de faltas de caixa (custo)** (contas `7*`), **Conta a receber do operador (faltas imputadas)** (`3*`), **Tolerância de desvio (Kz)** (≥0, r2), **Código do diário da integração POS** (omissão `GEPOS`, 10 chars, maiúsculas). (PGCA Angola: classe 6 = proveitos/ganhos [61 vendas de mercadorias, 62 prestações de serviços], classe 7 = custos/perdas; por isso sobras→6x e faltas→7x estão correctos.)

### 2.A.13 Relatórios POS (`renderPOSRelatoriosTab`, `pos_gestao.js:1096-1136`)
KPIs (cartões com borda inferior colorida): **Facturação POS** (Σ total_gross das FR com `pos_session_id`), **Documentos**, **Ticket médio**, **Desvios de caixa** (Σ `deviation` das sessões fechadas, com sinal), **Sessões abertas**, **Desvios por deliberar**. Tabela «Por meio de pagamento» (agrega `pos_payments.name`; sem detalhe usa `payment_method`) e tabela «Fechos de caixa (relatórios Z)» (até 200, ordenada por fecho desc): Z, Terminal·operador, Fecho, Vendas, Esperado, Contado, Desvio, Estado («Por deliberar» laranja / «Deliberado» / «OK»), botão reimprimir.

### 2.A.14 Config. Impressão (`ui_sales.js:9932-10032`)
Quatro parâmetros, **por empresa, em localStorage** (`pos_print_format_<emp>` `thermal|a4`; `pos_print_consultation_behavior_<emp>` `preview|direct`; `pos_print_auto_checkout_<emp>` `yes|no`; `pos_print_footer_msg_<emp>`, omissão «Obrigado pela preferência!»). Rótulos: «Formato Padrão do Recibo / Fatura:» («Talão termico (78mm/80mm)» / «Documento A4 Tradicional»), «Comportamento da Consulta de Mesa:» («Mostrar Pré-visualização no Ecrã (Recomendado)» / «Imprimir Diretamente (Ignorar Pré-visualização)»), «Auto-Impresso no Fim do Checkout:» («Sim, abrir dialogo de impressão automaticamente» / «Não, apenas concluir no ecrã (impressão manual)»), «Mensagem de Agradecimento (Rodapé do Talão):». Botão «Guardar Configurações» → alerta «Configurações de impressão do POS guardadas com sucesso!» e `renderVendas('pos_config_print')` (separador inexistente em `renderVendas` — o resultado é o ecrã de Vendas, comportamento curioso).

### 2.A.15 Integração contabilística em massa (`pos_prestacao.js:55-350`)
Separador **«Integração contabilística»**: «Integração contabilística das sessões do POS» / «Débito das contas transitórias (numerário e TPA em bolo; transferências por documento) e crédito dos proveitos e do IVA. Um lançamento por sessão e por dia.». Duas tabelas: sessões FECHADAS com `posting_status='PENDENTE'` (checkbox por linha, colunas Z, Terminal·operador, Fecho, Doc., Total, Meios) e «Sessões contabilizadas» (últimas 60, com lançamentos, data/utilizador e botão `fa-undo` «Descontabilizar»). Botão **«Pré-visualizar e contabilizar»**.
**`linhasSessao` (`:97-191`) — algoritmo de geração dos lançamentos**, por dia da venda (`dia(v.date)`):
- Débitos: para cada pagamento de cada venda sem `transit_account` → impedimento «<doc>: pagamento "<nome>" sem conta transitória.»; documento sem `pos_payments` → impedimento «…documento sem detalhe de pagamentos (emitido antes da nova versão do POS).»; soma dos pagamentos ≠ total (>0.01) → impedimento «pagamentos (x) diferentes do total (y).». Pagamentos NUMERARIO e TPA agregam-se por `transit_account|pm_id` (um débito «bolo» por meio: descrição `POS <Z> · <meio> (<n> pag.)`, `doc_number = z_number`, `reference = session_code`); TRANSFERÊNCIA debita por documento (`POS <Z> · Transferência <ref> · <doc>`, com `third_party_id`, BU e CC).
- Créditos: sem linhas ou bruto 0 → proveito na conta **62** (`total_net||total_gross`) + IVA (`total_tax`) na conta de IVA por omissão e aviso «sem linhas; proveito lançado na conta 62.» (não bloqueia). Com linhas: `factor = total_gross(doc) / Σ item.total`; por item `brutoLinha = (item.total||qtd*unit_price) * factor`; `base = brutoLinha/(1+tax_rate/100)`; crédito do proveito na conta `resolveMatérialAccount(produto, base)` (por omissão **61** para inventariáveis, **62** para serviços) agrupado por conta|tipo|BU|CC; IVA = `brutoLinha-base` (se >0.0001) na conta `produto.account_iva_liquidado || produto.account_iva`; se o produto não tem, usa `contaIvaLiquidadoPorOmissao` (configuração `sales_accounting_config.key='iva_vendas'` se válida e de movimento; senão a conta de IVA mais usada nos serviços, depois nos produtos) com aviso «Sem conta de IVA liquidado em: … Usada a conta X.» e botão «Gravar X nesses serviços» (`posGravarIvaEmFalta`, grava `account_iva_liquidado` e `account_iva` nos produtos); sem conta possível → impedimento.
- Linhas: descrições «POS <Z> · Vendas (n doc.)» / «IVA liquidado». Equilíbrio: se `|ΣD-ΣC| ≥ 0.01`: se `≤1` ajusta o maior crédito, senão impedimento «Lançamento de <data> desequilibrado em x.». Descarta linhas com valor ≤0. Extensões: `window.posIntegracaoExtras[]` (lavandaria) acrescentam lançamentos/avisos.
**Pré-visualização** (`posPreVisualizarIntegracao :262-309`): modal 980 px «Pré-visualização da integração» por sessão com avisos (vermelho = impedimento, laranja = aviso), lançamentos por data/diário/n.º de documentos, totais; contas inexistentes («Conta X não existe no plano de contas.») e exercício encerrado («Exercício de AAAA encerrado.») são impedimentos; rodapé «Débito x · Crédito y · N impedimento(s)»; botão «Contabilizar N sessão(ões)» desactivado com impedimentos.
**Contabilizar** (`contabilizarSessao :194-223`, `posConfirmarIntegracao :311-331`): diário = `journals` com `code = pos_settings.journal_code||'GEPOS'` (criado se não existe, descrição «Vendas POS»); por lançamento `generateGlobalLanNumber(empresa, diário, código, data)` e uma `journal_lines` por linha com `source_type 'POS'`, `pos_session_id`, `doc_date=entry_date=data`, `third_party_id/business_unit_id/cost_center_id`; marca as vendas `is_posted:true, pos_posting_lans`; sessão `posting_status 'CONTABILIZADA'`, `posting_lans`, `posting_journal_id`, `posted_at/by`. Falha de qualquer impedimento/conta/exercício encerra com erro `<Z>: …` (acumulado em «Erros:»); `isYearClosed` consultado por data.
**Descontabilizar** (`posDescontabilizarSessao :333-350`): bloqueado se há liquidações não anuladas («A sessão já tem movimentos de prestação de contas. Anule-os antes de descontabilizar.»), se há deliberação com lançamento («Anule a deliberação antes…»), exercício encerrado; apaga as linhas `journal_lines` (`source_type 'POS'` e `lan_number ∈ posting_lans`), repõe `is_posted=false` nas vendas, sessão volta a `PENDENTE` (`unposted_at/by`).

### 2.A.16 Desvios de caixa — deliberação (`pos_prestacao.js:352-489`)
Separador «Desvios de caixa»: «Desvios de caixa por deliberar» com explicação das 3 contas; tabela (Z, Terminal·operador, Fecho, Esperado, Contado, Desvio, Justificação do operador, Contabilização «Contabilizada/Por contabilizar», botão **«Deliberar»**) e «Deliberações» (Z, Operador, Desvio, Decisão, Fundamentação, Lançamento, Deliberado por, botão desfazer).
Modal «Deliberar desvio · Z…»: cartões Esperado/Contado/«Sobra|Falta»; opções — **sobra**: `SOBRA_PROVEITO` «Aceitar a sobra como proveito (<conta>)», `SEM_EFEITO` «Sem efeito — erro de contagem confirmado (sem lançamento)»; **falta**: `FALTA_CUSTO` «Assumir a falta como custo da empresa», `FALTA_OPERADOR` «Imputar a falta ao operador <nome>», `SEM_EFEITO`; **Data do lançamento** (omissão hoje) e **«Fundamentação da deliberação \*»** (mínimo 5 caracteres, «Escreva a fundamentação da deliberação.»). Se a sessão não está contabilizada só `SEM_EFEITO` é possível («Contabilize a sessão antes de lançar a sobra ou a falta.»).
Lançamento (diário GEPOS, `source_type 'POS_DESVIO'`, doc_number = Z, referência = session_code, BU/CC do terminal, descrição «Sobra|Falta de caixa <Z> · <operador>» (+ «(imputada ao operador)»), valor `|desvio|`): **sobra** → D transitória do numerário, C `account_surplus`; **falta (custo ou operador)** → D `account_shortage` ou `account_operator`, C transitória do numerário. Transitória do numerário = a do meio NUMERARIO em `totals_by_method`, senão a do terminal. Validações: exercício aberto, conta transitória encontrada, conta definida em Definições de caixa, contas existentes no plano. Guarda `deliberation {decision,note,amount,date,by,at,account,transit_account,lan_number,journal_id}` e `deviation_status 'DELIBERADO'`; recalcula `settlement_status`.
**Anular deliberação**: bloqueada se o numerário já passou à Folha de Caixa («O numerário desta sessão já foi transferido para a Folha de Caixa. Anule essa prestação de contas primeiro.»); elimina as linhas do lançamento (exercício aberto); volta a `PENDENTE` guardando `deliberation_cancelled`.

### 2.A.17 Prestação de contas (regularização das transitórias) (`pos_prestacao.js:491-797`)
Separador «Prestação de contas»: texto «Regulariza as contas transitórias: o numerário entra na Folha de Caixa, o fecho do TPA e cada transferência geram um recebimento pendente na Tesouraria (integrado depois pelo processo normal).» + estado da Folha de Caixa (conta, operador, desde) ou aviso «Não há Folha de Caixa aberta: abra uma sessão na Folha de Caixa para receber o numerário.».
Itens a liquidar por sessão (`itensLiquidar :494-518`; sessões fechadas com `settlement_status ≠ SEM_MOVIMENTO`):
- **NUMERARIO** (`NUM:<pm_id>`): valor = `amount` do meio + `deviation` se houve deliberação ≠ `SEM_EFEITO` (aplicado uma só vez ao 1.º meio numerário, `r2(valor+deviation)`); bloqueado «Delibere primeiro o desvio de caixa.» se desvio PENDENTE.
- **TPA** (`TPA:<pm_id>`): `amount` do meio; mostra talão, lote, n.º do TPA.
- **TRANSFERENCIA** (`TRF:<sale_id>:<pm_id>`): um item por documento com comprovativo.
Só se mostram itens com valor >0.004. Sessão só liquida depois de contabilizada («Contabilize primeiro a sessão.»; UI «por contabilizar»). `settlement_status` → `LIQUIDADA` (todos feitos) / `PARCIAL` / `PENDENTE` (`actualizarEstadoLiquidacao :520-527`).
**Numerário → Folha de Caixa** (`liquidarNumerario :684-710`): exige `cash_sessions` ABERTA da empresa («Não há Folha de Caixa aberta. Abra uma sessão na Folha de Caixa para receber o numerário do POS.»); se a conta da folha ≠ conta de liquidação do meio pede confirmação; confirma «Transferir x de numerário da sessão Z para a Folha de Caixa conta?»; calcula campos de câmbio `caixaFxCamposLinha(folha, data, valor)` (erro → alerta); cria `pos_settlements` (kind NUMERARIO, target `FOLHA_CAIXA`) e **`cash_lines`** tipo `REC`: `account_debit = conta da folha`, `account_credit = conta transitória`, `description «Prestação de contas POS <Z> · numerário (<operador>)»`, `doc_number=Z`, `reference=session_code`, `bu_id/cc_id` do terminal, `source_type 'pos'`, `source_id = liqId`, `is_posted false`.
**TPA → banco** (modal «Liquidação do TPA · Z»): campos Data-valor, Conta bancária (contas 43*), Valor bruto regularizado (só leitura = valor contabilizado), **Comissão bancária (%)** (omissão `r2(bruto*pct/100)` com bruto = talão se existir senão sistema; editável), Tratamento da comissão, texto «Crédito no banco: …». Avisos se talão ≠ sistema (≥0.01). Validações: banco obrigatório; «A comissão não pode ser igual ou superior ao valor liquidado.»; conta de comissões obrigatória se comissão>0. **`criarDocumentoTesouraria`** (`:541-556`): `treasury_documents` tipo `RECEBIMENTO`, `account_fin = banco`, `total_value = bruto − comissão` (se deduzida e comissão>0.004) senão `bruto`, `status 'PENDENTE'`, `reference = POS-TPA-<Z>-<pm_id>` (≤60 chars); `treasury_items`: C transitória (bruto) «— regularização da conta transitória»; comissão deduzida → D conta de comissões (descrição «— comissão bancária»); comissão não deduzida → 2.º documento `PAGAMENTO` (ref `<ref>-COM`) com item D comissão. Regista `pos_settlements` (kind TPA, `commission`, `commission_deducted`, `amount_net`, `batch_ref`, `treasury_doc_id`, `commission_doc_id`).
**Transferência → banco** (modal «Registar transferência no banco»; Data-valor = data da venda, Conta bancária): um `treasury_documents` RECEBIMENTO por comprovativo, ref `POS-TRF-<doc>-<ref>` (≤60), descrição «Transferência POS <doc> · comprovativo <ref>»; botão «Registar as N transferências» em lote (`posLiquidarTodasTransferencias`, usa data da venda e banco configurado do meio).
**Anular liquidação** (`posAnularLiquidacao :771-797`): Folha de Caixa → bloqueado se a `cash_line` já `is_posted` («A linha da Folha de Caixa já foi contabilizada. Não é possível anular.»), senão apaga a linha; Tesouraria → bloqueado se algum documento ≠ `PENDENTE` («O recebimento ou o pagamento da comissão já foi integrado na Tesouraria. Não é possível anular.»), senão apaga itens e documentos; marca `status 'ANULADO'`. Histórico «Movimentos de prestação de contas» (80): Data, Sessão, Tipo, Destino, Bruto, Comissão, Líquido, Registado por.

### 2.A.18 Ciclo de vida de estados (resumo)
Sessão: `ABERTA` → `FECHADA` (Z). `posting_status`: `PENDENTE` → `CONTABILIZADA` (↔ descontabilizar) | `SEM_MOVIMENTO`. `deviation_status`: `N/A` (aberta) → `SEM_DESVIO` | `PENDENTE` → `DELIBERADO` (↔ anular). `settlement_status`: `PENDENTE` → `PARCIAL` → `LIQUIDADA` | `SEM_MOVIMENTO`. Liquidação: `REGISTADO` → `ANULADO`. Venda: `PAGO` (+ `ANULADO`/`CANCELADO` excluídos dos totais). Não existe, no POS, função de anular/estornar/devolver venda individual; anulações fazem-se em Vendas (NC/anulação do documento).


---

## 2.B POS DE ARMAZÉM (Logística) — `js/ui_pos_armazem.js` (1257 linhas)

Sub-módulo independente do POS comercial: **atendimento de balcão de armazém com emissão de Guia de Saída, fila de picking de encomendas e monitor de chamada de senhas**. Não emite factura nem recibo, não usa sessões de caixa nem terminais. Título da vista: «POS de Armazém (Logística)» (ícone fa-cash-register, `:9`). Entrada: `window.renderPOSArmazém(tab='balcação')` (`:3`); tabs: **«Atendimento ao Balcão»** (`balcação`), **«Fila de Picking»** (`picking`), **«Monitor de Chamada de Senhas»** (`senhas`) (`:12-14`). Os identificadores de tab contêm acentos `balcação` (legado; a rota/manifesto deve ser consultada em §1.3).

### 2.B.1 Atendimento ao Balcão (`renderPOSBalcaoTab :37-146`)
Sem armazéns da empresa: cartão «Nenhum Armazém Configurado» / «Por favor, configure pelo menos um Armazém antes de aceder ao POS.» + botão «Configurar Armazens» → `renderArmazém('armazens')` (`:46-56`).
Layout em 2 colunas (`1.8fr 1.2fr`):
- **Catálogo**: seletor de armazém `#pos-wh-select` (omissão `_posActiveWhId` = 1.º armazém; mudar → `handlePOSWhChange`), filtro **Todos / Com Stock / Sem Stock** (`pos_wh_stock_filter`), pesquisa «Pesquisar...» (`onkeyup` → filtra cartões `.pos-product-card` por `data-name`/`data-code`, `display:block|none`), alternador grelha/lista (`pos_wh_layout_mode`), zoom `GrelhaZoom` chave `pos_armazem` (200/120/320). Aviso `#pos-wh-aviso-stock` quando há produtos com stock global ≠ soma dos armazéns, com botão **«Acertar stock deste armazém»**. Lista só produtos `is_inventory` (true/1/'1') e não `is_master_data`; stock mostrado = `warehouse_stock.stock_qty` do armazém seleccionado. Etiquetas: `>10` verde «disponivel» [sic], `>0` laranja «Baixo», `≤0` vermelha «Esgotado»; texto «STOCK DISP.: N U»; botão **«Add»** (desactivado sem stock). Vista lista mostra coluna Código monoespaçada.
- **Carrinho** («Carrinho de Picking»): select **Cliente** (Select2; opção «-- Cliente de Balcão (Geral) --»; só terceiros com `ContaTerceiro.ehCliente`), lista de itens (nome, «preço / U», input quantidade `min 1 max=stock`, subtotal, lixo), **«TOTAL DO PICKING:»**, botão **«Confirmar Saída e Expedir»**. Carrinho vazio: «Carrinho de Picking vazio.».
- Regras: `addToWhPOSCart` +1 até ao `maxQty` do armazém («Apenas N unidades disponíveis em stock no Armazém.»); `updatePOSWhCartQty` aceita decimais (`parseFloat`), `>max` → «Excede stock disponível (N U).» e corrige para o máximo, `≤0` remove. Preço = `product.unit_price` (**não editável**; não há desconto, nem IVA no carrinho). Carrinho global `_posWhCart` (só memória; **não persiste**).
- **Acerto de stock** (`posWhAcertarStock :155-215`): modal «Acertar stock — <armazém>»; tabela Produto | Stock global | Soma dos armazéns | Neste armazém | Ajuste | Fica neste armazém; `ajuste = max(-local, diferença)` (diferença = global − Σarmazéns, arredondada a 3 casas; «limitado ao stock deste armazém»); botão «Aplicar acerto» (confirma «Aplicar o acerto de stock a N produto(s) neste armazém?») aplica `updateWarehouseStock(armazém, produto, ajuste)` **sem criar movimentos** nem alterar stock global, regista `logMovement('armazem','ACERTO_STOCK_ARMAZEM',…)` e alerta «Stock acertado em N produto(s).». Mensagens: «Não há diferenças entre o stock global e os armazéns.», «Seleccione pelo menos um produto com ajuste.».

### 2.B.2 «Confirmar Saída e Expedir» (`finalizePOSWhSale :464-581`) — efeitos
Carrinho vazio → «O carrinho de picking esta vazio.». Confirmação «Deseja confirmar a saída física de stock destas mercadorias e emitir a Guia de Saída para o Armazém selecionado?». Spinner «Emitindo Guia de Saída...».
1. Número: `GE POS <ano>/<n>` com `n = count(delivery_notes da empresa)+1` (**contador único partilhado com todas as guias; pode colidir entre posts concorrentes**).
2. `delivery_notes.add({company_id, doc_number, date: hoje, type:'VENDA', entity_id: cliente|null, warehouse_id, status:'CONCLUIDO'})`; por item `delivery_items.add({delivery_id, product_id, quantity})`.
3. Stock: `products.stock_qty = max(0, global − qtd)`; `updateWarehouseStock(whId, produto, −qtd)` (cria linha `warehouse_stock` se não existe: `ui_warehouse.js:1013-1027`); `inventory_movements.add({type:'SAÍDA', quantity, unit_price = custo médio (CMP) || cost_price || unit_price, date: ISO completo, third_party_id, reference: 'Saída POS: <doc> (<cliente>)'})`. **Não grava `date` como AAAA-MM-DD** (usa `toISOString`), ao contrário do POS comercial (quirk).
4. **Contabilização automática do custo (CMV) na hora**: diário = `journals` com código `LO`, senão `GS`, senão o 1.º da empresa. Por item com custo>0: `custo = qtd × (CMP || prod.unit_price || 0)`; **D** `resolveMatérialAccount(prod,'71')` («Saída POS (Custo Matéria): <prod> - <cliente>») e **C** `prod.account_inventory || resolveMatérialAccount(prod,'26')` («Saída POS Stock: …»), `value = custo.toFixed(2)`, `reference = doc_number = docNum`, `third_party_id`, `doc_date=entry_date=hoje`. (`resolveMatérialAccount`, `app_v2.js:11146-11177`: se o produto tem `account_code` troca o prefixo da classe; sem conta e não-inventariável devolve '62' para 61/71/21/26/31; senão devolve a própria classe.) Não grava `lan_number`/`source_type` nas linhas.
 **CMP** `calculateAverageCosts` (`ui_warehouse.js:1-…`): por produto — média ponderada dos itens de **encomenda de compra** (`purchase_items` parent_type ORDER) com `unit_price>0`; senão de itens de proposta QUOTE; senão `cost_price||unit_price` do produto; último fallback: média ponderada de `inventory_movements.unit_price` e depois média dos preços de venda (`sale_items`).
5. Fala «Atendimento de <cliente> concluido com sucesso. Guia <doc> gerada.» (`speechSynthesis`, `lang pt-PT`, `rate 1`, `speakSentence :794-801`), alerta «Saída concluida com sucesso! Guia <doc> emitida.», imprime a **Guia de Saída A4** e o **talão de picking**, esvazia o carrinho e recarrega.
 Erro: «Erro ao processar a saída de Armazém: …».
**Impressos**: (a) *Guia de Saída* (`printGuiaSaidaPOS :628-792`, A4, fonte Inter): cabeçalho empresa (nome em maiúsculas, NIF, «Sede Social: Luanda, Angola», telefone/email **fixos de exemplo** `+244 923 000 000` / `logística@<empresa>.co.ao`), badge «GUIA DE SAÍDA», Nº Documento, Data de Emissão, Hora, Armazém de Origem; caixas «Destinatario» (nome, NIF, «Endereao: Luanda, Angola» fixo) e «Dados de Expedição» («Transporte Logastico / Balcão», «Modalidade: Levantamento Imediato no POS», «Estado da Carga: Pronta a Despachar»); tabela # | Código | Descrição do Artigo | Quantidade | Preço Unit. | Total Líquido; totais «Subtotal (Líquido)», «Impostos / IVA (Isento)» = 0,00 Kz fixo, «TOTAL GERAL»; assinaturas «O Operador do Armazém» e «O Cliente / Recebedor»; rodapé «Este documento não serve de fatura. Serve exclusivamente como guia de transporte físico e saída física de mercadorias das nossas instalações.» e «Processado por programa validado n? 0000/AGT/2026 ERP_CONSULT v2.2» (placeholder). Botão «Imprimir Documento A4». (b) *Talão* (`printPOSWhReceipt :583-626`, 320 px): «ERP_CONSULT» [fixo], «Talão DE LEVANTAMENTO / PICKING», Documento, Data, Destinatario, «Operação: Logística / Balcão», tabela Artigo (18 chars) | Qtd | Total, «TOTAL GERAL», «Obrigado pelo seu atendimento no nosso Balcão de Armazém!». Nenhum dos dois leva QR/ATCUD/hash (não é documento fiscal).

### 2.B.3 Fila de Picking (`renderPOSPickingTab :807-871`, `startOrderPicking :873-945`, `finalizeOrderPicking :1006-1123`)
Lista «Fila de Atendimento de Encomendas» / «Lista de pedidos de clientes aguardando recolha física nas prateleiras do Armazém.»: documentos `sales` com `doc_type==='Encomenda'` e status ∈ {`Pendente`,`PENDENTE`,`EM PICKING`,`Em Picking`}; colunas Data Pedido | Nº Encomenda | Cliente | Valor Total (`s.total_value` — **campo que `sales` não tem (usa `total_gross`) ⇒ apresenta «NaN/0» (bug aparente)**) | Estado (badge `EM PICKING` amarelo / outro azul) | botão «Iniciar Picking». Vazia: «Nenhuma encomenda na fila de picking hoje.».
«Iniciar Picking»: **marca a encomenda `status='EM PICKING'` imediatamente**; abre overlay «Checklist de Recolha: <doc>» («PICKING EM CURSO»), seletor «Armazém de Saída Física» (recalcula), tabela Produto | Stock Local | Qtd Pedida | Verificado (checkbox; **desabilitada com «Stock insuficiente no Armazém selecionado!» se stock<qtd**; cor verde/vermelho). Botões «Pausar» (fecha, mantém EM PICKING) e **«Finalizar Picking & Expedir»** (só activo com TODAS as caixas marcadas, `checkPickingCompletion`). Confirmação «Confirmar que recolheu todos os artigos e deseja dar saída física de stock?».
Efeitos de `finalizeOrderPicking`: `delivery_notes` número `GE PICK <ano>/<n>` (mesmo contador), `type:'BACK_TO_BACK'`, `related_sale_id`, `status 'CONCLUIDO'`; por linha da encomenda: `delivery_items`, `products.stock_qty − qtd`, `updateWarehouseStock`, `inventory_movements` SAÍDA (`reference 'Picking Encomenda: <guia> (Enc. <doc>)'`), lançamento de custo D `resolveMatérialAccount(prod,'71')` / C `account_inventory||…'26'` (descrições «Custo Artigo (Picking): …» / «Saída Stock (Picking): …»); encomenda → `status 'CONCLUIDO'`; fala «Picking concluido e expedido para <cliente>.»; alerta «Picking e expedição validados com sucesso! Guia <n> emitida.». **Não imprime guia** neste fluxo e não gera factura (a facturação da encomenda fica em Vendas). Sem controlo de lotes/validades nem localizações (prateleiras) — «prateleira» é só texto.

### 2.B.4 Monitor de Chamada de Senhas (`renderPOSSenhasTab :1133-1253`)
Estado só em memória (`_posSenhasActive`, `_posSenhasCalled`, `_posSenhaCounter` inicial 1; **perde-se ao recarregar; não é por empresa nem partilhado entre postos**). Painel de operação «Painel de Operação de Senhas»: **«Tirar Nova Senha»** (`posGenerateTicket`: senha `P-001`, `P-002`… `padStart(3)`; sem reinício diário) e **«Chamar próxima»** (`posCallNextTicket`: FIFO; vazio → «Sem senhas pendentes na fila.»; move para histórico, máx. 8 (`pop` ao exceder); mostra no ecrã grande `#big-ticket-display` («SENHA ATUAL EM CHAMADA», 5.5rem azul #3b82f6 que pisca a verde #10b981 durante 1200 ms, animação `bigTicketPulse` 1 s); **fala** «Senha número P 001. Por favor, dirigir-se ao Balcão de Logística.» (`ticket.replace('-',' ')`). Lista «Senhas Aguardando na Fila:» (chips azuis; «Fila vazia...») e «Últimas Senhas Chamadas:» (grelha de 4 colunas; exclui a actual; «Sem registos.»). Não há impressão de senha, nem ligação a guias/vendas/clientes.

### 2.B.5 Permissões/rotas
Ver §6 (as chaves `pos_armazem_*` e a rota/menu constam de `permissoes.js`/`modulos_manifesto.js`).


---

## 2.C POS HOTELARIA — `js/hotelaria.js` (706 linhas) + parte de `ui_sales.js`

Terminal de tipo `hotelaria` (§2.A.2). Carregado depois de `ui_sales.js` e `pos_gestao.js` (`hotelaria.js:10`); envolve (decora) `renderPOSTab`, `setPOSTable`, `renderPOSCart`, `addPOSProduct`, `showPOSCheckout` (`:146-204`) e redefine `renderPOSHotelStayPanel`, `showPOSHotelCheckout`, `finalizePOSHotelPayment`. As rotinas homónimas antigas de `ui_sales.js` (`showPOSHotelCheckout :7685`, `finalizePOSHotelPayment :7893`, `renderPOSHotelStayPanel :9782`, modais de criar/editar quarto `:9412-9582`, estadias em `localStorage pos_room_stays`) estão **substituídas** (migração `migrarDadosAntigos :135-143`: marca como `is_master_data=1,is_blocked=true` os produtos falsos de id `STAY_…`, remove `pos_room_stays`, flag `hotel_migracao_v1`).

### 2.C.1 Modelo: quartos = produtos
Quarto = `products` com `is_room=true` (checkbox «é Quarto / Alojamento?» na ficha do produto, `ui_sales.js:2390-2416`, bloco «Configurações de Hospedagem / Quarto» com **Preço por Hora (Kz)** omissão 4000, **Preço por diária (Kz)** omissão 28000, **Tempo Mínimo (Horas)** omissão 2; gravados em `pricePerHour`, `pricePerDay`, `minHours` — `ui_sales.js:2631-2634`). Mapa de quartos (`ui_sales.js:6510-6520`): produtos `is_room && !is_master_data && !is_blocked`; id de mapa `Q<id do produto>`; `pricePerHour||0`, `pricePerDay||unit_price||0`, `minHours||1`. «Não são criados quartos de exemplo». O alojamento é facturado **com o produto real do quarto** (conta de proveitos e IVA do produto) e descrição da estadia (`:8-9`). Sem quartos: mensagem «Não há quartos. Crie-os em Vendas → Produtos, marcando "É quarto" e indicando os preços por hora e por diária e a conta de proveitos.» (`:251`). Os quartos não se vendem pela grelha de produtos: removidos de `_posAllProducts` (`:210-213`) e `addPOSProduct` avisa «O alojamento lança-se pelo check-in no mapa de quartos.»; sem check-in: «Faça o check-in do quarto antes de lançar consumos.» (`:186-190`).

### 2.C.2 Regras do terminal (`regras :37-44`; editor em §2.A.12)
`hotel_checkin_time` ('14:00'), `hotel_checkout_time` ('12:00'), `hotel_late_tolerance_min` (60), `hotel_hour_block` (omissão true), `hotel_hour_block_from` ('21:00'), `hotel_hour_block_to` ('08:00').
- **`horaBloqueada(data, rg)`** (`:47-54`): sem bloqueio → false; `de===ate` → false; se `de>ate` (atravessa meia-noite) bloqueia quando `min(hora) ≥ de || < ate`; senão `≥ de && < ate`. Minutos = h*60+m.
- **`saidaPrevista(entrada, modo, qtd, rg)`** (`:56-63`): **HORA** → `entrada + qtd·3600000 ms`. **DIA** → dia civil da entrada + `max(1, round(qtd))` dias, à hora `hotel_checkout_time` (segundos/ms a 0). (Ex.: entrada 14:00 dia 5, 1 diária → saída 12:00 dia 6.)
- **`propostaTardia(estadia, rg, agora)`** (`:65-75`): se `agora ≤ prevista + tolerância(min)` → sem proposta. HORA: `horas = ceil((agora − checkin)/3600000)`, `nova = max(qtd contratada, horas)`; proposta só se `nova > qtd` → `{quantidade: nova, extra: nova − qtd}`. DIA: `extra = ceil((agora − prevista)/86400000)`; se >0 → `{quantidade: qtd + extra, extra}`. (Nota: a tolerância só serve para decidir se há proposta; uma vez há, o extra conta desde a hora prevista, não desde o fim da tolerância.)

### 2.C.3 Tabela `hotel_stays`
Campos (`hotelConfirmarCheckin :368-376`): `hotel_company_id`, `terminal_id`, `terminal_code`, `session_id`, `room_product_id`, `room_name`, `status` (`ABERTA`→`FECHADA`|`ANULADA`), `guest_customer_id`, `guest_name`, `guests_count`, `mode` (`DIA`|`HORA`), `checkin_at` (ISO), `planned_checkout_at` (ISO), `quantity` (diárias/horas), `unit_price` (IVA incl.), `tax_rate` (do produto), `notes`, `items[]` (consumos: `{product_id,name,price,quantity,tax_rate}`), `history[]` (`{at,by,text}`), `created_at/by`; ao fechar: `checkout_at`, `final_quantity`, `late_choice` (`RECALCULAR|MANTER|null`), `discount_pct`, `sale_id`, `sale_number`, `closed_session_id`, `closed_at/by`; ao anular `cancel_reason`. Índice composto `[hotel_company_id+status]` (consultas `:80,361`). Persistência na BD (Dexie `db.hotel_stays`) — vários postos vêem o mesmo estado; cada gravação confirma que a estadia continua `ABERTA`.

### 2.C.4 Mapa de quartos e painel de estadia
Cartão do quarto (decorado, `decorarEcra :206-253`): **livre** → «Livre» verde (#059669); **ocupado** → nome do hóspede, «saída <dd/mm hh:mm>», «· atrasado» (se `agora > prevista + tolerância`), valor `qtd×unit_price + consumos`; cores laranja (#f59e0b/#b45309) ou vermelho (#ef4444/#b91c1c, atrasado). Barra no cabeçalho do painel: «N livre(s)», «N ocupado(s)», «N com saída atrasada» e botão **«Balcão (venda directa)»** (carrinho `Balcao` = venda normal sem quarto). Clicar quarto → `setPOSTable`: grava consumos pendentes, troca, e se a estadia tem hóspede, **selecciona esse cliente** como cliente do POS.
**Painel de check-in** (quarto livre, `painelCheckin :270-296`): título «Check-in — <quarto>» «Livre»; campos: **Hóspede (cliente)** (select de clientes CLIENTE/AMBOS por nome; omissão o cliente actual do POS), **Modalidade** («Diária»; «À hora» desactivada se `pricePerHour≤0`, rótulo «(bloqueada agora)» se `horaBloqueada(agora)`), **N.º de hóspedes** (int ≥1, omissão 1), **Entrada** (`datetime-local`, omissão agora), **N.º de diárias** / «N.º de horas (mín. N)» (omissão 1; HORA: `min=minHours`, `step=0.5`, valor inicial = minHours), **Preço por diária (IVA incl.)** / «Preço por hora (IVA incl.)» (omissão = `pricePerDay` / `pricePerHour`; **o operador pode alterar o preço**), **Observações** («Opcional»); faixa de previsão `#hc-previsao`: «Saída prevista: <data/hora> · Alojamento: N diária(s)|N hora(s) × <preço> = <total>» + avisos; botão **«Confirmar check-in»** (desactivado enquanto houver impedimento).
 Avisos/validações (`hotelPrever :322-342`, `hotelConfirmarCheckin :344-386`): entrada inválida «Indique a data e hora de entrada.»; HORA em bloqueio «Venda à hora bloqueada entre as 21:00 e as 08:00. Use a diária.»; HORA abaixo do mínimo «Mínimo de N hora(s).»; qtd≤0 «Indique a quantidade.»; **aviso (não bloqueia)** «Entrada antes das 14:00 (check-in antecipado).» para DIA; hóspede obrigatório «Seleccione o hóspede.»; preço 0 pede confirmação «O alojamento fica com preço 0. Continuar?»; exige caixa aberta (`sessaoValida`); **quarto ocupado** «O <quarto> já tem check-in de <hóspede> (registado às <data>).» (verificação na BD, evita duplo check-in entre postos); «O produto do quarto não existe.». Efeito: cria `hotel_stays` ABERTA com `history` «Check-in: N diária(s) × preço, saída prevista …».
**Painel de estadia ocupada** (`painelOcupado :388-411`): «<quarto>» + «OCUPADO»/«SAÍDA ATRASADA»; linhas Hóspede (N pax), Entrada, Saída prevista, Alojamento («N diária(s) × preço = total»), Consumos, **«Total em aberto»**; Obs.; botões **Alterar**, **Anular**, **Check-out**.
**Consumos**: o carrinho do quarto é a lista de consumos; cada alteração (`renderPOSCart`) agenda, com *debounce* de 250 ms, `gravarConsumos` (grava `items` na BD; se a estadia já não está aberta alerta «A estadia do <quarto> já não está aberta (possivelmente fechada noutro computador). Os consumos não foram gravados.»). `carregarEstadias` recarrega do servidor a cada render: quartos sem estadia aberta ficam sem consumos. Consumos não abatem stock até ao check-out.
**Alterar estadia** (`hotelAlterarEstadia/hotelGravarAlteracao :425-476`): modal com Hóspede, Entrada, N.º diárias/horas, Preço, N.º hóspedes, Observações; recalcula a saída prevista; regista `history` «Estadia alterada: hóspede A → B; entrada x → y; N diária(s) → M; preço p → q.».
**Anular check-in** (`hotelAnularEstadia :478-494`): proibido com consumos («A estadia tem consumos lançados. Remova-os do carrinho antes de anular, ou faça o check-out.»); pede motivo (`Dialogo.pedir`, mínimo 3 caracteres; senão aborta); `status 'ANULADA'`, `cancel_reason`, `closed_at/by`, `history` «Check-in anulado — motivo.».

### 2.C.5 Check-out e facturação (`showPOSHotelCheckout :504-563`, `hotelCkAlterar :565-592`, `finalizePOSHotelPayment :594-703`)
Pré-requisitos: caixa aberta («Abra a caixa antes de facturar.»), meios do terminal («O terminal não tem meios de pagamento configurados (Terminais POS).»), há estadias («Não há quartos com check-in para facturar.»). Botão «Check-out» de um quarto abre o modal com **só esse quarto seleccionado**; o botão «Checkout» do carrinho (com o quarto activo) idem; todas as estadias abertas listam-se (ordenadas por nome, numérica) com checkbox para **facturar vários quartos de uma vez**.
Modal «Check-out e facturação» (720 px): por quarto: checkbox, nome, total, «hóspede · entrada → prevista saída», «Alojamento: N × preço = total · Consumos: x (n linha(s))»; se **saída tardia**: caixa vermelha «Saída tardia (tolerância 60 min). Proposta: +N hora(s)/diária(s), total … = …» com radios **«Cobrar o recálculo»** / **«Manter o contratado (N)»** (obrigatório decidir: «Confirme a saída tardia (cobrar o recálculo ou manter o contratado) de: …»). Campos globais: **Desconto (%)** (0-100, step 0.5, omissão 0), **Facturação**: «Uma factura por quarto (cliente de cada estadia)» | «Factura única» (+ **Cliente da factura única**); total grande e detalhe «N quarto(s) · bruto x · desconto p% (−y)»; pagamentos mistos com o mesmo componente do POS (`htmlPagamentos('hotel', …)`, `htmlResumoPagamento`, preenchendo tudo no 1.º meio ao alterar). Botão «Confirmar check-out e emitir».
**Cálculos** (`calcLinha :497-502`): `qtd = proposta && escolha==='RECALCULAR' ? proposta.quantidade : estadia.quantity`; `alojamento = r2(qtd × unit_price)`; `consumos = r2(Σ price × quantity)`; `bruto = r2(alojamento + consumos)`. Total do modal: `bruto_total = Σ bruto` das seleccionadas; `total = r2(bruto − bruto × desc/100)`.
**Emissão**: valida pagamentos (`calcularPagamentos('hotel')`, mesmas regras e mensagens que §2.A.7), cliente da factura única, e que cada estadia continua ABERTA («A estadia do <quarto> já foi fechada ou anulada (possivelmente noutro computador).»). Por estadia monta as linhas: 1.ª = **alojamento** (produto do quarto, `quantity=qtd`, `price=unit_price` da estadia, descrição «Alojamento <quarto> · N diária(s)|hora(s) · <entrada> a <agora>») + uma linha por consumo (preço guardado; produto reposto da BD; se o produto entretanto desapareceu usa os dados da linha, `is_inventory 0`). Totais por factura (`totaisDe`): `bruto = Σ preço×qtd`; `liquido = Σ (preço×qtd)/(1+tax_rate/100)`; `f = 1 − desc/100`; `gross = r2(bruto×f)`, `net = r2(liquido×f)`, `tax = r2(gross − net)`, `discount = r2(bruto − gross)`. Agrupamento: factura única ou uma por quarto (cliente da estadia, senão cliente do POS). **Repartição dos pagamentos por factura**: proporcional a `gross_factura/totalGeral` (`r2(p.amount × g.gross / totalGeral)`), a última factura recebe o resto; diferença ≥0.01 corrigida no maior pagamento; troco só na última. Cada factura é gerada por `criarVendaPOS` (§2.A.8: série do terminal, FE/AGT, baixa de stock dos consumos inventariáveis no armazém do terminal, `table_name` = nomes dos quartos, `hotel_stay_ids`). Estadia → `FECHADA` (`checkout_at`, `final_quantity`, `late_choice`, `discount_pct`, `sale_id`, `sale_number`, `history` «Check-out: <doc> (N diária(s), saída tardia: recálculo cobrado|mantido o contratado, desconto p%).»); limpa consumos do quarto e `pos_custom_table_carts`. Imprime um único talão com todas as linhas (`docNumber` = documentos separados por vírgula, `table` = quartos) via `imprimirTalao`, volta ao `Balcao`, modal «Check-out concluído» «Emitido(s): …». Erro: alert «Erro: …».
**Quirks**: (1) o talão térmico de check-out não procura QR FE (o `docNumber` contém vírgula quando há várias facturas — `printPOSThermalReceipt :8021` ignora FE nesse caso); (2) `showPOSCheckout` no Balcão em modo hotelaria usa o checkout normal (venda directa); (3) não há registo de «early check-out» com reembolso; (4) a «Venda à hora» só bloqueia a **entrada**, não a saída prevista; (5) o bloqueio horário é por terminal, não por quarto.


---

## 2.D POS LAVANDARIA E ALFAIATARIA — `js/lavandaria.js` (3031 linhas), `js/fluxo_lavandaria.js` (306), `js/scratch/fix_pos_payments.js` (413)

Terminal tipo `lavandaria`; quando a caixa está aberta, `renderPOSTab` desvia para `lavRenderFrente()` (`pos_gestao.js:139`). Há ainda um separador de backoffice **«Lavandaria»** (`lav_config`, `lavandaria.js:2895-2910`, botão `data-lav-extra` com `fa-tshirt`) com 6 sub-separadores: **Peças**, **Serviços**, **Ordens de serviço**, **Relatórios**, **Danos e reclamações**, **Definições** (`:1682-1698`, estado `_lavBO`). Regras do cliente em comentário `:1-16` (10+1 regras: facturação na recepção ou entrega; preço por peça/kg definido na peça; adiantamento obrigatório só para Consumidor Final; peças não levantadas 30 dias + taxa 2%/dia; danos indemnizados pelo valor da peça com comprovativo; orçamentos de alfaiataria sem limite com aprovação prévia; recolha/entrega ao domicílio com taxa; materiais de alfaiataria abatidos ao stock sem entrar no preço; códigos automáticos e serviços na classe 62; FR vs FT; estado à entrada obrigatório).

### 2.D.1 Constantes e enumerações
- **Estados** (`ESTADOS :37-45`): `ORCAMENTO` «Orçamento por aprovar» #b45309; `RECEBIDA` «Recebida» #2563eb; `EM_EXECUCAO` «Em execução» #7c3aed; `PRONTA` «Pronta» #059669; `ENTREGA_PARCIAL` «Entrega parcial» #0891b2 (só ordem); `ENTREGUE` «Entregue» #475569; `ANULADA` «Anulada» #b91c1c. Aplicam-se a ordem e a cada linha (peça).
- **Grupos**: `LAVANDARIA`→«Lavandaria», `ALFAIATARIA`→«Alfaiataria». **Unidades**: PECA/TAMANHO→«peça», KG→«kg». **Modos de preço**: `PECA` «Peça», `KG` «Quilograma (kg)», `TAMANHO` «Tamanho» (+ nome do tamanho; sugestões `XS,S,M,L,XL,XXL,Criança,Pequeno,Médio,Grande,Casal,Solteiro`).
- **Estados à entrada** (`ESTADOS_ENTRADA :51`): «Bom estado», «Manchas», «Rasgões ou furos», «Desbotada / descolorada», «Botões ou fechos em falta», «Desgaste / pelo», «Encolhida / deformada», «Outro dano». **Tecidos sugeridos**: Algodão, Linho, Seda, Lã, Poliéster, Viscose, Ganga, Couro, Camurça, Sintético, Misto. **Cores sugeridas**: Branco, Preto, Azul, Azul-marinho, Cinzento, Bege, Castanho, Vermelho, Verde, Amarelo, Rosa, Estampado, Várias.
- **Extras** (produtos criados automaticamente se não existirem, `produtoExtra :170-182`, `lav_group='EXTRA'`, `is_service 1`, conta = `extras_account`, IVA = `extras_tax_rate`): `URGENCIA` `LAV-URG` «Taxa de urgência»; `ARMAZEM` `LAV-ARM` «Taxa de armazenagem»; `RECOLHA` `LAV-REC` «Recolha ao domicílio»; `ENTREGA` `LAV-ENT` «Entrega ao domicílio».
- **Definições por omissão** (`DEF_PADRAO :60-64`, tabela `lav_settings`): `storage_fee_enabled true`, `storage_free_days 30`, `storage_fee_pct_day 2`, `cf_advance_pct 50`, `urgency_pct 50`, `urgency_lead_factor 0.5`, `pickup_fee 0`, `delivery_fee 0`, `invoice_on_advance true`, `compensation_account ''`, `extras_account ''`, `extras_tax_rate 14`, `claim_days 7`.
- **Consumidor Final** (`ehConsumidorFinal :68`): sem cliente, ou NIF só dígitos `^9{8,9}$`, ou nome ~ /consumidor\s*final/i.
- **Códigos automáticos** (`proximoCodigo`): prefixo + máx numérico+1 com 4 dígitos: peças `PC0001`, serviços `SV0001`.

### 2.D.2 Modelo de dados (Dexie)
- `lav_pieces` (`lav_company_id`, `code PCnnnn`, `name`, `fabric`, `color`, `lav_prices[] {mode,size,price}` (opções de preço; a 1.ª manda na grelha), `unit` e `price` espelho da 1.ª opção (compatibilidade), `service_prices[] {product_id, mode?, size?, price}` (matriz serviço × opção), `is_active 0/1`, `created/updated_at/by`).
- `products` com `lav_group` (`LAVANDARIA|ALFAIATARIA|EXTRA`), `lav_lead_days` (omissão 2; alfaiataria 5 se vazio), `lav_requires_quote`, `lav_active`, `account_code` (classe 62), `tax_rate` (omissão 14), `is_service 1`, `is_inventory 0`, `unit_price 0` — **os serviços são produtos**; preço vem da peça.
- `lav_orders` (`lav_company_id`, `order_number OS/<TERM>/<ANO>/<5 díg>`, `terminal_id/code`, `customer_id`, `received_at/by`, `reception_session_id`, `invoice_mode ENTREGA|RECEPCAO`, `urgent`, `promised_date`, `notes`, `pickup{enabled,address,date,fee}`, `delivery{…}`, `invoice_ids[]`, `extras[] {id,key,product_id,name,amount,tax_rate,invoice_sale_id,cancelled}`, `history[] {at,by,text}`, `status`, `assigned_employee_id/name/at/by`, `assignment_note`, `assignments[]`, `cancel_reason`, `delivered_at`, `items[]`).
 Linha (`items[]`): `line_id`, `piece_id/code/name`, `color`, `fabric`, `unit`, `size`, `product_id`, `service_code`, `name`, `group`, `qty`, `pieces` (n.º de etiquetas), `price` (IVA incl. por unidade), `tax_rate`, `lead_days`, `entry_state`, `entry_notes`, `declared_value`, `quote_required`, `quote_status PENDENTE|APROVADO|RECUSADO`, `quote_amount`, `quote_channel/note/approved_at/by`, `status`, `started_at`, `ready_at`, `delivered_at/by`, `assigned_to`, `tags[]`, `materials[] {product_id,name,qty,at,by}`, `invoice_sale_id`, `cancel_reason`, `piece_desc`.
- `lav_payments` (`lav_company_id`, `order_id/number`, `session_id`, `terminal_id`, `customer_id`, `date`, `amount`, `change`, `pos_payments[]`, `receipt_number`, `kind FR|ADIANTAMENTO|PAGAMENTO`, `sale_id`, `status REGISTADO|ANULADO`, `posting_lans`).
- `lav_claims` (danos/reclamações: `order_id/number`, `line_id`, `item_name`, `piece_desc`, `entry_state`, `customer_id`, `description`, `declared_value`, `status AGUARDA_COMPROVATIVO|COMPROVADO|APROVADA|RECUSADA|PAGA`, `proof_ref/date/amount`, `compensation`, `decision_note`, `decided_at/by`, `treasury_doc_id`, `paid_at/by`).
- `lav_settings` (acima). Terminal: contadores `lav_os_counters`, `lav_fr_counters`, `lav_ft_counters`, `lav_rc_counters` (por ano).
- Documentos de venda com `lav_order_id`, `lav_order_number`, `amount_paid`.

### 2.D.3 Frente de caixa da lavandaria (`lavRenderFrente :467-503`)
Barra superior: terminal (ícone fa-tshirt), `#session_code`, operador, widget de saldo, botões «Trocar terminal», «Relatório X», «Fechar caixa». Dois separadores: **«Nova ordem de serviço»** e **«Ordens e levantamentos»**. Se há cliente seleccionado no POS e a ordem em preparação está vazia, propaga-o.
**Nova ordem** (`renderNovaOrdem :505-636`), disposição: coluna esquerda (420-460px): **1. Cliente e condições** (Cliente select + botão novo cliente [reusa `showPOSCreateCustomerModal`]; **Facturação**: «Na entrega» (omissão) | «Na recepção»; **Serviço urgente** checkbox «+50% e prazo reduzido»), **2. Recolha e entrega ao domicílio** (por cada: checkbox, **Morada \***, Data, **Taxa (IVA incl.)** omissão da definição), **3. Observações**. Coluna direita (fixa/sticky): **Resumo** (Serviços (n linha(s)), Urgência (50%), Recolha, Entrega, **Total**; «Pronta a partir de <data>», «N etiqueta(s)», «Facturação agora, na recepção | na entrega», aviso Consumidor Final «adiantamento mínimo de 50% (valor)» ou «Cliente em conta corrente: adiantamento opcional», «N peça(s) sem estado à entrada»), botões **«Registar ordem»** (desactivado sem linhas) e **«Limpar»** (confirma «Limpar a ordem em preparação?»); **Catálogo** (painel de grelha/lista; botões «Peças»/«Serviços»; pesquisa «Procurar por nome, código ou tecido…» com debounce 220 ms; zoom 72–190 px (omissão 110) com slider e botões ±18; botão tag para mostrar/esconder código/tecido/preço (detalhes só aparecem acima de 88 px); vista grelha (`auto-fit`) ou lista; persistência `localStorage lav_grelha_zoom|modo|detalhes|vista`; texto «Pique a peça e escolha o serviço.»/«Pique o serviço e escolha a peça.»). **4. Peças da ordem**: lista (máx 46vh) com uma linha por peça (resumo: nº, peça + tamanho + serviço, `qtd× preço`, estado à entrada com ícone ⚠/✓ e «falta descrever o dano», «orçamento», valor; botões expandir/remover); detalhe expandido (um de cada vez) com **Cor**, **Tipo de tecido**, **Quantidade (peça|kg)** (step 0.1 kg / 1 peça), **N.º de peças (etiquetas)** (só kg), **Preço por peça|kg (IVA incl.)** (só leitura salvo orçamento: «Estimativa (IVA incl.)»), **Valor da peça (opcional)** (para indemnização), **Estado da peça à entrada \*** (select, vermelho se vazio), **Descrição do dano \*** (obrigatória se estado ≠ «Bom estado»; senão «Observações da peça»).
**Picar peça/serviço** (`lavGrelhaPeca/Servico/Escolher :855-946`): abre modal com bloco «Estado da peça à entrada \*», «Cobrar por \*» (se a peça tem >1 opção), «Quantidade», «N.º de peças» (kg), «Descrição do dano \*» (se dano), pesquisa e a lista de serviços (por grupo) ou peças×opções, cada com o preço específico; botão desactivado e «sem preço para esta peça nesta opção — complete em POS → Lavandaria → Peças» se o preço ≤0 e o serviço não é de orçamento. Validações ao escolher: «Indique o estado da peça à entrada antes de a juntar à ordem.»; «Descreva o dano: é o que protege a lavandaria e o cliente numa reclamação.»; «Indique a quantidade.». `adicionarLinha` (`:962-981`) usa `precoPecaServico(peça, serviço, opção)` = preço específico (>0) da matriz `service_prices` para (serviço, opção; entradas antigas sem modo valem para a opção única) senão preço base da opção; sem preço e sem orçamento: «A peça "X" não tem preço em «Opção» para o serviço "Y" (nem preço base). Complete-a em POS → Lavandaria → Peças.». Nova linha: `lead_days = lav_lead_days || (ALFAIATARIA?5:2)`, `quote_required = lav_requires_quote`, `declared_value 0`.
**Cálculo da ordem** (`calcularNova :948-959`): `servicos = Σ valorItem`, `valorItem = status==='ANULADA' ? 0 : r2(qty × price)`; `urgencia = urgent ? r2(servicos × urgency_pct/100) : 0`; `recolha = pickup.enabled ? r2(pickup.fee ?? def.pickup_fee) : 0`; `entrega` idem; **`total = r2(servicos+urgencia+recolha+entrega)`**; `prazoDias = max(lead_days das linhas)`; `dias = urgent ? max(1, ceil(prazoDias × urgency_lead_factor)) : max(1, prazoDias)`; `prometida = hoje + dias` (dias corridos); **`etiquetas = Σ (KG ? max(1,round(pieces)) : max(1,round(qty)))`**.
**Confirmar ordem** (`lavConfirmarOrdem :1003-1038`): validações — cliente; «Indique o estado à entrada da peça "X" (linha n).»; «Descreva o dano registado na peça "X" (linha n).»; «Indique a quantidade (peças ou kg) de todas as linhas.»; «Indique o preço de todas as linhas (excepto orçamentos).»; morada obrigatória da recolha/entrega activa. Modal «Registar ordem de serviço» com resumo e **«Valor a receber agora»** (omissão = total se «Na recepção» e Consumidor Final; senão o mínimo do CF [`r2(total×cf_advance_pct/100)`] ou 0), pagamentos mistos (componente §2.A.7), botões «Voltar» e «Registar e imprimir».
**Gravar** (`lavGravarOrdem :1040-1105`): «O Consumidor Final tem de pagar um adiantamento mínimo de X.»; «O valor a receber não pode exceder o total da ordem.»; pagamentos válidos. Gera `OS/<TERM>/<ANO>/<n 5 díg>` (contador `lav_os_counters`), etiquetas `<TERM><n 5 díg>-<seq 2 díg>` (sequência contínua pela ordem), cada linha: `status = quote_required ? 'ORCAMENTO' : 'RECEBIDA'`, `quote_status = 'PENDENTE'` se orçamento, `quote_amount = valorItem`; extras (urgência «Taxa de urgência (50%)», «Recolha ao domicílio · morada», «Entrega ao domicílio · morada»); `history` «Ordem recebida (N linha(s), M etiqueta(s)); K peça(s) com danos à entrada.». **Facturar se** `invoice_mode==='RECEPCAO'` **ou** (`valor>0` e `invoice_on_advance`); `receberComFacturacao` (§2.D.5). Imprime: **OS (2 vias)**, **etiquetas**, **documento/recibo**; abre o detalhe.
**Peças/serviços por orçamento** (`lav_requires_quote`): linhas com `quote_required` não são facturáveis até `quote_status==='APROVADO'` (`itemFacturavel :188`).

### 2.D.4 Lista «Ordens e levantamentos» e backoffice «Ordens de serviço» (`renderListaOrdens :1110-1254`)
KPIs clicáveis: «Recebidas hoje», «Sem responsável», «Orçamentos por aprovar», «Por executar / em execução», «Prontas para levantar», «Atrasadas», «Não levantadas (+N dias)». Filtros: pesquisa (OS, etiqueta, peça, cliente, NIF, telefone, responsável; Enter), estado (`ACTIVAS` «Em curso» omissão, `TODAS`, `SEM_RESPONSAVEL`, `ORCAMENTO`, `RECEBIDA`, `EM_EXECUCAO`, `PRONTA`, `ENTREGA_PARCIAL`, `ATRASADAS`, `NAO_LEVANTADAS`, `ENTREGUE`, `ANULADA`), responsável, terminal. Ordenação por recepção desc, máx. 300. Colunas: ☐, Ordem (+terminal, URGENTE), Cliente, Recebida, Responsável («por atribuir»), Atribuída em, Prazo (vermelho se atrasada), Pronta em, Entrega, Indicadores, Estado, Total, Saldo, abrir. Acções em massa (ordens em curso seleccionadas): **«Atribuir responsável»**, **«Alterar estado»**, **«Aprovar / recusar orçamentos»**. Painel «Carga e desempenho por responsável · indicadores de conclusão dos últimos 30 dias»: Em execução, Atrasadas, Prontas por levantar, Concluídas, % no prazo, Execução média (dias), Ciclo médio até à entrega (dias).
**Indicadores** (`indicadoresOrdem :224-243`): `concluida` = todas as linhas activas PRONTA/ENTREGUE; `prontaEm = max(ready_at)`; `entregueEm = delivered_at`; `atraso = max(0, dias(referência − promised_date))` com referência = prontaEm ou agora (só dias civis); situação: «Anulada» / «Concluída com Nd de atraso» (#b45309) / «Concluída no prazo» (#059669) / «Atrasada Nd» (#b91c1c) / «Dentro do prazo» (#2563eb); `diasAberta`, `execucao` (de `assigned_at` [se anterior à pronta] senão `received_at`, até pronta), `esperaLevantamento` (pronta→entregue/agora), arredondados a 0,1 dia (`difDias` usa /8640000 /10). **Não levantada** = alguma linha PRONTA há > `storage_free_days`.
**Estado da ordem** (`estadoOrdem :192-201`): sem linhas activas → ANULADA; todas ENTREGUE → ENTREGUE; alguma ENTREGUE → ENTREGA_PARCIAL; todas PRONTA → PRONTA; alguma EM_EXECUCAO/PRONTA → EM_EXECUCAO; alguma ORCAMENTO → ORCAMENTO; senão RECEBIDA.
**Totais** (`totaisOrdem :203-210`): `servicos = Σ valorItem`; `extras = Σ extras não cancelados`; `total`; `facturado = Σ total_gross das facturas não anuladas`; `pago = Σ lav_payments não anulados`; `saldo = total − pago`; `porFacturar = total − facturado`; `saldoFacturado = facturado − pago`.
**Detalhe da ordem** (`lavAbrirOrdem :1259-1332`, modal 1100 px): cabeçalho (cliente, recepção, pronta a partir de, responsável, situação, facturação, recolha/entrega, totais), tabela Peça·serviço | Estado à entrada | Etiquetas | Estado | Valor | Acções, painéis «Documentos de venda» (venda directa/conta corrente), «Recebimentos» (factura-recibo/adiantamento/recibo; reimprimir) e «Danos / reclamações», «Histórico». Acções por linha: orçamento → **Aprovar orçamento / Recusar**; RECEBIDA → **Iniciar**; EM_EXECUCAO → **Marcar pronta**; alfaiataria RECEBIDA/EM_EXECUCAO → **Materiais**; **Dano / reclamação**. Acções da ordem: OS, Etiquetas, **Mensagem ao cliente** (WhatsApp), Atribuir/Reatribuir responsável, Alterar estado, Iniciar todos, Todos prontos, **Anular** (só sem recebimentos nem facturas), e (com caixa aberta e fora do modo consulta) **Emitir factura** (linhas por facturar), **Receber pagamento** (saldo>0), **Entregar ao cliente** (há peças PRONTA). No backoffice (`soLeitura`) só aprovação/recusa de orçamentos.
**Acções de linha**: INICIAR pede «Executado por (operador / alfaiate):» (omissão responsável da ordem ou operador) → `EM_EXECUCAO`, `started_at`; PRONTA → `PRONTA`, `ready_at`; RECUSAR pede motivo («Motivo da recusa do orçamento de "X" (a peça é devolvida sem custo):») → linha `ANULADA`, `quote_status RECUSADO`; **APROVAR** (modal: **Valor aprovado (total, IVA incluído)**, **Forma de aprovação** Presencial|Telefone|WhatsApp|E-mail, **Notas**; «Indique o valor aprovado.») → `quote_status APROVADO`, `quote_amount = valor`, **`price = valor/qty`**, status `RECEBIDA`. **Aprovar/recusar em massa** (`lavOrcamentosMassa/ConfirmarOrcMassa :2185-2299`): tabela de orçamentos seleccionáveis com valor aprovado editável, decisão Aprovar|Recusar, canal, nota (recusa exige ≥3 caracteres), confirma «Aprovar N orçamento(s) no total de X (canal)?»; ignora ordens entregues/anuladas e orçamentos já tratados. **Materiais** (`consumoMaterial :1414-1448`, só produtos `is_inventory` sem `lav_group`): quantidade >0; stock insuficiente pede confirmação; abate `products.stock_qty`, `updateWarehouseStock(armazém do terminal || armazemPorOmissao || 1)`, `inventory_movements` SAÍDA (`unit_price = average_cost||cost_price`, `reference 'Consumo <OS>'`); regista em `materials[]`. **Não é acrescentado ao preço.** **Dano/reclamação** (`registarDano/lavConfirmarDano`): descrição ≥5 caracteres, valor declarado → `lav_claims` `AGUARDA_COMPROVATIVO`. **Alterar estado** (`lavAlterarEstado/ConfirmarEstado :2307-2390`): destinos `EM_EXECUCAO` (de RECEBIDA), `PRONTA` (de RECEBIDA/EM_EXECUCAO; preenche `started_at`), `RECEBIDA` «reverter» (de EM_EXECUCAO/PRONTA; limpa started/ready; motivo ≥3), `ANULADA` (sem pagamentos/facturas/peças entregues; motivo ≥3); ordens entregues/anuladas ignoradas; entrega só pelo fluxo «Entregar». **Atribuir responsável** (`:2105-2178`): colaboradores activos (RH `employees`, excluindo estado «não activo») com carga «N ordem(ns) em curso», data (não futura, não anterior à recepção), «Aplicar às peças» (`PENDENTES`: preenche `assigned_to` das linhas ORCAMENTO/RECEBIDA/EM_EXECUCAO | `NENHUM`), nota; «Retirar responsável»; histórico em `assignments[]`. **Mensagem** (`lavMensagem`): 4 modelos de texto (orçamento por aprovar; peças não levantadas com taxa; pronta com saldo; em tratamento com data) → copiar ou abrir `wa.me/<244+9 dígitos>?text=`.

### 2.D.5 Documentos, pagamentos, entrega e armazenagem
**`emitirDocumento(o, linhas, sessao, term, calc)`** (`:284-347`): linhas facturáveis = itens com `!invoice_sale_id`, status≠ANULADA e orçamento aprovado (ou não exigido) + extras não facturados/cancelados, com `total>0` (`linhasPorFacturar`, filtro opcional por `line_id`). **Tipo**: **Factura-Recibo** se há pagamento (`calc`) com `recebido>0`, `recebido == total` (±0,005) e `antes.facturado == antes.pago`; **senão Factura** (conta corrente). Numeração: `FacturaAGT.numerar(tipo)` (série do terminal) → `numerarLocal` → ou `FR-LAV/<TERM>/<ANO>/<5>` / `FT-LAV/<TERM>/<ANO>/<5>` (contadores `lav_fr_counters`/`lav_ft_counters`). `sales`: `doc_type`, `status PAGO` (FR) | `PENDENTE` (FT), `due_date = data`, `total_gross = Σ linhas`, `total_net = Σ r2(l.total)/(1+iva)`, `total_tax = total − net` (net arredondado r2), `lav_order_id`, `amount_paid`, `payment_method` (nome do meio, «Misto (…)» ou «Conta corrente»), `pos_payments` (FR) | null, `pos_change`; `sale_items` com `unit_price = total/qty`; sela FE; marca linhas `invoice_sale_id`; FR cria também `lav_payments` `kind 'FR'` (não contabilizado como recibo). Histórico «Factura-Recibo X emitida e paga (valor).» / «Factura X emitida em conta corrente (valor).».
**`registarRecibo`** (`:349-364`): `RC-LAV/<TERM>/<ANO>/<5>` (contador `lav_rc_counters`); `kind = ADIANTAMENTO` se `pago+valor > facturado+0,005`, senão `PAGAMENTO`.
**`receberComFacturacao`**: se há linhas, emite documento (com `calc` apenas se `valor>0`); se `valor>0` e o documento não é FR, regista recibo. **`actualizarFacturas`**: distribui o total pago pelas facturas por ordem de id (mais antigas primeiro): `pago_f = min(resta, total_gross)`, estado `PAGO` se `pago ≥ total−0,005` senão `PENDENTE`, `amount_paid`.
**Receber pagamento** (`lavReceberPagamento/ConfirmarPagamento :1510-1554`): valor >0 e ≤ saldo («O valor excede o saldo da ordem (X).»); `facturar = invoice_on_advance && pago+valor > facturado+0,005` (factura serviços confirmados: «IVA exigível no recebimento»).
**Emitir factura** (`lavFacturarPendente`): Factura em conta corrente das linhas por facturar; confirma «Emitir factura (conta corrente) de N linha(s) no valor de X?».
**Entrega** (`lavEntregar/lavEntregaRecalcular/lavConfirmarEntrega :1585-1677`): lista as peças PRONTA com checkbox, etiquetas, estado à entrada, «pronta há N dia(s)». **Taxa de armazenagem** (`taxaArmazenagem :1576-1583`): se `storage_fee_enabled`, por peça `diasTaxa = diasDesde(ready_at) − storage_free_days`; se >0: `valor = r2(valorItem × storage_fee_pct_day/100 × diasTaxa)`; soma = taxa; acrescenta extra `ARMAZEM-n` «Taxa de armazenagem (Nd, Md)» com IVA do produto extra. Simulação (`simularEntrega`): `porFacturar` das linhas escolhidas + taxa; `saldoFacturado = max(0, facturadoAntes + porFacturar − pago)`; `vendaDirectaPossivel = porFacturar>0 && facturadoAntes==pago`. Resumo: «Taxa de armazenagem: X (peça: N dia(s))», «A facturar nesta entrega: X · pagando este valor agora é emitida Factura-Recibo (venda directa)» ou «emitida Factura em conta corrente», «Saldo a pagar após a entrega: X», CF «o saldo tem de ser pago na entrega» | cliente em conta «o saldo pode ficar em dívida». Valida: «Seleccione as peças a entregar.»; CF «tem de receber X antes de entregar.»; «O valor excede o saldo a pagar (X).». Efeito: linhas `ENTREGUE` (`delivered_at/by`), ordem ENTREGUE se todas (`delivered_at`), documentos/recibos impressos, histórico «Entregue(s): …».
**Impressos** (todos 78 mm via `abrirImpressao`, §2.A.11): **OS** (`lavImprimirOS :401-421`: 2 vias «Via do cliente»/«Via da loja» com empresa/NIF, «ORDEM DE SERVIÇO <n>», cliente, NIF/Tel., Recebida, «Pronta a partir de», Facturação (na recepção/entrega), URGENTE, linhas (peça · serviço · qtd unidade, cor/tecido, descrição, «Estado à entrada: …», «Etiquetas: …», «Orçamento por aprovar»), extras, TOTAL/Pago/Saldo, Recolha/Entrega, Obs., **condições** (prazo de levantamento N dias, taxa de armazenagem X%/dia se activa, verificação do estado à entrada, indemnização = valor da peça com comprovativo, reclamações até N dias após a entrega, orçamentos só executados após aprovação) e assinatura «O cliente (confirmo o estado das peças e aceito as condições)»); **Etiquetas** (`:423-434`, 56×30 mm tracejadas: código da etiqueta 15px, «<OS> · cliente», «peça cor, tecido», serviço, «Entrada: estado», «Pronta: data» (+URGENTE)); **Recibo RC** (`:436-449`, «RECIBO <n>», Adiantamento|Pagamento, meios com referência, TOTAL RECEBIDO, Troco, «Processado por computador»); **Factura/FR** via `printInvoiceA4` (modelo comum de Vendas).

### 2.D.6 Backoffice (Peças, Serviços, Definições, Danos, Relatórios, Importação)
- **Peças** (`:1701-1880`): tabela Código | Peça | Tipo de tecido | Cor | Opções de preço | Preço (IVA incl.) («por definir» a vermelho; «+ N preço(s) por serviço») | Estado | editar; botões «Modelo», «Importar», «Modelo de preços», «Importar preços por serviço», «Nova peça». **Editor** (860 px): Código (auto), **Designação da peça \***, Tipo de tecido, Cor, Activa; **Opções de preço** (linhas Cobrar por [Peça/Quilograma/Tamanho] + Tamanho + **Preço base (IVA incl.)**; botão «+ Opção» acrescenta `TAMANHO`; remover se >1); matriz **Preços por serviço** (serviço × opção; vazio = preço base). Validações: designação; ≥1 opção; «Dê nome ao tamanho (ex.: M, Casal) ou remova essa opção de preço.»; «Há duas opções de preço iguais. Cada opção só pode aparecer uma vez.»; preços ≥0; duplicado (designação+tecido+1.ª opção) «Já existe uma peça com a mesma designação, tecido e primeira opção de preço. Junte-lhe uma opção em vez de criar outra peça.»; sem preço algum pede confirmação «A peça fica sem preço e só poderá ser usada em serviços com orçamento. Continuar?». Guarda `lav_prices`, `unit/price` espelho, `service_prices` (só preços >0 de opções válidas).
- **Serviços** (`:1889-1953`): Código | Serviço | Grupo | IVA | Conta de proveito («por definir» vermelho) | Prazo | Orçamento | Estado; editor: Designação \*, **Grupo \***, **Conta de proveitos (classe 62) \*** (só contas de movimento `62*`), Taxa de IVA (%) omissão 14, Prazo de execução (dias) omissão 2, «Exige orçamento aprovado», «Activo». Erros «Indique a designação do serviço.» / «Seleccione a conta de proveitos da classe 62.». Grava como `products` (`unit_price 0`, `is_service 1`, `is_inventory 0`).
- **Definições** (`:1956-2000`): os 12 parâmetros de `DEF_PADRAO` + «Facturar ao receber pagamentos antes da facturação» (`invoice_on_advance`: «O IVA é exigível no recebimento de adiantamentos…»); contas: «Conta de proveitos das taxas (classe 62)», «Conta das indemnizações por danos (custo)» (`7*`). Limites ao gravar: `cf_advance_pct` 0-100; `urgency_lead_factor` 0.1-1 (omissão 0.5); dias inteiros ≥0; actualiza `account_code`/`tax_rate` dos produtos LAV-URG/ARM/REC/ENT.
- **Danos e reclamações** (`:2003-2087`): tabela com Registo | Ordem·peça | Cliente | Estado à entrada | Reclamação | Declarado | Comprovativo | Indemnização | Estado | **Tratar**/**Pagar**. Tratar: comprovativo (n.º/descrição), data, **valor comprovado**, decisão (`COMPROVADO` só comprovativo | `APROVADA` indemnização = valor comprovado | `RECUSADA` fundamentação ≥5); aprovada/comprovada exige ref e valor>0. **Pagar** (`APROVADA`): exige `compensation_account` definida («Defina a conta das indemnizações em Lavandaria → Definições.»), exercício aberto, conta de caixa/banco (`^4[35]`), cria **`treasury_documents` PAGAMENTO** `PENDENTE` (ref `LAV-IND-<id>`) + `treasury_items` D conta de indemnizações (terceiro = cliente) e marca a reclamação `PAGA`.
- **Relatórios** (`:2399-2603`): período Dia|Mês|Intervalo × terminal; KPIs (Ordens recebidas [+peças+valor], entregues, Total facturado [base, IVA], Facturas (conta corrente), Facturas-Recibo, Recibos, Total recebido, Concluídas no prazo %, Danos registados, Saldo em aberto actual [+por facturar]), tabelas Documentos emitidos (+resumo por tipo), Recebimentos por meio, Por terminal, Facturado por serviço, Peças recebidas, Resumo diário; exportação **Excel** (7 folhas: Resumo, Documentos, Meios de pagamento, Serviços, Peças, Terminais, Diário; ficheiro `Relatorio_Lavandaria_<de>_<ate>.xlsx`) e **Imprimir** A4.
- **Importação Excel/CSV** (`:2608-2893`): modelos `Modelo_Pecas_Lavandaria.xlsx` (colunas «Código (vazio = novo)», «Designação *», «Tipo de tecido», «Cor», «Venda por (Peça/Kg) *», «Preço IVA incl. (por peça ou kg) *», «Activa (Sim/Não)»), `Modelo_Servicos_Lavandaria.xlsx` («Código…», «Designação *», «Grupo (Lavandaria/Alfaiataria) *», «Conta de proveitos (62) *», «IVA (%)», «Prazo (dias)», «Exige orçamento (Sim/Não)», «Activo (Sim/Não)»), `Modelo_Precos_Peca_Servico.xlsx` («Código da peça *», «Código do serviço *», «Preço IVA incl. (0 = usar o preço base)», «Opção (Peça/Kg/tamanho — vazio = a 1.ª)»). Pré-visualização com Criar/Actualizar/Ignorada e erros por linha; casa por código ou por (designação, tecido, unidade | designação, grupo); números aceitam vírgula decimal; Sim/Não aceita s/sim/y/yes/1/true/x/activo e n/não/no/0/false/inactivo; conta 62 existente e de movimento; `ImportMestre.decidir` pergunta se actualiza existentes (dados mestre) — preço 0 remove o preço específico.

### 2.D.7 Integração com o fecho e a contabilização do POS (`lavandaria.js:2915-3028`)
- `posFontesSessao`: para o fecho/Z, os recibos (`lav_payments`) entram como «documentos» com `total_gross = amount` e `pos_payments`; as facturas de lavandaria entram com `total_gross 0` e sem pagamentos (para contar documentos sem duplicar valores). **`totaisSessao` exclui as `sales` com `lav_order_id`** (para não duplicar: FR já está em `lav_payments` kind FR).
- `posIntegracaoExtras.linhas` (`linhasIntegracao :2925-3009`), por dia: **Factura-Recibo** → D transitórias dos meios (agrupadas por transitória|meio; transferências por documento), sem conta corrente do cliente; **Factura** → **D conta corrente do cliente** (`third_party.account_code`, senão `getSalesAccount('clientes_default','31.1')`) pelo total; créditos por item (`factor = total_gross/Σ item.total`; `bruto = item.total × factor`; `base = bruto/(1+iva)`): **C conta de proveito** `resolveMatérialAccount(prod,'62')` (tem de começar por `62`, senão impedimento «o serviço "X" não tem conta de proveitos da classe 62») + **C IVA** (`prod.account_iva_liquidado||account_iva`, senão `contaIvaLiquidadoPorOmissao` / `getSalesAccount('iva_vendas','34.5.3')`); **Recibos (RC/adiantamento, kind≠FR)** → D transitórias dos meios + **C conta corrente do cliente** pelo valor. Equilíbrio como no POS (ajuste ≤1 ao maior crédito «Serviços»; senão impedimento). `marcar`: `sales.is_posted=true`, `pos_posting_lans`, `lav_payments.posting_lans`; `desmarcar`: limpa `posting_lans` dos recibos.

### 2.D.8 Fluxo de Processos — Lavandaria (`fluxo_lavandaria.js`)
Um processo = uma ordem. Etapas (`ETAPAS :32-39`): **Recepção → Orçamento (alfaiataria) → Execução → Entrega ao cliente → Facturação e contabilização → Recebimento ou compensação**. Consulta e atalhos (vista funil + tabela partilhada `fluxo_tabela.js`): funil «Aguardam orçamento», «Em execução», «Por entregar», «Por facturar ou contabilizar», «Por receber», «Concluídas»; filtros «Por concluir», «Com pendências», «Atrasadas», «Por levantar», «Fora do prazo de levantamento», «Com valor por receber», «Todas»; KPIs «Ordens em curso», «Atrasadas», «Prontas por levantar», «Fora do prazo de levantamento», «Valor por receber». Regras relevantes: **por receber** = `facturado − min(facturado, pago + compensação (journal_lines D classe 3 com `reconciliation_id`) + créditos classe 3 não reconciliados com o n.º da factura)`; **adiantamento por facturar** = `pago − facturado`; avisos de recebimentos não contabilizados e de recebimento pendente na Tesouraria (documentos `RECEBIMENTO` ≠ INTEGRADO/ANULADO com item C e `doc_number` da factura); atalhos «Importar pendentes na Tesouraria», «Ver extracto por compensar», «Contabilizar na Integração do POS», «Entregar/Receber na Frente de Caixa». Abre o POS (`navigateTo('pos')` → `renderPOS('lav_config')` → `lavAbrirOrdem(id, true)`).

### 2.D.9 `js/scratch/fix_pos_payments.js` (script Node de uma só utilização, NÃO faz parte da aplicação)
Reescreve em `ui_sales.js` as funções `showPOSCheckout`, `updatePOSMultiPayments`, `setPOSMultiPaymentAll`, `finalizePOSPayment` para um checkout com **um campo de valor por meio de pagamento da Tesouraria** (`payment_methods` activos, classes 43/45; omissão «Numerário (Predefinição)» conta `4511001`) com botão «Tudo» por meio. Está obsoleto: a função equivalente final está em `pos_gestao.js` (meios por terminal com conta transitória). Mantém o numerador `FR-POS/<ano>/<seq 4>`. Lido: linhas 1-120 e inspeccionado o resto por pesquisa (sem lógica adicional relevante).


---

## 2.E FOLHA DE CAIXA (Tesouraria) — `js/ui_folha_caixa.js` (2020 linhas) + `js/moedas_tesouraria.js:334-426`

A Folha de Caixa não é um terminal POS: é o **livro de caixa de uma conta classe 45** (Tesouraria › «Folha de Caixa», `ui_tesouraria.js:134,159`, rota `#/tesouraria/folha-caixa`, vista `teso_folha_caixa` → `renderTesourariaOperacoes('folha_caixa')`, `app_v2.js:1167`). Relaciona-se com o POS porque é o **destino do numerário** na «Prestação de contas» (§2.A.17): cada transferência cria uma linha `cash_lines` `source_type 'pos'`. Só pode existir **uma sessão ABERTA por empresa** (`confirmarAbrirSessao :367-368`), independentemente do n.º de terminais POS.

### 2.E.1 Ecrã `renderFolhaCaixa(view='sessoes')` (`:21-315`)
Cabeçalho «Folha de Caixa» / «Gestão de sessões de caixa, pagamentos e recebimentos diários.»; botão **«Abrir Sessão»** (só sem sessão aberta). Separadores **«Sessões»** e **«Caixa Aberta»** (só com sessão aberta).
- **Sessões** («Histórico de Sessões»): colunas Data Abertura | Conta (código + descrição) | Operador | Saldo Inicial | Entradas | Saídas | Saldo Final | Estado (`ABERTA` verde, `CONTABILIZADA` azul, `FECHADA` contorno) | Acções (ir para a caixa; `fa-eye` detalhes; `fa-print` imprimir; **Contabilizar** e **Eliminar** só em FECHADA). Valores na moeda da sessão (`caixaFxMoedaSessao`). `Saldo Final = opening_balance + Σ REC − Σ PAG` (calculado, não gravado).
- **Caixa Aberta**: barra «SESSÃO ABERTA» (conta, moeda, operador, data) com SALDO INICIAL / ENTRADAS / SAÍDAS / **SALDO ACTUAL** (+ contravalor Kz `≈ x · 1 EUR = y Kz`), botões **«Registar Saída / Despesa»** (PAG), **«Registar Entrada / Receita»** (REC), **«Pagar Faturas Fornecedor»**, **«Receber Faturas Cliente»**, **«Importar Excel»**, **«Conferir contrapartidas»**, **«Fechar Sessão»**. Tabela «Movimentos da Sessão (n)»: ☐ | Data | Tipo (Entrada/Saída) | Nº Doc. (+ link `doc_url`) | Referência | Descrição | Terceiro | UN | CC | Nota DEMO | Nota Fluxo | Valor | Remover (ou «Integrado»). Barra de atribuição em massa de **Nota às demonstrações** e **Nota de fluxo de caixa** (só linhas não integradas; mensagens «Seleccione as linhas na tabela.», «Escolha a nota às demonstrações e/ou a nota de fluxo de caixa.», «As linhas seleccionadas já estão integradas.»).

### 2.E.2 Abrir sessão (`:270-385`)
Campos: **Conta de Caixa (Classe 45)** (contas `45*` do plano; mostra `(USD)` se moeda ≠ AOA), **Data de Abertura** (hoje), **Operador** (username do utilizador), **Saldo Inicial (moeda)** (≥0; ao escolher a conta preenche com o **saldo físico (ou final) da última sessão fechada/contabilizada dessa conta**). Erros: «Seleccione uma conta de caixa.», «Indique a data de abertura.», «Já existe uma sessão aberta. Feche-a antes de abrir uma nova.». Grava `cash_sessions {company_id, account_code, operator, open_date, close_date null, opening_balance, closing_balance null, physical_balance null, currency, status 'ABERTA'}`.

### 2.E.3 Registar linha (`showRegistarLinhaCaixa :391-584`, `_caixaGravarLinha :667-706`)
Duas vistas (guardada em `localStorage folhaCaixa.vistaFormulario` = `grade`|`linhas`): **Grade** (um lançamento: Data Doc., Nº Documento, Referência, Terceiro, **Produto / Serviço** («preenche a conta»), **Conta Débito|Crédito (Contrapartida)**, Descrição, URL do documento, **Valor (moeda)** min 0.01, Unidade Negócio, Centro de Custo, Nota às Demonstrações, Nota Fluxo de Caixa) e **Linhas** (tabela de 5 linhas iniciais com «Adicionar linha», «Duplicar última», «Colar do Excel», total e «Registar lançamentos»; valida tudo antes de gravar; «Linha n: falta data, conta de contrapartida, valor.»; confirma «Registar N entrada(s)/saída(s) no total de X?»; registo parcial lista falhas). **Colar do Excel**: separador tab/«|»/«;», cabeçalho reconhecido pelo nome ou ordem do modelo (Data Doc | Tipo REC/PAG | Nº Doc | Referência | Descrição | Valor | Conta Contrapartida | NIF Terceiro | Nota Demonstração | Nota Fluxo Caixa | URL | Produto), linhas do outro tipo ignoradas, valores não reconhecidos a vermelho.
**Regra da contrapartida pelo produto** (`_caixaContaDoProduto :903-908`; decisão de 2026-10-22): **Saída (PAG) → conta de custo do produto (`account_cost`, classe 71/75)**; **Entrada (REC) → conta de proveito (`account_code`, 61/62)**. Origem gravada em `contra_origem` = `PRODUTO` (a conta final coincide com a do produto) ou `MANUAL`. Produto sem a conta: aviso âmbar «<produto> não tem conta de custo (71/75)|proveito (61/62) na ficha…», **nunca bloqueia**; o atalho só apaga a conta/descrição que ele próprio pôs. Erros: «Indique um valor válido.», «Falta a conta de contrapartida. O bem/serviço escolhido não tem …», «Seleccione a conta de contrapartida.», «Indique a data do documento.».
**Gravação**: `cash_lines {session_id, company_id, type REC|PAG, doc_date, doc_number, reference, third_party_id, product_id, contra_origem, account_debit = REC ? conta da sessão : contrapartida, account_credit = REC ? contrapartida : conta da sessão, description (omissão «Receita de caixa»/«Despesa de caixa»), doc_url, value = r2, bu_id, cc_id, demo_note_id, cashflow_note_id, source_type 'manual', source_id, is_posted false}` + campos de câmbio de `caixaFxCamposLinha`.
**Pagar/Receber faturas** (`showPagarFaturas/showReceberFaturas`, `confirmarPagamentoFaturas/confirmarRecebimentoFaturas :1105-1297`): lista de documentos em aberto derivados do **razão** (`_getOpenInvoicesFromAccounting :1742-1790`: agrupa `journal_lines` por terceiro+conta+documento; Clientes = saldo devedor D>C, Fornecedores = saldo credor C>D, aberto se saldo >0,01); filtro, notas DEMO/fluxo, valor a liquidar editável (omissão saldo, na moeda da sessão); gera linha `PAG` (D conta do documento, C conta da sessão, `reference 'PG-DOC <n>'`, «Pagamento <n> - <fornecedor>») ou `REC` (D conta da sessão, C conta do documento, `reference 'REC-DOC <n>'`, «Recebimento <n> - <cliente>»), `source_type 'contabilidade'`, campos de liquidação cambial (`caixaFxLiquidacao`). Mensagens «Nenhuma sessão aberta.», «Seleccione pelo menos um documento.», «N pagamento(s)/recebimento(s) registado(s) com sucesso.».
**Importar Excel** (`showImportFolhaCaixaModal/handleFolhaCaixaImport :1808-2020`): modelo com colunas «Data Doc (YYYY-MM-DD)», «Tipo (REC/PAG)», «Nº Doc», «Referência», «Descrição», «Valor», «Conta Contrapartida», «NIF Terceiro», «Nota Demonstração», «Nota Fluxo Caixa», «URL»; erros por linha «Tipo inválido (use REC ou PAG).», «Valor inválido.», «Conta Contrapartida em falta.»; resumo «Importação concluída! Sucesso: N movimentos. Erros: M linhas ignoradas.».
**Conferir contrapartidas** (`:829-894`): lista (sem corrigir) saídas PAG lançadas a débito de contas 61/62 (resultado do bug antigo do atalho), com «Está em» vs «Devia ser» (conta de custo do produto). **Remover linha** só não integrada («Tem certeza que deseja eliminar este movimento?», «Não é possível eliminar um movimento já integrado.»).

### 2.E.4 Fechar, contabilizar, eliminar, imprimir
- **Fechar sessão** (`:1332-1361`): pede «Saldo Físico contado» (omissão = saldo do sistema); grava `status 'FECHADA'`, `close_date`, `closing_balance = saldo sistema`, `physical_balance`; mostra diferença «SOBRA|FALTA» se |dif|>0,01. **Não há tolerância nem deliberação de desvio na Folha de Caixa** (ao contrário do POS).
- **Contabilizar sessão** (`:1467-1596`): só FECHADA; exige movimentos; diário **`CX`** (senão `CA`; senão cria `CX` «Diário de Caixa»); **nota às demonstrações 10** obrigatória para linhas em contas 45 (aviso se não existir; linhas de caixa levam sempre nota 10 e **nenhuma nota de fluxo**; as da contrapartida levam as notas da linha); por **cada linha** um `lan_number` novo (`generateGlobalLanNumber`) com 2 linhas D/C (`doc_number = nº doc ou 'CX-<sessão>'`, `reference = ref ou 'CX-<conta>'`, valores em Kz via `caixaFxPartidas`) e, se houver diferença cambial, 3.ª linha em **6621 (ganho) / 7621 (perda)** («Diferenças de câmbio favoráveis|desfavoráveis realizadas»); marca `cash_lines.is_posted=true`; sessão `CONTABILIZADA`. Alerta «Sessão contabilizada com sucesso! Nº Lançamento: … N movimentos integrados.».
- **Eliminar sessão**: só FECHADA (CONTABILIZADA proibido) — apaga linhas e sessão. **Detalhes** (modal 750 px com cartões e tabela + «DIFERENÇA») e **Imprimir** (A4: «FOLHA DE CAIXA», empresa, saldos, «Saldo Físico», tabela Data|Tipo|Nº Doc|Ref.|Descrição|Terceiro|Débito|Crédito|UN|CC|Valor).

### 2.E.5 Moeda da caixa (`moedas_tesouraria.js:334-426`)
A sessão tem a moeda da conta 45 (`caixaFxMoedaSessao`: `session.currency` → mapa moeda das contas → `AOA`). Linha simples: se moeda≠AOA exige câmbio `Moedas.obterCambio(moeda, data)` (erro «Não há câmbio de USD registado até dd/mm/aaaa. Registe-o em Configurações → Moedas e Câmbios.») e grava `currency`, `exchange_rate`, `exchange_rate_id`, `value_kz = arred2(valor × taxa)`. **POS → Folha de Caixa**: `caixaFxCamposLinha(folha, data, valor)` é chamado em `liquidarNumerario` com o valor em Kz do numerário (se a Folha estiver em USD o valor entra como se fosse na moeda da folha — comportamento a conferir). Liquidação de documentos: `contra_value_kz` = valor histórico proporcional do documento (`bal_kz`) e diferença cambial realizada = `debito − credito` (≥0,01).

---

## 3. MODELO DE DADOS

Persistência: **IndexedDB (Dexie)** local ao browser (`db_v2.js`); NÃO há servidor para estas tabelas no legado (repositórios `js/data/*` só cobrem outras entidades — `grep` não encontrou POS neles). Várias das tabelas POS usam campo de empresa próprio (`pos_company_id`, `lav_company_id`, `hotel_company_id`) «para não activar os hooks de exercício encerrado/auditoria» (`db_v2.js:705-731`), por isso **as tabelas POS não têm auditoria automática** (`logMovement` só é chamado no acerto de stock do POS de Armazém). Hook de validação: contas mapeadas em `pos_terminals` (transit/settlement/commission), `pos_settings` e `lav_settings` **não podem ser totalizadoras** (`db_v2.js:479-511`, erro «A conta X é totalizadora e não pode ser mapeada (campo). Só contas de movimento podem ser associadas.»). Hooks de Facturação electrónica impedem eliminar/alterar número, data, cliente e totais de documentos selados (`facturacao_agt.js:8-10`).

### 3.1 Tabelas exclusivas do POS (índices Dexie literais)
| Tabela | Índices Dexie | Campos (tipo) e notas |
|---|---|---|
| `pos_terminals` (V1245) | `++id, pos_company_id, code, is_active, [pos_company_id+code]` | `id` int; `pos_company_id`; `code` str (≤10, `[A-Z0-9-]`, único por empresa); `name`; `type` loja\|restaurante\|hotelaria\|lavandaria; `business_unit_id`, `cost_center_id`, `warehouse_id`, `default_customer_id` (int\|null); `default_float` num; `payment_methods[]` = `{id 'pm_…', kind NUMERARIO\|TPA\|TRANSFERENCIA, name, transit_account, settlement_account, tpa_code, commission_account, commission_pct, commission_deducted (bool, omissão true), active (bool), copied_from}`; contadores por ano `counters{ano:n}` (documentos FR-POS), `session_counters`, `z_counters`, `lav_os_counters`, `lav_fr_counters`, `lav_ft_counters`, `lav_rc_counters`; `hotel_checkin_time/checkout_time/late_tolerance_min/hour_block/hour_block_from/hour_block_to`; `is_active` 1\|0; `legacy_id`; `created_at/by`, `updated_at/by`. |
| `pos_sessions` | `++id, pos_company_id, terminal_id, status, opened_at, posting_status, settlement_status, deviation_status, [pos_company_id+status], [terminal_id+status]` | `terminal_id/code/name`, `session_code` (`<TERM>-<ANO>-<NNNN>`), `status` ABERTA\|FECHADA, `opened_at` ISO, `opening_float`, `operator_id/name`, `posting_status` PENDENTE\|CONTABILIZADA\|SEM_MOVIMENTO, `settlement_status` PENDENTE\|PARCIAL\|LIQUIDADA\|SEM_MOVIMENTO, `deviation_status` N/A\|SEM_DESVIO\|PENDENTE\|DELIBERADO; no fecho: `closed_at/by`, `z_number`, `sales_count`, `total_sales`, `totals_by_method[] {pm_id,kind,name,transit_account,tpa_code,amount,count}`, `transfers[] {sale_id,doc_number,amount,reference,pm_id}`, `cash_sales`, `cash_expected`, `cash_counted`, `cash_counts{denominação:qtd}`, `deviation`, `tpa_closes[]`, `justification`; na integração: `posting_lans[]`, `posting_journal_id`, `posted_at/by`, `unposted_at/by`; na deliberação: `deliberation {decision,note,amount,date,by,at,account,transit_account,lan_number,journal_id}`, `deliberation_cancelled`. |
| `pos_settlements` | `++id, pos_company_id, session_id, kind, status, [pos_company_id+status]` | `session_id`, `z_number`, `item_key` (`NUM:<pm>` \| `TPA:<pm>` \| `TRF:<sale>:<pm>`), `kind`, `pm_id`, `doc_number`, `reference`, `date`, `amount_gross`, `commission`, `commission_deducted`, `amount_net`, `target` FOLHA_CAIXA\|TESOURARIA, `target_account`, `transit_account`, `commission_account`, `treasury_doc_id`, `commission_doc_id`, `cash_session_id`, `cash_line_id`, `batch_ref`, `status` REGISTADO\|ANULADO, `created_at/by`, `cancelled_at/by`. |
| `pos_settings` | `++id, pos_company_id` | 1 por empresa: `account_surplus`, `account_shortage`, `account_operator`, `deviation_tolerance`, `journal_code` (omissão `GEPOS`), `updated_at/by`. |
| `hotel_stays` (V1248) | `++id, hotel_company_id, terminal_id, room_product_id, status, [hotel_company_id+status]` | ver §2.C.3. |
| `lav_orders` (V1246) | `++id, lav_company_id, order_number, status, customer_id, terminal_id, promised_date, [lav_company_id+status]` | ver §2.D.2 (itens, extras e histórico embebidos em JSON). |
| `lav_payments` | `++id, lav_company_id, order_id, session_id, status` | ver §2.D.2. |
| `lav_claims` | `++id, lav_company_id, order_id, status` | ver §2.D.2. |
| `lav_settings` | `++id, lav_company_id` | ver §2.D.1. |
| `lav_pieces` (V1247) | `++id, lav_company_id, code, is_active, [lav_company_id+code]` | ver §2.D.2. |
| `cash_sessions` (V1235) | `++id, company_id, account_code, operator, open_date, close_date, opening_balance, closing_balance, physical_balance, status, [company_id+status], [company_id+account_code]` | + `currency`. Estados ABERTA\|FECHADA\|CONTABILIZADA. |
| `cash_lines` | `++id, session_id, company_id, type, doc_date, doc_number, reference, third_party_id, product_id, account_debit, account_credit, description, value, bu_id, cc_id, source_type, source_id, is_posted, [session_id+type], [company_id+session_id]…` | + `contra_origem`, `doc_url`, `demo_note_id`, `cashflow_note_id`, `currency`, `exchange_rate(_id)`, `value_kz`, `contra_value_kz/currency/value_currency`. `source_type` manual\|contabilidade\|**pos** (+`source_id`=`pos_settlements.id`). |

### 3.2 Tabelas partilhadas usadas pelo POS (campos relevantes)
- `sales` (`++id, company_id, customer_id, doc_type, doc_number, date, status, is_posted, related_doc_id, [company_id+doc_type], is_master_data`): campos acrescentados pelo POS — `pos_session_id`, `pos_terminal_id`, `pos_terminal_code`, `pos_payments[]` (`{pm_id,kind,name,amount,received,reference,transit_account,settlement_account,tpa_code}`), `pos_change`, `pos_operator`, `payment_method` (texto), `table_name` (mesa/quartos), `hotel_stay_ids[]`, `lav_order_id`, `lav_order_number`, `amount_paid`, `pos_posting_lans[]`, `discount`, `total_gross/net/tax`, campos `fe_*` (regime, tipo, série, número, estabelecimento, system_entry, documento selado, erros, estado), `business_unit_id`, `cost_center_id`, `due_date`. Estados usados: `PAGO` (FR), `PENDENTE` (FT em conta corrente), `ANULADO`/`CANCELADO` (excluídos dos totais).
- `sale_items` (`++id, sale_id, product_id, quantity, billed_qty, delivered_qty, …`): `description`, `unit_price` (com IVA), `tax_rate`, `total`.
- `products`: `code`, `name`, `unit_price` (**IVA incluído**), `tax_rate`, `is_inventory` (true\|1\|'1'), `is_service`, `stock_qty` (stock global), `cost_price`, `average_cost`, `category_id`, `image_base64`, `is_blocked`, `is_master_data`, `account_code` (proveito 61/62), `account_cost` (custo 71/75), `account_inventory`, `account_iva`, `account_iva_liquidado`, `is_room`, `pricePerHour`, `pricePerDay`, `minHours`, `lav_group`, `lav_lead_days`, `lav_requires_quote`, `lav_active`, campos FE `fe_unidade/fe_tipo_operacao/fe_isencao`.
- `product_categories` (nome, `company_id`); `third_parties` (clientes: `name`, `nif`, `address`, `email`, `phone`, `type` CLIENTE\|AMBOS\|…, `account_client`, `account_code`); `warehouses`; `warehouse_stock` (`[warehouse_id+product_id]`, `stock_qty`); `inventory_movements` (`++id, company_id, product_id, warehouse_id, date, type 'SAÍDA', quantity, unit_price, third_party_id, reference, [company_id+product_id]`); `delivery_notes` (`doc_number, date, type 'VENDA'\|'BACK_TO_BACK', entity_id, warehouse_id, status 'CONCLUIDO', related_sale_id`) e `delivery_items` (`delivery_id, product_id, quantity`).
- `journals` (`company_id, code, description`: `GEPOS` «Vendas POS», `CX` «Diário de Caixa», `LO`/`GS`), `journal_lines` (`company_id, journal_id, doc_date, entry_date, created_at, lan_number, reference, doc_number, description, value, type_dc D\|C, account_code, third_party_id, business_unit_id, cost_center_id, demo_note_id, cashflow_note_id, reconciliation_id`, + POS: `source_type` 'POS'\|'POS_DESVIO', `pos_session_id`).
- `treasury_documents` (`company_id, type RECEBIMENTO\|PAGAMENTO, doc_date, account_fin, description, total_value, status PENDENTE\|INTEGRADO\|ANULADO, reference, is_master_data`) e `treasury_items` (`doc_id, account_code, third_party_id, doc_number, description, value, type_dc, business_unit_id, cost_center_id`).
- `payment_methods` (Tesouraria: `company_id, name, account_code, account_type, is_active, is_default, iban, swift, currency`) — usado só como **fonte das contas por omissão** ao criar o terminal (`meiosPadrao`) e para as coordenadas bancárias do talão.
- `chart_of_accounts` (`code`, `description`, `type` 'M' movimento\|'T' totalizadora, `currency`), `sales_accounting_config` (`key`, `account_code`: `iva_vendas`, `clientes_default`, `caixa`, `banco_default`), `business_units`, `cost_centers`, `companies` (`name`, `nif`, `municipality`, `province`), `employees` (RH, para atribuir ordens), `demo_notes`/`cashflow_notes` (notas às demonstrações/fluxo de caixa, `code`, `description`), `fe_config`/`fe_series` (`tipo FT\|FR\|NC, ano, origem 'GERAL'\|'T<id>', origem_nome, estabelecimento, codigo, contingencia, proximo, ultima_data, estado ACTIVA\|FECHADA, agt_codigo, agt_ultimo`), `audit_logs` (via `logMovement`), `recycled_*`.

### 3.3 Chaves `localStorage` (preferências por posto/empresa — **não** persistem na BD)
`pos_active_terminal_<empresa>` (id do terminal); `pos_custom_table_carts` (JSON mesa/quarto→carrinho; global, sem empresa); `pos_custom_tables` (mesas criadas); `pos_custom_rooms` (obsoleto); `pos_layout_mode` (grid\|list); `pos_stock_filter` (all\|in\|out); `pos_hide_images` (true\|false); `pos_device_mode` (pc\|tablet\|mobile); `pos_print_format_<emp>` (thermal\|a4); `pos_print_consultation_behavior_<emp>` (preview\|direct); `pos_print_auto_checkout_<emp>` (yes\|no); `pos_print_footer_msg_<emp>`; `pos_wh_layout_mode`, `pos_wh_stock_filter`; `grelha_zoom_<chave>` (`pos_vendas` 170, `pos_mesas`/`pos_quartos` 140, `pos_armazem` 200); `lav_grelha_zoom|modo|detalhes|vista`; `folhaCaixa.vistaFormulario`; `hotel_migracao_v1`; `contas_defeito_limpas_<emp>`. Legado migrado: `pos_terminals_<emp>`, `pos_register_state_<emp>_<terminal>`, `pos_room_stays`.

### 3.4 Enumerações e constantes
- Meio de pagamento (`TIPOS_MEIO`, `pos_gestao.js:26-30`): `NUMERARIO` «Numerário» (fa-money-bill-wave, #059669, liquidação «Caixa (classe 45) — Folha de Caixa»), `TPA` «Multicaixa (TPA)» (fa-credit-card, #2563eb, «Banco (classe 43) — liquidação do TPA»), `TRANSFERENCIA` «Transferência bancária» (fa-university, #7c3aed, «Banco (classe 43) — por comprovativo»).
- Denominações de contagem (Kz): `[5000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 1]`.
- Decisões de desvio: `SOBRA_PROVEITO` «Sobra levada a proveito», `FALTA_CUSTO` «Falta assumida como custo», `FALTA_OPERADOR` «Falta imputada ao operador», `SEM_EFEITO` «Sem efeito (erro de contagem)».
- Origem de série FE: `GERAL` ou `T<id do terminal>`; tipos AGT `FT`, `FR`, `NC`.
- Contas fixas no código: **62** (proveito de documentos sem linhas), **61/62** (proveito por omissão de inventariável/serviço), **71** (custo, guias de saída do armazém), **26** (inventário), **6621/7621** (câmbio), `31.1` / `34.5.3` (fallbacks de cliente e IVA na lavandaria), `4511001`/`4311001` (fallbacks do checkout antigo), nota às demonstrações **10** (caixa).
- IVA por omissão: 14% (lavandaria); produtos usam `tax_rate`.
- Numeração: ver §3.5.

### 3.5 Séries e numeração
| Documento | Formato | Contador / regra |
|---|---|---|
| FR do POS (com `FacturaAGT`) | `FR <SÉRIE>/<n>`, série por defeito = `<CÓDIGO_TERMINAL>` + ano, ex. `FR T012026/1` (`facturacao_agt.js:192-197,238-241`) | `fe_series` por tipo+ano+origem `T<id>`; 1 série ACTIVA por tipo/ano/origem; `proximo` e `ultima_data` actualizados em transacção; **data nunca anterior à última do documento da série** («A série X já tem um documento com data Y. A data do novo documento não pode ser anterior.») e **nunca futura** («Os documentos fiscais não podem ter data futura (d).»); série criada automaticamente se não existir (dentro do regime); com `exigir_series_agt` só séries com `agt_codigo`; esgotada → erro «A série … esgotou a numeração autorizada pela AGT.»; falha ao gravar → `libertar` repõe `proximo` se for o último. |
| FR/FT/NC fora do regime AGT | mesmo formato `<TIPO> <código>/<n>` via `numerarLocal` (série activa do terminal; senão a série GERAL; sem série → `null`) | não marca `fe_regime` (documento não selado). |
| FR sem séries | `FR-POS/<código>/<ano>/<n 5 dígitos>` | `pos_terminals.counters[ano]`, incremento atómico (`transaction rw`), verificação de duplicado até 50 tentativas. |
| Sessão | `<código>-<ano>-<n 4 dígitos>` | `session_counters[ano]`. |
| Relatório Z | `Z-<código>-<ano>-<n 4 dígitos>` | `z_counters[ano]`. |
| Ordem de serviço | `OS/<código>/<ano>/<n 5>` + etiquetas `<código><n 5>-<seq 2>` | `lav_os_counters[ano]`. |
| FR/FT/RC da lavandaria (sem séries) | `FR-LAV/…`, `FT-LAV/…`, `RC-LAV/<código>/<ano>/<n 5>` | `lav_fr_counters`, `lav_ft_counters`, `lav_rc_counters`. (RC usa sempre este contador — não há série AGT para recibos.) |
| Guia de Saída do balcão de armazém | `GE POS <ano>/<n>` com n = nº total de `delivery_notes` da empresa + 1 | sem atomicidade. |
| Guia de picking | `GE PICK <ano>/<n>` (mesmo contador) | idem. |
| Peça / serviço da lavandaria | `PC0001…` / `SV0001…` | máximo existente + 1. |
| Senhas do monitor | `P-001`… | `_posSenhaCounter` em memória. |
| Lançamentos | `generateGlobalLanNumber(empresa, diário, código, data)` | função global (não lida). |

---

## 4. REGRAS DE NEGÓCIO E CÁLCULOS

Notação: `r2(x) = Math.round((x + EPSILON)*100)/100`. «Preço» do POS é **com IVA incluído**.

| ID | Regra | Fórmula / condição | Evidência |
|---|---|---|---|
| R-001 | Total de linha | `rowGross = (item.price ?? product.unit_price) × qty`; `rowNet = rowGross/(1+tax_rate/100)`; `rowTax = rowGross − rowNet` (sem arredondamento) | `ui_sales.js:6852-6862` |
| R-002 | Desconto global | `pct ∈ [0,100]`; `discount = totalGross × pct/100`; `gross = totalGross − discount`; `net` e `tax` NÃO são reduzidos (quirk) | `:6864-6888`, `:7228-7235` |
| R-003 | Preço editável | `price<0` ou NaN repõe `unit_price`; sem permissão específica no código (a tarefa `pos_desconto` lista `updatePOSCartPrice`) | `:7208-7216`; `permissoes.js:268` |
| R-004 | Limite de stock | inventariável: `qty ≤ stock_qty` (stock global do produto, não o do armazém do terminal); serviços sem limite; bloqueado `is_blocked`; quarto só em hotelaria | `:7152-7206` |
| R-005 | Pagamentos mistos | `pago = Σ valores`; `troco = max(0, pago − total)` abatido só em numerário; TPA+transferência `≤ total`; transferência exige referência; `pago ≥ total − 0.005` | `pos_gestao.js:296-322` |
| R-006 | Documento só com pagamentos coerentes | Σ `pos_payments.amount` = `total_gross` (±0,01) para poder contabilizar | `pos_prestacao.js:113-115` |
| R-007 | Stock na venda POS | `stock_qty = max(0, qty actual − qtd)`; armazém do terminal ‖ `_lastActiveWhId` ‖ 1; movimento `SAÍDA` com `unit_price` = preço de venda (não custo) | `pos_gestao.js:412-423` |
| R-008 | Cliente | obrigatório; Consumidor Final (NIF 99999999/999999999 ou nome ~consumidor) criado se faltar | `ui_sales.js:6526-6548` |
| R-009 | Totais da sessão | `esperadoCaixa = opening_float + Σ numerário líquido de troco`; vendas ANULADO/CANCELADO excluídas; docs de lavandaria entram pelas «fontes» | `pos_gestao.js:230-251` |
| R-010 | Contagem de caixa | `contado = Σ denom × floor(qtd≥0)`; `desvio = r2(contado − esperado)`; tolerância: `|desvio| ≤ tol + 0.004` ⇒ sem desvio (`SEM_DESVIO`) | `:589-606,632,659` |
| R-011 | TPA no fecho | `diferença = r2(talão − sistema)`; qualquer diferença ≥0,005 exige justificação | `:596-604,633` |
| R-012 | Fecho exige justificação | (desvio fora de tolerância ∨ diferença TPA) ∧ justificação vazia ⇒ bloqueia | `:634` |
| R-013 | Z | `Z-<TERM>-<ANO>-NNNN`; sessão sem vendas ⇒ `posting_status`/`settlement_status = SEM_MOVIMENTO` | `:652-662` |
| R-014 | Créditos da contabilização | `factor = total_gross(doc)/Σ item.total`; `brutoLinha = item.total × factor`; `base = brutoLinha/(1+iva/100)`; IVA = `brutoLinha − base` (se >0,0001); conta de proveito = `resolveMatérialAccount(prod, inventariável?'61':'62')`; conta de IVA = `account_iva_liquidado ‖ account_iva ‖ contaIvaPorOmissao` | `pos_prestacao.js:139-157` |
| R-015 | Conta de IVA por omissão | `sales_accounting_config(iva_vendas)` se existir/movimento; senão a mais usada nos serviços; senão nos produtos | `:60-75` |
| R-016 | Débitos da contabilização | numerário e TPA agregados por (transitória, meio) por dia; transferências 1 linha por documento com terceiro, UN, CC | `:116-126,168-172` |
| R-017 | Equilíbrio do lançamento | `|ΣD−ΣC|<0,01` ok; `≤1` ajusta o maior crédito; senão impedimento «desequilibrado» | `:173-182` |
| R-018 | Impedimentos de integração | pagamento sem transitória; doc sem `pos_payments`; Σ≠total; IVA sem conta; conta inexistente no plano; exercício encerrado | `:114-117,133,166,199-204` |
| R-019 | Deliberação de desvio | sobra: D transitória numerário / C `account_surplus`; falta custo: D `account_shortage` / C transitória; falta operador: D `account_operator` / C transitória; valor `|desvio|`; só com sessão contabilizada; fundamentação ≥5 caracteres | `:396-472` |
| R-020 | Valor do numerário a prestar | `amount do meio + deviation` (se deliberação ≠ SEM_EFEITO, uma só vez) | `:501-506` |
| R-021 | Comissão TPA | omissão `r2(bruto × pct/100)` com `bruto = talão ?? sistema`; recebimento = `bruto − comissão` se «deduzida» senão `bruto` + pagamento separado da comissão; `comissão < valor` | `:647-648,756,541-556` |
| R-022 | Estado de prestação | `LIQUIDADA` (todos os itens >0,004 feitos) / `PARCIAL` / `PENDENTE` | `:520-527` |
| R-023 | Numerário em moeda | `caixaFxCamposLinha`: `value_kz = r2(valor × taxa do dia)` se moeda≠AOA | `moedas_tesouraria.js:346-352` |
| R-024 | Série FE por terminal | ver §3.5 | `facturacao_agt.js:198-231` |
| R-025 | IVA da AGT | imposto da linha arredondado por **excesso ao cêntimo** (`excessoCentimo`) no documento selado; talão usa `documentTotals.grossTotal` | `facturacao_agt.js:27-28`; `ui_sales.js:8025-8028` |
| R-026 | CMV do balcão de armazém | `custo = qty × (CMP ‖ unit_price ‖ 0)`; D `resolveMatérialAccount(prod,'71')` / C `account_inventory ‖ …('26')`; CMP = média ponderada de encomendas de compra, depois propostas, depois `cost_price`/`unit_price`, depois movimentos, depois preços de venda | `ui_pos_armazem.js:536-562`; `ui_warehouse.js:1-90` |
| R-027 | Stock do armazém | `updateWarehouseStock(wh, prod, ±qtd)`: soma ao `stock_qty` ou cria a linha; **pode ficar negativo** (sem `max(0)`) | `ui_warehouse.js:1013-1027` |
| R-028 | Picking | só marca a ordem `EM PICKING` ao abrir; expedir exige todas as linhas verificadas; checkbox desactivada se stock local < pedido | `ui_pos_armazem.js:888,976,993` |
| R-029 | Diária hoteleira | saída prevista = dia de entrada + `max(1, round(n))` dias às `hotel_checkout_time` | `hotelaria.js:56-63` |
| R-030 | Hora hoteleira | saída = entrada + n horas; mínimo `minHours`; bloqueada na janela `de–até` (atravessa meia-noite) | `:47-63,331-332` |
| R-031 | Saída tardia | ver §2.C.2 (`propostaTardia`) | `:65-75` |
| R-032 | Totais do check-out | `alojamento = r2(qtd×unit_price)`; `consumos = r2(Σ preço×qtd)`; `bruto`; `total = r2(bruto − bruto×desc/100)`; por factura `gross=r2(bruto×f)`, `net=r2(liquido×f)`, `tax=gross−net`, `discount=bruto−gross`, `f=1−desc/100` | `:497-502,568-583,636-644` |
| R-033 | Repartição de pagamentos por factura | proporcional a `gross/totalGeral`; última recebe o resto; correcção de ≥0,01 no maior | `:651-665` |
| R-034 | Total da ordem de lavandaria | `Σ qty×price` (excl. ANULADA) `+ urgência (pct do total de serviços) + recolha + entrega` | `lavandaria.js:948-959` |
| R-035 | Prazo | `max(lead_days)`; urgente: `max(1, ceil(prazo × urgency_lead_factor))` dias corridos | `:953-956` |
| R-036 | Etiquetas | KG: `max(1, round(pieces))`; peça: `max(1, round(qty))` | `:957,1067` |
| R-037 | Adiantamento do Consumidor Final | mínimo `r2(total × cf_advance_pct/100)` (50%); na entrega o saldo facturado tem de ser pago na totalidade | `:1046-1048,1645` |
| R-038 | FR vs FT | FR se `recebido == total ∧ facturado == pago`; senão FT + recibo | `:289` |
| R-039 | Taxa de armazenagem | por peça PRONTA: `dias = diasDesde(ready_at) − storage_free_days`; `valor = r2(valorItem × pct/100 × dias)` | `:1576-1583` |
| R-040 | Facturar ao receber | `invoice_on_advance ∧ pago+valor > facturado+0,005` ⇒ factura serviços confirmados | `:1085,1540` |
| R-041 | Orçamento aprovado | `price = valor/qty`; só linhas com orçamento aprovado são facturáveis | `:188,1404` |
| R-042 | Distribuição de pagamentos às facturas | mais antigas primeiro, estado PAGO se `pago ≥ total − 0,005` | `:375-385` |
| R-043 | Preço da peça no serviço | específico (>0) da matriz peça×serviço×opção ‖ preço base da opção | `:162-168` |
| R-044 | Contabilização da lavandaria | FR: D transitória; FT: D conta corrente cliente; RC: D transitória / C cliente; créditos 62 e IVA proporcionais | `:2925-3009` |
| R-045 | Indemnização | = valor comprovado; pagamento cria `PAGAMENTO` Tesouraria D conta de indemnizações | `:2041-2087` |
| R-046 | Folha de Caixa | `saldo = abertura + ΣREC − ΣPAG`; só 1 sessão aberta; contabilização por linha (D/C + câmbio 6621/7621) | `ui_folha_caixa.js:141,367,1509-1584` |
| R-047 | Contrapartida por produto | PAG→`account_cost`; REC→`account_code` | `:903-908` |
| R-048 | Exercício encerrado | `isYearClosed(data)` bloqueia integração, desfazer, deliberação, pagamento de dano | `pos_prestacao.js:24,203,440,484`; `lavandaria.js:2080` |
| R-049 | Terminal | código único/empresa; transitórias distintas; liquidação 45 (numerário) / 43 (outros); transitória ≠ liquidação; conta de comissão se pct>0; só contas de movimento | `pos_gestao.js:983-1013` |
| R-050 | Arredondamento do widget | pílulas mostram `fm(valor)` de somas já `r2` por pagamento | `:243-263` |

---

## 5. INTERLIGAÇÕES

Formato: **Evento → dados → validações → gravado de cada lado**.

| ID | Ligação | Evento / acção | Dados que passam e validações | O que fica gravado |
|---|---|---|---|---|
| I-01 | **POS → Vendas/Facturação (FR)** | `finalizePOSPayment` → `criarVendaPOS` | cliente, linhas (produto, qtd, preço c/IVA, `tax_rate`), totais, pagamentos, terminal, sessão; valida sessão ABERTA, cliente, pagamentos | `sales` (FR, `PAGO`, `is_posted=false`) + `sale_items`; **sem tesouraria nem contabilidade** (§1.4); `showPOSSucessoModal` oferece «Imprimir A4/PDF» (`printInvoice`, modelo comum `DocumentoComercial`, `print_docs.js`) |
| I-02 | **POS → Séries / AGT / QR / SAF-T** | idem (numeração) e `selar` | `FacturaAGT.numerar('Factura-Recibo', data, {origem:'T<id>', origemNome:'POS <cod> — <nome>', terminalCodigo})`; regime por data (`fe_config`); série do terminal (§3.5); `selar(saleId)` gera `fe_documento` (JSON `registarFactura › documents[]`), valida (regras AGT, totais E22/E23/E24) | `sales.fe_*`; `FacturaAGTQR.doDocumento` no talão; `COM_ERROS` ⇒ notificação «tem erros para a AGT (ver Vendas › Facturação)»; o SAF-T (`renderSAFTExportTab`, `ui_sales.js:10038+`) lê `sales` com `getSalePaymentSplits` (`:10289`; não lido em detalhe) |
| I-03 | **POS → Stock/Armazém** | venda de produto `is_inventory` | armazém = `terminal.warehouse_id ‖ _lastActiveWhId ‖ 1`; stock global `max(0,…)`; stock do armazém pode ficar <0 | `products.stock_qty`, `warehouse_stock`, `inventory_movements` (`SAÍDA`, `Venda POS: <doc>`, preço de VENDA); **sem guia de remessa, sem lotes/validades, sem CMV** |
| I-04 | **POS Armazém → Stock/Guias/Contabilidade** | «Confirmar Saída e Expedir» / «Finalizar Picking & Expedir» | carrinho/encomenda, armazém, cliente | `delivery_notes` + `delivery_items`, stock global e do armazém, `inventory_movements` (custo CMP), **`journal_lines` D 71… / C 26…** no diário `LO`‖`GS`‖1.º (lançamento imediato sem `lan_number`/`source_type`); encomenda passa `EM PICKING`→`CONCLUIDO`; a guia fica visível em «Guias de Saída» (`delivery_notes`) |
| I-05 | **POS → Contabilidade (integração em massa)** | «Contabilizar N sessão(ões)» | `sales` da sessão, itens, produtos (contas), plano, exercício; impedimentos R-018 | `journal_lines` no diário `GEPOS` (D transitórias / C 61-62 e IVA), `lan_number` por dia, `source_type 'POS'`, `pos_session_id`; `sales.is_posted=true`+`pos_posting_lans`; `pos_sessions.posting_status` |
| I-06 | **POS → Contabilidade (desvios)** | «Registar deliberação» | `pos_settings` contas; transitória do numerário | `journal_lines` D/C (`source_type 'POS_DESVIO'`); `deviation_status DELIBERADO` |
| I-07 | **POS → Folha de Caixa** | «Transferir para a Folha de Caixa» (numerário) | folha ABERTA da empresa; confirmação se a conta ≠ liquidação do terminal; câmbio da folha | `pos_settlements` + `cash_lines` REC (D conta 45 / C transitória, `source_type 'pos'`, `source_id`=liquidação, `is_posted=false`); posteriormente a Folha contabiliza (diário `CX`) |
| I-08 | **POS → Tesouraria** | «Liquidar TPA» / «Registar no banco» | conta bancária 43, comissão, tratamento | `treasury_documents` `RECEBIMENTO` `PENDENTE` (+ `PAGAMENTO` da comissão se «não deduzida») e `treasury_items` (C transitória, D comissão); integração posterior pelo processo normal da Tesouraria (**não lido**); anular só se ainda `PENDENTE` |
| I-09 | **POS → Contas / plano de contas** | editor do terminal, definições, serviços | só contas de movimento (hook `db_v2.js:479-511`); liquidação 45/43; comissões classe 7; sobras 6; faltas 7; operador 3; proveitos lavandaria 62 | campos `transit_account`, `settlement_account`, `commission_account`, `account_*` |
| I-10 | **POS → Terceiros/Clientes** | seleccionar/criar cliente | `showPOSCreateCustomerModal`: nome, NIF (omissão 999999999), morada, e-mail, **conta de cliente classe 31 obrigatória, existente, de movimento** (`ContaTerceiro.classeCorrecta`) | `third_parties` `type CLIENTE`, `account_client`; `finalizePOSPayment` antigo exigia `ContaTerceiro.exigir`, o novo (`pos_gestao`) **não valida a conta do cliente** (ver §8) |
| I-11 | **POS → Produtos/Catálogo/Preços** | grelha de venda | `products` (`unit_price` c/IVA, `tax_rate`, `is_inventory`, `stock_qty`, `is_blocked`, `is_room`, `category_id`, `image_base64`); **não há tabelas de preço**, promoções nem preços por cliente | — |
| I-12 | **POS → Impostos** | cálculo de IVA | só `tax_rate` do produto (0 ⇒ isento); sem motivo de isenção no POS; isenção AGT em `products.fe_isencao`/`fe_config.isencao_padrao` | `sales.total_net/total_tax`, `sale_items.tax_rate`; créditos de IVA por conta |
| I-13 | **POS → Moedas/Câmbios** | só na Folha de Caixa | conta 45 em moeda estrangeira: `Moedas.obterCambio(moeda, data)` | `cash_lines.currency/exchange_rate/value_kz`; diferenças 6621/7621 |
| I-14 | **POS → RH** | lavandaria: atribuir responsável (`employees` activos); operador de caixa = `currentUser`; **sem comissões de vendedor, sem turnos** | — | `lav_orders.assigned_*` |
| I-15 | **POS → Projectos** | — | **não há ligação** (apenas `business_unit_id`/`cost_center_id` do terminal) | UN e CC em `sales`, `journal_lines`, `cash_lines`, `treasury_items` |
| I-16 | **POS → Activos** | hotelaria: quartos são produtos `is_room` (não activos imobilizados); produto `is_asset` é outro conceito | — | — |
| I-17 | **POS → Utilizadores/Permissões/Empresas** | todas as acções | `window.currentUser` (nome/username/id), `activeCompanyId`; permissões por tarefa (§6) com funções envolvidas por guarda | operador em `pos_sessions`, `sales.pos_operator`, `*_by` |
| I-18 | **POS → Auditoria** | — | `logMovement` só no acerto de stock do armazém; **as tabelas POS não são auditadas** (campos `*_company_id`) | `audit_logs` |
| I-19 | **POS → Notificações / Fluxo de Processos** | FE com erros → `Notificacoes.aviso`; Fluxo «POS» e «Lavandaria» (consulta + atalhos) | etapas §2.A.18/§2.D.8; compensação das transitórias por `reconciliation_id` | — (só leitura) |
| I-20 | **POS → Dashboard/BI/Relatórios** | Painel de módulos: «POS — Sessões Abertas / Vendas de hoje», pendências (por contabilizar, desvios, por liquidar), lavandaria (prontas, atrasadas); Relatórios de gestão «POS e Serviços»: Vendas POS, nº vendas, ticket médio, sessões, desvios, ordens, valor, entregas no prazo, estadias, **ocupação, ADR, RevPAR**; Cubo/BI da Folha de Caixa | `ui_painel_modulos.js:850-866`; `relatorios_gestao.js:557-600`; `ui_cubo.js:293` | — |
| I-21 | **Lavandaria → Contabilidade/Tesouraria** | integração da sessão, indemnizações | ver §2.D.7 e §2.D.6 | `journal_lines`, `treasury_documents` PAGAMENTO |
| I-22 | **Hotelaria → Vendas** | check-out | linhas alojamento + consumos | `sales` FR (uma por quarto ou única), `hotel_stays` FECHADA |
| I-23 | **Folha de Caixa → Contabilidade** | «Contabilizar» | notas 10, câmbio | `journal_lines` diário `CX` |
| I-24 | **Notas às demonstrações / fluxo de caixa** | nas linhas da Folha | só na Folha de Caixa (as linhas POS→Folha chegam sem nota; a nota 10 é imposta ao contabilizar a conta 45) | `demo_note_id`, `cashflow_note_id` |

---

## 6. PERMISSÕES, PERFIS E CONFIGURAÇÕES

### 6.1 Catálogo de permissões (`js/permissoes.js`; chave = «tarefa»; consulta do ecrã = `<ecrã>_view`)
Módulo «POS, Lavandaria e Hotelaria» (`permissoes.js:265-302`), marca `(s)` = tarefa sensível:
| Ecrã | Chave | Descrição | Funções protegidas |
|---|---|---|---|
| `pos` Frente de caixa | `pos_venda` | Abrir sessão e registar vendas | `showPOSCheckout`, `openPOSRegister`, `finalizePOSPayment` |
| | `pos_desconto` (s) | Aplicar descontos e alterar preços | `updatePOSDiscount`, `updatePOSCartPrice` |
| | `pos_fecho` (s) | Fechar sessão (Z) | `showPOSCloseRegisterModal`, `posConfirmarFecho` |
| `pos_relatorios` | — | Relatórios POS (só consulta) | |
| `pos_terminais` | `pos_terminais_gerir` (s) | Configurar terminais, meios e definições de caixa | `posGravarTerminal`, `posConfirmarCopiaMeios`, `posAlternarTerminal`, `deletePOSTerminal`, `posGravarDefinicoes` |
| `pos_config_print` | `pos_print_config` | Gravar configuração de impressão | `savePOSPrintConfig` |
| `pos_integracao` | `pos_integrar` (s) | Integrar sessões na contabilidade | `posConfirmarIntegracao`, `posGravarIvaEmFalta` |
| | `pos_descontabilizar` (s) | Descontabilizar sessões | `posDescontabilizarSessao` |
| `pos_desvios` | `pos_desvio_deliberar` (s) | Deliberar e anular deliberações | `posConfirmarDeliberacao`, `posAnularDeliberacao` |
| `pos_prestacao` | `pos_prestar` (s) | Liquidar numerário, transferências e TPA | `posLiquidarItem`, `posConfirmarTransferencia`, `posLiquidarTodasTransferencias`, `posConfirmarTPA` |
| | `pos_prestacao_anular` (s) | Anular liquidações | `posAnularLiquidacao` |
| `pos_lavandaria` | `lav_ordens` | Registar ordens, orçamentos, estados, entregas e facturar | `lavGravarOrdem`, `lavItemAccao`, `lavAccaoTodos`, `lavConfirmarOrcamento`, `lavConfirmarOrcMassa`, `lavConfirmarMaterial`, `lavConfirmarDano`, `lavConfirmarEstado`, `lavConfirmarAtribuicao`, `lavFacturarPendente`, `lavConfirmarEntrega` |
| | `lav_receber` (s) | Receber pagamentos | `lavConfirmarPagamento` |
| | `lav_anular` (s) | Anular ordens | `lavAnularOrdem` |
| | `lav_tabelas` (s) | Tabelas de peças/serviços, importação, definições | `lavGravarPeca`, `lavGravarServico`, `lavConfirmarImportacao`, `lavGravarDefinicoes` |
| | `lav_dano_decidir` (s) | Decidir indemnizações | `lavGravarDecisaoDano` |
| | `lav_dano_pagar` (s) | Pagar indemnizações | `lavConfirmarPagarDano` |
| `pos_hotelaria` | `hotel_estadias` | Check-in e alteração de estadias | `hotelConfirmarCheckin`, `hotelGravarAlteracao` |
| | `hotel_checkout` | Check-out e pagamento | `showPOSHotelCheckout`, `finalizePOSHotelPayment` |
| | `hotel_anular` (s) | Anular estadias | `hotelAnularEstadia` |
| | `hotel_quartos` (s) | Criar e editar quartos | `showCreatePOSRoomModal`, `showEditPOSRoomModal`, `saveNewPOSRoom`, `saveEditPOSRoom` (**funções obsoletas** — os quartos são hoje produtos) |
| `pos_armazem` (módulo Armazém) | `pos_armazem_vender` | Vender ao balcão | `finalizePOSWhSale` |
| | `pos_armazem_picking` | Iniciar picking | `startOrderPicking` |
| | `pos_armazem_expedir` (s) | Confirmar expedição | `finalizeOrderPicking` |
| | `pos_armazem_acerto` (s) | Acertar stock do armazém | `posWhAplicarAcerto` |
| `teso_folha_caixa` | `teso_caixa_operar` | Abrir sessão e registar movimentos | `showAbrirSessaoForm`, `showRegistarLinhaCaixa`, `showPagarFaturas`, `showReceberFaturas`, `showImportFolhaCaixaModal`, `atribuirNotasLinhasCaixa`, `confirmarAbrirSessao`, `confirmarLinhaCaixa`, `confirmarPagamentoFaturas`, `confirmarRecebimentoFaturas`, `handleFolhaCaixaImport`, `eliminarLinhaCaixa` |
| | `teso_caixa_fechar` (s) | Fechar sessão (contagem) | `fecharSessaoCaixa` |
| | `teso_caixa_contabilizar` (s) | Contabilizar sessão | `contabilizarSessaoCaixa` |
| | `teso_caixa_eliminar` (s) | Eliminar sessão fechada | `eliminarSessaoCaixa` |
| Vendas | `vendas_clientes_gerir` | inclui `showPOSCreateCustomerModal`, `createPOSCustomer` | |
Separadores de `renderPOS` → ecrã (`permissoes.js:741`): `dashboard→pos`, `relatorios→pos_relatorios`, `terminais→pos_terminais`, `config_print→pos_config_print`, `pos_integracao`, `pos_desvios`, `pos_prestacao`, `lav_config→pos_lavandaria`. Perfil novo (`permissions._v2`): só vale o marcado; perfil antigo usa `pos_venda/pos_desconto/pos_anular` do `app_v2.js:3405-3409` (a chave `pos_anular` «Anular/Cancelar Talões no POS» **não tem função correspondente** no POS actual). Segregações assinaladas ao gravar perfil: `pos_venda`+`pos_desvio_deliberar` («Vende no POS e delibera os desvios de caixa.»), `pos_venda`+`pos_prestar` (`permissoes.js:586-587`).
### 6.2 Perfis-modelo
- **«Operador POS / Lavandaria»**: ver `pos, pos_relatorios, pos_lavandaria, pos_hotelaria`; tarefas `pos_venda, pos_fecho, lav_ordens, lav_receber, hotel_estadias, hotel_checkout` (`:611-612`).
- **Tesoureiro**: ver `pos_prestacao` (+ Tesouraria); tarefa `pos_prestar`. **Contabilista**: ver `pos_integracao`. **Direcção/Aprovador**: `pos_desvio_deliberar`, `lav_dano_decidir`. **Operador da Folha de Caixa**: ver `teso_folha_caixa`; tarefas `teso_caixa_operar, teso_caixa_fechar`. **Armazém**: `pos_armazem_vender, pos_armazem_picking, pos_armazem_expedir`.
### 6.3 Configurações editáveis e valores predefinidos
- **Por empresa (BD)**: `pos_settings` (contas sobras/faltas/operador; tolerância 0; diário `GEPOS`), `lav_settings` (§2.D.1), meios de pagamento por terminal (`pos_terminals.payment_methods`), regras hoteleiras por terminal (14:00/12:00/60 min/bloqueio 21:00–08:00), séries de facturação (`fe_series`), `fe_config`.
- **Por posto/empresa (localStorage)**: impressão (formato talão 78 mm, comportamento da consulta, auto-impressão, rodapé), terminal activo, layout/zoom/filtros.
- **Seeds**: terminal `T01` «Terminal Principal» tipo loja (migração do `T_DEFAULT`); meios Numerário/Multicaixa (TPA)/Transferência bancária (sem contas); cliente «Consumidor Final» NIF 99999999; mesas `Balcao, Mesa 1-8, Terrasso 1-2, Take-Away`; produtos extras `LAV-URG/ARM/REC/ENT`; diário `GEPOS` «Vendas POS» e `CX` «Diário de Caixa» criados à primeira utilização. **Nenhum quarto de exemplo** é criado.

---

## 7. UX DO LEGADO A REPRODUZIR

Descrição a partir do HTML/CSS (não foi possível ver o browser).
1. **Modo imersivo**: ao «Iniciar Ponto de Venda» o `body` ganha `pos-exclusive-mode` (`css/styles.css:2431-2455`): esconde a barra lateral, o cabeçalho e o botão «voltar»; fundo `#0f172a`; cabeçalho próprio 60 px com título, selector **PC / Tablet / Mobile** (PC = ecrã inteiro; Tablet = moldura 1024×768 com borda 16 px `#1e293b` e raio 28; Mobile = 412×844 raio 36) e «Voltar ao Office ERP». O selector é um emulador: guarda `pos_device_mode`.
2. **Frente de caixa**: duas colunas `420px | 1fr` (`.pos-main-layout`): carrinho à esquerda (fundo branco, cabeçalho cinzento claro, itens com preço editável e `− qtd +`, rodapé com Desconto %, subtotal/IVA/total em azul 1.4rem, botão «Checkout» azul) e catálogo à direita (pesquisa arredondada 46 px com lupa, grupo Todos/Com Stock/Sem Stock, «Ocultar Imagens», alternador grelha/lista, **barra horizontal de categorias** com contagem, grelha de cartões 170 px). Topo: crachás arredondados por modo (azul loja, verde restaurante, laranja hotelaria), terminal em roxo, pílulas de saldo, «Fechar Caixa» vermelho. Cores de estado: livre cinzento/verde, ocupado laranja `#f59e0b`, atrasado vermelho; stock verde (>5), amarelo (<5), vermelho (0); modais com sobreposição `rgba(0,0,0,0.6)`, cantos 4–16 px, `z-index 9999–10001`.
3. **Foco/teclado**: não há atalhos de teclado nem leitor de código de barras dedicado; o campo de pesquisa **não tem autofocus**, e **não** adiciona o artigo ao carrinho com Enter. Foco automático em: nome da mesa/terminal/cliente (`setTimeout 100 ms`), estado da peça (lavandaria, 60 ms), pesquisa da grelha (restaura o foco após redesenhar). Enter só confirma na pesquisa de ordens da lavandaria. O teclado numérico é o do browser (`type=number`; sem teclado virtual).
4. **Drag & drop** do produto para o carrinho (zona tracejada azul ao arrastar).
5. **Pagamentos**: uma linha por meio com «Tudo»; o primeiro meio abre com o total; o troco só aparece em verde quando `pago ≥ total`, «Falta X» a vermelho; erros em texto vermelho por baixo.
6. **Talões**: Courier New, 78 mm, linhas tracejadas; abrem em nova janela e imprimem (`onload print/close`) — o novo sistema deve manter impressão térmica 78/80 mm e A4.
7. **Mapa de mesas/quartos**: grelha de cartões ajustável (`GrelhaZoom`: botões −/cursor/+ guardados por posto), cartão activo com borda azul.
8. **Lavandaria**: ecrã em 2 colunas (condições à esquerda, resumo + catálogo fixos à direita, lista de peças por baixo); cartões de peças com ícone `fa-tshirt`/`fa-soap`/`fa-cut` (cores #0891b2 lavandaria, #7c3aed alfaiataria); estados com crachás coloridos; linhas vermelhas quando falta estado à entrada.
9. **Monitor de senhas**: ecrã escuro 24 px de raio, senha atual 5.5rem azul que pulsa (`scale 1→1.05`, 1 s) e muda para verde 1,2 s ao chamar; voz pt-PT.
10. Ícones Font Awesome 5.15; fonte «Outfit»/«Inter»; todas as mensagens em pt-PT/AO (Kz, DD/MM/AAAA).

---

## 8. CASOS LIMITE E CURIOSIDADES DO LEGADO

**A preservar (regras)**
1. Venda POS **nunca** gera contabilidade nem tesouraria directamente; contabilização individual bloqueada (`postDocumentToAccounting`, `_executeSalePosting`).
2. Troco abatido só ao numerário; TPA/transferência não podem exceder o total.
3. Fecho: desvios só dentro da tolerância dispensam justificação; `deviation_status PENDENTE` bloqueia a transferência do numerário.
4. Deliberação só com sessão contabilizada; anular deliberação/liquidação/contabilização tem a ordem inversa (liquidação → deliberação → integração).
5. Cada meio com transitória própria e distinta; copiar meios reaproveita os `id` do mesmo tipo.
6. Estado à entrada obrigatório na lavandaria; descrição do dano obrigatória; consumidor final com adiantamento mínimo.
7. Série FR por terminal; numeração sem datas retroactivas; `libertar` em caso de falha.
8. Check-in duplicado impedido na BD; consumos do quarto gravados com debounce de 250 ms; cada gravação confirma que a estadia continua ABERTA.
9. Um único alerta de totalizadora por 1,5 s.

**Bugs aparentes / curiosidades (marcar e decidir)**
- BUG? `printPOSThermalReceipt` e consultas imprimem sempre «Operador: CAIXA POS» (não o operador real); «Talão nº» mostra o `doc_number`.
- BUG? `border-collapose: collapose` (typo) em vários CSS inline; `Serviao`, `Endereao`, `Logastico`, `Terrasso`, `disponivel`, `sumário/Diviso` (acentuação corrompida pela codificação). As mesas por omissão têm nomes com `???`/`?` (emojis perdidos), e `saveNewPOSTable` acrescenta « ???» a mesas sem emoji.
- BUG? `renderPOSPickingTab` mostra `s.total_value` que `sales` não tem (aparece 0/NaN).
- BUG? Contador de guias `GE POS/GE PICK` por contagem total ⇒ colisões possíveis; `inventory_movements.date` ISO completo no POS de Armazém vs `AAAA-MM-DD` nos outros.
- BUG? `updatePOSTotals`: `net`/`tax` não reflectem o desconto (R-002); talão e `sales.total_net/total_tax` ficam inconsistentes com `total_gross` quando há desconto (a contabilização corrige-o por proporção).
- BUG? `renderPOSConfigPrintTab` termina com `renderVendas('pos_config_print')` (aba inexistente de Vendas).
- BUG? `pos_anular` (perfil antigo) sem implementação; o POS **não tem anulação/estorno/devolução de venda**, nem gorjeta, nem caixa/sangria, nem fundo de maneio intermédio, nem pré-fatura de mesa («Consulta» é só impressão), nem transferência de mesa, nem divisão de conta.
- BUG? A venda POS já não valida a conta de cliente (classe 31) como o checkout antigo; o cliente «Consumidor Final» criado automaticamente fica sem conta.
- BUG? Stock do POS comercial é validado contra o **stock global** do produto, não contra o do armazém do terminal; o armazém pode ficar negativo.
- BUG? Em lavandaria, `totaisSessao` exclui `sales` com `lav_order_id`, mas a «fonte» devolve as facturas com `total_gross 0`: os documentos de lavandaria contam-se no Z como «documentos» sem valor (as FR valem pelos recibos `kind FR`).
- Curiosidade: o aviso de sessões abertas há dias só olha para a data de abertura; o POS não fecha sozinho a caixa.
- Curiosidade: o tipo de terminal pode mudar depois de existirem sessões; `lavandaria` com caixa aberta ignora a frente normal.
- Curiosidade: a Folha de Caixa em moeda estrangeira recebe do POS um valor Kz tratado como valor na moeda da folha (a confirmar).
- Curiosidade: `hotel_quartos` (permissão) e as funções de quartos `showCreatePOSRoomModal…` existem mas já não alimentam o mapa (quartos = produtos `is_room`).
- Curiosidade: scripts `js/scratch/fix_pos_payments.js` é um *patcher* único nunca incorporado.
- Máquina: estado das senhas e dos carrinhos de armazém é só em memória.

---

## 9. LISTA DE FUNCIONALIDADES ATÓMICAS (checklist de paridade)

Cada item é identificável e testável; as regras de cálculo correspondentes estão em §4 (R-xxx) e os detalhes por ecrã em §2.

**A. POS comercial — acesso e abertura**

- F-001 Vista «Ponto de Venda (POS)» com 7 separadores (Frente de Caixa, relatórios POS, Terminais POS, Config. Impressão, Integração contabilística, Desvios de caixa, Prestação de contas) + «Lavandaria»
- F-002 Cartão «Frente de Caixa Imersiva» com botão «Iniciar Ponto de Venda (POS)»
- F-003 Modo imersivo (esconde menu/cabeçalho) com saída «Voltar ao Office ERP»
- F-004 Aviso de saída com caixa aberta («A caixa X continua aberta…»)
- F-005 Selector de dispositivo PC/Tablet/Mobile (emulador) persistido
- F-006 Selecção do terminal no ecrã de abertura (terminais activos por código)
- F-007 Trocar terminal activo (modal «Trocar Terminal POS»)
- F-008 Fundo de maneio pré-preenchido com o `default_float` do terminal
- F-009 Bloco informativo na abertura (operador, série, meios de pagamento)
- F-010 Bloqueio da abertura sem terminal/meios com conta transitória
- F-011 Abertura de caixa: valida fundo ≥0 e sessão já aberta no terminal (multi-posto)
- F-012 Abertura de caixa cria sessão `<TERM>-<ANO>-NNNN` com contador atómico
- F-013 Aviso de sessões abertas de dias anteriores
- F-014 Terminal activo guardado por empresa/posto
- F-015 Migração automática de terminais antigos do localStorage (T01, T02…)

**A. POS comercial — frente de venda**

- F-016 Crachá de modo (Loja/Restaurante/Hotelaria) e crachá do terminal
- F-017 Widget de saldo da sessão (Fundo, cada meio, Vendas)
- F-018 Botão «Relatório X» com informação da sessão (#código, operador)
- F-019 Selector de cliente (Select2) com «Nome (NIF)»
- F-020 Cliente por omissão «Consumidor Final» (NIF 99999999) criado automaticamente
- F-021 Criar cliente rápido no POS (nome, NIF, morada, e-mail, conta classe 31 obrigatória)
- F-022 Validação da conta do cliente (classe 31, existe no plano, não totalizadora)
- F-023 Pesquisa de produtos por nome ou código
- F-024 Filtro por categoria com contagem e ícone por nome de categoria
- F-025 Filtro de stock Todos/Com Stock/Sem Stock (persistente)
- F-026 Alternar vista grelha/lista (persistente)
- F-027 Zoom dos cartões da grelha (pos_vendas)
- F-028 Ocultar imagens (persistente)
- F-029 Cartão de produto (imagem/ícone, nome, código, preço, crachá de stock/serviço/bloqueado)
- F-030 Produtos bloqueados ou sem stock não clicáveis/arrastáveis
- F-031 Adicionar ao carrinho por clique
- F-032 Adicionar ao carrinho por arrastar e largar
- F-033 Bloqueio de produto bloqueado («esta bloqueado para venda»)
- F-034 Bloqueio de produto inventariável sem stock («fora de stock»)
- F-035 Limite de quantidade pelo stock global (adicionar/+/campo)
- F-036 Quantidade ≤0 remove a linha
- F-037 Preço unitário editável por linha (validação ≥0)
- F-038 Remover linha / Limpar carrinho
- F-039 Desconto global em % (0–100)
- F-040 Resumo Subtotal Líquido / IVA / Desconto / Total Geral
- F-041 Produtos quarto escondidos fora de terminais hotelaria
- F-042 Carrinho vazio com mensagem

**A. POS comercial — restaurante**

- F-043 Mapa de Mesas com estado Livre/Ocupado e total em aberto
- F-044 Mesas por omissão (Balcao, Mesa 1–8, Terrasso 1–2, Take-Away)
- F-045 Criar mesa (nome único, persistência local)
- F-046 Carrinho por mesa persistido em localStorage
- F-047 Trocar de mesa guarda e restaura carrinhos
- F-048 Suspender mesa («Conta Suspensa»)
- F-049 Consulta de mesa (conta provisória não fiscal) com pré-visualização ou impressão directa
- F-050 Mesa registada em `sales.table_name`

**A. POS comercial — checkout e emissão**

- F-051 Modal «Finalizar venda» com total e identificação da sessão
- F-052 Um campo por meio de pagamento activo do terminal
- F-053 Botão «Tudo» por meio
- F-054 Referência de TPA (opcional) e de transferência (obrigatória)
- F-055 Pagamentos mistos (numerário + TPA + transferência)
- F-056 Cálculo de troco só em numerário
- F-057 Validação «Falta cobrar X»
- F-058 Validação «TPA/transferências não podem exceder o total»
- F-059 Validação de valores negativos
- F-060 Checkout bloqueado com carrinho vazio / caixa fechada / sem meios
- F-061 Revalidação da sessão ABERTA na BD antes de emitir (multi-posto)
- F-062 Emissão da Factura-Recibo com série do terminal (AGT/local/FR-POS)
- F-063 Libertação do número se a gravação falhar
- F-064 Gravação de `sales` com pagamentos, troco, operador, terminal, sessão, UN, CC
- F-065 Gravação de `sale_items` (qtd, preço c/IVA, IVA, total)
- F-066 Baixa de stock global e do armazém do terminal
- F-067 Movimento de inventário SAÍDA (referência «Venda POS: <doc>»)
- F-068 Selagem FE e aviso de erros AGT
- F-069 Impressão automática configurável (talão térmico ou A4)
- F-070 Talão térmico 78 mm (empresa, NIF, cliente, linhas, totais, meios, troco, QR AGT, rodapé, coordenadas bancárias)
- F-071 QR code AGT no talão de documentos selados
- F-072 Modal de sucesso com «Imprimir A4» e «Descarregar PDF»
- F-073 Limpeza do carrinho/mesa após venda
- F-074 Contabilização individual de documentos POS bloqueada

**A. POS comercial — fecho de caixa**

- F-075 Relatório X (parcial) impresso sem fechar
- F-076 Modal de fecho com vendas por meio de pagamento
- F-077 Contagem de numerário por 11 denominações
- F-078 Cálculo de esperado/contado/desvio com tolerância
- F-079 Fecho do TPA (valor do talão, n.º de operações, lote, diferença)
- F-080 Lista de transferências recebidas (comprovativos)
- F-081 Justificação obrigatória com desvio/diferença TPA
- F-082 Confirmação de talão TPA em falta
- F-083 Confirmação final do fecho
- F-084 Relatório Z numerado por terminal e ano
- F-085 Estados de lançamento/prestação (PENDENTE ou SEM_MOVIMENTO)
- F-086 Impressão do Relatório Z 78 mm
- F-087 Reimpressão do Relatório Z
- F-088 Sessão fechada deixa de aceitar vendas

**A. Terminais e definições**

- F-089 Lista de terminais (código, nome/tipo/série, meios, estado da caixa)
- F-090 Criar/editar terminal (código, nome, tipo, fundo, armazém, cliente, UN, CC)
- F-091 Código do terminal bloqueado após emitir documentos
- F-092 Regras de hotelaria no terminal (check-in/out, tolerância, bloqueio horário)
- F-093 Meios de pagamento por terminal (Numerário, TPA, Transferência)
- F-094 Conta transitória e de liquidação por meio com filtros 45/43
- F-095 TPA: n.º do terminal, conta e taxa de comissão, tratamento da comissão
- F-096 Adicionar/remover/activar meio
- F-097 Copiar meios de outro terminal (mesma ou outra empresa; substituir/acrescentar)
- F-098 Validação das contas dos meios (existência, movimento, classes, distintas)
- F-099 Desactivar/activar terminal (bloqueio com sessão aberta)
- F-100 Definições de caixa (contas de sobras/faltas/operador, tolerância, diário GEPOS)

**A. Relatórios, impressão e fluxos do POS**

- F-101 Relatórios POS: KPIs (facturação, documentos, ticket médio, desvios, sessões abertas, desvios por deliberar)
- F-102 Relatórios POS: tabela por meio de pagamento
- F-103 Relatórios POS: lista de fechos Z com estado e reimpressão
- F-104 Config. Impressão (formato, consulta, auto-impressão, rodapé)
- F-105 Fluxo de Processos POS (6 etapas por sessão, KPIs, compensação das transitórias)
- F-106 Painel de módulos: pendências e vendas do dia do POS
- F-107 Relatórios de gestão «POS e Serviços»

**A. Integração contabilística**

- F-108 Lista de sessões fechadas por contabilizar e contabilizadas
- F-109 Pré-visualização de lançamentos por sessão/dia
- F-110 Gerar lançamentos D transitórias / C proveitos e IVA por dia
- F-111 Resolução da conta de proveito (61/62) e de IVA por produto
- F-112 Conta de IVA por omissão e botão «Gravar … nesses serviços»
- F-113 Impedimentos de integração (pagamentos, contas, exercício, equilíbrio)
- F-114 Ajuste de arredondamento ≤1
- F-115 Diário GEPOS criado automaticamente
- F-116 Contabilizar em massa com resultados por sessão
- F-117 Marcação das vendas como contabilizadas
- F-118 Descontabilizar sessão (com bloqueios)

**A. Desvios de caixa**

- F-119 Lista de desvios por deliberar e deliberados
- F-120 Deliberar sobra (proveito/sem efeito)
- F-121 Deliberar falta (custo/operador/sem efeito)
- F-122 Lançamento do desvio D/C com conta do numerário
- F-123 Fundamentação ≥5 caracteres e data do lançamento
- F-124 Anular deliberação (com bloqueios)

**A. Prestação de contas**

- F-125 Lista de itens a regularizar por sessão (numerário, TPA, transferências)
- F-126 Estado da Folha de Caixa aberta
- F-127 Transferir numerário para a Folha de Caixa (linha REC)
- F-128 Ajuste do numerário com o desvio deliberado
- F-129 Liquidar TPA com data-valor, banco, comissão e tratamento
- F-130 Recebimento de Tesouraria pendente por TPA/transferência
- F-131 Pagamento separado da comissão não deduzida
- F-132 Registar transferência por comprovativo
- F-133 Registar todas as transferências de uma sessão
- F-134 Anular liquidação (bloqueios por integração)
- F-135 Histórico de movimentos de prestação
- F-136 Estado da sessão LIQUIDADA/PARCIAL/PENDENTE

**B. POS de Armazém**

- F-137 Separadores Atendimento ao Balcão / Fila de Picking / Monitor de Senhas
- F-138 Aviso «Nenhum Armazém Configurado»
- F-139 Selector de armazém
- F-140 Catálogo de produtos inventariáveis com stock do armazém
- F-141 Filtro Todos/Com Stock/Sem Stock, pesquisa, grelha/lista, zoom
- F-142 Etiquetas Esgotado/Baixo/disponível
- F-143 Carrinho de picking com limite pelo stock do armazém
- F-144 Selecção de cliente de balcão (opcional)
- F-145 Aviso de diferenças stock global vs armazéns
- F-146 Acertar stock do armazém (sem movimentos)
- F-147 Confirmar Saída e Expedir (Guia de Saída GE POS)
- F-148 Baixa de stock global e do armazém e movimento SAÍDA a custo médio
- F-149 Lançamento contabilístico D 71 / C 26 do custo
- F-150 Impressão da Guia de Saída A4
- F-151 Impressão do talão de levantamento/picking
- F-152 Síntese de voz da conclusão
- F-153 Fila de picking de encomendas (Pendente/EM PICKING)
- F-154 Iniciar picking (marca EM PICKING)
- F-155 Checklist de recolha com armazém de saída e stock local
- F-156 Bloqueio de verificação sem stock
- F-157 Finalizar Picking & Expedir (guia GE PICK, stock, movimentos, custo)
- F-158 Pausar picking
- F-159 Tirar nova senha (P-001…)
- F-160 Chamar próxima senha (voz, ecrã grande, histórico de 8)

**C. Hotelaria**

- F-161 Mapa de quartos (produtos is_room) com livre/ocupado/atrasado
- F-162 Barra livres/ocupados/atrasados e botão Balcão (venda directa)
- F-163 Check-in (hóspede, modalidade, n.º hóspedes, entrada, quantidade, preço, observações)
- F-164 Diária e venda à hora (mínimo de horas)
- F-165 Bloqueio horário da venda à hora
- F-166 Saída prevista calculada (diária 12:00 / horas)
- F-167 Aviso de check-in antecipado
- F-168 Impedir check-in duplicado do mesmo quarto
- F-169 Estadia na BD com histórico
- F-170 Consumos do quarto (carrinho persistido com debounce)
- F-171 Bloqueio de lançar consumos sem check-in
- F-172 Alterar estadia com registo no histórico
- F-173 Anular check-in (sem consumos, com motivo)
- F-174 Check-out de um ou vários quartos
- F-175 Saída tardia com proposta de recálculo (recalcular/manter)
- F-176 Desconto percentual no check-out
- F-177 Factura por quarto ou factura única
- F-178 Repartição proporcional dos pagamentos
- F-179 Emissão FR com alojamento + consumos
- F-180 Fecho da estadia com dados da factura
- F-181 Talão único do check-out

**D. Lavandaria e alfaiataria**

- F-182 Terminal tipo lavandaria com ecrã próprio
- F-183 Tabela de peças com opções de preço (peça/kg/tamanho)
- F-184 Preços por serviço×opção
- F-185 Tabela de serviços (grupo, conta 62, IVA, prazo, orçamento, activo)
- F-186 Códigos automáticos PC/SV
- F-187 Importação Excel de peças, serviços e preços (com pré-visualização)
- F-188 Modelos Excel de importação
- F-189 Definições da lavandaria (armazenagem, urgência, taxas, adiantamento, contas, IVA)
- F-190 Nova ordem: cliente, facturação (recepção/entrega), urgência
- F-191 Recolha e entrega ao domicílio (morada, data, taxa)
- F-192 Catálogo em grelha/lista de peças e serviços
- F-193 Escolher peça→serviço ou serviço→peça
- F-194 Estado da peça à entrada obrigatório
- F-195 Descrição do dano obrigatória
- F-196 Cobrança por kg com n.º de peças/etiquetas
- F-197 Linhas com orçamento (estimativa)
- F-198 Cálculo da ordem (serviços, urgência, recolha, entrega)
- F-199 Prazo prometido e prazo urgente
- F-200 Adiantamento obrigatório do Consumidor Final
- F-201 Pagamento na recepção com pagamentos mistos
- F-202 Registo da ordem OS/<TERM>/<ANO>/<n>
- F-203 Etiquetas por peça
- F-204 Impressão OS (2 vias com condições)
- F-205 Impressão de etiquetas 56×30 mm
- F-206 Lista de ordens com KPIs, filtros e pesquisa
- F-207 Selecção em massa (atribuir, alterar estado, orçamentos)
- F-208 Detalhe da ordem (peças, documentos, recebimentos, danos, histórico)
- F-209 Iniciar execução (executado por)
- F-210 Marcar pronta
- F-211 Iniciar/Pronto em todas as peças
- F-212 Aprovar orçamento (valor, canal, notas)
- F-213 Recusar orçamento
- F-214 Aprovação/recusa em massa de orçamentos
- F-215 Materiais da alfaiataria abatidos ao stock
- F-216 Registar dano/reclamação
- F-217 Alterar estado (individual/massa)
- F-218 Atribuir responsável (RH) com carga
- F-219 Anular ordem
- F-220 Mensagem ao cliente (WhatsApp/copiar)
- F-221 Emitir factura de linhas por facturar
- F-222 Receber pagamento (adiantamento/pagamento) com recibo RC
- F-223 Entrega de peças com taxa de armazenagem
- F-224 FR (venda directa) vs FT (conta corrente) automático
- F-225 Distribuição de recebimentos pelas facturas
- F-226 Indicadores de prazo (atraso, execução, espera)
- F-227 Carga e desempenho por responsável
- F-228 Danos e reclamações: comprovativo, decisão, pagamento (Tesouraria)
- F-229 Relatórios da lavandaria (período, terminal) com Excel e impressão
- F-230 Integração contabilística da lavandaria (FR/FT/RC)
- F-231 Fonte da sessão para o Z da lavandaria
- F-232 Fluxo de Processos Lavandaria (6 etapas)

**E. Folha de Caixa**

- F-233 Histórico de sessões com entradas/saídas/saldo/estado
- F-234 Abrir sessão (conta 45, data, operador, saldo inicial da última sessão)
- F-235 Uma só sessão aberta por empresa
- F-236 Registar entrada/saída (vista grade e vista linhas)
- F-237 Contrapartida pelo produto (custo nas saídas, proveito nas entradas)
- F-238 Colar do Excel, Importar Excel
- F-239 Pagar faturas de fornecedor em aberto
- F-240 Receber faturas de cliente em aberto
- F-241 Notas às demonstrações e de fluxo de caixa por linha e em massa
- F-242 Câmbio da conta de caixa e diferenças cambiais
- F-243 Conferir contrapartidas das saídas
- F-244 Remover linha não integrada
- F-245 Fechar sessão com contagem física e diferença
- F-246 Contabilizar sessão (diário CX, nota 10, câmbio)
- F-247 Eliminar sessão fechada; ver detalhes; imprimir

---

## 10. ÍNDICE DE FICHEIROS-FONTE LIDOS (linhas lidas)

Todos os caminhos relativos a `v_boss/payroll_system_web/`. «Lido» = lido por blocos com `Read`; «Amostragem» = lido por pesquisa/linhas isoladas; «Não lido» = fora do âmbito ou obsoleto.

| Ficheiro | Linhas totais | Linhas lidas | Nota |
|---|---:|---:|---|
| `js/pos_gestao.js` | 1155 | 1155 | integral |
| `js/pos_prestacao.js` | 826 | 826 | integral |
| `js/ui_pos_armazem.js` | 1257 | 1257 | integral |
| `js/hotelaria.js` | 706 | 706 | integral |
| `js/lavandaria.js` | 3031 | 3031 | integral |
| `js/fluxo_lavandaria.js` | 306 | 306 | integral |
| `js/ui_folha_caixa.js` | 2020 | ~1590 | lido 1-1029 e 1240-1610 integralmente; **1030-1240** (listas de faturas em aberto: só o início de `showPagarFaturas` e `confirmarPagamentoFaturas`) e **1610-2020** (impressão A4, `_getOpenInvoicesFromAccounting` lido, importação Excel só por pesquisa de mensagens/colunas) por amostragem |
| `js/scratch/fix_pos_payments.js` | 413 | 120 + pesquisa | script *patcher* único; restante inspeccionado por `grep` (sem lógica adicional) |
| `js/ui_sales.js` (10730) — partes POS | 10730 | ~2500 | lidos: 120-380 (renderPOS, sucesso), 2385-2425 (ficha produto quarto), 6295-7690 (estado, ecrã, carrinho, totais, checkout antigo), 8000-8900 (talão, consulta, mesa, terminais antigos, cliente), 9116-9196 e 9340-9415 (Z antigo, modo imersivo), 9930-10040 (config. impressão). **Não lidos por estarem obsoletos/superados:** 7685-8000 (checkout hoteleiro antigo), 8900-9116 e 9196-9340 (fecho/Z antigo), 9412-9930 (quartos/terminais/painel de estadia antigos) |
| `js/ui_warehouse.js` | 2240 | ~100 | `calculateAverageCosts` (1-90), `updateWarehouseStock`, `armazemPorOmissao` (1005-1040); restante não lido (fora do POS) |
| `js/moedas_tesouraria.js` | 435 | ~180 | 300-435 (Folha de Caixa/câmbio) |
| `js/facturacao_agt.js` | 572 | ~145 | cabeçalho e `numerar`/`numerarLocal`/`libertar` (1-40, 186-290) |
| `js/vendas_series.js` | ~320 | amostragem | regras de séries (origem GERAL/T<id>) por pesquisa |
| `js/fluxo_vendas.js` | 493 | 175 | 318-493 (Fluxo POS) |
| `js/permissoes.js` | 1261 | amostragem alargada | catálogo POS/Armazém/Folha de Caixa, perfis-modelo, separadores (linhas 204-212, 265-302, 366-372, 586-612, 735-745 e pesquisas) |
| `js/db_v2.js` | 1237 | ~90 | esquemas V1245-V1248, `cash_*`, tabelas base de vendas/stock/tesouraria, validação de contas mapeadas |
| `js/app_v2.js` | >11000 | amostragem | mapa de vistas (1122-1152), lista de módulos (3265-3300, 3400-3425), `hasView` (760-800), `resolveMatérialAccount` (11146-11177), `logMovement` |
| `js/core/modulos_registo.js` | — | 20-26 | rotas |
| `index_arrumado.html` | — | 156-205 | menu |
| `js/ui_painel_modulos.js` | — | 850-866 | KPIs POS |
| `js/modules/gestao/relatorios_gestao.js` | — | 557-600 | KPIs «POS e Serviços» |
| `js/shared/grelha_zoom.js` | — | 1-40 | zoom das grelhas |
| `js/engine_v2.js` | — | 95-125 | `formatMoney` |
| `css/styles.css` | — | 2425-2530 | modo imersivo e emulação de dispositivo |
| `docs/DATA_RELATIONSHIPS.md`, `docs/MODULES.md`, `docs/API_READINESS.md` | — | pesquisa | confirmação de relações |
| **Pesquisados (grep) sem leitura integral**: `js/ui_tesouraria.js`, `js/fluxo_tesouraria.js`, `js/fluxo_processos.js`, `js/ui_cubo.js`, `js/consolidacao.js`, `js/ajuda.js`, `js/modulos_manifesto.js`, `ferramentas/*.js`, `js/print_docs.js`, `js/condicoes_pagamento_vendas.js`, `js/moedas_vendas.js`, `js/data/repositorios.js`, `js/data/repositorio_base.js`, `js/engine_v2.js` (restante), `portal.html`, `Regras/`, `Estruturas as Notas/`, `servico_agt/` | | | sem referências adicionais ao POS (ou só listas de nomes de funções) |

**O que ficou por ler ou só em amostragem (para a equipa de reconstrução)**: (1) integração de Tesouraria que transforma os `treasury_documents` RECEBIMENTO/PAGAMENTO do POS em lançamentos (D banco/C transitória) — `ui_tesouraria.js` e correlatos não lidos; (2) `generateGlobalLanNumber`, `Dialogo`, `ModalManager`, `ContaTerceiro`, `ImportMestre`, `Notificacoes` (utilitários de `nucleo_partilhado.js`, não lidos); (3) geração do JSON AGT, validação, QR e SAF-T (`facturacao_agt*.js`, `ui_sales.js:10038+`) — só a numeração foi lida; (4) `printInvoice` (modelo A4 comum de Vendas, `print_docs.js`) — conteúdo do A4 não inventariado; (5) parte antiga/obsoleta de `ui_sales.js` indicada acima; (6) 1030-1240 e 1610-2020 de `ui_folha_caixa.js`; (7) listas de utilizador/perfil do `app_v2.js` (UI de gestão de perfis).
