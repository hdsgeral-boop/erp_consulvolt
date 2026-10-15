import { Modal } from 'antd';
import { useEffect } from 'react';
import { obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import type { Elegibilidade } from './api';
import { eContaBancaria } from './regras';

/** Notifica uma vez o erro de uma consulta das cartas. */
export function useAvisarErroCartas(erro: unknown, titulo = 'Erro ao carregar os dados') {
  useEffect(() => {
    if (erro) notificarErro(erro, titulo);
  }, [erro, titulo]);
}

/** Endereço do ecrã das cartas com a emissão aberta para o pagamento (sem dados pessoais na URL). */
export const urlEmissaoCarta = (documentoId: number) => `/m/teso/teso_cartas?documento=${documentoId}`;

/**
 * Convite discreto depois de gravar um pagamento (tesoOferecerCarta do legado novo): só se for um pagamento numa conta
 * 42/43 que reúna as condições (IBAN, beneficiários com conta). Caso contrário não se diz nada — quem quiser emite mais
 * tarde pela lista. Devolve true se o convite foi mostrado.
 */
export async function oferecerCarta(
  doc: { id: number; tipo: string; conta_financeira: string | null },
  podeEmitirCarta: boolean,
  abrir: (url: string) => void,
): Promise<boolean> {
  if (!podeEmitirCarta || doc.tipo !== 'PAGAMENTO' || !eContaBancaria(doc.conta_financeira)) return false;
  try {
    const el = await obter<Elegibilidade>(`/tesouraria/cartas/documentos/${doc.id}/elegibilidade`);
    if (!el.pode) return false;
    const quantos = (el.beneficiarios ?? []).filter((b) => b.iban).length;
    const quem = el.tipo === 'SALARIOS' ? 'colaborador' : 'beneficiário';
    Modal.confirm({
      title: 'Emitir a carta de ordem de pagamento?',
      content: `Pagamento gravado. A conta ${el.conta?.codigo ?? ''} (${el.conta?.banco_nome ?? 'banco'}) pode emitir carta e há ${quantos} ${quem}${quantos === 1 ? '' : 's'} com conta bancária configurada.`,
      okText: 'Emitir agora',
      cancelText: 'Mais tarde',
      onOk: () => abrir(urlEmissaoCarta(doc.id)),
    });
    return true;
  } catch {
    return false;
  }
}
