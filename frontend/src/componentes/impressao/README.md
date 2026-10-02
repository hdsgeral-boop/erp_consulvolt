# Impressão e PDF (motor comum)

O PDF é gerado pelo **motor de impressão do navegador**: o documento HTML é montado numa iframe oculta
(`about:blank`, sem scripts — compatível com a CSP `script-src 'self'`), com `@page` de tamanho/orientação
calculados, e o utilizador escolhe **«Guardar como PDF»** no diálogo. Fica vectorial, com texto seleccionável.
Não usar jsPDF/pdfmake/html2canvas nem `window.print()` da página.

Cada documento leva: **logótipo + nome da empresa** (NIF, morada, contactos em letra pequena), título,
período/filtros, data/hora de emissão e utilizador (só na 1.ª página, como no sistema anterior); em **todas** as
páginas, o rodapé da empresa e **«Página X de Y»**. Nunca há barras de deslocação; o cabeçalho das tabelas repete-se
em cada página; os totais (`tfoot`) saem só no fim.

## Paginação feita pelo motor (Chromium/Edge **e** Firefox) — `paginacao.ts`

O Firefox não suporta as caixas de margem do `@page` (`@bottom-right { content: counter(page) }`), por isso a
numeração não pode vir do navegador. Depois de decidido o formato, o documento da iframe é partido em folhas
`<section class="imp-pagina">` com a altura útil exacta da página (`alturaPaginaMm`: papel − margens 10/10/8/10 mm −
0,8 mm de segurança), cada uma com `<footer class="imp-pagina-rodape">` («rodapé da empresa … Página X de Y») e
`break-after: page`. O `@page` fica só com tamanho e margens.

- Os nós são movidos para a folha corrente e é medido se cabem (layout real da iframe, à largura útil da página e com
  a escala já aplicada). Um contentor que não cabe é **aberto** (Card, div, tabela, tbody…) e a cadeia de contentores
  é recriada na folha seguinte («cascas» sem filhos).
- **Indivisíveis** (passam inteiros): linhas de tabela, `thead`/`tfoot`, imagens, SVG, canvas, parágrafos, títulos,
  elementos com `break-inside: avoid` ou `.imp-sem-quebra`, e linhas flex sem quebra (ex.: linhas do Gantt).
- **Repetidos no topo de cada folha**: `<thead>`, `<colgroup>` e qualquer elemento com a classe **`.imp-repetir`**
  (ex.: a linha de meses/semanas do Gantt). As larguras das colunas de cada tabela são fixadas antes de partir
  (percentagens), para todas as folhas terem as mesmas colunas.
- Títulos, legendas, `.ant-card-head`, `tr.imp-grupo` e `.imp-manter-com-seguinte` não ficam sozinhos no fundo.
- Quebras pedidas: `break-before/after: page` e `.imp-quebra-pagina`. As regras `@media print` do CSS copiado do
  ecrã passam a valer já na medição (e as `@media screen` deixam de valer), para a medição bater com o papel.
- Um bloco indivisível mais alto do que uma folha inteira é reduzido com `zoom` (mínimo 25 %).
- Sem scripts no documento impresso (CSP respeitada); a iframe continua `about:blank`.

**Recurso:** com `paginar: false`, ou se a paginação falhar (excepção — fica registado um aviso na consola), o
documento é reescrito sem folhas e o `@page` volta às caixas de margem: o «Página X de Y» só aparece no
Chromium/Edge. **Limites conhecidos:** uma célula com `rowspan` que atravesse a quebra não é partida; um bloco de
texto contínuo (um só parágrafo) maior do que uma folha é reduzido em vez de partido; a medição faz-se no ecrã
(96 dpi) — 1,5 mm de folga em cada folha absorvem as diferenças de arredondamento da impressão.

**Papel/orientação automáticos** (medição real da largura do conteúdo): A4 retrato (190 mm úteis) →
A4 paisagem (277 mm, aceitando até 85 % de redução) → A3 paisagem (400 mm) → só então reduz a escala (mín. 55 %; abaixo disso deixa o texto
das células quebrar). Tabelas com ≥ 9 colunas vão logo para paisagem. Pode impor-se `orientacao`/`papel`.
Os talões térmicos do POS ficam fora desta regra.

## Como pôr Imprimir/PDF num ecrã (texto para os prompts)

> Para imprimir/exportar PDF usa **só** o motor comum `@/componentes/impressao` (nada de `window.print()`,
> jsPDF ou html2canvas). Escolhe um dos padrões:
>
> 1. **Listagem paginada (TabelaApi)** — passa `impressao={{ titulo: 'Lista de …', periodo?, filtros?: ['Estado: Activo'] }}`.
>    Aparecem «Imprimir» e «PDF»; imprime **todas as páginas** do mesmo endpoint com os filtros actuais (até 5 000
>    linhas, com aviso). Saem as colunas com título (as de acções, sem título, ficam de fora); o texto vem do
>    `render`. Por coluna: `exportar: false` (não imprimir), `valorImpressao: (linha) => texto` (texto próprio),
>    `totalImpressao: (linhas) => texto` (linha de totais).
> 2. **Mapa com dados já carregados** — gera HTML limpo com `tabelaHtml({ colunas, linhas, totais?, agrupar? })`
>    (colunas: `{ titulo, valor: (l) => …, formato?: 'moeda'|'numero'|'inteiro'|'data'|'percentagem', somar?, quebrar? }`)
>    e põe os botões no cabeçalho:
>    `<CabecalhoPagina titulo="…" impressao={() => ({ titulo: 'Mapa de …', periodo: '…', filtros: [...], conteudo: tabelaHtml({...}) })} />`
>    (ou `<BotoesExportar obterPedido={() => ({ ... })} />` noutro sítio).
> 3. **Conteúdo visual do ecrã** (organigramas, gráficos, demonstrações, recibos, cartas) — passa o elemento:
>    `conteudo: ref.current` (é clonado e limpo: botões, `.no-print`/`.imp-nao-imprimir` e paginação saem; as
>    tabelas Ant Design perdem o scroll/cabeçalho fixo; o CSS do ecrã é copiado). Marca com `className="imp-nao-imprimir"`
>    o que não deve ir para o papel e com `imp-so-ecra` o que repete o cabeçalho da empresa.
>
> Nunca escrevas o nome/logótipo da empresa à mão no conteúdo impresso: o motor põe-nos (via `useIdentidade`).
> Para controlo total usa o hook `const { imprimir } = useImpressao(); imprimir({ titulo, conteudo, modo: 'pdf' })`.
> Para repetir uma linha de cabeçalho própria (fora de `<thead>`) no topo de cada página, marca-a com `className="imp-repetir"`;
> para um bloco não ser partido, `imp-sem-quebra`; para forçar página nova a seguir, `imp-quebra-pagina`.
> Testes: `construirDocumento(opcoes, formato)` devolve o HTML sem folhas; `prepararDocumento(opcoes)` mede, decide o formato e pagina.

## API

```ts
imprimirDocumento(o: OpcoesDocumento): Promise<FormatoPagina>        // imprime (diálogo do navegador)
prepararDocumento(o: OpcoesDocumento): Promise<{ html, formato, nomeFicheiro, paginas }> // sem imprimir (testes)
paginarDocumento(doc, formato, rodape): { paginas }                   // folhas + «Página X de Y» (usado pelo motor)
construirDocumento(o: OpcoesDocumento & { conteudo: string }, formato?, estilos?): string
decidirFormato({ medir(larguraPx, quebrar), colunas?, papel?, orientacao? }): FormatoPagina
tabelaHtml<T>({ colunas, linhas, legenda?, totais?, agrupar?, vazio? }): string
clonarParaImpressao(elemento, { aoClonar? }): string
useImpressao(): { imprimir(pedido), aImprimir, obterIdentidade }
<BotoesExportar obterPedido={() => pedido | Promise<pedido>} />     // «Imprimir» + «PDF»
<BotaoImprimir obterPedido={…} modo="imprimir" | "pdf" />

OpcoesDocumento = { titulo, subtitulo?, periodo?, filtros?, identidade?, utilizador?, emitidoEm?,
  conteudo: string | Element, orientacao?: 'auto'|'retrato'|'paisagem', papel?: 'auto'|'A4'|'A3',
  nomeFicheiro?, rodape?, estilosDaPagina?, cssExtra?, cabecalho?, paginar? }
```

O nome de ficheiro sugerido é «Título - Empresa - AAAA-MM-DD» (título do documento e, durante o diálogo, da janela).
