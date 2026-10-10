# CabecalhoModulo — cabeçalho de módulo agregador

`src/componentes/CabecalhoModulo.tsx` reproduz o cabeçalho dos ecrãs agregadores do sistema anterior (G.5 do relatório de
verificação visual): título grande com o ícone do módulo (`h2.view-title`), subtítulo, indicadores à direita, botão
**«⛶ Ecrã Cheio»** opcional, barra opcional de acções rápidas e os **separadores em forma de botão** (`.tab-btn`: activo azul
cheio, restantes brancos com contorno, ícone à esquerda, contador opcional, quebra em várias linhas). Cada separador é um
ecrã com rota própria `/m/{modulo}/{ecra}`: o componente navega entre rotas, não esconde conteúdo.

Exemplo aplicado: `src/modulos/compras/comum/BarraCompras.tsx` (Compras).

## Utilização

```tsx
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';

<CabecalhoModulo
  titulo="Gestão de Vendas"
  icone="shopping-cart"                      // nome Font Awesome 5 do legado (IconeFa) ou um nó React
  subtitulo="Administre clientes, produtos e todo o ciclo de faturação."
  indicadores={[
    { chave: 'pendentes', rotulo: 'Pendentes', valor: 8, icone: 'clock', cor: '#f59e0b' },
    { chave: 'mensal', rotulo: 'Mensal', valor: formatarKz(total), icone: 'chart-bar', cor: '#10b981' },
  ]}
  ecraCheio                                  // true = área de conteúdo; ou () => elemento
  accoesRapidas={<Button type="primary">Novo Pedido</Button>}
  separadores={[
    { chave: 'dashboard', rotulo: 'Dashboard', icone: 'chart-line', destino: '/m/vendas/vendas_relatorios', permissao: 'vendas_relatorios_view' },
    { chave: 'clientes', rotulo: 'Clientes', icone: 'users', destino: '/m/vendas/vendas_clientes', permissao: 'vendas_clientes_view', contador: 3 },
  ]}
/>
```

Para usar em todos os ecrãs de um módulo sem repetir código, crie um componente do módulo (como `BarraCompras`) e
ponha-o por cima de cada ecrã em `ecras.ts` (ver `comBarra` em `src/modulos/compras/ecras.ts`).

## Propriedades

| Propriedade | Tipo | Notas |
|---|---|---|
| `titulo` | `ReactNode` | Título do módulo, como no legado (ex.: «Tesouraria - Operações», «Gestão de Activos Imobilizados»). |
| `icone` | `string \| ReactNode` | Nome FA 5 do legado (ex.: `shopping-cart`, `university`, `couch`, `boxes`) desenhado por `IconeFa`. |
| `subtitulo` | `ReactNode` | Linha cinzenta por baixo do título. |
| `indicadores` | `IndicadorModulo[]` | `{ chave, rotulo, valor, icone?, cor?, dica? }` → «● Pendentes: **8**». Sem `icone` mostra «●» na `cor` (laranja por omissão). |
| `ecraCheio` | `boolean \| () => HTMLElement \| null` | Botão «Ecrã Cheio»/«Sair do Ecrã Cheio» (Fullscreen API). Só aparece se o navegador o suportar. |
| `accoes` | `ReactNode` | Botões extra à direita (depois dos indicadores). |
| `accoesRapidas` | `ReactNode` | Barra cinzenta por baixo do título (legado `.compras-toolbar`). |
| `separadores` | `SeparadorModulo[]` | `{ chave, rotulo, destino, icone?, contador?, permissao?, activo? }`. |
| `activo` | `string` | Chave do separador activo (sobrepõe a detecção automática). |
| `minimoSeparadores` | `number` | Mínimo de separadores visíveis para mostrar a barra (omissão 2). |
| `children` | `ReactNode` | Conteúdo por baixo dos separadores (ex.: sub-separadores da Facturação). |

### Separadores

- **Permissões**: `permissao` (texto ou lista; basta uma) usa `pode()` da sessão — o separador some se o perfil não tiver
  acesso. O servidor continua a validar cada pedido.
- **Activo**: por omissão é activo o separador cujo `destino` é prefixo do caminho actual (`/m/compras/compras_encomendas`
  não confunde com `/m/compras/compras_encomendas_clientes`). Se o `destino` tiver query string (ex.: `?tipo=PAGAMENTO`),
  os parâmetros também têm de coincidir. Use `activo` (no cabeçalho) ou `activo` (no separador) para casos especiais.
- **Navegação**: são ligações `<a href>` (papel *link*, abrem noutro separador do navegador com Ctrl/clique do meio) e
  navegam com o React Router; não usam `role="button"`, para não colidirem com os botões dos ecrãs nos testes E2E.
- **Segurança**: nunca ponha dados pessoais (nomes, NIF, valores) em `destino`; só ids de ecrã e filtros fixos.

## Estilo

`CabecalhoModulo.css` (importado pelo componente): cores do legado (`#475569` título, `#2563eb` activo, `#cbd5e1` contorno,
`#f8fafc` barra de acções). Em telemóvel os separadores ficam mais compactos e quebram linha; não há deslocação
horizontal. O cabeçalho não sai na impressão (`imp-nao-imprimir`).

## Ícones disponíveis

Os desenhos estão em `src/componentes/fluxos/iconesFa.ts` (Font Awesome Free 6.4.0, CC BY 4.0, sem CDN). Um nome
desconhecido mostra um círculo vazio; para acrescentar um ícone copie o `path` do SVG da versão 6.4.0 com o nome da
versão 5 usado no legado como chave.
