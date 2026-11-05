# Guia de responsividade do frontend

Complementa `frontend/src/componentes/responsivo/README.md` (grelhas, `BarraFiltros`, `larguraModal`…).

## Pontos de quebra

| Nome | Largura | Uso típico |
|---|---|---|
| xs | < 576 px | telemóvel (360–414): 1 coluna, menu em gaveta |
| sm | ≥ 576 px | telemóvel grande / tablet pequeno |
| md | ≥ 768 px | tablet (768) |
| lg | ≥ 992 px | portátil pequeno (1024): menu lateral/topo visível |
| xl | ≥ 1200 px | portátil (1366) |
| xxl | ≥ 1600 px | ecrã grande (1920, 2560) |

## Regras de ouro

1. **Valores nunca partem a meio.** Moeda, números e datas usam `white-space: nowrap` e `font-variant-numeric: tabular-nums`
   (classe `.erp-valor`, componente `<ValorMonetario>`, `.ant-statistic-content` já tratado globalmente).
   A moeda liga-se ao número com espaço não separável (` `).
2. **Rótulos e texto livre só quebram em fronteiras de palavras** (`overflow-wrap: break-word; word-break: normal`).
   Evite `overflow-wrap: anywhere` / `word-break: break-all` (só para IBAN, URLs, hashes, ficheiros).
3. **Resumos de valores** (subtotal, desconto, total…) usam `<ResumoValores>`: grelha `auto-fit` — cada bloco ocupa o que
   precisa e, ao faltar espaço, passa à linha seguinte. Nunca `Descriptions bordered column={4}` com `maxWidth` fixo.
4. **Descriptions** ficam com `table-layout: auto` (CSS global): colunas medidas pelo conteúdo. Use
   `column={COLUNAS_DESCRICOES}` (ou `{ xs:1, sm:1, md:2, lg:3, xl:3, xxl:4 }`).
5. **Tabelas**: `scroll={{ x: 'max-content' }}`, cabeçalhos/células em nowrap; colunas de texto livre com
   `className: 'erp-quebra'` (quebra entre palavras, largura mínima 160 px) ou `ellipsis`. Evite `width` fixo em todas as colunas.
6. **Cartões/Statistic**: `min-width: 0` global; título do cartão e `extra` quebram linha; em xs o valor do `Statistic` desce para 20 px.
7. **Modais/gavetas**: `larguraModal(px)` / `larguraGaveta(px)`; nunca maiores do que o ecrã.
8. **Larguras fixas**: `maxWidth` + `width: '100%'`; nada de `width`/`minWidth` > 340 px em contentores.

9. **`Col span={N}` sem `xs`**: em < 576 px empilha em largura total (regra global). Para manter lado a lado em telemóvel,
   use `xs={12}` ou a classe `erp-col-fixa`.
10. **Cabeçalho do ERP**: pesquisa por extenso e rótulos dos botões («Menu no topo», «Trocar empresa», «Ajuda») só em ≥ 1600 px;
    abaixo só ícones (a pesquisa passa a botão); o nome/papel do utilizador esconde-se < 1200 px.

## Utilitários

| O quê | Onde |
|---|---|
| `ResumoValores`, `ValorMonetario`, `formatarValor` | `frontend/src/componentes/responsivo/` (exportados em `index.ts`) |
| CSS global (descrições, cartões, tabelas, resumo, modais) | `frontend/src/estilos/responsividade.css` |
| `.erp-valor`, `.erp-resumo-valores*`, `.erp-quebra` | idem |
| Base anterior (`BarraFiltros`, `useEcra`, `COL_CAMPO`, `scrollTabela`…) | `componentes/responsivo/` + `estilos/global.css` |

```tsx
<ResumoValores itens={[
  { rotulo: 'Subtotal bruto', valor: <ValorMonetario valor="9 500" moeda="AOA" /> },
  { rotulo: 'Preço mensal efectivo', valor: <ValorMonetario valor="9 500" moeda="AOA" sufixo="/mês" /> },
  { rotulo: 'Valor a pagar', destaque: true, valor: <ValorMonetario forte valor="9 500" moeda="AOA" /> },
]} />
```

## Menu no topo

O modo «Menu no topo» replica o aspecto da produção (`estilos/menuTopo.css`): barra `#121212` com o logótipo, os módulos
lado a lado (abrem ao clicar, lista escura compacta `.erp-menu-barra-topo-popup`) e a linha «Favoritos» em pílulas.
Só existe em ecrãs ≥ 992 px; abaixo disso o menu é a gaveta. A estrutura e as permissões dos itens não mudam.

## Como testar

- Unitário: `cd frontend && npx vitest run src/componentes/responsivo`.
- Auditoria multi-largura (360, 414, 768, 1024, 1366, 1920, 2560): `npm run build` em `frontend/`, depois, na raiz do repo,
  `node ferramentas/qualidade/responsividade.mjs [--larguras=360,1366] [--urls=/,/portal] [--capturas=pasta]`.
  Ignora o excesso ≤ 16 px do `.erp-conteudo` (margem negativa das `Row` com gutter); os falsos positivos de `Tabs` com
  deslocamento próprio não contam.
  Usa o utilizador fictício do ambiente E2E (`docker exec erp_app_e2e php artisan erp:e2e:preparar --force` prepara a base
  `erp_consulvolt_e2e`); URL por `E2E_URL` (omissão `http://127.0.0.1:8081`). Falha se a página tiver scroll horizontal,
  o `.erp-conteudo` exceder a largura ou houver valores cortados.
- E2E existente: `frontend/e2e/responsivo.e2e.ts` (375 px, todos os ecrãs do catálogo).
