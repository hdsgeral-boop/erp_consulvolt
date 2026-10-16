import { theme } from 'antd';

/**
 * Cores dos cartões e caixas copiados do legado (`background:#fff; border:1px solid #e2e8f0`, cabeçalhos `#f8fafc`,
 * texto `#0f172a`) lidas do tema: na tela clara dão as mesmas cores do legado e na tela nocturna acompanham o escuro.
 */
export function useCoresPos() {
  const { token } = theme.useToken();
  return {
    /** `#fff` */
    fundo: token.colorBgContainer,
    /** `#f8fafc` */
    fundoAlt: token.colorFillAlter,
    /** `#f1f5f9` */
    fundoSuave: token.colorFillSecondary,
    /** `#e2e8f0` */
    borda: token.colorBorderSecondary,
    /** `#cbd5e1` */
    bordaForte: token.colorBorder,
    /** `#0f172a` / `#1e293b` */
    texto: token.colorText,
    /** `#64748b` */
    textoSec: token.colorTextTertiary,
  };
}
