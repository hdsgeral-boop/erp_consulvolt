import { useCallback, useState, type ReactNode } from 'react';
import { DialogoExcesso } from './DialogoExcesso';
import { eExcessoOrcamental, extrairExcesso, type ContextoExcesso, type DadosExcesso, type OpcoesOrcamento } from './excesso';

export interface OpcoesTratamentoExcesso {
  /** Tipo, data e linhas do documento (função para só se calcular quando o erro é mesmo de excesso). */
  contexto?: ContextoExcesso | (() => ContextoExcesso | undefined);
  /** Repete a gravação com as opções de orçamento (ou sem elas, depois de aprovado). */
  repetir?: (opcoes?: OpcoesOrcamento) => void;
}

/**
 * Tratamento comum do erro ORCAMENTO_EXIGE_APROVACAO nos ecrãs que gravam documentos com controlo orçamental:
 *
 *   const excesso = useExcessoOrcamental();
 *   onError: (e, v) => excesso.tratar(e, { contexto: () => …, repetir: (o) => gravar.mutate({ ...v, orcamento: o }) }) || notificarErro(e, '…')
 *   …
 *   {excesso.dialogo}
 *
 * `tratar` devolve true quando o erro é de excesso (e abre o diálogo); caso contrário false, para o ecrã notificar como sempre.
 */
export function useExcessoOrcamental(): { tratar: (e: unknown, opcoes?: OpcoesTratamentoExcesso) => boolean; dialogo: ReactNode } {
  const [estado, setEstado] = useState<{ dados: DadosExcesso; contexto?: ContextoExcesso; repetir?: (opcoes?: OpcoesOrcamento) => void } | null>(null);

  const tratar = useCallback((e: unknown, opcoes?: OpcoesTratamentoExcesso) => {
    if (!eExcessoOrcamental(e)) return false;
    let contexto: ContextoExcesso | undefined;
    try {
      contexto = typeof opcoes?.contexto === 'function' ? opcoes.contexto() : opcoes?.contexto;
    } catch {
      contexto = undefined;
    }
    setEstado({ dados: extrairExcesso(e), contexto, repetir: opcoes?.repetir });
    return true;
  }, []);

  const dialogo = estado ? <DialogoExcesso dados={estado.dados} contexto={estado.contexto} repetir={estado.repetir} aoFechar={() => setEstado(null)} /> : null;
  return { tratar, dialogo };
}
