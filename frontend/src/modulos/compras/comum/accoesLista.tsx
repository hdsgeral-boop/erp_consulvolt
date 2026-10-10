import { Button, Modal, Skeleton, Steps } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useCallback, type ReactNode } from 'react';
import { obter } from '@/api/cliente';
import { useImpressao } from '@/componentes/impressao';
import { larguraModal } from '@/componentes/responsivo';
import { pedidoDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';
import { linhasFatura } from '../faturacao/DetalheFatura';
import { rotuloEstado } from './estados';
import { dadosEncomendaCompra, dadosFaturaCompra, dadosPedidoCompra, dadosPropostaCompra, dadosRececaoCompra } from './impressao';
import { ModalIva } from './ModalIva';
import { numeroOuId, type EncomendaCompra, type EtapaDeliberacao, type FaturaCompra, type PedidoCompra, type PropostaCompra, type RececaoCompra } from './tipos';

/**
 * Peças das acções por linha das listas de Compras (paridade com os botões-ícone do legado): impressão a partir da lista
 * (lê o detalhe com as linhas), modais que precisam do documento completo e a «Deliberação por valor».
 */

/** «Imprimir» das listas: lê o documento com as linhas e imprime-o com o motor comum (mesmo papel que o detalhe). */
export function useImprimirCompra() {
  const { imprimir } = useImpressao();
  const executar = useCallback(
    async (ler: () => Promise<Parameters<typeof pedidoDocumentoComercial>[0]>) => {
      try {
        await imprimir(pedidoDocumentoComercial(await ler()));
      } catch (e) {
        notificarErro(e, 'Não foi possível preparar a impressão');
      }
    },
    [imprimir],
  );
  return {
    pedido: (id: number) => executar(async () => dadosPedidoCompra(await obter<PedidoCompra>(`/compras/pedidos/${id}`))),
    proposta: (id: number) => executar(async () => dadosPropostaCompra(await obter<PropostaCompra>(`/compras/propostas/${id}`))),
    encomenda: (id: number) => executar(async () => dadosEncomendaCompra(await obter<EncomendaCompra>(`/compras/encomendas/${id}`))),
    rececao: (id: number, extra: { encomenda?: string | null; armazem?: string | null }) =>
      executar(async () => dadosRececaoCompra(await obter<RececaoCompra>(`/compras/rececoes/${id}`), extra)),
    fatura: (id: number) =>
      executar(async () => {
        const f = await obter<FaturaCompra>(`/compras/faturas/${id}`);
        return dadosFaturaCompra(
          f,
          linhasFatura(f).map((l) => ({
            codigo: l.produto?.codigo ?? null,
            descricao: l.produto?.nome ?? l.descricao ?? `Produto #${l.produto_id}`,
            quantidade: l.quantidade,
            preco: l.preco,
            taxa: l.iva,
            base: l.liquido,
            iva: l.imposto || undefined,
            total: l.liquido,
          })),
        );
      }),
  };
}

/** Lê o documento completo (com as linhas) antes de abrir um modal que precisa delas. */
export function ComDocumento<T>({ url, children }: { url: string | null; children: (doc: T) => ReactNode }) {
  const consulta = useQuery({ queryKey: ['compras', 'documento-lista', url], queryFn: () => obter<T>(url as string), enabled: !!url });
  if (!url) return null;
  if (!consulta.data) {
    return consulta.isError ? null : (
      <Modal open footer={null} closable={false} width={larguraModal(420)}>
        <Skeleton active />
      </Modal>
    );
  }
  return <>{children(consulta.data)}</>;
}

/** «Definir o IVA das linhas» a partir da lista (proposta ou encomenda). */
export function ModalIvaDaLista({ documento, aoFechar }: { documento: { tipo: 'proposta' | 'encomenda'; id: number } | null; aoFechar: () => void }) {
  if (!documento) return null;
  const base = documento.tipo === 'proposta' ? 'propostas' : 'encomendas';
  return (
    <ComDocumento<PropostaCompra | EncomendaCompra> url={`/compras/${base}/${documento.id}`}>
      {(d) => (
        <ModalIva
          aberto
          encomenda={documento.tipo === 'encomenda'}
          titulo={documento.tipo === 'proposta' ? `IVA da proposta ${numeroOuId((d as PropostaCompra).numero_proposta, d.id)}` : `IVA da encomenda ${numeroOuId((d as EncomendaCompra).numero_encomenda, d.id)}`}
          linhas={d.linhas ?? []}
          url={`/compras/${base}/${d.id}/iva`}
          aoFechar={aoFechar}
        />
      )}
    </ComDocumento>
  );
}

function estadoPasso(e: EtapaDeliberacao): 'finish' | 'process' | 'error' | 'wait' {
  if (e.estado === 'APROVADO') return 'finish';
  if (e.estado === 'PENDENTE') return 'process';
  if (e.estado === 'RECUSADO') return 'error';
  return 'wait';
}

/** «Deliberação por valor» (comprasVerDeliberacao, js/compras_deliberacao.js:237): valor, níveis exigidos e decisões. */
export function ModalDeliberacao({ pedido, aoFechar }: { pedido: PedidoCompra | null; aoFechar: () => void }) {
  const etapas = pedido?.deliberacao?.etapas ?? [];
  return (
    <Modal
      open={!!pedido}
      onCancel={aoFechar}
      width={larguraModal(720)}
      title={pedido ? `Deliberação por valor — pedido ${numeroOuId(pedido.numero_pedido, pedido.id)}` : ''}
      footer={<Button onClick={aoFechar}>Fechar</Button>}
    >
      {pedido && (
        <>
          <p>
            Valor estimado: <strong>{formatarKz(pedido.deliberacao?.valor ?? 0)} Kz</strong>
            {(pedido.deliberacao?.sem_preco ?? 0) > 0 && ` (${pedido.deliberacao?.sem_preco} linha(s) sem preço)`} · Estado: <strong>{rotuloEstado(pedido.estado)}</strong>
          </p>
          <Steps
            direction="vertical"
            size="small"
            items={etapas.map((e) => ({
              title: `Nível ${e.ordem}: ${e.nome}`,
              status: estadoPasso(e),
              description: (
                <>
                  <div>{rotuloEstado(e.estado)}{e.revisao ? ' (revisão na adjudicação)' : ''}</div>
                  {e.por && <div>{e.por}{e.em ? ` · ${formatarDataHora(e.em)}` : ''}</div>}
                  {e.nota && <div><em>{e.nota}</em></div>}
                </>
              ),
            }))}
          />
        </>
      )}
    </Modal>
  );
}
