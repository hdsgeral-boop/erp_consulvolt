import { useQueryClient } from '@tanstack/react-query';
import { App, message } from 'antd';
import { useCallback, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { chaveIdentidade, type IdentidadeEmpresa } from '@/sessao/identidade';
import { notificarErro } from '@/utilitarios/erros';
import { imprimirDocumento } from './motor';
import type { FormatoPagina, IdentidadeImpressao, OpcoesImpressao } from './tipos';

export type PedidoImpressao = Omit<OpcoesImpressao, 'identidade' | 'utilizador'> & Partial<Pick<OpcoesImpressao, 'identidade' | 'utilizador'>>;

/** Mensagens do Ant Design com o tema da aplicação (App.useApp); fora do <App> usa as funções estáticas. */
export function useMensagem(): typeof message {
  const app = App.useApp();
  return (app.message && typeof app.message.info === 'function' ? app.message : message) as typeof message;
}

export const DICA_PDF = 'Na janela de impressão, escolha «Guardar como PDF» como destino.';

/**
 * Impressão/PDF com a identidade da empresa activa (logótipo, nome, NIF, morada, rodapé) e o utilizador da sessão
 * já injectados. Se a identidade ainda não estiver em cache, é lida antes de imprimir (o logótipo sai sempre).
 */
export function useImpressao() {
  const { empresa, utilizador } = useSessao();
  const cliente = useQueryClient();
  const [aImprimir, setAImprimir] = useState(false);
  const mensagem = useMensagem();

  const obterIdentidade = useCallback(async (): Promise<IdentidadeImpressao | null> => {
    if (!empresa) return null;
    try {
      return await cliente.ensureQueryData({
        queryKey: chaveIdentidade(empresa.id),
        queryFn: () => obter<IdentidadeEmpresa>('/sistema/identidade'),
        staleTime: 30 * 60_000,
      });
    } catch {
      return { nome: empresa.nome, nif: empresa.nif ?? null };
    }
  }, [cliente, empresa]);

  const imprimir = useCallback(
    async (pedido: PedidoImpressao): Promise<FormatoPagina | null> => {
      setAImprimir(true);
      try {
        const identidade = pedido.identidade ?? (await obterIdentidade());
        const nomeUtilizador = pedido.utilizador ?? (utilizador ? utilizador.nome_completo || utilizador.nome_utilizador : null);
        if (pedido.modo === 'pdf') mensagem.info({ content: DICA_PDF, duration: 6 });
        return await imprimirDocumento({ ...pedido, identidade, utilizador: nomeUtilizador });
      } catch (e) {
        notificarErro(e, 'Não foi possível preparar a impressão');
        return null;
      } finally {
        setAImprimir(false);
      }
    },
    [obterIdentidade, utilizador, mensagem],
  );

  return { imprimir, aImprimir, obterIdentidade };
}
