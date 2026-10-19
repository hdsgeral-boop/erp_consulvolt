import { App, Button, Modal, message as mensagemEstatica } from 'antd';
import { useQueryClient } from '@tanstack/react-query';
import { useCallback, useState } from 'react';
import { enviar } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { notificarErro } from '@/utilitarios/erros';
import type { Organigrama } from '../../comum/tipos';
import { aplicarMovimento, localizarMembro, previsaoDestino, type Destino, type PedidoMoverMembro, type ResultadoMoverMembro } from './moverMembro';

/** Segundos em que o aviso com «Desfazer» fica visível. */
export const DURACAO_DESFAZER = 8;

/**
 * Mover um membro para outra posição (arrastar e largar ou menu «Mover para…»): actualiza o organigrama logo (optimista, sem
 * reconstruir o quadro — o zoom e a deslocação mantêm-se), pede confirmação acima das vagas como o legado, envia
 * POST /projetos/{p}/organigrama/mover e mostra um aviso com «Desfazer», que repõe a posição e a responsabilidade.
 */
export function useMoverMembro(projectoId: number) {
  const cliente = useQueryClient();
  const app = App.useApp();
  // dentro de <App> (tema nocturno, foco) usa o contexto; fora dele os métodos estáticos
  const message = typeof app.message?.success === 'function' ? app.message : mensagemEstatica;
  const confirmarModal = typeof app.modal?.confirm === 'function' ? app.modal.confirm : Modal.confirm;
  const [aMover, setAMover] = useState(false);
  const chave = ['projectos', 'organigrama', projectoId];

  const enviarMovimento = useCallback(async (pedido: PedidoMoverMembro): Promise<ResultadoMoverMembro> => {
    // uma leitura em curso não pode sobrepor-se à actualização optimista (o cancelamento é imediato; não se espera por ele)
    void cliente.cancelQueries({ queryKey: chave });
    const antes = cliente.getQueryData<Organigrama>(chave);
    if (antes) cliente.setQueryData(chave, aplicarMovimento(antes, pedido.membro_id, pedido.no_destino_id, !!pedido.repor_responsavel));
    setAMover(true);
    try {
      return (await enviar<ResultadoMoverMembro>('post', `/projetos/${projectoId}/organigrama/mover`, pedido)).dados;
    } catch (e) {
      if (antes) cliente.setQueryData(chave, antes);
      throw e;
    } finally {
      setAMover(false);
      void cliente.invalidateQueries({ queryKey: ['projectos'] });
    }
  }, [cliente, projectoId]); // eslint-disable-line react-hooks/exhaustive-deps

  const desfazer = useCallback(async (r: ResultadoMoverMembro, nome: string) => {
    try {
      await enviarMovimento({ membro_id: r.membro_id, no_destino_id: r.de, no_origem_id: r.para, confirmar_excesso: true, repor_responsavel: r.era_responsavel });
      void message.info(`Movimento de «${nome}» desfeito.`);
    } catch (e) {
      notificarErro(e, 'Não foi possível desfazer o movimento');
    }
  }, [enviarMovimento, message]);

  const mover = useCallback((membroId: number, para: Destino) => {
    const o = cliente.getQueryData<Organigrama>(chave);
    const origem = o ? localizarMembro(o, membroId) : null;
    if (!o || !origem || origem.de === para) return;
    const previsao = previsaoDestino(o, membroId, para);
    const destino = para === null ? null : o.posicoes.find((p) => p.id === para);
    const nome = origem.membro.nome;
    const confirmarVagas = (seguir: () => void, vagas: number, ficaria: number) => confirmarModal({
      title: 'Vagas excedidas', okText: 'Alocar', cancelText: 'Cancelar', onOk: seguir,
      content: `A posição «${destino?.titulo ?? ''}» tem ${vagas} vaga(s) e ficará com ${ficaria} membro(s). Continuar?`,
    });
    const executar = async (confirmar: boolean) => {
      try {
        const r = await enviarMovimento({ membro_id: membroId, no_destino_id: para, no_origem_id: origem.de, confirmar_excesso: confirmar });
        if (!r.alterado) return;
        const k = `mover-membro-${r.membro_id}-${Date.now()}`;
        void message.success({
          key: k, duration: DURACAO_DESFAZER,
          content: (
            <span role="status">
              {destino ? `«${nome}» movido para «${destino.titulo}».` : `«${nome}» ficou sem posição.`}{' '}
              <Button type="link" size="small" style={{ padding: 0, height: 'auto' }} onClick={() => { message.destroy(k); void desfazer(r, nome); }}>Desfazer</Button>
            </span>
          ),
        });
      } catch (e) {
        // os dados no ecrã estavam desactualizados: o servidor pede a confirmação das vagas
        if (e instanceof ErroApi && e.codigo === 'VAGAS_EXCEDIDAS' && !confirmar) {
          const d = (e.erros ?? {}) as { vagas?: number; ficaria_com?: number };
          confirmarVagas(() => void executar(true), Number(d.vagas ?? previsao?.vagas ?? 0), Number(d.ficaria_com ?? previsao?.ficaria ?? 0));
          return;
        }
        notificarErro(e, 'Não foi possível mover o membro');
      }
    };
    if (previsao?.excede) confirmarVagas(() => void executar(true), previsao.vagas, previsao.ficaria);
    else void executar(false);
  }, [cliente, enviarMovimento, desfazer, projectoId, message, confirmarModal]); // eslint-disable-line react-hooks/exhaustive-deps

  return { mover, aMover };
}
