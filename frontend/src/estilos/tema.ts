import type { ThemeConfig } from 'antd';

/** Cor primária da marca ERP Consulvolt (avatar de iniciais, realces). */
export const COR_PRIMARIA = '#1f5fae';

/**
 * Tema do Ant Design (ConfigProvider em src/main.tsx).
 * O tamanho das tabelas/formulários NÃO é global: em ecrã pequeno use `size="small"` caso a caso
 * (ver src/componentes/responsivo/README.md).
 */
export const TEMA: ThemeConfig = {
  token: { colorPrimary: COR_PRIMARIA, borderRadius: 6 },
  components: {
    Layout: { headerBg: '#ffffff', siderBg: '#ffffff', bodyBg: '#f5f6f8' },
    Menu: { itemMarginInline: 6, subMenuItemBg: 'transparent' },
  },
};
