import { Button, Card, Select, Tag, Typography } from 'antd';
import { useEcraPequeno } from '@/componentes/responsivo';
import { PainelPesquisaCompras } from '../comum/PainelPesquisaCompras';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import type { AccaoLinha } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { ModalDeliberacao, useImprimirCompra } from '../comum/accoesLista';
import { colunaUnCc } from '../comum/colunasLegado';
import { EstadoTag, opcoesEstado, rotuloEstado } from '../comum/estados';
import { ModalFluxoDocumental, type TipoDocumentoCompra } from '../comum/FluxoDocumental';
import { accoesPedido, etapaPendente, type Pode } from '../comum/regras';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { numeroOuId, type PedidoCompra } from '../comum/tipos';
import { ModalEscaloes } from './ModalEscaloes';

/** Acções por linha da «Lista de Pedidos de Compra» do legado (js/ui_compras_v2.js:207-225), com as mesmas condições. */
export function accoesLinhaPedido(
  r: PedidoCompra,
  pode: Pode,
  f: { ver: () => void; fluxo: () => void; imprimir: () => unknown; proposta: () => void; apagar: () => void; deliberacao: () => void; aprovar: () => unknown; recusar: () => void },
): AccaoLinha[] {
  const a = accoesPedido(r, pode);
  const decide = r.estado === 'PENDENTE' && (r.pode_decidir ?? a.decidir);
  return [
    { icone: 'eye', titulo: 'Ver Detalhes', onClick: f.ver },
    { icone: 'project-diagram', titulo: 'Fluxo Documental', variante: 'info', onClick: f.fluxo },
    { icone: 'print', titulo: 'Imprimir', onClick: f.imprimir },
    { icone: 'file-contract', titulo: 'Registar Proposta para este Pedido', variante: 'primario', cheio: true, oculto: !a.registarProposta, onClick: f.proposta },
    {
      icone: 'trash', titulo: 'Apagar Pedido', variante: 'perigo', cheio: true, onClick: f.apagar,
      desactivado: !pode('compras_ped_eliminar') ? 'Sem permissão para anular pedidos' : !a.anular ? `Um pedido ${rotuloEstado(r.estado).toLowerCase()} não pode ser anulado (anule primeiro a encomenda)` : false,
    },
    { icone: 'gavel', titulo: 'Deliberação por valor', oculto: !r.deliberacao?.etapas?.length, onClick: f.deliberacao },
    { icone: 'check', titulo: 'Aprovar no nível pendente', variante: 'sucesso', cheio: true, oculto: !decide, confirmar: { titulo: `Aprovar o pedido ${numeroOuId(r.numero_pedido, r.id)}?`, texto: `Etapa: ${etapaPendente(r)?.nome ?? 'nível 1'}.` }, onClick: f.aprovar },
    { icone: 'times', titulo: 'Não Autorizar', variante: 'perigo', cheio: true, oculto: !decide, onClick: f.recusar },
  ];
}

/** Compras › Pedidos: «Lista de Pedidos de Compra» do legado (colunas, acções por linha e escalões de aprovação). */
export function ListaPedidos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [pesquisa, setPesquisa] = useState('');
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [escaloes, setEscaloes] = useState(false);
  const [fluxo, setFluxo] = useState<{ tipo: TipoDocumentoCompra; id: number } | null>(null);
  const [deliberacao, setDeliberacao] = useState<PedidoCompra | null>(null);
  const [motivo, setMotivo] = useState<{ pedido: PedidoCompra; tipo: 'anular' | 'recusar' } | null>(null);
  const pequeno = useEcraPequeno();
  const imprimir = useImprimirCompra();
  const accao = useAccao<PedidoCompra>({ invalidar: [['compras']], aoSucesso: () => setMotivo(null) });

  const colunas: ColunaApi<PedidoCompra>[] = [
    { title: 'Ref.', key: 'numero', fixed: 'left', valorImpressao: (r) => numeroOuId(r.numero_pedido, r.id), render: (_, r) => <strong>{numeroOuId(r.numero_pedido, r.id)}</strong> },
    { title: 'Data Pedido', dataIndex: 'data', render: formatarData },
    {
      title: 'Itens / Qtd', key: 'itens', valorImpressao: (r) => r.itens_resumo || 'Sem itens', valorFiltro: (r) => r.itens_resumo ?? '',
      render: (_, r) => <Typography.Text style={{ fontSize: 12 }}>{r.itens_resumo || 'Sem itens'}</Typography.Text>,
    },
    { title: 'Entrega Ref.', dataIndex: 'data_entrega', valorImpressao: (r) => formatarData(r.data_entrega), render: (v: string | null) => <Tag>{v ? formatarData(v) : '-'}</Tag> },
    { title: 'Requisitante', dataIndex: 'nome_requerente' },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, ocultaPorOmissao: true, render: (v) => v || '—' },
    colunaUnCc<PedidoCompra>(),
    {
      title: 'Status', dataIndex: 'estado', valorImpressao: (r) => rotuloEstado(r.estado),
      render: (e: string, r) => (
        <>
          <EstadoTag estado={e} />
          {e === 'PENDENTE' && etapaPendente(r) && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 11 }}>Aguarda: {etapaPendente(r)?.nome}</Typography.Text>}
        </>
      ),
    },
  ];

  return (
    <>
      {/* um só cartão com o painel de pesquisa, o título e a lista, como o legado (js/ui_compras_v2.js:165-233) */}
      <Card className="compras-cartao-lista">
      <PainelPesquisaCompras placeholder="Nº Doc / Requisitante..." aoPesquisar={setPesquisa} periodo={periodo} aoMudarPeriodo={setPeriodo}>
        <Select placeholder="Estado" allowClear style={{ width: 200, maxWidth: '100%' }} value={estado} onChange={setEstado} options={opcoesEstado(['PENDENTE', 'APROVADO', 'REJEITADO', 'ADJUDICADO', 'ANULADO'])} />
      </PainelPesquisaCompras>
      <CabecalhoPagina
        titulo="Lista de Pedidos de Compra"
        accoes={
          pode('compras_pedidos_view', 'compras_deliberacao_config') && (
            <Button icon={<IconeFa nome="layer-group" />} onClick={() => setEscaloes(true)} title="Níveis de aprovação por valor">
              Escalões de aprovação
            </Button>
          )
        }
      />
        <TabelaApi<PedidoCompra>
          url="/compras/pedidos"
          chaveConsulta={['compras', 'pedidos']}
          filtros={{ estado, pesquisa: pesquisa || undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Lista de Pedidos de Compra',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [estado && `Estado: ${rotuloEstado(estado)}`, pesquisa && `Pesquisa: ${pesquisa}`],
          }}
          accoes={(r) =>
            accoesLinhaPedido(r, pode, {
              ver: () => navegar(String(r.id)),
              fluxo: () => setFluxo({ tipo: 'pedido', id: r.id }),
              imprimir: () => imprimir.pedido(r.id),
              proposta: () => navegar(`/m/compras/compras_prospeccao/novo?pedido=${r.id}`),
              apagar: () => setMotivo({ pedido: r, tipo: 'anular' }),
              deliberacao: () => setDeliberacao(r),
              aprovar: () => accao.mutateAsync({ url: `/compras/pedidos/${r.id}/decidir`, dados: { decisao: 'APROVAR' } }),
              recusar: () => setMotivo({ pedido: r, tipo: 'recusar' }),
            })
          }
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalEscaloes aberto={escaloes} aoFechar={() => setEscaloes(false)} />
      <ModalFluxoDocumental documento={fluxo} aoFechar={() => setFluxo(null)} />
      <ModalDeliberacao pedido={deliberacao} aoFechar={() => setDeliberacao(null)} />
      <ModalMotivo
        aberto={!!motivo}
        titulo={motivo ? `${motivo.tipo === 'anular' ? 'Apagar (anular) o pedido' : 'Não autorizar o pedido'} ${numeroOuId(motivo.pedido.numero_pedido, motivo.pedido.id)}` : ''}
        textoOk={motivo?.tipo === 'anular' ? 'Anular' : 'Não autorizar'}
        aviso={motivo?.tipo === 'anular' ? 'O pedido fica anulado (com o motivo) e as propostas em aberto também.' : 'O pedido fica rejeitado nesta etapa.'}
        carregando={accao.isPending}
        aoFechar={() => setMotivo(null)}
        aoConfirmar={(texto) => {
          if (!motivo) return;
          accao.mutate(
            motivo.tipo === 'anular'
              ? { url: `/compras/pedidos/${motivo.pedido.id}/anular`, dados: { motivo: texto } }
              : { url: `/compras/pedidos/${motivo.pedido.id}/decidir`, dados: { decisao: 'RECUSAR', nota: texto } },
          );
        }}
      />
    </>
  );
}
