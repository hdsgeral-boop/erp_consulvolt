# Acessibilidade (WCAG 2.2 AA)

Verificação automática: `node ferramentas/qualidade/acessibilidade.mjs [--larguras=1366,375] [--urls=...] [--todos] [--saida=f.json]`
(Playwright + `axe-core`, instalado só em `frontend` como devDependency; não entra no bundle). Percorre 5 páginas públicas e
~20 ecrãs de módulos em 1366 e 375 px, com o utilizador fictício do ambiente E2E; falha com violações `critical`/`serious`.
Resultados: `docs/qualidade/axe_antes.json` e `docs/qualidade/axe_depois.json`.

## Resultado (50 páginas = 25 ecrãs × 2 larguras, axe 4.x, tags wcag2a/2aa/21a/21aa/22aa/best-practice)

| Impacto | Antes | Depois |
|---|---|---|
| Crítico | 44 | 7 |
| Sério | 267 | 53 |
| Moderado | 40 | 59 (regras *best-practice* de landmarks/h1, antes mascaradas por outras falhas) |
| Menor | 23 | 19 |

Corrigido (global, sem tocar em cada ecrã): nomes acessíveis das caixas de selecção do AntD e das `th` vazias
(`componentes/AjudasAcessibilidade.tsx`); ligação «Saltar para o conteúdo» (`SaltarParaConteudo.tsx`, `id` no `main`); área clicável
≥ 24 px (botões, funil de filtro, ícone da palavra-passe, alternar vista) e 40 px em ecrãs tácteis; contraste do texto de
substituição (`colorTextPlaceholder`), das etiquetas `Tag` coloridas, do rodapé público e do botão «+ Factura-Recibo»; ligações do
texto legal sublinhadas (não só pela cor); regiões deslocáveis com `tabIndex=0` (`DeslocamentoHorizontal`, funil, Kanban, Gantt);
foco visível comum (`:focus-visible`, 2 px); `prefers-reduced-motion`.

## Por rever (violações restantes)

- `aria-required-children` (crítico, 5 nós em 3 ecrãs de Configuração): `Tabs` do AntD com `role="tablist"` sem separadores visíveis.
- `label` (crítico, 2 nós em `/dados-pessoais`): inputs desactivados sem rótulo (ecrã de outro agente).
- `label-content-name-mismatch` (sério): o botão do utilizador tem `aria-label="Opções do utilizador"` e texto visível diferente; mudar
  exige actualizar os testes de `LayoutPrincipal`/`LayoutPlataforma` que o procuram por esse nome.
- `color-contrast` (sério, 21 nós): píulas `.rh-pilula`, botões primários em degradê e `Tag` de cores personalizadas.
- `scrollable-region-focusable`: `.ant-table-content` da licença (tabela com `scroll`).
- Moderados: páginas públicas sem `main`/`h1` (landmarks); `empty-table-header` (mantém `aria-label`, o axe pede texto).

## Checklist por ecrã novo

1. Cada campo com `<label>`/`aria-label`; erros do `Form.Item` ligados por `aria-describedby` (automático no AntD).
2. Botões só-ícone com `aria-label`; área ≥ 24×24 px.
3. Estado nunca só por cor (ícone/texto/forma).
4. Contraste ≥ 4,5:1 (texto) e 3:1 (componentes); usar os tokens de `designSystem.css`.
5. Tab percorre tudo na ordem visual; foco visível; Esc fecha modais/gavetas.
6. Alterações dinâmicas (notificações, resultados de pesquisa) em região `aria-live`/`role="status"` (já em gráficos e AntD `message`).
7. Sem animação essencial; respeitar `prefers-reduced-motion`.
8. `node ferramentas/qualidade/acessibilidade.mjs --urls=/m/modulo/ecra` sem violações graves.
