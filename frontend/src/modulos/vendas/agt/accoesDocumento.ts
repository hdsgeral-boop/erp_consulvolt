import type { DocumentoVenda } from '../api';

/** Estados do envio em que a AGT ainda não recebeu o documento (ServicoEnvioAgt::ESTADOS_PARA_ENVIAR). */
const PARA_ENVIAR: (string | null)[] = [null, 'POR_ENVIAR', 'ERRO'];

/**
 * Botões AGT avançados no detalhe da venda, com as permissões do catálogo e as mesmas condições do servidor:
 *  - revalidar (POST /documentos/{id}/revalidar, vendas_fat_emitir): documentos do regime com erros locais ainda por enviar,
 *    ou inválidos/rejeitados pela AGT (ServicoEnvioAgt::revalidarEReenviar);
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
    revalidar: regime && d.estado !== 'ANULADO' && (comErrosLocais || envio === 'INVALIDO' || envio === 'REJEITADO') && pode('vendas_fat_emitir'),
    qr: selado && pode('vendas_faturacao_view'),
    pedidoAssinado: selado && pode('vendas_fe_config'),
  };
}

/** Imagem SVG (texto) como data URL para um <img> — um <img> nunca executa scripts do SVG. */
export function svgComoDataUrl(svg: string): string {
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}
