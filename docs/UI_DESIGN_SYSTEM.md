# Design system do ERP (UI)

O ERP **não tem um 2.º design system**: o tema do Ant Design 6 (`frontend/src/estilos/tema.ts`, `ConfigProvider` em
`src/main.tsx`) continua a ser a fonte das cores, raios e tamanhos dos componentes. O que o CSS próprio precisa (espaçamentos,
sombras, transições, tipografia fluida, foco) está centralizado em **`frontend/src/estilos/designSystem.css`**, como variáveis
`--erp-*` na `:root`. O teste `src/estilos/designSystem.test.ts` garante que as cores CSS coincidem com as de `tema.ts`.

Princípio (Implementar.txt §18): modernização **incremental** — a identidade visual, a posição dos módulos, a hierarquia dos
menus, a ordem dos campos e o dashboard não mudam.

## Folhas de estilo (ordem de importação em `main.tsx`)

| Ficheiro | Papel |
|---|---|
| `global.css` | base (box-sizing, layout principal, sider, cabeçalho, pesquisa, impressão) |
| `designSystem.css` | **tokens `--erp-*`**, foco visível, estados de botões/cartões, toque, redução de movimento, «saltar para o conteúdo» |
| `identidade.css` | identidade herdada do sistema anterior (degradês dos botões, menu lateral, cartões de entrada, módulos) |
| `escalaResponsiva.css` | ajustes de densidade < 768 / < 480 px |
| `responsividade.css` | valores sem quebra, descrições, cartões, resumo de valores, tabela em cartões, colunas empilhadas em xs |
| `menuTopo.css` | modo «Menu no topo» (≥ 992 px) |

Regra: uma propriedade, um sítio. Foram removidas as duplicações (largura máxima do modal em 3 folhas, `min-width` das tabelas,
preenchimento do conteúdo que ficava sobreposto por `!important`).

## Tokens

| Grupo | Variáveis |
|---|---|
| Cores | `--erp-cor-primaria` `#2563eb`, `-escura`, `-clara`; `--erp-cor-sucesso`, `--erp-cor-aviso`, `--erp-cor-perigo`; texto (`--erp-cor-texto`, `-secundario`, `-terciario`); `--erp-cor-limite`, `--erp-cor-limite-suave`, `--erp-cor-fundo`; menu (`--erp-cor-menu`, `--erp-cor-realce-menu`) |
| Foco | `--erp-foco` (azul escuro, ≥ 3:1 sobre claro) e `--erp-foco-escuro` (sobre o menu escuro) |
| Raios | `--erp-raio-botao` 2 px, `--erp-raio` 4 px, `--erp-raio-painel` 8 px, `--erp-raio-sobreposicao` 12 px |
| Sombras | `--erp-sombra-1/2/3` (discreta, média, sobreposição) |
| Espaçamento | `--erp-esp-1…6` (4, 8, 12, 16, 24, 32 px) |
| Tipografia | `--erp-fs-pequeno/base`; fluida: `--erp-fs-titulo-pagina`, `--erp-fs-titulo-cartao`, `--erp-fs-valor-kpi` (`clamp()`) |
| Movimento | `--erp-dur-rapida` 150 ms, `--erp-dur` 180 ms, `--erp-dur-lenta` 220 ms, `--erp-ease`, `--erp-elevacao` (−1 px), `--erp-compressao` (0,98) |
| Pontos de quebra | xs < 576 · sm ≥ 576 · md ≥ 768 · lg ≥ 992 · xl ≥ 1200 · xxl ≥ 1600 (iguais ao AntD e a `useEcra`; as media queries não aceitam `var()`) |

## Componentes e estados

**Botões** (`.ant-btn`, degradês de `identidade.css`): transição de 180 ms em `transform/box-shadow/cor`; *hover* — mudança de
luminosidade (degradê) + elevação de 1 px e sombra (só em rato, `@media (hover: hover)`, não nos botões `sm`, `link`, `text`,
desactivados ou a carregar); *active* — compressão (`scale(.98)`); *focus-visible* — contorno de 2 px `--erp-foco` com
afastamento de 2 px (azul claro sobre fundos escuros); *disabled* — cursor `not-allowed`; *loading* — cursor `progress` e o
indicador do AntD. Em ecrãs tácteis (`pointer: coarse`) os botões têm 40 px de altura mínima.

**Cartões** (`.ant-card`): raio, borda e sombra suaves de `identidade.css`. **Só os interactivos reagem**: `Card hoverable` ou
`className="erp-cartao-interactivo"` sobem 2 px, ganham borda primária e sombra e têm `:focus-within`; os informativos não têm
hover. Use `erp-cartao-interactivo` apenas em cartões clicáveis (e dê-lhes um elemento focável/`role="button"`).

**Menus** (`.erp-menu`, `menuTopo.css`): item activo com barra lateral de 3 px (forma, não só cor), seta do submenu que roda
quando expandido, transições de 180 ms em fundo/cor, foco visível a azul claro. A hierarquia e os destinos não mudam.

**Gráficos** (`componentes/graficos`, `modulos/geral/comum/GraficoPainel`, `plataforma/GraficosPainel`): SVG próprio com
`ResizeObserver` (recalculam ao mudar o contentor), legenda a quebrar linha, tooltip `role="status"`, `aria-label` em cada barra/
ponto, alternância gráfico/tabela, animação de entrada de 450 ms por `transform/opacity` (desligada em `prefers-reduced-motion`
e na impressão) e realce `brightness(1.08)` ao passar.

**Tabelas**: contentor com deslocamento horizontal próprio (`.ant-table { overflow-x: auto }` global), nunca na página. Opcional,
para listagens simples sem linhas expansíveis: `className={CLASSE_TABELA_CARTOES}` + `columns={comRotulosDeCartao(colunas)}`
(`componentes/responsivo/tabelaCartoes.tsx`) transforma cada linha em cartão «título: valor» abaixo de 576 px, sem esconder dados.

**Valores financeiros**: `<ValorMonetario>`, `<ResumoValores>`, `.erp-valor` (nunca partem a meio, algarismos tabulares).

## Regras de animação

1. Só `transform`, `opacity` e cor/borda; nunca `width/height/top/left` de áreas grandes.
2. 150–220 ms; nada que atrase a acção (a acção corre já; a animação só acompanha).
3. Sem animações contínuas (o único movimento infinito permitido é o indicador de carregamento do AntD).
4. `prefers-reduced-motion: reduce` (bloco único em `designSystem.css`): transições/animações a ~0 ms, sem elevação/compressão;
   os indicadores de carregamento (`ant-spin`, `anticon-spin`) mantêm-se para não esconderem o estado.
5. Hover só em dispositivos com rato (`@media (hover: hover)`).
6. Estados nunca só por cor: activo do menu (barra), erro/aviso (ícone + texto no AntD `Alert`/`Form`), desactivado (cursor), selecção
   (marca de verificação).

## `!important`

Reservado a (a) a regra de redução de movimento (tem de vencer animações inline e do AntD) e (b) legados anteriores em
`escalaResponsiva.css`/`identidade.css` que se mantêm até serem convertidos em regras com especificidade (`:root .x`).
Não acrescentar novos.
