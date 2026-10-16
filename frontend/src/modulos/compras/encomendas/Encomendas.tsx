import { Alert, Button, Card, Descriptions, Modal, Select, Skeleton, Table, Tag } from 'antd';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import type { AccaoLinha } from '@/componentes/vistas';
import { larguraModal } from '@/componentes/responsivo';
import { PainelPesquisaCompras } from '../comum/PainelPesquisaCompras';
import { ComDocumento, ModalIvaDaLista, useImprimirCompra } from '../comum/accoesLista';
import { BarrasSatisfacao, colunaUnCc } from '../comum/colunasLegado';
import { ModalFluxoDocumental, type TipoDocumentoCompra } from '../comum/FluxoDocumental';
import type { Pode } from '../comum/regras';
import type { ContratoCompra } from '../comum/tipos';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { COLUNAS_DESCRICOES, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { CartaoAlocacaoUN, bloqueioAlocacao, useColunasUNCC, type LinhaComUNCC, type ResumoAlocacao } from '@/componentes/reparticao';
import { pedidoDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { dadosEncomendaCompra } from '../comum/impressao';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { linhaIvaEditavel, ModalIva } from '../comum/ModalIva';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { pendente } from '../comum/calculos';
import { EstadoTag, opcoesEstado, rotuloEstado } from '../comum/estados';
import { ModalFaturaEncomenda, ModalRececao } from '../comum/ModaisEncomenda';
import { NomeProduto, NomeTerceiro } from '../comum/referencias';
import { accoesEncomenda } from '../comum/regras';
import { SeletorTerceiro } from '../comum/Seletores';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { numeroOuId, type EncomendaCompra, type FaturaCompra, type ItemCompra, type RececaoCompra } from '../comum/tipos';
import { ValorMoeda } from '../comum/Valores';

/** Compras › Encomendas a fornecedores (ecrã compras_encomendas). As encomendas nascem da adjudicação de propostas. */
export default function Encomendas() {
  return (
    <Routes>
      <Route index element={<ListaEncomendas />} />
      <Route path=":id" element={<DetalheEncomenda />} />
    </Routes>
  );
}

/** Acções por linha de «Ordens de Encomenda» do legado (js/ui_compras_v2.js:436-456), com as mesmas condições. */
export function accoesLinhaEncomenda(
  e: EncomendaCompra,
  pode: Pode,
  f: { ver: () => void; fluxo: () => void; imprimir: () => unknown; iva: () => void; fatura: () => void; rececao: () => void; apagar: () => void },
): AccaoLinha[] {
  const a = accoesEncomenda(e, pode);
  const anulada = e.estado === 'ANULADA';
  const porFaturar = (e.percentagem_faturada ?? 0) < 100;
  const porReceber = (e.percentagem_entregue ?? 0) < 100 && e.estado !== 'RECEBIDO';
  return [
    { icone: 'eye', titulo: 'Ver Detalhes', onClick: f.ver },
    { icone: 'project-diagram', titulo: 'Fluxo Documental', variante: 'info', onClick: f.fluxo },
    { icone: 'print', titulo: 'Imprimir', onClick: f.imprimir },
    { icone: 'percent', titulo: 'Definir o IVA das linhas', oculto: !porFaturar || anulada || !pode('compras_enc_criar'), onClick: f.iva },
    { icone: 'file-invoice-dollar', titulo: 'Gerar Fatura de Fornecedor', variante: 'primario', cheio: true, oculto: !porFaturar || anulada, desactivado: !a.registarFatura && 'Sem permissão para registar facturas', onClick: f.fatura },
    { icone: 'check-double', titulo: 'Totalmente Faturada', cor: '#1e3a8a', oculto: porFaturar, desactivado: 'Totalmente Faturada' },
    { icone: 'truck-loading', titulo: 'Aguarda receção — registar a recepção (validada no Armazém)', variante: 'sucesso', oculto: !porReceber || anulada, desactivado: !a.registarRececao && 'Sem permissão para registar recepções', onClick: f.rececao },
    { icone: 'warehouse', titulo: 'Totalmente Recebida', cor: '#16a34a', oculto: porReceber, desactivado: 'Totalmente Recebida' },
    { icone: 'trash', titulo: 'Apagar Encomenda', variante: 'perigo', cheio: true, onClick: f.apagar,
      desactivado: anulada ? 'A encomenda já está anulada' : !a.anular ? 'Sem permissão para anular encomendas' : (e.percentagem_entregue ?? 0) > 0 || (e.percentagem_faturada ?? 0) > 0 ? 'Eliminação bloqueada: a encomenda tem recepções ou facturas (anule-as primeiro)' : false },
  ];
}

/** Cores dos estados das encomendas como no legado (js/ui_compras_v2.js:432): recebida a verde, parcial a laranja, o resto a azul. */
export function corEstadoEncomenda(estado: string | null | undefined): string {
  if (estado === 'RECEBIDO') return 'green';
  if (estado === 'PARCIAL') return 'orange';
  if (estado === 'ANULADA') return 'red';
  return 'blue';
}

/** «Associar» / alterar o contrato da encomenda (associarContratoEncomenda, js/ui_compras_v2.js:4727). */
function ModalContratoEncomenda({ encomenda, aoFechar }: { encomenda: EncomendaCompra | null; aoFechar: () => void }) {
  const [escolha, setEscolha] = useState<number | null | undefined>(undefined);
  const contratos = useQuery({
    queryKey: ['compras', 'contratos', 'activos-do-fornecedor', encomenda?.fornecedor_id],
    queryFn: () => obterPagina<ContratoCompra>('/compras/contratos', { fornecedor_id: encomenda?.fornecedor_id, estado: 'ATIVO', por_pagina: 200 }),
    enabled: !!encomenda,
  });
  const accao = useAccao({ invalidar: [['compras']], aoSucesso: () => { setEscolha(undefined); aoFechar(); } });
  const atual = encomenda?.contrato?.id ?? null;
  const valor = escolha === undefined ? atual : escolha;
  return (
    <Modal
      open={!!encomenda}
      title={encomenda ? `Contrato da encomenda ${numeroOuId(encomenda.numero_encomenda, encomenda.id)}` : ''}
      width={larguraModal(520)}
      okText="Gravar"
      cancelText="Cancelar"
      okButtonProps={{ disabled: valor === atual }}
      confirmLoading={accao.isPending}
      onCancel={() => { setEscolha(undefined); aoFechar(); }}
      onOk={() => encomenda && accao.mutate({ url: `/compras/encomendas/${encomenda.id}/contrato`, dados: { contrato_fornecedor_id: valor ?? null } })}
      destroyOnHidden
    >
      <Select
        style={{ width: '100%' }}
        allowClear
        loading={contratos.isLoading}
        placeholder="Sem contrato"
        value={valor ?? undefined}
        onChange={(v) => setEscolha(v ?? null)}
        options={(contratos.data?.itens ?? []).map((c) => ({ value: c.id, label: `${c.referencia}${c.descricao ? ` — ${c.descricao}` : ''}` }))}
        notFoundContent="O fornecedor não tem contratos activos."
      />
    </Modal>
  );
}

function ListaEncomendas() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [fornecedor, setFornecedor] = useState<number>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [pesquisa, setPesquisa] = useState('');
  const [nomeFornecedor, setNomeFornecedor] = useState<string>();
  const [fluxo, setFluxo] = useState<{ tipo: TipoDocumentoCompra; id: number } | null>(null);
  const [iva, setIva] = useState<{ tipo: 'encomenda'; id: number } | null>(null);
  const [modal, setModal] = useState<{ tipo: 'fatura' | 'rececao'; id: number } | null>(null);
  const [anular, setAnular] = useState<EncomendaCompra | null>(null);
  const [contrato, setContrato] = useState<EncomendaCompra | null>(null);
  const pequeno = useEcraPequeno();
  const imprimir = useImprimirCompra();
  const accao = useAccao<EncomendaCompra>({ invalidar: [['compras']], aoSucesso: () => setAnular(null) });
  const podeAssociar = pode('compras_contratos_gerir', 'compras_enc_criar');

  const colunas: ColunaApi<EncomendaCompra>[] = [
    {
      title: 'Nº Ordem', key: 'numero', fixed: 'left', valorImpressao: (r) => numeroOuId(r.numero_encomenda, r.id),
      render: (_, r) => <><strong>{numeroOuId(r.numero_encomenda, r.id)}</strong>{r.codigo_moeda && r.codigo_moeda !== 'AOA' && <Tag style={{ marginLeft: 4 }}>{r.codigo_moeda}</Tag>}</>,
    },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Fornecedor', key: 'fornecedor', valorImpressao: (r) => r.fornecedor?.nome?.trim() ?? `#${r.fornecedor_id}`, render: (_, r) => <NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /> },
    {
      title: 'Contrato', key: 'contrato', valorImpressao: (r) => r.contrato?.referencia ?? '', valorFiltro: (r) => r.contrato?.referencia ?? '',
      render: (_, r) =>
        r.contrato ? (
          <Tag style={{ cursor: podeAssociar ? 'pointer' : undefined }} title="Alterar o contrato" onClick={(ev) => { ev.stopPropagation(); if (podeAssociar) setContrato(r); }}>
            <IconeFa nome="file-contract" /> {r.contrato.referencia}
          </Tag>
        ) : podeAssociar && r.estado !== 'ANULADA' ? (
          <Button size="small" icon={<IconeFa nome="link" />} title="Associar a um contrato" onClick={(ev) => { ev.stopPropagation(); setContrato(r); }}>
            Associar
          </Button>
        ) : (
          '—'
        ),
    },
    {
      title: 'Satisfação (E/F)', key: 'satisfacao', width: 110, filtravel: false,
      valorImpressao: (r) => `E ${Math.round(r.percentagem_entregue ?? 0)}% / F ${Math.round(r.percentagem_faturada ?? 0)}%`,
      render: (_, r) => <BarrasSatisfacao entregue={r.percentagem_entregue ?? 0} faturada={r.percentagem_faturada ?? 0} />,
    },
    colunaUnCc<EncomendaCompra>(),
    { title: 'Status', dataIndex: 'estado', valorImpressao: (r) => rotuloEstado(r.estado), render: (e: string) => <Tag color={corEstadoEncomenda(e)}>{rotuloEstado(e)}</Tag> },
    { title: 'Total (Kz)', key: 'total', align: 'right', ocultaPorOmissao: true, valorImpressao: (r) => formatarKz(r.montante_total), render: (_, r) => <ValorMoeda kz={r.montante_total} moeda={r.codigo_moeda} valorMoeda={r.montante_total_moeda} /> },
    { title: 'Entrega prevista', dataIndex: 'data_entrega_prevista', ocultaPorOmissao: true, render: formatarData },
  ];

  return (
    <>
      {/* um só cartão com o painel de pesquisa, o título e a lista, como o legado (js/ui_compras_v2.js:165-233) */}
      <Card className="compras-cartao-lista">
      <PainelPesquisaCompras placeholder="N.º da encomenda" aoPesquisar={setPesquisa} periodo={periodo} aoMudarPeriodo={setPeriodo}>
        <Select placeholder="Estado" allowClear style={{ width: 200, maxWidth: '100%' }} value={estado} onChange={setEstado} options={opcoesEstado(['EM_PROCESSAMENTO', 'PARCIAL', 'RECEBIDO', 'ANULADA'])} />
        <SeletorTerceiro papel="FORNECEDOR" style={{ width: 320, maxWidth: '100%' }} value={fornecedor} onChange={(v, o) => { setFornecedor(v); setNomeFornecedor(o && !Array.isArray(o) && o.label ? String(o.label) : undefined); }} />
      </PainelPesquisaCompras>
      <CabecalhoPagina titulo="Ordens de Encomenda" />
      <ModalFluxoDocumental documento={fluxo} aoFechar={() => setFluxo(null)} />
      <ModalIvaDaLista documento={iva} aoFechar={() => setIva(null)} />
      <ModalContratoEncomenda encomenda={contrato} aoFechar={() => setContrato(null)} />
      {modal && (
        <ComDocumento<EncomendaCompra> url={`/compras/encomendas/${modal.id}`}>
          {(e) =>
            modal.tipo === 'rececao' ? (
              <ModalRececao encomenda={e} aberto aoFechar={() => setModal(null)} />
            ) : (
              <ModalFaturaEncomenda encomenda={e} aberto aoFechar={() => setModal(null)} aoRegistar={(f) => pode('compras_faturacao_view') && navegar(`/m/compras/compras_faturacao/${f.id}`)} />
            )
          }
        </ComDocumento>
      )}
      <ModalMotivo
        aberto={!!anular}
        titulo={anular ? `Apagar (anular) a encomenda ${numeroOuId(anular.numero_encomenda, anular.id)}` : ''}
        textoOk="Anular"
        aviso="Só se anulam encomendas sem recepções nem facturas activas. A proposta volta ao estado Proposta e o pedido a Aprovado."
        carregando={accao.isPending}
        aoFechar={() => setAnular(null)}
        aoConfirmar={(motivo) => anular && accao.mutate({ url: `/compras/encomendas/${anular.id}/anular`, dados: { motivo } })}
      />
        <TabelaApi<EncomendaCompra>
          url="/compras/encomendas"
          chaveConsulta={['compras', 'encomendas']}
          filtros={{ estado, pesquisa: pesquisa || undefined, fornecedor_id: fornecedor, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          accoes={(r) =>
            accoesLinhaEncomenda(r, pode, {
              ver: () => navegar(String(r.id)),
              fluxo: () => setFluxo({ tipo: 'encomenda', id: r.id }),
              imprimir: () => imprimir.encomenda(r.id),
              iva: () => setIva({ tipo: 'encomenda', id: r.id }),
              fatura: () => setModal({ tipo: 'fatura', id: r.id }),
              rececao: () => setModal({ tipo: 'rececao', id: r.id }),
              apagar: () => setAnular(r),
            })
          }
          impressao={{
            titulo: 'Ordens de Encomenda',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [estado && `Estado: ${rotuloEstado(estado)}`, !!fornecedor && `Fornecedor: ${nomeFornecedor ?? `#${fornecedor}`}`, pesquisa && `Pesquisa: ${pesquisa}`],
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}

export function DetalheEncomenda() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'rececao' | 'fatura' | 'anular' | 'iva' | null>(null);
  const consulta = useQuery({ queryKey: ['compras', 'encomenda', id], queryFn: () => obter<EncomendaCompra & { alocacao_un?: ResumoAlocacao | null }>(`/compras/encomendas/${id}`) });
  // ADR-071 (R5): colunas «Un. negócio» / «C. custo» por linha
  const colunasUNCC = useColunasUNCC((consulta.data?.linhas ?? []) as (ItemCompra & LinhaComUNCC)[]);
  const rececoes = useQuery({
    queryKey: ['compras', 'rececoes', 'da-encomenda', id],
    queryFn: () => obterPagina<RececaoCompra>('/compras/rececoes', { encomenda_compra_id: id, por_pagina: 100 }),
    enabled: pode('compras_rececoes_view', 'armazem_rececoes_view'),
  });
  const faturas = useQuery({
    queryKey: ['compras', 'faturas', 'da-encomenda', id],
    queryFn: () => obterPagina<FaturaCompra>('/compras/faturas', { encomenda_compra_id: id, por_pagina: 100 }),
    enabled: pode('compras_faturacao_view'),
  });
  const anular = useAccao<EncomendaCompra>({ invalidar: [['compras']], aoSucesso: () => setModal(null) });

  if (consulta.isLoading) return <Skeleton active />;
  const e = consulta.data;
  if (!e) return <Alert type="error" message="Encomenda não encontrada." />;
  const a = accoesEncomenda(e, pode);
  const moeda = e.codigo_moeda || 'AOA';
  const nome = numeroOuId(e.numero_encomenda, e.id);

  return (
    <>
      <CabecalhoPagina
        titulo={`Encomenda ${nome}`}
        subtitulo={<NomeTerceiro id={e.fornecedor_id} terceiro={e.fornecedor} />}
        impressao={() => pedidoDocumentoComercial(dadosEncomendaCompra(e))}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.registarRececao && <Button type="primary" onClick={() => setModal('rececao')}>Registar recepção</Button>}
            {a.registarFatura && <Button onClick={() => setModal('fatura')}>Registar factura</Button>}
            {e.estado !== 'ANULADA' && pode('compras_enc_criar') && (e.linhas ?? []).some((l) => linhaIvaEditavel(l, true)) && <Button onClick={() => setModal('iva')}>Editar IVA</Button>}
            {a.anular && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {e.estado === 'ANULADA' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Encomenda anulada${e.motivo_anulacao ? `: ${e.motivo_anulacao}` : '.'}`} />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={COLUNAS_DESCRICOES} size="small">
          <Descriptions.Item label="Data">{formatarData(e.data)}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={e.estado} /></Descriptions.Item>
          <Descriptions.Item label="Entrega prevista">{formatarData(e.data_entrega_prevista)}</Descriptions.Item>
          <Descriptions.Item label="Pedido">{e.pedido_compra_id ? `#${e.pedido_compra_id}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Proposta">{e.cotacao_compra_id ? `#${e.cotacao_compra_id}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Contrato">{e.contrato_fornecedor_id ? `#${e.contrato_fornecedor_id}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Moeda">{moeda}{e.taxa_cambio && moeda !== 'AOA' ? ` (câmbio ${formatarNumero(e.taxa_cambio)})` : ''}</Descriptions.Item>
          <Descriptions.Item label="Total (Kz)"><ValorMoeda kz={e.montante_total} moeda={moeda} valorMoeda={e.montante_total_moeda} /></Descriptions.Item>
          {e.total_com_imposto && <Descriptions.Item label="Total c/ IVA (Kz)">{formatarKz(e.total_com_imposto)}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card title="Artigos" style={{ marginBottom: 16 }}>
        <Table<ItemCompra>
          rowKey="id"
          size="small"
          pagination={false}
          scroll={scrollTabela()}
          dataSource={e.linhas ?? []}
          columns={[
            { title: 'Produto', render: (_, l) => <NomeProduto id={l.produto_id} produto={l.produto} descricao={l.descricao} /> },
            { title: 'Encomendado', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
            { title: 'Recebido', dataIndex: 'quantidade_recebida', align: 'right', render: (v) => formatarNumero(v ?? 0) },
            { title: 'Por receber', key: 'pr', align: 'right', render: (_, l) => formatarNumero(pendente(l.quantidade, l.quantidade_recebida)) },
            { title: 'Facturado', dataIndex: 'quantidade_faturada', align: 'right', render: (v) => formatarNumero(v ?? 0) },
            ...(moeda !== 'AOA' ? [{ title: `Preço (${moeda})`, dataIndex: 'preco_unitario_moeda', align: 'right' as const, render: (v: string | null) => formatarKz(v) }] : []),
            { title: 'Preço (Kz)', dataIndex: 'preco_unitario', align: 'right', render: (v: string | null) => formatarKz(v) },
            { title: 'Total (Kz)', key: 'total', align: 'right', render: (_, l) => formatarKz(l.total_kz ?? l.total) },
            ...colunasUNCC,
          ]}
        />
        {/* ADR-071 (R2): alterar a repartição por UN (passa às facturas da encomenda) enquanto não estiver contabilizada nem anulada */}
        <CartaoAlocacaoUN tipo="encomenda_compra" id={e.id} resumo={e.alocacao_un} valor={Number(e.montante_total ?? 0)} invalidar={[['compras']]}
          podeAlterar={pode('compras_enc_criar')} bloqueio={bloqueioAlocacao(e)} />
      </Card>
      {(rececoes.data?.itens.length ?? 0) > 0 && (
        <Card title="Recepções" style={{ marginBottom: 16 }}>
          <Table<RececaoCompra>
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={rececoes.data?.itens}
            scroll={scrollTabela()}
            onRow={(r) => ({ onClick: () => pode('compras_rececoes_view') && navegar(`/m/compras/compras_rececoes/${r.id}`), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Recepção', render: (_, r) => numeroOuId(r.numero_rececao, r.id) },
              { title: 'Guia do fornecedor', dataIndex: 'numero_entrega' },
              { title: 'Data', dataIndex: 'data', render: formatarData },
              { title: 'Estado', dataIndex: 'estado', render: (s: string) => <EstadoTag estado={s} /> },
            ]}
          />
        </Card>
      )}
      {(faturas.data?.itens.length ?? 0) > 0 && (
        <Card title="Facturas" style={{ marginBottom: 16 }}>
          <Table<FaturaCompra>
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={faturas.data?.itens}
            scroll={scrollTabela()}
            onRow={(f) => ({ onClick: () => navegar(`/m/compras/compras_faturacao/${f.id}`), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Factura', dataIndex: 'numero_fatura' },
              { title: 'Data', dataIndex: 'data', render: formatarData },
              { title: 'Total (Kz)', key: 'total', align: 'right', render: (_, f) => <ValorMoeda kz={f.montante_total} moeda={f.codigo_moeda} valorMoeda={f.montante_total_moeda} /> },
              { title: 'Estado', dataIndex: 'estado', render: (s: string | null) => <EstadoTag estado={s} /> },
            ]}
          />
        </Card>
      )}
      <ModalRececao encomenda={e} aberto={modal === 'rececao'} aoFechar={() => setModal(null)} />
      <ModalIva aberto={modal === 'iva'} encomenda titulo={`IVA da encomenda ${e.numero_encomenda ?? e.id}`} linhas={e.linhas ?? []} url={`/compras/encomendas/${e.id}/iva`} aoFechar={() => setModal(null)} />
      <ModalFaturaEncomenda
        encomenda={e}
        aberto={modal === 'fatura'}
        aoFechar={() => setModal(null)}
        aoRegistar={(f) => pode('compras_faturacao_view') && navegar(`/m/compras/compras_faturacao/${f.id}`)}
      />
      <ModalMotivo
        aberto={modal === 'anular'}
        titulo={`Anular a encomenda ${nome}`}
        textoOk="Anular"
        aviso="Só se anulam encomendas sem recepções nem facturas activas. A proposta volta ao estado Proposta e o pedido a Aprovado."
        carregando={anular.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => anular.mutate({ url: `/compras/encomendas/${e.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
