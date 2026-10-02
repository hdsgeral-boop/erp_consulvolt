# Base responsiva dos ecrãs

Alvos: telemóvel 360–414 px · tablet 768–1024 px · portátil 1366 px · ecrã grande 1920 px.
Pontos de quebra (Ant Design 5): `xs < 576 · sm ≥ 576 · md ≥ 768 · lg ≥ 992 · xl ≥ 1200 · xxl ≥ 1600`.

O layout principal já trata do menu (gaveta abaixo de 992 px), do padding do conteúdo (12/16/24 px) e impede que a
página ganhe barra horizontal. O CSS global (`src/estilos/global.css`) já faz: tabelas que deslocam dentro de si,
modais e gavetas nunca maiores do que o ecrã, rodapés de modal que quebram linha, `Form layout="inline"` e
`Descriptions bordered` que empilham em telemóvel, palavras longas que quebram. **Cada ecrã só tem de não impor larguras fixas.**

## Regras para cada ecrã

1. **Grelhas**: `Row gutter={[16, 16]}` + `Col` com `xs`/`sm`/`md`/`lg` (nunca só `span`).
   Campos de formulário: `<Col {...COL_CAMPO}>` (1/2/3/4 por linha) ou `COL_CAMPO_LARGO`.
   Cartões de indicadores/atalhos: `<div className="erp-grelha-auto">` (mínimo 220 px por cartão).
2. **Tabelas**: `scroll={scrollTabela()}` (= `{ x: 'max-content' }`); colunas secundárias com
   `responsive: ['md']` (escondidas em telemóvel); `size={pequeno ? 'small' : 'middle'}` com `useEcraPequeno()`.
   Evite `width` em todas as colunas; use `ellipsis: true` nas de texto livre.
3. **Filtros e barras de botões**: `<BarraFiltros accoes={…}>…</BarraFiltros>` ou `<Space wrap>` / `<Flex wrap gap="small">`.
   Nunca `Space` sem `wrap` com mais de 2–3 elementos. `style={{ width: 240 }}` em filtros é aceitável dentro da
   `BarraFiltros` (em telemóvel passam a 100 %); fora dela use `width: '100%'` dentro de um `Col`.
4. **Modais/gavetas**: `width={larguraModal(900)}` / `width={larguraGaveta(720)}` em vez de números.
   Formulários dentro de modais: `layout="vertical"`.
5. **Descriptions**: `column={COLUNAS_DESCRICOES}` (1/2/3) em vez de `column={3}`.
6. **Conteúdo largo** (Gantt, gráfico com largura fixa, `<table>` HTML, organigrama): `<DeslocamentoHorizontal>`.
7. **Decisões em JS**: `const { telemovel, tablet, pequeno } = useEcra();` ou `useEcraPequeno('lg')`.
   Para mostrar/esconder pouco conteúdo use as classes `erp-oculto-telemovel` / `erp-so-telemovel`.
8. **Cabeçalhos de página**: `CabecalhoPagina` já quebra linha; não ponha mais de 3–4 botões visíveis — o resto num
   `Dropdown` («Mais acções»).
9. Nada de `minWidth`/`width` fixos > 340 px em contentores; prefira `maxWidth` + `width: '100%'`.

## Exemplo

```tsx
import { Col, Form, Input, Row, Table } from 'antd';
import { BarraFiltros, COL_CAMPO, larguraModal, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';

const pequeno = useEcraPequeno();
<BarraFiltros accoes={<Button type="primary">Novo</Button>}>
  <Input.Search placeholder="Pesquisar" style={{ width: 240 }} />
</BarraFiltros>
<Table size={pequeno ? 'small' : 'middle'} scroll={scrollTabela()} columns={colunas} … />
<Modal width={larguraModal(820)} …>
  <Form layout="vertical"><Row gutter={16}><Col {...COL_CAMPO}><Form.Item …/></Col></Row></Form>
</Modal>
```

## Testes

`simularLargura(375)` (em `testes/simularLargura.ts`) simula a largura para o `matchMedia` em Vitest;
`afterEach(() => simularLargura(null))`. Ícones de módulos/ecrãs: `iconeModulo(id)`, `iconeEcra(id, moduloId)` em
`src/componentes/icones/iconesModulos.tsx`.
