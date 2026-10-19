import type { ThemeConfig } from 'antd';

/**
 * Identidade visual do ERP Consulvolt — copiada do sistema anterior (payroll_system_web/css/styles.css) para que o
 * utilizador reconheça o ambiente: azul vibrante, menu lateral escuro (#121212), cinzentos «slate», cantos de 4 px,
 * tabelas densas com cabeçalho em maiúsculas e botões em degradê «clássico ERP».
 *
 * Contraste (WCAG 2.1 AA, ≥ 4,5:1 para texto normal) verificado para as combinações de texto usadas:
 *   branco sobre COR_PRIMARIA 5,2:1 · TEXTO sobre branco 14,6:1 · TEXTO_SECUNDARIO sobre branco 7,6:1 ·
 *   TEXTO_TERCIARIO sobre branco 4,8:1 · MENU_TEXTO sobre MENU_FUNDO 12,2:1 · MENU_TEXTO_NIVEL3 sobre MENU_FUNDO 7,3:1 ·
 *   branco sobre COR_PERIGO 4,8:1 (o vermelho do legado, #ef4444, ficava em 3,8:1 e foi escurecido) ·
 *   texto dos botões em degradê (#0b4a8e sobre #d4e8fb 7,0:1; #333 sobre #e5e5e5 10,0:1; #8e0b0b sobre #fcd6d6 7,1:1).
 */

/** Cor primária (legado: --primary-color). */
export const COR_PRIMARIA = '#2563eb';
/** Variante escura (legado: --primary-hover). */
export const COR_PRIMARIA_ESCURA = '#1d4ed8';
/** Fundo claro da cor primária (legado: --primary-light). */
export const COR_PRIMARIA_CLARA = '#eff6ff';
export const COR_SUCESSO = '#059669';
export const COR_AVISO = '#f59e0b';
/** Vermelho de perigo (legado #ef4444, escurecido para AA com texto branco). */
export const COR_PERIGO = '#dc2626';

export const TEXTO = '#1e293b';
export const TEXTO_SECUNDARIO = '#475569';
export const TEXTO_TERCIARIO = '#64748b';
export const LIMITE = '#cbd5e1';
export const LIMITE_SECUNDARIO = '#e2e8f0';
/** Fundo da área de trabalho (legado: --bg-color). */
export const FUNDO = '#f8fafc';

/** Menu lateral (legado: .sidebar). */
export const MENU_FUNDO = '#121212';
export const MENU_TEXTO = '#d0d0d0';
export const MENU_TEXTO_NIVEL3 = '#94a3b8';
export const MENU_REALCE = '#3b82f6';

/** Tipo de letra do legado (Inter quando instalado; senão o tipo de letra do sistema). */
export const FONTE = "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

/**
 * Tema do Ant Design (ConfigProvider em src/main.tsx). Os pormenores que os tokens não cobrem (degradê dos botões,
 * separadores em botão, cabeçalho das tabelas em maiúsculas, guias tracejadas do menu) estão em src/estilos/global.css.
 * O tamanho das tabelas/formulários NÃO é global: em ecrã pequeno use `size="small"` caso a caso
 * (ver src/componentes/responsivo/README.md).
 */
export const TEMA: ThemeConfig = {
  // Variáveis CSS do AntD (`--ant-color-primary`, `--ant-color-border`…): os estilos próprios (CSS e `style` inline) que
  // usam `var(--ant-…)` seguem o tema activo — também a tela nocturna (TEMA_NOCTURNO volta a ligá-las, com inherit: false).
  // As variáveis ficam declaradas na classe `css-var-…` da raiz de cada componente AntD (Layout, Modal…) e herdam-se.
  cssVar: true,
  token: {
    colorPrimary: COR_PRIMARIA,
    colorInfo: COR_PRIMARIA,
    colorSuccess: COR_SUCESSO,
    colorWarning: COR_AVISO,
    colorError: COR_PERIGO,
    colorLink: COR_PRIMARIA,
    colorText: TEXTO,
    colorTextHeading: '#334155',
    colorTextSecondary: TEXTO_SECUNDARIO,
    colorTextTertiary: TEXTO_TERCIARIO,
    colorTextDescription: TEXTO_TERCIARIO,
    colorBorder: LIMITE,
    colorBorderSecondary: LIMITE_SECUNDARIO,
    colorSplit: 'rgba(15, 23, 42, 0.08)',
    colorBgLayout: FUNDO,
    colorFillAlter: FUNDO,
    fontFamily: FONTE,
    fontSize: 13,
    fontSizeSM: 11,
    fontSizeLG: 14,
    fontSizeXL: 16,
    fontSizeHeading1: 22,
    fontSizeHeading2: 18,
    fontSizeHeading3: 15,
    fontSizeHeading4: 13.5,
    fontSizeHeading5: 12.5,
    borderRadius: 4,
    borderRadiusSM: 3,
    borderRadiusLG: 4,
    borderRadiusXS: 2,
  },
  components: {
    Layout: { headerBg: '#ffffff', siderBg: MENU_FUNDO, bodyBg: FUNDO },
    // legado .nav-item/.nav-group-header: a toda a largura (sem margem), hover branco a 3 %, activo azul a 10 %; o fundo
    // é o do menu lateral (transparente, para a tela nocturna o poder escurecer); medidas por nível em identidade.css
    Menu: {
      itemMarginInline: 0,
      itemBorderRadius: 4,
      subMenuItemBg: 'transparent',
      darkItemBg: 'transparent',
      darkSubMenuItemBg: 'transparent',
      darkPopupBg: '#1c1c1c',
      darkItemColor: MENU_TEXTO,
      darkItemHoverColor: '#ffffff',
      darkItemHoverBg: 'rgba(255, 255, 255, 0.04)',
      darkItemSelectedColor: '#ffffff',
      darkItemSelectedBg: 'rgba(59, 130, 246, 0.16)',
      darkGroupTitleColor: MENU_TEXTO_NIVEL3,
      itemHeight: 40,
      iconSize: 16,
      collapsedIconSize: 18,
    },
    Card: { headerFontSize: 14, headerBg: 'transparent' },
    Table: {
      headerBg: '#f1f5f9',
      headerColor: TEXTO,
      headerSortActiveBg: '#e2e8f0',
      headerSortHoverBg: '#e2e8f0',
      rowHoverBg: COR_PRIMARIA_CLARA,
      borderColor: LIMITE_SECUNDARIO,
      footerBg: FUNDO,
      cellPaddingBlock: 5,
      cellPaddingInline: 8,
      cellPaddingBlockMD: 4,
      cellPaddingInlineMD: 6,
      cellPaddingBlockSM: 3,
      cellPaddingInlineSM: 5,
      cellFontSize: 12.5,
    },
    // legado .btn: 2px de raio, escala compacta corporativa
    Button: { fontWeight: 500, primaryShadow: 'none', defaultShadow: 'none', dangerShadow: 'none', borderRadius: 2, borderRadiusSM: 2, borderRadiusLG: 2, contentFontSize: 12.5, controlHeight: 30, paddingInline: 12 },
    Tabs: { horizontalItemGutter: 8, itemColor: '#334155' },
    Modal: { titleFontSize: 15 },
    Form: { labelColor: TEXTO },
    Input: { activeShadow: '0 0 0 3px rgba(59, 130, 246, 0.15)' },
    Select: { optionSelectedBg: COR_PRIMARIA_CLARA },
    Descriptions: { labelBg: FUNDO },
  },
};
