# Legado novo vs legado antigo vs erp_laravel — Vendas, Compras, Tesouraria, Stock, Contabilidade, IA e Núcleo

Siglas: **LN** = legado novo (`Versões/Versão Actual/ERP`), **LA** = legado antigo (`payroll_system_web`), **EL** = `erp_laravel`.
Fiz só leituras: nada foi editado, migrado nem gravado. Os diffs ignoram CRLF e os sufixos `?v=`.
Grande parte do diff de `index_arrumado.html` (~1258 linhas) e de `modulos_manifesto.js` é só a troca dos sufixos de versão (`?v=…`) e das assinaturas. O conteúdo novo é o que se lista abaixo.

## Tabela-resumo

| # | Funcionalidade | Ecrã | Estado no EL | Esforço |
|---|---|---|---|---|
| 1 | Séries de numeração das facturas de cliente (ecrã próprio, número de arranque) | Vendas › Séries de Numeração | PARCIAL | Baixo–Médio |
| 2 | Documentos não se apagam, anulam-se (vendas, compras, stock) | Vendas, Compras, Armazém, Inventário | EXISTE (vendas sem motivo/autor) | Baixo |
| 3 | Factura paga: cancelar recibo e recebimento de tesouraria na hora, antes da NC | Vendas › Facturas (NC / converter) | PARCIAL | Médio |
| 4 | NC nunca acima do facturado (por artigo e pelo valor) | Vendas › Nota de Crédito | EXISTE | — |
| 5 | Conta de cliente (31) e conta de fornecedor (32/37.0) no mesmo terceiro | Clientes, Fornecedores, Tabelas Auxiliares, criação rápida, CRM, mapeamento em massa | FALTA (o tipo CLIENTE_FORNECEDOR existe, mas a conta é uma só) | Médio |
| 6 | Integração sem contas por defeito ('31.1', '321'): pára e diz o que falta | Integração de vendas, recibos, compras, POS e hotel | PARCIAL | Baixo |
| 7 | Unidade de negócio e centro de custo na linha do documento | Vendas, Compras (pedido, factura directa) | FALTA | Médio |
| 8 | Repartição por várias unidades de negócio (critérios + alocação no objecto) | Configurações › Unidades de negócio; Vendas, Compras, Tesouraria, Lançamentos, Ficha do colaborador | FALTA | Alto |
| 9 | Balanço e DR com uma coluna por unidade de negócio | Contabilidade › Relatórios | FALTA (só há filtro por unidade) | Médio |
| 10 | Cartas de ordem de pagamento a partir de um pagamento da tesouraria (terceiros + salários, modelo de texto) | Tesouraria › Cartas de Pagamento | PARCIAL (só salários, no RH) | Médio–Alto |
| 11 | Contas bancárias dos terceiros (várias, uma predefinida) | Tabelas Auxiliares › Terceiros | FALTA | Médio |
| 12 | Dados bancários nas contas 42/43 do plano (banco, balcão, n.º, IBAN, SWIFT, titular) | Plano de Contas | FALTA | Baixo |
| 13 | Pagamentos/recebimentos só por contas bancárias (42/43); o numerário (45) fica para a Folha de Caixa | Tesouraria › Pagamentos/Recebimentos | FALTA | Baixo |
| 14 | Folha de Caixa no menu da Tesouraria | Menu › Tesouraria | EXISTE | — |
| 15 | Caixas de contas/terceiros com pesquisa; totalizadoras visíveis mas bloqueadas | Todas as caixas | EXISTE (as totalizadoras ficam ocultas, não desactivadas) | Baixo |
| 16 | Assistente IA com regras em ficheiro JSON (motor interno com formulário + IA externa que só extrai valores) | Assistente IA | FALTA (o EL tem regras por palavras-chave) | Alto |
| 17 | Escolha de fornecedor IA (OpenRouter/Gemini) com chave no navegador | Assistente IA | NÃO COPIAR (o EL guarda a chave no servidor) | — |
| 18 | Busca de operações na árvore do menu (filtro em linha) | Menu lateral | PARCIAL (o EL tem Ctrl+K em modal) | Baixo |
| 19 | Aspecto do menu: completo / só símbolos / só nomes | Menu lateral | PARCIAL (o EL só recolhe para ícones) | Baixo |
| 20 | Tela nocturna (tema escuro, Ctrl+Shift+N, por utilizador) | Barra superior | FALTA | Médio |
| 21 | Correcção dos favoritos (recursão ao clicar) e rótulo no modo «só símbolos» | Favoritos | N/A (o EL tem implementação própria) | — |
| 22 | Permissões: `data-perm-req`, aberturas de formulário guardadas, perfis antigos verificados | Núcleo | N/A / verificar | Baixo |
| 23 | Rótulos «Anular» em vez de «Eliminar» no catálogo | Perfis e permissões | PARCIAL (o catálogo do EL diz «Eliminar») | Baixo |
| 24 | Cópia/clone por empresa das tabelas novas | Configurações › Ferramentas | FALTA (as tabelas não existem) | Baixo (depende de 10, 11, 16) |
| 25 | Ajuda: Séries, Cartas, Clientes (conta 31), Projectos | Painel de Ajuda | FALTA (os textos novos) | Baixo |
| 26 | Importação de terceiros com duas colunas de conta | Importar Excel › Terceiros | FALTA | Baixo (depende de 5) |
| 27 | Ajustes visuais (tabela de linhas da venda, select2, separadores que transbordam) | Vendas e geral | N/A (UI diferente) | — |

---

## 1. Vendas / Facturação / AGT

### 1.1 Séries de numeração das facturas de cliente — PARCIAL
- **O que faz:** um ecrã novo, Vendas › Séries de Numeração (`data-target="vendas_series"`, separador «Séries» em `renderVendas`), onde se atribui o código e o **número de arranque** das séries FT/FR/NC, por ano e por origem (facturação geral ou cada terminal POS). Assim pode continuar-se uma numeração que vem de um livro em papel ou de outro programa. O formato é o do SAF-T: `TIPO SÉRIE/N`. Para cada tipo, o ecrã mostra o que acontece hoje (série atribuída ou numeração automática).
- **Ficheiros LN:** `js/vendas_series.js:63` (`renderVendasSeriesTab`), `js/facturacao_agt.js:125-186` (`proximoValido`, conta dos emitidos) e `:245` (`numerarLocal`, `reservarNumero`). Usado em `js/ui_sales.js:1863` e `:3014`, `js/pos_gestao.js:393` e `js/lavandaria.js:234`.
- **Dados e regras:** usa a tabela `fe_series` (já existia). O campo `proximo` passa a ser editável.
  - O número só se altera enquanto a série não tem documentos. «Usada» passa a significar ter documentos emitidos (conta `sales.fe_serie_id`), e não `proximo > 1`.
  - Uma só série activa por tipo + ano + origem; código alfanumérico até 30 caracteres.
  - A data de um documento não pode ser anterior à `ultima_data` da série.
  - Uma série com documentos não se apaga, fecha-se.
  - Fora do regime AGT, `numerarLocal` usa uma série ACTIVA criada pelo utilizador e nunca cria nenhuma. Sem série, fica a numeração automática antiga. Não marca `fe_regime`.
  - No POS, usa a série do terminal e, se não houver, a geral.
- **Permissão:** ecrã `vendas_series`, tarefa `vendas_series_gerir` (ou `vendas_fe_config`). Os botões ficam desactivados com `data-perm-req`.
- **No EL:** `backend/app/Services/Vendas/ServicoSeries.php` reserva o número (lock + transacção) e cria a série `A<ano>` automaticamente. O CRUD está em `FaturacaoEletronicaController::criarSerie/atualizarSerie/eliminarSerie` (`routes/api.php:166-170`) e em `ServicoConfigFaturacaoEletronica::gravarSerie`. A UI está em `frontend/src/modulos/vendas/agt/FaturacaoEletronica.tsx`, dentro da Facturação Electrónica.
  - **Falta:**
    - (a) o campo «número inicial» (`proximo_numero` é sempre 1 na criação e não é aceite em `validarSerie`);
    - (b) a regra «usada = tem documentos emitidos»: o EL usa `proximo_numero > 1`, o que com arranque em 501 bloquearia uma série virgem;
    - (c) o ecrã/separador próprio «Séries» em Vendas (não está em `vendas/ecras.ts`) e a chave `vendas_series`/`vendas_series_gerir` no catálogo;
    - (d) não se trata de numeração automática: a série por omissão do EL já cumpre o papel.
- **Esforço:** Baixo–Médio. Inclui: aceitar `proximo_numero` na criação/edição com validação 1..99 999 999; contar `vendas` e `recibos_venda` com `serie_faturacao_eletronica_id`; um separador em `BarraVendas`; chaves no catálogo.

### 1.2 Anulação em vez de eliminação — EXISTE (com uma lacuna)
- **LN:** `js/shared/anulacao_documentos.js` (`window.AnularDocs`: `recusarEliminar`, `pedirMotivo`, `anularVenda`, `anularRecibo`, `anularCompra`, `cancelarRecebimentos`, `exigirRecebimentoCancelado`, `notaCredito`). A eliminação está bloqueada em vendas (`ui_sales.js:2024-2045`, incluindo a limpeza em massa), em compras (`ui_compras_v2.js:2886-2906`, `anularDocumentoCompra`), nas recepções e guias (`ui_warehouse.js:1707-1848`) e nas sessões de inventário (`ui_inventory.js:209`). Anular pede motivo, regista autor e data, e reverte as quantidades da encomenda e as ligações. As guias com movimento de stock anulam-se no Armazém. A factura FT/FR só se corrige com NC.
- **EL:** existem rotas `anular` para vendas (`routes/api.php:153`), recibos (`:160`), pedidos, propostas, encomendas, recepções e facturas de compra (`:206-234`), guias de saída (`:131`) e inventários (`:143`). Não há DELETE de documentos. Os fiscais recusam-se com `DOCUMENTO_SELADO` («emita uma nota de crédito»). Nas compras há motivo e rasto (`ServicoProcessoCompras.php:30,72`).
- **Lacuna:** `DocumentoVendaController::anular` (`:163`) não recebe **motivo** (o recibo recebe). Além disso, o rótulo de `vendas_fat_del` no catálogo continua «Eliminar documentos».
- **Esforço:** Baixo (motivo + autor na anulação de vendas; rótulos).

### 1.3 Factura paga: cancelar o recebimento antes da NC — PARCIAL
- **LN:** a «porta única» `AnularDocs.exigirRecebimentoCancelado(saleId, {oferecer})`. Se a factura estiver liquidada, pergunta e cancela na hora os recibos e os documentos de tesouraria (que ficam ANULADOS, não apagados) e só depois deixa emitir a NC. Usada em `ui_sales.js:1758` (gravar NC) e `:2763` (converter para NC). A limpeza de uma venda também passa a marcar recibos e tesouraria como anulados (`ui_sales.js:5252-5263`).
- **EL:** `ServicoDocumentosVenda.php:238-243` e `:541-544` **recusam** a NC com `NC_FATURA_PAGA` («anule primeiro o recibo/pagamento»). Falta a acção combinada «cancelar recebimentos desta factura» (recibos + tesouraria numa só transacção) oferecida a partir da factura.
- **Esforço:** Médio. Um endpoint `POST vendas/documentos/{id}/cancelar-recebimentos` que anula os recibos e os documentos de tesouraria ligados, com reversão contabilística se estiverem integrados, e um diálogo no frontend.

### 1.4 Limite da Nota de Crédito — EXISTE
- **LN:** `js/shared/nota_credito_limites.js` (`LimiteNC.saldo/saldoDeVarias/validar`). Por artigo, «facturado − já creditado» (as NC anuladas não contam), mais o valor total. Usado em `ui_sales.js:1825` (NC manual, várias facturas referidas) e `:2978` (conversão), com a quantidade sugerida pelo saldo (`:2777-2851`).
- **EL:** `ServicoDocumentosVenda.php:246-255` (conversão pelo saldo por produto), `:655-659` (quantidade da NC ≤ origem − creditada, com a origem bloqueada) e `:545-548` (`NC_EXCEDE_SALDO`). Já cobre o que o LN acrescenta. Convém confirmar que a NC manual com **várias** facturas referidas soma os saldos (o LN tem `saldoDeVarias`).

### 1.5 Conta de cliente e conta de fornecedor no mesmo terceiro — FALTA
- **LN:** `js/shared/contas_terceiro.js` (`window.ContaTerceiro`: `ehCliente`, `ehFornecedor`, `temTipo`, `comPapel`, `cliente()`, `fornecedor()`, `classeCorrecta`, `exigir`, `exigirOuAvisar`, `falta`, `opcoesTipo`, `contasDoPlano`).
  - `third_parties` ganha `account_client` (classe 31) e `account_supplier` (classe 32 ou 37.0). `account_code` mantém-se como recurso quando a classe corresponde.
  - `type` passa a ser uma **lista** («CLIENTE, FORNECEDOR»): gravar como cliente acrescenta o papel sem apagar o outro. Nunca se consulta `where({type})`.
  - A migração `db.version(1276)` reparte a conta antiga pela classe.
  - A conta é validada na gravação e não há contas por defeito.
  - Afecta: fichas de cliente (`ui_sales.js:2053-2236`), de fornecedor (`ui_compras_v2.js:2459-2632`), Tabelas Auxiliares (`ui_aux.js:178-1057`), criação rápida (`app_v2.js:11225-11392`, «Cliente e Fornecedor»), CRM (`crm_dados.js:163`), mapeamento em massa (`mapeamento_massa.js:23`), importação (`formularios_linhas_config.js:182`, colunas `Conta_Cliente`/`Conta_Fornecedor`), listas de clientes e fornecedores no POS e no Armazém (`ui_pos_armazem.js:44,816`, `ui_warehouse.js:278,611`) e os fluxos (`fluxo_vendas.js:222`, `fluxo_compras.js:179`).
- **EL:** `Terceiro` tem o tipo `CLIENTE_FORNECEDOR` (`Models/Terceiro.php:19-25`), mas só um `codigo_conta` (`TerceiroBase.php:49`). Não há `account_client`/`account_supplier` (o grep só encontra ocorrências em CRM/FluxoVendas sem coluna própria).
- **Esforço:** Médio.
  - Migração: `conta_cliente` e `conta_fornecedor`, com a repartição de `codigo_conta` por classe.
  - Integrações de vendas, recibos, POS, hotel e lavandaria a usar `conta_cliente`; compras a usar `conta_fornecedor`.
  - Validação da classe; formulários; importação; esquema do legado (`esquema.json`) para a migração definitiva.

### 1.6 Integração sem contas por defeito — PARCIAL
- **LN:** recibos (`ui_sales.js:3595-3642`: o `'31.1'` foi retirado), integração de vendas (`:3159`), POS (`:7504`), hotel (`:7908`), compras (`ui_compras_v2.js:3298`: o `'321'` foi retirado). Usa `exigirOuAvisar`: um modal «Falta a conta do terceiro», e a integração pára.
- **EL:** em geral já não usa contas fixas (ver `ServicoFontesLavandariaPOS.php:24`). Falta confirmar caso a caso depois de 1.5; a mensagem tem de indicar a conta de cada papel.
- **Esforço:** Baixo (junto com 1.5).

### 1.7 Unidade de negócio e centro de custo na linha — FALTA
- **LN:** `js/shared/linha_un_cc.js` (`window.LinhaUNCC`: `carregar`, `ths`, `tds`, `ler`). Colunas UN/CC em cada linha de venda (`ui_sales.js:1100-1122`, `:1647-1697`, `:1813`), do pedido de compra (`ui_compras_v2.js:888-1061`) e da factura directa de fornecedor (`:1729-2033`). Na proposta, encomenda e factura, a UN/CC da linha segue o pedido (`:1450`, `:1689`).
- **Precedência na contabilização:** linha › repartição do documento › cabeçalho (`ui_sales.js:3210`, `ui_compras_v2.js:3404`).
- **EL:** as UN/CC estão só no cabeçalho (`ServicoContabilizacaoVendas.php:115,171`, `ServicoContabilizacaoCompras.php:71`). `ItemVenda`/`ItemCompra` não têm `unidade_negocio_id`/`centro_custo_id`.
- **Esforço:** Médio (colunas nos itens, formulários de linhas e contabilização por linha).

### 1.8 Repartição por unidades de negócio — FALTA
- **LN:**
  - `js/modules/configuracoes/reparticao.js` (`ReparticaoUN`: critérios PERCENTAGEM, PARTES_IGUAIS, COLABORADORES, VOLUME_NEGOCIOS, AREA, PERSONALIZADO; `pesos()`).
  - `alocacao_un.js` (`AlocacaoUN.montar` → `unidades_alocadas = {criterio_id, unidades:[{business_unit_id, peso}]}`).
  - `reparticao_ui.js` (`ReparticaoPagina.desenhar/escolher`, desenhado em Configurações › Unidades de negócio, `unidades_negocio.js:118`).
- **Tabela nova:** `bu_allocation_keys`. O campo `unidades_alocadas` aparece em vendas, recibos (herdam da factura), pedidos e facturas de compra e na ficha do colaborador.
- **Regras:** na venda reparte só as contas de proveito e de CMV (não 31/34), com a última parcela a absorver o arredondamento (`ui_sales.js:3357-3386`). A tesouraria e os lançamentos têm o botão «Repartir linha» (`ui_tesouraria.js:1113`, `ui_lancamentos.js:1495`), que substitui uma linha por N linhas com o mesmo total. A folha salarial reparte o custo, os impostos e o líquido (`app_v2.js:6477-6584`, RH).
- **EL:** não existe (só «repartição» nos acréscimos/diferimentos).
- **Esforço:** Alto. Tabela de critérios, componente de alocação reutilizável, repartição na contabilização de vendas, compras e salários, e nos formulários de tesouraria e de lançamentos.

---

## 2. Compras
- **Anulação** (pedidos, propostas, encomendas, facturas): `ui_compras_v2.js:216,323,455,636,2903`. A tarefa de permissão passa a guardar `anularDocumentoCompra` (`permissoes.js:29`). **EL: EXISTE** (rotas de anulação em `routes/api.php:206-234`).
- **Fornecedores só com conta 32/37.0** nas listas da factura (`ui_compras_v2.js:1132,1283`) e ficha com conta de fornecedor própria: ver 1.5 (FALTA).
- **UN/CC por linha e repartição do pedido e da factura:** ver 1.7 e 1.8 (FALTA). A alocação segue factura › encomenda › pedido (`ui_compras_v2.js:3506`).
- **Fluxos** (`fluxo_compras.js:179`, `fluxo_vendas.js:222`): a verificação «sem conta» passa a usar a conta do papel. Depende de 1.5.

## 3. Tesouraria

### 3.1 Cartas de ordem de pagamento — PARCIAL
- **LN:** `js/cartas_pagamento.js` (484 linhas), `js/cartas_pagamento_ui.js:196` (`renderCartasPagamento`, separadores «Cartas» e «Modelo de texto»), `ui_tesouraria.js:599` (botão na lista), `:1102` e `:1144` (`tesoOferecerCarta` depois de gravar), `:1153` (`tesoEmitirCarta`). O ecrã está em `data-target="teso_cartas"` (Tesouraria › Cartas de Pagamento).
- **Regras:**
  - A carta nasce sempre de um PAGAMENTO já gravado (`treasury_doc_id` obrigatório) e não cria lançamentos.
  - A conta financeira tem de ser 42/43 com IBAN.
  - Beneficiários: terceiros com conta em `third_party_bank_details` ou colaboradores do período com IBAN.
  - O mesmo mês pode ter várias cartas; quem já entrou numa carta do mês aparece assinalado e desmarcado.
  - Numeração por ano, estado, anulação; texto padrão por empresa e tipo (editável no modelo e em cada carta).
- **Dados:** `payment_letters` (tipo, numero, ano, estado, treasury_doc_id, payroll_period_id…), `payment_letter_items` (+ `third_party_id`), `payment_letter_templates` (v1271).
- **Permissões:** ecrã `teso_cartas`; tarefas `teso_carta_emitir`, `teso_carta_modelo`.
- **EL:** `cartas_pagamento_bancario` / `itens_carta_pagamento` (`CartaPagamentoBancarioBase.php:27` já tem `documento_tesouraria_id`), usados no RH (`PagamentoSalariosController`, `frontend/src/modulos/rh/comum/Cartas.tsx`, `Bancario.tsx`). Só servem colaboradores. Faltam: o terceiro como beneficiário, a numeração e o estado/anulação, os modelos de texto, o ecrã na Tesouraria e o convite depois de gravar.
- **Esforço:** Médio–Alto (depende de 3.2 e 3.3).

### 3.2 Contas bancárias dos terceiros — FALTA
- **LN:** `ui_aux.js:920-1005` (`auxDesenharContasBancarias`, `auxAdicionarContaBancaria`, `auxContaBancariaPredefinida`, `auxEliminarContaBancaria`). Um terceiro pode ter várias contas (banco, moeda, IBAN), uma delas `predefinida`. Na lista de terceiros aparece a coluna «Banco».
- **Tabela:** `third_party_bank_details` (v1271).
- **EL:** não existe (só `coordenadas_bancarias_colaboradores`).
- **Esforço:** Médio.

### 3.3 Dados bancários nas contas 42/43 do plano — FALTA
- **LN:** `app_v2.js:9050-9163` (`CONTAS_BANCARIAS_PREFIXOS=['42','43']`, `_accCamposBancarios`, `_accPreencherBancarios`, `_accSugerirBancos`). Campos `bank_name`, `bank_branch`, `bank_account_number`, `bank_iban`, `bank_swift`, `bank_holder` em `chart_of_accounts`, só nas contas 42/43. Limpam-se se a conta deixar de ser bancária.
- **EL:** `PlanoContaBase.php:22-24` só tem empresa, código, descrição, tipo, moeda e natureza.
- **Esforço:** Baixo.

### 3.4 Pagamentos e recebimentos só por bancos — FALTA
- **LN:** `ui_tesouraria.js:653-678` e `:861-877`. Desde 2026-10-07, os pagamentos e recebimentos só movimentam 42/43. O meio CAIXA (45) só aparece quando se edita um documento antigo que já tinha conta 45; o numerário é exclusivo da Folha de Caixa.
- **EL:** `ServicoDocumentosTesouraria.php` aceita qualquer `conta_financeira`. A conta 45 só decide o diário (`:405`).
- **Esforço:** Baixo (validação no serviço e filtro do meio no frontend, mantendo os históricos).

### 3.5 Repartir linha por unidades (tesouraria) — FALTA
Ver 1.8 (`ui_tesouraria.js:960,1113`).

### 3.6 Folha de Caixa no menu — EXISTE
O LN acrescenta a entrada `teso_folha_caixa` ao menu (a rota já existia em `modulos_registo.js`). O EL tem-na em `frontend/src/layout/menuLegado.ts:108` e no catálogo (`catalogo.json:811`).

## 4. Logística / Stock
- **Anulação de recepções, guias e sessões de inventário** com motivo e autor; uma anulação repetida dá «Já anulada» (`ui_warehouse.js:1707-1848`, `ui_inventory.js:209-225`). **EL: EXISTE** (`guias-saida/{guia}/anular`, `inventarios/{sessao}/anular`, `rececoes/{id}/anular`).
- **Listas de fornecedores e clientes por papel** (`ui_warehouse.js:278,611`; POS Armazém `ui_pos_armazem.js:44,816`): depende de 1.5.
- POS e Lavandaria a numerar pela série atribuída: **EL: EXISTE** (`ServicoFrentePOS`, `ServicoCaixaLavandaria` usam `ServicoSeries`). Fica pendente o número inicial (1.1).

## 5. Contabilidade
- **Balanço e DR por unidade de negócio** (`ui_reports.js:376-406`, `toggleReportPorUN`, `colunasPorUN`; `:1041-1082` Balanço, `:1275-1365` DR). Uma coluna por unidade com movimento no período, mais a coluna «sem unidade» e o Total. **EL: FALTA.** `ServicoDemonstracoesFinanceiras.php:114` aceita `unidade_negocio_id` como filtro, mas não as colunas por unidade. **Esforço:** Médio (agrupar `linhas_lancamento` por `unidade_negocio_id` e correr o mesmo cálculo).
- **Lançamentos — repartir linha** (`ui_lancamentos.js:1495,1612`): ver 1.8 (FALTA).
- **Lançamentos — permissões nas aberturas** (`showAddEntryForm`, `addEntryLine`, `openMultiAccountsModal`, `openGenericMultiModal` passam a exigir `lancamentos_editar`/`lancamentos_post`; `showImportHistoryModal`; `showRegularizationModal`): no EL as acções estão protegidas no backend (`exigir`). Basta verificar que os botões do frontend ficam inactivos sem permissão.
- **Tabelas auxiliares — terceiros:** duas contas (1.5), contas bancárias (3.2), tipo «Cliente e Fornecedor» (`ui_aux.js:226,711`, `auxTipoTerceiroMudou`), sugestões de conta por classe (`auxPreencherContasTerceiro`), criação rápida a partir de outro ecrã (`openMasterDataQuickCreateModal` na tarefa `aux_gerir`).
- **Guarda da base de dados:** `CAMPOS_CONTA_MAPEADA.third_parties` passa a incluir `account_client` e `account_supplier` (`db_v2.js:413`), e uma conta 'T' é recusada. No EL a regra «só contas de movimento» está nos serviços (`ServicoConfigCompras`, `ServicoLancamentos`, `ServicoCategoriasAtivos`…). As colunas novas têm de entrar na mesma validação.
- **Caixas de escolha** (`js/shared/seletores.js`): o select2 com pesquisa em todas as caixas de contas e terceiros; as totalizadoras aparecem **desactivadas** com «(totalizadora — não pode ser mapeada)»; datalists validados ao sair; `data-seletor="conta|terceiro|nenhum"` e `data-classes`. **EL: EXISTE** — `frontend/src/modulos/contab/comum/Seletores.tsx:17-35` (`showSearch`, só tipo M por omissão, `incluirTotalizadoras`) e `compras/comum/Seletores.tsx`. Diferença menor: no EL as totalizadoras ficam ocultas e não desactivadas, e algumas caixas de outros módulos podem ser `Input` simples (rever caso a caso).

## 6. Assistente IA

### 6.1 Regras em ficheiro JSON — FALTA
- **LN:** `js/ia_regras_ficheiro.js` (532 linhas). Substitui as antigas regras por palavras-chave (`internal_rules`). Funções retiradas do manifesto: `_renderInternalRules`, `_saveInternalRule`, `openInternalRulesModal`, `openAIRulesModal`, `saveAIRules`.
- **Formato da regra:** `rule_name`, `description`, `entity{company,nif,unit,currency,vat_rate}`, `role`, `principles`, `scope`, `source{document,extract,unmapped_payment_methods}`, `payment_mapping[{method[],account_code,type_dc}]`, `revenue{…gross/net/vat}`, `entry{reference,reference_date,omit_zero_lines,lines[]}`, `validations[{rule,tolerance}]`, `output_format{…}`, `daily_prompt`.
- **Avaliador seguro de expressões:** só números, variáveis, `+ - * / ( )` e `round/abs/min/max/sum` (`:27-60`), sem eval.
- **Dois motores com o mesmo ficheiro:**
  - INTERNO: um formulário gerado a partir da regra (`iaRegraFormulario`, `iaFormCalcular`, `iaFormDia`, `iaFormLancar`).
  - EXTERNA: a IA só extrai valores; o cálculo, as validações e o lançamento são locais.
- **Gestão:** `iaRegraImportar`, `iaRegraExportar`, `iaRegraModelo`, `iaRegraVer`, `iaRegraActivar`, `iaRegraEliminar`, `iaRegraMotor` (AMBOS|INTERNA|EXTERNA), `openRuleFilesModal` (`:434`).
- **Tabela:** `ai_rule_files` (v1269): `{company_id, rule_name, nome, ficheiro, conteudo (JSON), activo, motor, importado_em/por, versao}`.
- **Permissões:** ecrã `ia_assistente`; tarefas `ia_regras`, `ia_lancar`.
- **EL:** `ServicoAssistenteIA.php` (desligado por omissão, só propõe, chave no servidor, motor interno por **palavras-chave** em `regras_internas_ia`, registo em `utilizacoes_assistente_ia`). Rotas em `routes/api/integracoes.php:40-48`; protegido por `lancamentos_post`/`aux_gerir`/`config_empresas_gerir`, sem chaves `ia_*` no catálogo.
- **Falta:** a tabela de ficheiros de regra, a validação do esquema JSON, o avaliador de expressões (em PHP, sem eval), o formulário gerado, o modo «IA só extrai valores» com cálculo e validações locais, importação/exportação, as permissões `ia_regras`/`ia_lancar` e a inclusão na cópia de empresa.
- **Esforço:** Alto.

### 6.2 Ligação ao fornecedor (`ui_ai_agent.js:46-212`) — NÃO COPIAR
O LN permite escolher OpenRouter ou Gemini, guarda a chave em `localStorage` e testa a ligação directamente a partir do navegador. O EL corrigiu isto de propósito (a chave fica no servidor, ADR do R2-G4). Mantém-se a abordagem do EL. Do LN pode aproveitar-se só a ideia de o utilizador escolher o motor por regra (`motor` AMBOS/INTERNA/EXTERNA).

## 7. Núcleo

### 7.1 Busca no menu (`js/busca_menu.js`, `index_arrumado.html:97-108`, `css/styles.css`) — PARCIAL
Uma caixa no topo da árvore que filtra à medida que se escreve: sem acentos, pelo rótulo **e** pelo caminho dos grupos, setas do teclado, Esc para limpar, contador `aria-live`. Nunca revela itens escondidos por permissões, e ao limpar repõe os grupos que estavam abertos. **EL:** `frontend/src/layout/PesquisaEcras.tsx` (Ctrl+K, modal, favoritos primeiro, sem acentos, pelo módulo). Falta o filtro em linha na árvore. **Esforço:** Baixo.

### 7.2 Aspecto do menu (`js/aspecto_menu.js`, `index_arrumado.html:122-131`) — PARCIAL
Três modos: completo, só símbolos (nome ao passar o rato) e só nomes. É uma preferência por utilizador (`localStorage nav-aspecto_<user>`). Os rótulos ficam guardados em `data-nav-rotulo` para os favoritos. **EL:** `LayoutPrincipal.tsx:86,262,319` só recolhe o Sider (o equivalente a «só símbolos»), sem «só nomes» e sem persistência por utilizador. **Esforço:** Baixo.

### 7.3 Tela nocturna (`js/tela_noturna.js`, `css/tela_noturna.css` com 282 linhas, `index_arrumado.html:26-40,468-471`) — FALTA
Classe `tela-noturna` no `<body>`, aplicada antes da primeira pintura (sem clarão), botão na barra superior, Ctrl+Shift+N, preferência por utilizador. É relida depois do login (`app_v2.js:317`). **EL:** só o menu lateral é escuro (`estilos/tema.ts:80-88`). Não há tema escuro global (no antd seria `theme.darkAlgorithm` no `ConfigProvider`, mais os tokens dos CSS próprios) nem botão. **Esforço:** Médio (rever cores fixas nos CSS de impressão e nos ecrãs).

### 7.4 Favoritos (`app_v2.js:1181-1185`, `css/styles.css` «lápis de renomear») — N/A
Correcção da recursão ao clicar num favorito e lápis para renomear. O EL tem favoritos próprios. Basta confirmar que é possível renomear.

### 7.5 Permissões (`js/permissoes.js`) — ver a secção «Permissões novas»
Mudanças de comportamento:
- (a) **`data-perm-req`** em botões ligados por `addEventListener` (desactiva e põe o título «Sem permissão: …»). Usado em orçamento (`orcamento_ui.js:111,283`) e nas séries.
- (b) Perfis antigos (não v2) passam a ser verificados (`antigoAutoriza`). Um «Visualizador» deixa de criar dados.
- (c) Uma função que serve várias tarefas só é negada se nenhuma delas for permitida.
- (d) As **aberturas de formulário** (`show*`) passam a ser guardadas, e não só as gravações.

**EL:** o controlo é feito no backend (`exigir`) e o frontend esconde e desactiva por permissão. Não precisa de nada equivalente; deve confirmar-se que os botões «Novo…» respeitam a permissão de criar.

### 7.6 Cópias por empresa (`js/company_backup.js`) — FALTA (depende das tabelas)
Entram no backup, clone e limpeza: `ai_rule_files`, `payment_letter_templates`, `third_party_bank_details`, `rh_loans`, `rh_loan_settings`, `mt_assuntos` e `payment_letter_items` (tabela filha via `letter_id`, com remapeamento de `treasury_doc_id`, `payroll_period_id`, `employee_id`, `third_party_id` e `bank_id`). **EL:** `ServicoCopiaEmpresa.php` existe. As tabelas novas têm de ser acrescentadas quando forem criadas. **Esforço:** Baixo.

### 7.7 Ajuda (`js/ajuda.js:89-276`) — FALTA (textos)
Textos novos: `vendas_clientes` (conta 31), `vendas_series`, `teso_cartas`, `teso_meios_pagamento`, e projectos (`projectos_metas`, `projectos_execucao`, `projectos_equipa`, mapa de quantidades). **EL:** `frontend/src/componentes/ajuda/ajuda.ts`. **Esforço:** Baixo.

### 7.8 Impressão e estilos
- `js/shared/impressao.js:70`: as páginas com folha própria (recibo em A4) não são medidas.
- `css/styles.css:1363-1396`: campos flex, largura do select2, separadores que transbordam.
- **EL:** N/A (outra UI).

### 7.9 Outros (fora do âmbito pedido, mas presentes no diff do núcleo)
| Funcionalidade | Onde no LN | Estado no EL |
|---|---|---|
| Método de Trabalho — Registo de Assuntos 5W1H | `mt_assuntos`, `js/modules/metodo/assuntos*.js` | FALTA |
| Empréstimos a colaboradores | `rh_emprestimos` | FALTA |
| Escalas do mês | `rh_schedules` | FALTA |
| Estado do contrato (suspensão/cessação) | — | não verificado |
| Descritivo das funções | `roles.objectivo_geral`/`responsabilidades` | FALTA |
| Horário no contrato | — | não verificado |
| Recibo de vencimento A4 com 2 vias | — | o EL tem recibos em 2 vias (ADR-069) |
| Activos: edifícios, andares, portas, inventário físico | `asset_floors/rooms/inventories` | FALTA |
| Activos: manutenção com planos, ordens e requisições | — | o EL tem `planos_manutencao_ativos`, sem ordens nem requisições |
| Projectos: WBS, metas por quantidade, Gantt por quantidades | `projectos_hierarquia/metas/orcamento_qtd.js` | não verificado |
| Bloqueio do arranque do ERP no portal | `MODO_PORTAL`, `app_v2.js:11580` | não verificado |

---

## Modelo de dados novo (db_v2.js)

Todas as versões novas do Dexie no LN (o LA acaba na v1267 / bloco das linhas 858-859):

| Versão | Tabela | Índices (Dexie) | Notas / campos não indexados |
|---|---|---|---|
| (alteração) | `CAMPOS_CONTA_MAPEADA.third_parties` | — | passa a `['account_code','account_client','account_supplier','account_purchase_clearing']`: recusa contas 'T' |
| 1268 | `fixed_assets` (alterada) | `++id, company_id, code, category_id, acquisition_date, status, journal_line_id, cost_center_id, supplier_id, [company_id+status], [company_id+code], [company_id+category_id], is_master_data, asset_type, building_id, floor_id, room_id` | novos: `asset_type` (EDIFICIO), `building_id`, `floor_id`, `room_id` |
| 1268 | `asset_floors` (nova) | `++id, company_id, building_id, ordem` | andares do edifício |
| 1268 | `asset_rooms` (nova) | `++id, company_id, building_id, floor_id, ordem` | portas numeradas |
| 1268 | `asset_inventories` (nova) | `++id, company_id, building_id, estado, data` | folha de inventário físico |
| 1268 | `asset_inventory_lines` (nova) | `++id, company_id, inventory_id, asset_id, room_id` | resultado: ENCONTRADO / EM_FALTA / DESLOCADO / EXTRA |
| 1269 | `ai_rule_files` (nova) | `++id, company_id, rule_name, activo` | `nome, ficheiro, conteudo (JSON), motor AMBOS/INTERNA/EXTERNA, importado_em/por, versao` |
| 1270 | `rh_schedules` (nova) | `++id, company_id, employee_id, dia, prioritaria, [company_id+dia], [employee_id+dia], [company_id+employee_id]` | `entrada, saida, intervalo_min, tolerancia_min, folga, origem, nota` |
| 1271 | `payment_letters` (alterada) | `++id, company_id, tipo, numero, ano, estado, data, treasury_doc_id, payroll_period_id, month_year, bank_account_code, signature_name, total_amount, is_master_data, [company_id+tipo], [company_id+ano], [company_id+payroll_period_id]` | novos: tipo, numero, ano, estado, treasury_doc_id |
| 1271 | `payment_letter_items` (alterada) | `++id, letter_id, employee_id, third_party_id, amount, iban, is_master_data, [letter_id+employee_id], [letter_id+third_party_id]` | novo: `third_party_id` |
| 1271 | `payment_letter_templates` (nova) | `++id, company_id, tipo, is_master_data, [company_id+tipo]` | texto padrão por tipo |
| 1271 | `third_party_bank_details` (nova) | `++id, company_id, third_party_id, bank_id, iban, predefinida, is_master_data, [company_id+third_party_id]` | várias contas por terceiro, moeda |
| 1272 | `roles` (alterada + upgrade) | `++id, company_id, name, is_master_data` | `objectivo_geral`, `responsabilidades` (← `description`) |
| 1273 | `rh_loans` (nova) | `++id, rh_company_id, employee_id, estado, numero, [rh_company_id+estado], [rh_company_id+employee_id]` | capital, prestacoes, taxa_juro… estados PENDENTE_CHEFIA…LIQUIDADO |
| 1273 | `rh_loan_instalments` (nova) | `++id, loan_id, employee_id, month_year, estado, [loan_id+month_year], [employee_id+month_year]` | |
| 1273 | `rh_loan_settings` (nova) | `++id, rh_company_id` | contas, rubrica de desconto, limites |
| 1274 | `mt_assuntos` (nova) | `++id, company_id, numero, estado, responsavel_principal_id, departamento_id, cliente_id, data_inicio, data_fim, purchase_request_id, [company_id+estado]` | 5W1H |
| 1274 | `mt_assunto_log` (nova) | `++id, assunto_id, em` | histórico |
| 1275 | `bu_allocation_keys` (nova) | `++id, company_id, codigo, criterio, activo, [company_id+activo]` | `nome, criterio, periodo_de/ate, linhas[{business_unit_id,peso}]` |
| 1276 | `third_parties` (alterada + upgrade) | `++id, company_id, nif, name, type, account_code, account_client, account_supplier, [company_id+type], is_master_data` | reparte `account_code` por classe 31 / 32-370 |
| 1277 | `asset_maintenance_plans` (nova) | `++id, company_id, codigo, asset_id, category_id, tipo, activo, proxima_data, [company_id+activo], is_master_data` | |
| 1277 | `asset_maintenance_orders` (nova) | `++id, company_id, numero, asset_id, plan_id, tipo, estado, data_prevista, [company_id+estado], [company_id+asset_id], is_master_data` | |
| 1277 | `asset_maintenance_order_items` (nova) | `++id, company_id, order_id, tipo, product_id, estado, purchase_request_id, requisicao_id, is_master_data` | |
| 1277 | `maint_requisitions` (nova) | `++id, company_id, numero, order_id, asset_id, estado, data, [company_id+estado], is_master_data` | |
| 1277 | `maint_requisition_lines` (nova) | `++id, company_id, requisition_id, order_item_id, product_id, estado, is_master_data` | |

**Campos novos não indexados** (relevantes para o âmbito):
- `fe_series.proximo` passa a ser editável.
- `sales`, `receipts`, `purchase_requests` e `purchase_invoices` ganham `unidades_alocadas`.
- As linhas de venda e de compra ganham `business_unit_id` e `cost_center_id`.
- `chart_of_accounts` ganha `bank_name`, `bank_branch`, `bank_account_number`, `bank_iban`, `bank_swift`, `bank_holder`.
- Os documentos anulados ganham motivo, autor e data e o estado ANULADO.
- `employees` ganha `unidades_alocadas`; `contracts` ganha `horario`.

**No EL:** existem `cartas_pagamento_bancario`, `itens_carta_pagamento` (só colaboradores), `regras_internas_ia` e `planos_manutencao_ativos`. Não existem `ai_rule_files`, `payment_letter_templates`, `third_party_bank_details`, `bu_allocation_keys`, as contas por papel em `terceiros`, os dados bancários em `plano_contas` nem a UN/CC nos itens.

## Permissões novas

Comparação de `js/permissoes.js` LA → LN.

**Ecrãs novos:**
| Ecrã | Tarefas |
|---|---|
| `vendas_series` | `vendas_series_gerir` (sensível) |
| `teso_cartas` | `teso_carta_emitir`, `teso_carta_modelo` (sensível) |
| `ia_assistente` | `ia_regras`, `ia_lancar` (sensível) |
| `mt_assuntos` (módulo novo `metodo`) | `mt_criar`, `mt_aprovar` (sensível) |
| `rh_emprestimos` | `rh_emp_gerir`, `rh_emp_lancar` (s), `rh_emp_config` (s) |
| `activos_edificios` | `activos_locais_gerir`, `activos_inventario_fisico` (s) |

**Tarefas novas em ecrãs que já existiam:**
- `contratos_suspender`
- `activos_manut_gerir`
- `activos_manut_requisitar` (s)
- `proj_orcamento_qtd` (s)

**Tarefas com o significado alterado:**
| Tarefa | Antes | Agora |
|---|---|---|
| `vendas_fat_del` | «Eliminar documentos» | «Anular documentos de venda (a factura só com NC)»: `anularVenda`, `notaCredito`, `cancelarRecebimentos` |
| `vendas_recibos` | eliminar recibos | «Emitir e anular recibos» (+`anularRecibo`) |
| `compras_ped_eliminar`, `compras_prop_eliminar`, `compras_enc_eliminar`, `compras_fact_eliminar` | «Eliminar …» | «Anular …» (guarda `anularDocumentoCompra`) |
| `activos_manut` | registar, finalizar e eliminar | só «Eliminar registos do histórico» |
| `contratos_terminate` | rescindir e eliminar | + cessar e anular a cessação |
| `config_empresas_gerir` | criar e editar | + `deleteCompany` |
| `config_ferramentas` | copiar, clonar, reparar | + `importDatabase`, `wipeDatabase` |

**Funções acrescentadas às tarefas existentes** (sobretudo as aberturas `show*`):
- `lancamentos_editar`/`lancamentos_post`: `showAddEntryForm`, `addEntryLine`, `openMultiAccountsModal`, `openGenericMultiModal`
- `lancamentos_saldos`: `showImportHistoryModal`
- `contab_compensar`: `showRegularizationModal`
- `aux_gerir`: `openCopyTableModal`, `openMasterDataQuickCreateModal`, `importSimpleExcel`
- `contab_mapeamento`: `openCopyAccountingMaposModal`
- `teso_doc_emitir`: `showTesourariaForm`, `showtreasuryImportModal`
- `teso_caixa_operar`: `showAbrirSessaoForm`, `showRegistarLinhaCaixa`, `showPagarFaturas`, `showReceberFaturas`, `showImportFolhaCaixaModal`, `atribuirNotasLinhasCaixa`
- `teso_conc_importar`: `editBankStatementLine`
- `teso_meios_gerir`: `showMeioPagamentoModal`
- `vendas_clientes_gerir`: `showCustomerForm`, `showPOSCreateCustomerModal`, `createPOSCustomer`, `openImportModal`
- `vendas_produtos_gerir`: `showProductForm`, `openImportModal`
- `vendas_fat_emitir`: `showSaleForm`
- `pos_venda`: `showPOSCheckout`
- `pos_fecho`: `showPOSCloseRegisterModal`
- `hotel_checkout`: `showPOSHotelCheckout`
- `hotel_quartos`: `showCreatePOSRoomModal`, `showEditPOSRoomModal`
- `compras_ped_criar`: `showNewPurchaseRequest`
- `compras_ped_aprovar`: `updateRequestStatus`
- `compras_new_proposal`: `showNewPurchaseQuote`
- `compras_rec_registar`: `showPartialDeliveryForm`
- `compras_fact_registar`: `showNewDirectPurchaseInvoice`
- `compras_forn_gerir`: `showSupplierForm`
- `compras_contratos_gerir`: `showPurchaseContractModal`
- `armazem_ajuste`: `showStockAdjustment`
- `armazem_guias_emitir`: `showGuiaSaidaForm`
- `armazem_config`: `showWarehouseForm`
- `activos_gerir`: `showAssetForm`, `openAssetCreationModalFromJournal`, `showTransferAssetModal`, `showBulkAssetEditModal`
- `activos_amort_*`: `showBulk*`
- `activos_inventariar`: `openAssetCreationModalFromJournal`
- `proj_*`: `show*Modal`, mais as funções de WBS e metas
- `config_moedas_gerir`: `abrirCambiosBAI`
- `rh_prod_registar`: `prodProjectosAssistente`, `prodProjectosEnviar`
- `contratos_new`: `contratosProdutividadeEmMassa`, `aplicarProdutividadeEmMassa`
- `rh_infotipos_gerir`: `gravarRegrasHorasExtra`

**Perfis-tipo:** `teso` ganha `teso_cartas`. O perfil de tesouraria ganha `teso_carta_emitir` e `teso_carta_modelo`. Os perfis RH ganham `contratos_suspender`. Os perfis de projectos ganham `proj_orcamento_qtd`.

**No EL** (`backend/resources/permissoes/catalogo.json`): nenhuma das chaves novas existe (grep = 0 para `vendas_series`, `vendas_series_gerir`, `teso_cartas`, `teso_carta_emitir`, `teso_carta_modelo`, `ia_assistente`, `ia_regras`, `ia_lancar`, `mt_*`, `rh_emp*`, `contratos_suspender`, `activos_edificios`, `activos_locais_gerir`, `activos_inventario_fisico`, `activos_manut_gerir`, `activos_manut_requisitar`, `proj_orcamento_qtd`). Os rótulos de `vendas_fat_del` e `vendas_recibos` continuam a dizer «Eliminar».

## Ecrãs/menu novos (data-target)

`data-target` que existem no `index_arrumado.html` novo e não no antigo (lista completa; nenhum foi removido):

| data-target | Rótulo / sítio no menu | Linha LN | Rota (`modulos_registo.js`) | No EL |
|---|---|---|---|---|
| `vendas_series` | Vendas › Séries de Numeração | 217 | `vendas/series` | FALTA o ecrã (séries só na Facturação Electrónica) |
| `rh_emprestimos` | RH › Empréstimos | 254 | `rh/emprestimos` | FALTA |
| `mt_assuntos` | Método de Trabalho (grupo novo) › Registo de Assuntos (5W1H) | 275 | `metodo/assuntos` | FALTA |
| `teso_folha_caixa` | Tesouraria › Folha de Caixa | 294 | `teso/folha-caixa` (já existia) | EXISTE (`menuLegado.ts:108`) |
| `teso_cartas` | Tesouraria › Cartas de Pagamento | 295 | `teso/cartas-pagamento` | FALTA (as cartas só existem no RH) |

**Outros elementos novos no `index_arrumado.html`:**
- Na cabeça da página: `css/tela_noturna.css` e o script que aplica o tema antes de pintar.
- No menu: `.nav-busca` (`#nav-busca-campo`, `#nav-busca-limpar`, `#nav-busca-conta`) e `.nav-aspecto` (3 botões `data-aspecto`).
- Na barra superior: o botão `#btn-tela-noturna`.
- Scripts novos: `shared/contas_terceiro.js`, `shared/seletores.js`, `shared/anulacao_documentos.js`, `shared/nota_credito_limites.js`, `shared/linha_un_cc.js`, `modules/configuracoes/reparticao.js`, `alocacao_un.js`, `reparticao_ui.js`, `modules/metodo/assuntos*.js`, `modules/rh/{escalas,produtividade_projectos,contratos_produtividade,emprestimos,emprestimos_ui,contrato_estado,contrato_estado_ui}.js`, `tela_noturna.js`, `aspecto_menu.js`, `busca_menu.js`.
- Carregados a pedido pelo manifesto: `vendas_series.js`, `cartas_pagamento*.js`, `ia_regras_ficheiro.js`, `activos_edificios.js`, `activos_manutencao*.js`, `projectos_{hierarquia,metas,orcamento_qtd}.js`.

## Prioridade sugerida para o EL (âmbito pedido)
1. **1.5 Contas por papel no terceiro** (+1.6, 26): corrige integrações erradas quando o mesmo terceiro é cliente e fornecedor. Esforço Médio.
2. **1.1 Número inicial e ecrã de Séries**: é Baixo e desbloqueia empresas que continuam uma numeração anterior.
3. **3.4 Tesouraria só por bancos** e **3.3 dados bancários 42/43**: Baixo, e são a base de 3.1 e 3.2.
4. **1.3 Cancelar os recebimentos antes da NC**: Médio.
5. **3.2 e 3.1 Contas bancárias dos terceiros e Cartas na Tesouraria**: Médio–Alto.
6. **1.7, 1.8 e 5 (relatórios por UN)**: UN/CC por linha, repartição e colunas por unidade. Médio a Alto, e convém fazê-los juntos.
7. **6.1 Regras IA em ficheiro**: Alto. Mantém-se a chave no servidor.
8. **Núcleo:** 7.1 e 7.2 (Baixo), 7.3 tema escuro (Médio), 7.7 ajuda, rótulos «Anular» no catálogo e motivo na anulação de vendas (Baixo).
