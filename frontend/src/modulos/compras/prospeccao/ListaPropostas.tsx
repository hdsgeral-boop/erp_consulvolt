import { Button, Card, InputNumber, Select, Tag, Space } from 'antd';
import { useEcraPequeno } from '@/componentes/responsivo';
import { PainelPesquisaCompras } from '../comum/PainelPesquisaCompras';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import type { AccaoLinha } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { ModalIvaDaLista, useImprimirCompra } from '../comum/accoesLista';
import { colunasMoeda, colunaUnCc } from '../comum/colunasLegado';
import { EstadoTag, opcoesEstado, rotuloEstado } from '../comum/estados';
import { ModalFluxoDocumental, type TipoDocumentoCompra } from '../comum/FluxoDocumental';
import { NomeTerceiro } from '../comum/referencias';
import { accoesProposta, type Pode } from '../comum/regras';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { numeroOuId, type EncomendaCompra, type PropostaCompra } from '../comum/tipos';

/** Acções por linha de «Propostas de Fornecedores» do legado (js/ui_compras_v2.js:313-323), com as mesmas condições. */
export function accoesLinhaProposta(
  c: PropostaCompra,
  pode: Pode,
  f: { fluxo: () => void; propor: () => unknown; adjudicar: () => unknown; mudar: () => unknown; iva: () => void; imprimir: () => unknown; apagar: () => unknown },
): AccaoLinha[] {
  const a = accoesProposta(c, pode);
  const aberta = ['PROPOSTA', 'PROPOSTA_ADJUDICACAO'].includes(c.estado);
  const nome = c.referencia || numeroOuId(c.numero_proposta, c.id);
  return [
    { icone: 'project-diagram', titulo: 'Fluxo Documental', variante: 'info', onClick: f.fluxo },
    { icone: 'hand-pointer', titulo: 'Propor Adjudicação', oculto: c.estado !== 'PROPOSTA', desactivado: !a.propor && 'Sem permissão para avaliar propostas', confirmar: `Propor a adjudicação da proposta ${nome}?`, onClick: f.propor },
    { icone: 'check-double', titulo: 'Aprovação Final', variante: 'sucesso', cheio: true, oculto: c.estado !== 'PROPOSTA_ADJUDICACAO', desactivado: !a.adjudicar && 'Sem permissão para adjudicar',
      confirmar: { titulo: `Adjudicar a proposta ${nome}?`, texto: 'Gera a encomenda ao fornecedor; as outras propostas do pedido ficam recusadas.', textoOk: 'Adjudicar e gerar encomenda' }, onClick: f.adjudicar },
    { icone: 'undo', titulo: 'Mudar Escolha', variante: 'perigo', cheio: true, oculto: c.estado !== 'PROPOSTA_ADJUDICACAO', desactivado: !a.cancelarProposta && 'Sem permissão para avaliar propostas',
      confirmar: `Cancelar a proposta de adjudicação de ${nome}?`, onClick: f.mudar },
    { icone: 'percent', titulo: 'Definir o IVA das linhas', oculto: !aberta || !pode('compras_new_proposal'), onClick: f.iva },
    { icone: 'print', titulo: 'Imprimir', onClick: f.imprimir },
    { icone: 'trash', titulo: 'Apagar Proposta', variante: 'perigo', cheio: true, desactivado: !a.anular && (aberta ? 'Sem permissão para anular propostas' : `Uma proposta ${rotuloEstado(c.estado).toLowerCase()} não pode ser anulada`),
      confirmar: { titulo: `Apagar (anular) a proposta ${nome}?`, texto: 'A proposta fica anulada (fica o rasto).' }, onClick: f.apagar },
  ];
}

export function ListaPropostas() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [pedido, setPedido] = useState<number | null>(null);
  const [pesquisa, setPesquisa] = useState('');
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [fluxo, setFluxo] = useState<{ tipo: TipoDocumentoCompra; id: number } | null>(null);
  const [iva, setIva] = useState<{ tipo: 'proposta'; id: number } | null>(null);
  const pequeno = useEcraPequeno();
  const imprimir = useImprimirCompra();
  const accao = useAccao<PropostaCompra | EncomendaCompra>({
    invalidar: [['compras']],
    aoSucesso: (d, p) => {
      if (p.url.endsWith('/adjudicar') && d?.id && pode('compras_encomendas_view')) navegar(`/m/compras/compras_encomendas/${d.id}`);
    },
  });

  const colunas: ColunaApi<PropostaCompra>[] = [
    { title: 'Fornecedor', key: 'fornecedor', fixed: 'left', valorImpressao: (r) => r.fornecedor?.nome?.trim() ?? `#${r.fornecedor_id}`, render: (_, r) => <strong><NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /></strong> },
    { title: 'Pedido Ref.', dataIndex: 'pedido_compra_id', render: (v: number) => `#${v}` },
    { title: 'Referência', dataIndex: 'referencia', render: (v: string | null, r) => <Tag>{v || numeroOuId(r.numero_proposta, r.id)}</Tag>, valorImpressao: (r) => r.referencia || numeroOuId(r.numero_proposta, r.id) },
    { title: 'Prazo Entrega', dataIndex: 'data_entrega', valorImpressao: (r) => formatarData(r.data_entrega), render: (v: string | null) => <Tag>{v ? formatarData(v) : '-'}</Tag> },
    { title: 'Valor Total (Kz)', dataIndex: 'montante_total', align: 'right', total: true, render: (v: string | null) => formatarKz(v) },
    ...colunasMoeda<PropostaCompra>(),
    colunaUnCc<PropostaCompra>(),
    { title: 'Status', dataIndex: 'estado', valorImpressao: (r) => rotuloEstado(r.estado), render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Proposta', key: 'numero', ocultaPorOmissao: true, valorImpressao: (r) => numeroOuId(r.numero_proposta, r.id), render: (_, r) => numeroOuId(r.numero_proposta, r.id) },
    { title: 'Data', dataIndex: 'data', ocultaPorOmissao: true, render: formatarData },
  ];

  return (
    <>
      {/* um só cartão com o painel de pesquisa, o título (com «Comparar por Pedido…») e a lista, como o legado */}
      <Card className="compras-cartao-lista">
        <PainelPesquisaCompras placeholder="Nº Doc / Entidade / Justificação..." aoPesquisar={setPesquisa} periodo={periodo} aoMudarPeriodo={setPeriodo}>
          <Select placeholder="Estado" allowClear style={{ width: 230, maxWidth: '100%' }} value={estado} onChange={setEstado} options={opcoesEstado(['PROPOSTA', 'PROPOSTA_ADJUDICACAO', 'ADJUDICADO', 'RECUSADA', 'ANULADA'])} />
        </PainelPesquisaCompras>
        <CabecalhoPagina
          titulo={
            <Space wrap size={12}>
              <span>Propostas de Fornecedores</span>
              <InputNumber placeholder="Comparar por Pedido..." min={1} style={{ width: 200, maxWidth: '100%' }} value={pedido} onChange={(v) => setPedido(v)} />
              {pedido && <Button onClick={() => navegar(`comparacao/${pedido}`)}>Quadro comparativo do pedido</Button>}
            </Space>
          }
          accoes={
            <Button icon={<IconeFa nome="sync" />} onClick={() => { setEstado(undefined); setPedido(null); setPeriodo(null); }}>
              Limpar Filtro
            </Button>
          }
        />
        <TabelaApi<PropostaCompra>
          url="/compras/propostas"
          chaveConsulta={['compras', 'propostas']}
          filtros={{ estado, pesquisa: pesquisa || undefined, pedido_compra_id: pedido ?? undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          rotuloTotal="TOTAL (FILTRADO):"
          impressao={{
            titulo: 'Propostas de Fornecedores',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [estado && `Estado: ${rotuloEstado(estado)}`, !!pedido && `Pedido: #${pedido}`],
          }}
          accoes={(r) =>
            accoesLinhaProposta(r, pode, {
              fluxo: () => setFluxo({ tipo: 'proposta', id: r.id }),
              propor: () => accao.mutateAsync({ url: `/compras/propostas/${r.id}/propor` }),
              adjudicar: () => accao.mutateAsync({ url: `/compras/propostas/${r.id}/adjudicar`, dados: { data: dayjs().format('YYYY-MM-DD') } }),
              mudar: () => accao.mutateAsync({ url: `/compras/propostas/${r.id}/cancelar-proposta` }),
              iva: () => setIva({ tipo: 'proposta', id: r.id }),
              imprimir: () => imprimir.proposta(r.id),
              apagar: () => accao.mutateAsync({ url: `/compras/propostas/${r.id}/anular` }),
            })
          }
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalFluxoDocumental documento={fluxo} aoFechar={() => setFluxo(null)} />
      <ModalIvaDaLista documento={iva} aoFechar={() => setIva(null)} />
    </>
  );
}
