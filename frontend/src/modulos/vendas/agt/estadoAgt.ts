/**
 * Estado AGT de um documento de venda e mensagens do validador/da AGT em forma legível (lacuna A-04).
 * Mesma regra de ServicoEnvioAgt::resumo()/estadoEnvio(): fora do regime não há estado; selado com erros locais
 * (fe_estado ≠ PRONTO) = «Com erros locais»; pronto sem envio = «Por enviar»; depois o estado do envio.
 */

export type EstadoAgt = 'POR_ENVIAR' | 'ENVIADO' | 'VALIDO' | 'INVALIDO' | 'REJEITADO' | 'ERRO' | 'COM_ERROS_LOCAIS';

export const ESTADOS_AGT: { chave: EstadoAgt; rotulo: string; rotuloPlural: string; cor: string; corTexto?: string }[] = [
  { chave: 'POR_ENVIAR', rotulo: 'Por enviar', rotuloPlural: 'Por enviar', cor: 'gold', corTexto: '#d48806' },
  { chave: 'ENVIADO', rotulo: 'Enviado (a aguardar)', rotuloPlural: 'Enviados (a aguardar)', cor: 'blue' },
  { chave: 'VALIDO', rotulo: 'Válido', rotuloPlural: 'Válidos', cor: 'green', corTexto: '#389e0d' },
  { chave: 'INVALIDO', rotulo: 'Inválido', rotuloPlural: 'Inválidos', cor: 'red', corTexto: '#cf1322' },
  { chave: 'REJEITADO', rotulo: 'Rejeitado', rotuloPlural: 'Rejeitados', cor: 'red', corTexto: '#cf1322' },
  { chave: 'ERRO', rotulo: 'Erro de envio', rotuloPlural: 'Erro de envio', cor: 'volcano', corTexto: '#cf1322' },
  { chave: 'COM_ERROS_LOCAIS', rotulo: 'Com erros locais', rotuloPlural: 'Com erros locais', cor: 'red', corTexto: '#cf1322' },
];

export const INFO_ESTADO_AGT: Record<string, (typeof ESTADOS_AGT)[number]> = Object.fromEntries(ESTADOS_AGT.map((e) => [e.chave, e]));

interface FeMinima {
  regime?: boolean | null;
  estado?: string | null;
  envio?: string | null;
}

export function estadoAgtDoDocumento(fe: FeMinima | null | undefined): EstadoAgt | null {
  if (!fe?.regime) return null;
  if (fe.estado !== 'PRONTO') return 'COM_ERROS_LOCAIS';
  const envio = fe.envio ?? 'POR_ENVIAR';
  return (INFO_ESTADO_AGT[envio] ? envio : 'POR_ENVIAR') as EstadoAgt;
}

export interface MensagemAgt {
  codigo: string | null;
  mensagem: string;
  documento?: string | null;
}

/**
 * Lista de erros/avisos em qualquer das formas guardadas: texto «E02: mensagem» (validador local do ERP),
 * objecto {codigo, mensagem, documentNo} (envio) ou {idError, descriptionError} (resposta da AGT / legado).
 */
export function normalizarMensagensAgt(lista: unknown): MensagemAgt[] {
  if (!Array.isArray(lista)) return [];
  const saida: MensagemAgt[] = [];
  for (const item of lista) {
    if (item === null || item === undefined || item === '') continue;
    if (typeof item === 'string') {
      const m = /^\s*([A-Z]\d{2}(?:\/[A-Z]\d{2})*)\s*[:-]\s*(.+)$/s.exec(item);
      saida.push(m ? { codigo: m[1], mensagem: m[2].trim() } : { codigo: null, mensagem: item.trim() });
      continue;
    }
    if (typeof item === 'object') {
      const o = item as Record<string, unknown>;
      const codigo = String(o.codigo ?? o.idError ?? o.code ?? '').trim() || null;
      const mensagem = String(o.mensagem ?? o.descriptionError ?? o.message ?? o.descricao ?? '').trim() || JSON.stringify(o);
      const documento = (o.documentNo ?? o.documento ?? null) as string | null;
      saida.push({ codigo, mensagem, documento });
    }
  }
  return saida;
}

/** O que fazer, por estado (texto do legado, facturacao_agt_ui.js). */
export function orientacaoAgt(estado: EstadoAgt | null): string | null {
  switch (estado) {
    case 'COM_ERROS_LOCAIS':
      return 'Corrija os dados de origem (produto, cliente, empresa ou configuração) e use «Revalidar AGT». O número, a data, o cliente e os valores não mudam; se estiverem errados, emita uma nota de crédito e um novo documento.';
    case 'INVALIDO':
    case 'REJEITADO':
      return 'Corrija os dados de origem (produto, cliente, empresa) e use «Revalidar AGT» (um documento inválido segue como correcção). Se o erro estiver no número, na data, no cliente ou nos valores, emita uma nota de crédito e um novo documento.';
    case 'ERRO':
      return 'O envio falhou antes de a AGT responder (rede, credenciais ou serviço). O documento volta a ser enviado no próximo envio.';
    case 'ENVIADO':
      return 'A AGT recebeu o documento e está a validá-lo; o estado é consultado automaticamente.';
    default:
      return null;
  }
}
