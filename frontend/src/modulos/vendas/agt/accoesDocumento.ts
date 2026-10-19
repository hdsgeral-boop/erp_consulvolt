import type { DocumentoVenda } from '../api';

/** Estados do envio em que a AGT ainda não recebeu o documento (ServicoEnvioAgt::ESTADOS_PARA_ENVIAR). */
const PARA_ENVIAR: (string | null)[] = [null, 'POR_ENVIAR', 'ERRO', 'CONTINGENCIA'];

/**
 * Botões AGT avançados no detalhe da venda, com as permissões do catálogo e as mesmas condições do servidor:
 *  - revalidar (POST /documentos/{id}/revalidar, vendas_agt_enviar): documentos do regime com erros locais ainda por enviar
 *    (ServicoEnvioAgt::revalidarEReenviar);
 *  - correccao (POST /documentos/{id}/correccao-agt, vendas_agt_enviar): inválidos/rejeitados pela AGT — documento novo com
 *    novo número, documentStatus «C» e rejectedDocumentNo (E46; ServicoCorrecaoAgt);
 *  - QR (GET /documentos/{id}/qr, vendas_faturacao_view): documentos do regime já selados;
 *  - pedido assinado (GET /documentos/{id}/pedido-assinado, vendas_fe_config): documentos do regime já selados (têm documento AGT).
 */
export function accoesAgt(d: Pick<DocumentoVenda, 'estado' | 'faturacao_eletronica'>, pode: (...p: string[]) => boolean) {
  const fe = d.faturacao_eletronica;
  const regime = Boolean(fe?.regime);
  const envio = fe?.envio ?? null;
  const selado = regime && Boolean(fe?.selado_em);
  const comErrosLocais = fe?.estado === 'COM_ERROS' && PARA_ENVIAR.includes(envio);
  return {
    revalidar: regime && d.estado !== 'ANULADO' && comErrosLocais && pode('vendas_agt_enviar'),
    correccao: regime && d.estado !== 'ANULADO' && (envio === 'INVALIDO' || envio === 'REJEITADO') && pode('vendas_agt_enviar'),
    qr: selado && pode('vendas_faturacao_view'),
    pedidoAssinado: selado && pode('vendas_fe_config'),
  };
}

/** Imagem SVG (texto) como data URL para um <img> — um <img> nunca executa scripts do SVG. */
export function svgComoDataUrl(svg: string): string {
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}
