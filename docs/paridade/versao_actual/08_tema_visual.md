# 08 — Tema visual e menu lateral (paridade com a Versão Actual)

Referência: `Versões/v4/ERP` — `index_arrumado.html` (menu e cabeçalho), `css/styles.css`, `css/tela_noturna.css`,
`js/app_v2.js` («Sidebar Resizer Logic», `toggleSidebar`), `js/aspecto_menu.js`, `js/busca_menu.js` e
`js/ui_painel_modulos.js` (Chart.js e cartões do Dashboard).
Sistema novo: `frontend/src/layout/**`, `frontend/src/estilos/{tema.ts,global.css,identidade.css}`,
`frontend/src/componentes/{vistas,graficos}/**` e `frontend/src/modulos/geral/comum/**`.

Decisões que se mantêm (ADR-070): o botão «Modo Responsivo» continua retirado; o menu do utilizador continua com
«Gerir Dados Pessoais» em primeiro lugar e «Terminar sessão» no fim.

## 1. Menu lateral

| Legado | Novo | Notas |
|---|---|---|
| `.sidebar` `#121212`, `--sidebar-width: 280px`, `z-index: 100` | `.erp-sider` (`Layout.Sider`), `width` = largura do utilizador, `z-index: 101` | Fixo (`sticky`); a marca não se desloca, só a árvore (`.erp-sider-rolagem`). |
| `.sidebar-resizer`: arrastar 220–800 px, `localStorage['sidebar-width']`, duplo clique repõe | `PuxadorMenu.tsx` + `larguraMenu.ts` (`.erp-menu-puxador`) | Mesmos limites e o mesmo aspecto (friso de 12 px, linha de 2 px, pega de 8 × 44 px, azul ao passar ou a arrastar). Guardado **por utilizador** em `erp.menu.largura.<id>`. Ponteiro e rato. Acrescentado: `role="separator"` com `aria-valuenow/min/max`, focável; ← → ±10 px (Shift ±50), Home/End aos limites, Enter/Delete repõem. Fica escondido com o menu recolhido e na gaveta. |
| `body.nav-so-simbolos .sidebar { width: var(--sidebar-estreita) }` (76 px) | `collapsedWidth = LARGURA_MENU_RECOLHIDO` (76) | «Recolher menu» (barra superior) alterna «só símbolos» ↔ aspecto anterior; ao expandir volta à largura escolhida. |
| `.sidebar-header`: 1,5rem, logótipo 40 px (4 px de raio), nome 1,2rem/700 branco, «GESTÃO EMPRESARIAL» 0,7rem `#94a3b8` | `.erp-sider-topo` + `MarcaEmpresa escuro comProduto` (`.erp-marca-escura`) | O nome é o da empresa activa (identidade); o produto é «ERP Consulvolt». |
| `.sidebar-nav` (padding 2rem 0, barra de deslocamento de 12 px) | `.erp-sider-rolagem` | Mesma barra (WebKit) e `scrollbar-color` (Firefox). |
| `.nav-busca` / `.nav-busca-campo` («Procurar operação...») / `.nav-busca-conta` | `.erp-menu-busca` (Input AntD) / `.erp-menu-busca-contador` | Placeholder igual ao legado; o nome acessível continua «Procurar no menu» (testes E2E). Fundo branco a 6 %, contorno a 12 %, foco azul `#3b82f6`; `mark` âmbar `#fbbf24`. |
| Itens do topo (`.nav-item` com `margin: .5rem 1rem; border-radius: 12px` → 4 px) | `.erp-menu-lista-topo` > `.erp-menu-topo` | 58 px, 1rem, 1rem entre símbolo e nome, barra de 4 px; activo `rgba(59,130,246,.1)` + barra `#3b82f6`. |
| `.nav-aspecto` (3 botões: «Símbolo e nome», «Só símbolos», «Só nomes») | `.erp-menu-aspecto` (`role="radiogroup"`, botões `role="radio"`) | Entre os itens do topo e os grupos, como no legado. O submenu «Aspecto do menu» do menu do utilizador continua. Preferência no servidor (`aspecto_nucleo`). |
| Botões «Expandir Tudo» / «Recolher Tudo» | `.erp-menu-expandir` | Só com a árvore aberta (não no recolhido nem durante a busca). |
| `.nav-group-header` (10 px 20 px, 1rem, `fa-chevron-down` 0,8rem que roda 180°) | `.ant-menu-submenu-title` de 1.º nível + `expandIcon` (`.erp-menu-seta`) | 46 px a toda a largura; a seta é um SVG próprio (sem `fx-icone`). |
| `.nav-group-items` (12 px, `margin-left` 18 px; níveis seguintes 22 px; guia `1px dashed rgba(255,255,255,.15)`) | `.ant-menu-sub.ant-menu-inline` | |
| 2.º nível (`.nav-group-items .nav-item` .6rem 1rem a .9rem; sub-grupos 8 px 15 px a .9rem) | idem em `identidade.css` | Activo com barra de 4 px. |
| 3.º nível (.85rem, `#94a3b8`, «•», activo azul a 15 % sem barra) | idem | |
| Hover `rgba(255,255,255,.03)`, texto `#D0D0D0` → branco | tokens `Menu.darkItemHoverBg`, `darkItemColor` (`tema.ts`) | `darkItemBg/darkSubMenuItemBg` passam a transparentes (o fundo é o do menu lateral). |
| `@media (max-width: 1024px)`: menu fora do ecrã, abre com o botão | `< 992 px`: gaveta `.erp-gaveta-menu` («Abrir menu») | Sem friso nem botões do aspecto na gaveta. |
| Tela nocturna: `.sidebar` `#0b1119` | `html.erp-tela-nocturna .erp-sider` + `TEMA_NOCTURNO.components.Layout.siderBg` | |

Seletores usados pelos E2E mantidos: `.erp-utilizador-botao`, `aria-label="Opções do utilizador"`, `.erp-menu-busca`,
«Abrir menu», `.erp-gaveta-menu`, «Recolher menu», `.erp-conteudo`, `.ant-layout-sider`. Alteração de estrutura: o menu
passou a dois `<Menu className="erp-menu …">` (`.erp-menu-lista-topo` e `.erp-menu-lista-grupos`) dentro de
`<nav class="erp-sider-rolagem" aria-label="Menu principal">`.

## 2. Tema base

| Legado (`styles.css`) | Novo | Onde |
|---|---|---|
| `--primary-color #2563eb`, `--primary-hover #1d4ed8`, `--primary-light #eff6ff` | `COR_PRIMARIA`, `COR_PRIMARIA_ESCURA`, `COR_PRIMARIA_CLARA` → `colorPrimary`, `colorLink`, `rowHoverBg`… | `estilos/tema.ts` |
| `--success #059669`, `--warning #f59e0b`, `--danger #ef4444` | `COR_SUCESSO`, `COR_AVISO`, `COR_PERIGO #dc2626` (escurecido para AA com texto branco) | `tema.ts` |
| `--bg-color #f8fafc`, `--border-color #e2e8f0`, texto slate | `FUNDO`, `LIMITE_SECUNDARIO`, `TEXTO*` | `tema.ts` |
| `--radius-*: 4px` («limites pouco arredondados» para cartões, KPI, ícones, separadores) | `borderRadius 4`; `.erp-kpi`, `.erp-kpi-icone`, `.painel-*`, `.erp-lista-topo` e filtros por coluna a 4 px | `tema.ts`, `vistas/padroesLegado.css`, `geral/comum/painel.css` |
| `.card` (moldura `#f1f5f9`, sombra muito suave), `.card-title` 700 `#475569` | `Card` AntD + `:root .ant-card` | `identidade.css` |
| `h2.view-title` 2rem/800 `#475569` | `CabecalhoPagina` (`.cabecalho-pagina .erp-titulo-pagina`) | `componentes/CabecalhoPagina.tsx`, `identidade.css` |
| `.stat-card` / `.painel-kpi` (ícone com fundo da cor a 10 %, rótulo em maiúsculas, valor a 800, barra de cor) | `CartaoKpi` + `GrelhaKpi` (`.erp-kpi`, `--erp-kpi-cor`), `CORES_KPI`; no Dashboard, `CartaoKpi` de `geral/comum/componentes.tsx` com `estiloKpi()` | `componentes/vistas/CartaoKpi.tsx` |
| `th`: `#f1f5f9`, 0,72rem/700 em maiúsculas; `td` tracejado `#cbd5e1`; hover `--primary-light` | tokens `Table` + `:root .ant-table-wrapper …` | `tema.ts`, `identidade.css` |
| `tfoot th`: `#f8fafc`, 0,85rem/800, `#2563eb` | `.ant-table-summary td` (e `.erp-linha-total`) | `identidade.css`, `padroesLegado.css` |
| `.data-table` densa (4 px × 1,2ch; 13,12 px) | `TabelaLegado` (`.erp-tabela-legado`) | `padroesLegado.css` |
| Zebra: só na tela nocturna (`tbody tr:nth-child(even)` a 2,5 %) | `html.erp-tela-nocturna … tr:nth-child(even)` | `layout/telaNocturna.css` |
| `.btn` / `.btn-primary` / `.btn-danger` (degradês «ERP clássico», 2 px, 0,85rem) | `Button` (default/primary/danger) + regras de degradê | `tema.ts`, `identidade.css` |
| `.tab-btn` (branco com moldura; activo azul cheio) | `Tabs` (tipo «line») | `identidade.css` |
| Chart.js: `PALETA` (10 cores), barras `cor+'cc'`, borda 1, raio 6, máx. 38 px; linhas `tension .35`, 3/2 px, área `cor+'22'`, pontos 3; donut borda 2 branca; legenda no topo, centrada, círculos | `estiloGraficos.ts` (`ESTILO_GRAFICO`, `CORES_SERIES`, `corSerie`) usado por `Graficos.tsx` e `GraficoPainel.tsx` | `componentes/graficos/**` |
| `tela_noturna.css` (variáveis `--n-*`, cartões, tabelas, botões, separadores) | `TEMA_NOCTURNO` (`darkAlgorithm` + cores do ERP) e `layout/telaNocturna.css` (cabeçalho, botões, separadores, cartões KPI/painel, tabelas, totais, gráficos) | `layout/temaNocturno.ts`, `layout/telaNocturna.css` |

## 3. Guia para os agentes de módulo

Regra geral: **não escrever cores, raios, sombras nem tamanhos de letra nos ecrãs**. O aspecto vem do tema e dos
componentes partilhados; o que o legado fazia com estilos inline (cartões com `border-radius: 12px`, por exemplo) ficava
a 4 px por uma regra global, e no novo também fica a 4 px.

1. **Título do ecrã:** `<CabecalhoPagina titulo=… subtitulo=… accoes=… impressao=… />` (`@/componentes/CabecalhoPagina`).
   Nada de `Typography.Title` solto para o título da página.
2. **Cartões:** `Card` do AntD sem `style` de moldura/sombra/raio (o tema já dá o `.card` do legado). Cartões com título
   usam `title`; separação interna com `Divider`, não com bordas próprias.
3. **Indicadores (KPI):** `<GrelhaKpi>` com `<CartaoKpi rotulo valor unidade secundaria icone cor barra aoClicar activo />`
   (`@/componentes/vistas`). `icone` é o nome Font Awesome do legado (ex.: `shopping-cart`); `cor` uma chave de
   `CORES_KPI` (`azul`, `verde`, `laranja`, `vermelho`, `roxo`, `cinzento`…) ou a cor exacta do legado. `barra="esquerda"`
   = cartões do Dashboard Global; `barra="baixo"` (omissão) = cartões dos dashboards de módulo (Vendas, POS, Lavandaria).
   Para indicadores dos painéis use `CartaoKpi` de `@/modulos/geral/comum/componentes` com `estiloKpi(painel, id, valor)`.
4. **Tabelas:** listas como as `.data-table` do legado → `TabelaLegado`/`TabelaComModos` (`@/componentes/vistas`), que
   já trazem densidade, filtros por coluna, linha «TOTAL:» e paginação do legado. `Table` simples herda o cabeçalho em
   maiúsculas, o tracejado e o realce ao passar; totais em `Table.Summary` (ficam a azul, 800). Não pôr zebra nem cores
   de fundo nas linhas (só estados com significado: vermelho para negativos, etc.).
5. **Gráficos:** só `GraficoAuto`/`GraficoBarras`/`GraficoLinhas`/`GraficoDonut` (`@/componentes/graficos/Graficos`).
   Cores: a ordem de `CORES_SERIES`; uma série com cor própria no legado (vendas `#2563eb`, compras `#ea580c`, proveitos
   `#10b981`, custos `#ef4444`…) passa `cor`. Nada de paletas locais nem de bibliotecas de gráficos.
   Cor por barra/fatia (o `backgroundColor: [...]` do legado): `coresCategorias` (lista pela ordem dos rótulos ou função
   `(rotulo, indice) => cor`) em `GraficoBarras`/`GraficoDonut`/`GraficoAuto`/`GraficoPainel`, ou `cores` numa série
   (p. ex. «Realizado» vermelho só nas rubricas acima do orçado). Sem isso vale a paleta de `estiloGraficos.ts`.
6. **Botões e separadores:** `Button` (`type="primary"` para a acção principal, `danger` para apagar) e `Tabs` sem estilos
   próprios. Separadores em pílula com a cor do módulo só no Dashboard (`painel.css`).
7. **Tela nocturna:** o que usa os componentes acima fica certo sozinho. As variáveis CSS do AntD estão ligadas (`cssVar: true` em
   `TEMA` e `TEMA_NOCTURNO`): em CSS e em `style` pode usar-se `var(--ant-color-primary)`, `var(--ant-color-bg-container)`,
   `var(--ant-color-text)`… (com o valor claro como alternativa), que seguem o tema activo. Na tela nocturna a primária
   derivada pelo `darkAlgorithm` é `#3572d4`. Cores fixas claras escritas no ecrã (`#fff`,
   `#f8fafc`, texto `#1e293b`…) ficam erradas no escuro — usar `theme.useToken()` (`token.colorBgContainer`,
   `token.colorText`, `token.colorFillAlter`…) quando for mesmo preciso.
8. **Responsivo:** `useEcra()`, `BarraFiltros`, `scrollTabela()` (`@/componentes/responsivo`) — ver o README de lá.

## 4. Diferenças por ecrã a tratar pelos agentes de módulo

Levantamento por pesquisa no código (`src/modulos/**`), a confirmar lado a lado com o legado antes de mudar:

- **Indicadores com `Statistic` do AntD em vez de `CartaoKpi`** — verificar se no legado são cartões `.stat-card`/KPI
  (então trocar por `CartaoKpi`) ou só totais de formulário (então ficam): acrescimos/DetalheItem, acrescimos/Registos,
  activos/Pendentes, activos/edificios/{Edificios,Folha}, compras/contratos/DetalheContrato, compras/faturacao/NovaFatura,
  compras/pedidos/NovoPedido, compras/prospeccao/NovaProposta, config/Perfis, config/validacoes/Validacoes,
  contab/comum/{Componentes,Demonstracao}, contab/lancamentos/ModalSaldosHistoricos,
  contab/mapas/{ControloDesequilibrios,MapaBalancete}, crm/Contas, metodo/Assuntos, orcamento/Cenarios,
  pos/comum/PainelPagamentos, pos/frente/PainelVenda, pos/lavandaria/Recepcao, projectos/Extracto,
  projectos/separadores/{Execucao,Orcamento,Organigrama,Resumo}, rh/{Assiduidade,Portal}, rh/assiduidade/EscalaMes,
  rh/comum/SimulacaoColaborador, rh/portal/PortalFaseB, stock/comum/Inventario,
  teso/{Conciliacao,Conferencia,FolhaCaixa,MapaPendentes,Mapas}, teso/pagamentos/FormularioDocumento, vendas/EmitirDocumento.
- **Cartões KPI com classes próprias** (`est-kpi`): estrutura/Estrutura e estrutura/MapaPessoal — passar a `CartaoKpi`
  se o legado usar `.stat-card`; pos/lavandaria/Ordens idem.
- **Raios ≥ 8 px escritos no ecrã** (o legado forçava 4 px): activos/{Amortizacoes,Manutencao},
  activos/inventario/AlertaReconciliacao, compras/comum/{FluxoDocumental,LinhasProdutos}, config/plano/PlanoContasParidade,
  contab/lancamentos/ModaisParidade, contab/mapas/MapaExtrato, crm/Pipeline, pos/{ConfigImpressao,FrenteCaixa},
  pos/frente/{AberturaCaixa,BarraSessaoFrente,GrelhaProdutos,ModoImersivo,PainelMesas,PainelQuartos,PainelVenda},
  rh/Colaboradores, rh/folha/ListaProcessamentos, stock/{Movimentos,PosArmazem}, stock/posArmazem/Balcao,
  teso/pagamentos/ListaDocumentos, vendas/{EmitirDocumento,ModalFluxoProcesso}, vendas/agt/FaturacaoEletronica,
  vendas/formulario/QuadrosFormulario, vendas/relatorios/EcraSaft. (Os círculos — `50%`, crachás em pílula — ficam.)
- **Paleta própria de gráfico:** geral/RelatoriosGestao (`CORES_A`/`CORES_B`) — confirmar com o legado
  (`js/ui_reports.js`/relatórios de gestão); se forem as cores do legado ficam, senão passam a `CORES_SERIES`.
- **Título fora de `CabecalhoPagina`:** geral/fluxo/componentes (`Typography.Title` de nível 2/3) — confirmar se é o
  título do ecrã.
- **Tela nocturna:** ecrãs com fundos/texto claros fixos nos estilos inline continuam claros no escuro (o legado
  traduzia-os por atributo `style`); corrigir com `theme.useToken()` ao passar por cada ecrã.

## 5. Testes

- `src/layout/larguraMenu.test.ts`: limites, teclas, leitura por utilizador e o gancho (gravar, repor, teclado).
- `src/layout/LayoutPrincipal.test.tsx` («menu lateral igual ao legado»): arrastar com o rato (420 → 800 → 220 → 380,
  gravado por utilizador, cursor reposto, ouvintes retirados), duplo clique repõe, teclado no separador, largura lida ao
  entrar, recolhido a 76 px sem friso e regresso à largura escolhida, botões do aspecto, «Expandir/Recolher tudo», seta
  que roda, gaveta sem friso nem aspecto.
- `src/componentes/graficos/estiloGraficos.test.ts`: paleta e parâmetros do Chart.js do legado.
