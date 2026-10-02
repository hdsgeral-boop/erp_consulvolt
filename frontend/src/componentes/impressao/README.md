# Impressão e PDF (motor comum)

O PDF é gerado pelo **motor de impressão do navegador**: o documento HTML é montado numa iframe oculta
(`about:blank`, sem scripts — compatível com a CSP `script-src 'self'`), com `@page` de tamanho/orientação
calculados, e o utilizador escolhe **«Guardar como PDF»** no diálogo. Fica vectorial, com texto seleccionável.
Não usar jsPDF/pdfmake/html2canvas nem `window.print()` da página.

Cada documento leva: **logótipo + nome da empresa** (NIF, morada, contactos em letra pequena), título,
período/filtros, data/hora de emissão e utilizador; rodapé com **«Página X de Y»** e o rodapé da empresa
(caixas de margem `@page`, Chromium ≥ 131). Nunca há barras de deslocação; o cabeçalho das tabelas repete-se
em cada página; os totais (`tfoot`) saem só no fim.

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
> Testes: `construirDocumento(opcoes, formato)` devolve o HTML final; `prepararDocumento(opcoes)` mede e decide o formato.

## API

```ts
imprimirDocumento(o: OpcoesDocumento): Promise<FormatoPagina>        // imprime (diálogo do navegador)
prepararDocumento(o: OpcoesDocumento): Promise<{ html, formato, nomeFicheiro }> // sem imprimir (testes)
construirDocumento(o: OpcoesDocumento & { conteudo: string }, formato?, estilos?): string
decidirFormato({ medir(larguraPx, quebrar), colunas?, papel?, orientacao? }): FormatoPagina
tabelaHtml<T>({ colunas, linhas, legenda?, totais?, agrupar?, vazio? }): string
clonarParaImpressao(elemento, { aoClonar? }): string
useImpressao(): { imprimir(pedido), aImprimir, obterIdentidade }
<BotoesExportar obterPedido={() => pedido | Promise<pedido>} />     // «Imprimir» + «PDF»
<BotaoImprimir obterPedido={…} modo="imprimir" | "pdf" />

OpcoesDocumento = { titulo, subtitulo?, periodo?, filtros?, identidade?, utilizador?, emitidoEm?,
  conteudo: string | Element, orientacao?: 'auto'|'retrato'|'paisagem', papel?: 'auto'|'A4'|'A3',
  nomeFicheiro?, rodape?, estilosDaPagina?, cssExtra?, cabecalho? }
```

O nome de ficheiro sugerido é «Título - Empresa - AAAA-MM-DD» (título do documento e, durante o diálogo, da janela).
