import { theme, type ThemeConfig } from 'antd';
import { MENU_FUNDO, TEMA } from '@/estilos/tema';

/**
 * Tela nocturna (Versão Actual, css/tela_noturna.css): tema escuro do Ant Design (`darkAlgorithm`) com as cores próprias do
 * ERP. É aplicado num ConfigProvider interno do layout com `inherit: false`, porque o tema claro (src/estilos/tema.ts) fixa
 * cores de texto, fundos e tabelas que se sobreporiam ao algoritmo escuro. Os estilos que não vêm dos tokens (degradês dos
 * botões, separadores, Início, cabeçalho) estão em telaNocturna.css. A impressão continua sempre clara.
 *
 * Contraste (WCAG 2.1 AA): TEXTO #e2e8f0 sobre FUNDO_CARTAO #111a2b ≈ 13:1 · TEXTO_SECUNDARIO #cbd5e1 ≈ 10:1 ·
 * TEXTO_TERCIARIO #94a3b8 ≈ 6:1 · COR_PRIMARIA #3b82f6 sobre #111a2b ≈ 4,6:1.
 */
export const FUNDO_NOCTURNO = '#0b1220';
export const FUNDO_CARTAO = '#111a2b';
export const FUNDO_ELEVADO = '#172236';
export const LIMITE_NOCTURNO = '#334155';
const TEXTO = '#e2e8f0';
const TEXTO_SECUNDARIO = '#cbd5e1';
const TEXTO_TERCIARIO = '#94a3b8';
const PRIMARIA = '#3b82f6';

export const TEMA_NOCTURNO: ThemeConfig = {
  inherit: false,
  algorithm: theme.darkAlgorithm,
  token: {
    ...TEMA.token,
    colorPrimary: PRIMARIA,
    colorInfo: PRIMARIA,
    colorLink: '#60a5fa',
    colorText: TEXTO,
    colorTextHeading: '#f1f5f9',
    colorTextSecondary: TEXTO_SECUNDARIO,
    colorTextTertiary: TEXTO_TERCIARIO,
    colorTextDescription: TEXTO_TERCIARIO,
    colorBorder: LIMITE_NOCTURNO,
    colorBorderSecondary: '#1f2a3d',
    colorSplit: 'rgba(148, 163, 184, 0.16)',
    colorBgLayout: FUNDO_NOCTURNO,
    colorBgContainer: FUNDO_CARTAO,
    colorBgElevated: FUNDO_ELEVADO,
    colorFillAlter: '#152035',
  },
  components: {
    ...TEMA.components,
    Layout: { headerBg: FUNDO_CARTAO, siderBg: MENU_FUNDO, bodyBg: FUNDO_NOCTURNO },
    Card: { ...TEMA.components?.Card, headerBg: 'transparent' },
    Table: {
      ...TEMA.components?.Table,
      headerBg: '#1b263b',
      headerColor: TEXTO,
      headerSortActiveBg: '#22304a',
      headerSortHoverBg: '#22304a',
      rowHoverBg: '#1b2a44',
      borderColor: '#1f2a3d',
      footerBg: '#0f172a',
    },
    Tabs: { ...TEMA.components?.Tabs, itemColor: TEXTO_SECUNDARIO },
    Form: { labelColor: TEXTO },
    Select: { optionSelectedBg: '#1e3a8a' },
    Descriptions: { labelBg: '#152035' },
  },
};
