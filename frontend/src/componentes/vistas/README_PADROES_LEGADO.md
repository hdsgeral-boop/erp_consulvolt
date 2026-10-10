# Padrões das listas do sistema anterior (vaga 1A)

Objectivo: as listas do sistema novo têm o aspecto e o comportamento das DataTables do antigo
(`payroll_system_web`: `js/app_v2.js` preInit/DataTable, `js/shared/tabelas_padrao.js`, `css/styles.css`).
Relatório de referência: `verificacao_visual/RELATORIO.md` (G.1–G.4, G.7, 1.1.5, 3.2.2, 4.3.5).

Tudo está em `@/componentes/vistas` (exportado por `index.ts`). Estilos: `padroesLegado.css`.

## 1. Listas: `TabelaComModos` (dados locais) e `TabelaApi` (paginada no servidor)

As duas usam `TabelaLegado` por dentro. **Sem alterar o ecrã**, todas as listas passam a ter:

| Activo por omissão | O quê | Desligar |
|---|---|---|
| G.1 | Caixa por cima: «**N registos encontrados**» (azul) à esquerda; à direita `barra` do ecrã · Excel · PDF · Imprimir (+ orientação/papel) · **Colunas ▾** · **Filtro personalizado** · Linhas/Grade | `contador={false}`, `menuColunas={false}`, `filtroPersonalizado={false}` |
| G.2 | Linha de filtros por baixo dos cabeçalhos (caixa «contém» + funil com Contém/Não contém/Começa/Termina/Igual/Diferente/>/<) | `filtrosColuna={false}` (ecrã) ou `filtravel: false` (coluna) |
| G.4 | «Mostrar [10/25/50/100/Ver Todos] registos» à esquerda; «Anterior · 1 · Próximo» à direita (50 por omissão) | `paginacaoLegado={false}` |
| G.7 | Estado vazio com ícone grande («Nenhum registo encontrado») | `vazio={…}` ou `locale.emptyText` |

Excel · PDF · Imprimir aparecem quando o ecrã passa `impressao={{ titulo: '…' }}` (nas listas locais imprime as
linhas filtradas e ordenadas; na `TabelaApi` lê todas as páginas do endpoint). As colunas escondidas em «Colunas ▾»
não vão para o papel.

**«Colunas ▾»** fica lembrado por utilizador e ecrã (preferência `interface/colunas_listas` no servidor + cópia no
`localStorage`). Por coluna: `ocultavel: false` (sempre visível), `ocultaPorOmissao: true` (começa escondida).

**Filtros na `TabelaApi`**: o servidor não tem filtro genérico por coluna, por isso as caixas filtram a página
carregada (o contador avisa: «filtro aplicado aos 50 registos desta página…»). Uma coluna com
`filtroServidor: 'parametro'` envia o texto ao endpoint (400 ms de atraso, volta à 1.ª página). «Ver Todos» carrega
todas as páginas (até 5 000) e aí os filtros valem para tudo.

### Novas props (as antigas continuam a funcionar)

```tsx
<TabelaComModos<Factura>
  rowKey="id"
  dataSource={facturas}
  columns={colunas}
  impressao={{ titulo: 'Facturas', filtros: ['Ano: 2026'] }}       // Excel · PDF · Imprimir
  accoes={(f) => [                                                  // coluna «AÇÕES» automática no fim
    { icone: 'fa-project-diagram', titulo: 'Ver Processo', onClick: () => abrirFluxo(f.id) },
    { icone: 'fa-print', titulo: 'Imprimir', onClick: () => imprimir(f) },
    { icone: 'fa-undo', titulo: 'Descontabilizar', variante: 'perigo', oculto: !f.contabilizado,
      confirmar: 'Descontabilizar o documento?', onClick: () => descontabilizar.mutateAsync(f.id) },
    { icone: 'fa-trash', titulo: 'Eliminar', variante: 'perigo', cheio: true,
      desactivado: f.contabilizado ? 'Eliminação bloqueada: documento contabilizado' : false,
      confirmar: { titulo: 'Eliminar a factura?', texto: 'Esta operação não pode ser desfeita.' },
      onClick: () => eliminar.mutateAsync(f.id) },
  ]}
  seleccao={{                                                       // «Seleccionadas: N» + acções em massa
    editarEmMassa: { onClick: (linhas) => abrirEdicaoMassa(linhas) },   // «Editar em massa (N)»
    accoes: [{ texto: 'Contabilizar seleccionados', icone: 'book', variante: 'primario', onClick: (linhas, chaves) => … }],
    ajuda: 'Marque os documentos para acções em massa.',
    bloquearLinha: (f) => f.anulado && 'Documento anulado',
  }}
  vazio={{ icone: 'file-invoice', titulo: 'Sem facturas', texto: 'Ainda não há facturas neste período.' }}
/>
```

| Prop | Tipo | Notas |
|---|---|---|
| `accoes` | `(linha, i) => AccaoLinha[]` | Coluna «Ações» (`tituloAccoes` muda o título). Também vai para o rodapé do cartão da grade. |
| `seleccao` | `OpcoesSeleccao<T>` | `accoes`, `editarEmMassa`, `ajuda`, `chaves`/`aoMudar` (controlado), `bloquearLinha`. Mantém a selecção entre páginas. |
| `impressao` | `ImpressaoLista` | `titulo`, `subtitulo`, `periodo`, `filtros`, `orientacao`, `papel`, `nomeFicheiro`, `rotuloTotal`, `excel`. |
| `vazio` | `PropsEstadoVazio \| ReactNode` | Estado vazio. |
| `rotuloTotal` | `ReactNode \| false` | «TOTAL:» por omissão. |
| `contador`, `menuColunas`, `filtrosColuna`, `filtroPersonalizado`, `paginacaoLegado` | `boolean` | Todos `true` por omissão. |

### Extras por coluna (`ColunaLegado<T>` = `ColunaApi<T>`)

```ts
{ title: 'Total (Kz)', dataIndex: 'total', align: 'right', render: formatarKz,
  total: true,                 // G.3 — linha «TOTAL:» (soma); 'media' | 'contagem' | (linhas) => ReactNode
  formatoTotal: (v) => formatarKz(v, true),   // por omissão formatarKz
  filtravel: false,            // sem caixa de filtro
  filtroServidor: 'total_min', // TabelaApi: filtro enviado ao endpoint
  valorFiltro: (l) => l.cliente.nome,          // texto usado para filtrar (por omissão o texto da célula)
  ocultavel: false, ocultaPorOmissao: true }
```

A linha de totais soma as linhas **filtradas** (todas as páginas; na `TabelaApi` a página carregada, ou tudo com
«Ver Todos») — ao contrário do bug do antigo (4.2.7), soma mesmo. Lê números brutos (`1500.50`) ou texto pt-PT
(«1 500,50 Kz»).

## 2. `AccoesLinha` / `BotaoAccao` (botões-ícone do antigo)

```ts
interface AccaoLinha {
  icone: string;            // 'eye' | 'fa-eye' | 'fas fa-eye' (nomes da v5; alguns da v6 aceites)
  titulo: string;           // dica e nome acessível (obrigatório)
  onClick?: () => unknown;  // se devolver Promise, o botão mostra «a carregar»
  variante?: 'normal' | 'primario' | 'perigo' | 'sucesso' | 'aviso' | 'info';
  cheio?: boolean;          // degradê preenchido (btn-danger/btn-success do antigo)
  cor?: string;             // cor própria do ícone
  desactivado?: boolean | string;  // texto = dica (ex.: «Edição bloqueada (processado)»)
  confirmar?: boolean | string | { titulo?, texto?, textoOk?, textoCancelar?, perigo? };
  oculto?: boolean; carregando?: boolean; texto?: ReactNode; chave?: string;
}
<AccoesLinha accoes={[…]} />   // fora das tabelas (detalhes, cartões)
<BotaoAccao accao={…} />
```

O clique não dispara o `onRow` da linha. Ícones disponíveis: `componentes/fluxos/iconesFa.ts` (Font Awesome 6.4 Free
em SVG; acrescentar novos com o mesmo formato). `IconeLegado` desenha um ícone pelo nome do antigo.

## 3. `BarraSeleccao` / `BotaoEditarEmMassa`

Usados pela tabela com `seleccao`; também se podem usar soltos:
`<BarraSeleccao linhas={linhas} chaves={chaves} accoes={[…]} editarEmMassa={{ onClick }} ajuda="…" aoLimpar={…} />`.
`AccaoMassa`: `texto`, `icone`, `variante` (`primario`/`perigo`/`sucesso`), `onClick(linhas, chaves)`,
`desactivado`, `confirmar` (`{n}` = número seleccionado), `soComSeleccao`.

## 4. `CartaoKpi` + `GrelhaKpi` (cartões com barra de cor)

```tsx
<GrelhaKpi larguraMinima={220}>
  <CartaoKpi rotulo="Faturação total POS" valor={formatarKz(total)} unidade="Kz" icone="coins" cor="roxo" />
  <CartaoKpi rotulo="Vendas do mês" valor="…" secundaria="Faturação activa" icone="shopping-cart" cor="azul" barra="esquerda" />
  <CartaoKpi rotulo="Desvios" valor={n} cor="vermelho" corValor="#dc2626" aoClicar={() => filtrar('desvios')} activo={filtro === 'desvios'} />
</GrelhaKpi>
```

`barra`: `'baixo'` (omissão — Dashboard de Vendas 4.3.5, Relatórios POS 3.2.2, Lavandaria 3.8.3) ou `'esquerda'`
(Dashboard Global 1.1.5); `cor`: chave de `CORES_KPI` (`azul`, `primario`, `verde`, `sucesso`, `verdeAgua`, `laranja`,
`vermelho`, `roxo`, `cinzento`) ou `#rrggbb`. O `CartaoKpi` de `modulos/geral/comum/componentes.tsx` (painéis) já usa
este cartão (barra à esquerda; aceita `icone`, `cor`, `barra`).

## 5. `EstadoVazio`

`<EstadoVazio icone="check-circle" cor="#059669" titulo="Nenhuma Revisão Pendente" texto="…" accoes={<Button>…</Button>} />`

## 6. Peças avulsas

- `FiltroColuna`, `BotaoFiltroPersonalizado`, `ModalFiltroPersonalizado` — UI dos filtros.
- `aplicarFiltros`, `passaCondicao`, `normalizarFiltroPersonalizado`, `OPERADORES_FILTRO` — lógica (texto simples; nada é
  interpretado como código ou expressão regular).
- `paginacaoLegado({...})` — configuração da paginação do antigo para uma `Table`/`Pagination` própria.
- `BotoesExportarLegado` (`@/componentes/impressao`) — Excel · PDF · Imprimir na ordem do antigo.

## Limitações conhecidas

- A linha de filtros não acompanha colunas `fixed` (fixas à esquerda/direita) durante o deslocamento horizontal.
- Em vista «Grade» não há linha de filtros (não há cabeçalhos); o «Filtro personalizado» e o contador funcionam.
- Ecrãs que já desenham os seus próprios botões Imprimir/PDF na `barra` devem passar a usar `impressao` para não
  duplicar os botões.
